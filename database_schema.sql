-- ============================================================
-- MEHBOOB TRADERS - Wholesale Management System
-- Database Schema
-- ============================================================

DROP DATABASE IF EXISTS mehboob_traders;
CREATE DATABASE mehboob_traders
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mehboob_traders;

-- ---------------------------------------------------------
-- 1. branches
-- ---------------------------------------------------------
CREATE TABLE branches (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    address     TEXT,
    phone       VARCHAR(20),
    status      TINYINT(1) DEFAULT 1,
    created_at  DATE NOT NULL,
    updated_at  DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 2. users
-- ---------------------------------------------------------
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(100),
    phone         VARCHAR(20),
    role          ENUM('admin','manager','cashier','salesperson','accountant','salesman','order_booker','loader') DEFAULT 'cashier',
    branch_id     INT DEFAULT NULL,
    status        TINYINT(1) DEFAULT 1,
    created_at    DATE NOT NULL,
    updated_at    DATE DEFAULT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 3. activity_logs
-- ---------------------------------------------------------
CREATE TABLE activity_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT DEFAULT NULL,
    action      VARCHAR(100) NOT NULL,
    module      VARCHAR(50) NOT NULL,
    reference_id INT DEFAULT NULL,
    description TEXT,
    ip_address  VARCHAR(45),
    created_at  DATE NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 4. bank_accounts
-- ---------------------------------------------------------
CREATE TABLE bank_accounts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    account_name    VARCHAR(100) NOT NULL,
    bank_name       VARCHAR(100) NOT NULL,
    account_no      VARCHAR(50) NOT NULL UNIQUE,
    account_type    ENUM('current','savings','loan') DEFAULT 'current',
    branch_code     VARCHAR(50),
    opening_balance DECIMAL(12,2) DEFAULT 0.00,
    opening_date    DATE DEFAULT NULL,
    current_balance DECIMAL(12,2) DEFAULT 0.00,
    status          TINYINT(1) DEFAULT 1,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 5. bank_transactions
-- ---------------------------------------------------------
CREATE TABLE bank_transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_type ENUM('deposit','withdrawal','transfer_in','transfer_out') NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    description     TEXT,
    reference_type  VARCHAR(50),
    reference_id    INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 6. categories   (e.g. Biscuits, Toffees, Chips, Nimko, ...)
-- ---------------------------------------------------------
CREATE TABLE categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    status      TINYINT(1) DEFAULT 1,
    created_at  DATE NOT NULL,
    updated_at  DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 7. brands   (e.g. PepsiCo, National, Peek Freans, ...)
-- ---------------------------------------------------------
CREATE TABLE brands (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    status      TINYINT(1) DEFAULT 1,
    created_at  DATE NOT NULL,
    updated_at  DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 8. products
--   unit: pcs / dozen / box / carton / kg / pakka / gadda
--   wholesale: purchase (aata price) + sale (bechne ki rate)
-- ---------------------------------------------------------
CREATE TABLE products (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(50) NOT NULL UNIQUE COMMENT 'Item Code',
    name            VARCHAR(100) NOT NULL,
    description     TEXT,
    category_id     INT DEFAULT NULL,
    brand_id        INT DEFAULT NULL,
    unit            VARCHAR(20) DEFAULT 'pcs',
    boxes_per_carton INT NOT NULL DEFAULT 1 COMMENT 'boxes per carton (purchase carton system)',
    purchase_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'wholesale aata price',
    sale_price      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'wholesale bechne ki rate',
    stock_quantity  INT NOT NULL DEFAULT 0,
    min_stock_level INT DEFAULT 0 COMMENT 'low stock alert',
    status          TINYINT(1) DEFAULT 1,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 9. suppliers
--   balance: positive = we owe supplier, negative = supplier owes us
-- ---------------------------------------------------------
CREATE TABLE suppliers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    contact_person  VARCHAR(100),
    phone           VARCHAR(20),
    cnic            VARCHAR(30) DEFAULT NULL,
    email           VARCHAR(100),
    address         TEXT,
    city            VARCHAR(50),
    notes           TEXT,
    opening_balance DECIMAL(12,2) DEFAULT 0.00,
    adjustment      DECIMAL(12,2) DEFAULT 0.00 COMMENT 'manual +/- adjust',
    current_balance DECIMAL(12,2) DEFAULT 0.00 COMMENT 'live running balance',
    status          TINYINT(1) DEFAULT 1,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 10. customers
--   balance: positive = customer owes us, negative = we owe customer
-- ---------------------------------------------------------
CREATE TABLE customers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    customer_no     VARCHAR(20) NOT NULL UNIQUE,
    full_name       VARCHAR(100) NOT NULL,
    phone           VARCHAR(20) NOT NULL,
    cnic            VARCHAR(30) DEFAULT NULL,
    email           VARCHAR(100),
    address         TEXT,
    city            VARCHAR(50),
    area            VARCHAR(50) DEFAULT NULL COMMENT 'salesman territory filter',
    opening_balance DECIMAL(12,2) DEFAULT 0.00,
    current_balance DECIMAL(12,2) DEFAULT 0.00 COMMENT 'live running balance',
    notes           TEXT,
    branch_id       INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 11. purchases   (stock in from supplier - cash OR credit)
-- ---------------------------------------------------------
CREATE TABLE purchases (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id     INT DEFAULT NULL,
    invoice_no      VARCHAR(50),
    purchase_date   DATE NOT NULL,
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(12,2) DEFAULT 0.00,
    paid_amount     DECIMAL(12,2) DEFAULT 0.00,
    due_amount      DECIMAL(12,2) DEFAULT 0.00,
    payment_method  ENUM('cash','bank') DEFAULT 'cash',
    bank_account_id INT DEFAULT NULL,
    status          ENUM('pending','received','cancelled') DEFAULT 'received',
    notes           TEXT,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 12. purchase_items
-- ---------------------------------------------------------
CREATE TABLE purchase_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id     INT NOT NULL,
    product_id      INT NOT NULL,
    cartons         INT NOT NULL DEFAULT 0 COMMENT 'carton count entered',
    boxes_per_carton INT NOT NULL DEFAULT 1 COMMENT 'boxes in one carton',
    loose_boxes     INT NOT NULL DEFAULT 0 COMMENT 'extra loose boxes',
    quantity        INT NOT NULL DEFAULT 0 COMMENT 'total boxes = cartons*bpc + loose',
    purchase_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 13. supplier_payments
--   to supplier = we are paying money (cash/bank)
-- ---------------------------------------------------------
CREATE TABLE supplier_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id     INT NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    payment_method  ENUM('cash','bank') DEFAULT 'cash',
    bank_account_id INT DEFAULT NULL,
    description     TEXT,
    payment_date    DATE NOT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 14. sales   (stock out to customer - cash OR credit)
-- ---------------------------------------------------------
CREATE TABLE sales (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no      VARCHAR(20) NOT NULL UNIQUE,
    customer_id     INT NOT NULL,
    salesman_id     INT DEFAULT NULL,
    sale_date       DATE NOT NULL,
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(12,2) DEFAULT 0.00,
    initial_paid    DECIMAL(12,2) DEFAULT 0.00,
    paid_amount     DECIMAL(12,2) DEFAULT 0.00,
    due_amount      DECIMAL(12,2) DEFAULT 0.00,
    payment_method  ENUM('cash','bank','credit') DEFAULT 'credit',
    bank_account_id INT DEFAULT NULL,
    status          ENUM('active','completed','cancelled') DEFAULT 'active',
    notes           TEXT,
    branch_id       INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (salesman_id) REFERENCES employees(id) ON DELETE SET NULL,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 15. sale_items
-- ---------------------------------------------------------
CREATE TABLE sale_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    sale_id     INT NOT NULL,
    product_id  INT NOT NULL,
    quantity    INT NOT NULL,
    price       DECIMAL(12,2) NOT NULL,
    subtotal    DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 16. customer_receipts
--   from customer = customer paise de raha hai (cash/bank)
-- ---------------------------------------------------------
CREATE TABLE customer_receipts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT NOT NULL,
    sale_id         INT DEFAULT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    payment_method  ENUM('cash','bank') DEFAULT 'cash',
    bank_account_id INT DEFAULT NULL,
    description     TEXT,
    receipt_date    DATE NOT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 17. expense_categories
-- ---------------------------------------------------------
CREATE TABLE expense_categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    status      TINYINT(1) DEFAULT 1,
    created_at  DATE NOT NULL,
    updated_at  DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 18. expenses
-- ---------------------------------------------------------
CREATE TABLE expenses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    category_id     INT DEFAULT NULL,
    expense_date    DATE NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    description     TEXT,
    vendor_name     VARCHAR(100),
    bill_no         VARCHAR(50),
    payment_method  ENUM('cash','bank') DEFAULT 'cash',
    bank_account_id INT DEFAULT NULL,
    notes           TEXT,
    branch_id       INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 17. cash_book_daily
-- ---------------------------------------------------------
CREATE TABLE cash_book_daily (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    date            DATE NOT NULL UNIQUE,
    opening_balance DECIMAL(12,2) DEFAULT 0.00,
    total_inflow    DECIMAL(12,2) DEFAULT 0.00,
    total_outflow   DECIMAL(12,2) DEFAULT 0.00,
    closing_balance DECIMAL(12,2) DEFAULT 0.00,
    status          ENUM('open','closed') DEFAULT 'open',
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 18. cash_book
-- ---------------------------------------------------------
CREATE TABLE cash_book (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    daily_id        INT DEFAULT NULL,
    transaction_date DATE NOT NULL,
    transaction_type ENUM('opening_balance','inflow','outflow','closing_balance') NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    description     TEXT,
    reference_type  VARCHAR(50) COMMENT 'e.g. sale, purchase, supplier_payment, customer_receipt, expense',
    reference_id    INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (daily_id) REFERENCES cash_book_daily(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 19. employees
-- ---------------------------------------------------------
CREATE TABLE employees (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT DEFAULT NULL,
    emp_code        VARCHAR(50) DEFAULT NULL,
    full_name       VARCHAR(100) NOT NULL,
    employee_type   ENUM('salesman','order_booker','loader') NOT NULL DEFAULT 'salesman',
    phone           VARCHAR(20),
    area            VARCHAR(50) DEFAULT NULL COMMENT 'salesman territory, drives customer visibility',
    cnic            VARCHAR(30),
    address         VARCHAR(255),
    joining_date    DATE DEFAULT NULL,
    salary          DECIMAL(12,2) DEFAULT 0,
    status          TINYINT(1) DEFAULT 1,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 20. employee_salaries
-- ---------------------------------------------------------
CREATE TABLE employee_salaries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    slip_no         VARCHAR(50) NOT NULL,
    employee_id     INT NOT NULL,
    salary_month    VARCHAR(7) NOT NULL COMMENT 'YYYY-MM',
    amount          DECIMAL(12,2) NOT NULL,
    payment_method  ENUM('cash','bank') DEFAULT 'cash',
    bank_account_id INT DEFAULT NULL,
    payment_date    DATE NOT NULL,
    notes           TEXT,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- INDEXES
-- ============================================================
CREATE INDEX idx_products_code ON products(code);
CREATE INDEX idx_products_name ON products(name);
CREATE INDEX idx_purchases_supplier ON purchases(supplier_id);
CREATE INDEX idx_purchases_date ON purchases(purchase_date);
CREATE INDEX idx_sales_invoice ON sales(invoice_no);
CREATE INDEX idx_sales_customer ON sales(customer_id);
CREATE INDEX idx_sales_date ON sales(sale_date);
CREATE INDEX idx_supplier_pay_date ON supplier_payments(payment_date);
CREATE INDEX idx_customer_receipt_date ON customer_receipts(receipt_date);
CREATE INDEX idx_expenses_date ON expenses(expense_date);
CREATE INDEX idx_cashbook_date ON cash_book(transaction_date);
CREATE INDEX idx_banktxn_date ON bank_transactions(transaction_date);
CREATE INDEX idx_salary_date ON employee_salaries(payment_date);
CREATE INDEX idx_activity_user ON activity_logs(user_id);
CREATE INDEX idx_activity_module ON activity_logs(module);