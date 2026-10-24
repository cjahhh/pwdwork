# Phase 1.5 setup and integration

Phase 2 extends this baseline. [PHASE2-SETUP.md](PHASE2-SETUP.md) supersedes publication rules: public jobs now require moderation approval, employer verification, open availability, and an active employer account. Admin/report/notification workflows are implemented there; historical deferred-feature notes in this baseline describe Phase 1.5 only.

Installation and local commands are in [README.md](README.md). This document defines the behavior added to the existing Phase 1 implementation. MySQL-backed Laravel models are the source of truth. Browser storage holds accessibility preferences only; historical browser records are not automatically imported.

## Data preservation and schema

Use `php artisan migrate` from backend after reviewing pending migrations and taking an appropriate backup. The new 2026_10_24_000001 migration adds:

- applicant_profiles.experience: nullable professional experience text.
- applications.share_accommodations: boolean, default false for both existing and new applications.

Application status already uses a string column. No status conversion is necessary. Existing hired records are retained; historical hired values may include old shortlisting decisions, and the database cannot distinguish those from legitimate hires. Review ambiguous records with the employer before an explicit correction. Reading an application never changes its status.

The retired database/sql/pwdwork.sql contains no executable statements. Historical imports/reset commands are not an upgrade path. No migration rollback, table reset, or seeding against existing real data is part of Phase 1.5 setup. Existing schema imports from earlier versions still need pending migrations.

## Application statuses

Stored codes: submitted, reviewing, shortlisted, interview, hired, rejected. Employers see submitted as New; applicants see Submitted. Reviewing, Shortlisted, Interview, Hired, and Rejected mean the same to both roles. Shortlisted does not indicate a hiring decision. API responses include a display status and canonical statusCode. Unknown historical values remain readable with their original code and a human-readable label; writes accept only supported codes. Interview scheduling still requires a future/current date and a time in Asia/Manila; changing away from interview clears its schedule as before.

## Professional profiles and privacy

Employers receive professional details only for applications to their own listings: name, email, headline, skills, experience, professional summary, cover letter, and resume availability. Empty fields are shown as Not provided. Experience is a new editable profile field; legacy summaries are not automatically reclassified as work history.

Each application has independent accommodation-sharing consent. The apply screen defaults to unchecked, and Your applications allows enabling or revoking sharing. Repeated apply requests preserve the original cover letter, status, and sharing choice. Only standardized accommodation labels are shared when consent is true; arbitrary historical labels are excluded. Employers cannot modify consent. Private PWD identifiers, phone numbers, raw storage paths, and unrelated profile fields are excluded from employer responses, including status-update responses. Applicants should keep medical diagnoses out of professional free-text fields and resumes; software cannot infer or redact diagnoses inside user-authored documents.

Resume uploads remain deferred. Existing safe, relative PDF/DOC/DOCX references use RESUME_DISK (local private disk by default). GET /api/employer/applications/{id}/resume checks employer ownership, rejects absolute/URL/traversal paths, and streams a private download with no-store caching. Missing files return 404. The API does not expose a storage path or a public link. On cloud hosts, use private durable storage, install its compatible Flysystem adapter if required, and configure credentials through the host. The repository does not currently include the S3 adapter; choosing an S3 disk requires installing it first.

## Collection API and pagination

GET /api/jobs, /api/employer/jobs, /api/employer/applications, /api/applicant/applications, and /api/applicant/saved-jobs now paginate on the server. Defaults: 12 records; per_page accepts 1-50; page accepts positive integers up to 1,000,000. Invalid values return 422. Ordering uses creation time and ID (saved jobs use saved pivot ID) to prevent duplicate/omitted records on tied timestamps for a stable dataset. Concurrent additions can shift offset pages.

Existing named arrays remain: jobs, applicants, or applications. Each response now includes:

```json
{"jobs": [], "meta": {"current_page": 1, "last_page": 1, "per_page": 12, "total": 0, "from": null, "to": null}}
```

This changes collection services from returning bare arrays to returning the complete response envelope. All existing consumers in src were updated together. External clients must consume meta and request later pages; the first response no longer contains every record.

- Public jobs: query, location, modes[], types[], flexible, accommodations[]. Employment types are Full Time, Part Time, Contract. Any selected type/mode may match; all selected accommodations must match.
- Employer jobs: query (title), status=open|closed.
- Employer applications: query (applicant name/email, job title, professional experience/summary), status (canonical code), job_id. Ownership applies to the complete query before filtering.
- Applicant applications: status (canonical code), always account scoped.
- GET /api/applicant/saved-jobs/{jobId} returns {"saved": true|false}, so job-detail state never depends on which saved-jobs page is loaded.
- PATCH /api/applicant/applications/{id} accepts share_accommodations only, for the owning applicant.

React uses labelled Previous/Next controls, live page summaries, loading/errors/retry, empty results, page reset on filter changes, and recovery when a mutation empties the last page. The employer's job filter has its own paginated searchable choices and uses IDs to distinguish duplicate titles. CSV export explicitly fetches all matching application pages in batches of 50; it retains the existing columns and formula-injection protection. It is an explicit export, not a listing API bypass; very large exports can consume browser memory and need a future background export workflow. CSV and pagination are not a transactionally frozen snapshot.

## Registration and public endpoint throttles

Registration: 5 requests/minute/client IP, including validation failures, through Laravel's named registration limiter. Successful registration returns 201 and signs in via the web guard; validation returns 422; throttling returns 429 with Retry-After. Applicant and employer roles are supported; self-registration as admin is rejected. Existing limits remain: login 10/minute, forgot-password 5/minute, reset-password 10/minute.

