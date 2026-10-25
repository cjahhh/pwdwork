# PWDWork: Docker preparation for Render Free

Prepared 25 October 2026. Nothing has been deployed, pushed, migrated on MySQL, or imported. Local `.env` files and working Gmail settings are unchanged. This guide supersedes the VPS examples in `deploy/*.example` for this container.

## What the image runs

The root Dockerfile builds React with Node 24 and `npm ci`, installs locked production Composer dependencies, then serves the built SPA and Laravel with Nginx and PHP 8.4.27 FPM. PHP's existing security-baseline check runs during the build and at startup. Recheck PHP advisories and update the pinned patch before future releases; rebuild with `--pull` to refresh base OS packages.

Only frontend build inputs, PHP source, migrations, and container configuration enter the build context. Neither `.env`, local storage, dependencies, database files/backups, certificates, nor repository metadata are copied. Composer scripts, key generation, seeding and migrations never run automatically in the build. Runtime configuration is cached only after secrets are injected.

Nginx listens on `0.0.0.0:$PORT` (default 10000). `/api`, `/api/*`, `/sanctum/csrf-cookie` and `/up` execute only Laravel's fixed front controller. React routes fall back to `index.html`; missing hashed assets return 404. Arbitrary PHP files and dotfiles cannot execute or be downloaded. API, login cookies and password reset links use the same HTTPS origin. The compiled API URL is `/api`; do not enter a separate frontend URL or Vite API host.

Render terminates HTTPS at its edge. Its built-in `RENDER=true` enables HTTPS/port normalization in Nginx. Only in that mode, the single `CF-Connecting-IP` supplied by Render's Cloudflare ingress becomes the client IP; a missing/malformed header falls back to the peer IP. Incoming `Forwarded` and `X-Forwarded-*` values are cleared before PHP. Leave Laravel `TRUSTED_PROXIES` empty. Do not expose this Render mode directly to the internet on another host; put it behind an ingress that overwrites that client-IP header. Verify edge header spoof resistance in the staging checks below.

## Render service settings

When you choose to deploy later, create **one Web Service**, with these settings:

| Dashboard field | Value |
| --- | --- |
| Source | Your existing repository and intended branch |
| Language / Runtime | Docker |
| Root Directory | Leave empty (repository root) |
| Dockerfile Path | `./Dockerfile` |
| Docker Build Context Directory | `.` (repository root) |
| Instance Type | Free |
| Region | Prefer the region nearest the Aiven database |
| Docker Command | Leave empty; use the image entrypoint and command |
| Health Check Path | `/up` |
| Auto-Deploy | Off while preparing and validating |
| Pre-Deploy Command | Leave empty; migrations are a separate manual operation |

Do not put credentials in build arguments, Docker Command, repository files, or frontend `VITE_*` values. The Dockerfile has no secret build arguments. Render supplies `PORT` and `RENDER` automatically; keep the default port 10000 rather than a reserved port.

## Exact Render environment variables

Replace the example hostname in all four origin settings with the actual service hostname assigned by Render. Enter secrets privately in the Render dashboard. Values below are runtime settings, not edits to your local `.env`.

