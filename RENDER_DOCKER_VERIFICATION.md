# Docker/Render implementation and verification

25 October 2026. Deployment preparation is implemented; no deployment, Git push/commit, local/Aiven MySQL connection or database change was performed. Local `.env` contents were not inspected, printed or modified, and local Gmail SMTP settings are unchanged. Tests use synthetic mail settings and do not send real email.

## Files created

| File | Purpose |
| --- | --- |
| `Dockerfile` | Locked frontend and Composer builds; PHP 8.4.27 runtime, Nginx/FPM, health probe, safe startup |
| `.dockerignore` | Allowlisted context; excludes environment files, secrets, local data, backups, installed dependencies and caches |
| `.gitattributes` | LF line endings for container scripts/configuration on Windows checkouts |
| `deploy/docker/nginx.conf.template` | PORT binding, static SPA routing, API front controller, private-file denial, response policies and Render edge normalization |
| `deploy/docker/fastcgi-app.conf` | Fixed Laravel script, explicit FastCGI parameters and cleared untrusted proxy metadata |
| `deploy/docker/php.ini` | Production error handling, memory/upload limits and OPcache |
| `deploy/docker/php-fpm.conf` | Private loopback listener, two on-demand workers, worker recycling and stderr errors; no access log |
| `deploy/docker/entrypoint.sh` | Read-only configuration checks, runtime caches, selective template substitution and daemon config tests; no migrations |
| `deploy/docker/run-services.sh` | Graceful termination and container failure if either daemon exits; tini reaps orphaned children |
| `deploy/docker/check-environment.php` | Fail startup for invalid production origins/cookies/key, missing verified MySQL TLS, external private storage, or HTTPS mail settings; does not connect to services |
| `backend/tests/Feature/DeploymentConfigurationTest.php` | TLS options, unchanged non-TLS local configuration, independent boot health, S3 adapter and private storage regression |
| `scripts/nginx-smoke.mjs` | Optional Windows Nginx/PHP-CGI host test with synthetic configuration and temporary front controller; no database/mail |
| `RENDER_DOCKER_DEPLOYMENT.md` | Exact Render dashboard/environment/CA settings, private storage, manual migration procedure and remaining integration checks |
| `RENDER_DOCKER_VERIFICATION.md` | This file: change manifest, results and verification limits |

## Files modified

| File | Change |
| --- | --- |
| `backend/composer.json` | Added Laravel's standard `league/flysystem-aws-s3-v3` adapter for the existing configurable private resume disk |
| `backend/composer.lock` | Locked five new adapter dependencies; no existing package versions changed |
| `backend/config/database.php` | Explicit certificate/server verification when a MySQL/MariaDB CA is configured; no CA leaves local options unchanged |
| `backend/config/filesystems.php` | Explicit private S3 visibility and no public S3 URL; authenticated downloads remain unchanged |

The dependency installation used `--no-scripts --no-plugins`, so local Composer application hooks/migrations were not invoked. Existing UI files, authentication controllers, password recovery workflow, migrations and deployment example files were not changed. The already-existing Laravel `/up` endpoint supplies the boot health check; `/api/health` remains available for the separate API/database middleware check.

## Completed checks

| Check | Result |
| --- | --- |
| `php artisan test --compact` | **117 tests passed; 1,194 assertions**. PHPUnit forces isolated SQLite in memory; includes login, role/suspension, password reset, replay/expiration/privacy, Brevo notifications, resume and existing feature regressions |
| `php artisan test --compact --filter=DeploymentConfigurationTest` after formatting | **3 tests passed; 13 assertions** |
| `npm run build` | Passed, production Vite assets generated; no frontend source/design changes |
| `npm run lint` / `npm run lint:eslint` | Passed; no remaining lint warnings |
| Existing production-assets browser smoke | **All 14 groups passed** with Edge, temporary SQLite and test-only email capture; no MySQL or real sends |
| Nginx host syntax and FastCGI smoke | Passed with official portable Nginx 1.30.6 and local PHP-CGI; host path/user adaptation only, using the committed templates |
| PHP syntax | **110 files passed**, including application/configuration/migrations/tests and the new container checker |
| `bash -n` for both container scripts | Passed using Git Bash |
| Pint for changed PHP configuration, deployment checker and new tests | Passed |
| `composer validate --strict` | Passed |
| `composer check-platform-reqs --no-dev` | Passed on the installed host; this verifies dependency compatibility, not host security patch status |
| Composer dependency advisory check during adapter installation | No vulnerability advisories reported |
| `git diff --check` | Passed |
| Docker context / build review | Explicit COPY sources and allowlist reviewed; no broad repository COPY, build secrets, `.env`, local storage, generated cache, seed data or database dumps included |

The browser groups cover public/mobile navigation and keyboard focus, search/pagination, login and cookie refresh, saved/application flows, employer/admin workflows, worker-free password reset and new-password login, recommendations, accommodations, resume creation/sharing/revocation, dark/high contrast preferences, and 200% text at 320px. The existing harness reported that native keyboard event dispatch was unavailable for resume reordering; it exercised focused native-button activation as its fallback. This is not a claim of full manual assistive-technology certification.

The Nginx host test exercised seven SPA deep links; eight missing/private paths; real hashed asset caching and security headers; Laravel boot health without any database; JSON API health and unknown-route 404; CORS; secure, HttpOnly, host-only Sanctum session cookies; HTTPS URL reconstruction and client IP normalization ignoring forged `Forwarded`/`X-Forwarded-*`; valid IPv4/IPv6 and malformed client-IP fallback. Request metadata was inspected through a temporary test-only front controller, deleted afterward. It is never copied into the image and no public diagnostic route was added. The portable binary is confined to ignored framework cache storage; no Windows service was installed.

To repeat the optional host test on Windows after obtaining Nginx from its official site:

```powershell
$env:NGINX_PATH = 'C:/PRIVATE/nginx/nginx.exe'
$env:PHP_CGI_PATH = 'C:/xampp/php/php-cgi.exe'
node scripts/nginx-smoke.mjs
```

Its test ports are loopback 18481 and 19001; keep them free. It needs an existing production `dist/` build. All processes are shut down after the test and temporary test files are removed.

## Remaining verification and owner setup

No Docker/Podman engine or Linux PHP-FPM is installed here. **The Linux image was not built or run**, its image tags were not pulled, FPM configuration and process shutdown were not executed, and memory limits were not load tested in a container. The official PHP 8.4 image source was checked for its patched release and bundled mbstring/OPcache support; redundant extension builds and the base image's FPM request access logging are excluded from the final configuration. A supported PHP image does not make the local host's PHP 8.2.12 patched; that host intentionally still fails the existing runtime security baseline.

Before deployment, the owner must build with `docker build --pull -t pwdwork-render .`, run the actual container checks documented in the deployment guide, provision/configure a private bucket, supply Aiven settings/CA, generate and securely retain the production key, verify Brevo sender/API access, and manually approve/run initial migrations against a dedicated empty deployment database. No external bucket, API key, real mail delivery, Aiven TLS handshake/cipher, existing file transfer or Render edge header behavior has been verified here. These require the owner's actual services and a deliberate deployment/testing step.

Render Free recovery uses the existing deferred after-response connection without a worker; an interrupted send has no retry. For durable password recovery, choose the existing database queue and provision an external worker. The existing strict production-readiness command continues to fail deferred delivery's durability check; no security gate was weakened to hide this Free limitation. Exact settings, alternative worker command, storage requirements and safe migration commands are in `RENDER_DOCKER_DEPLOYMENT.md`.