Use shared CACHE_STORE=database (example default) or an appropriately configured Redis store across web instances. A file cache gives separate limits on each instance. TRUSTED_PROXIES is a comma-separated allowlist of proxy IPs/CIDRs. Empty trusts no proxy. Forwarded client-IP/HTTPS headers are honored only from these proxies; forwarded host is not trusted. Configure the provider's actual ingress addresses and prevent direct access that bypasses the ingress. An incorrect proxy list can collapse clients onto one IP or permit spoofed IPs. Tests cover separate clients behind a trusted proxy and forged headers from an untrusted address.

## Authentication and recovery

First-party React authentication uses HttpOnly Laravel database session cookies with CSRF protection. Login/register fetch /api/csrf-cookie before writing. Tokens remain available through Sanctum for externally provisioned API clients; no public token-issuing flow was added.

FRONTEND_URL is a single trusted frontend URL for reset email links. CORS_ALLOWED_ORIGINS is a separate comma-separated list of exact browser origins. SANCTUM_STATEFUL_DOMAINS contains trusted frontend/API hosts with ports and no schemes. Use one hostname consistently locally. For sibling subdomains, configure SESSION_DOMAIN=.example.com so Axios can read the XSRF cookie and send its header; use same-site frontend/API domains. This architecture does not support arbitrary cross-site hosting domains without further authentication changes.

Every accepted recovery request queues the same job before account lookup and returns a generic 202. PASSWORD_RESET_QUEUE_CONNECTION (default database) and PASSWORD_RESET_QUEUE (default password-resets) select the connection/queue independently of the general queue. Select an asynchronous driver, never sync for real recovery. Run the matching supervised worker, for example:

```powershell
php artisan queue:work database --queue=password-resets,default --tries=3 --timeout=60
```

SMTP errors retry and enter failed_jobs; monitor failed jobs and retry after fixing delivery. Queue push failures return a generic 503. The broker enforces a per-account 60-second throttle, hashed 60-minute single-use tokens, and password confirmation. Reset revokes personal tokens and database sessions; retain SESSION_DRIVER=database for cross-session revocation. Real inbox delivery is a release check; a log mailer does not deliver.

## Cloud deployment requirements

The hosting provider is intentionally unspecified. Preserve the React/Laravel/MySQL architecture.

1. Build frontend assets using the locked Node dependencies (`npm ci`, `npm run build`). VITE_API_URL is public build-time configuration. With /api, configure production routing to Laravel; with a sibling API subdomain, set its HTTPS API URL before building. Serve dist with fallback to index.html for React routes while preserving API routes. Backend is an API and does not need its skeleton Vite assets built.
2. Install PHP dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader`. Serve backend/public; keep .env, source, and private storage outside the public document root. PHP storage and bootstrap/cache must be writable. Enforce HTTPS at the host and configure the proxy allowlist for TLS termination.
3. Use APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, and a stable secret APP_KEY shared across instances. Use exact HTTPS frontend/reset/CORS URLs and stateful domains. Secure secrets using the provider. Existing keys are not regenerated during upgrades.
4. Use managed MySQL/MariaDB with least-privilege runtime credentials, a backed-up database, and provider-required TLS via MYSQL_ATTR_SSL_CA. A separate migration identity can hold schema permissions. Apply reviewed pending migrations during deployment; never reset or seed real records. Test migrations on a representative staging copy first.
5. Use database sessions and a shared cache. Example cache is database and logs go to stderr; configure host log collection, retention, and access controls. LOG_LEVEL should normally be info or warning in production. Local log files need collection/rotation if chosen. Do not use the log mailer with real reset links in production.
6. Supervise the configured asynchronous recovery worker, restart workers after code/config updates, and monitor failed jobs. General QUEUE_CONNECTION alone does not change the recovery worker connection. Supply real SMTP credentials/sender and verify actual delivery. Keep worker timeout below the queue retry interval.
7. Keep applicant documents on private durable storage when used; ephemeral local disk loses files between instances/redeployments. Configure the selected adapter, bucket/permissions, and RESUME_DISK. Never put resumes under a public disk or expose them with a public storage link. No uploads are added in this phase.
8. After supplying production env values, cache config/routes/views using Laravel's production commands, and verify health endpoints and browser login/refresh/logout on the actual domains. /api/health is liveness only and does not assert DB/mail/worker readiness. Arrange database backups and restore verification, service monitoring, and writable runtime storage.

No hosting, deployment, migration against existing data, or Git push was performed by the Phase 1.5 implementation. Production smoke tests need the chosen host and real service credentials.

## Accessibility and release checks

Target remains WCAG 2.2 AA without a conformance claim. Existing settings, skip link, route focus, dialog behavior, reduced-motion support, browser reading controls, and contrast presentation are preserved. Native paginated controls include labels, disabled states and live summaries.

Release checks still include keyboard-only dialogs/navigation; NVDA or VoiceOver labels/errors/status; 200% zoom/320px reflow; contrast; reduced motion; mobile filters; login/refresh/logout; expiry; offline retry; consent grant/revocation; profile visibility restricted to hiring employers; actual email delivery; expired/reused links; private file permissions; and browser behavior behind production HTTPS/proxy/CORS configuration.

Current verification details belong in PWDWORK_IMPLEMENTATION_REPORT.md. Historical October 22 checks are not evidence of a current run.
