<?php
// Products and categories.
//   products.php                       product list
//   products.php?show=form[&id=N]      add / edit a product
//   products.php?show=categories       category list
//   products.php?show=category_form    add / edit a category
//   products.php?export=csv            download the product list
require_once "../includes/functions.php";
require_once "../includes/orders.php";
require_login();

$view = in_array(get("show"), ["form", "categories", "category_form"], true) ? get("show") : "list";
$id = filter_var(get("id"), FILTER_VALIDATE_INT);   // edit mode when an id is given
$action = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_check();
    $action = post("action");
}

// ---------- CSV export ----------
if (get("export") == "csv") {
    $stmt = $pdo->query("SELECT p.sku, p.name, c.name, s.name, p.cost, p.price, p.quantity, p.reorder_level,
                                IF(p.is_active, 'Active', 'Archived')
                         FROM products p
                         JOIN categories c ON c.category_id = p.category_id
                         JOIN suppliers s ON s.supplier_id = p.supplier_id
                         ORDER BY p.name");
    csv_download("products", ["SKU", "Name", "Category", "Supplier", "Cost", "Price", "Quantity", "Reorder level", "Status"], $stmt);
}

// ---------- Delete, archive, restore (POST, admin only) ----------
if (in_array($action, ["delete", "archive", "restore", "delete_category"], true)) {
    require_admin();

    if ($action == "delete_category") {
        $categoryId = filter_var(post("category_id"), FILTER_VALIDATE_INT);
        if ($categoryId) {
            // a category that still has products cannot be deleted
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
            $stmt->execute([$categoryId]);
            $used = $stmt->fetchColumn();
            if ($used > 0) {
                flash("Cannot delete: $used product(s) still use this category.", "error");
            } else {
                $pdo->prepare("DELETE FROM categories WHERE category_id = ?")->execute([$categoryId]);
                log_action("delete", "category", $categoryId, "Category deleted");
                flash("Category deleted.");
            }
        }
        redirect("products.php?show=categories");
    }

    $productId = filter_var(post("product_id"), FILTER_VALIDATE_INT);
    if ($productId && $action == "delete") {
        try {
            $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$productId]);
            log_action("delete", "product", $productId, "Product deleted");
            flash("Product deleted.");
        } catch (PDOException $e) {   // stock history or orders still point to this product
            flash("Cannot delete: this product has stock history or orders. Archive it instead.", "error");
        }
    } elseif ($productId) {
        $pdo->prepare("UPDATE products SET is_active = ? WHERE product_id = ?")->execute([$action == "restore" ? 1 : 0, $productId]);
        log_action($action, "product", $productId, "Product " . $action . "d");
        flash($action == "restore" ? "Product restored." : "Product archived. It is hidden from new orders and stock changes.");
    }
    redirect("products.php");
}

$errors = [];

// ---------- Product form ----------
if ($view == "form") {
    $data = ["sku" => "", "name" => "", "category_id" => "", "supplier_id" => "",
             "cost" => "", "price" => "", "quantity" => "0", "reorder_level" => "5"];
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        if (!$data) {
            flash("Product not found.", "error");
            redirect("products.php");
        }
    }

    $categoryList = $pdo->query("SELECT category_id, name FROM categories ORDER BY name")->fetchAll();
    $supplierList = $pdo->query("SELECT supplier_id, name FROM suppliers ORDER BY name")->fetchAll();

    if ($action == "save_product") {
        foreach (["sku", "name", "category_id", "supplier_id", "cost", "price", "reorder_level"] as $field) {
            $data[$field] = post($field);
        }
        if (!$id) $data["quantity"] = post("quantity");   // stock of an existing product changes on the Stock page

        $intRange = ["options" => ["min_range" => 0, "max_range" => 1000000]];

        if (!preg_match("/^[A-Za-z0-9-]{3,20}$/", $data["sku"]))
            $errors["sku"] = "SKU must be 3-20 letters, numbers or dashes";
        if (strlen($data["name"]) < 2 || strlen($data["name"]) > 100)
            $errors["name"] = "Name must be 2-100 characters";
        if (!in_ids($data["category_id"], $categoryList, "category_id"))
            $errors["category_id"] = "Choose a category";
        if (!in_ids($data["supplier_id"], $supplierList, "supplier_id"))
            $errors["supplier_id"] = "Choose a supplier";
        if (!preg_match("/^\d{1,8}(\.\d{1,2})?$/", $data["cost"]))
            $errors["cost"] = "Cost must be a number like 12.50";
        if (!preg_match("/^\d{1,8}(\.\d{1,2})?$/", $data["price"]))
            $errors["price"] = "Price must be a number like 19.99";
        if (!$id && filter_var($data["quantity"], FILTER_VALIDATE_INT, $intRange) === false)
            $errors["quantity"] = "Quantity must be a whole number (0 or more)";
        if (filter_var($data["reorder_level"], FILTER_VALIDATE_INT, $intRange) === false)
            $errors["reorder_level"] = "Reorder level must be a whole number (0 or more)";

        // SKU must be unique
        if (!isset($errors["sku"])) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ? AND product_id <> ?");
            $stmt->execute([$data["sku"], $id ?: 0]);
            if ($stmt->fetchColumn() > 0) $errors["sku"] = "This SKU is already used";
        }

        if (!$errors) {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE products SET sku = ?, name = ?, category_id = ?, supplier_id = ?,
                                       cost = ?, price = ?, reorder_level = ? WHERE product_id = ?");
                $stmt->execute([$data["sku"], $data["name"], $data["category_id"], $data["supplier_id"],
                                $data["cost"], $data["price"], $data["reorder_level"], $id]);
                log_action("update", "product", $id, $data["sku"] . " " . $data["name"]);
                flash("Product updated.");
            } else {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category_id, supplier_id, cost, price, quantity, reorder_level)
                                       VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
                $stmt->execute([$data["sku"], $data["name"], $data["category_id"], $data["supplier_id"],
                                $data["cost"], $data["price"], $data["reorder_level"]]);
                $newId = $pdo->lastInsertId();
                if ($data["quantity"] > 0) {   // starting stock goes through the history like any other change
                    move_stock($newId, "IN", (int) $data["quantity"], "Opening", "Opening stock");
                }
                $pdo->commit();
                log_action("create", "product", $newId, $data["sku"] . " " . $data["name"]);
                flash("Product added.");
            }
            redirect("products.php");
        }
    }
}

