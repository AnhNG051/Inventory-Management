<?php
require_once "../includes/functions.php";
require_login();

$tabs = ["valuation" => "Stock Value", "lowstock" => "Low Stock", "sales" => "Sales", "movements" => "Movements"];
$type = array_key_exists(get("type"), $tabs) ? get("type") : "valuation";

// date range used by the Sales and Movements reports
$from = valid_date(get("from")) ? get("from") : date("Y-m-01", strtotime("-5 months"));
$to = valid_date(get("to")) ? get("to") : date("Y-m-d");

// ---------- CSV export of the Movements report (same date range as the screen) ----------
if ($type == "movements" && get("export") == "csv") {
    $stmt = $pdo->prepare("SELECT m.created_at, p.sku, p.name, m.type, m.reason, m.quantity, m.note, u.username
                           FROM stock_movements m
                           JOIN products p ON p.product_id = m.product_id
                           JOIN users u ON u.user_id = m.user_id
                           WHERE DATE(m.created_at) BETWEEN ? AND ?
                           ORDER BY m.movement_id DESC");
    $stmt->execute([$from, $to]);
    csv_download("movements", ["Date", "SKU", "Product", "Type", "Reason", "Quantity", "Note", "User"], $stmt);
}

// ---------- Stock Value: what the stock on the shelves is worth ----------
if ($type == "valuation") {
    $rows = $pdo->query("
        SELECT c.name AS category, COUNT(p.product_id) AS products, COALESCE(SUM(p.quantity), 0) AS units,
               COALESCE(SUM(p.quantity * p.cost), 0) AS cost_value, COALESCE(SUM(p.quantity * p.price), 0) AS retail_value
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.category_id AND p.is_active = 1
        GROUP BY c.category_id ORDER BY c.name")->fetchAll();
}

// ---------- Low Stock: what to order, minus what is already on order ----------
if ($type == "lowstock") {
    $rows = $pdo->query("
        SELECT p.product_id, p.sku, p.name, s.name AS supplier, p.quantity, p.reorder_level,
               (SELECT COALESCE(SUM(i.quantity), 0) FROM purchase_order_items i
                JOIN purchase_orders o ON o.po_id = i.po_id
                WHERE i.product_id = p.product_id AND o.status = 'Ordered') AS on_order,
               GREATEST(p.reorder_level * 2 - p.quantity, 1) AS suggested
        FROM products p JOIN suppliers s ON s.supplier_id = p.supplier_id
        WHERE p.is_active = 1 AND p.quantity <= p.reorder_level
        ORDER BY p.quantity - p.reorder_level, p.name")->fetchAll();
}

// ---------- Sales: completed orders by month, best products and customers ----------
if ($type == "sales") {
    $range = [$from, $to];
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS month, COUNT(DISTINCT o.so_id) AS orders, SUM(i.quantity) AS units,
               SUM(i.quantity * i.unit_price) AS revenue, SUM(i.quantity * i.unit_cost) AS cost
        FROM sales_orders o JOIN sales_order_items i ON i.so_id = o.so_id
        WHERE o.status = 'Completed' AND o.order_date BETWEEN ? AND ?
        GROUP BY month ORDER BY month DESC");
    $stmt->execute($range);
    $rows = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT p.sku, p.name, SUM(i.quantity) AS units, SUM(i.quantity * i.unit_price) AS revenue
        FROM sales_orders o JOIN sales_order_items i ON i.so_id = o.so_id JOIN products p ON p.product_id = i.product_id
        WHERE o.status = 'Completed' AND o.order_date BETWEEN ? AND ?
        GROUP BY p.product_id ORDER BY revenue DESC LIMIT 5");
    $stmt->execute($range);
    $topProducts = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT c.name, COUNT(DISTINCT o.so_id) AS orders, SUM(i.quantity * i.unit_price) AS revenue
        FROM sales_orders o JOIN sales_order_items i ON i.so_id = o.so_id JOIN customers c ON c.customer_id = o.customer_id
        WHERE o.status = 'Completed' AND o.order_date BETWEEN ? AND ?
        GROUP BY c.customer_id ORDER BY revenue DESC LIMIT 5");
    $stmt->execute($range);
    $topCustomers = $stmt->fetchAll();
}

// ---------- Movements: every stock change in the date range ----------
if ($type == "movements") {
    $product = filter_var(get("product"), FILTER_VALIDATE_INT);
    $moveType = in_array(get("move"), ["IN", "OUT"], true) ? get("move") : "";
    $where = ["DATE(m.created_at) BETWEEN ? AND ?"];
    $params = [$from, $to];
    if ($product) { $where[] = "m.product_id = ?"; $params[] = $product; }
    if ($moveType !== "") { $where[] = "m.type = ?"; $params[] = $moveType; }
    $whereSql = "WHERE " . implode(" AND ", $where);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements m $whereSql");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    [$page, $pages, $offset] = paging($total, 20);

    $stmt = $pdo->prepare("SELECT m.created_at, p.sku, p.name, m.type, m.reason, m.quantity, m.note, u.username
                           FROM stock_movements m
                           JOIN products p ON p.product_id = m.product_id
                           JOIN users u ON u.user_id = m.user_id
                           $whereSql ORDER BY m.movement_id DESC LIMIT 20 OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $productList = $pdo->query("SELECT product_id, sku, name FROM products ORDER BY name")->fetchAll();
}

page_start("Reports");
?>

<h1>Reports</h1>

<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="reports.php?type=<?= h($key) ?>" <?= $type == $key ? 'class="current"' : "" ?>><?= h($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($type == "valuation"): ?>
    <?php $sum = ["products" => 0, "units" => 0, "cost_value" => 0, "retail_value" => 0];
    foreach ($rows as $r) foreach ($sum as $k => $v) $sum[$k] += $r[$k]; ?>
    <div class="card">
        <h2>Stock Value by Category</h2>
        <p>Active products only. Cost value is what the stock cost us, retail value is what it sells for.</p>
        <div class="table-wrap">
            <table>
                <tr><th>Category</th><th class="num">Products</th><th class="num">Units</th><th class="num">Cost value</th><th class="num">Retail value</th></tr>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r["category"]) ?></td>
                        <td class="num"><?= h($r["products"]) ?></td>
                        <td class="num"><?= h($r["units"]) ?></td>
                        <td class="num"><?= h(money($r["cost_value"])) ?></td>
                        <td class="num"><?= h(money($r["retail_value"])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total">
                    <td>Total</td>
                    <td class="num"><?= h($sum["products"]) ?></td>
                    <td class="num"><?= h($sum["units"]) ?></td>
                    <td class="num"><?= h(money($sum["cost_value"])) ?></td>
                    <td class="num"><?= h(money($sum["retail_value"])) ?></td>
                </tr>
            </table>
        </div>
    </div>

<?php elseif ($type == "lowstock"): ?>
    <div class="card">
        <h2>Low Stock</h2>
        <p>Products at or below their reorder level. Suggested quantity brings the stock to twice the reorder level.</p>
        <div class="table-wrap">
            <table>
                <tr><th>SKU</th><th>Product</th><th class="hide-sm">Supplier</th><th class="num">In stock</th><th class="num">Reorder level</th>
                    <th class="num">On order</th><th class="num">Suggested</th><th></th></tr>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r["sku"]) ?></td>
                        <td><?= h($r["name"]) ?></td>
                        <td class="hide-sm"><?= h($r["supplier"]) ?></td>
                        <td class="num"><?= h($r["quantity"]) ?></td>
                        <td class="num"><?= h($r["reorder_level"]) ?></td>
                        <td class="num"><?= h($r["on_order"]) ?></td>
                        <td class="num"><?= h($r["suggested"]) ?></td>
                        <td><a href="purchase_orders.php?show=form&amp;product_id=<?= h($r["product_id"]) ?>">Order</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="8" class="empty">All products are well stocked.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

<?php elseif ($type == "sales"): ?>
    <div class="card">
        <form method="get" action="reports.php" class="inline-form">
            <input type="hidden" name="type" value="sales">
            <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
            <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
            <button type="submit" class="btn">Show</button>
        </form>
    </div>
    <?php $sum = ["orders" => 0, "units" => 0, "revenue" => 0, "cost" => 0];
    foreach ($rows as $r) foreach ($sum as $k => $v) $sum[$k] += $r[$k]; ?>
    <div class="card">
        <h2>Sales by Month</h2>
        <p>Completed orders only. Profit is revenue minus the product cost saved when the order was completed.</p>
        <div class="table-wrap">
            <table>
                <tr><th>Month</th><th class="num">Orders</th><th class="num">Units</th><th class="num">Revenue</th><th class="num">Cost</th>
                    <th class="num">Profit</th><th class="num hide-sm">Margin</th></tr>
                <?php foreach ($rows as $r): ?>
                    <?php $profit = $r["revenue"] - $r["cost"]; ?>
                    <tr>
                        <td><?= h($r["month"]) ?></td>
                        <td class="num"><?= h($r["orders"]) ?></td>
                        <td class="num"><?= h($r["units"]) ?></td>
                        <td class="num"><?= h(money($r["revenue"])) ?></td>
                        <td class="num"><?= h(money($r["cost"])) ?></td>
                        <td class="num"><?= h(money($profit)) ?></td>
                        <td class="num hide-sm"><?= $r["revenue"] > 0 ? h(number_format($profit / $r["revenue"] * 100, 1)) . "%" : "-" ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows): $profit = $sum["revenue"] - $sum["cost"]; ?>
                    <tr class="total">
                        <td>Total</td>
                        <td class="num"><?= h($sum["orders"]) ?></td>
                        <td class="num"><?= h($sum["units"]) ?></td>
                        <td class="num"><?= h(money($sum["revenue"])) ?></td>
                        <td class="num"><?= h(money($sum["cost"])) ?></td>
                        <td class="num"><?= h(money($profit)) ?></td>
                        <td class="num hide-sm"><?= $sum["revenue"] > 0 ? h(number_format($profit / $sum["revenue"] * 100, 1)) . "%" : "-" ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="7" class="empty">No completed sales in this date range.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <div class="card">
        <h2>Top 5 Products</h2>
        <div class="table-wrap">
            <table>
                <tr><th>SKU</th><th>Product</th><th class="num">Units</th><th class="num">Revenue</th></tr>
                <?php foreach ($topProducts as $r): ?>
                    <tr><td><?= h($r["sku"]) ?></td><td><?= h($r["name"]) ?></td><td class="num"><?= h($r["units"]) ?></td><td class="num"><?= h(money($r["revenue"])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$topProducts): ?><tr><td colspan="4" class="empty">No data.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>
    <div class="card">
        <h2>Top 5 Customers</h2>
        <div class="table-wrap">
            <table>
                <tr><th>Customer</th><th class="num">Orders</th><th class="num">Revenue</th></tr>
                <?php foreach ($topCustomers as $r): ?>
                    <tr><td><?= h($r["name"]) ?></td><td class="num"><?= h($r["orders"]) ?></td><td class="num"><?= h(money($r["revenue"])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$topCustomers): ?><tr><td colspan="3" class="empty">No data.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

<?php else: ?>
    <div class="card">
        <form method="get" action="reports.php" class="inline-form">
            <input type="hidden" name="type" value="movements">
            <div class="field grow">
                <label for="product">Product</label>
                <select id="product" name="product">
                    <option value="">All</option>
                    <?php foreach ($productList as $p): ?>
                        <option value="<?= h($p["product_id"]) ?>" <?= $product == $p["product_id"] ? "selected" : "" ?>><?= h($p["sku"] . " - " . $p["name"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="move">Type</label>
                <select id="move" name="move">
                    <option value="">All</option>
                    <option value="IN" <?= $moveType == "IN" ? "selected" : "" ?>>Stock In</option>
                    <option value="OUT" <?= $moveType == "OUT" ? "selected" : "" ?>>Stock Out</option>
                </select>
            </div>
            <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
            <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
            <div>
                <button type="submit" class="btn">Show</button>
                <a href="reports.php?<?= h(http_build_query(["type" => "movements", "from" => $from, "to" => $to, "export" => "csv"])) ?>" class="btn">Export CSV</a>
            </div>
        </form>
    </div>
    <div class="card">
        <h2>Stock Movements</h2>
        <div class="table-wrap">
            <table>
                <tr><th>Date</th><th>Product</th><th>Type</th><th>Reason</th><th class="num">Qty</th><th class="hide-sm">Note</th><th class="hide-sm">By</th></tr>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r["created_at"]) ?></td>
                        <td><?= h($r["sku"] . " - " . $r["name"]) ?></td>
                        <td><?= h($r["type"]) ?></td>
                        <td><?= h($r["reason"]) ?></td>
                        <td class="num"><?= h($r["quantity"]) ?></td>
                        <td class="hide-sm"><?= h($r["note"]) ?></td>
                        <td class="hide-sm"><?= h($r["username"]) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="7" class="empty">No movements in this range.</td></tr><?php endif; ?>
            </table>
        </div>
        <p><?= h($total) ?> movement(s)</p>
        <?php pager($page, $pages); ?>
    </div>
<?php endif; ?>

<?php page_end(); ?>
