-- Inventory Management System (full version) - database script (MySQL 8 / MariaDB)
-- Import in phpMyAdmin (Import tab) or run: mysql -u root < database.sql

DROP DATABASE IF EXISTS inventory_full_db;
CREATE DATABASE inventory_full_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventory_full_db;

-- 1. Users (login accounts)
CREATE TABLE users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(15)  NOT NULL UNIQUE,
    email         VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,          -- 0 = account disabled
    must_change   TINYINT(1) NOT NULL DEFAULT 0,          -- 1 = must set a new password at next login
    failed_logins INT NOT NULL DEFAULT 0,                 -- wrong passwords in a row
    locked_until  DATETIME NULL,                          -- login blocked until this time
    last_login    DATETIME NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Categories
CREATE TABLE categories (
    category_id   INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(50) NOT NULL UNIQUE,
    description   VARCHAR(200) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

-- 3. Suppliers (we buy from them)
CREATE TABLE suppliers (
    supplier_id   INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL UNIQUE,
    email         VARCHAR(100) NOT NULL,
    phone         VARCHAR(20)  NOT NULL
) ENGINE=InnoDB;

-- 4. Customers (we sell to them)
CREATE TABLE customers (
    customer_id   INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL UNIQUE,
    email         VARCHAR(100) NOT NULL,
    phone         VARCHAR(20)  NOT NULL,
    address       VARCHAR(200) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

-- 5. Products (cost = what we pay, price = what we charge; is_active 0 = archived)
CREATE TABLE products (
    product_id    INT AUTO_INCREMENT PRIMARY KEY,
    sku           VARCHAR(20)  NOT NULL UNIQUE,
    name          VARCHAR(100) NOT NULL,
    category_id   INT NOT NULL,
    supplier_id   INT NOT NULL,
    cost          DECIMAL(10,2) NOT NULL DEFAULT 0 CHECK (cost >= 0),
    price         DECIMAL(10,2) NOT NULL CHECK (price >= 0),
    quantity      INT NOT NULL DEFAULT 0 CHECK (quantity >= 0),
    reorder_level INT NOT NULL DEFAULT 5 CHECK (reorder_level >= 0),
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE RESTRICT,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id)  ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 6. Stock movements (history of every stock change, with the reason)
CREATE TABLE stock_movements (
    movement_id   INT AUTO_INCREMENT PRIMARY KEY,
    product_id    INT NOT NULL,
    user_id       INT NOT NULL,
    type          ENUM('IN', 'OUT') NOT NULL,
    reason        ENUM('Opening', 'Purchase', 'Sale', 'Return', 'Damaged', 'Lost', 'Correction', 'Other') NOT NULL DEFAULT 'Other',
    quantity      INT NOT NULL CHECK (quantity > 0),
    note          VARCHAR(200) NOT NULL DEFAULT '',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id)    REFERENCES users(user_id)       ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 7. Purchase orders (stock we buy from a supplier)
CREATE TABLE purchase_orders (
    po_id         INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id   INT NOT NULL,
    user_id       INT NOT NULL,
    status        ENUM('Draft', 'Ordered', 'Received', 'Cancelled') NOT NULL DEFAULT 'Draft',
    order_date    DATE NOT NULL,
    expected_date DATE NULL,
    note          VARCHAR(200) NOT NULL DEFAULT '',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id)     REFERENCES users(user_id)         ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE purchase_order_items (
    item_id       INT AUTO_INCREMENT PRIMARY KEY,
    po_id         INT NOT NULL,
    product_id    INT NOT NULL,
    quantity      INT NOT NULL CHECK (quantity > 0),
    unit_cost     DECIMAL(10,2) NOT NULL CHECK (unit_cost >= 0),
    FOREIGN KEY (po_id)      REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)   ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 8. Sales orders (stock we sell to a customer)
CREATE TABLE sales_orders (
    so_id         INT AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT NOT NULL,
    user_id       INT NOT NULL,
    status        ENUM('Pending', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
    order_date    DATE NOT NULL,
    note          VARCHAR(200) NOT NULL DEFAULT '',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id)     REFERENCES users(user_id)         ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE sales_order_items (
    item_id       INT AUTO_INCREMENT PRIMARY KEY,
    so_id         INT NOT NULL,
    product_id    INT NOT NULL,
    quantity      INT NOT NULL CHECK (quantity > 0),
    unit_price    DECIMAL(10,2) NOT NULL CHECK (unit_price >= 0),
    unit_cost     DECIMAL(10,2) NOT NULL DEFAULT 0,        -- product cost saved when the order is completed
    FOREIGN KEY (so_id)      REFERENCES sales_orders(so_id)  ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 9. Audit log (who did what and when)
CREATE TABLE audit_log (
    log_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NULL,
    username      VARCHAR(15) NOT NULL DEFAULT '',
    action        VARCHAR(30) NOT NULL,
    entity        VARCHAR(30) NOT NULL DEFAULT '',
    entity_id     INT NULL,
    details       VARCHAR(255) NOT NULL DEFAULT '',
    ip            VARCHAR(45) NOT NULL DEFAULT '',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Sample data. Passwords: admin -> Admin123, staff -> Staff123
-- Every product quantity equals opening stock + purchases - sales - adjustments.
-- ---------------------------------------------------------------
INSERT INTO users (username, email, password_hash, role, last_login) VALUES
('admin', 'admin@example.com', '$2y$10$cyO/5R/dFzVtOr56vmxvLeI8cgS3TfcLmX781NwyNJkeUesj26gWe', 'admin', NOW()),
('staff', 'staff@example.com', '$2y$10$uwXtvwJECffk2TkF4d4Y9O3IUigWfr8Bmkl/LaGQzZYFIAH/eC..a', 'staff', NOW());

INSERT INTO categories (name, description) VALUES
('Electronics', 'Computers, phones and accessories'),
('Office Supplies', 'Paper, pens and desk items'),
('Furniture', 'Desks, chairs and shelves'),
('Cleaning', 'Cleaning products and tools'),
('Networking', 'Routers, cables and switches');

INSERT INTO suppliers (name, email, phone) VALUES
('TechSource Ltd', 'sales@techsource.example', '604-555-0101'),
('Paper Plus', 'orders@paperplus.example', '604-555-0102'),
('Home Office Depot', 'info@hodepot.example', '778-555-0103'),
('NetWorks Inc', 'support@networks.example', '778-555-0104');

INSERT INTO customers (name, email, phone, address) VALUES
('Maple Dental Clinic', 'orders@mapledental.example', '604-555-0201', '120 Main St, Vancouver, BC'),
('Fraser Accounting Group', 'billing@fraseracct.example', '604-555-0202', '45 King George Blvd, Surrey, BC'),
('Westside Co-Working', 'hello@westsidecowork.example', '778-555-0203', '800 W Broadway, Vancouver, BC'),
('Green Leaf Cafe', 'owner@greenleaf.example', '778-555-0204', '22 Granville St, Burnaby, BC');

INSERT INTO products (sku, name, category_id, supplier_id, cost, price, quantity, reorder_level, is_active) VALUES
('ELEC-001', 'Wireless Mouse', 1, 1, 11.00, 19.99, 40, 10, 1),
('ELEC-002', 'USB-C Charger', 1, 1, 13.50, 24.50, 8, 10, 1),
('ELEC-003', 'Laptop Stand', 1, 1, 20.00, 35.00, 15, 5, 1),
('OFF-001', 'A4 Copy Paper (500)', 2, 2, 4.00, 6.75, 120, 30, 1),
('OFF-002', 'Ballpoint Pens (12)', 2, 2, 2.50, 4.25, 3, 20, 1),
('OFF-003', 'Sticky Notes', 2, 2, 1.50, 2.99, 60, 15, 1),
('FUR-001', 'Office Chair', 3, 3, 95.00, 149.00, 6, 3, 1),
('FUR-002', 'Standing Desk', 3, 3, 270.00, 399.00, 2, 3, 1),
('CLN-001', 'Disinfectant Wipes', 4, 3, 3.00, 5.49, 25, 10, 1),
('NET-001', 'Cat6 Cable 5m', 5, 4, 4.50, 8.99, 50, 15, 1),
('NET-002', 'WiFi Router', 5, 4, 58.00, 89.99, 4, 5, 1),
('NET-003', '8-Port Switch', 5, 4, 28.00, 45.00, 12, 4, 1),
('ELEC-004', 'Wired Keyboard (discontinued)', 1, 1, 15.00, 29.00, 0, 5, 0);

INSERT INTO purchase_orders (po_id, supplier_id, user_id, status, order_date, expected_date, note) VALUES
(1, 4, 1, 'Received', DATE_SUB(CURDATE(), INTERVAL 150 DAY), NULL, ''),
(2, 2, 2, 'Received', DATE_SUB(CURDATE(), INTERVAL 140 DAY), NULL, ''),
(3, 1, 1, 'Received', DATE_SUB(CURDATE(), INTERVAL 100 DAY), NULL, ''),
(4, 3, 1, 'Received', DATE_SUB(CURDATE(), INTERVAL 70 DAY), NULL, ''),
(5, 2, 2, 'Ordered', DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 5 DAY), ''),
(6, 3, 1, 'Draft', DATE_SUB(CURDATE(), INTERVAL 0 DAY), DATE_ADD(CURDATE(), INTERVAL 14 DAY), ''),
(7, 1, 2, 'Cancelled', DATE_SUB(CURDATE(), INTERVAL 30 DAY), NULL, '');

INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_cost) VALUES
(1, 11, 12, 58.00),
(1, 10, 60, 4.50),
(2, 5, 60, 2.50),
(2, 4, 200, 4.00),
(2, 6, 80, 1.50),
(3, 1, 60, 11.00),
(3, 2, 20, 13.50),
(3, 3, 20, 20.00),
(4, 7, 10, 95.00),
(4, 8, 4, 270.00),
(4, 9, 40, 3.00),
(5, 5, 100, 2.50),
(5, 4, 100, 4.00),
(6, 8, 3, 270.00),
(6, 7, 4, 95.00),
(7, 2, 50, 13.50);

INSERT INTO sales_orders (so_id, customer_id, user_id, status, order_date, note) VALUES
(1, 1, 2, 'Completed', DATE_SUB(CURDATE(), INTERVAL 130 DAY), ''),
(2, 2, 2, 'Completed', DATE_SUB(CURDATE(), INTERVAL 120 DAY), ''),
(3, 3, 1, 'Completed', DATE_SUB(CURDATE(), INTERVAL 105 DAY), ''),
(4, 4, 2, 'Completed', DATE_SUB(CURDATE(), INTERVAL 90 DAY), ''),
(5, 1, 1, 'Completed', DATE_SUB(CURDATE(), INTERVAL 75 DAY), ''),
(6, 2, 2, 'Completed', DATE_SUB(CURDATE(), INTERVAL 60 DAY), ''),
(7, 3, 2, 'Completed', DATE_SUB(CURDATE(), INTERVAL 45 DAY), ''),
(8, 4, 1, 'Completed', DATE_SUB(CURDATE(), INTERVAL 30 DAY), ''),
(9, 1, 2, 'Completed', DATE_SUB(CURDATE(), INTERVAL 20 DAY), ''),
(10, 2, 2, 'Completed', DATE_SUB(CURDATE(), INTERVAL 12 DAY), ''),
(11, 3, 1, 'Completed', DATE_SUB(CURDATE(), INTERVAL 6 DAY), ''),
(12, 1, 2, 'Completed', DATE_SUB(CURDATE(), INTERVAL 3 DAY), ''),
(13, 4, 2, 'Pending', DATE_SUB(CURDATE(), INTERVAL 1 DAY), ''),
(14, 2, 1, 'Pending', DATE_SUB(CURDATE(), INTERVAL 0 DAY), ''),
(15, 3, 2, 'Cancelled', DATE_SUB(CURDATE(), INTERVAL 40 DAY), '');

INSERT INTO sales_order_items (so_id, product_id, quantity, unit_price, unit_cost) VALUES
(1, 11, 4, 89.99, 58.00),
(1, 10, 20, 8.99, 4.50),
(2, 5, 40, 4.25, 2.50),
(2, 4, 80, 6.75, 4.00),
(3, 1, 20, 19.99, 11.00),
(3, 3, 6, 35.00, 20.00),
(4, 6, 30, 2.99, 1.50),
(4, 9, 10, 5.49, 3.00),
(5, 7, 3, 149.00, 95.00),
(5, 12, 4, 45.00, 28.00),
(6, 4, 60, 6.75, 4.00),
(6, 5, 25, 4.25, 2.50),
(7, 11, 5, 89.99, 58.00),
(7, 10, 15, 8.99, 4.50),
(7, 12, 3, 45.00, 28.00),
(8, 9, 8, 5.49, 3.00),
(8, 6, 20, 2.99, 1.50),
(9, 1, 15, 19.99, 11.00),
(9, 2, 12, 24.50, 13.50),
(10, 5, 20, 4.25, 2.50),
(10, 6, 10, 2.99, 1.50),
(11, 8, 2, 399.00, 270.00),
(11, 7, 2, 149.00, 95.00),
(12, 11, 2, 89.99, 58.00),
(12, 10, 10, 8.99, 4.50),
(13, 1, 5, 19.99, 0.00),
(13, 4, 20, 6.75, 0.00),
(14, 9, 6, 5.49, 0.00),
(15, 3, 4, 35.00, 0.00);

INSERT INTO stock_movements (product_id, user_id, type, reason, quantity, note, created_at) VALUES
(1, 1, 'IN', 'Opening', 15, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(2, 1, 'IN', 'Opening', 2, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(3, 1, 'IN', 'Opening', 1, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(4, 1, 'IN', 'Opening', 60, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(5, 1, 'IN', 'Opening', 28, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(6, 1, 'IN', 'Opening', 43, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(7, 1, 'IN', 'Opening', 1, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(9, 1, 'IN', 'Opening', 3, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(10, 1, 'IN', 'Opening', 33, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(11, 1, 'IN', 'Opening', 3, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(12, 1, 'IN', 'Opening', 19, 'Opening stock', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(11, 1, 'IN', 'Purchase', 12, 'PO-0001', DATE_SUB(NOW(), INTERVAL 148 DAY)),
(10, 1, 'IN', 'Purchase', 60, 'PO-0001', DATE_SUB(NOW(), INTERVAL 148 DAY)),
(5, 2, 'IN', 'Purchase', 60, 'PO-0002', DATE_SUB(NOW(), INTERVAL 138 DAY)),
(4, 2, 'IN', 'Purchase', 200, 'PO-0002', DATE_SUB(NOW(), INTERVAL 138 DAY)),
(6, 2, 'IN', 'Purchase', 80, 'PO-0002', DATE_SUB(NOW(), INTERVAL 138 DAY)),
(11, 2, 'OUT', 'Sale', 4, 'SO-0001', DATE_SUB(NOW(), INTERVAL 130 DAY)),
(10, 2, 'OUT', 'Sale', 20, 'SO-0001', DATE_SUB(NOW(), INTERVAL 130 DAY)),
(5, 2, 'OUT', 'Sale', 40, 'SO-0002', DATE_SUB(NOW(), INTERVAL 120 DAY)),
(4, 2, 'OUT', 'Sale', 80, 'SO-0002', DATE_SUB(NOW(), INTERVAL 120 DAY)),
(1, 1, 'OUT', 'Sale', 20, 'SO-0003', DATE_SUB(NOW(), INTERVAL 105 DAY)),
(3, 1, 'OUT', 'Sale', 6, 'SO-0003', DATE_SUB(NOW(), INTERVAL 105 DAY)),
(1, 1, 'IN', 'Purchase', 60, 'PO-0003', DATE_SUB(NOW(), INTERVAL 98 DAY)),
(2, 1, 'IN', 'Purchase', 20, 'PO-0003', DATE_SUB(NOW(), INTERVAL 98 DAY)),
(3, 1, 'IN', 'Purchase', 20, 'PO-0003', DATE_SUB(NOW(), INTERVAL 98 DAY)),
(6, 2, 'OUT', 'Sale', 30, 'SO-0004', DATE_SUB(NOW(), INTERVAL 90 DAY)),
(9, 2, 'OUT', 'Sale', 10, 'SO-0004', DATE_SUB(NOW(), INTERVAL 90 DAY)),
(7, 1, 'OUT', 'Sale', 3, 'SO-0005', DATE_SUB(NOW(), INTERVAL 75 DAY)),
(12, 1, 'OUT', 'Sale', 4, 'SO-0005', DATE_SUB(NOW(), INTERVAL 75 DAY)),
(7, 1, 'IN', 'Purchase', 10, 'PO-0004', DATE_SUB(NOW(), INTERVAL 68 DAY)),
(8, 1, 'IN', 'Purchase', 4, 'PO-0004', DATE_SUB(NOW(), INTERVAL 68 DAY)),
(9, 1, 'IN', 'Purchase', 40, 'PO-0004', DATE_SUB(NOW(), INTERVAL 68 DAY)),
(4, 2, 'OUT', 'Sale', 60, 'SO-0006', DATE_SUB(NOW(), INTERVAL 60 DAY)),
(5, 2, 'OUT', 'Sale', 25, 'SO-0006', DATE_SUB(NOW(), INTERVAL 60 DAY)),
(11, 2, 'OUT', 'Sale', 5, 'SO-0007', DATE_SUB(NOW(), INTERVAL 45 DAY)),
(10, 2, 'OUT', 'Sale', 15, 'SO-0007', DATE_SUB(NOW(), INTERVAL 45 DAY)),
(12, 2, 'OUT', 'Sale', 3, 'SO-0007', DATE_SUB(NOW(), INTERVAL 45 DAY)),
(9, 1, 'OUT', 'Sale', 8, 'SO-0008', DATE_SUB(NOW(), INTERVAL 30 DAY)),
(6, 1, 'OUT', 'Sale', 20, 'SO-0008', DATE_SUB(NOW(), INTERVAL 30 DAY)),
(2, 2, 'OUT', 'Damaged', 2, 'Dropped during delivery', DATE_SUB(NOW(), INTERVAL 25 DAY)),
(1, 2, 'OUT', 'Sale', 15, 'SO-0009', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(2, 2, 'OUT', 'Sale', 12, 'SO-0009', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(10, 1, 'IN', 'Correction', 2, 'Stock count correction', DATE_SUB(NOW(), INTERVAL 15 DAY)),
(5, 2, 'OUT', 'Sale', 20, 'SO-0010', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(6, 2, 'OUT', 'Sale', 10, 'SO-0010', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(6, 2, 'OUT', 'Lost', 3, 'Missing after stock count', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(8, 1, 'OUT', 'Sale', 2, 'SO-0011', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(7, 1, 'OUT', 'Sale', 2, 'SO-0011', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(11, 2, 'OUT', 'Sale', 2, 'SO-0012', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(10, 2, 'OUT', 'Sale', 10, 'SO-0012', DATE_SUB(NOW(), INTERVAL 3 DAY));

INSERT INTO audit_log (user_id, username, action, entity, entity_id, details, ip, created_at) VALUES
(1, 'admin', 'login', 'user', 1, 'Signed in', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 'staff', 'create', 'sales_order', 12, 'SO-0012 for Maple Dental Clinic', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(2, 'staff', 'complete', 'sales_order', 12, 'SO-0012 completed, stock reduced', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 'admin', 'create', 'purchase_order', 6, 'PO-0006 for Home Office Depot', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, 'admin', 'login', 'user', 1, 'Signed in', '127.0.0.1', NOW());
