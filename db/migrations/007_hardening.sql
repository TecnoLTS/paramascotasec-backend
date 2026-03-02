BEGIN;

ALTER TABLE "User" DROP CONSTRAINT IF EXISTS "User_email_key";
ALTER TABLE "Product" DROP CONSTRAINT IF EXISTS "Product_slug_key";

DROP INDEX IF EXISTS "Product_legacy_id_key";

CREATE INDEX IF NOT EXISTS "User_tenant_id_idx" ON public."User" USING btree (tenant_id);
CREATE INDEX IF NOT EXISTS "User_tenant_email_idx" ON public."User" USING btree (tenant_id, email);
CREATE UNIQUE INDEX IF NOT EXISTS "User_tenant_email_uidx" ON public."User" USING btree (tenant_id, email);

CREATE INDEX IF NOT EXISTS "Product_tenant_id_idx" ON public."Product" USING btree (tenant_id);
CREATE INDEX IF NOT EXISTS "Product_tenant_slug_idx" ON public."Product" USING btree (tenant_id, slug);
CREATE UNIQUE INDEX IF NOT EXISTS "Product_tenant_slug_uidx" ON public."Product" USING btree (tenant_id, slug);
CREATE INDEX IF NOT EXISTS "Product_tenant_legacy_id_idx" ON public."Product" USING btree (tenant_id, legacy_id);

CREATE INDEX IF NOT EXISTS "Order_tenant_id_idx" ON public."Order" USING btree (tenant_id);
CREATE INDEX IF NOT EXISTS "Order_tenant_created_idx" ON public."Order" USING btree (tenant_id, created_at);
CREATE INDEX IF NOT EXISTS "Order_tenant_user_idx" ON public."Order" USING btree (tenant_id, user_id);

CREATE INDEX IF NOT EXISTS "OrderItem_order_id_idx" ON public."OrderItem" USING btree (order_id);
CREATE INDEX IF NOT EXISTS "OrderItem_product_id_idx" ON public."OrderItem" USING btree (product_id);
CREATE INDEX IF NOT EXISTS "Image_product_id_idx" ON public."Image" USING btree (product_id);
CREATE INDEX IF NOT EXISTS "Variation_product_id_idx" ON public."Variation" USING btree (product_id);

CREATE INDEX IF NOT EXISTS "Setting_tenant_id_idx" ON public."Setting" USING btree (tenant_id);

COMMIT;