// ---------- Category form ----------
if ($view == "category_form") {
    $data = ["name" => "", "description" => ""];
    if ($id) {
        $stmt = $pdo->prepare("SELECT name, description FROM categories WHERE category_id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        if (!$data) {
            flash("Category not found.", "error");
            redirect("products.php?show=categories");
        }
    }

    if ($action == "save_category") {
        $data["name"] = post("name");
        $data["description"] = post("description");

        if (strlen($data["name"]) < 2 || strlen($data["name"]) > 50)
            $errors["name"] = "Name must be 2-50 characters";
        if (strlen($data["description"]) > 200)
            $errors["description"] = "Description can be at most 200 characters";

        // name must be unique
        if (!isset($errors["name"])) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ? AND category_id <> ?");
            $stmt->execute([$data["name"], $id ?: 0]);
            if ($stmt->fetchColumn() > 0) $errors["name"] = "This category already exists";
        }

        if (!$errors) {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE category_id = ?");
                $stmt->execute([$data["name"], $data["description"], $id]);
                log_action("update", "category", $id, $data["name"]);
                flash("Category updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                $stmt->execute([$data["name"], $data["description"]]);
                log_action("create", "category", $pdo->lastInsertId(), $data["name"]);
                flash("Category added.");
            }
            redirect("products.php?show=categories");
        }
    }
}

