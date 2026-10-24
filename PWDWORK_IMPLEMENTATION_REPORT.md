# PWDWork Phase 2 implementation report

Date: October 24, 2026 (Asia/Shanghai).

## Outcome

Phase 2 is implemented directly in the existing React/Laravel/Sanctum project. Administration now uses real database queries and protected APIs for statistics, employer verification, job moderation, account restrictions, private reports, audit history, and notifications. Phase 1/1.5 authentication, recovery, profiles, search, Contract filters, pagination, application statuses and accommodation consent remain covered by regression tests.

**Verification:** 50 Laravel tests passed with 523 assertions; production React build/lint, PHP syntax/formatting and the integrated browser smoke suite passed. Tests used only isolated SQLite databases. No business data or credentials in the existing MySQL database were changed, and nothing was pushed or deployed.

**Upgrade required before using this version against the existing database:** the new Phase 2 migration remains pending. It preserves existing records but intentionally sets every existing job's new moderation status to pending, so old jobs require authorized review before public visibility. Apply it through the reviewed backup/staging procedure in [PHASE2-SETUP.md](PHASE2-SETUP.md), then provision or securely use an authorized admin account. No real administrator was provisioned by this implementation run.

## Implemented features

| Feature | Behavior |
| --- | --- |
| Admin authentication | Existing Sanctum cookies/tokens and role middleware enforce active admin access. No public admin registration/promotion API exists. Admins use the normal login and protected /admin routes. |
| Controlled provisioning | Interactive `php artisan pwdwork:provision-admin` prompts privately for a strong confirmed password, refuses existing-account overwrite, asks for production confirmation, and records a CLI audit event atomically. No privileged credentials are hardcoded. The demo seeder no longer creates admins. |
| Dashboard overview | Eleven statistics are calculated from actual records: registered jobseekers/employers, verified/pending/rejected employers, total listings, active approved jobs, pending job reviews, total applications, pending reports, suspended accounts. Recent queues/activity and explicit refresh are included. Navigation back after actions fetches current aggregates. |
| Employer verification | Pending registrations, company/contact review, verified/rejected decisions, required rejection reasons, persistent in-app results, owner review history, corrections and another-review requests. Verified company detail changes invalidate verification and require review. |
| Job moderation | Separate pending/approved/rejected/hidden moderation and open/closed availability. Verified employers submit; admins approve/reject/hide. Approved content edits require review again. Rejected listings can be corrected/resubmitted; hidden listings cannot be restored by employer edits or resubmission. |
| Publication enforcement | Public search/details/save/apply require approved + open + verified company + active employer account. Application submission checks current locked owner/company/job rows, including MySQL repeatable-read semantics. Saved records retain IDs but unavailable listings show neutral placeholders rather than unpublished content. |
| User management | Paginated basic account directory; name/email search, role/status filters, registration dates; confirmed suspension/reactivation with reasons. Self-suspension and removal of the last active admin are blocked. Suspension revokes tokens/database sessions and blocks protected requests; reactivation requires a fresh login. Critical writes recheck current account state under locks. |
| Reports/complaints | Authenticated jobseekers/employers report relevant jobs, employers, hiring accounts, or platform/account problems. Target/category/description validation, ownership/privacy, five submissions/minute/account, and an indexed duplicate-open-report fingerprint are enforced. Admins search/filter, review, assign themselves, add private notes, resolve/dismiss, and follow target links to moderation/account actions. |
| Audit history | Selected actor/action/target/time/reason/before-after status fields are appended in MySQL. Employer/job histories reuse this journal. Complete logs are admin-only, searchable/filterable/paginated, with no public mutation/deletion endpoint. Status changes, audit and in-app notices share a transaction; an audit-write failure rolls the decision back. |
| Notifications | Owner-scoped paginated in-app notifications, related-page links, and mark-as-read. Optional mail implements Laravel ShouldQueue, dispatches after commit on configurable connection/queue, and logs enqueue failures while preserving the durable in-app result. |
| UI/accessibility | Six admin sections, responsive statistic cards/queues, search/filter/pagination, loading/error/retry/empty states, status badges, native confirmation dialogs, focus handling, visible focus and existing font/dark/contrast/motion preferences. Navigation wraps and uses the mobile menu at narrower widths. |

