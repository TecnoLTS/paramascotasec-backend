BEGIN;

CREATE TABLE IF NOT EXISTS "Tenant" (
    id text PRIMARY KEY,
    name text,
    created_at timestamp without time zone DEFAULT NOW()
);

INSERT INTO "Tenant" (id, name) VALUES
    ('paramascotasec', 'Para Mascotas EC'),
    ('tecnolts', 'TecnoLTS')
ON CONFLICT (id) DO NOTHING;

ALTER TABLE "User" ADD COLUMN IF NOT EXISTS tenant_id text;
ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS tenant_id text;
ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS tenant_id text;
ALTER TABLE "Setting" ADD COLUMN IF NOT EXISTS tenant_id text;

UPDATE "User" SET tenant_id = COALESCE(tenant_id, 'paramascotasec');
UPDATE "Product" SET tenant_id = COALESCE(tenant_id, 'paramascotasec');
UPDATE "Order" SET tenant_id = COALESCE(tenant_id, 'paramascotasec');
UPDATE "Setting" SET tenant_id = COALESCE(tenant_id, 'paramascotasec') WHERE tenant_id IS NULL;
UPDATE "Setting"
SET key = 'paramascotasec:' || key
WHERE key NOT LIKE 'paramascotasec:%' AND key NOT LIKE 'tecnolts:%';

CREATE INDEX IF NOT EXISTS "User_tenant_id_idx" ON public."User" USING btree (tenant_id);
CREATE INDEX IF NOT EXISTS "Product_tenant_id_idx" ON public."Product" USING btree (tenant_id);
CREATE INDEX IF NOT EXISTS "Order_tenant_id_idx" ON public."Order" USING btree (tenant_id);
CREATE INDEX IF NOT EXISTS "Setting_tenant_id_idx" ON public."Setting" USING btree (tenant_id);

COMMIT;
