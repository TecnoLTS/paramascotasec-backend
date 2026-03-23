ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS price_net numeric(12,4);
ALTER TABLE "OrderItem" ALTER COLUMN price_net TYPE numeric(12,4) USING COALESCE(price_net, 0)::numeric(12,4);

ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS net_total numeric(12,4);
ALTER TABLE "OrderItem" ALTER COLUMN net_total TYPE numeric(12,4) USING COALESCE(net_total, 0)::numeric(12,4);

ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS tax_rate numeric(6,2);
ALTER TABLE "OrderItem" ALTER COLUMN tax_rate TYPE numeric(6,2) USING COALESCE(tax_rate, 0)::numeric(6,2);

ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS tax_amount numeric(12,4);
ALTER TABLE "OrderItem" ALTER COLUMN tax_amount TYPE numeric(12,4) USING COALESCE(tax_amount, 0)::numeric(12,4);

UPDATE "OrderItem" oi
SET price_net = ROUND((
    COALESCE(oi.price, 0) / NULLIF((1 + (COALESCE(o.vat_rate, 0) / 100.0)), 0)
)::numeric, 4)
FROM "Order" o
WHERE oi.order_id = o.id
  AND oi.price_net IS NULL;

UPDATE "OrderItem" oi
SET net_total = ROUND((
    COALESCE(oi.quantity, 0) * COALESCE(oi.price_net, 0)
)::numeric, 4)
WHERE oi.net_total IS NULL;

UPDATE "OrderItem" oi
SET tax_rate = COALESCE(o.vat_rate, 0)
FROM "Order" o
WHERE oi.order_id = o.id
  AND oi.tax_rate IS NULL;

UPDATE "OrderItem" oi
SET tax_amount = ROUND((
    (COALESCE(oi.quantity, 0) * COALESCE(oi.price, 0)) - COALESCE(oi.net_total, 0)
)::numeric, 4)
WHERE oi.tax_amount IS NULL;

UPDATE "OrderItem" SET price_net = 0 WHERE price_net IS NULL;
ALTER TABLE "OrderItem" ALTER COLUMN price_net SET DEFAULT 0;
ALTER TABLE "OrderItem" ALTER COLUMN price_net SET NOT NULL;

UPDATE "OrderItem" SET net_total = 0 WHERE net_total IS NULL;
ALTER TABLE "OrderItem" ALTER COLUMN net_total SET DEFAULT 0;
ALTER TABLE "OrderItem" ALTER COLUMN net_total SET NOT NULL;

UPDATE "OrderItem" SET tax_rate = 0 WHERE tax_rate IS NULL;
ALTER TABLE "OrderItem" ALTER COLUMN tax_rate SET DEFAULT 0;
ALTER TABLE "OrderItem" ALTER COLUMN tax_rate SET NOT NULL;

UPDATE "OrderItem" SET tax_amount = 0 WHERE tax_amount IS NULL;
ALTER TABLE "OrderItem" ALTER COLUMN tax_amount SET DEFAULT 0;
ALTER TABLE "OrderItem" ALTER COLUMN tax_amount SET NOT NULL;