## Database changes and data preservation

Added migration: `backend/database/migrations/2026_10_24_000002_add_administration_workflows.php`.

- employers: verification_reason, verification_reviewed_at, verification_requested_at, review_version, verification-status index.
- job_listings: moderation_status (default pending), moderation_reason, moderation_reviewed_at, review_version, moderation/availability index.
- users: admin_version and role/status index; existing role/status strings are reused.
- user_notifications: optional action_url and ownership/read index.
- New user_reports table with reporter/reviewer relations, statuses, private notes, review versions, target/status/ownership indexes and a nullable unique open fingerprint.
- New admin_audit_logs table with actor relation and indexed action, timestamp, target type/ID history.

The migration does not delete or rewrite existing users, employers, jobs, applications, saved jobs, passwords, legitimate hired statuses or existing verification/availability values. Existing listings enter a safe pending-publication review state through the new column default. No retrospective decisions/history are fabricated.

A preservation test builds the previous schema in a separate SQLite memory database, inserts representative existing records, applies the new migration, and verifies the password hash, verified employer, open job, hired application and saved job remain intact while moderation defaults to pending.

A read-only `php artisan migrate:status` confirmed the 12 previous migrations are applied to the configured database and the Phase 2 migration is pending. No new migration, seeder, reset, destructive import, account restriction or provisioning command was executed against that database. Runtime suspension intentionally removes only affected authentication sessions/tokens when an authorized administrator later invokes it; business records remain.

## React files created or edited

| Files | Purpose |
| --- | --- |
| `src/App.jsx`, `src/components/Navbar.jsx`, `src/index.css` | Protected nested admin/report/notification routes, accessible responsive navigation and admin/dialog styles. |
| `src/pages/AdminDashboardPage.jsx` | Replaced the placeholder with actual aggregate statistics and recent work queues. |
| New `src/pages/admin/AdminLayout.jsx`, `AdminQueuePage.jsx`, `AdminAuditPage.jsx` | Six-section administration navigation, API-backed verification/moderation/user/report lists, filters, pagination, decisions and audit search. |
| New `src/components/admin/AdminRecordReview.jsx`, `src/components/ReviewDialog.jsx`, `src/components/HistoryList.jsx` | Current record details, versioned confirmation forms, private notes, native modal focus/cancel handling and paginated history. |
| New `src/pages/ReportsPage.jsx`, `NotificationsPage.jsx` | Private report submission/status tracking and durable notification management. |
| `src/pages/ProfilePage.jsx`, `EmployerListingsEnhancedPage.jsx` | Company rejection reasons/corrections/resubmission/history; job moderation state/reasons/submission/history and publication-aware controls. |
| `src/pages/JobDetailsPage.jsx`, `ApplicantApplicationsEnhancedPage.jsx`, `EmployerApplicantsEnhancedPage.jsx` | Appropriate report links from listings and existing hiring interactions. |
| `src/components/JobCard.jsx` | Saved unavailable listings display a neutral state without dead public-detail links or unpublished content. |
| New `src/services/admin.js`, `moderation.js` | Real API integration for administration, decisions, histories, reports and notifications. |
| `src/services/api.js`, `src/context/AuthContext.jsx` | Account-inactive responses clear stale auth state and provide a useful message without treating ordinary authorization failures as expired login. |

No browser storage is used for administrative records. Existing accessibility preferences remain the only persisted browser settings.

## Laravel files created or edited

