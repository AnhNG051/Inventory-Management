<?php
// Helpers shared by the Stock page, purchase orders and sales orders.

const REASONS_IN = ["Purchase", "Return", "Correction", "Other"];
const REASONS_OUT = ["Sale", "Damaged", "Lost", "Correction", "Other"];

// change one product's stock and write the history row. Call it inside a transaction.
// Returns false when the product is missing or an OUT is bigger than the stock.
function move_stock($productId, $type, $qty, $reason, $note) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT quantity FROM products WHERE product_id = ? FOR UPDATE");   // lock the row
    $stmt->execute([$productId]);
    $current = $stmt->fetchColumn();
    if ($current === false || ($type == "OUT" && $qty > $current)) {
        return false;
    }
    $change = $type == "IN" ? $qty : -$qty;
    $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE product_id = ?")->execute([$change, $productId]);
    $pdo->prepare("INSERT INTO stock_movements (product_id, user_id, type, reason, quantity, note) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$productId, $_SESSION["user_id"], $type, $reason, $qty, $note]);
    return true;
}

// products that can be chosen on an order (active ones, plus any already on the order)
function selectable_products($extraIds = []) {
    global $pdo;
    $extraIds = array_map("intval", $extraIds) ?: [0];
    $marks = implode(",", array_fill(0, count($extraIds), "?"));
    $stmt = $pdo->prepare("SELECT product_id, sku, name, quantity, cost, price FROM products
                           WHERE is_active = 1 OR product_id IN ($marks) ORDER BY name");
    $stmt->execute($extraIds);
    return $stmt->fetchAll();
}

// read the order lines from the form (product_id[], quantity[], price[]); blank rows are skipped
function read_lines() {
    $columns = [];
    foreach (["product_id", "quantity", "price"] as $name) {
        $columns[$name] = is_array($_POST[$name] ?? null) ? array_values($_POST[$name]) : [];
    }
    $lines = [];
    foreach ($columns["product_id"] as $i => $id) {
        $line = [];
        foreach ($columns as $name => $values) {
            $line[$name] = is_string($values[$i] ?? null) ? trim($values[$i]) : "";
        }
        if ($line["product_id"] !== "" || $line["quantity"] !== "" || $line["price"] !== "") {
            $lines[] = $line;
        }
    }
    return $lines;
}

// check the lines; returns an error text or "" when everything is fine
function check_lines($lines, $products) {
    if (!$lines) return "Add at least one product line";
    if (count($lines) > 50) return "An order can have at most 50 lines";
    $seen = [];
    foreach ($lines as $i => $line) {
        $n = $i + 1;
        if (!in_ids($line["product_id"], $products, "product_id")) return "Line $n: choose a product";
        if (isset($seen[$line["product_id"]])) return "Line $n: this product is already on the order";
        $seen[$line["product_id"]] = true;
        if (filter_var($line["quantity"], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 100000]]) === false)
            return "Line $n: quantity must be a whole number (1 or more)";
        if (!preg_match("/^\d{1,8}(\.\d{1,2})?$/", $line["price"])) return "Line $n: price must be a number like 19.99";
    }
    return "";
}

// order total from lines that were already checked
function lines_total($lines) {
    $total = 0;
    foreach ($lines as $line) $total += $line["quantity"] * $line["price"];
    return $total;
}

// one row of the lines table ($line is null for an empty row)
function line_row($line, $products) {
    ?>
    <tr class="line">
        <td>
            <select name="product_id[]" class="line-product" required data-label="Product">
                <option value="">-- Select --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= h($p["product_id"]) ?>" data-price="<?= h($p["_default"]) ?>"
                        <?= $line && $line["product_id"] == $p["product_id"] ? "selected" : "" ?>>
                        <?= h($p["sku"] . " - " . $p["name"] . " (" . $p["quantity"] . " in stock)") ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="number" name="quantity[]" class="line-qty" value="<?= h($line["quantity"] ?? "") ?>"
                   required min="1" max="100000" step="1" data-label="Quantity"></td>
        <td><input type="number" name="price[]" class="line-price" value="<?= h($line["price"] ?? "") ?>"
                   required min="0" max="99999999" step="0.01" data-label="Price"></td>
        <td class="line-total">$0.00</td>
        <td><button type="button" class="btn small remove-line">Remove</button></td>
    </tr>
    <?php
}

