-- =====================================================================
-- DEMONSTRATION DATA ONLY
-- ---------------------------------------------------------------------
-- These categories and products are sample records for the class demo.
-- Every row is tagged "[DEMO]" in its description so it is easy to spot.
-- NO sales are seeded: every sale, top-seller and slow-moving result you
-- see in the app comes from transactions you actually record.
--
-- Run order:  1) schema.sql   2) php scripts/create_admin.php   3) seed.sql
-- (seed.sql needs an admin user, because every opening stock quantity is
--  recorded as an 'initial' stock movement for the audit trail.)
-- =====================================================================

BEGIN;

-- Guard: if this fails, the whole transaction is rolled back (nothing inserted)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM public.users WHERE role = 'admin' AND is_active) THEN
        RAISE EXCEPTION 'Create an admin first: php scripts/create_admin.php';
    END IF;
    IF EXISTS (SELECT 1 FROM public.products) THEN
        RAISE EXCEPTION 'Products already exist — seed.sql is only for an empty database.';
    END IF;
END $$;

INSERT INTO public.categories (name, description) VALUES
    ('Beverages',      '[DEMO] Softdrinks, juices, coffee and water'),
    ('Snacks',         '[DEMO] Chips, biscuits and candies'),
    ('Canned Goods',   '[DEMO] Canned meat, fish and vegetables'),
    ('Personal Care',  '[DEMO] Soap, shampoo and toiletries'),
    ('Household',      '[DEMO] Cleaning supplies and home essentials');

INSERT INTO public.products (category_id, sku, name, description, purchase_price, selling_price, quantity, reorder_level)
SELECT c.id, v.sku, v.name, '[DEMO] ' || v.descr, v.cost, v.price, v.qty, v.reorder
FROM (VALUES
    ('Beverages',     'BEV-001', 'Bottled Water 500ml',       'Purified drinking water',        8.50,  15.00, 120, 30),
    ('Beverages',     'BEV-002', 'Cola 1.5L',                 'Carbonated soft drink',          52.00, 70.00,  40, 12),
    ('Beverages',     'BEV-003', '3-in-1 Coffee Sachet',      'Instant coffee mix, 20g',         6.00, 10.00, 200, 50),
    ('Beverages',     'BEV-004', 'Orange Juice 1L',           'Ready-to-drink juice',           58.00, 85.00,   6, 10),
    ('Snacks',        'SNK-001', 'Potato Chips 60g',          'Salted potato chips',            22.00, 35.00,  55, 15),
    ('Snacks',        'SNK-002', 'Chocolate Bar 40g',         'Milk chocolate',                 18.00, 30.00,  70, 20),
    ('Snacks',        'SNK-003', 'Cream Crackers 250g',       'Plain crackers',                 38.00, 55.00,   4,  8),
    ('Canned Goods',  'CAN-001', 'Corned Beef 150g',          'Canned corned beef',             36.00, 52.00,  48, 12),
    ('Canned Goods',  'CAN-002', 'Sardines in Tomato 155g',   'Canned sardines',                18.50, 26.00,  90, 25),
    ('Canned Goods',  'CAN-003', 'Sweet Corn 425g',           'Whole kernel corn',              42.00, 60.00,  25,  6),
    ('Personal Care', 'PRC-001', 'Bath Soap 90g',             'Antibacterial soap',             28.00, 42.00,  35, 10),
    ('Personal Care', 'PRC-002', 'Shampoo Sachet 12ml',       'Single-use shampoo',              5.00,  8.00, 150, 40),
    ('Personal Care', 'PRC-003', 'Toothpaste 150g',           'Fluoride toothpaste',            65.00, 95.00,   3,  8),
    ('Household',     'HSH-001', 'Dishwashing Liquid 250ml',  'Lemon dishwashing liquid',       32.00, 48.00,  30, 10),
    ('Household',     'HSH-002', 'Laundry Powder 1kg',        'Powder detergent',               85.00,120.00,  18,  6),
    ('Household',     'HSH-003', 'Trash Bags (10 pcs)',       'Large garbage bags',             40.00, 60.00,  22,  8)
) AS v(cat, sku, name, descr, cost, price, qty, reorder)
JOIN public.categories c ON c.name = v.cat;

-- Opening stock is recorded in the audit trail
INSERT INTO public.stock_movements (product_id, user_id, movement_type, quantity, previous_quantity, resulting_quantity, notes)
SELECT p.id,
       (SELECT id FROM public.users WHERE role = 'admin' AND is_active ORDER BY id LIMIT 1),
       'initial', p.quantity, 0, p.quantity, '[DEMO] Opening stock from seed.sql'
FROM public.products p
WHERE p.quantity > 0;

-- Open alerts for demo products that start at or below their reorder level
INSERT INTO public.low_stock_alerts (product_id, quantity_at_alert, reorder_level_at_alert)
SELECT id, quantity, reorder_level
FROM public.products
WHERE is_active AND quantity <= reorder_level;

COMMIT;
