CREATE INDEX IF NOT EXISTS "Product_tenant_created_idx"
ON public."Product" USING btree (tenant_id, created_at DESC);

CREATE INDEX IF NOT EXISTS "Product_catalog_listing_idx"
ON public."Product" USING btree (tenant_id, created_at DESC)
WHERE COALESCE(is_published, true) = true
  AND COALESCE(quantity, 0) > 0;
