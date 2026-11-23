<?php
// Purchase orders (stock coming in from suppliers).
//   purchase_orders.php                            list
//   purchase_orders.php?show=form[&id=N]           new order, or edit a draft
//   purchase_orders.php?show=form&product_id=N     new order started from the low stock report
//   purchase_orders.php?show=view&id=N             one order: receive, cancel, print
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

// ---------- Status changes: mark as ordered, receive, cancel (POST) ----------
if (in_array($action, ["order", "receive", "cancel"], true)) {
    $poId = filter_var(post("po_id"), FILTER_VALIDATE_INT);
    $no = order_no("PO", (int) $poId);

    if ($poId && $action == "order") {
        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = 'Ordered' WHERE po_id = ? AND status = 'Draft'");
        $stmt->execute([$poId]);
        if ($stmt->rowCount()) {
            log_action("order", "purchase_order", $poId, "$no marked as ordered");
            flash("Purchase order marked as ordered.");
        } else {
            flash("Only a draft can be marked as ordered.", "error");
        }
    } elseif ($poId && $action == "receive") {
        $error = receive_purchase($poId);
        if ($error) {
            flash($error, "error");
        } else {
            log_action("receive", "purchase_order", $poId, "$no received, stock increased");
            flash("Stock received. Product quantities and costs are updated.");
        }
    } elseif ($poId && $action == "cancel") {
        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = 'Cancelled' WHERE po_id = ? AND status IN ('Draft', 'Ordered')");
        $stmt->execute([$poId]);
        if ($stmt->rowCount()) {
            log_action("cancel", "purchase_order", $poId, "$no cancelled");
            flash("Purchase order cancelled.");
        } else {
            flash("This purchase order cannot be cancelled.", "error");
        }
    }
    redirect("purchase_orders.php?show=view&id=" . (int) $poId);
}

// ---------- Order form ----------
if ($view == "form") {
    $data = ["supplier_id" => get("supplier_id"), "order_date" => date("Y-m-d"), "expected_date" => "", "note" => ""];
    $lines = [];
    $savedIds = [];   // products already on the saved order (may be archived)
    $errors = [];

    if ($id) {
        $stmt = $pdo->prepare("SELECT supplier_id, order_date, expected_date, note, status FROM purchase_orders WHERE po_id = ?");
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order || $order["status"] != "Draft") {
            flash("Only a draft purchase order can be edited.", "error");
            redirect("purchase_orders.php");
        }
        $data = ["supplier_id" => $order["supplier_id"], "order_date" => $order["order_date"],
                 "expected_date" => $order["expected_date"] ?? "", "note" => $order["note"]];
        $stmt = $pdo->prepare("SELECT product_id, quantity, unit_cost AS price FROM purchase_order_items WHERE po_id = ? ORDER BY item_id");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll();
        $savedIds = array_column($lines, "product_id");
    } elseif ($productId = filter_var(get("product_id"), FILTER_VALIDATE_INT)) {
        // coming from the low stock report: start with that product, enough to reach twice its reorder level
        $stmt = $pdo->prepare("SELECT product_id, supplier_id, cost, quantity, reorder_level FROM products WHERE product_id = ? AND is_active = 1");
        $stmt->execute([$productId]);
        if ($p = $stmt->fetch()) {
            $data["supplier_id"] = $p["supplier_id"];
            $lines = [["product_id" => $p["product_id"], "quantity" => max(1, $p["reorder_level"] * 2 - $p["quantity"]), "price" => $p["cost"]]];
        }
    }

    if ($action == "save") {
        foreach (["supplier_id", "order_date", "expected_date", "note"] as $field) {
            $data[$field] = post($field);
        }
        $lines = read_lines();
    }

    $supplierList = $pdo->query("SELECT supplier_id, name FROM suppliers ORDER BY name")->fetchAll();
    $products = selectable_products($savedIds);

    if ($action == "save") {
        if (!in_ids($data["supplier_id"], $supplierList, "supplier_id"))
            $errors["supplier_id"] = "Choose a supplier";
        if (!valid_date($data["order_date"]))
            $errors["order_date"] = "Enter a valid date";
        if ($data["expected_date"] !== "" && (!valid_date($data["expected_date"]) || $data["expected_date"] < $data["order_date"]))
            $errors["expected_date"] = "Expected date must be a valid date on or after the order date";
        if (strlen($data["note"]) > 200)
            $errors["note"] = "Note can be at most 200 characters";
        if ($lineError = check_lines($lines, $products))
            $errors["lines"] = $lineError;

        if (!$errors) {
            $status = post("save") == "order" ? "Ordered" : "Draft";
            $new = !$id;
            $pdo->beginTransaction();
            if ($new) {
                $pdo->prepare("INSERT INTO purchase_orders (supplier_id, user_id, status, order_date, expected_date, note) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$data["supplier_id"], $_SESSION["user_id"], $status, $data["order_date"], $data["expected_date"] ?: null, $data["note"]]);
                $id = $pdo->lastInsertId();
            } else {
                $pdo->prepare("UPDATE purchase_orders SET supplier_id = ?, status = ?, order_date = ?, expected_date = ?, note = ? WHERE po_id = ?")
                    ->execute([$data["supplier_id"], $status, $data["order_date"], $data["expected_date"] ?: null, $data["note"], $id]);
                $pdo->prepare("DELETE FROM purchase_order_items WHERE po_id = ?")->execute([$id]);
            }
            $insert = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_cost) VALUES (?, ?, ?, ?)");
            foreach ($lines as $line) {
                $insert->execute([$id, $line["product_id"], $line["quantity"], $line["price"]]);
            }
            $pdo->commit();
            log_action($new ? "create" : "update", "purchase_order", $id, order_no("PO", $id) . " (" . $status . ")");
            flash("Purchase order saved.");
            redirect("purchase_orders.php?show=view&id=$id");
        }
    }
}