| Variable | Value |
| --- | --- |
| `APP_NAME` | `PWDWork` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | A new, securely generated Laravel production application key; keep it stable across deployments |
| `APP_URL` | `https://YOUR-SERVICE.onrender.com` |
| `FRONTEND_URL` | `https://YOUR-SERVICE.onrender.com` |
| `CORS_ALLOWED_ORIGINS` | `https://YOUR-SERVICE.onrender.com` (one origin, no trailing slash) |
| `SANCTUM_STATEFUL_DOMAINS` | `YOUR-SERVICE.onrender.com` (hostname only, no scheme/path) |
| `TRUSTED_PROXIES` | Empty; omit the variable |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_HTTP_ONLY` | `true` |
| `SESSION_SAME_SITE` | `lax` |
| `SESSION_DOMAIN` | `null` (literal Laravel null value; host-only cookies) |
| `SESSION_PATH` | `/` |
| `SESSION_LIFETIME` | `120` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `PASSWORD_RESET_QUEUE_CONNECTION` | `deferred` for one Free service; see delivery limitation below |
| `PASSWORD_RESET_QUEUE` | `password-resets` |
| `ADMIN_NOTIFICATION_MAIL` | `false` (existing in-app notifications still work) |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | Aiven's exact DNS hostname from connection information, without a scheme or port |
| `DB_PORT` | Aiven's actual MySQL port; do not assume 3306 |
| `DB_DATABASE` | Your chosen empty deployment database name |
| `DB_USERNAME` | Your non-root deployment database account |
| `DB_PASSWORD` | That account's password, entered privately |
| `MYSQL_ATTR_SSL_CA` | `/etc/secrets/aiven-ca.pem` |
| `MAIL_MAILER` | `brevo` |
| `PASSWORD_RESET_MAILER` | `brevo` |
| `BREVO_API_KEY` | Your verified Brevo account's transactional API key, entered privately |
| `MAIL_FROM_ADDRESS` | Your Brevo-verified sender email address |
| `MAIL_FROM_NAME` | `PWDWork` |
| `RESUME_DISK` | `s3` |
| `AWS_ACCESS_KEY_ID` | Private object-storage account's scoped access key |
| `AWS_SECRET_ACCESS_KEY` | Its secret key, entered privately |
| `AWS_DEFAULT_REGION` | Your bucket provider's region (for example, `us-east-1` only if appropriate) |
| `AWS_BUCKET` | Your private bucket name |
| `AWS_ENDPOINT` | Your S3-compatible provider's HTTPS endpoint; omit for AWS S3 |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Provider requirement: `true` or `false`; usually `false` for AWS S3 |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `warning` |

Leave `DB_URL`, `DB_SOCKET`, `MAIL_URL`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `AWS_URL`, `SESSION_CONNECTION`, `DB_CACHE_CONNECTION` and `DB_QUEUE_CONNECTION` unset on Render. Do not copy the local `.env` to Render. Gmail SMTP is still usable locally; Render Free blocks SMTP ports 25, 465 and 587, so recovery explicitly uses the existing Brevo HTTPS transport. No provider is selected automatically by application code.

Generate a separate production key on a trusted machine with a supported PHP version, without modifying any `.env` file:

```sh
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Paste the output only into your private production secret store. Never post the key, include it in screenshots/logs, or generate a new key on every start. Existing encrypted data and sessions depend on this key.

## Aiven TLS and secret file

Download the CA certificate from your Aiven service's connection information. In Render **Secret Files**, create `aiven-ca.pem` with that PEM content. Runtime path: `/etc/secrets/aiven-ca.pem`. Never commit the certificate bundle or bake it into the image. Keep the public CA current when Aiven rotates it.

PDO now sets `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=true` whenever a CA is configured, for both MySQL and MariaDB configurations. Container startup rejects a missing/unreadable/non-PEM CA or absent verification setting. Keep the exact Aiven hostname so TLS hostname verification succeeds; never work around a failure by disabling verification or using an IP address. Local MySQL behavior is unchanged when no CA is set.

### Temporary database failure diagnostics

Production `QueryException` reports now contain a fixed message, `Database failure diagnostics.`, and only three diagnostic fields: `sqlstate`, `mysql_driver_code`, and `category`. Missing or malformed codes become JSON null. The reporter never reads exception messages, SQL, bindings, connection details or certificate contents, and stops Laravel's default exception logging. Existing API responses and debug settings are unchanged; keep `APP_DEBUG=false`. Other exceptions retain the existing sanitized report. This is temporary: remove the QueryException reporting branch and its `DatabaseFailureDiagnostics` helper after diagnosis. Keep log access restricted as usual.

| Driver code | Category / next area to inspect privately |
| --- | --- |
| 2002 / 2003 | Connection failure; check endpoint, port, approved Render network path and TLS setup |
| 2005 | DNS resolution failure |
| 2006 / 2013 | Lost connection |
| 2026 | TLS failure; check CA file access, validity and hostname |
| 1045 | Authentication failure |
| 1044 / 1142 / 1143 | Database or statement permission denied |
| 1049 | Database not found |
| 1146 / 1054 | Table or column not found on the selected target |
| 1040 / 1203 | Connection limit |

