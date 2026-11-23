# Inventory Management System (full version)

PHP 8, MySQL/MariaDB (PDO), HTML, CSS, JavaScript. No third-party libraries.

## Run it (XAMPP)
1. Copy this folder to `C:\xampp\htdocs\inventory_full`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. phpMyAdmin -> Import -> choose `database/database.sql` (creates `inventory_full_db` with sample data).
4. Open http://localhost/inventory_full/

| Role  | Username | Password |
|-------|----------|----------|
| Admin | admin    | Admin123 |
| Staff | staff    | Staff123 |

Database settings are in `config/database.php` (XAMPP default: root, no password).

## Files
- `pages/` - one file per area; each handles its list, form and detail views (`?show=form`, `?show=view&id=N`).
  `login.php` (login, register, logout), `dashboard.php`, `products.php` (products and categories), `contacts.php` (suppliers and customers),
  `purchase_orders.php`, `sales_orders.php`, `stock.php`, `reports.php`, `admin.php` (users and audit log), `profile.php`.
- `includes/` - `functions.php` (helpers, security, sessions), `orders.php` (stock and order rules), `layout.php` (page header and footer).
- `assets/` - `css/style.css`, `js/app.js`.  `config/database.php`.  `database/database.sql`.

## What it does
- **Accounts**: register, login, logout, change password, 30 minute idle timeout, lock after 5 wrong passwords (15 minutes).
- **Users (admin)**: change role, disable/enable, unlock, reset password (temporary password, forced change at next login).
- **Products**: add, edit, search, filter, sort, pages, archive/restore, delete (only when unused), cost and price, CSV export.
- **Categories, Suppliers, Customers**: add, edit, search, delete (blocked while in use).
- **Purchase orders**: draft -> ordered -> received (stock goes up, latest cost saved) or cancelled. Print view.
- **Sales orders**: pending -> completed (stock goes down, blocked when stock is short) or cancelled (admin can cancel a completed order and the stock comes back). Print view works as an invoice.
- **Stock**: manual stock in/out with a reason (purchase, sale, return, damaged, lost, correction, other) and full history.
- **Reports**: stock value by category, low stock with suggested order quantity and one-click purchase order, sales by month with profit, top products and customers, stock movements by date. CSV export for products, sales and movements.
- **Audit log (admin)**: who did what and when, with filters.

## Security
Prepared statements everywhere, `htmlspecialchars` on all output, CSRF token on every POST form, `password_hash`, session id regenerated at login, role checks on the server, strict Content-Security-Policy and other security headers, CSV cells that start with `=`, `+`, `-` or `@` are neutralised.

## Rules the system keeps
- Product quantity only changes through a stock movement (never by editing the product), so history always adds up.
- Stock changes run in database transactions with row locks, so two users cannot sell the same stock.
- Products, customers and suppliers used by orders cannot be deleted. Products are archived instead.
