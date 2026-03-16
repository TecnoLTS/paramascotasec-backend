BEGIN;

CREATE TABLE IF NOT EXISTS "PurchaseInvoice" (
    id text PRIMARY KEY,
    tenant_id text NOT NULL,
    supplier_name text NOT NULL,
    supplier_document text,
    invoice_number text NOT NULL,
    external_key text NOT NULL,
    issued_at date NOT NULL,
    subtotal numeric(12,4) NOT NULL DEFAULT 0,
    tax_total numeric(12,4) NOT NULL DEFAULT 0,
    total numeric(12,4) NOT NULL DEFAULT 0,
    notes text,
    metadata jsonb,
    created_at timestamp without time zone NOT NULL DEFAULT NOW(),
    updated_at timestamp without time zone NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS "PurchaseInvoiceItem" (
    id text PRIMARY KEY,
    purchase_invoice_id text NOT NULL,
    tenant_id text NOT NULL,
    product_id text NOT NULL,
    product_name_snapshot text,
    quantity integer NOT NULL,
    unit_cost numeric(12,4) NOT NULL DEFAULT 0,
    line_total numeric(12,4) NOT NULL DEFAULT 0,
    metadata jsonb,
    created_at timestamp without time zone NOT NULL DEFAULT NOW(),
    updated_at timestamp without time zone NOT NULL DEFAULT NOW()
);

ALTER TABLE "InventoryLot" ADD COLUMN IF NOT EXISTS purchase_invoice_id text;
ALTER TABLE "InventoryLot" ADD COLUMN IF NOT EXISTS purchase_invoice_item_id text;

CREATE UNIQUE INDEX IF NOT EXISTS "PurchaseInvoice_tenant_external_key_uidx"
ON "PurchaseInvoice" (tenant_id, external_key);

CREATE INDEX IF NOT EXISTS "PurchaseInvoice_tenant_issued_idx"
ON "PurchaseInvoice" (tenant_id, issued_at DESC, created_at DESC);

CREATE INDEX IF NOT EXISTS "PurchaseInvoiceItem_tenant_invoice_idx"
ON "PurchaseInvoiceItem" (tenant_id, purchase_invoice_id, created_at ASC);

CREATE INDEX IF NOT EXISTS "PurchaseInvoiceItem_tenant_product_idx"
ON "PurchaseInvoiceItem" (tenant_id, product_id, created_at DESC);

CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_purchase_invoice_idx"
ON "InventoryLot" (tenant_id, purchase_invoice_id);

CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_purchase_invoice_item_idx"
ON "InventoryLot" (tenant_id, purchase_invoice_item_id);

COMMIT;
