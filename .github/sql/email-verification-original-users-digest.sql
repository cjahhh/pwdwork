-- Read-only projection of EVERY original users column in this repository's pre-feature schema.
-- Pipe batch/no-column-name output directly to sha256sum; do not print per-row results.
-- Compare information_schema with this list first; stop on unreviewed schema drift.
-- Always use the same UTC session, character set, client and fixed data snapshot.
SELECT SHA2(CAST(JSON_ARRAY(
    id, name, email, email_verified_at, password, role, status,
    remember_token, created_at, updated_at, admin_version
) AS CHAR CHARACTER SET utf8mb4), 256)
FROM users
ORDER BY id;
