ALTER TABLE "Order"
ADD COLUMN IF NOT EXISTS delivery_method text;

UPDATE "Order"
SET delivery_method = CASE
    WHEN COALESCE(shipping, 0) > 0 THEN 'delivery'
    WHEN LOWER(COALESCE(status, '')) IN ('pickup', 'ready_for_pickup', 'ready') THEN 'pickup'
    WHEN COALESCE(TRIM(COALESCE(shipping_address->>'pickupWindow', '')), '') <> '' THEN 'pickup'
    WHEN COALESCE(TRIM(COALESCE(shipping_address->>'provider', '')), '') <> '' THEN 'pickup'
    WHEN shipping_address IS NOT NULL THEN 'delivery'
    ELSE NULL
END
WHERE delivery_method IS NULL;
