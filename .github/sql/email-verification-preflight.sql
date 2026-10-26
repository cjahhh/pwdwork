-- Read-only. Does not assert the target automatically: operator must verify identity first.
-- Use a SELECT-only account, the intended default database, UTC session time and verified TLS in production.
SELECT VERSION() AS mysql_version, @@hostname AS server_identity, DATABASE() AS selected_database,
       @@session.time_zone AS session_time_zone, @@sql_mode AS sql_mode;
SHOW SESSION STATUS LIKE 'Ssl_cipher';
SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE();

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, DATA_LENGTH, INDEX_LENGTH
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
ORDER BY TABLE_NAME;

SELECT ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
ORDER BY ORDINAL_POSITION;

SELECT COUNT(*) AS ownership_columns_present
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
  AND COLUMN_NAME IN ('email_ownership_verified_at', 'email_verification_required_at', 'email_verification_version');

SELECT migration, batch FROM migrations ORDER BY id;
SELECT COUNT(*) AS intended_migration_records FROM migrations
WHERE migration = '2026_10_26_000001_add_email_ownership_verification_to_users';

SELECT role, status, COUNT(*) AS account_count FROM users GROUP BY role, status ORDER BY role, status;
SELECT COUNT(*) AS user_count FROM users;
SELECT COUNT(*) AS session_count FROM sessions;
SELECT verification_status, COUNT(*) AS employer_count FROM employers GROUP BY verification_status ORDER BY verification_status;

-- Counts only: never print current SQL text, bindings or session payloads.
-- Missing performance_schema privileges are a stop/review condition, not grounds to broaden privileges blindly.
SELECT LOCK_TYPE, LOCK_STATUS, COUNT(*) AS users_metadata_locks
FROM performance_schema.metadata_locks
WHERE OBJECT_SCHEMA = DATABASE() AND OBJECT_NAME = 'users'
GROUP BY LOCK_TYPE, LOCK_STATUS ORDER BY LOCK_TYPE, LOCK_STATUS;
