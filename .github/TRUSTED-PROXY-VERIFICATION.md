# Focused trusted proxy verification

Date: 26 October 2026

**Assessment:** Local verification passes. Production sign-off remains conditional on verifying Render's public header sanitization and origin isolation. No production requests or deployment actions were performed.

## How PWDWork obtains a client IP

1. In `deploy/docker/entrypoint.sh`, `RENDER=true` selects `$render_client_ip` for the FastCGI `REMOTE_ADDR`. Other environments select the socket's `$remote_addr`.
2. In `deploy/docker/nginx.conf.template`, a `geo` block validates `CF-Connecting-IP` as an individual IPv4/IPv6 address. The map uses that header when valid and falls back to the socket address otherwise. This is format validation; it does not authenticate the header's sender.
3. `deploy/docker/fastcgi-app.conf` passes the selected value as `REMOTE_ADDR`. It explicitly clears `Forwarded` and `X-Forwarded-*`, including their host, scheme and prefix variants.
4. `backend/config/trustedproxy.php` defaults to an empty allowlist. `deploy/docker/check-environment.php` requires that empty list for the container. Laravel therefore uses Nginx's `REMOTE_ADDR`; it does not interpret another forwarding chain. Laravel's configured trust mask also excludes forwarded host/prefix and RFC `Forwarded`.
5. Login's existing `throttle:10,1` uses `Request::ip()` for unauthenticated requests. The shared API limit also uses it. The new `throttle:login-account` separately uses a normalized email digest and is independent of proxy headers.

`X-Real-IP` is not used for client selection. CF and real-IP headers can still be present as request metadata in PHP; the current application does not use them directly. PHP-FPM listens on loopback only and allows only loopback clients.

## Confirmed local results

| Input/path | Observed result |
| --- | --- |
| Render expression; fixed CF header; rotating `X-Forwarded-For`, `Forwarded` and `X-Real-IP` | Same client identity and IP bucket; third harmless probe rejected by a test-only limit of two. |
| Render expression; rotating valid CF headers sent directly to Nginx | Caller-selected identities and separate IP buckets. An untrusted direct path would bypass the IP limit. |
| Render expression; valid IPv4/IPv6 CF value | That address becomes Laravel's client IP. |
| Missing, empty, malformed, comma-separated, duplicate-line, bracketed or IP-with-port CF values | Socket-address fallback; no arbitrary first item selected. |
| Direct/non-Render client-IP expression; rotating all three headers | Socket identity retained; IP limit still enforced. |
| Either expression; rotating CF values; fixed synthetic email | Real account limiter rejects the eleventh GET probe. |

The direct-access result establishes the origin trust dependency. It does not establish that an internet caller can bypass Render's public edge. Render describes [Cloudflare protection for public web traffic](https://render.com/articles/how-render-handles-ddos-attacks). Cloudflare describes its [CF header behavior, including Worker exceptions](https://developers.cloudflare.com/fundamentals/reference/http-headers/).

