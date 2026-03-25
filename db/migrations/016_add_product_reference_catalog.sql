CREATE TABLE IF NOT EXISTS "ProductReferenceCatalog" (
    id text PRIMARY KEY,
    tenant_id text NOT NULL,
    catalog_key text NOT NULL,
    label text NOT NULL,
    payload jsonb DEFAULT '{}'::jsonb,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp without time zone DEFAULT NOW() NOT NULL,
    updated_at timestamp without time zone DEFAULT NOW() NOT NULL
);

CREATE INDEX IF NOT EXISTS "ProductReferenceCatalog_tenant_id_idx"
    ON "ProductReferenceCatalog" (tenant_id);

CREATE INDEX IF NOT EXISTS "ProductReferenceCatalog_tenant_catalog_idx"
    ON "ProductReferenceCatalog" (tenant_id, catalog_key, sort_order);
