CREATE TABLE IF NOT EXISTS "AuthSecurityEvent" (
    id text PRIMARY KEY,
    tenant_id text NOT NULL,
    user_id text,
    email text,
    event_type text NOT NULL,
    status text DEFAULT 'info' NOT NULL,
    ip_address text,
    user_agent text,
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp without time zone DEFAULT NOW() NOT NULL
);

CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_created_idx"
    ON "AuthSecurityEvent" (tenant_id, created_at DESC);

CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_event_idx"
    ON "AuthSecurityEvent" (tenant_id, event_type, created_at DESC);

CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_user_idx"
    ON "AuthSecurityEvent" (tenant_id, user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_email_idx"
    ON "AuthSecurityEvent" (tenant_id, email, created_at DESC);
