CREATE TABLE IF NOT EXISTS "FinancialPeriod" (
    id varchar(64) PRIMARY KEY,
    tenant_id varchar(120) NOT NULL,
    period_key varchar(7) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'open',
    snapshot_json jsonb NULL,
    closed_by_user_id varchar(64) NULL,
    closed_at timestamptz NULL,
    notes text NULL,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    updated_at timestamptz NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, period_key)
);

CREATE INDEX IF NOT EXISTS idx_financial_period_tenant_dates
    ON "FinancialPeriod"(tenant_id, start_date DESC, status);

CREATE TABLE IF NOT EXISTS "FinancialAdjustment" (
    id varchar(64) PRIMARY KEY,
    tenant_id varchar(120) NOT NULL,
    period_key varchar(7) NOT NULL,
    adjustment_date date NOT NULL,
    type varchar(40) NOT NULL,
    target_type varchar(60) NULL,
    target_id varchar(80) NULL,
    original_period_key varchar(7) NULL,
    description text NOT NULL,
    amount numeric(12,2) NOT NULL DEFAULT 0,
    tax_amount numeric(12,2) NOT NULL DEFAULT 0,
    total numeric(12,2) NOT NULL DEFAULT 0,
    reason text NULL,
    created_by_user_id varchar(64) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_financial_adjustment_tenant_period
    ON "FinancialAdjustment"(tenant_id, period_key, created_at DESC);

CREATE TABLE IF NOT EXISTS "BusinessExpenseRecurrence" (
    id varchar(64) PRIMARY KEY,
    tenant_id varchar(120) NOT NULL,
    category varchar(120) NOT NULL,
    description text NOT NULL,
    amount numeric(12,2) NOT NULL DEFAULT 0,
    tax_amount numeric(12,2) NOT NULL DEFAULT 0,
    total numeric(12,2) NOT NULL DEFAULT 0,
    frequency varchar(20) NOT NULL DEFAULT 'monthly',
    interval_count integer NOT NULL DEFAULT 1,
    start_date date NOT NULL,
    next_due_date date NOT NULL,
    payment_method varchar(60) NULL,
    reference varchar(160) NULL,
    notes text NULL,
    active boolean NOT NULL DEFAULT TRUE,
    created_by_user_id varchar(64) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_business_expense_recurrence_tenant_next
    ON "BusinessExpenseRecurrence"(tenant_id, active, next_due_date);

CREATE TABLE IF NOT EXISTS "BusinessExpense" (
    id varchar(64) PRIMARY KEY,
    tenant_id varchar(120) NOT NULL,
    recurrence_id varchar(64) NULL REFERENCES "BusinessExpenseRecurrence"(id) ON DELETE SET NULL,
    category varchar(120) NOT NULL,
    description text NOT NULL,
    amount numeric(12,2) NOT NULL DEFAULT 0,
    tax_amount numeric(12,2) NOT NULL DEFAULT 0,
    total numeric(12,2) NOT NULL DEFAULT 0,
    expense_date date NOT NULL,
    due_date date NULL,
    paid_at timestamptz NULL,
    status varchar(20) NOT NULL DEFAULT 'pending',
    type varchar(30) NOT NULL DEFAULT 'one_time',
    payment_method varchar(60) NULL,
    reference varchar(160) NULL,
    notes text NULL,
    source varchar(40) NULL,
    source_id varchar(80) NULL,
    created_by_user_id varchar(64) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    updated_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_business_expense_tenant_status_date
    ON "BusinessExpense"(tenant_id, status, expense_date DESC);

CREATE UNIQUE INDEX IF NOT EXISTS idx_business_expense_recurrence_due_unique
    ON "BusinessExpense"(tenant_id, recurrence_id, due_date)
    WHERE recurrence_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS "BusinessExpensePayment" (
    id bigserial PRIMARY KEY,
    tenant_id varchar(120) NOT NULL,
    expense_id varchar(64) NOT NULL REFERENCES "BusinessExpense"(id) ON DELETE CASCADE,
    amount numeric(12,2) NOT NULL DEFAULT 0,
    paid_at timestamptz NOT NULL DEFAULT NOW(),
    payment_method varchar(60) NULL,
    reference varchar(160) NULL,
    notes text NULL,
    created_by_user_id varchar(64) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_business_expense_payment_expense
    ON "BusinessExpensePayment"(tenant_id, expense_id, paid_at DESC);

CREATE TABLE IF NOT EXISTS "PosShift" (
    id varchar(64) PRIMARY KEY,
    tenant_id varchar(120) NOT NULL,
    opened_by_user_id varchar(64) NOT NULL,
    opened_at timestamptz NOT NULL DEFAULT NOW(),
    opening_cash numeric(12,2) NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'open',
    open_notes text NULL,
    closed_by_user_id varchar(64) NULL,
    closed_at timestamptz NULL,
    closing_cash numeric(12,2) NULL,
    close_notes text NULL,
    expected_cash numeric(12,2) NULL,
    difference_cash numeric(12,2) NULL,
    summary_json text NULL
);

CREATE INDEX IF NOT EXISTS idx_pos_shift_tenant_status
    ON "PosShift"(tenant_id, status);

CREATE INDEX IF NOT EXISTS idx_pos_shift_tenant_opened_at
    ON "PosShift"(tenant_id, opened_at DESC);

CREATE TABLE IF NOT EXISTS "PosMovement" (
    id bigserial PRIMARY KEY,
    tenant_id varchar(120) NOT NULL,
    shift_id varchar(64) NOT NULL,
    type varchar(20) NOT NULL,
    amount numeric(12,2) NOT NULL,
    description text NULL,
    business_expense_id varchar(64) NULL,
    created_by_user_id varchar(64) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    CONSTRAINT pos_movement_shift_fk FOREIGN KEY (shift_id) REFERENCES "PosShift"(id) ON DELETE CASCADE
);

ALTER TABLE "PosMovement" ADD COLUMN IF NOT EXISTS business_expense_id varchar(64) NULL;

CREATE INDEX IF NOT EXISTS idx_pos_movement_shift
    ON "PosMovement"(tenant_id, shift_id, created_at DESC);

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'paramascotasec_backend_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE
            "FinancialPeriod",
            "FinancialAdjustment",
            "BusinessExpenseRecurrence",
            "BusinessExpense",
            "BusinessExpensePayment",
            "PosShift",
            "PosMovement"
        TO paramascotasec_backend_app;

        GRANT USAGE, SELECT ON SEQUENCE
            "BusinessExpensePayment_id_seq",
            "PosMovement_id_seq"
        TO paramascotasec_backend_app;
    END IF;
END $$;
