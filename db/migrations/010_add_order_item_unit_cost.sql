BEGIN;

ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS unit_cost numeric(10,2);

UPDATE "OrderItem" oi
SET unit_cost = COALESCE((
    SELECT p.cost
    FROM "Order" o
    LEFT JOIN "Product" p ON p.id = oi.product_id AND p.tenant_id = o.tenant_id
    WHERE o.id = oi.order_id
    LIMIT 1
), 0)
WHERE oi.unit_cost IS NULL;

UPDATE "OrderItem"
SET unit_cost = 0
WHERE unit_cost IS NULL;

ALTER TABLE "OrderItem" ALTER COLUMN unit_cost SET DEFAULT 0;
ALTER TABLE "OrderItem" ALTER COLUMN unit_cost SET NOT NULL;

COMMIT;
