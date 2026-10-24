# PWDWork Laravel API

Read [the root README](../README.md) for Composer installation, MySQL setup, additive migrations, local commands and isolated tests. Read [Phase 1.5 setup](../PHASE1-SETUP.md) for API contracts, privacy and cloud requirements.

The React/Vite frontend lives in the repository root. The skeleton backend/package.json is not required to build or serve that frontend. Use the documented separate API, root Vite, and queue-worker terminals instead of the skeleton Composer development launcher.

Use migrations as the schema authority. database/sql/pwdwork.sql is a retired, comments-only placeholder. Avoid demo seeders on real records. backend/.env.testing contains a public test-only key and isolated SQLite settings; deployment uses provider-managed secrets and MySQL.
