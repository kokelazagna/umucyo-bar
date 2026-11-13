-- =====================================================================
-- BSSBMS seed data  (run AFTER schema.sql)
-- All sample users use the password:  Admin@123   (bcrypt hash below)
-- CHANGE THE PASSWORDS after the demonstration.
-- Products start with 0 stock on purpose: the demo records a purchase
-- and then verifies that stock increased.
-- =====================================================================
USE bar_management_db;

-- ROLES ----------------------------------------------------------------
INSERT INTO roles (id, name, description) VALUES
 (1, 'owner',         'Owner / Administrator - full system access'),
 (2, 'manager',       'Manage products, purchases, sales and reports'),
 (3, 'cashier',       'Record sales and receive payments'),
 (4, 'stock_manager', 'Manage stock, purchases and adjustments');

-- USERS ----------------------------------------------------------------
INSERT INTO users (id, role_id, full_name, username, email, phone, password_hash, status) VALUES
 (1, 1, 'System Administrator', 'admin',    'admin@umucyobar.rw',    '0788000000', '$2y$10$sVDj/sBIbk84XGuBMA4GV.69FfRP0dgf9Tw8XfsZYkxk.B1K7G4Iq', 'active'),
 (2, 2, 'Sample Manager',       'manager',  'manager@umucyobar.rw',  '0788000001', '$2y$10$sVDj/sBIbk84XGuBMA4GV.69FfRP0dgf9Tw8XfsZYkxk.B1K7G4Iq', 'active'),
 (3, 3, 'Sample Cashier',       'cashier',  'cashier@umucyobar.rw',  '0788000002', '$2y$10$sVDj/sBIbk84XGuBMA4GV.69FfRP0dgf9Tw8XfsZYkxk.B1K7G4Iq', 'active'),
 (4, 4, 'Sample Stock Manager', 'stock',    'stock@umucyobar.rw',    '0788000003', '$2y$10$sVDj/sBIbk84XGuBMA4GV.69FfRP0dgf9Tw8XfsZYkxk.B1K7G4Iq', 'active');

-- BEVERAGE CATEGORIES --------------------------------------------------
INSERT INTO categories (id, name, description) VALUES
 (1, 'Beer',        'Bottled and canned beer'),
 (2, 'Soft drink',  'Sodas and juices'),
 (3, 'Water',       'Bottled drinking water'),
 (4, 'Wine',        'Red, white and sparkling wine'),
 (5, 'Other',       'Other permitted beverages');

-- EXPENSE CATEGORIES ---------------------------------------------------
INSERT INTO expense_categories (id, name, description) VALUES
 (1, 'Electricity',        'Electricity bills'),
 (2, 'Water',              'Water bills'),
 (3, 'Rent',               'Premises rent'),
 (4, 'Transport',          'Delivery and transport costs'),
 (5, 'Employee salaries',  'Staff salaries and wages'),
 (6, 'Cleaning materials', 'Cleaning supplies'),
 (7, 'Repairs',            'Repairs and maintenance'),
 (8, 'Other',              'Other business expenses');

-- SUPPLIERS ------------------------------------------------------------
INSERT INTO suppliers (id, supplier_code, name, business_name, phone, email, address) VALUES
 (1, 'SUP-0001', 'Sample Beer Distributor',  'Kigali Beer Distribution Ltd', '0788100001', 'beer@example.rw',  'Kicukiro, Kigali'),
 (2, 'SUP-0002', 'Sample Soft Drink Agent',  'Kigali Soft Drinks Agency',    '0788100002', 'soft@example.rw',  'Nyarugenge, Kigali'),
 (3, 'SUP-0003', 'Sample Water Supplier',    'Pure Water Supply Ltd',        '0788100003', 'water@example.rw', 'Gasabo, Kigali');

