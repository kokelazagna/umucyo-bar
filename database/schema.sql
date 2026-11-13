CREATE DATABASE IF NOT EXISTS bar_management_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bar_management_db;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. ROLES -------------------------------------------------------------
CREATE TABLE roles (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(50)  NOT NULL UNIQUE,      -- owner, manager, cashier, stock_manager
  description  VARCHAR(255) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. USERS -------------------------------------------------------------
CREATE TABLE users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id        INT UNSIGNED NOT NULL,
  full_name      VARCHAR(120) NOT NULL,
  username       VARCHAR(50)  NOT NULL UNIQUE,
  email          VARCHAR(120) NULL UNIQUE,
  phone          VARCHAR(20)  NULL,
  password_hash  VARCHAR(255) NOT NULL,           -- password_hash() / bcrypt
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login_at  DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 3. CATEGORIES --------------------------------------------------------
CREATE TABLE categories (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80) NOT NULL UNIQUE,       -- Beer, Soft drink, Water, Wine...
  description  VARCHAR(255) NULL,
  status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 4. PRODUCTS ----------------------------------------------------------
-- quantity_in_stock, avg_cost and selling_price are all per BASE unit.
CREATE TABLE products (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_code      VARCHAR(30)  NOT NULL UNIQUE,  -- Product ID shown to users
  category_id       INT UNSIGNED NOT NULL,
  name              VARCHAR(120) NOT NULL,
  brand             VARCHAR(80)  NULL,
  barcode           VARCHAR(50)  NULL UNIQUE,
  base_unit         VARCHAR(20)  NOT NULL DEFAULT 'bottle',
  purchase_price    DECIMAL(15,2) NOT NULL DEFAULT 0,  -- last cost per base unit
  avg_cost          DECIMAL(15,4) NOT NULL DEFAULT 0,  -- weighted average cost per base unit
  selling_price     DECIMAL(15,2) NOT NULL DEFAULT 0,  -- per base unit
  quantity_in_stock INT NOT NULL DEFAULT 0,
  min_stock         INT NOT NULL DEFAULT 0,
  expiry_date       DATE NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT chk_stock_not_negative CHECK (quantity_in_stock >= 0),
  INDEX idx_products_name (name),
  INDEX idx_products_status (status),
  INDEX idx_products_expiry (expiry_date)
) ENGINE=InnoDB;

-- 5. PRODUCT_UNITS -----------------------------------------------------
-- e.g. Primus: bottle (factor 1, base), crate (factor 20)
CREATE TABLE product_units (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id         INT UNSIGNED NOT NULL,
  unit_name          VARCHAR(20) NOT NULL,          -- bottle, can, crate, pack
  conversion_factor  INT UNSIGNED NOT NULL DEFAULT 1, -- how many base units in this unit
  selling_price      DECIMAL(15,2) NOT NULL DEFAULT 0, -- price per this unit
  is_base            TINYINT(1) NOT NULL DEFAULT 0,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_unit (product_id, unit_name),
  CONSTRAINT fk_units_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT chk_factor_positive CHECK (conversion_factor >= 1)
) ENGINE=InnoDB;

-- 6. SUPPLIERS ---------------------------------------------------------
CREATE TABLE suppliers (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_code  VARCHAR(30)  NOT NULL UNIQUE,
  name           VARCHAR(120) NOT NULL,
  business_name  VARCHAR(150) NULL,
  phone          VARCHAR(20)  NULL,
  email          VARCHAR(120) NULL,
  address        VARCHAR(255) NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_suppliers_name (name)
) ENGINE=InnoDB;

-- 7. PURCHASES ---------------------------------------------------------
CREATE TABLE purchases (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference_no  VARCHAR(30) NOT NULL UNIQUE,        -- e.g. PUR-20261105-0001
  supplier_id   INT UNSIGNED NOT NULL,
  purchase_date DATE NOT NULL,
  total_cost    DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_paid   DECIMAL(15,2) NOT NULL DEFAULT 0,
  balance       DECIMAL(15,2) NOT NULL DEFAULT 0,   -- outstanding supplier balance
  status        ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  notes         VARCHAR(255) NULL,
  created_by    INT UNSIGNED NOT NULL,
  confirmed_by  INT UNSIGNED NULL,
  confirmed_at  DATETIME NULL,
  cancelled_by  INT UNSIGNED NULL,
  cancelled_at  DATETIME NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_purchases_supplier  FOREIGN KEY (supplier_id)  REFERENCES suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_purchases_creator   FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_purchases_confirmer FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_purchases_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_purchases_date (purchase_date),
  INDEX idx_purchases_status (status)
) ENGINE=InnoDB;

-- 8. PURCHASE_ITEMS ----------------------------------------------------
CREATE TABLE purchase_items (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id         INT UNSIGNED NOT NULL,
  product_id          INT UNSIGNED NOT NULL,
  unit_id             INT UNSIGNED NOT NULL,          -- purchase unit (crate, bottle...)
  quantity            INT UNSIGNED NOT NULL,          -- in purchase unit
  base_quantity       INT UNSIGNED NOT NULL,          -- quantity x conversion_factor
  unit_cost           DECIMAL(15,2) NOT NULL,         -- cost per purchase unit
  cost_per_base_unit  DECIMAL(15,4) NOT NULL,         -- unit_cost / conversion_factor
  total_cost          DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_pitems_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pitems_product  FOREIGN KEY (product_id)  REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pitems_unit     FOREIGN KEY (unit_id)     REFERENCES product_units(id) ON DELETE RESTRICT,
  INDEX idx_pitems_product (product_id)
) ENGINE=InnoDB;

-- 9. CUSTOMERS ---------------------------------------------------------
CREATE TABLE customers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_code VARCHAR(30)  NOT NULL UNIQUE,
  full_name     VARCHAR(120) NOT NULL,
  phone         VARCHAR(20)  NULL,
  address       VARCHAR(255) NULL,
  credit_limit  DECIMAL(15,2) NOT NULL DEFAULT 0,
  current_debt  DECIMAL(15,2) NOT NULL DEFAULT 0,
  status        ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customers_name (full_name),
  INDEX idx_customers_phone (phone)
) ENGINE=InnoDB;