| Files | Purpose |
| --- | --- |
| New `backend/app/Http/Controllers/AdminController.php`, `ReportController.php`, `NotificationController.php` | Protected aggregate/list/detail/decision APIs, scoped reports and notifications. |
| New `backend/app/Http/Requests/AdminIndexRequest.php`, `AdminActionRequest.php`, `SubmitReportRequest.php`, `RequestReviewRequest.php` | Filter/action/report/review authorization and validation. |
| New `backend/app/Services/ModerationService.php`, `backend/app/Support/AdminPayload.php` | Current-row locking/version checks, decision transactions, restrictions, selected safe payloads and notifications. |
| New `backend/app/Models/AdminAuditLog.php`, `UserReport.php` | Persistent audit journal, complaints and their actor/reviewer relationships. |
| `backend/app/Models/Employer.php`, `JobListing.php`, `User.php`, `UserNotification.php` | Status constants, review fields/casts/relationships, shared public eligibility scope and notification navigation. |
| New `backend/app/Console/Commands/ProvisionAdmin.php` | Authorized interactive administrator provisioning. |
| New `backend/app/Notifications/ModerationDecision.php`, `backend/config/administration.php` | Configurable queued optional decision mail; immediate in-app notices remain independent of email. |
| `backend/app/Http/Controllers/AuthController.php`, `ProfileController.php`, `JobListingController.php`, `backend/app/Http/Middleware/EnsureUserRole.php` | Registration history, active-account enforcement, re-review after edits, publication guards and scope-safe hiring/report metadata. |
| `backend/routes/api.php`, `backend/app/Providers/AppServiceProvider.php` | Protected admin/report/notification/review routes and the report rate limiter. |
| `backend/database/seeders/PwdWorkSeeder.php` | Stops creating a privileged demo admin; existing real records are not altered by this code change. |
| New `backend/database/migrations/2026_10_24_000002_add_administration_workflows.php` | Additive administration schema and indexes. |
| New `backend/tests/Feature/PhaseTwoTest.php`; existing PhaseOneTest.php, PhaseOnePointFiveTest.php, browser-fixture.php; `backend/phpunit.xml` | Phase 2 regression/security/preservation tests, explicitly approved/verified public fixtures, isolated browser data, forced optional-email-off default during tests. |

Other updated files: README.md, PHASE1-SETUP.md, new PHASE2-SETUP.md, this implementation report, backend/README.md, backend/.env.example, and scripts/browser-smoke.mjs. No package/Composer dependency or lockfile changes were required. Existing `.env` credentials were neither printed nor changed.

## APIs added

All paths below are prefixed `/api`. Existing authentication and core API paths remain intact. Collections retain named arrays plus `meta`, 12 records default and 50 maximum per page.

| Access | Routes |
| --- | --- |
| Active admin | GET /admin/overview; GET /admin/employers and /admin/employers/{id}; PATCH /admin/employers/{id}/verification |
| Active admin | GET /admin/jobs and /admin/jobs/{id}; PATCH /admin/jobs/{id}/moderation |
| Active admin | GET /admin/users and /admin/users/{id}; PATCH /admin/users/{id}/status |
| Active admin | GET /admin/reports and /admin/reports/{id}; PATCH /admin/reports/{id}; GET /admin/audit-logs |
| Active employer, own records | POST /employer/verification-request; GET /employer/verification-history; POST /employer/jobs/{id}/submit; GET /employer/jobs/{id}/history |
| Active jobseeker/employer | GET /reports (own reports only); POST /reports |
| Any active authenticated role | GET /notifications (own notices only); PATCH /notifications/{id}/read |

Decisions require expected_version and the appropriate canonical status; rejection/hiding/restriction/resolution/dismissal require a reason. Version conflicts return 409, validation/workflow errors 422, role/ownership errors 403, missing/unavailable records 404, expired auth 401/419, throttling 429, and successful creation 201. The route list confirmed all 14 admin routes. No public administrator-registration, role-promotion, report-disclosure or record-deletion route was added.

## Executed verification

