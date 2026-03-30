CREATE TABLE IF NOT EXISTS "ContactMessage" (
    id text PRIMARY KEY,
    tenant_id text NOT NULL,
    name text NOT NULL,
    email text NOT NULL,
    phone text,
    subject text NOT NULL,
    message text NOT NULL,
    source text DEFAULT 'web' NOT NULL,
    status text DEFAULT 'new' NOT NULL,
    ip_address text,
    user_agent text,
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp without time zone DEFAULT NOW() NOT NULL,
    updated_at timestamp without time zone DEFAULT NOW() NOT NULL
);

CREATE INDEX IF NOT EXISTS "ContactMessage_tenant_created_idx"
    ON "ContactMessage" (tenant_id, created_at DESC);

CREATE INDEX IF NOT EXISTS "ContactMessage_tenant_status_idx"
    ON "ContactMessage" (tenant_id, status);
