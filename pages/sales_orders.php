<?php
// Sales orders (stock going out to customers).
//   sales_orders.php                            list
//   sales_orders.php?show=form[&id=N]           new order, or edit a pending one
//   sales_orders.php?show=view&id=N             one order: complete, cancel, print
//   sales_orders.php?export=csv                 download all orders
require_once "../includes/functions.php";
require_once "../includes/orders.php";
require_login();

$view = in_array(get("show"), ["form", "view"], true) ? get("show") : "list";
$id = filter_var(get("id"), FILTER_VALIDATE_INT);   // the order (edit mode in the form)
$action = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_check();
    $action = post("action");
}

// ---------- CSV export ----------
if (get("export") == "csv") {
    $stmt = $pdo->query("SELECT CONCAT('SO-', LPAD(o.so_id, 4, '0')), o.order_date, c.name, o.status,
                                COALESCE(SUM(i.quantity * i.unit_price), 0)
                         FROM sales_orders o
                         JOIN customers c ON c.customer_id = o.customer_id
                         LEFT JOIN sales_order_items i ON i.so_id = o.so_id
                         GROUP BY o.so_id ORDER BY o.so_id DESC");
    csv_download("sales", ["Order", "Date", "Customer", "Status", "Total"], $stmt);
}

// ---------- Status changes: complete, cancel (POST) ----------
if (in_array($action, ["complete", "cancel"], true)) {
    $soId = filter_var(post("so_id"), FILTER_VALIDATE_INT);
    $no = order_no("SO", (int) $soId);

    if ($soId && $action == "complete") {
        $error = complete_sale($soId);
        if ($error) {
            flash($error, "error");
        } else {
            log_action("complete", "sales_order", $soId, "$no completed, stock reduced");
            flash("Sales order completed. Stock was reduced.");
        }
    } elseif ($soId && $action == "cancel") {
        $stmt = $pdo->prepare("SELECT status FROM sales_orders WHERE so_id = ?");
        $stmt->execute([$soId]);
        if ($stmt->fetchColumn() == "Completed" && !is_admin()) {   // undoing a sale changes stock, so admins only
            flash("Only an admin can cancel a completed sales order.", "error");
        } else {
            $error = cancel_sale($soId);
            if ($error) {
                flash($error, "error");
            } else {
                log_action("cancel", "sales_order", $soId, "$no cancelled");
                flash("Sales order cancelled.");
            }
        }
    }
    redirect("sales_orders.php?show=view&id=" . (int) $soId);
}

// ---------- Order form ----------
if ($view == "form") {
    $data = ["customer_id" => get("customer_id"), "order_date" => date("Y-m-d"), "note" => ""];
    $lines = [];
    $savedIds = [];   // products already on the saved order (may be archived)
    $errors = [];

    if ($id) {
        $stmt = $pdo->prepare("SELECT customer_id, order_date, note, status FROM sales_orders WHERE so_id = ?");
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order || $order["status"] != "Pending") {
            flash("Only a pending sales order can be edited.", "error");
            redirect("sales_orders.php");
        }
        $data = ["customer_id" => $order["customer_id"], "order_date" => $order["order_date"], "note" => $order["note"]];
        $stmt = $pdo->prepare("SELECT product_id, quantity, unit_price AS price FROM sales_order_items WHERE so_id = ? ORDER BY item_id");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll();
        $savedIds = array_column($lines, "product_id");
    }

    if ($action == "save") {
        foreach (["customer_id", "order_date", "note"] as $field) {
            $data[$field] = post($field);
        }
        $lines = read_lines();
    }

    $customerList = $pdo->query("SELECT customer_id, name FROM customers ORDER BY name")->fetchAll();
    $products = selectable_products($savedIds);

    if ($action == "save") {
        if (!in_ids($data["customer_id"], $customerList, "customer_id"))
            $errors["customer_id"] = "Choose a customer";
        if (!valid_date($data["order_date"]))
            $errors["order_date"] = "Enter a valid date";
        if (strlen($data["note"]) > 200)
            $errors["note"] = "Note can be at most 200 characters";
        if ($lineError = check_lines($lines, $products))
            $errors["lines"] = $lineError;

        if (!$errors) {
            $new = !$id;
            $pdo->beginTransaction();
            if ($new) {
                $pdo->prepare("INSERT INTO sales_orders (customer_id, user_id, order_date, note) VALUES (?, ?, ?, ?)")
                    ->execute([$data["customer_id"], $_SESSION["user_id"], $data["order_date"], $data["note"]]);
                $id = $pdo->lastInsertId();
            } else {
                $pdo->prepare("UPDATE sales_orders SET customer_id = ?, order_date = ?, note = ? WHERE so_id = ? AND status = 'Pending'")
                    ->execute([$data["customer_id"], $data["order_date"], $data["note"], $id]);
                $pdo->prepare("DELETE FROM sales_order_items WHERE so_id = ?")->execute([$id]);
            }
            $insert = $pdo->prepare("INSERT INTO sales_order_items (so_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            foreach ($lines as $line) {
                $insert->execute([$id, $line["product_id"], $line["quantity"], $line["price"]]);
            }
            $pdo->commit();
            log_action($new ? "create" : "update", "sales_order", $id, order_no("SO", $id));

            if (post("save") == "complete") {   // save and take the stock out in one step
                $error = complete_sale($id);
                if ($error) {
                    flash("Order saved as pending, but it was not completed. $error", "error");
                } else {
                    log_action("complete", "sales_order", $id, order_no("SO", $id) . " completed, stock reduced");
                    flash("Sales order completed. Stock was reduced.");
                }
            } else {
                flash("Sales order saved.");
            }
            redirect("sales_orders.php?show=view&id=$id");
        }
    }
}

