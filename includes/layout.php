<?php
// Page layout. Call page_start("Title") at the top of a page and page_end() at the bottom.
// Login and register use page_start("Title", true) to hide the menu.

// menu link; it gets a box on its own page
function nav_link($file, $label) {
    $current = basename($_SERVER["SCRIPT_NAME"]) == $file ? ' class="current"' : "";
    echo '<a href="' . h($file) . '"' . $current . '>' . h($label) . '</a>';
}

function page_start($title, $public = false) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> - Inventory System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<header class="topbar">
    <span class="brand">Inventory System</span>
    <?php if (!$public): ?>
        <nav>
            <?php
            nav_link("dashboard.php", "Dashboard");
            nav_link("products.php", "Products");
            nav_link("contacts.php", "Contacts");
            nav_link("purchase_orders.php", "Purchases");
            nav_link("sales_orders.php", "Sales");
            nav_link("stock.php", "Stock");
            nav_link("reports.php", "Reports");
            if (is_admin()) {
                nav_link("admin.php", "Admin");
            }
            ?>
        </nav>
        <form method="post" action="login.php" class="logout">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="logout">
            <a href="profile.php"><?= h($_SESSION["username"]) ?> (<?= h($_SESSION["role"]) ?>)</a>
            <button type="submit" class="btn small">Logout</button>
        </form>
    <?php endif; ?>
</header>
<main class="container">
    <?php show_flash(); ?>
<?php
}

function page_end() {
?>
</main>
<footer class="footer">INFO 3135 Course Project - Inventory Management System</footer>
<script src="../assets/js/app.js"></script>
</body>
</html>
<?php
}