-- 19. CASH_SESSIONS (created before sales because sales reference it) ---
CREATE TABLE cash_sessions (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  opened_by         INT UNSIGNED NOT NULL,
  closed_by         INT UNSIGNED NULL,
  opened_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at         DATETIME NULL,
  opening_balance   DECIMAL(15,2) NOT NULL DEFAULT 0,
  expected_closing  DECIMAL(15,2) NULL,
  counted_closing   DECIMAL(15,2) NULL,
  variance          DECIMAL(15,2) NULL,              -- counted - expected
  status            ENUM('open','closed') NOT NULL DEFAULT 'open',
  notes             VARCHAR(255) NULL,
  CONSTRAINT fk_cs_opener FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cs_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_cs_status (status),
  INDEX idx_cs_opened (opened_at)
) ENGINE=InnoDB;

-- 10. SALES ------------------------------------------------------------
CREATE TABLE sales (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_no       VARCHAR(30) NOT NULL UNIQUE,      -- e.g. REC-20261105-0001
  customer_id      INT UNSIGNED NULL,                -- NULL = walk-in customer
  cash_session_id  INT UNSIGNED NULL,
  sale_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  table_session_id INT UNSIGNED NULL,                -- NEW V2.1: open tab of a table session
  subtotal         DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_paid      DECIMAL(15,2) NOT NULL DEFAULT 0, -- paid now (cash + momo + bank)
  credit_amount    DECIMAL(15,2) NOT NULL DEFAULT 0, -- left on credit
  status           ENUM('completed','cancelled','open') NOT NULL DEFAULT 'completed',
  sold_by          INT UNSIGNED NOT NULL,
  discount_by      INT UNSIGNED NULL,                -- who authorised the discount
  cancelled_by     INT UNSIGNED NULL,
  cancelled_at     DATETIME NULL,
  cancel_reason    VARCHAR(255) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id)     REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_session  FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_seller   FOREIGN KEY (sold_by)         REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_discount FOREIGN KEY (discount_by)     REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_canceller FOREIGN KEY (cancelled_by)   REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_table_session FOREIGN KEY (table_session_id) REFERENCES table_sessions(id) ON DELETE RESTRICT,
  INDEX idx_sales_date (sale_date),
  INDEX idx_sales_status (status),
  INDEX idx_sales_tsession (table_session_id)
) ENGINE=InnoDB;

