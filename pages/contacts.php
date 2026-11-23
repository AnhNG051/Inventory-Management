<?php
// Suppliers and customers.
//   contacts.php                                  supplier list
//   contacts.php?type=customers                   customer list
//   contacts.php?type=suppliers&show=form[&id=N]  add / edit (works for either type)
require_once "../includes/functions.php";
require_login();

// $type is checked against this list, so it is safe to use as a table name below
$type = in_array(get("type"), ["suppliers", "customers"], true) ? get("type") : "suppliers";
$isSupplier = $type == "suppliers";
$key = $isSupplier ? "supplier_id" : "customer_id";
$one = $isSupplier ? "Supplier" : "Customer";
$many = $isSupplier ? "Suppliers" : "Customers";
$fields = $isSupplier ? ["name", "email", "phone"] : ["name", "email", "phone", "address"];
$base = "contacts.php?type=" . $type;

$showForm = get("show") == "form";
$id = filter_var(get("id"), FILTER_VALIDATE_INT);   // edit mode when an id is given
$action = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_check();
    $action = post("action");
}

// ---------- Delete (POST, admin only) ----------
if ($action == "delete") {
    require_admin();
    $recordId = filter_var(post("record_id"), FILTER_VALIDATE_INT);
    if ($recordId) {
        // a record that orders or products still use cannot be deleted
        if ($isSupplier) {
            $stmt = $pdo->prepare("SELECT (SELECT COUNT(*) FROM products WHERE supplier_id = ?) +
                                          (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?)");
            $stmt->execute([$recordId, $recordId]);
            $used = $stmt->fetchColumn();
            $reason = "this supplier is used by $used product(s) or purchase order(s)";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_orders WHERE customer_id = ?");
            $stmt->execute([$recordId]);
            $used = $stmt->fetchColumn();
            $reason = "this customer has $used sales order(s)";
        }
        if ($used > 0) {
            flash("Cannot delete: $reason.", "error");
        } else {
            $pdo->prepare("DELETE FROM $type WHERE $key = ?")->execute([$recordId]);
            log_action("delete", strtolower($one), $recordId, "$one deleted");
            flash("$one deleted.");
        }
    }
    redirect($base);
}

// ---------- Add / edit form ----------
$errors = [];
if ($showForm) {
    $data = array_fill_keys($fields, "");
    if ($id) {
        $stmt = $pdo->prepare("SELECT " . implode(", ", $fields) . " FROM $type WHERE $key = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        if (!$data) {
            flash("$one not found.", "error");
            redirect($base);
        }
    }

    if ($action == "save") {
        foreach ($fields as $field) {
            $data[$field] = post($field);
        }

        if (strlen($data["name"]) < 2 || strlen($data["name"]) > 100)
            $errors["name"] = "Name must be 2-100 characters";
        if (strlen($data["email"]) > 100 || !filter_var($data["email"], FILTER_VALIDATE_EMAIL))
            $errors["email"] = "Enter a valid email address";
        if (!preg_match("/^[0-9+() -]{7,20}$/", $data["phone"]))
            $errors["phone"] = "Phone must be 7-20 digits (spaces, dashes, + and brackets allowed)";
        if (!$isSupplier && strlen($data["address"]) > 200)
            $errors["address"] = "Address can be at most 200 characters";

        // name must be unique
        if (!isset($errors["name"])) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $type WHERE name = ? AND $key <> ?");
            $stmt->execute([$data["name"], $id ?: 0]);
            if ($stmt->fetchColumn() > 0) $errors["name"] = "This " . strtolower($one) . " already exists";
        }

        if (!$errors) {
            $values = array_values($data);
            if ($id) {
                $sets = implode(", ", array_map(fn($f) => "$f = ?", $fields));
                $pdo->prepare("UPDATE $type SET $sets WHERE $key = ?")->execute([...$values, $id]);
                log_action("update", strtolower($one), $id, $data["name"]);
                flash("$one updated.");
            } else {
                $marks = implode(", ", array_fill(0, count($fields), "?"));
                $pdo->prepare("INSERT INTO $type (" . implode(", ", $fields) . ") VALUES ($marks)")->execute($values);
                log_action("create", strtolower($one), $pdo->lastInsertId(), $data["name"]);
                flash("$one added.");
            }
            redirect($base);
        }
    }
} else {
    // ---------- List: search, sort, paging (10 per page) ----------
    $q = get("q");
    $sorts = ["name" => "t.name", "used" => "used"];
    $sort = array_key_exists(get("sort"), $sorts) ? get("sort") : "name";
    $dir = get("dir") === "desc" ? "DESC" : "ASC";

    // "used" = products of a supplier, or sales orders of a customer
    $join = $isSupplier ? "LEFT JOIN products x ON x.supplier_id = t.supplier_id"
                        : "LEFT JOIN sales_orders x ON x.customer_id = t.customer_id";
    $usedColumn = $isSupplier ? "x.product_id" : "x.so_id";
    $search = "t.name LIKE ? OR t.email LIKE ? OR t.phone LIKE ?";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $type t WHERE $search");
    $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    $total = (int) $stmt->fetchColumn();
    [$page, $pages, $offset] = paging($total, 10);

    $stmt = $pdo->prepare("
        SELECT t.*, COUNT($usedColumn) AS used
        FROM $type t
        $join
        WHERE $search
        GROUP BY t.$key
        ORDER BY {$sorts[$sort]} $dir
        LIMIT 10 OFFSET $offset");
    $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    $rows = $stmt->fetchAll();
}

page_start($showForm ? ($id ? "Edit $one" : "Add $one") : $many);
?>

<?php if ($showForm): ?>

<div class="card<?= $isSupplier ? " narrow" : "" ?>">
    <h1><?= h($id ? "Edit $one" : "Add $one") ?></h1>
    <form method="post" action="<?= h($base) ?>&amp;show=form<?= $id ? "&amp;id=" . h($id) : "" ?>" class="validate" novalidate>
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <div class="<?= $isSupplier ? "" : "form-grid" ?>">
            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="<?= h($data["name"]) ?>" required minlength="2" maxlength="100"
                       data-label="Name">
                <?php field_error($errors, "name"); ?>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= h($data["email"]) ?>" required maxlength="100"
                       data-label="Email">
                <?php field_error($errors, "email"); ?>
            </div>
            <div class="field">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" value="<?= h($data["phone"]) ?>" required maxlength="20"
                       pattern="[0-9+() -]{7,20}" data-label="Phone"
                       data-message="Phone must be 7-20 digits (spaces, dashes, + and brackets allowed)">
                <?php field_error($errors, "phone"); ?>
            </div>
            <?php if (!$isSupplier): ?>
                <div class="field">
                    <label for="address">Address (optional)</label>
                    <input type="text" id="address" name="address" value="<?= h($data["address"]) ?>" maxlength="200"
                           data-label="Address">
                    <?php field_error($errors, "address"); ?>
                </div>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn">Save</button>
        <a href="<?= h($base) ?>" class="btn light">Cancel</a>
    </form>
</div>

<?php else: ?>

<div class="page-head">
    <h1><?= h($many) ?></h1>
    <a href="<?= h($base) ?>&amp;show=form" class="btn">+ Add <?= h($one) ?></a>
</div>

<div class="tabs">
    <a href="contacts.php?type=suppliers"<?= $isSupplier ? ' class="current"' : "" ?>>Suppliers</a>
    <a href="contacts.php?type=customers"<?= $isSupplier ? "" : ' class="current"' ?>>Customers</a>
</div>

<div class="card">
    <form method="get" action="contacts.php" class="page-head">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search name, email or phone" class="grow">
        <div>
            <button type="submit" class="btn">Search</button>
            <a href="<?= h($base) ?>" class="btn light">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th><?= sort_link("Name", "name", $sort, $dir) ?></th>
                <th class="hide-sm">Email</th>
                <th class="hide-sm">Phone</th>
                <?php if (!$isSupplier): ?><th class="hide-sm">Address</th><?php endif; ?>
                <th><?= sort_link($isSupplier ? "Products" : "Orders", "used", $sort, $dir) ?></th>
                <th>Actions</th>
            </tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= h($r["name"]) ?></td>
                    <td class="hide-sm"><?= h($r["email"]) ?></td>
                    <td class="hide-sm"><?= h($r["phone"]) ?></td>
                    <?php if (!$isSupplier): ?><td class="hide-sm"><?= h($r["address"]) ?></td><?php endif; ?>
                    <td><?= h($r["used"]) ?></td>
                    <td class="actions">
                        <a href="<?= h($base) ?>&amp;show=form&amp;id=<?= h($r[$key]) ?>">Edit</a>
                        <?php if (!$isSupplier): ?>
                            | <a href="sales_orders.php?show=form&amp;customer_id=<?= h($r[$key]) ?>">New order</a>
                        <?php endif; ?>
                        <?php if (is_admin()): ?>
                            <form method="post" action="<?= h($base) ?>" class="confirm-delete">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="record_id" value="<?= h($r[$key]) ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn small danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="empty">No <?= h(strtolower($many)) ?> found.</td></tr><?php endif; ?>
        </table>
    </div>
    <p><?= h($total) ?> <?= h(strtolower($one)) ?>(s) found</p>
    <?php pager($page, $pages); ?>
</div>

<?php endif; ?>

<?php page_end(); ?>