// ---------- One order ----------
if ($view == "view") {
    $stmt = $pdo->prepare("SELECT o.*, c.name AS customer, c.email, c.phone, c.address, u.username
                           FROM sales_orders o
                           JOIN customers c ON c.customer_id = o.customer_id
                           JOIN users u ON u.user_id = o.user_id
                           WHERE o.so_id = ?");
    $stmt->execute([$id ?: 0]);
    $order = $stmt->fetch();
    if (!$order) {
        flash("Sales order not found.", "error");
        redirect("sales_orders.php");
    }

    $stmt = $pdo->prepare("SELECT i.quantity, i.unit_price, p.sku, p.name
                           FROM sales_order_items i JOIN products p ON p.product_id = i.product_id
                           WHERE i.so_id = ? ORDER BY i.item_id");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
    $orderTotal = 0;
    foreach ($items as $i) $orderTotal += $i["quantity"] * $i["unit_price"];
    $orderStatus = $order["status"];
}

// ---------- List: search, filter, sort, paging ----------
if ($view == "list") {
    $q = get("q");
    $customer = filter_var(get("customer"), FILTER_VALIDATE_INT);
    $statuses = ["Pending", "Completed", "Cancelled"];
    $status = in_array(get("status"), $statuses, true) ? get("status") : "";

    $sorts = ["id" => "o.so_id", "date" => "o.order_date", "total" => "total", "customer" => "c.name"];
    $sort = array_key_exists(get("sort"), $sorts) ? get("sort") : "id";
    $dir = get("dir") === "asc" ? "ASC" : "DESC";

    $where = [];
    $params = [];
    if ($q !== "") {
        $where[] = "(c.name LIKE ? OR o.note LIKE ? OR o.so_id = ?)";   // "SO-0003" finds order 3
        array_push($params, "%$q%", "%$q%", (int) preg_replace("/\D/", "", $q));
    }
    if ($customer) { $where[] = "o.customer_id = ?"; $params[] = $customer; }
    if ($status !== "") { $where[] = "o.status = ?"; $params[] = $status; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_orders o JOIN customers c ON c.customer_id = o.customer_id $whereSql");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    [$page, $pages, $offset] = paging($total, 10);

    $stmt = $pdo->prepare("
        SELECT o.so_id, o.order_date, o.status, c.name AS customer,
               COALESCE(SUM(i.quantity * i.unit_price), 0) AS total
        FROM sales_orders o
        JOIN customers c ON c.customer_id = o.customer_id
        LEFT JOIN sales_order_items i ON i.so_id = o.so_id
        $whereSql
        GROUP BY o.so_id
        ORDER BY {$sorts[$sort]} $dir
        LIMIT 10 OFFSET $offset");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $customerList = $pdo->query("SELECT customer_id, name FROM customers ORDER BY name")->fetchAll();
}

// small POST button used in the action row of the order view
function action_button($id, $action, $label, $confirm = "") {
    echo '<form method="post" action="sales_orders.php?show=view&amp;id=' . h($id) . '"' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : "") . '>';
    csrf_field();
    echo '<input type="hidden" name="so_id" value="' . h($id) . '"><input type="hidden" name="action" value="' . h($action) . '">';
    echo '<button type="submit" class="btn">' . h($label) . '</button></form>';
}

$titles = ["list" => "Sales Orders", "form" => ($id ? "Edit Sales Order" : "New Sales Order"),
           "view" => order_no("SO", (int) $id)];
page_start($titles[$view]);
?>

<?php if ($view == "list"): ?>

<div class="page-head">
    <h1>Sales Orders</h1>
    <div>
        <a href="sales_orders.php?export=csv" class="btn">Export CSV</a>
        <a href="sales_orders.php?show=form" class="btn">+ New Sales Order</a>
    </div>
</div>

<div class="card">
    <form method="get" action="sales_orders.php" class="filters filters-wide">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="dir" value="<?= h(strtolower($dir)) ?>">
        <div class="field">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="SO number, customer or note">
        </div>
        <div class="field">
            <label for="customer">Customer</label>
            <select id="customer" name="customer">
                <option value="">All</option>
                <?php foreach ($customerList as $c): ?>
                    <option value="<?= h($c["customer_id"]) ?>" <?= $customer == $c["customer_id"] ? "selected" : "" ?>><?= h($c["name"]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <?php foreach ($statuses as $st): ?>
                    <option value="<?= h($st) ?>" <?= $status === $st ? "selected" : "" ?>><?= h($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn">Filter</button>
            <a href="sales_orders.php" class="btn light">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th><?= sort_link("SO #", "id", $sort, $dir) ?></th>
                <th><?= sort_link("Customer", "customer", $sort, $dir) ?></th>
                <th><?= sort_link("Date", "date", $sort, $dir) ?></th>
                <th>Status</th>
                <th class="num"><?= sort_link("Total", "total", $sort, $dir) ?></th>
                <th></th>
            </tr>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?= h(order_no("SO", $o["so_id"])) ?></td>
                    <td><?= h($o["customer"]) ?></td>
                    <td><?= h($o["order_date"]) ?></td>
                    <td><?= h($o["status"]) ?></td>
                    <td class="num"><?= h(money($o["total"])) ?></td>
                    <td><a href="sales_orders.php?show=view&amp;id=<?= h($o["so_id"]) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?><tr><td colspan="6" class="empty">No sales orders found.</td></tr><?php endif; ?>
        </table>
    </div>
    <p><?= h($total) ?> sales order(s) found</p>
    <?php pager($page, $pages); ?>
</div>

<?php elseif ($view == "form"): ?>

<div class="card">
    <h1><?= h($titles[$view]) ?></h1>
    <form method="post" action="sales_orders.php?show=form<?= $id ? "&amp;id=" . h($id) : "" ?>" class="validate" novalidate>
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <div class="form-grid">
            <div class="field">
                <label for="customer_id">Customer</label>
                <select id="customer_id" name="customer_id" required data-label="Customer">
                    <option value="">-- Select --</option>
                    <?php foreach ($customerList as $c): ?>
                        <option value="<?= h($c["customer_id"]) ?>" <?= $data["customer_id"] == $c["customer_id"] ? "selected" : "" ?>><?= h($c["name"]) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php field_error($errors, "customer_id"); ?>
            </div>
            <div class="field">
                <label for="order_date">Order date</label>
                <input type="date" id="order_date" name="order_date" value="<?= h($data["order_date"]) ?>" required data-label="Order date">
                <?php field_error($errors, "order_date"); ?>
            </div>
            <div class="field">
                <label for="note">Note (optional)</label>
                <input type="text" id="note" name="note" value="<?= h($data["note"]) ?>" maxlength="200" data-label="Note">
                <?php field_error($errors, "note"); ?>
            </div>
        </div>

        <h2>Products</h2>
        <?php field_error($errors, "lines"); ?>
        <?php lines_table($lines, $products, "Unit price", "price"); ?>

        <button type="submit" name="save" value="pending" class="btn">Save Order</button>
        <button type="submit" name="save" value="complete" class="btn">Save and Complete</button>
        <a href="sales_orders.php" class="btn light">Cancel</a>
    </form>
</div>

<?php else: ?>

<div class="card">
    <h1>Sales Order <?= h(order_no("SO", $id)) ?></h1>
    <dl>
        <dt>Status</dt><dd><?= h($orderStatus) ?></dd>
        <dt>Customer</dt><dd><?= h($order["customer"]) ?></dd>
        <dt>Contact</dt><dd><?= h($order["email"]) ?>, <?= h($order["phone"]) ?></dd>
        <dt>Address</dt><dd><?= h($order["address"] !== "" ? $order["address"] : "-") ?></dd>
        <dt>Order date</dt><dd><?= h($order["order_date"]) ?></dd>
        <dt>Created by</dt><dd><?= h($order["username"]) ?></dd>
        <dt>Note</dt><dd><?= h($order["note"] !== "" ? $order["note"] : "-") ?></dd>
    </dl>

    <div class="table-wrap">
        <table>
            <tr><th>SKU</th><th>Product</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Total</th></tr>
            <?php foreach ($items as $i): ?>
                <tr>
                    <td><?= h($i["sku"]) ?></td>
                    <td><?= h($i["name"]) ?></td>
                    <td class="num"><?= h($i["quantity"]) ?></td>
                    <td class="num"><?= h(money($i["unit_price"])) ?></td>
                    <td class="num"><?= h(money($i["quantity"] * $i["unit_price"])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total"><td colspan="4" class="num">Order total</td><td class="num"><?= h(money($orderTotal)) ?></td></tr>
        </table>
    </div>

    <div class="actions-row no-print">
        <?php if ($orderStatus == "Pending"): ?>
            <a href="sales_orders.php?show=form&amp;id=<?= h($id) ?>" class="btn">Edit</a>
            <?php action_button($id, "complete", "Complete Order", "Complete this order? Stock will be reduced."); ?>
            <?php action_button($id, "cancel", "Cancel Order", "Cancel this sales order?"); ?>
        <?php elseif ($orderStatus == "Completed" && is_admin()): ?>
            <?php action_button($id, "cancel", "Cancel and Return Stock", "Cancel this completed order? The stock will be put back."); ?>
        <?php endif; ?>
        <button type="button" class="btn" data-print>Print</button>
        <a href="sales_orders.php" class="btn light">Back to list</a>
    </div>
</div>

<?php endif; ?>

<?php page_end(); ?>
