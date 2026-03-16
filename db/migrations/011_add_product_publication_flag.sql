ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS is_published boolean;

UPDATE "Product"
SET is_published = true
WHERE is_published IS NULL;

ALTER TABLE "Product" ALTER COLUMN is_published SET DEFAULT true;
ALTER TABLE "Product" ALTER COLUMN is_published SET NOT NULL;

CREATE INDEX IF NOT EXISTS "Product_tenant_published_idx"
ON public."Product" USING btree (tenant_id, is_published);