| Check | Result |
| --- | --- |
| `php artisan test` | **50 passed, 523 assertions**; all 31 baseline tests retained plus 19 Phase 2 tests. |
| `npm run build` | Passed, 131 modules transformed; production assets generated. |
| `npm run lint` | Passed without reported warnings/errors. |
| `php -l` | All 72 inspected PHP files passed syntax validation. |
| `php vendor/bin/pint --test --dirty` | Passed after formatting changed PHP files. |
| `composer validate --no-check-publish` | Passed. |
| `node --check scripts/browser-smoke.mjs` | Passed. |
| `git diff --check` | Passed; Git emitted Windows line-ending normalization notices only. |
| `php artisan route:list --path=api/admin --except-vendor` | Confirmed 14 admin routes. |
| `node scripts/browser-smoke.mjs` | Passed in headless Chrome with actual Vite/Laravel, database sessions and a newly created temporary SQLite database. |
| Existing MySQL migration status | Read-only check: 12 previous migrations applied; new Phase 2 migration pending. No production/new-schema writes executed. |

Phase 2 automated coverage includes admin/ordinary/guest authorization; normal admin cookie login and inactive blocking; pending registration; verification rejection reasons and correction/resubmission; stale company/job reviews; unverified submission/publication bypass attempts; approval/content edits/rejection/resubmission/hidden protection; all public visibility conditions; unavailable saved-content privacy; session/token revocation and reactivation; self/last-admin protections; in-flight active-state rechecks; report privacy/duplicates/categories/targets/rate limits/reviewer/notes/resolution; owner history isolation; action rollback when audit persistence fails; real statistics, filters and pagination; safe admin/audit payloads; notification ownership and optional queued-notification configuration; controlled CLI provisioning; and old-schema data preservation.

Real-browser checks passed for the existing login/register/recovery/search/Contract/pagination/status/consent/offline flows and for:

- Eleven real overview cards and the employer review queue.
- Company rejection, visible reason, company correction and review request, admin verification, persisted notification, and verified-owner listing submission.
- Administrative job approval followed by public availability.
- Jobseeker report submission, admin resolution with private notes, hiding the reported listing, exclusion from public results, and persisted audit events.
- Account suspension, failed login while suspended, reactivation and successful new login.
- Reporter outcome visibility without disclosure of internal notes.
- Native review-dialog focus and admin 320px reflow, dark/high-contrast toggles and persistence after refresh; no uncaught browser exceptions.

Initial test runs corrected two expectation errors (a nonexistent audit-edit route returns 404 and registration adds an initial history event). The expanded browser harness also needed to wait for document/auth restoration and honor the existing login Retry-After window; application throttles were not weakened. The final browser run passed. Fixtures used fake accounts; cleanup targeted only those runs' verified temporary directories. Production mail delivery was not attempted.

## Known limits and deployment requirements

