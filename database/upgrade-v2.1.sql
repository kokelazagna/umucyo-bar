-- =====================================================================
-- BSSBMS upgrade v2.1  (Premium POS: tables, open tabs, served rounds)
--
-- Run this ONLY on a database that already has the older v2.0 schema
-- (bar_management_db created with the previous schema.sql). A fresh
-- install does NOT need this file: schema.sql already contains everything.
--
-- In phpMyAdmin: select bar_management_db, then Import this file.
-- Tables use IF NOT EXISTS, so running it twice will not duplicate them.
-- =====================================================================
USE bar_management_db;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Floor & seating -----------------------------------------------------
CREATE TABLE IF NOT EXISTS bar_areas (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_area_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bar_tables (
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

-- 2. Table sessions (one OPEN session per table, enforced in the service)
CREATE TABLE IF NOT EXISTS table_sessions (
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

-- 3. Serving rounds: draft -> served (stock deducted exactly once) -------
CREATE TABLE IF NOT EXISTS orders (
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

CREATE TABLE IF NOT EXISTS order_items (
  id                         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                   INT UNSIGNED NOT NULL,
  product_id                 INT UNSIGNED NOT NULL,
  unit_id                    INT UNSIGNED NOT NULL,
  conversion_factor_snapshot INT UNSIGNED NOT NULL,
  unit_price_snapshot        DECIMAL(15,2) NOT NULL,
  unit_cost_snapshot         DECIMAL(15,4) NOT NULL DEFAULT 0,
  quantity                   INT UNSIGNED NOT NULL,
  base_quantity              INT UNSIGNED NOT NULL,
  state                      ENUM('draft','served','void') NOT NULL DEFAULT 'draft',
  served_at                  DATETIME NULL,
  served_by                  INT UNSIGNED NULL,
  CONSTRAINT fk_oitem_order   FOREIGN KEY (order_id)   REFERENCES orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_oitem_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_oitem_unit    FOREIGN KEY (unit_id)    REFERENCES product_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_oitem_served  FOREIGN KEY (served_by)  REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_oitem_order (order_id)
) ENGINE=InnoDB;

-- 4. SALES: link to a table session, and an OPEN tab is a real state ----
-- (older rows keep their ENUM value; 'open' is appended)
ALTER TABLE sales
  MODIFY status ENUM('completed','cancelled','open') NOT NULL DEFAULT 'completed';

ALTER TABLE sales
  ADD COLUMN table_session_id INT UNSIGNED NULL AFTER sale_date,
  ADD INDEX idx_sales_tsession (table_session_id),
  ADD CONSTRAINT fk_sales_table_session FOREIGN KEY (table_session_id)
      REFERENCES table_sessions(id) ON DELETE RESTRICT;

-- 5. SALE_ITEMS: audited void lines + trace to the served round ---------
ALTER TABLE sale_items
  ADD COLUMN line_status ENUM('active','void') NOT NULL DEFAULT 'active' AFTER cost_total,
  ADD COLUMN order_item_id INT UNSIGNED NULL AFTER line_status,
  ADD CONSTRAINT fk_sitems_oitem FOREIGN KEY (order_item_id)
      REFERENCES order_items(id) ON DELETE RESTRICT;

-- 6. Settings -> Product Units & Conversions: change history -------------
CREATE TABLE IF NOT EXISTS product_price_history (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id        INT UNSIGNED NOT NULL,
  unit_id           INT UNSIGNED NULL,
  unit_name         VARCHAR(20) NOT NULL,
  conversion_factor INT UNSIGNED NOT NULL DEFAULT 1,
  selling_price     DECIMAL(15,2) NOT NULL,
  previous_factor   INT UNSIGNED NULL,
  previous_price    DECIMAL(15,2) NULL,
  change_kind       VARCHAR(30) NOT NULL,
  reason            VARCHAR(255) NULL,
  changed_by        INT UNSIGNED NOT NULL,
  changed_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_phistory_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_phistory_unit    FOREIGN KEY (unit_id)    REFERENCES product_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_phistory_user    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_phistory_product (product_id)
) ENGINE=InnoDB;

-- 7. Default floor (skipped automatically if the name/code already exists)
INSERT INTO bar_areas (name, sort_order, active)
SELECT 'Main Hall', 1, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM bar_areas WHERE name = 'Main Hall');

INSERT INTO bar_tables (code, area_id, capacity, status, active, floor_position)
SELECT 'T01', a.id, 4, 'available', 1, 'A1' FROM bar_areas a
 WHERE a.name = 'Main Hall'
   AND NOT EXISTS (SELECT 1 FROM bar_tables WHERE code = 'T01');

SET FOREIGN_KEY_CHECKS = 1;

-- 8. Check ---------------------------------------------------------------
-- Should list bar_areas, bar_tables, orders, order_items, table_sessions
-- and product_price_history. If any is missing, the upgrade did not finish.
SHOW TABLES;
