BEGIN;

CREATE TABLE IF NOT EXISTS "InventoryLot" (
    id text PRIMARY KEY,
    tenant_id text NOT NULL,
    product_id text NOT NULL,
    source_type text NOT NULL,
    source_ref text,
    unit_cost numeric(12,4) NOT NULL DEFAULT 0,
    initial_quantity integer NOT NULL,
    remaining_quantity integer NOT NULL,
    metadata jsonb,
    received_at timestamp without time zone NOT NULL DEFAULT NOW(),
    created_at timestamp without time zone NOT NULL DEFAULT NOW(),
    updated_at timestamp without time zone NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS "InventoryLotAllocation" (
    id text PRIMARY KEY,
    tenant_id text NOT NULL,
    lot_id text NOT NULL,
    order_item_id text NOT NULL,
    product_id text NOT NULL,
    quantity integer NOT NULL,
    unit_cost numeric(12,4) NOT NULL DEFAULT 0,
    metadata jsonb,
    created_at timestamp without time zone NOT NULL DEFAULT NOW()
);

ALTER TABLE "OrderItem"
ALTER COLUMN unit_cost TYPE numeric(12,4)
USING COALESCE(unit_cost, 0)::numeric(12,4);

ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS cost_total numeric(12,4);

UPDATE "OrderItem"
SET cost_total = ROUND((COALESCE(quantity, 0) * COALESCE(unit_cost, 0))::numeric, 4)
WHERE cost_total IS NULL;

ALTER TABLE "OrderItem" ALTER COLUMN cost_total SET DEFAULT 0;
ALTER TABLE "OrderItem" ALTER COLUMN cost_total SET NOT NULL;

INSERT INTO "InventoryLot" (
    id,
    tenant_id,
    product_id,
    source_type,
    source_ref,
    unit_cost,
    initial_quantity,
    remaining_quantity,
    metadata,
    received_at,
    created_at,
    updated_at
)
SELECT
    'lot_seed_' || md5(COALESCE(p.tenant_id, '') || ':' || COALESCE(p.id, '') || ':opening'),
    p.tenant_id,
    p.id,
    'bootstrap_opening',
    p.id,
    COALESCE(p.cost, 0)::numeric(12,4),
    COALESCE(p.quantity, 0),
    COALESCE(p.quantity, 0),
    jsonb_build_object('seed', 'migration_012_add_inventory_lots_fifo'),
    COALESCE(p.created_at, NOW()),
    NOW(),
    NOW()
FROM "Product" p
WHERE COALESCE(p.quantity, 0) > 0
  AND COALESCE(p.tenant_id, '') <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM "InventoryLot" l
      WHERE l.tenant_id = p.tenant_id
        AND l.product_id = p.id
  );

CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_product_received_idx"
ON "InventoryLot" (tenant_id, product_id, received_at, created_at);

CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_product_remaining_idx"
ON "InventoryLot" (tenant_id, product_id, remaining_quantity);

CREATE INDEX IF NOT EXISTS "InventoryLotAllocation_tenant_order_item_idx"
ON "InventoryLotAllocation" (tenant_id, order_item_id);

CREATE INDEX IF NOT EXISTS "InventoryLotAllocation_tenant_product_idx"
ON "InventoryLotAllocation" (tenant_id, product_id);

CREATE INDEX IF NOT EXISTS "InventoryLotAllocation_tenant_lot_idx"
ON "InventoryLotAllocation" (tenant_id, lot_id);

COMMIT;