-- 11. SALE_ITEMS -------------------------------------------------------
-- unit_cost is copied from products.avg_cost at sale time so past
-- profit never changes when prices change.
CREATE TABLE sale_items (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id             INT UNSIGNED NOT NULL,
  product_id          INT UNSIGNED NOT NULL,
  unit_id             INT UNSIGNED NOT NULL,
  quantity            INT UNSIGNED NOT NULL,          -- in selling unit
  base_quantity       INT UNSIGNED NOT NULL,          -- deducted from stock
  unit_price          DECIMAL(15,2) NOT NULL,         -- price per selling unit
  unit_cost           DECIMAL(15,4) NOT NULL,         -- avg cost per BASE unit at sale time
  subtotal            DECIMAL(15,2) NOT NULL,         -- quantity x unit_price
  cost_total          DECIMAL(15,2) NOT NULL,         -- base_quantity x unit_cost (COGS)
  line_status         ENUM('active','void') NOT NULL DEFAULT 'active', -- NEW V2.1: audited voids
  order_item_id       INT UNSIGNED NULL,              -- NEW V2.1: served round line this came from
  CONSTRAINT fk_sitems_sale    FOREIGN KEY (sale_id)    REFERENCES sales(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sitems_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sitems_unit    FOREIGN KEY (unit_id)    REFERENCES product_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sitems_oitem   FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
  INDEX idx_sitems_product (product_id)
) ENGINE=InnoDB;

-- 20. BAR_AREAS (NEW V2.1: Settings -> Floor & Seating -> Areas) --------
CREATE TABLE bar_areas (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_area_name (name)
) ENGINE=InnoDB;

-- 21. BAR_TABLES (NEW V2.1: Settings -> Floor & Seating -> Tables) ------
-- status is informational for 'reserved'/'out_of_service'; 'occupied' is
-- always the truth while an open table_session exists for the table.
CREATE TABLE bar_tables (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(20) NOT NULL,
  area_id        INT UNSIGNED NOT NULL,
  capacity       INT UNSIGNED NOT NULL DEFAULT 4,
  status         ENUM('available','occupied','reserved','out_of_service') NOT NULL DEFAULT 'available',
  active         TINYINT(1) NOT NULL DEFAULT 1,
  floor_position VARCHAR(40) NOT NULL DEFAULT '',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_table_code (code),
  CONSTRAINT fk_table_area FOREIGN KEY (area_id) REFERENCES bar_areas(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 22. TABLE_SESSIONS (NEW V2.1: one OPEN session per table at a time) ---
CREATE TABLE table_sessions (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_id           INT UNSIGNED NOT NULL,
  opened_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opened_by          INT UNSIGNED NOT NULL,
  assigned_waiter_id INT UNSIGNED NULL,
  status             ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_at          DATETIME NULL,
  closed_by          INT UNSIGNED NULL,
  notes              VARCHAR(255) NULL,
  CONSTRAINT fk_session_table  FOREIGN KEY (table_id) REFERENCES bar_tables(id) ON DELETE RESTRICT,
  CONSTRAINT fk_session_opener FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_session_waiter FOREIGN KEY (assigned_waiter_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_session_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_session_table_status (table_id, status)
) ENGINE=InnoDB;

-- 23. ORDERS (NEW V2.1: serving rounds of a table session) --------------
-- A draft round does NOT touch stock. Confirming the round as SERVED
-- deducts stock ONCE and creates the bill obligation (sale_items).
CREATE TABLE orders (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_session_id INT UNSIGNED NULL,
  sales_channel    ENUM('TABLE','WALK_IN') NOT NULL DEFAULT 'TABLE',
  created_by       INT UNSIGNED NOT NULL,
  state            ENUM('draft','served','closed') NOT NULL DEFAULT 'draft',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_session FOREIGN KEY (table_session_id) REFERENCES table_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_user    FOREIGN KEY (created_by)       REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_order_session (table_session_id, state)
) ENGINE=InnoDB;

-- 24. ORDER_ITEMS (NEW V2.1: lines of a serving round) ------------------
CREATE TABLE order_items (
  id                         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                   INT UNSIGNED NOT NULL,
  product_id                 INT UNSIGNED NOT NULL,
  unit_id                    INT UNSIGNED NOT NULL,
  conversion_factor_snapshot INT UNSIGNED NOT NULL,   -- factor when the line was added
  unit_price_snapshot        DECIMAL(15,2) NOT NULL,  -- price when the line was added
  unit_cost_snapshot         DECIMAL(15,4) NOT NULL DEFAULT 0,
  quantity                   INT UNSIGNED NOT NULL,   -- in the chosen unit
  base_quantity              INT UNSIGNED NOT NULL,   -- quantity x factor snapshot
  state                      ENUM('draft','served','void') NOT NULL DEFAULT 'draft',
  served_at                  DATETIME NULL,
  served_by                  INT UNSIGNED NULL,
  CONSTRAINT fk_oitem_order   FOREIGN KEY (order_id)   REFERENCES orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_oitem_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_oitem_unit    FOREIGN KEY (unit_id)    REFERENCES product_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_oitem_served  FOREIGN KEY (served_by)  REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_oitem_order (order_id)
) ENGINE=InnoDB;

-- 25. PRODUCT_PRICE_HISTORY (NEW V2.1: Settings -> Product Units &
-- Conversions). Every conversion / price change is recorded here;
-- completed transactions keep the snapshot they were made with.
CREATE TABLE product_price_history (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id       INT UNSIGNED NOT NULL,
  unit_id          INT UNSIGNED NULL,
  unit_name        VARCHAR(20) NOT NULL,
  conversion_factor INT UNSIGNED NOT NULL DEFAULT 1,
  selling_price    DECIMAL(15,2) NOT NULL,
  previous_factor  INT UNSIGNED NULL,
  previous_price   DECIMAL(15,2) NULL,
  change_kind      VARCHAR(30) NOT NULL,   -- unit_created / unit_changed / unit_removed / base_price_changed
  reason           VARCHAR(255) NULL,
  changed_by       INT UNSIGNED NOT NULL,
  changed_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_phistory_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_phistory_unit    FOREIGN KEY (unit_id)    REFERENCES product_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_phistory_user    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_phistory_product (product_id)
) ENGINE=InnoDB;

-- 12. PAYMENTS (payments received for a sale; supports split payments) --
CREATE TABLE payments (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id      INT UNSIGNED NOT NULL,
  method       ENUM('cash','mobile_money','bank') NOT NULL,
  amount       DECIMAL(15,2) NOT NULL,
  reference    VARCHAR(60) NULL,                      -- MoMo / bank reference
  received_by  INT UNSIGNED NOT NULL,
  paid_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_sale FOREIGN KEY (sale_id)     REFERENCES sales(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_payments_method (method),
  INDEX idx_payments_date (paid_at)
) ENGINE=InnoDB;

-- 13. CUSTOMER_DEBTS (one row per credit sale) -------------------------
CREATE TABLE customer_debts (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id      INT UNSIGNED NOT NULL,
  sale_id          INT UNSIGNED NOT NULL,
  original_amount  DECIMAL(15,2) NOT NULL,
  paid_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  balance          DECIMAL(15,2) NOT NULL,
  status           ENUM('open','partial','paid','cancelled') NOT NULL DEFAULT 'open',
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_debts_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_debts_sale     FOREIGN KEY (sale_id)     REFERENCES sales(id) ON DELETE RESTRICT,
  INDEX idx_debts_status (status)
) ENGINE=InnoDB;

-- 14. DEBT_PAYMENTS ----------------------------------------------------
CREATE TABLE debt_payments (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id       INT UNSIGNED NOT NULL,
  customer_debt_id  INT UNSIGNED NULL,                -- NULL = general payment allocated oldest-first
  cash_session_id   INT UNSIGNED NULL,
  amount            DECIMAL(15,2) NOT NULL,
  method            ENUM('cash','mobile_money','bank') NOT NULL,
  reference         VARCHAR(60) NULL,
  received_by       INT UNSIGNED NOT NULL,
  paid_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes             VARCHAR(255) NULL,
  CONSTRAINT fk_dpay_customer FOREIGN KEY (customer_id)      REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dpay_debt     FOREIGN KEY (customer_debt_id) REFERENCES customer_debts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dpay_session  FOREIGN KEY (cash_session_id)  REFERENCES cash_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dpay_user     FOREIGN KEY (received_by)      REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_dpay_date (paid_at)
) ENGINE=InnoDB;

-- 16. EXPENSE_CATEGORIES -----------------------------------------------
CREATE TABLE expense_categories (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80) NOT NULL UNIQUE,
  description  VARCHAR(255) NULL,
  status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 15. EXPENSES ---------------------------------------------------------
-- Owner withdrawals are NOT stored here (they go to cash_transactions).
CREATE TABLE expenses (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_code         VARCHAR(30) NOT NULL UNIQUE,
  expense_category_id  INT UNSIGNED NOT NULL,
  cash_session_id      INT UNSIGNED NULL,
  description          VARCHAR(255) NOT NULL,
  amount               DECIMAL(15,2) NOT NULL,
  payment_method       ENUM('cash','mobile_money','bank') NOT NULL DEFAULT 'cash',
  expense_date         DATE NOT NULL,
  recorded_by          INT UNSIGNED NOT NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_exp_category FOREIGN KEY (expense_category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_exp_session  FOREIGN KEY (cash_session_id)     REFERENCES cash_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_exp_user     FOREIGN KEY (recorded_by)         REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_exp_date (expense_date)
) ENGINE=InnoDB;

-- 18. STOCK_ADJUSTMENTS ------------------------------------------------
CREATE TABLE stock_adjustments (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id       INT UNSIGNED NOT NULL,
  adjustment_type  ENUM('damaged','expired','missing','returned','correction') NOT NULL,
  direction        ENUM('increase','decrease') NOT NULL,
  base_quantity    INT UNSIGNED NOT NULL,
  reason           VARCHAR(255) NOT NULL,            -- reason is mandatory
  adjusted_by      INT UNSIGNED NOT NULL,            -- responsible user
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_adj_product FOREIGN KEY (product_id)  REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_adj_user    FOREIGN KEY (adjusted_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_adj_type (adjustment_type),
  INDEX idx_adj_date (created_at)
) ENGINE=InnoDB;

-- 17. STOCK_MOVEMENTS (complete history of every stock change) ---------
CREATE TABLE stock_movements (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      INT UNSIGNED NOT NULL,
  movement_type   ENUM('purchase','sale','sale_cancel','purchase_cancel',
                       'damaged','expired','missing','returned','correction') NOT NULL,
  quantity_change INT NOT NULL,                      -- +in / -out, in base units
  balance_after   INT NOT NULL,
  unit_cost       DECIMAL(15,4) NULL,
  reference_type  VARCHAR(30) NULL,                  -- purchase, sale, adjustment
  reference_id    INT UNSIGNED NULL,
  reason          VARCHAR(255) NULL,
  created_by      INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sm_user    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_sm_product_date (product_id, created_at),
  INDEX idx_sm_reference (reference_type, reference_id)
) ENGINE=InnoDB;

-- 20. CASH_TRANSACTIONS (every cash in / out) --------------------------
CREATE TABLE cash_transactions (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cash_session_id  INT UNSIGNED NOT NULL,
  txn_type         ENUM('sale','debt_payment','deposit','supplier_payment',
                        'expense','owner_withdrawal','refund') NOT NULL,
  direction        ENUM('in','out') NOT NULL,
  amount           DECIMAL(15,2) NOT NULL,
  reference_type   VARCHAR(30) NULL,
  reference_id     INT UNSIGNED NULL,
  description      VARCHAR(255) NULL,
  recorded_by      INT UNSIGNED NOT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ct_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ct_user    FOREIGN KEY (recorded_by)     REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_ct_type (txn_type),
  INDEX idx_ct_date (created_at)
) ENGINE=InnoDB;

-- 21. AUDIT_LOGS -------------------------------------------------------
CREATE TABLE audit_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(60) NOT NULL,                 -- login, price_change, cancel_sale...
  entity_type  VARCHAR(40) NULL,
  entity_id    INT UNSIGNED NULL,
  details      TEXT NULL,
  ip_address   VARCHAR(45) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_action (action),
  INDEX idx_audit_date (created_at)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