1. **Existing MySQL rollout is pending.** The actual Phase 2 migration, MySQL/MariaDB locking/concurrent load, production-volume queries and provider DDL behavior have not been executed/tested against existing or staging MySQL data. Review backup/lock implications and rehearse the upgrade on a staging copy before applying it. The tested preservation case uses SQLite.
2. **First-admin operational setup is still required where no secure admin exists.** The documented interactive command is implemented/tested but was not run against existing accounts. Secure historical publicly known demo credentials before public deployment.
3. **Optional SMTP delivery is unverified.** In-app decisions are verified without a worker. The queued mail class/configuration is covered using fakes; existing password-recovery mail was exercised through a real worker with the log mailer. Real provider delivery, credentials, sender, queue supervision/retries and failed-job monitoring still need staging checks. Optional email enqueue is best effort after commit, not an outbox/delivery guarantee.
4. **Hosting remains undecided and undeployed.** Retain HTTPS, production debug-off, stable secret APP_KEY, exact CORS/Sanctum/session domains, trusted ingress addresses, MySQL TLS/backups, shared cache/database sessions, writable runtime directories, collected logs and supervised workers. Production logic uses env-based URLs/configuration, SQL records and existing private storage abstractions; it is not tied to XAMPP.
5. **Accessibility conformance requires manual release review.** Semantic controls, native dialog focus, narrow-screen layout and settings were checked, but NVDA/VoiceOver, full keyboard/dialog/zoom/color/motion reviews are not a WCAG conformance certification.
6. **Audit is append-only through the application API.** Database operators retain direct database access; this phase does not add external tamper-evident retention. Only selected status/action metadata are stored, never whole applicant profiles or credentials.
7. **Reports are not an automatic content-redaction system.** Basic account/admin payloads exclude applicant medical fields and report notes remain private, but users/admins must avoid adding medical/authentication secrets to free text. Duplicate prevention groups open complaints by reporter/target/category; new recurring reports are allowed after closure.
8. **Scale-specific improvements remain workload-dependent.** All list APIs are paginated/indexed for common filters. Substring searches and serialized admin write locks need real-load measurement; the existing explicit CSV export can use substantial browser memory and is not a frozen snapshot.
9. **Later work remains later work.** Resume uploads and new matching features remain later-phase work. Changes remain local; hosting was not deployed and no Git push was performed. Existing private resume references and accommodation consent are preserved.

## Phase 3 readiness

The implemented Phase 2 code and isolated integration checks provide a verified basis for Phase 3 development. First complete the local/staging upgrade and administrator provisioning/review of existing pending listings so the developer environment matches the new schema. Production release remains subject to the deployment and manual checks above. Phase 3 itself was not implemented.


## Phase 3 ? Fit & Access Match (24 October 2026)

Implemented authenticated applicant recommendations at `/applicant/recommendations` and `GET /api/applicant/recommendations`. The API uses Sanctum, existing active-account/applicant role middleware, existing public eligibility scope, validated `page` (1?1,000,000) and `per_page` (1?50, default 12), existing jobs/meta response conventions, and private/no-store response caching. Scores are never added to public job APIs. No user ID parameter is accepted as the matching identity; identity always comes from authentication.

The page includes real job records, verified employer badges, title/company/location/type/work arrangement, textual scores, confidence/coverage descriptions, matching skills, explanations, employer-reported support, loading/error/retry and empty states, pagination, Save Job through the existing API, and Apply for Job links into the existing detail/application/consent workflow. Applicant navigation links to recommendations. The home page shows three recommendations for applicants and retains featured jobs for visitors. Existing accessibility styles/settings apply, with semantic text and native buttons, links, lists and disclosure elements.

### Formula and fairness

Professional weights are skills 40, preferred work arrangement 25, and preferred location 15. Skills use case-insensitive literal substring occurrence in the job title or description; the fraction of distinct, nonblank, trimmed profile skills found earns the skills points. Work arrangement and location use trimmed case-insensitive exact equality. Missing employer work/location earns half that component's points and is explained as unknown. Nonmatching stated work/location earns zero; this never restricts access or applications. Missing applicant categories are omitted from the denominator. `score = round(100 ? earned points / available weights)`. No available professional category returns a null score and guidance, while retaining all eligible jobs. Coverage is displayed to avoid implying reliability from a sparse profile. For example, one of two skills plus matching work/location yields `(20 + 25 + 15) / 80 ? 100 = 75`.

Accommodation preferences are optional and informational: matched labels are explicitly employer-reported, never independently verified; missing reports are unknown. They never change score or ordering. This deliberately replaces the suggested 20% access weight so disclosure and unreported support cannot disadvantage applicants. Diagnoses, PWD IDs, summaries and experience are not scoring inputs. Summaries and experience remain editable professional context; arbitrary free text is excluded to avoid extracting sensitive traits or inventing skills. Employer verification does not verify individual accommodation claims. Scores establish neither eligibility, hiring probability nor guaranteed accessibility. No automated applications/rejections or score-based access restrictions exist. Existing explicit per-application sharing consent remains unchanged.

### Database, performance, and deployment

