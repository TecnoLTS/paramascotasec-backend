BEGIN;

CREATE TABLE IF NOT EXISTS "DiscountCode" (
    id text PRIMARY KEY,
    tenant_id text NOT NULL,
    code text NOT NULL,
    name text,
    description text,
    type text NOT NULL,
    value numeric(12,2) NOT NULL,
    min_subtotal numeric(12,2) DEFAULT 0 NOT NULL,
    max_discount numeric(12,2),
    max_uses integer,
    used_count integer DEFAULT 0 NOT NULL,
    starts_at timestamp without time zone,
    ends_at timestamp without time zone,
    is_active boolean DEFAULT true NOT NULL,
    created_by text,
    metadata jsonb,
    created_at timestamp without time zone DEFAULT NOW() NOT NULL,
    updated_at timestamp without time zone DEFAULT NOW() NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS "DiscountCode_tenant_code_uidx"
ON public."DiscountCode" USING btree (tenant_id, code);

CREATE INDEX IF NOT EXISTS "DiscountCode_tenant_active_idx"
ON public."DiscountCode" USING btree (tenant_id, is_active);

CREATE INDEX IF NOT EXISTS "DiscountCode_tenant_window_idx"
ON public."DiscountCode" USING btree (tenant_id, starts_at, ends_at);

CREATE TABLE IF NOT EXISTS "DiscountAudit" (
    id text PRIMARY KEY,
    tenant_id text NOT NULL,
    discount_code_id text,
    code text,
    action text NOT NULL,
    reason text,
    order_id text,
    amount numeric(12,2),
    payload jsonb,
    user_id text,
    created_at timestamp without time zone DEFAULT NOW() NOT NULL
);

CREATE INDEX IF NOT EXISTS "DiscountAudit_tenant_created_idx"
ON public."DiscountAudit" USING btree (tenant_id, created_at DESC);

CREATE INDEX IF NOT EXISTS "DiscountAudit_tenant_code_idx"
ON public."DiscountAudit" USING btree (tenant_id, code);

CREATE INDEX IF NOT EXISTS "DiscountAudit_tenant_order_idx"
ON public."DiscountAudit" USING btree (tenant_id, order_id);

ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS discount_code text;
ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS discount_total numeric(12,2) DEFAULT 0;
ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS discount_snapshot jsonb;

COMMIT;