// the whole lines table. $priceField is "cost" (purchase orders) or "price" (sales orders).
function lines_table($lines, $products, $priceLabel, $priceField) {
    foreach ($products as &$p) $p["_default"] = $p[$priceField];
    unset($p);
    ?>
    <div class="table-wrap">
        <table class="lines">
            <tr><th>Product</th><th>Qty</th><th><?= h($priceLabel) ?> ($)</th><th>Total</th><th></th></tr>
            <tbody id="lines">
                <?php foreach ($lines ?: [null] as $line) line_row($line, $products); ?>
            </tbody>
        </table>
    </div>
    <template id="line-template"><?php line_row(null, $products); ?></template>
    <p><button type="button" class="btn small" id="add-line">+ Add line</button></p>
    <p>Order total: <strong id="order-total">$0.00</strong></p>
    <?php
}

// ---------- Status changes that move stock (each one runs as a single transaction) ----------

// purchase order Ordered -> Received: add the stock and remember the latest cost. Returns "" or an error text.
function receive_purchase($poId) {
    global $pdo;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT status FROM purchase_orders WHERE po_id = ? FOR UPDATE");   // blocks a double receive
    $stmt->execute([$poId]);
    if ($stmt->fetchColumn() !== "Ordered") {
        $pdo->rollBack();
        return "Only an ordered purchase order can be received.";
    }
    $stmt = $pdo->prepare("SELECT product_id, quantity, unit_cost FROM purchase_order_items WHERE po_id = ? ORDER BY product_id");
    $stmt->execute([$poId]);
    foreach ($stmt->fetchAll() as $item) {
        if (!move_stock($item["product_id"], "IN", (int) $item["quantity"], "Purchase", order_no("PO", $poId))) {
            $pdo->rollBack();
            return "Could not add the stock. Nothing was changed.";
        }
        $pdo->prepare("UPDATE products SET cost = ? WHERE product_id = ?")->execute([$item["unit_cost"], $item["product_id"]]);
    }
    $pdo->prepare("UPDATE purchase_orders SET status = 'Received' WHERE po_id = ?")->execute([$poId]);
    $pdo->commit();
    return "";
}

// sales order Pending -> Completed: take the stock out. Returns "" or an error text.
function complete_sale($soId) {
    global $pdo;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT status FROM sales_orders WHERE so_id = ? FOR UPDATE");
    $stmt->execute([$soId]);
    if ($stmt->fetchColumn() !== "Pending") {
        $pdo->rollBack();
        return "Only a pending sales order can be completed.";
    }
    $stmt = $pdo->prepare("SELECT i.item_id, i.product_id, i.quantity, p.name, p.quantity AS in_stock, p.cost
                           FROM sales_order_items i JOIN products p ON p.product_id = i.product_id
                           WHERE i.so_id = ? ORDER BY i.product_id");
    $stmt->execute([$soId]);
    foreach ($stmt->fetchAll() as $item) {
        if (!move_stock($item["product_id"], "OUT", (int) $item["quantity"], "Sale", order_no("SO", $soId))) {
            $pdo->rollBack();
            return "Not enough stock for " . $item["name"] . " (needs " . $item["quantity"] . ", only " . $item["in_stock"] . " in stock).";
        }
        // keep today's cost on the line so profit reports stay correct when the cost changes later
        $pdo->prepare("UPDATE sales_order_items SET unit_cost = ? WHERE item_id = ?")->execute([$item["cost"], $item["item_id"]]);
    }
    $pdo->prepare("UPDATE sales_orders SET status = 'Completed' WHERE so_id = ?")->execute([$soId]);
    $pdo->commit();
    return "";
}

// sales order Pending/Completed -> Cancelled. A completed order puts its stock back.
function cancel_sale($soId) {
    global $pdo;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT status FROM sales_orders WHERE so_id = ? FOR UPDATE");
    $stmt->execute([$soId]);
    $status = $stmt->fetchColumn();
    if ($status !== "Pending" && $status !== "Completed") {
        $pdo->rollBack();
        return "This sales order cannot be cancelled.";
    }
    if ($status == "Completed") {
        $stmt = $pdo->prepare("SELECT product_id, quantity FROM sales_order_items WHERE so_id = ? ORDER BY product_id");
        $stmt->execute([$soId]);
        foreach ($stmt->fetchAll() as $item) {
            move_stock($item["product_id"], "IN", (int) $item["quantity"], "Return", order_no("SO", $soId) . " cancelled");
        }
    }
    $pdo->prepare("UPDATE sales_orders SET status = 'Cancelled' WHERE so_id = ?")->execute([$soId]);
    $pdo->commit();
    return "";
}