-- PRODUCTS (stock, cost and prices are per BASE unit) ------------------
-- avg_cost starts at 0 and is recalculated on every confirmed purchase.
INSERT INTO products
 (id, product_code, category_id, name, brand, barcode, base_unit, purchase_price, avg_cost, selling_price, quantity_in_stock, min_stock, expiry_date) VALUES
 (1, 'PRD-0001', 1, 'Primus 72cl',       'Bralirwa',     '6001000000011', 'bottle',  700, 0, 1000, 0, 40, NULL),
 (2, 'PRD-0002', 1, 'Mutzig 50cl',       'Bralirwa',     '6001000000028', 'bottle',  750, 0, 1000, 0, 40, NULL),
 (3, 'PRD-0003', 1, 'Heineken 33cl Can', 'Heineken',     '6001000000035', 'can',    1100, 0, 1500, 0, 24, NULL),
 (4, 'PRD-0004', 2, 'Coca-Cola 30cl',    'Coca-Cola',    '6001000000042', 'bottle',  500, 0,  700, 0, 48, NULL),
 (5, 'PRD-0005', 2, 'Fanta Orange 30cl', 'Coca-Cola',    '6001000000059', 'bottle',  500, 0,  700, 0, 48, NULL),
 (6, 'PRD-0006', 3, 'Drinking Water 50cl','Inyange',     '6001000000066', 'bottle',  300, 0,  500, 0, 48, NULL),
 (7, 'PRD-0007', 4, 'Red Wine 75cl',     'House Wine',   '6001000000073', 'bottle', 5000, 0, 8000, 0,  6, NULL);

-- PRODUCT UNITS (conversion_factor = base units contained) -------------
-- selling_price here is the price per that unit.
INSERT INTO product_units (product_id, unit_name, conversion_factor, selling_price, is_base) VALUES
 (1, 'bottle',  1,  1000, 1), (1, 'crate', 20, 19000, 0),
 (2, 'bottle',  1,  1000, 1), (2, 'crate', 20, 19000, 0),
 (3, 'can',     1,  1500, 1), (3, 'pack',   6,  8500, 0),
 (4, 'bottle',  1,   700, 1), (4, 'crate', 24, 16000, 0),
 (5, 'bottle',  1,   700, 1), (5, 'crate', 24, 16000, 0),
 (6, 'bottle',  1,   500, 1), (6, 'pack',  12,  5500, 0),
 (7, 'bottle',  1,  8000, 1);

-- CUSTOMERS (credit customers) -----------------------------------------
-- Routine table and walk-in sales never need a customer. Registering a
-- customer is only for authorised credit accounts or optional services.
INSERT INTO customers (id, customer_code, full_name, phone, address, credit_limit, current_debt) VALUES
 (1, 'CUS-0001', 'Sample Customer One', '0788200001', 'Kicukiro, Kigali', 50000, 0),
 (2, 'CUS-0002', 'Sample Customer Two', '0788200002', 'Kanombe, Kigali',  30000, 0);

-- FLOOR AREAS (Settings -> Floor & Seating) ----------------------------
INSERT INTO bar_areas (id, name, sort_order, active) VALUES
 (1, 'Main Hall', 1, 1),
 (2, 'Terrace',   2, 1);

-- TABLES (unique code, area, seats). No customers are registered here.
INSERT INTO bar_tables (id, code, area_id, capacity, status, active, floor_position) VALUES
 (1,  'T01', 1, 4, 'available', 1, 'A1'),
 (2,  'T02', 1, 4, 'available', 1, 'A2'),
 (3,  'T03', 1, 6, 'available', 1, 'A3'),
 (4,  'T04', 1, 4, 'available', 1, 'A4'),
 (5,  'T05', 1, 2, 'available', 1, 'A5'),
 (6,  'T06', 1, 8, 'available', 1, 'A6'),
 (7,  'T07', 2, 4, 'available', 1, 'B1'),
 (8,  'T08', 2, 4, 'available', 1, 'B2'),
 (9,  'T09', 2, 6, 'available', 1, 'B3'),
 (10, 'T10', 2, 2, 'available', 1, 'B4');