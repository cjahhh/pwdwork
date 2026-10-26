# Login security implementation report

Date: 26 October 2026

Follow-up: [focused trusted proxy verification](TRUSTED-PROXY-VERIFICATION.md) confirms the local behavior and documents the remaining Render ingress release gate. Production approval remains conditional on that verification.

## Files changed

| File | Change |
| --- | --- |
| `backend/app/Providers/AppServiceProvider.php` | Register an account limiter allowing 10 login requests per minute per normalized, SHA-256-hashed email. |
| `backend/routes/api.php` | Add `throttle:login-account` alongside the unchanged `throttle:10,1` login middleware. |
| `backend/app/Http/Controllers/AuthController.php` | Perform configured password-hashing work for unknown emails before returning the existing generic credential error. |
| `backend/tests/Feature/LoginRateLimitTest.php` | Add rate-limit, normalization, expiry, malformed-input and proxy security tests. |
| `backend/tests/Feature/LoginSecurityTest.php` | Add authentication, account privacy, suspension, preservation and shared database-cache tests. |
| `.github/LOGIN-SECURITY-REPORT.md` | Record implementation, validation and deployment limitations. |

## Security issues fixed

- Rotating IP addresses no longer provides a fresh login budget for the same email. The account limit applies before credential verification and independently of the IP limit.
- Whitespace, capitalization and common accent variants share a bucket. Transliteration accounts for variants accepted by the configured MySQL case/accent-insensitive collation. Stored email addresses and credential lookup behavior are unchanged.
- Known and unknown emails receive the same account-limit policy, generic HTTP 429 response and retry headers. The limiter never looks up whether an account exists. Cache keys contain a digest instead of the submitted email.
- Incorrect passwords for active and suspended accounts produce the same HTTP 422 JSON as unknown emails. Unknown emails also perform password hashing using the configured driver and work factor, reducing the previous fast missing-account path. This is not a strict constant-time guarantee for accounts with historical hash settings.
- Successful attempts count toward the account budget and do not clear it. The fixed window expires after 60 seconds; blocked requests do not extend the account timer. This is a temporary cache limit, with no persistent account-lock field or database schema change.

Jobseekers (`applicant`), employers and administrators retain cookie login, session ID renewal, session restoration and logout. Suspended accounts remain blocked after correct password verification. Login does not change passwords, roles, statuses or other account fields. Laravel's existing remember-token rotation on logout is preserved.

## Render and client IP review

Reviewed `backend/bootstrap/app.php`, `backend/config/trustedproxy.php`, and the container's Nginx, FastCGI, entrypoint and environment-check files. No changes to these files or production settings were necessary.

The existing Render path uses Nginx to validate the single `CF-Connecting-IP` value and pass it as `REMOTE_ADDR`, falling back to the socket address for malformed values. Non-Render mode uses the socket address. FastCGI clears `Forwarded` and `X-Forwarded-*`; the container requires an empty Laravel proxy allowlist. The new limiter uses only the email and is independent of these headers.

Render documents its [Cloudflare and load-balancer ingress](https://render.com/articles/how-render-handles-ddos-attacks). Cloudflare documents [client-IP headers and Worker-specific behavior](https://developers.cloudflare.com/fundamentals/reference/http-headers/). The existing normalization is safe only while its ingress trust assumptions hold. Local tests verify parsing and application behavior; they cannot establish that every live ingress route overwrites caller-supplied `CF-Connecting-IP`.

## Test results

| Check | Final result |
| --- | --- |
| `php artisan test --compact` | **230 passed, 2,851 assertions**, 20.80 seconds; exit 0. |
| Focused login security tests | **19 passed, 235 assertions**; exit 0. |
| Pint on all five changed PHP files | Passed. |
| `php -l` on all five changed PHP files | No syntax errors. |
| `git diff --check` | Passed. |
| Existing `scripts/nginx-smoke.mjs` | Passed using portable Windows Nginx and PHP-CGI with synthetic settings. |

Coverage includes distributed failures, preserved IP limits across different emails, independent email buckets, normalized keys, malformed email arrays, retry headers, expiry, successful login without budget reset, generic known/unknown errors, all three roles, session renewal, suspension, unchanged account data, and database-cache sharing across independently constructed limiter instances. Proxy tests cover untrusted spoofed headers, explicitly trusted proxies, and forged entries before the nearest untrusted client in a forwarded chain.

The Nginx smoke test verifies template syntax, routing, API responses, CORS, secure host-only session cookies, IPv4/IPv6 normalization, malformed-IP fallback and rejection of forged forwarded metadata. Its temporary inspection controller is removed by the script and is never part of the application.

Laravel tests used forced SQLite `:memory:` settings with the existing guard refusing any existing database. Test-only migrations ran in memory with explicit user approval. No migrations ran against local or production databases. Password recovery tests intentionally emit warnings for simulated provider/configuration failures; the final suite had no failed tests.

## Remaining problems and deployment assessment

No implementation or automated-test failures remain for this change.

- Live Render edge spoofing verification and a Linux Docker smoke test were not performed. Before release, verify client identity through the actual ingress and confirm that two client IPs share the account budget while retaining separate IP budgets.
- Shared database cache must remain enabled across application workers/instances. Existing Render startup checks already require it; no setting was changed.
- An attacker can temporarily exhaust a target email's budget. The 60-second window avoids a permanent account lock, but this availability tradeoff is inherent in account throttling.
- The local test runtime is PHP 8.2.12, below this repository's security baseline. The existing Dockerfile pins PHP 8.4.27; neither runtime configuration was modified. These tests do not certify the live production runtime.

**Deployment assessment:** The code change is suitable for a controlled deployment after the normal staging/Render ingress smoke check. It requires no schema migration, new environment variable or production setting change. Live production security is not certified by local tests alone.

No production data, existing local database records, environment files, keys, deployment settings or dependencies were modified. No commit, push or deployment was performed.