Categories describe the reported code, not a confirmed root cause. A general `HY000` or connection error cannot prove a particular network or certificate fault. Codes follow the [MySQL client](https://dev.mysql.com/doc/mysql-errors/8.4/en/client-error-reference.html) and [server error references](https://dev.mysql.com/doc/mysql-errors/8.4/en/server-error-reference.html). The diagnostics intentionally do not parse error messages to distinguish ambiguous errors.

A successful GitHub Actions migration proves that runner's configuration and network path worked at that time. It does not establish Render's current target, credentials, privileges, network access or runtime certificate access. Both public jobs and authenticated user requests can touch shared database-backed rate limits, sessions and application tables. No specific cause is established by `QueryException` alone.

For Render Secret Files, set `MYSQL_ATTR_SSL_CA` to the absolute runtime **file path**, such as `/etc/secrets/aiven-ca.pem`, not the PEM contents or the GitHub runner's `/run/aiven-ca.pem` path. See [Render Secret Files](https://render.com/docs/configure-environment-variables). The runtime image now adds `www-data` to supplementary GID 1000, as recommended by [Render's Docker documentation](https://render.com/docs/docker-secrets#accessing-secret-files-at-runtime), while preserving its primary group. An existing group with that numeric GID is reused. No certificate or Secret File permissions are changed.

Startup still checks CA validity and verified TLS configuration as root, then uses `runuser -u www-data` to run the standalone `check-environment.php --ca-readable` mode. This initializes the application user's supplementary groups and attempts to open the configured CA for reading. It closes the handle without reading or printing contents, and never boots Laravel or connects to a database. Failure stops startup with a fixed message; success confirms application-user readability. It does not test the Aiven network connection or prove that a certificate handshake will succeed. Keep `APP_DEBUG=false` and certificate/hostname verification enabled.

The previous Dockerfile did not explicitly grant group 1000 membership. That is a configuration gap matching Render's guidance, but the actual live file permissions and built-image membership have not been inspected. Driver code 2002 alone does not establish that this caused the incident. Confirmation requires a separately approved deployment and successful worker-readability check plus API verification; no deployment is performed by this change.

The manual Docker Build Check workflow now includes a network-disabled permissions smoke test. It checks image membership and creates only synthetic root-owned fixtures inside the disposable container: a GID-1000 file with mode 0640 under a 0750 directory must be readable as `www-data`; a root-only file, inaccessible parent, missing file and empty path must fail with only the fixed safe diagnostic. No real certificate, credentials, database or application bootstrap is involved. Run that workflow manually after separately approving the source changes; Docker need not be installed locally. This smoke test was added but cannot be executed in a local environment without Docker.

The entrypoint rebuilds Laravel's config cache after runtime environment injection, and FPM preserves the environment (`clear_env=no`). `DB_URL` must remain unset, as it can override individual database settings; the startup validator already rejects it. Privately compare Render's host, port and database with the approved Aiven deployment record. Do not dump environment variables or cached configuration into logs. Do not rerun migrations or weaken Aiven network restrictions to troubleshoot this incident.

After a separately approved deployment of this diagnostic change, reproduce one failing request and inspect only the three fields above in restricted Render logs. The existing `stderr` logger must permit error-level records (the Docker default is `warning`); a stricter level can filter them out. Use the observed code to guide the next fix, then remove the temporary diagnostics. These instructions do not authorize a deployment or database operation.

Allow the service's documented Render outbound ranges in Aiven's network rules. Separately allow only the trusted operator's IP while performing manual setup, then remove that temporary allowance. Use a migration account with the required schema privileges; after setup, prefer a scoped runtime account. The image does not connect to Aiven during a build or import local data.

## Temporary disk, uploads, memory, and mail delivery

Database sessions, rate-limit cache, users, jobs, password tokens, saved resumes and resume snapshots live in Aiven. PHP temporary PDF files, compiled views, and caches can be recreated after every restart. Logs go to stderr; request access logs are disabled and Nginx errors below critical are suppressed because those messages can contain reset URLs. Existing application exception sanitization remains in effect.

Render Free does not provide persistent disks. The existing authenticated legacy resume download path already supports a configurable disk. Its standard S3 adapter is now installed, with private visibility and no public storage URL. Supply a private S3-compatible bucket and narrowly scoped credentials to preserve files across restarts. Public bucket access must remain blocked; allow only the object's required read/write/list operations, and deletion if used by your storage lifecycle. No bucket or real credentials have been configured by this task. Existing local files are not uploaded automatically. Database-backed Resume Builder records and PDFs continue to work independently of legacy uploaded files. Startup requires external storage rather than silently accepting loss of private files.

The container uses one Nginx worker, at most two on-demand FPM children, a 128 MB per-request PHP memory limit and 64 MB OPcache. It runs no queue daemon, scheduler, Node development server or MySQL server. Monitor peak memory when testing long resume PDFs and concurrent requests; these settings are a conservative Free starting point, not a load-test guarantee. Server upload ceilings are 5 MB per file / 8 MB request and do not relax application validation.

`PASSWORD_RESET_QUEUE_CONNECTION=deferred` uses the existing Laravel response-first delivery: PHP-FPM completes the response and then invokes Brevo over HTTPS. It requires no queue worker and keeps the request confirmation generic. **A process interruption can lose that send, and it has no durable retry.** The user can request a new link after the throttle interval. Tokens, expiration, replay protection, account suspension and login rules are unchanged.

For reliable delivery under restarts, run a separately provisioned, always-on worker using the same image, database and secrets, select `PASSWORD_RESET_QUEUE_CONNECTION=database`, and run `php artisan queue:work database --queue=password-resets,default --tries=3 --timeout=60 --memory=128`. If enabling administrator email, also consume the configured `notifications` queue. Render Free does not offer a free background worker and sleeps after idle time; a worker inside the web container would not solve durability. The existing `pwdwork:production-check` intentionally continues to report deferred recovery as a failed durability requirement. This Free setup is for preview/local testing; use a durable worker and suitable hosting before treating it as production service availability.

## Safely prepare the initial schema (manual, later)

Render Free has no dashboard/SSH shell or one-off jobs. Do not rely on a Free pre-deploy migration command. Perform these steps from a trusted Docker-enabled workstation, using the same built image and a separate, approved **empty deployment database**. Do not point these commands at local XAMPP data or an existing populated Aiven database. If the chosen database is not empty, stop and plan a reviewed migration with a verified backup.

1. Review `backend/database/migrations/`. Choose a dedicated empty deployment database and confirm the host/name/account privately before any write. Configure Aiven network access and the CA as above. Take/verify a backup before applying migrations to any existing deployment in the future.
2. Build without local data: `docker build --pull -t pwdwork-render .`.
3. Prepare an operator-only environment file **outside the repository/build context**, using the settings above, plus a CA file outside the repository. Protect both files. Do not put secret values on the command line, in Git, or in shell history.
4. Run the following commands individually after replacing the two absolute paths. They bypass normal web startup and do not regenerate keys. The environment check validates settings without a database connection. `migrate:status` is a read-only status check; on a brand-new database it can report that no migrations table exists.

```sh
docker run --rm --env-file /PRIVATE/render.env --mount type=bind,source=/PRIVATE/aiven-ca.pem,target=/etc/secrets/aiven-ca.pem,readonly --entrypoint php pwdwork-render /opt/pwdwork/check-environment.php
docker run --rm --env-file /PRIVATE/render.env --mount type=bind,source=/PRIVATE/aiven-ca.pem,target=/etc/secrets/aiven-ca.pem,readonly --entrypoint php pwdwork-render artisan migrate:status
docker run --rm --env-file /PRIVATE/render.env --mount type=bind,source=/PRIVATE/aiven-ca.pem,target=/etc/secrets/aiven-ca.pem,readonly --entrypoint php pwdwork-render artisan migrate --pretend --force
```

On an empty database, Laravel's pretend command can initialize the migration bookkeeping table. Use it only after approving that empty target. Review the proposed schema SQL. If correct, the operator can deliberately run:

```sh
docker run --rm --env-file /PRIVATE/render.env --mount type=bind,source=/PRIVATE/aiven-ca.pem,target=/etc/secrets/aiven-ca.pem,readonly --entrypoint php pwdwork-render artisan migrate --force
```

Run `migrate:status` again. Do not run `migrate:fresh`, `migrate:refresh`, `db:wipe`, demo seeders, or import a local database dump. Deployment startup never performs these operations. Do not add a public HTTP migration/admin setup endpoint. Review the existing `pwdwork:provision-admin --help` command separately if you need an initial administrator; there is no automatically created demo account.

For a custom domain later, change APP_URL, FRONTEND_URL, CORS_ALLOWED_ORIGINS and SANCTUM_STATEFUL_DOMAINS together, verify HTTPS and redeploy. Users will need to log in again when the cookie host changes. Keep a single canonical origin.

## Verification before deployment

Automated checks performed for this change and their results are recorded in `RENDER_DOCKER_VERIFICATION.md`. This Windows workspace has no Docker engine or Linux PHP-FPM, so a real image build and container smoke test still need a Docker-enabled machine. A separate portable Windows Nginx/PHP-CGI test passed the server routing, cookie and proxy-metadata cases. Neither that host test nor the existing browser harness (Vite preview plus isolated Laravel/SQLite) is a Linux container test.

After building successfully, use the private environment/CA mount above to start the image on a trusted machine. For a Render-mode ingress simulation, bind only to loopback and set `RENDER=true`; it deliberately trusts Cloudflare headers and must not be publicly exposed in that form:

```sh
docker run --rm --name pwdwork-preview -p 127.0.0.1:10000:10000 --env-file /PRIVATE/render.env --env RENDER=true --mount type=bind,source=/PRIVATE/aiven-ca.pem,target=/etc/secrets/aiven-ca.pem,readonly pwdwork-render
```

Use a local HTTPS reverse proxy with the configured hostname for browser login testing. Test data must go to an explicitly approved disposable database/bucket; automated tests here use neither real Aiven nor MySQL records. Check:

- `/up`: 200, verifies Nginx, FPM and Laravel boot, without querying the database. Separately check `/api/health` and `/api/jobs` after migrations; the API middleware uses database-backed throttling and therefore needs the schema.
- `/jobs`, `/forgot-password`, `/reset-password` and nested applicant/employer/admin routes: SPA HTML loads on direct visits and refreshes. Missing `/assets/anything.js`, `/.env`, `/backend/.env`, `/vendor/autoload.php` and `/anything.php`: 404. An unknown `/api/...` returns Laravel JSON 404, not SPA HTML.
- Same-origin registration/login/logout, reload restoring the cookie session, CSRF rejection without a token, protected-role access, suspension rules, job browsing, saved/recommended jobs and resumes/PDFs.
- Real Brevo delivery to a test account; reset link uses this HTTPS hostname; valid reset, expired/reused link rejection, old password rejected, new password accepted. No URL/token/body or credential appears in logs. Test unknown-email requests for the same generic response. Provider/sender/domain verification must be completed by the owner first.
- Private object download after a container restart; anonymous/other-user requests remain forbidden. Database-backed sessions/cache survive that restart. Verify Aiven's session reports a nonempty `Ssl_cipher`; verify wrong/untrusted CA and mismatched hostname fail against a disposable TLS database, without disabling verification.
- At Render's edge, confirm Nginx receives Cloudflare's actual single client IP and that externally supplied `CF-Connecting-IP`, `Forwarded` and `X-Forwarded-For` values cannot change the effective client identity/rate-limit bucket. This is required when changing providers or adding another ingress. There is no public diagnostic endpoint exposing client headers.
- Docker stop terminates both daemons; killing either daemon stops the container. Exercise two concurrent PDF/reset requests while monitoring the 512 MB budget. Review response security policies in the browser console without changing the UI.

## Primary references

- [Render Docker settings](https://render.com/docs/docker), [port binding](https://render.com/docs/web-services#port-binding), [Free limitations](https://render.com/docs/free), [health checks](https://render.com/docs/health-checks).
- [Render's Cloudflare ingress](https://render.com/articles/how-render-handles-ddos-attacks), [Cloudflare origin request headers](https://developers.cloudflare.com/fundamentals/reference/http-request-headers/).
- [Aiven MySQL PHP/TLS connection](https://aiven.io/docs/products/mysql/howto/connect-with-php), [Aiven TLS certificates](https://aiven.io/docs/platform/concepts/tls-ssl-certificates).
- [PHP security releases](https://www.php.net/archive/2026.php), [supported PHP branches](https://www.php.net/supported-versions.php), [Laravel S3 filesystem](https://laravel.com/docs/12.x/filesystem#s3-driver-configuration).
