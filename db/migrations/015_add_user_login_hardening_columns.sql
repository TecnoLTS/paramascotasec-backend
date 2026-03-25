ALTER TABLE "User" ADD COLUMN IF NOT EXISTS failed_login_attempts integer;
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS login_locked_until timestamp;
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS last_login_at timestamp;

UPDATE "User"
SET failed_login_attempts = 0
WHERE failed_login_attempts IS NULL;

UPDATE "User"
SET login_locked_until = NULL
WHERE login_locked_until IS NOT NULL
  AND login_locked_until < NOW() - INTERVAL '365 days';
