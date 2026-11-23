<?php
require_once "../includes/functions.php";
require_login();

// summary numbers (archived products are left out)
$totals = $pdo->query("
    SELECT COUNT(*) AS products,
           COALESCE(SUM(cost * quantity), 0) AS value,
           COALESCE(SUM(quantity <= reorder_level), 0) AS low
    FROM products WHERE is_active = 1")->fetch();
$openPurchases = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Draft', 'Ordered')")->fetchColumn();
$pendingSales = $pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status = 'Pending'")->fetchColumn();
$monthSales = $pdo->query("
    SELECT COALESCE(SUM(i.quantity * i.unit_price), 0)
    FROM sales_orders o JOIN sales_order_items i ON i.so_id = o.so_id
    WHERE o.status = 'Completed' AND o.order_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();

// products that need reordering
$lowStock = $pdo->query("
    SELECT product_id, sku, name, quantity, reorder_level
    FROM products WHERE is_active = 1 AND quantity <= reorder_level
    ORDER BY quantity ASC LIMIT 8")->fetchAll();

// orders waiting for action
$pending = $pdo->query("
    SELECT o.so_id, o.order_date, c.name AS customer, COALESCE(SUM(i.quantity * i.unit_price), 0) AS total
    FROM sales_orders o JOIN customers c ON c.customer_id = o.customer_id
    LEFT JOIN sales_order_items i ON i.so_id = o.so_id
    WHERE o.status = 'Pending' GROUP BY o.so_id ORDER BY o.so_id DESC LIMIT 5")->fetchAll();
$open = $pdo->query("
    SELECT o.po_id, o.status, o.expected_date, s.name AS supplier, COALESCE(SUM(i.quantity * i.unit_cost), 0) AS total
    FROM purchase_orders o JOIN suppliers s ON s.supplier_id = o.supplier_id
    LEFT JOIN purchase_order_items i ON i.po_id = o.po_id
    WHERE o.status IN ('Draft', 'Ordered') GROUP BY o.po_id ORDER BY o.po_id DESC LIMIT 5")->fetchAll();

// latest stock movements
$recent = $pdo->query("
    SELECT m.created_at, m.type, m.reason, m.quantity, p.name AS product, u.username
    FROM stock_movements m
    JOIN products p ON p.product_id = m.product_id
    JOIN users u ON u.user_id = m.user_id
    ORDER BY m.movement_id DESC LIMIT 5")->fetchAll();

page_start("Dashboard");
?>

<h1>Dashboard</h1>

<div class="stats">
    <div class="stat"><strong><?= h($totals["products"]) ?></strong>Active Products</div>
    <div class="stat"><strong><?= h(money($totals["value"])) ?></strong>Stock Value (cost)</div>
    <div class="stat"><strong><?= h($totals["low"]) ?></strong>Low Stock</div>
    <div class="stat"><strong><?= h($pendingSales) ?></strong>Pending Sales Orders</div>
    <div class="stat"><strong><?= h($openPurchases) ?></strong>Open Purchase Orders</div>
    <div class="stat"><strong><?= h(money($monthSales)) ?></strong>Sales This Month</div>
</div>

<div class="card">
    <div class="page-head">
        <h2>Low Stock Products</h2>
        <a href="reports.php?type=lowstock">Full report</a>
    </div>
    <div class="table-wrap">
        <table>
            <tr><th>SKU</th><th>Product</th><th>In Stock</th><th>Reorder Level</th><th></th></tr>
            <?php foreach ($lowStock as $p): ?>
                <tr>
                    <td><?= h($p["sku"]) ?></td>
                    <td><?= h($p["name"]) ?></td>
                    <td><?= h($p["quantity"]) ?></td>
                    <td><?= h($p["reorder_level"]) ?></td>
                    <td class="actions">
                        <a href="stock.php?product_id=<?= h($p["product_id"]) ?>">Restock</a> |
                        <a href="purchase_orders.php?show=form&amp;product_id=<?= h($p["product_id"]) ?>">Order</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lowStock): ?><tr><td colspan="5" class="empty">All products are well stocked.</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<div class="card">
    <div class="page-head">
        <h2>Pending Sales Orders</h2>
        <a href="sales_orders.php?status=Pending">View all</a>
    </div>
    <div class="table-wrap">
        <table>
            <tr><th>Order</th><th>Customer</th><th>Date</th><th class="num">Total</th><th></th></tr>
            <?php foreach ($pending as $o): ?>
                <tr>
                    <td><?= h(order_no("SO", $o["so_id"])) ?></td>
                    <td><?= h($o["customer"]) ?></td>
                    <td><?= h($o["order_date"]) ?></td>
                    <td class="num"><?= h(money($o["total"])) ?></td>
                    <td><a href="sales_orders.php?show=view&amp;id=<?= h($o["so_id"]) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pending): ?><tr><td colspan="5" class="empty">No pending sales orders.</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<div class="card">
    <div class="page-head">
        <h2>Open Purchase Orders</h2>
        <a href="purchase_orders.php">View all</a>
    </div>
    <div class="table-wrap">
        <table>
            <tr><th>Order</th><th>Supplier</th><th>Status</th><th class="hide-sm">Expected</th><th class="num">Total</th><th></th></tr>
            <?php foreach ($open as $o): ?>
                <tr>
                    <td><?= h(order_no("PO", $o["po_id"])) ?></td>
                    <td><?= h($o["supplier"]) ?></td>
                    <td><?= h($o["status"]) ?></td>
                    <td class="hide-sm"><?= h($o["expected_date"] ?? "-") ?></td>
                    <td class="num"><?= h(money($o["total"])) ?></td>
                    <td><a href="purchase_orders.php?show=view&amp;id=<?= h($o["po_id"]) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$open): ?><tr><td colspan="6" class="empty">No open purchase orders.</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<div class="card">
    <div class="page-head">
        <h2>Recent Stock Movements</h2>
        <a href="stock.php">View all</a>
    </div>
    <div class="table-wrap">
        <table>
            <tr><th>Date</th><th>Product</th><th>Type</th><th class="hide-sm">Reason</th><th>Qty</th><th class="hide-sm">By</th></tr>
            <?php foreach ($recent as $m): ?>
                <tr>
                    <td><?= h($m["created_at"]) ?></td>
                    <td><?= h($m["product"]) ?></td>
                    <td><?= h($m["type"]) ?></td>
                    <td class="hide-sm"><?= h($m["reason"]) ?></td>
                    <td><?= h($m["quantity"]) ?></td>
                    <td class="hide-sm"><?= h($m["username"]) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="6" class="empty">No stock movements yet.</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<?php page_end(); ?>