No new tables, migrations, records, or dependencies. The existing `preferred_work_mode` field is now editable through `preferredWorkMode` on GET/PUT `/api/profile` (`Remote`, `Hybrid`, `Onsite`, or blank). Existing profile location doubles as preferred location. Existing skills, experience, summary and accommodation relations are reused. No migrations were applied to the user's database.

Eligibility requires open + approved jobs, verified employer and active employer account. Database SQL computes scores and sorts score descending then ID descending before pagination; only one page of jobs is hydrated. Relationships are eager loaded, avoiding per-job queries. Recalculation occurs on every API request, so the next fetch reflects profile, listing, employer and moderation changes without cache invalidation or workers. Pagination is stable for unchanged inputs; concurrent listing/profile changes can shift offset pages.

Production uses the existing environment-based API, HTTPS/cookie/CORS/Sanctum configuration and persistent MySQL storage. Build frontend with `npm run build`; no special service, paid API, worker or Phase 3 migration is required. No deployment or GitHub push was performed.

### Files

Created `backend/app/Services/JobMatchService.php`, `backend/app/Http/Controllers/RecommendationController.php`, `backend/tests/Feature/PhaseThreeTest.php`, and `src/pages/RecommendationsPage.jsx`.
Modified `backend/app/Http/Controllers/JobListingController.php` (protected reusable payload), `ProfileController.php`, `backend/routes/api.php`, `src/App.jsx`, `src/components/Navbar.jsx`, `src/pages/HomePage.jsx`, `src/pages/ProfilePage.jsx`, `src/services/applicant.js`, `scripts/browser-smoke.mjs`, and this report.

### Limitations and Phase 4 readiness

Literal skills matching does not understand synonyms, negation, proficiency, years of experience or actual hiring requirements. Locations are exact text comparisons, with no distance/geocoding or remote geographic eligibility inference. One work arrangement and location preference are supported by the existing schema. Summary/experience and employment type do not influence ranking. Matching scans eligible listings in SQL; very large catalogs should be profiled with MySQL EXPLAIN before introducing a search index. No formal accessibility audit or live cloud deployment was performed. Automated mutation tests use isolated SQLite. Read-only ranking/pagination/explanation and weighted-expression smoke checks also passed against the configured MySQL database, without changing records; broader production MySQL integration/load testing remains necessary. Phase 4 can build on the authenticated API, explainable formula and tests, with curated job skill fields, preference research, and measured search/index improvements before any more complex ranking.

### Verification

- `php artisan test`: 56 passed, 566 assertions, including six Phase 3 tests covering authorization, role validation, empty/incomplete profiles, normalized skills, work/location scoring, unknown employer information, optional accommodations, sensitive-field exclusion, identity isolation, all publication gates, stable ordering/pagination, profile API updates and live recalculation. Existing saved jobs, applications, consent and admin regression tests pass.
- `npm.cmd run build`: passed; production bundle generated.
- `npm.cmd run lint`: passed.
- PHP syntax checks across app/routes/tests: passed; Laravel Pint checks passed for changed/new PHP files.
- `git diff --check` and browser script syntax check: passed.
- Read-only configured MySQL smoke checks: ranking, pagination, eligibility, explanations and bound weighted SQL passed; one existing eligible listing sampled. No database mutations or migrations.
- Browser suite results follow below. The initial sandboxed Chromium startup failed; running the isolated suite outside the sandbox resolved startup. One Phase 3 test selector was corrected from button to link before rerunning; this was a test issue, not an application failure.

- `node scripts/browser-smoke.mjs`: final full run passed using isolated temporary SQLite fixtures and browser profile. Verified baseline search, authentication, saves, applications/consent, employer workflows, recovery, admin verification/moderation/reporting/suspension, responsive layout and persisted accessibility settings; Phase 3 recommendations/explanations, save action, apply detail link, personalized home and 320px reflow passed with no uncaught browser exceptions. Existing MySQL records were untouched.
