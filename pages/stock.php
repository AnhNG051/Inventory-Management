<?php
require_once "../includes/functions.php";
require_once "../includes/orders.php";
require_login();

// only active products can have stock changes
$productList = $pdo->query("SELECT product_id, sku, name, quantity FROM products WHERE is_active = 1 ORDER BY name")->fetchAll();
$selected = filter_var(get("product_id"), FILTER_VALIDATE_INT);   // product chosen from a link
$data = ["product_id" => $selected ?: "", "type" => "IN", "reason" => "", "quantity" => "", "note" => ""];
$errors = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_check();
    foreach (["product_id", "type", "reason", "quantity", "note"] as $field) {
        $data[$field] = post($field);
    }

    if (!in_ids($data["product_id"], $productList, "product_id"))
        $errors["product_id"] = "Choose a product";
    if (!in_array($data["type"], ["IN", "OUT"], true))
        $errors["type"] = "Choose Stock In or Stock Out";
    elseif (!in_array($data["reason"], $data["type"] == "IN" ? REASONS_IN : REASONS_OUT, true))
        $errors["reason"] = "Choose a reason that fits Stock " . ($data["type"] == "IN" ? "In" : "Out");
    if (filter_var($data["quantity"], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 1000000]]) === false)
        $errors["quantity"] = "Quantity must be a whole number (1 or more)";
    if (strlen($data["note"]) > 200)
        $errors["note"] = "Note can be at most 200 characters";

    if (!$errors) {
        $pdo->beginTransaction();
        if (move_stock($data["product_id"], $data["type"], (int) $data["quantity"], $data["reason"], $data["note"])) {
            $pdo->commit();
            log_action("stock_" . strtolower($data["type"]), "product", $data["product_id"],
                       $data["quantity"] . " units, " . $data["reason"]);
            flash("Stock updated.");
            redirect("stock.php?product_id=" . $data["product_id"]);
        }
        $pdo->rollBack();
        $errors["quantity"] = "Not enough stock for this Stock Out";
    }
}

// movement history (one product when selected), 15 per page
$where = $selected ? "WHERE m.product_id = ?" : "";
$params = $selected ? [$selected] : [];
$stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements m $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
[$page, $pages, $offset] = paging($total, 15);

$stmt = $pdo->prepare("SELECT m.created_at, m.type, m.reason, m.quantity, m.note, p.name AS product, u.username
                       FROM stock_movements m
                       JOIN products p ON p.product_id = m.product_id
                       JOIN users u ON u.user_id = m.user_id
                       $where ORDER BY m.movement_id DESC LIMIT 15 OFFSET $offset");
$stmt->execute($params);
$history = $stmt->fetchAll();

page_start("Stock");
?>

<h1>Stock In / Out</h1>

<div class="card">
    <form method="post" action="stock.php<?= $selected ? "?product_id=" . h($selected) : "" ?>" class="validate" novalidate>
        <?php csrf_field(); ?>
        <div class="form-grid">
            <div class="field">
                <label for="product_id">Product</label>
                <select id="product_id" name="product_id" required data-label="Product">
                    <option value="">-- Select --</option>
                    <?php foreach ($productList as $p): ?>
                        <option value="<?= h($p["product_id"]) ?>" <?= $data["product_id"] == $p["product_id"] ? "selected" : "" ?>>
                            <?= h($p["sku"] . " - " . $p["name"] . " (" . $p["quantity"] . " in stock)") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php field_error($errors, "product_id"); ?>
            </div>
            <div class="field">
                <label for="type">Type</label>
                <select id="type" name="type" required data-label="Type">
                    <option value="IN" <?= $data["type"] == "IN" ? "selected" : "" ?>>Stock In (receive)</option>
                    <option value="OUT" <?= $data["type"] == "OUT" ? "selected" : "" ?>>Stock Out (use / sell / write off)</option>
                </select>
                <?php field_error($errors, "type"); ?>
            </div>
            <div class="field">
                <label for="reason">Reason</label>
                <select id="reason" name="reason" required data-label="Reason">
                    <option value="">-- Select --</option>
                    <?php foreach (array_unique(array_merge(REASONS_IN, REASONS_OUT)) as $r): ?>
                        <option value="<?= h($r) ?>" data-type="<?= (in_array($r, REASONS_IN) ? "IN " : "") . (in_array($r, REASONS_OUT) ? "OUT" : "") ?>"
                                <?= $data["reason"] == $r ? "selected" : "" ?>><?= h($r) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php field_error($errors, "reason"); ?>
            </div>
            <div class="field">
                <label for="quantity">Quantity</label>
                <input type="number" id="quantity" name="quantity" value="<?= h($data["quantity"]) ?>" required min="1"
                       max="1000000" step="1" data-label="Quantity">
                <?php field_error($errors, "quantity"); ?>
            </div>
            <div class="field">
                <label for="note">Note (optional)</label>
                <input type="text" id="note" name="note" value="<?= h($data["note"]) ?>" maxlength="200" data-label="Note">
                <?php field_error($errors, "note"); ?>
            </div>
        </div>
        <button type="submit" class="btn">Save Movement</button>
    </form>
</div>

<div class="card">
    <div class="page-head">
        <h2>Movement History</h2>
        <?php if ($selected): ?><a href="stock.php">Show all products</a><?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <tr><th>Date</th><th>Product</th><th>Type</th><th>Reason</th><th>Qty</th><th class="hide-sm">Note</th><th class="hide-sm">By</th></tr>
            <?php foreach ($history as $m): ?>
                <tr>
                    <td><?= h($m["created_at"]) ?></td>
                    <td><?= h($m["product"]) ?></td>
                    <td><?= h($m["type"]) ?></td>
                    <td><?= h($m["reason"]) ?></td>
                    <td><?= h($m["quantity"]) ?></td>
                    <td class="hide-sm"><?= h($m["note"]) ?></td>
                    <td class="hide-sm"><?= h($m["username"]) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="7" class="empty">No movements yet.</td></tr><?php endif; ?>
        </table>
    </div>
    <p><?= h($total) ?> movement(s)</p>
    <?php pager($page, $pages); ?>
</div>

<?php page_end(); ?>
