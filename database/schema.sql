-- =====================================================================
-- Inventory and Sales Management System — database schema
-- Target: Supabase PostgreSQL (tested on PostgreSQL 16/17)
--
-- Safe to run on an EMPTY database. It does NOT drop anything.
-- Run it once in the Supabase SQL Editor (or with psql).
-- =====================================================================

-- ---------------------------------------------------------------------
-- Helper: keep updated_at current on every UPDATE
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = ''
AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------------
-- users: people who can log in (admin or staff)
-- ---------------------------------------------------------------------
CREATE TABLE public.users (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    full_name      VARCHAR(120) NOT NULL CHECK (length(trim(full_name)) >= 2),
    email          VARCHAR(255) NOT NULL CHECK (position('@' IN email) > 1),
    password_hash  VARCHAR(255) NOT NULL,
    role           VARCHAR(10)  NOT NULL DEFAULT 'staff' CHECK (role IN ('admin', 'staff')),
    is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
    last_login_at  TIMESTAMPTZ,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);
-- Emails are unique regardless of letter case
CREATE UNIQUE INDEX users_email_lower_uq ON public.users (lower(email));

-- ---------------------------------------------------------------------
-- categories
-- ---------------------------------------------------------------------
CREATE TABLE public.categories (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         VARCHAR(80)  NOT NULL CHECK (length(trim(name)) >= 2),
    description  VARCHAR(500),
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
-- "Beverages" and "beverages" count as the same category
CREATE UNIQUE INDEX categories_name_lower_uq ON public.categories (lower(name));

-- ---------------------------------------------------------------------
-- products
--   quantity can never be negative (CHECK) -> stock cannot go below 0
--   is_active = FALSE means "archived" (kept for history, hidden from sales)
-- ---------------------------------------------------------------------
CREATE TABLE public.products (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    category_id     BIGINT        NOT NULL REFERENCES public.categories (id) ON DELETE RESTRICT,
    sku             VARCHAR(40)   NOT NULL CHECK (sku ~ '^[A-Z0-9][A-Z0-9-]{1,39}$'),
    name            VARCHAR(150)  NOT NULL CHECK (length(trim(name)) >= 2),
    description     VARCHAR(1000),
    purchase_price  NUMERIC(12,2) NOT NULL CHECK (purchase_price >= 0),
    selling_price   NUMERIC(12,2) NOT NULL CHECK (selling_price >= 0),
    quantity        INTEGER       NOT NULL DEFAULT 0 CHECK (quantity >= 0),
    reorder_level   INTEGER       NOT NULL DEFAULT 0 CHECK (reorder_level >= 0),
    is_active       BOOLEAN       NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX products_sku_uq       ON public.products (sku);
CREATE INDEX        products_category_idx ON public.products (category_id);
CREATE INDEX        products_name_idx     ON public.products (lower(name));
CREATE INDEX        products_low_stock_idx ON public.products (id) WHERE is_active AND quantity <= reorder_level;

-- ---------------------------------------------------------------------
-- sales (header) and sale_items (lines)
-- ---------------------------------------------------------------------
CREATE SEQUENCE public.sales_txn_seq;

CREATE TABLE public.sales (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transaction_number  VARCHAR(30)   NOT NULL,
    user_id             BIGINT        NOT NULL REFERENCES public.users (id) ON DELETE RESTRICT,
    subtotal            NUMERIC(12,2) NOT NULL CHECK (subtotal >= 0),
    discount            NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (discount >= 0),
    total_amount        NUMERIC(12,2) NOT NULL CHECK (total_amount >= 0),
    payment_method      VARCHAR(10)   NOT NULL CHECK (payment_method IN ('cash', 'gcash', 'card')),
    status              VARCHAR(10)   NOT NULL DEFAULT 'completed' CHECK (status IN ('completed', 'voided')),
    void_reason         VARCHAR(255),
    voided_by           BIGINT        REFERENCES public.users (id) ON DELETE RESTRICT,
    voided_at           TIMESTAMPTZ,
    created_at          TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT sales_discount_le_subtotal CHECK (discount <= subtotal),
    CONSTRAINT sales_total_matches       CHECK (total_amount = subtotal - discount),
    CONSTRAINT sales_void_fields         CHECK (
        (status = 'completed' AND voided_at IS NULL AND voided_by IS NULL)
        OR (status = 'voided' AND voided_at IS NOT NULL AND voided_by IS NOT NULL)
    )
);
CREATE UNIQUE INDEX sales_txn_uq          ON public.sales (transaction_number);
CREATE INDEX        sales_created_at_idx  ON public.sales (created_at);
CREATE INDEX        sales_status_idx      ON public.sales (status, created_at);
CREATE INDEX        sales_user_idx        ON public.sales (user_id);
CREATE INDEX        sales_voided_by_idx   ON public.sales (voided_by);

CREATE TABLE public.sale_items (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sale_id     BIGINT        NOT NULL REFERENCES public.sales (id) ON DELETE RESTRICT,
    product_id  BIGINT        NOT NULL REFERENCES public.products (id) ON DELETE RESTRICT,
    quantity    INTEGER       NOT NULL CHECK (quantity > 0),
    unit_price  NUMERIC(12,2) NOT NULL CHECK (unit_price >= 0),
    line_total  NUMERIC(12,2) NOT NULL CHECK (line_total >= 0),
    CONSTRAINT sale_items_line_total_matches CHECK (line_total = quantity * unit_price),
    CONSTRAINT sale_items_one_line_per_product UNIQUE (sale_id, product_id)
);
CREATE INDEX sale_items_product_idx ON public.sale_items (product_id);

-- ---------------------------------------------------------------------
-- stock_movements: audit trail of EVERY quantity change
--   quantity is the signed change (+ in, - out)
--   resulting_quantity must equal previous_quantity + quantity
-- ---------------------------------------------------------------------
CREATE TABLE public.stock_movements (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id          BIGINT       NOT NULL REFERENCES public.products (id) ON DELETE RESTRICT,
    user_id             BIGINT       NOT NULL REFERENCES public.users (id) ON DELETE RESTRICT,
    sale_id             BIGINT       REFERENCES public.sales (id) ON DELETE RESTRICT,
    movement_type       VARCHAR(12)  NOT NULL CHECK (movement_type IN ('stock_in', 'stock_out', 'adjustment', 'sale', 'sale_void', 'initial')),
    quantity            INTEGER      NOT NULL CHECK (quantity <> 0),
    previous_quantity   INTEGER      NOT NULL CHECK (previous_quantity >= 0),
    resulting_quantity  INTEGER      NOT NULL CHECK (resulting_quantity >= 0),
    notes               VARCHAR(500),
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT stock_movements_math CHECK (resulting_quantity = previous_quantity + quantity),
    CONSTRAINT stock_movements_direction CHECK (
        (movement_type IN ('stock_in', 'sale_void', 'initial') AND quantity > 0)
        OR (movement_type IN ('stock_out', 'sale') AND quantity < 0)
        OR movement_type = 'adjustment'
    ),
    CONSTRAINT stock_movements_sale_link CHECK (
        (movement_type IN ('sale', 'sale_void')) = (sale_id IS NOT NULL)
    )
);
CREATE INDEX stock_movements_product_idx ON public.stock_movements (product_id, created_at DESC);
CREATE INDEX stock_movements_created_idx ON public.stock_movements (created_at DESC);
CREATE INDEX stock_movements_type_idx    ON public.stock_movements (movement_type, created_at DESC);
CREATE INDEX stock_movements_user_idx    ON public.stock_movements (user_id);
CREATE INDEX stock_movements_sale_idx    ON public.stock_movements (sale_id);

-- ---------------------------------------------------------------------
-- low_stock_alerts
--   An alert is "open" while quantity <= reorder_level.
--   The partial unique index guarantees at most ONE open alert per product,
--   so the same unresolved condition never creates duplicates.
-- ---------------------------------------------------------------------
CREATE TABLE public.low_stock_alerts (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id              BIGINT      NOT NULL REFERENCES public.products (id) ON DELETE RESTRICT,
    status                  VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'resolved')),
    quantity_at_alert       INTEGER     NOT NULL CHECK (quantity_at_alert >= 0),
    reorder_level_at_alert  INTEGER     NOT NULL CHECK (reorder_level_at_alert >= 0),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    resolved_at             TIMESTAMPTZ,
    CONSTRAINT low_stock_alerts_resolved CHECK ((status = 'resolved') = (resolved_at IS NOT NULL))
);
CREATE UNIQUE INDEX low_stock_alerts_one_open_uq ON public.low_stock_alerts (product_id) WHERE status = 'open';
CREATE INDEX        low_stock_alerts_status_idx  ON public.low_stock_alerts (status, created_at DESC);

-- ---------------------------------------------------------------------
-- updated_at triggers
-- ---------------------------------------------------------------------
CREATE TRIGGER users_set_updated_at      BEFORE UPDATE ON public.users      FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER categories_set_updated_at BEFORE UPDATE ON public.categories FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER products_set_updated_at   BEFORE UPDATE ON public.products   FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ---------------------------------------------------------------------
-- Security (Supabase-specific)
--   Supabase exposes the public schema through its REST "Data API".
--   This app talks to Postgres ONLY through PHP PDO, so we enable
--   Row Level Security with NO policies: the anon/authenticated API roles
--   can read nothing, while the server-side postgres role (used by PHP)
--   is unaffected.
-- ---------------------------------------------------------------------
ALTER TABLE public.users            ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.categories       ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.products         ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.sales            ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.sale_items       ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.stock_movements  ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.low_stock_alerts ENABLE ROW LEVEL SECURITY;
