ALTER TABLE "Order"
ADD COLUMN IF NOT EXISTS invoice_number text;

ALTER TABLE "Order"
ADD COLUMN IF NOT EXISTS invoice_html text;

ALTER TABLE "Order"
ADD COLUMN IF NOT EXISTS invoice_created_at timestamp(3) without time zone;

ALTER TABLE "Order"
ADD COLUMN IF NOT EXISTS invoice_data jsonb;