// ---------- One order ----------
if ($view == "view") {
    $stmt = $pdo->prepare("SELECT o.*, s.name AS supplier, s.email, s.phone, u.username
                           FROM purchase_orders o
                           JOIN suppliers s ON s.supplier_id = o.supplier_id
                           JOIN users u ON u.user_id = o.user_id
                           WHERE o.po_id = ?");
    $stmt->execute([$id ?: 0]);
    $order = $stmt->fetch();
    if (!$order) {
        flash("Purchase order not found.", "error");
        redirect("purchase_orders.php");
    }

    $stmt = $pdo->prepare("SELECT i.quantity, i.unit_cost, p.sku, p.name
                           FROM purchase_order_items i JOIN products p ON p.product_id = i.product_id
                           WHERE i.po_id = ? ORDER BY i.item_id");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
    $orderTotal = 0;
    foreach ($items as $i) $orderTotal += $i["quantity"] * $i["unit_cost"];
    $orderStatus = $order["status"];
}

// ---------- List: search, filter, sort, paging ----------
if ($view == "list") {
    $q = get("q");
    $supplier = filter_var(get("supplier"), FILTER_VALIDATE_INT);
    $statuses = ["Draft", "Ordered", "Received", "Cancelled"];
    $status = in_array(get("status"), $statuses, true) ? get("status") : "";

    $sorts = ["id" => "o.po_id", "date" => "o.order_date", "total" => "total", "supplier" => "s.name"];
    $sort = array_key_exists(get("sort"), $sorts) ? get("sort") : "id";
    $dir = get("dir") === "asc" ? "ASC" : "DESC";

    $where = [];
    $params = [];
    if ($q !== "") {
        $where[] = "(s.name LIKE ? OR o.note LIKE ? OR o.po_id = ?)";   // "PO-0003" finds order 3
        array_push($params, "%$q%", "%$q%", (int) preg_replace("/\D/", "", $q));
    }
    if ($supplier) { $where[] = "o.supplier_id = ?"; $params[] = $supplier; }
    if ($status !== "") { $where[] = "o.status = ?"; $params[] = $status; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders o JOIN suppliers s ON s.supplier_id = o.supplier_id $whereSql");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    [$page, $pages, $offset] = paging($total, 10);

    $stmt = $pdo->prepare("
        SELECT o.po_id, o.order_date, o.expected_date, o.status, s.name AS supplier,
               COALESCE(SUM(i.quantity * i.unit_cost), 0) AS total
        FROM purchase_orders o
        JOIN suppliers s ON s.supplier_id = o.supplier_id
        LEFT JOIN purchase_order_items i ON i.po_id = o.po_id
        $whereSql
        GROUP BY o.po_id
        ORDER BY {$sorts[$sort]} $dir
        LIMIT 10 OFFSET $offset");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $supplierList = $pdo->query("SELECT supplier_id, name FROM suppliers ORDER BY name")->fetchAll();
}

// small POST button used in the action row of the order view
function action_button($id, $action, $label, $confirm = "") {
    echo '<form method="post" action="purchase_orders.php?show=view&amp;id=' . h($id) . '"' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : "") . '>';
    csrf_field();
    echo '<input type="hidden" name="po_id" value="' . h($id) . '"><input type="hidden" name="action" value="' . h($action) . '">';
    echo '<button type="submit" class="btn">' . h($label) . '</button></form>';
}

$titles = ["list" => "Purchase Orders", "form" => ($id ? "Edit Purchase Order" : "New Purchase Order"),
           "view" => order_no("PO", (int) $id)];
page_start($titles[$view]);
?>

<?php if ($view == "list"): ?>

<div class="page-head">
    <h1>Purchase Orders</h1>
    <a href="purchase_orders.php?show=form" class="btn">+ New Purchase Order</a>
</div>

<div class="card">
    <form method="get" action="purchase_orders.php" class="filters filters-wide">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="dir" value="<?= h(strtolower($dir)) ?>">
        <div class="field">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="PO number, supplier or note">
        </div>
        <div class="field">
            <label for="supplier">Supplier</label>
            <select id="supplier" name="supplier">
                <option value="">All</option>
                <?php foreach ($supplierList as $s): ?>
                    <option value="<?= h($s["supplier_id"]) ?>" <?= $supplier == $s["supplier_id"] ? "selected" : "" ?>><?= h($s["name"]) ?></option>
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
            <a href="purchase_orders.php" class="btn light">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th><?= sort_link("PO #", "id", $sort, $dir) ?></th>
                <th><?= sort_link("Supplier", "supplier", $sort, $dir) ?></th>
                <th><?= sort_link("Date", "date", $sort, $dir) ?></th>
                <th class="hide-sm">Expected</th>
                <th>Status</th>
                <th class="num"><?= sort_link("Total", "total", $sort, $dir) ?></th>
                <th></th>
            </tr>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?= h(order_no("PO", $o["po_id"])) ?></td>
                    <td><?= h($o["supplier"]) ?></td>
                    <td><?= h($o["order_date"]) ?></td>
                    <td class="hide-sm"><?= h($o["expected_date"] ?? "-") ?></td>
                    <td><?= h($o["status"]) ?></td>
                    <td class="num"><?= h(money($o["total"])) ?></td>
                    <td><a href="purchase_orders.php?show=view&amp;id=<?= h($o["po_id"]) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?><tr><td colspan="7" class="empty">No purchase orders found.</td></tr><?php endif; ?>
        </table>
    </div>
    <p><?= h($total) ?> purchase order(s) found</p>
    <?php pager($page, $pages); ?>
</div>

<?php elseif ($view == "form"): ?>

<div class="card">
    <h1><?= h($titles[$view]) ?></h1>
    <form method="post" action="purchase_orders.php?show=form<?= $id ? "&amp;id=" . h($id) : "" ?>" class="validate" novalidate>
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <div class="form-grid">
            <div class="field">
                <label for="supplier_id">Supplier</label>
                <select id="supplier_id" name="supplier_id" required data-label="Supplier">
                    <option value="">-- Select --</option>
                    <?php foreach ($supplierList as $s): ?>
                        <option value="<?= h($s["supplier_id"]) ?>" <?= $data["supplier_id"] == $s["supplier_id"] ? "selected" : "" ?>><?= h($s["name"]) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php field_error($errors, "supplier_id"); ?>
            </div>
            <div class="field">
                <label for="order_date">Order date</label>
                <input type="date" id="order_date" name="order_date" value="<?= h($data["order_date"]) ?>" required data-label="Order date">
                <?php field_error($errors, "order_date"); ?>
            </div>
            <div class="field">
                <label for="expected_date">Expected delivery (optional)</label>
                <input type="date" id="expected_date" name="expected_date" value="<?= h($data["expected_date"]) ?>" data-label="Expected date">
                <?php field_error($errors, "expected_date"); ?>
            </div>
            <div class="field">
                <label for="note">Note (optional)</label>
                <input type="text" id="note" name="note" value="<?= h($data["note"]) ?>" maxlength="200" data-label="Note">
                <?php field_error($errors, "note"); ?>
            </div>
        </div>

        <h2>Products</h2>
        <?php field_error($errors, "lines"); ?>
        <?php lines_table($lines, $products, "Unit cost", "cost"); ?>

        <button type="submit" name="save" value="draft" class="btn">Save as Draft</button>
        <button type="submit" name="save" value="order" class="btn">Save and Mark as Ordered</button>
        <a href="purchase_orders.php" class="btn light">Cancel</a>
    </form>
</div>

<?php else: ?>

<div class="card">
    <h1>Purchase Order <?= h(order_no("PO", $id)) ?></h1>
    <dl>
        <dt>Status</dt><dd><?= h($orderStatus) ?></dd>
        <dt>Supplier</dt><dd><?= h($order["supplier"]) ?> (<?= h($order["email"]) ?>, <?= h($order["phone"]) ?>)</dd>
        <dt>Order date</dt><dd><?= h($order["order_date"]) ?></dd>
        <dt>Expected delivery</dt><dd><?= h($order["expected_date"] ?? "-") ?></dd>
        <dt>Created by</dt><dd><?= h($order["username"]) ?></dd>
        <dt>Note</dt><dd><?= h($order["note"] !== "" ? $order["note"] : "-") ?></dd>
    </dl>

    <div class="table-wrap">
        <table>
            <tr><th>SKU</th><th>Product</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Total</th></tr>
            <?php foreach ($items as $i): ?>
                <tr>
                    <td><?= h($i["sku"]) ?></td>
                    <td><?= h($i["name"]) ?></td>
                    <td class="num"><?= h($i["quantity"]) ?></td>
                    <td class="num"><?= h(money($i["unit_cost"])) ?></td>
                    <td class="num"><?= h(money($i["quantity"] * $i["unit_cost"])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total"><td colspan="4" class="num">Order total</td><td class="num"><?= h(money($orderTotal)) ?></td></tr>
        </table>
    </div>

    <div class="actions-row no-print">
        <?php if ($orderStatus == "Draft"): ?>
            <a href="purchase_orders.php?show=form&amp;id=<?= h($id) ?>" class="btn">Edit</a>
            <?php action_button($id, "order", "Mark as Ordered"); ?>
        <?php endif; ?>
        <?php if ($orderStatus == "Ordered"): ?>
            <?php action_button($id, "receive", "Receive Stock", "Receive all items? Product stock will increase."); ?>
        <?php endif; ?>
        <?php if ($orderStatus == "Draft" || $orderStatus == "Ordered"): ?>
            <?php action_button($id, "cancel", "Cancel Order", "Cancel this purchase order?"); ?>
        <?php endif; ?>
        <button type="button" class="btn" data-print>Print</button>
        <a href="purchase_orders.php" class="btn light">Back to list</a>
    </div>
</div>

<?php endif; ?>

<?php page_end(); ?>
