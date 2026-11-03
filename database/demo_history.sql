-- =====================================================================
-- OPTIONAL — SIMULATED SALES HISTORY FOR THE CLASSROOM DEMO ONLY
-- ---------------------------------------------------------------------
-- Slow-moving analysis needs at least 30 days of recorded sales, which a
-- brand-new system does not have. This script simulates 60 days of past
-- activity so the dashboard, charts, best-sellers and slow-moving
-- recommendations have something to show during the presentation.
--
--   * Every simulated sale has a transaction number starting with "DEMO-".
--   * Every simulated stock movement note starts with "[DEMO]".
--   * It refuses to run if any real (non-DEMO) sale exists.
--
-- Do NOT run this on a database that will be used for a real business.
-- Run order: schema.sql → create_admin.php → seed.sql → (optional) this file
-- =====================================================================

BEGIN;

DO $$
DECLARE
    v_user      BIGINT;
    v_day       INT;
    v_sale      BIGINT;
    v_sales_today INT;
    v_lines     INT;
    v_qty       INT;
    v_prev      INT;
    v_sub       NUMERIC(12,2);
    v_at        TIMESTAMPTZ;
    v_n         INT := 0;
    v_pick      RECORD;
    -- Demand profile: higher weight = sold more often. Products not listed
    -- here are never sold (they become "no sales" slow movers).
    v_profile   JSONB := '{
        "BEV-001": 10, "BEV-003": 12, "PRC-002": 9, "CAN-002": 8, "SNK-002": 7,
        "BEV-002": 5,  "SNK-001": 5,  "CAN-001": 4, "PRC-001": 3, "HSH-001": 3,
        "BEV-004": 2,  "SNK-003": 2,  "PRC-003": 1, "CAN-003": 0.3
    }';
BEGIN
    IF EXISTS (SELECT 1 FROM public.sales WHERE transaction_number NOT LIKE 'DEMO-%') THEN
        RAISE EXCEPTION 'Real sales exist — demo history is only for an empty demo database.';
    END IF;
    IF EXISTS (SELECT 1 FROM public.sales) THEN
        RAISE EXCEPTION 'Demo history was already generated.';
    END IF;
    IF EXISTS (SELECT 1 FROM public.stock_movements WHERE movement_type <> 'initial') THEN
        RAISE EXCEPTION 'Stock has already changed after seeding — run this right after seed.sql.';
    END IF;
    SELECT id INTO v_user FROM public.users WHERE role = 'admin' AND is_active ORDER BY id LIMIT 1;
    IF v_user IS NULL THEN
        RAISE EXCEPTION 'Create an admin first: php scripts/create_admin.php';
    END IF;

    PERFORM setseed(0.42);   -- same "random" history every time

    -- Products and their opening stock now date from 60 days ago.
    UPDATE public.products SET created_at = now() - interval '60 days';
    UPDATE public.stock_movements SET created_at = now() - interval '60 days'
        WHERE movement_type = 'initial';

    FOR v_day IN REVERSE 59..1 LOOP
        -- Mid-period restock of popular items (keeps them selling)
        IF v_day IN (40, 20) THEN
            FOR v_pick IN SELECT id, quantity FROM public.products
                          WHERE sku IN ('BEV-001', 'BEV-003', 'PRC-002', 'CAN-002', 'SNK-002', 'BEV-002', 'SNK-001', 'CAN-001', 'PRC-001', 'HSH-001') LOOP
                v_at := date_trunc('day', now() - make_interval(days => v_day)) + interval '7 hours';
                UPDATE public.products SET quantity = quantity + 40 WHERE id = v_pick.id;
                INSERT INTO public.stock_movements (product_id, user_id, movement_type, quantity, previous_quantity, resulting_quantity, notes, created_at)
                VALUES (v_pick.id, v_user, 'stock_in', 40, v_pick.quantity, v_pick.quantity + 40, '[DEMO] Simulated supplier delivery', v_at);
            END LOOP;
        END IF;

        v_sales_today := 2 + floor(random() * 5)::int;   -- 2..6 sales per day
        FOR i IN 1..v_sales_today LOOP
            -- Sales happen in time order through the day (8:00–20:00) so the
            -- audit trail reads correctly when sorted by time.
            v_at := date_trunc('day', now() - make_interval(days => v_day)) + interval '8 hours'
                    + make_interval(mins => ((i - 1) * 720 / v_sales_today) + floor(random() * (720 / v_sales_today))::int);
            v_n := v_n + 1;
            INSERT INTO public.sales (transaction_number, user_id, subtotal, discount, total_amount, payment_method, created_at)
            VALUES ('DEMO-' || to_char(v_at, 'YYYYMMDD') || '-' || lpad(v_n::text, 4, '0'), v_user, 0, 0, 0,
                    (ARRAY['cash', 'cash', 'cash', 'gcash', 'gcash', 'card'])[1 + floor(random() * 6)::int], v_at)
            RETURNING id INTO v_sale;

            v_lines := 1 + floor(random() * 3)::int;   -- 1..3 different products
            v_sub := 0;
            FOR v_pick IN
                SELECT p.id, p.selling_price, p.quantity
                FROM public.products p
                JOIN jsonb_each_text(v_profile) w(sku, weight) ON w.sku = p.sku
                WHERE p.quantity > 0
                ORDER BY -ln(random()) / w.weight::numeric   -- weighted random order
                LIMIT v_lines
            LOOP
                v_qty := LEAST(1 + floor(random() * 3)::int, v_pick.quantity);
                v_prev := v_pick.quantity;
                INSERT INTO public.sale_items (sale_id, product_id, quantity, unit_price, line_total)
                VALUES (v_sale, v_pick.id, v_qty, v_pick.selling_price, v_qty * v_pick.selling_price);
                UPDATE public.products SET quantity = quantity - v_qty WHERE id = v_pick.id;
                INSERT INTO public.stock_movements (product_id, user_id, sale_id, movement_type, quantity, previous_quantity, resulting_quantity, notes, created_at)
                VALUES (v_pick.id, v_user, v_sale, 'sale', -v_qty, v_prev, v_prev - v_qty, '[DEMO] Simulated sale', v_at);
                v_sub := v_sub + v_qty * v_pick.selling_price;
            END LOOP;

            IF v_sub = 0 THEN
                DELETE FROM public.sales WHERE id = v_sale;   -- nothing left to sell
            ELSE
                UPDATE public.sales SET subtotal = v_sub, total_amount = v_sub WHERE id = v_sale;
            END IF;
        END LOOP;
    END LOOP;

    -- Re-sync low-stock alerts with the final quantities.
    UPDATE public.low_stock_alerts a SET status = 'resolved', resolved_at = now()
    FROM public.products p
    WHERE a.product_id = p.id AND a.status = 'open' AND NOT (p.is_active AND p.quantity <= p.reorder_level);

    INSERT INTO public.low_stock_alerts (product_id, quantity_at_alert, reorder_level_at_alert)
    SELECT p.id, p.quantity, p.reorder_level FROM public.products p
    WHERE p.is_active AND p.quantity <= p.reorder_level
      AND NOT EXISTS (SELECT 1 FROM public.low_stock_alerts a WHERE a.product_id = p.id AND a.status = 'open');

    RAISE NOTICE 'Demo history created: % simulated sales.', (SELECT count(*) FROM public.sales);
END $$;

COMMIT;
