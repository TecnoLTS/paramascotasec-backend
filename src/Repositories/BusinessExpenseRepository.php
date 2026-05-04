<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use DateTimeImmutable;
use PDO;

class BusinessExpenseRepository {
    private $db;
    private static $schemaEnsured = false;
    private array $statuses = ['pending', 'paid', 'overdue', 'cancelled'];
    private array $types = ['one_time', 'recurring_instance'];
    private array $frequencies = ['weekly', 'monthly'];

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureSchema();
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function newId(string $prefix): string {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }

    private function ensureSchema(): void {
        if (self::$schemaEnsured) return;

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS "BusinessExpenseRecurrence" (
                id varchar(64) PRIMARY KEY,
                tenant_id varchar(120) NOT NULL,
                category varchar(120) NOT NULL,
                description text NOT NULL,
                amount numeric(12,2) NOT NULL DEFAULT 0,
                tax_amount numeric(12,2) NOT NULL DEFAULT 0,
                total numeric(12,2) NOT NULL DEFAULT 0,
                frequency varchar(20) NOT NULL DEFAULT \'monthly\',
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
            )
        ');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_business_expense_recurrence_tenant_next ON "BusinessExpenseRecurrence"(tenant_id, active, next_due_date)');

        $this->db->exec('
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
                status varchar(20) NOT NULL DEFAULT \'pending\',
                type varchar(30) NOT NULL DEFAULT \'one_time\',
                payment_method varchar(60) NULL,
                reference varchar(160) NULL,
                notes text NULL,
                source varchar(40) NULL,
                source_id varchar(80) NULL,
                created_by_user_id varchar(64) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT NOW(),
                updated_at timestamptz NOT NULL DEFAULT NOW()
            )
        ');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_business_expense_tenant_status_date ON "BusinessExpense"(tenant_id, status, expense_date DESC)');
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_business_expense_recurrence_due_unique ON "BusinessExpense"(tenant_id, recurrence_id, due_date) WHERE recurrence_id IS NOT NULL');

        $this->db->exec('
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
            )
        ');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_business_expense_payment_expense ON "BusinessExpensePayment"(tenant_id, expense_id, paid_at DESC)');

        self::$schemaEnsured = true;
    }

    private function money($value): float {
        return round(max(0, (float)$value), 2);
    }

    private function cleanDate($value, ?string $fallback = null): string {
        $raw = trim((string)($value ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
        return $fallback ?: date('Y-m-d');
    }

    private function normalizeStatus(string $status): string {
        $value = strtolower(trim($status));
        return in_array($value, $this->statuses, true) ? $value : 'pending';
    }

    private function normalizeExpenseRow(array $row): array {
        foreach (['amount', 'tax_amount', 'total'] as $field) {
            $row[$field] = isset($row[$field]) ? (float)$row[$field] : 0.0;
        }
        $row['payment_exists'] = (bool)($row['payment_exists'] ?? $row['pdf_exists'] ?? false);
        return $row;
    }

    private function normalizeRecurrenceRow(array $row): array {
        foreach (['amount', 'tax_amount', 'total'] as $field) {
            $row[$field] = isset($row[$field]) ? (float)$row[$field] : 0.0;
        }
        $row['interval_count'] = (int)($row['interval_count'] ?? 1);
        $row['active'] = (bool)($row['active'] ?? true);
        return $row;
    }

    private function refreshOverdue(): void {
        $stmt = $this->db->prepare('UPDATE "BusinessExpense" SET status = \'overdue\', updated_at = NOW() WHERE tenant_id = :tenant_id AND status = \'pending\' AND due_date IS NOT NULL AND due_date < CURRENT_DATE');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
    }

    public function list(array $filters = []): array {
        $this->generateDueRecurringInstances();
        $this->refreshOverdue();

        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->getTenantId()];
        foreach (['status', 'category', 'type'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '' && strtolower($value) !== 'all') {
                $where[] = $field . ' = :' . $field;
                $params[$field] = $value;
            }
        }
        if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['from'])) {
            $where[] = 'expense_date >= :from_date';
            $params['from_date'] = $filters['from'];
        }
        if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['to'])) {
            $where[] = 'expense_date <= :to_date';
            $params['to_date'] = $filters['to'];
        }

        $stmt = $this->db->prepare('SELECT *, EXISTS(SELECT 1 FROM "BusinessExpensePayment" p WHERE p.expense_id = "BusinessExpense".id AND p.tenant_id = "BusinessExpense".tenant_id) AS payment_exists FROM "BusinessExpense" WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(due_date, expense_date) DESC, created_at DESC LIMIT 300');
        $stmt->execute($params);
        return array_map(fn($row) => $this->normalizeExpenseRow($row), $stmt->fetchAll() ?: []);
    }

    public function categories(): array {
        $stmt = $this->db->prepare('
            SELECT DISTINCT category
            FROM (
                SELECT category FROM "BusinessExpense" WHERE tenant_id = :tenant_id
                UNION ALL
                SELECT category FROM "BusinessExpenseRecurrence" WHERE tenant_id = :tenant_id
            ) source
            WHERE category IS NOT NULL AND TRIM(category) <> \'\'
            ORDER BY category ASC
            LIMIT 100
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return array_values(array_filter(array_map(static fn($row) => (string)($row['category'] ?? ''), $stmt->fetchAll() ?: [])));
    }

    public function create(array $data, string $userId): array {
        $amount = $this->money($data['amount'] ?? 0);
        $tax = $this->money($data['tax_amount'] ?? 0);
        $total = $this->money($data['total'] ?? ($amount + $tax));
        if ($total <= 0) throw new \Exception('El gasto debe ser mayor a cero.');

        $status = $this->normalizeStatus((string)($data['status'] ?? 'pending'));
        $paidAt = $status === 'paid' ? ($data['paid_at'] ?? date(DATE_ATOM)) : null;
        $expenseDate = $this->cleanDate($data['expense_date'] ?? null);
        $dueDate = !empty($data['due_date']) ? $this->cleanDate($data['due_date']) : null;

        $stmt = $this->db->prepare('
            INSERT INTO "BusinessExpense" (id, tenant_id, recurrence_id, category, description, amount, tax_amount, total, expense_date, due_date, paid_at, status, type, payment_method, reference, notes, source, source_id, created_by_user_id, created_at, updated_at)
            VALUES (:id, :tenant_id, :recurrence_id, :category, :description, :amount, :tax_amount, :total, :expense_date, :due_date, :paid_at, :status, :type, :payment_method, :reference, :notes, :source, :source_id, :created_by_user_id, NOW(), NOW())
            RETURNING *
        ');
        $stmt->execute([
            'id' => $this->newId('exp'),
            'tenant_id' => $this->getTenantId(),
            'recurrence_id' => $data['recurrence_id'] ?? null,
            'category' => trim((string)($data['category'] ?? 'Otros')) ?: 'Otros',
            'description' => trim((string)($data['description'] ?? 'Gasto operativo')),
            'amount' => $amount,
            'tax_amount' => $tax,
            'total' => $total,
            'expense_date' => $expenseDate,
            'due_date' => $dueDate,
            'paid_at' => $paidAt,
            'status' => $status,
            'type' => in_array(($data['type'] ?? 'one_time'), $this->types, true) ? $data['type'] : 'one_time',
            'payment_method' => trim((string)($data['payment_method'] ?? '')) ?: null,
            'reference' => trim((string)($data['reference'] ?? '')) ?: null,
            'notes' => trim((string)($data['notes'] ?? '')) ?: null,
            'source' => trim((string)($data['source'] ?? '')) ?: null,
            'source_id' => trim((string)($data['source_id'] ?? '')) ?: null,
            'created_by_user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        if ($row && $status === 'paid') {
            $this->recordPayment((string)$row['id'], (float)$row['total'], $row['payment_method'] ?? null, $row['reference'] ?? null, $userId);
        }
        return $row ? $this->normalizeExpenseRow($row) : [];
    }

    public function update(string $id, array $data): array {
        $existing = $this->find($id);
        if (!$existing) throw new \Exception('Gasto no encontrado.');

        $amount = $this->money($data['amount'] ?? $existing['amount']);
        $tax = $this->money($data['tax_amount'] ?? $existing['tax_amount']);
        $total = $this->money($data['total'] ?? ($amount + $tax));
        $status = $this->normalizeStatus((string)($data['status'] ?? $existing['status']));

        $stmt = $this->db->prepare('
            UPDATE "BusinessExpense" SET category = :category, description = :description, amount = :amount, tax_amount = :tax_amount, total = :total, expense_date = :expense_date, due_date = :due_date, paid_at = :paid_at, status = :status, payment_method = :payment_method, reference = :reference, notes = :notes, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
            RETURNING *
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'category' => trim((string)($data['category'] ?? $existing['category'])) ?: 'Otros',
            'description' => trim((string)($data['description'] ?? $existing['description'])) ?: 'Gasto operativo',
            'amount' => $amount,
            'tax_amount' => $tax,
            'total' => $total,
            'expense_date' => $this->cleanDate($data['expense_date'] ?? $existing['expense_date']),
            'due_date' => !empty($data['due_date']) ? $this->cleanDate($data['due_date']) : null,
            'paid_at' => $status === 'paid' ? ($data['paid_at'] ?? $existing['paid_at'] ?? date(DATE_ATOM)) : null,
            'status' => $status,
            'payment_method' => trim((string)($data['payment_method'] ?? '')) ?: null,
            'reference' => trim((string)($data['reference'] ?? '')) ?: null,
            'notes' => trim((string)($data['notes'] ?? '')) ?: null,
        ]);
        return $this->normalizeExpenseRow($stmt->fetch() ?: []);
    }

    public function updateStatus(string $id, string $status, array $data = [], ?string $userId = null): array {
        $status = $this->normalizeStatus($status);
        $paidAt = $status === 'paid' ? date(DATE_ATOM) : null;
        $stmt = $this->db->prepare('UPDATE "BusinessExpense" SET status = :status, paid_at = :paid_at, payment_method = COALESCE(:payment_method, payment_method), reference = COALESCE(:reference, reference), updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id RETURNING *');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'status' => $status,
            'paid_at' => $paidAt,
            'payment_method' => trim((string)($data['payment_method'] ?? '')) ?: null,
            'reference' => trim((string)($data['reference'] ?? '')) ?: null,
        ]);
        $row = $stmt->fetch();
        if (!$row) throw new \Exception('Gasto no encontrado.');

        if ($status === 'paid' && $userId) {
            $this->recordPayment((string)$row['id'], (float)$row['total'], $row['payment_method'] ?? null, $row['reference'] ?? null, $userId);
        }

        return $this->normalizeExpenseRow($row);
    }

    private function recordPayment(string $expenseId, float $amount, ?string $method, ?string $reference, string $userId): void {
        $stmt = $this->db->prepare('INSERT INTO "BusinessExpensePayment" (tenant_id, expense_id, amount, payment_method, reference, created_by_user_id) VALUES (:tenant_id, :expense_id, :amount, :payment_method, :reference, :created_by_user_id)');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'expense_id' => $expenseId,
            'amount' => round($amount, 2),
            'payment_method' => $method,
            'reference' => $reference,
            'created_by_user_id' => $userId,
        ]);
    }

    private function find(string $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM "BusinessExpense" WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute(['id' => $id, 'tenant_id' => $this->getTenantId()]);
        $row = $stmt->fetch();
        return $row ? $this->normalizeExpenseRow($row) : null;
    }

    public function summary(): array {
        $this->generateDueRecurringInstances();
        $this->refreshOverdue();
        $stmt = $this->db->prepare('
            SELECT
                SUM(total) FILTER (WHERE status = \'paid\') AS paid,
                SUM(total) FILTER (WHERE status = \'pending\') AS pending,
                SUM(total) FILTER (WHERE status = \'overdue\') AS overdue,
                SUM(total) FILTER (WHERE status IN (\'pending\', \'overdue\')) AS committed,
                COUNT(*) FILTER (WHERE status = \'paid\') AS paid_count,
                COUNT(*) FILTER (WHERE status = \'pending\') AS pending_count,
                COUNT(*) FILTER (WHERE status = \'overdue\') AS overdue_count
            FROM "BusinessExpense"
            WHERE tenant_id = :tenant_id AND status <> \'cancelled\'
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $row = $stmt->fetch() ?: [];
        $paid = round((float)($row['paid'] ?? 0), 2);
        $pending = round((float)($row['pending'] ?? 0), 2);
        $overdue = round((float)($row['overdue'] ?? 0), 2);
        $committed = round((float)($row['committed'] ?? 0), 2);
        return [
            'paid' => $paid,
            'pending' => $pending,
            'overdue' => $overdue,
            'committed' => $committed,
            'cash_expenses' => $paid,
            'committed_expenses' => round($paid + $committed, 2),
            'paid_count' => (int)($row['paid_count'] ?? 0),
            'pending_count' => (int)($row['pending_count'] ?? 0),
            'overdue_count' => (int)($row['overdue_count'] ?? 0),
        ];
    }

    public function listRecurrences(): array {
        $this->generateDueRecurringInstances();
        $stmt = $this->db->prepare('SELECT * FROM "BusinessExpenseRecurrence" WHERE tenant_id = :tenant_id ORDER BY active DESC, next_due_date ASC, created_at DESC LIMIT 200');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return array_map(fn($row) => $this->normalizeRecurrenceRow($row), $stmt->fetchAll() ?: []);
    }

    public function createRecurrence(array $data, string $userId): array {
        $amount = $this->money($data['amount'] ?? 0);
        $tax = $this->money($data['tax_amount'] ?? 0);
        $total = $this->money($data['total'] ?? ($amount + $tax));
        if ($total <= 0) throw new \Exception('El gasto recurrente debe ser mayor a cero.');

        $frequency = strtolower(trim((string)($data['frequency'] ?? 'monthly')));
        if (!in_array($frequency, $this->frequencies, true)) $frequency = 'monthly';
        $startDate = $this->cleanDate($data['start_date'] ?? null);
        $nextDue = $this->cleanDate($data['next_due_date'] ?? $startDate, $startDate);

        $stmt = $this->db->prepare('
            INSERT INTO "BusinessExpenseRecurrence" (id, tenant_id, category, description, amount, tax_amount, total, frequency, interval_count, start_date, next_due_date, payment_method, reference, notes, active, created_by_user_id, created_at, updated_at)
            VALUES (:id, :tenant_id, :category, :description, :amount, :tax_amount, :total, :frequency, :interval_count, :start_date, :next_due_date, :payment_method, :reference, :notes, TRUE, :created_by_user_id, NOW(), NOW())
            RETURNING *
        ');
        $stmt->execute([
            'id' => $this->newId('expr'),
            'tenant_id' => $this->getTenantId(),
            'category' => trim((string)($data['category'] ?? 'Otros')) ?: 'Otros',
            'description' => trim((string)($data['description'] ?? 'Gasto recurrente')),
            'amount' => $amount,
            'tax_amount' => $tax,
            'total' => $total,
            'frequency' => $frequency,
            'interval_count' => max(1, (int)($data['interval_count'] ?? 1)),
            'start_date' => $startDate,
            'next_due_date' => $nextDue,
            'payment_method' => trim((string)($data['payment_method'] ?? '')) ?: null,
            'reference' => trim((string)($data['reference'] ?? '')) ?: null,
            'notes' => trim((string)($data['notes'] ?? '')) ?: null,
            'created_by_user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        $this->generateDueRecurringInstances();
        return $this->normalizeRecurrenceRow($row ?: []);
    }

    public function updateRecurrence(string $id, array $data): array {
        $stmt = $this->db->prepare('UPDATE "BusinessExpenseRecurrence" SET category = COALESCE(:category, category), description = COALESCE(:description, description), amount = COALESCE(:amount, amount), tax_amount = COALESCE(:tax_amount, tax_amount), total = COALESCE(:total, total), frequency = COALESCE(:frequency, frequency), interval_count = COALESCE(:interval_count, interval_count), next_due_date = COALESCE(:next_due_date, next_due_date), active = COALESCE(:active, active), payment_method = COALESCE(:payment_method, payment_method), reference = COALESCE(:reference, reference), notes = COALESCE(:notes, notes), updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id RETURNING *');
        $frequency = isset($data['frequency']) && in_array($data['frequency'], $this->frequencies, true) ? $data['frequency'] : null;
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'category' => isset($data['category']) ? trim((string)$data['category']) : null,
            'description' => isset($data['description']) ? trim((string)$data['description']) : null,
            'amount' => isset($data['amount']) ? $this->money($data['amount']) : null,
            'tax_amount' => isset($data['tax_amount']) ? $this->money($data['tax_amount']) : null,
            'total' => isset($data['total']) ? $this->money($data['total']) : null,
            'frequency' => $frequency,
            'interval_count' => isset($data['interval_count']) ? max(1, (int)$data['interval_count']) : null,
            'next_due_date' => isset($data['next_due_date']) ? $this->cleanDate($data['next_due_date']) : null,
            'active' => array_key_exists('active', $data) ? (bool)$data['active'] : null,
            'payment_method' => isset($data['payment_method']) ? trim((string)$data['payment_method']) : null,
            'reference' => isset($data['reference']) ? trim((string)$data['reference']) : null,
            'notes' => isset($data['notes']) ? trim((string)$data['notes']) : null,
        ]);
        $row = $stmt->fetch();
        if (!$row) throw new \Exception('Recurrencia no encontrada.');
        return $this->normalizeRecurrenceRow($row);
    }

    public function generateDueRecurringInstances(): void {
        $stmt = $this->db->prepare('SELECT * FROM "BusinessExpenseRecurrence" WHERE tenant_id = :tenant_id AND active = TRUE AND next_due_date <= (CURRENT_DATE + INTERVAL \'45 days\') ORDER BY next_due_date ASC LIMIT 100');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as $row) {
            $nextDue = (string)$row['next_due_date'];
            $iterations = 0;
            while ($nextDue <= (new DateTimeImmutable('+45 days'))->format('Y-m-d') && $iterations < 24) {
                $this->createRecurringInstance($row, $nextDue);
                $nextDue = $this->nextDueDate($nextDue, (string)$row['frequency'], (int)$row['interval_count']);
                $iterations++;
            }
            $update = $this->db->prepare('UPDATE "BusinessExpenseRecurrence" SET next_due_date = :next_due_date, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
            $update->execute(['next_due_date' => $nextDue, 'id' => $row['id'], 'tenant_id' => $this->getTenantId()]);
        }
    }

    private function createRecurringInstance(array $recurrence, string $dueDate): void {
        $stmt = $this->db->prepare('
            INSERT INTO "BusinessExpense" (id, tenant_id, recurrence_id, category, description, amount, tax_amount, total, expense_date, due_date, status, type, payment_method, reference, notes, created_by_user_id, created_at, updated_at)
            VALUES (:id, :tenant_id, :recurrence_id, :category, :description, :amount, :tax_amount, :total, :expense_date, :due_date, \'pending\', \'recurring_instance\', :payment_method, :reference, :notes, :created_by_user_id, NOW(), NOW())
            ON CONFLICT DO NOTHING
        ');
        $stmt->execute([
            'id' => $this->newId('exp'),
            'tenant_id' => $this->getTenantId(),
            'recurrence_id' => $recurrence['id'],
            'category' => $recurrence['category'],
            'description' => $recurrence['description'],
            'amount' => $recurrence['amount'],
            'tax_amount' => $recurrence['tax_amount'],
            'total' => $recurrence['total'],
            'expense_date' => $dueDate,
            'due_date' => $dueDate,
            'payment_method' => $recurrence['payment_method'] ?? null,
            'reference' => $recurrence['reference'] ?? null,
            'notes' => $recurrence['notes'] ?? null,
            'created_by_user_id' => $recurrence['created_by_user_id'],
        ]);
    }

    private function nextDueDate(string $date, string $frequency, int $interval): string {
        $dt = new DateTimeImmutable($date);
        $spec = $frequency === 'weekly' ? '+' . $interval . ' week' : '+' . $interval . ' month';
        return $dt->modify($spec)->format('Y-m-d');
    }
}