Render also documents [private-network access within the same workspace/region](https://render.com/docs/private-network), including private access to the primary HTTP server. Free web services cannot receive private-network requests; paid services can. The repository's Free deployment instructions are not proof of the live service's plan or network exposure.

**Runtime fix decision:** No exploitable public Render path was confirmed locally. Authentication, Nginx behavior, Laravel proxy settings and production settings were left unchanged. If untrusted direct/private access is possible, the existing CF trust must be fixed before release. A safe fix requires a verified provider-only peer boundary or authenticated ingress. Guessing Render/Cloudflare/private CIDRs would not establish that boundary and could break client identification. Do not switch to the first `X-Forwarded-For` item or enable wildcard Laravel proxy trust.

## Automated verification

Changed in this follow-up:

- `scripts/nginx-smoke.mjs`: expand the existing real Nginx/PHP-CGI smoke test with both client-IP expressions, duplicate/malformed headers, real IP throttling, explicit CF trust-boundary reproduction, and the real account limiter.
- `.github/TRUSTED-PROXY-VERIFICATION.md`: this report and release procedure.
- `.github/LOGIN-SECURITY-REPORT.md`: link to this follow-up assessment.

The previous login implementation and its tests are preserved. The host smoke test uses temporary file cache storage, synthetic settings, a loopback listener and temporary GET probe routes. It submits no login credentials and accesses no database or mail provider. The script removes its temporary storage and front controller; neither probe route is part of production source or the Docker image.

Run from the repository root in PowerShell, using the existing local portable Nginx binary:

```powershell
$env:NGINX_PATH = 'C:/xampp/htdocs/pwdwork-/backend/storage/framework/cache/render-nginx-tool/nginx-1.30.6/nginx.exe'
node scripts/nginx-smoke.mjs
node scripts/nginx-smoke.mjs --direct
```

Results:

- Both smoke modes passed, including Nginx template syntax, real FastCGI/Laravel behavior, cookie security, CORS, routing and header handling. The Render run explicitly reports acceptance of valid CF headers at the container boundary as an observation requiring edge validation.
- `php artisan test --compact --filter='LoginRateLimitTest|LoginSecurityTest|DeploymentConfigurationTest'`: **22 passed, 248 assertions**. SQLite `:memory:` only; no existing database used.
- JavaScript syntax, PHP syntax for the Laravel bootstrap/proxy config and container environment checker, and `git diff --check`: passed.
- The previous full-suite result remains **230 passed, 2,851 assertions**. This follow-up reran the focused subset; no application runtime code changed.

Docker is not installed on this host. The Windows Nginx adapter replaces filesystem paths, removes the Unix user directive, and simulates HTTPS. It does not validate the actual Linux image or Render's edge.

## Safe staging or controlled production procedure

These are proposed operator steps, not actions performed in this session. Prefer an isolated staging service with the intended ingress, plan and image. A production check requires separate operational authorization.

1. Confirm the intended public hostname(s), plan, private-network reachability and any custom CDN/Worker path. Verify there is no untrusted path to Nginx that skips the sanitizing edge. Obtain provider confirmation if that cannot be established. Check all supported public hostnames and relevant internal paths.
2. Use a quiet window and a fresh client without cookies, authorization or an Origin header. Use only `GET /api/health`. Do not request login, registration or password recovery. `/up` cannot demonstrate API rate-bucket behavior because it has no API throttle.
3. Make at most six sequential requests from one stable egress IP, in less than 30 seconds. Record only status, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and whether an intermediary cache served the response. Stop on 429, unexpected responses, missing rate headers, or fewer than 20 remaining API requests. Do not attempt to exhaust a production limit.

   | Request | Additional headers |
   | --- | --- |
   | 1 | None: baseline. |
   | 2 | `CF-Connecting-IP: 198.51.100.21` |
   | 3 | `CF-Connecting-IP: 198.51.100.22` |
   | 4 | `X-Forwarded-For: 198.51.100.23` |
   | 5 | `X-Real-IP: 198.51.100.24` |
   | 6 | All three with new distinct test values, plus `Forwarded: for=198.51.100.25;proto=http`. |

   Use documentation-only test IPs, never other people's real addresses. Example request shape, after substituting the authorized staging hostname:

   ```powershell
   curl.exe --silent --show-error --max-time 10 --dump-header - --output NUL --header 'Accept: application/json' --header 'CF-Connecting-IP: 198.51.100.21' 'https://staging.example.test/api/health'
   ```

4. Within one fixed rate window, all six responses should consume the same existing API bucket; forged header values must not create fresh budgets. A higher/reset count is suspicious. Window rollover, concurrent clients behind NAT, caching and inconsistent cache stores can make counters inconclusive. Stop and resolve ambiguity in restricted staging diagnostics or with Render support. Do not infer a pass from HTTP 200 alone.
5. Repeat a small baseline pair from a genuinely different egress network. It should use an independent IP bucket. A shared socket/Worker IP can collapse clients into one bucket even when spoofing is prevented. If the application has multiple instances, confirm shared cache consistency through staging diagnostics.
6. If private access is supported, an authorized operator must also verify that internal callers cannot supply arbitrary effective CF addresses. The current local reproduction shows what happens if they can. Restrict/authenticate that path using verified provider information before approving the deployment. Do not publish the local probe routes or client-IP echo headers to production.

The health-check comparison examines the same Laravel IP selection used by login without generating failed login attempts. The local account probe and isolated Laravel tests already exercise account throttling. No production account needs to be locked or queried for this verification.

**Release gate:** Approve production only after the actual edge overwrites caller-supplied CF values, untrusted origin bypass is excluded or remediated, and the intended Linux image passes a smoke check. The local authentication changes are ready for this staging verification; live ingress security remains unverified.

No production settings, data, environment files, migrations, commits, pushes or deployments were touched in this follow-up.
