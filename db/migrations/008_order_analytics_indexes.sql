BEGIN;

-- Speeds up status-based operational views and analytics by tenant.
CREATE INDEX IF NOT EXISTS "Order_tenant_status_idx"
ON public."Order" USING btree (tenant_id, status);

-- Speeds up queries that normalize status using LOWER(COALESCE(status,'pending')).
CREATE INDEX IF NOT EXISTS "Order_tenant_status_norm_idx"
ON public."Order" USING btree (tenant_id, (LOWER(COALESCE(status, 'pending'))));

-- Speeds up dashboard trend/sales widgets that read only active orders by date.
CREATE INDEX IF NOT EXISTS "Order_tenant_created_active_idx"
ON public."Order" USING btree (tenant_id, created_at)
WHERE LOWER(COALESCE(status, 'pending')) NOT IN ('canceled', 'cancelled');

COMMIT;