// ---------- Product list: search, filter, sort, paging ----------
if ($view == "list") {
    $q = get("q");
    $category = filter_var(get("category"), FILTER_VALIDATE_INT);
    $supplier = filter_var(get("supplier"), FILTER_VALIDATE_INT);
    $lowOnly = get("low") === "1";
    $status = in_array(get("status"), ["archived", "all"], true) ? get("status") : "active";

    $sorts = ["name" => "p.name", "sku" => "p.sku", "price" => "p.price", "quantity" => "p.quantity"];
    $sort = array_key_exists(get("sort"), $sorts) ? get("sort") : "name";   // only allowed columns
    $dir = get("dir") === "desc" ? "DESC" : "ASC";

    $where = [];
    $params = [];
    if ($q !== "") {
        $where[] = "(p.name LIKE ? OR p.sku LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($category) { $where[] = "p.category_id = ?"; $params[] = $category; }
    if ($supplier) { $where[] = "p.supplier_id = ?"; $params[] = $supplier; }
    if ($lowOnly)  { $where[] = "p.quantity <= p.reorder_level"; }
    if ($status != "all") { $where[] = "p.is_active = ?"; $params[] = $status == "active" ? 1 : 0; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $perPage = 10;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSql");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    [$page, $pages, $offset] = paging($total, $perPage);

    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS category, s.name AS supplier
        FROM products p
        JOIN categories c ON c.category_id = p.category_id
        JOIN suppliers s ON s.supplier_id = p.supplier_id
        $whereSql
        ORDER BY {$sorts[$sort]} $dir
        LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    $categoryList = $pdo->query("SELECT category_id, name FROM categories ORDER BY name")->fetchAll();
    $supplierList = $pdo->query("SELECT supplier_id, name FROM suppliers ORDER BY name")->fetchAll();
}

// ---------- Category list: search and sort ----------
if ($view == "categories") {
    $q = get("q");
    $sorts = ["name" => "c.name", "products" => "products"];
    $sort = array_key_exists(get("sort"), $sorts) ? get("sort") : "name";
    $dir = get("dir") === "desc" ? "DESC" : "ASC";

    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(p.product_id) AS products
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.category_id
        WHERE c.name LIKE ? OR c.description LIKE ?
        GROUP BY c.category_id
        ORDER BY {$sorts[$sort]} $dir");
    $stmt->execute(["%$q%", "%$q%"]);
    $categories = $stmt->fetchAll();
}

$titles = ["list" => "Products", "form" => ($id ? "Edit Product" : "Add Product"),
           "categories" => "Categories", "category_form" => ($id ? "Edit Category" : "Add Category")];
page_start($titles[$view]);
?>

<?php if ($view == "list"): ?>

<div class="page-head">
    <h1>Products</h1>
    <div>
        <a href="products.php?export=csv" class="btn">Export CSV</a>
        <a href="products.php?show=form" class="btn">+ Add Product</a>
    </div>
</div>

<div class="tabs">
    <a href="products.php" class="current">Products</a>
    <a href="products.php?show=categories">Categories</a>
</div>

<div class="card">
    <form method="get" action="products.php" class="filters filters-p">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="dir" value="<?= h(strtolower($dir)) ?>">
        <div class="field">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Name or SKU">
        </div>
        <div class="field">
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">All</option>
                <?php foreach ($categoryList as $c): ?>
                    <option value="<?= h($c["category_id"]) ?>" <?= $category == $c["category_id"] ? "selected" : "" ?>><?= h($c["name"]) ?></option>
                <?php endforeach; ?>
            </select>
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
                <option value="active" <?= $status == "active" ? "selected" : "" ?>>Active</option>
                <option value="archived" <?= $status == "archived" ? "selected" : "" ?>>Archived</option>
                <option value="all" <?= $status == "all" ? "selected" : "" ?>>All</option>
            </select>
        </div>
        <label class="check"><input type="checkbox" name="low" value="1" <?= $lowOnly ? "checked" : "" ?>> Low stock only</label>
        <div>
            <button type="submit" class="btn">Filter</button>
            <a href="products.php" class="btn light">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th class="hide-xs"><?= sort_link("SKU", "sku", $sort, $dir) ?></th>
                <th><?= sort_link("Name", "name", $sort, $dir) ?></th>
                <th class="hide-sm">Category</th>
                <th class="hide-sm">Supplier</th>
                <th class="hide-sm">Cost</th>
                <th><?= sort_link("Price", "price", $sort, $dir) ?></th>
                <th><?= sort_link("Qty", "quantity", $sort, $dir) ?></th>
                <th>Actions</th>
            </tr>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td class="hide-xs"><?= h($p["sku"]) ?></td>
                    <td><?= h($p["name"]) ?><?= $p["is_active"] ? "" : " (archived)" ?></td>
                    <td class="hide-sm"><?= h($p["category"]) ?></td>
                    <td class="hide-sm"><?= h($p["supplier"]) ?></td>
                    <td class="hide-sm"><?= h(money($p["cost"])) ?></td>
                    <td><?= h(money($p["price"])) ?></td>
                    <td><?= h($p["quantity"]) ?><?= $p["quantity"] <= $p["reorder_level"] ? " (low)" : "" ?></td>
                    <td class="actions">
                        <a href="products.php?show=form&amp;id=<?= h($p["product_id"]) ?>">Edit</a>
                        <?php if ($p["is_active"]): ?>
                            | <a href="stock.php?product_id=<?= h($p["product_id"]) ?>">Stock</a>
                        <?php endif; ?>
                        <?php if (is_admin()): ?>
                            <form method="post" action="products.php" data-confirm="<?= $p["is_active"] ? "Archive" : "Restore" ?> this product?">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?= h($p["product_id"]) ?>">
                                <input type="hidden" name="action" value="<?= $p["is_active"] ? "archive" : "restore" ?>">
                                <button type="submit" class="btn small"><?= $p["is_active"] ? "Archive" : "Restore" ?></button>
                            </form>
                            <form method="post" action="products.php" class="confirm-delete">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?= h($p["product_id"]) ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn small danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$products): ?><tr><td colspan="8" class="empty">No products found.</td></tr><?php endif; ?>
        </table>
    </div>

    <p><?= h($total) ?> product(s) found</p>
    <?php pager($page, $pages); ?>
</div>

<?php elseif ($view == "form"): ?>

<div class="card">
    <h1><?= h($titles[$view]) ?></h1>
    <form method="post" action="products.php?show=form<?= $id ? "&amp;id=" . h($id) : "" ?>" class="validate" novalidate>
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save_product">
        <div class="form-grid">
            <div class="field">
                <label for="sku">SKU</label>
                <input type="text" id="sku" name="sku" value="<?= h($data["sku"]) ?>" required minlength="3" maxlength="20"
                       pattern="[A-Za-z0-9-]+" data-label="SKU" data-message="SKU can use letters, numbers and dashes only">
                <?php field_error($errors, "sku"); ?>
            </div>
            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="<?= h($data["name"]) ?>" required minlength="2" maxlength="100"
                       data-label="Name">
                <?php field_error($errors, "name"); ?>
            </div>
            <div class="field">
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id" required data-label="Category">
                    <option value="">-- Select --</option>
                    <?php foreach ($categoryList as $c): ?>
                        <option value="<?= h($c["category_id"]) ?>" <?= $data["category_id"] == $c["category_id"] ? "selected" : "" ?>><?= h($c["name"]) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php field_error($errors, "category_id"); ?>
            </div>
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
                <label for="cost">Cost ($) - what we pay</label>
                <input type="number" id="cost" name="cost" value="<?= h($data["cost"]) ?>" required min="0" max="99999999"
                       step="0.01" data-label="Cost">
                <?php field_error($errors, "cost"); ?>
            </div>
            <div class="field">
                <label for="price">Price ($) - what we charge</label>
                <input type="number" id="price" name="price" value="<?= h($data["price"]) ?>" required min="0" max="99999999"
                       step="0.01" data-label="Price">
                <?php field_error($errors, "price"); ?>
            </div>
            <div class="field">
                <label for="quantity">Quantity in Stock</label>
                <?php if ($id): ?>
                    <input type="number" id="quantity" value="<?= h($data["quantity"]) ?>" readonly>
                    <span class="error"></span>
                    <small>Change stock on the <a href="stock.php?product_id=<?= h($id) ?>">Stock page</a>.</small>
                <?php else: ?>
                    <input type="number" id="quantity" name="quantity" value="<?= h($data["quantity"]) ?>" required min="0"
                           max="1000000" step="1" data-label="Quantity">
                    <?php field_error($errors, "quantity"); ?>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="reorder_level">Reorder Level</label>
                <input type="number" id="reorder_level" name="reorder_level" value="<?= h($data["reorder_level"]) ?>" required
                       min="0" max="1000000" step="1" data-label="Reorder level">
                <?php field_error($errors, "reorder_level"); ?>
            </div>
        </div>
        <button type="submit" class="btn">Save</button>
        <a href="products.php" class="btn light">Cancel</a>
    </form>
</div>

<?php elseif ($view == "categories"): ?>

<div class="page-head">
    <h1>Categories</h1>
    <a href="products.php?show=category_form" class="btn">+ Add Category</a>
</div>

<div class="tabs">
    <a href="products.php">Products</a>
    <a href="products.php?show=categories" class="current">Categories</a>
</div>

<div class="card">
    <form method="get" action="products.php" class="page-head">
        <input type="hidden" name="show" value="categories">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search categories" class="grow">
        <div>
            <button type="submit" class="btn">Search</button>
            <a href="products.php?show=categories" class="btn light">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th><?= sort_link("Name", "name", $sort, $dir) ?></th>
                <th class="hide-sm">Description</th>
                <th><?= sort_link("Products", "products", $sort, $dir) ?></th>
                <th>Actions</th>
            </tr>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><?= h($c["name"]) ?></td>
                    <td class="hide-sm"><?= h($c["description"]) ?></td>
                    <td><?= h($c["products"]) ?></td>
                    <td class="actions">
                        <a href="products.php?show=category_form&amp;id=<?= h($c["category_id"]) ?>">Edit</a>
                        <?php if (is_admin()): ?>
                            <form method="post" action="products.php?show=categories" class="confirm-delete">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="category_id" value="<?= h($c["category_id"]) ?>">
                                <input type="hidden" name="action" value="delete_category">
                                <button type="submit" class="btn small danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$categories): ?><tr><td colspan="4" class="empty">No categories found.</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<?php else: ?>

<div class="card narrow">
    <h1><?= h($titles[$view]) ?></h1>
    <form method="post" action="products.php?show=category_form<?= $id ? "&amp;id=" . h($id) : "" ?>" class="validate" novalidate>
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save_category">
        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="<?= h($data["name"]) ?>" required minlength="2" maxlength="50"
                   data-label="Name">
            <?php field_error($errors, "name"); ?>
        </div>
        <div class="field">
            <label for="description">Description (optional)</label>
            <input type="text" id="description" name="description" value="<?= h($data["description"]) ?>" maxlength="200"
                   data-label="Description">
            <?php field_error($errors, "description"); ?>
        </div>
        <button type="submit" class="btn">Save</button>
        <a href="products.php?show=categories" class="btn light">Cancel</a>
    </form>
</div>

<?php endif; ?>

<?php page_end(); ?>
