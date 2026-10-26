-- Read-only. Run only after the migration reports success, with enrollment still OFF.
SELECT VERSION() AS mysql_version, @@hostname AS server_identity, DATABASE() AS selected_database;

SELECT ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
ORDER BY ORDINAL_POSITION;

SELECT COUNT(*) AS intended_migration_records FROM migrations
WHERE migration = '2026_10_26_000001_add_email_ownership_verification_to_users';
SELECT migration, batch FROM migrations ORDER BY id;

SELECT COUNT(*) AS user_count,
       COALESCE(SUM(email_ownership_verified_at IS NOT NULL), 0) AS ownership_claims,
       COALESCE(SUM(email_verification_required_at IS NOT NULL), 0) AS enrolled_accounts,
       COALESCE(SUM(email_verification_version <> 0 OR email_verification_version IS NULL), 0) AS nonzero_or_null_versions
FROM users;
SELECT role, status, COUNT(*) AS account_count FROM users GROUP BY role, status ORDER BY role, status;
SELECT COUNT(*) AS session_count FROM sessions;
SELECT verification_status, COUNT(*) AS employer_count FROM employers GROUP BY verification_status ORDER BY verification_status;

-- All three detail rows must match the reviewed definitions; count alone is insufficient.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
  AND COLUMN_NAME IN ('email_ownership_verified_at', 'email_verification_required_at', 'email_verification_version')
ORDER BY ORDINAL_POSITION;
