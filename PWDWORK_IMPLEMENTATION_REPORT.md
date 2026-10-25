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


## Phase 4 - Accommodation Request System (24 October 2026)

Phase 4 implements private recruitment/interview request coordination on existing job applications. Applicant and employer pages are available at `/applicant/accommodation-requests` and `/employer/accommodation-requests`, with individual `/:id` details, an applicant `/new` form, status/search/application filters, pagination, refresh, empty/loading/error/success states, and links from Applications/Applicants and the role navigation. Create, edit, withdraw, acknowledge, propose, confirm original arrangements, accept/decline alternatives, discussion, history and notifications use real Laravel APIs and database persistence. There are no placeholder actions or automatic applications, hiring decisions, recommendation penalties or schedule changes.

### Privacy, consent and lifecycle

The request uses an intentional required sharing checkbox. Only selected catalog labels and optional practical details/discussion/history are shared with the employer associated with the owned application. No profile preference is copied automatically. Existing `share_accommodations` consent remains independent and unchanged. Diagnoses, certificates, PWD IDs and uploads are not requested. Applicants are instructed to describe practical support, not medical information.

Statuses are `submitted`, `under_discussion`, `alternative_proposed`, `agreed`, `unable_to_provide` and `withdrawn`. Employer acknowledgment moves Submitted to Under discussion. Confirmation of original requests is allowed only from Submitted/Under discussion; alternatives are proposals and cannot be confirmed by the employer while pending. Only the applicant can accept/decline a proposal. A decline returns to discussion. Inability responses require an explanation and never reject the application. Applicant edits renew sharing consent, preserve history, reset status to Submitted and clear previous agreements. All non-withdrawn requests support private messages. Withdrawal is irrevocable for that request, blocks employer lists/detail/messages/history/actions, and prevents further mutations; applicants retain their own history and may intentionally create a new request. Previously viewed information cannot be made unseen.

Current interview information comes from the existing application. Submission and confirmation record interview snapshots; rescheduling preserves the request and flags changed context for discussion/reconfirmation. Confirmed interview arrangements appear in existing applicant/employer application cards, with changed-schedule warnings. Schedule changes still go through the existing authorized scheduling API. No request action writes application status, interview date/time or profile preferences.

### Schema and safe upgrade

New additive migration: `backend/database/migrations/2026_10_25_000001_create_accommodation_request_workflows.php`. It creates `accommodation_requests`, `accommodation_request_messages`, and `accommodation_request_events`, including restrictive foreign keys, timestamps, JSON category/snapshot fields, status/version, deduplication keys/hashes and lookup/order/uniqueness indexes. Its `up()` does not alter existing tables or records. **The migration was not applied to the existing MySQL database.** Existing application APIs skip new relations while the table is absent; Phase 4 endpoints return a generic 503 until all new tables exist. The pending migration was confirmed with the read-only `php artisan migrate:status` command.

[PHASE4-SETUP.md](PHASE4-SETUP.md) documents the exact schema, approved manual migration command, safe upgrade sequence, status transition table, request payloads, test commands, configuration, production requirements and limitations. The new features require that upgrade before use against the existing database. No deployment or push was performed.

### API and security

New authenticated applicant/employer routes:

- `GET /api/accommodation-requests/catalog`
- `GET /api/accommodation-requests` (`status`, `application_id`, `query`, `page`, `per_page`)
- `POST /api/accommodation-requests` (applicant only, explicit sharing)
- `GET /api/accommodation-requests/{id}`
- `POST /api/accommodation-requests/{id}/actions`
- `GET /api/accommodation-requests/{id}/messages`
- `GET /api/accommodation-requests/{id}/events`

Existing `GET /api/applicant/applications` additionally supports a validated `application_id` filter for the create form. Authorized application payloads include confirmed interview arrangements when Phase 4 is installed. Existing saved-job behavior and matching logic are unchanged.

Sanctum, existing active-account/role middleware and first-party CSRF protection remain in use. Participant-scoped queries exclude other applicants/companies/admins and recheck employer authorization on message/history queries. New private responses use `Cache-Control: private, no-store`. Laravel Form Requests validate writes; pagination defaults to 12 and caps at 50. Context relations are eager loaded and lists/history/messages sort by descending ID. Private discussion has no edit/delete API. React text rendering escapes user input, with no HTML injection or attachment handling.

Transactions and locking reads recheck active roles and current versions. Unique per-application submission keys and per-request action keys provide idempotency; payload hashes reject reuse for changed content. Exact retries add no messages/events/notifications. Stale versions return 409 and require refresh/review. Client-side in-flight guards prevent repeated submissions and retain an action key across uncertain network failures. Creation/actions have request throttles. Notification failure rolls back the entire private write.

In-app notifications reuse `user_notifications`, existing recipient ownership/read/unread APIs and the Notifications page. Each successful action creates a single generic notice for the other participant, with a relative safe link and no categories, descriptions, medical information or message content in previews. Withdrawal links lead to the list, and old links remain subject to current access checks. Phase 4 does not require email, workers or paid services. No request body, discussion or private history is added to ordinary admin audit logs, admin statistics or admin APIs. Reporting links do not copy private descriptions into reports.

### Accessibility

Native labeled forms, fieldsets, explicit textual statuses, validation, status/error announcements, refresh/pagination controls, existing `ReviewDialog` focus management and confirmation flows are reused. Accept/decline/withdraw moves focus to the updated request heading when its original trigger disappears; editing restores focus to its trigger. Existing dark/high-contrast/text-size/reduced-motion/font/cursor settings apply through the current design system. Long private text wraps on narrow screens. Formal WCAG conformance requires a separate manual audit; browser checks cover practical reflow and focus behavior where supported by this environment.

### Files added

- `backend/database/migrations/2026_10_25_000001_create_accommodation_request_workflows.php`
- `backend/app/Models/AccommodationRequest.php`, `AccommodationRequestMessage.php`, `AccommodationRequestEvent.php`
- `backend/app/Services/AccommodationRequestService.php`
- `backend/app/Http/Controllers/AccommodationRequestController.php`
- `backend/app/Http/Requests/AccommodationWorkflowRequest.php`
- `backend/app/Http/Middleware/EnsureAccommodationRequestsReady.php`
- `backend/tests/Feature/PhaseFourTest.php`
- `src/services/accommodationRequests.js`
- `src/pages/AccommodationRequestsPage.jsx`
- `src/components/InterviewArrangements.jsx`
- `PHASE4-SETUP.md`

### Files modified

`backend/app/Models/Application.php`, `backend/app/Http/Controllers/JobListingController.php`, `backend/routes/api.php`, `src/App.jsx`, `src/components/Navbar.jsx`, `src/pages/ApplicantApplicationsEnhancedPage.jsx`, `src/pages/EmployerApplicantsEnhancedPage.jsx`, `scripts/browser-smoke.mjs`, and this report. Browser tooling supports optional alternate ports and a focused Phase 4 mode, using only temporary fixtures/profile/proxy configuration.

### Verification and limitations

- Full `php artisan test`: **70 tests passed, 723 assertions**, including 13 Phase 4 tests and all existing Phase 1-3 regressions (isolated in-memory SQLite).
- React production build and frontend lint passed; final verification rerun results follow below.
- PHP syntax checks across app/routes/tests/migrations passed; Laravel Pint formatted and checked changed/new PHP files.
- `git diff --check` and browser-script syntax checks passed.
- Focused Phase 4 browser smoke checks passed using temporary SQLite/profile and separate test ports: intentional sharing, native form submission, escaped private message content, edit/history, employer acknowledgment/proposal, explicit acceptance, confirmed arrangement integration, mobile reflow, dialog focus/cancel/withdraw, and revoked employer access.
- Native CDP keyboard injection did not reach the focused document in this Windows headless environment. The harness records this limitation and uses native `requestSubmit` and dialog `cancel` event fallbacks, while asserting focus restoration. This does not establish full keyboard-only or WCAG conformance; a manual accessibility audit remains necessary.
- Read-only configured MySQL `migrate:status` and `migrate --pretend --path=database/migrations/2026_10_25_000001_create_accommodation_request_workflows.php` passed. The preview contains only the three new tables, constraints and indexes. The migration remains pending; no Phase 4 MySQL DDL or request writes were executed, and actual MySQL workflow/load tests remain an upgrade/staging requirement.
- An initial browser focus assertion exposed a real confirmation-dialog focus ordering issue; focus now moves to the updated heading after dialog cleanup. Earlier test harness input/focus assumptions were corrected before the successful focused run. Full final browser regression result follows below. Phase 4 regression coverage includes intentional/optional sharing, application ownership and participant/role boundaries, private-profile exclusion, immutable messages, withdrawn access revocation, applicant alternative acceptance/decline, invalid transitions, retry/conflict behavior, notification privacy/atomicity, pagination/filtering, edit history/agreement reset, interview rescheduling, independent profile consent, recommendation-score invariance and operation before the migration.

Communication is refresh-based, with no realtime service, attachments, reminders, multi-user employer teams or retention/export/deletion automation. Text is not automatically screened for medical disclosures; guidance and minimization are used instead. Arrangements are employer/applicant statements, not independently verified accessibility guarantees. Tests use isolated SQLite for mutations, with MySQL schema/runtime verification described in final results; no existing MySQL records were changed. Production needs reviewed additive migrations, HTTPS/secure-cookie/CORS/Sanctum configuration, persistent protected MySQL/backups, disabled debug/body logging, and measured load/accessibility/retention reviews. The independent lifecycle, typed events, participant authorization, idempotency and regression tests provide Phase 5 readiness without coupling accommodation outcomes to hiring or recommendations.


Final Phase 4 verification:

- Full browser regression (`node scripts/browser-smoke.mjs` with separate API/frontend/debug ports): passed for Phases 1-4 using only isolated temporary SQLite fixtures and browser profile. No uncaught browser exceptions. Includes all prior search/authentication/application/scheduling/admin/recommendation/saved-state workflows and the new request sharing, messages, editing, proposal/acceptance, interview integration, 320px layout, dialog focus, and withdrawal authorization checks. Native keyboard injection used the documented form/cancel fallback.
- Final production frontend build and lint: passed. Final PHP syntax and Pint checks: passed. No dependencies, deployment, GitHub push, existing-database migration or existing-record mutations were introduced.
- The Phase 4 migration remains pending in the configured MySQL database. Existing application APIs continue to work while it is pending. Follow PHASE4-SETUP.md for the separately approved upgrade; actual MySQL request writes and a manual keyboard/accessibility audit remain staging/deployment verification tasks.


### Phase 4 final security and notification polish

Accommodation-request API exception responses now use safe messages even with debug mode enabled. Unauthorized or missing request details/messages/history/actions keep their existing HTTP 403/404 authorization behavior and return no model names, exception details, database information, stack traces or private data. Not-found responses display: "Accommodation request not found or you do not have permission to view it." Unexpected failures use a generic message; field validation errors remain useful and rate-limit headers are preserved. This handling is scoped to accommodation-request endpoints; ownership checks are unchanged. Notifications now separate the related-page link and mark-as-read button with responsive horizontal/vertical spacing, preserving control order and keyboard access.

Verification: 72 Laravel tests passed (790 assertions), including unrelated-company direct API access, identical safe errors for missing/unauthorized IDs, protected message/history/actions, debug on/off and simulated internal failures. Full browser regression passed with isolated temporary SQLite fixtures, including the accessible unauthorized direct-link message and computed notification spacing; the previously documented native keyboard injection fallback remains in use. React build, lint, PHP syntax, Pint and diff checks passed. No existing MySQL records, schema, deployment or GitHub push were changed.

## Phase 5 â€” Accessible Resume Builder (25 October 2026)

### Delivered behavior

Applicants can manage multiple private resumes at `/applicant/resumes`: create, edit, preview, download PDF, duplicate and delete, with titles, versions, last-updated dates and pagination. The structured editor includes personal/contact information, summary/objective, education, work, volunteering, skills, certifications/training, projects, languages and optional references. Only the name is required in document content; employment history and sensitive disclosures are not required. Repeatable entries have native keyboard-operable add/remove/move controls. Templates share one underlying document: Classic Professional uses traditional serif typography, and Modern Minimal uses sans-serif typography with restrained teal headings. Both are single-column.

A confirmation gates optional profile import. The importer selects name, email, location, skills, summary and combined work/volunteer experience only, and never changes the profile. Existing experience is free text, so it is initially imported into Work experience and can be edited or separated into volunteering manually. Applicants should review imported prose because free text might contain information they previously entered themselves. PWD identifiers, private phone fields, accommodation settings and private discussions are not fetched by import.

Save validates the complete document, increments a version, and refreshes the preview. Optimistic concurrency returns 409 for stale saves. Client locks prevent repeated-click saves/shares; controls indicate busy state and announce save/errors. Native navigation confirmation protects unsaved editor changes, including router back navigation; tab close/reload uses browser beforeunload. Drafts remain in memory and are not stored in local/session storage. The root now uses React Router's data router to support its navigation blocker; existing routes and providers remain intact. The editor is lazy loaded.

### Sharing and privacy

After applying, an applicant chooses a saved resume on their application page and explicitly consents to sharing that document. Accommodation consent remains separate. A transaction copies title, template, version and content into an application snapshot; personal edits and deletion do not change it. An explicit subsequent share replaces the active snapshot. Applicants can revoke future access at any application status. Deleting a personal resume and revoking a share are separate actions with clear guidance. Previously viewed/downloaded copies cannot be recalled.

Only the applicant who owns the application and the employer who owns its job's company can read an active snapshot. Employers cannot access personal resume CRUD/PDF routes or browse unrelated documents. No public share links are created, and neither creating a resume nor submitting a job application automatically shares it. Existing uploaded profile resumes retain their earlier workflow; builder documents do not enter that storage path or legacy endpoint. Application status, Fit & Access Match scoring, profile records, verification, moderation and accommodation state are not changed by resume actions.

Application lists return only eager-loaded active-share metadata, not content. Resume selection fetches paginated choices when opened, avoiding per-row requests on page load. Owner/role checks, input allowlists, size limits, transactions, active-account rechecks under lock, throttling and private no-store responses protect the new endpoints. Unknown content keys are rejected. Blade escapes all text; applicant HTML, scripts, images and external resource URLs are not interpreted. Unexpected and unauthorized resume errors return sanitized JSON even with local debug enabled. Database/storage paths and traces are excluded from responses.

### Schema and API

One additive migration: `backend/database/migrations/2026_10_25_000001_create_resumes_tables.php`.

- `resumes`: applicant foreign key, title, template, validated JSON content, version, timestamps and applicant/updated index.
- `resume_application_shares`: unique application foreign key, nullable source-resume key, copied title/template/content/version, shared timestamp and revocation timestamp. Source deletion nulls only the reference. Application deletion cascades its snapshot.

The migration was **not applied to the existing MySQL database**. New endpoints return a safe 503 while it is pending; existing application endpoints tolerate its absence. No existing records were imported, replaced or reset.

All new endpoints require Sanctum authentication. Applicant routes under `/api/applicant/resumes` provide GET/POST collection, POST `import-profile`, GET/PUT/DELETE `{id}`, POST `{id}/duplicate`, GET `{id}/preview`, and GET `{id}/pdf`. Application routes under `/api/applications/{id}/built-resume` provide GET metadata, PUT explicit share, DELETE revoke, GET `preview` and GET `pdf`. Only the owning applicant may write; reads are scoped to the application participants. `PHASE5-SETUP.md` includes request details, limits and the complete endpoint table.

### PDF generation

Composer installs and locks Dompdf 3.1.6, php-font-lib 1.0.2, php-svg-lib 1.0.2, masterminds/html5 2.11.0 and sabberworm/php-css-parser 9.5.0. The project had no existing PDF renderer. The implementation follows Dompdf's [official documentation](https://github.com/dompdf/dompdf) and uses bundled DejaVu fonts, A4 paper, 18 mm margins, wrapping long words and natural multi-page flow. Preview and PDF share one escaped Blade document. Remote resources, embedded PHP, JavaScript and resource protocols are disabled. PDFs are generated in memory after authorization with safe descriptive filenames; no document is published to storage or a paid service.

Executed deeper verification generated synthetic **eight-page PDFs for each template**. Pypdf confirmed searchable text including the final experience entry, name, skills and languages, and A4 dimensions. PyMuPDF confirmed text bounds stayed inside the tested page margins and no raster images replaced text. First-page renders of both templates were visually inspected. The fixture generator does not access a database. Optional Python inspection dependencies were installed only into an ignored local tooling directory, not into production dependencies.

PDFs are not validated PDF/UA and may lack tagging/assistive-technology reading-order guarantees. The HTML preview offers structured headings and text. Browser preview is continuous and may paginate/render fonts differently from the PDF. DejaVu does not guarantee coverage for CJK, emoji or all complex scripts; additional fonts and validation are required for those uses. Extreme content and fonts still need release review.

### File inventory

New backend files:

- `app/Http/Controllers/ResumeController.php`
- `app/Http/Middleware/EnsureResumesReady.php`
- `app/Http/Requests/SaveResumeRequest.php`, `ShareResumeRequest.php`
- `app/Models/Resume.php`, `ResumeApplicationShare.php`
- `app/Services/ResumeRenderer.php`
- `database/migrations/2026_10_25_000001_create_resumes_tables.php`
- `resources/views/resumes/document.blade.php`
- `tests/Feature/PhaseFiveTest.php`, `tests/resume-pdf-fixture.php`

New frontend/tooling/documentation files:

- `src/pages/ResumesPage.jsx`, `ResumesPage.css`
- `src/components/ResumeSharing.jsx`
- `src/services/resumes.js`
- `scripts/verify-resume-pdf.py`
- `PHASE5-SETUP.md`

Modified integration files: `backend/routes/api.php`, `backend/bootstrap/app.php`, `backend/app/Models/Application.php`, `backend/app/Http/Controllers/JobListingController.php`, `backend/composer.json`, `backend/composer.lock`, `backend/tests/Feature/PhaseFourTest.php` (schema mock now also accounts for the new optional table), `src/main.jsx`, `src/App.jsx`, `src/components/Navbar.jsx`, both enhanced applicant/employer application pages, `scripts/browser-smoke.mjs`, `.gitignore`, and this report. No `.env` credentials were read or changed.

### Executed automated checks

- Laravel suite: **84 tests passed, 892 assertions**, including **12 Phase 5 tests** and all existing Phases 1â€“4 tests. All writes/migrations in the Laravel suite use guarded, isolated SQLite memory databases.
- Phase 5 tests cover structured CRUD, pagination metadata, duplication, preview/PDF, stale-save rejection, owner/cross-account boundaries, explicit consent, company authorization, unshared/revoked access, snapshot stability after edits/deletion, profile privacy, escaped malicious input, size limits, unauthenticated access, sanitized debug errors, safe pre-migration operation and in-flight suspension.
- React production build passed. Vite reports a non-blocking main-bundle size warning (~504 kB minified); the resume editor is split into a separate lazy-loaded chunk.
- Frontend lint passed without findings.
- Laravel Pint checks passed for all 15 changed/new PHP files; PHP syntax checks passed for those files.
- `git diff --check` passed.
- Both eight-page PDF extraction/layout checks passed as described above.

### Deployment and remaining manual review

Use `PHASE5-SETUP.md` for the exact backup, migration-preview and single-migration commands. The existing MySQL database remains untouched. Actual MySQL resume writes/concurrency tests should be run in staging after the reviewed upgrade. No deployment or GitHub push occurred.

The web editor inherits existing text-size, high-contrast, dark-mode, reduced-motion, dyslexia font and large-cursor preferences. Fields are labeled, status/errors announced, focus visible, layout flexible and sorting has button alternatives. PDF print typography is independent of theme settings. Full WCAG 2.2 AA and PDF/UA conformance are not claimed. Manual NVDA/JAWS/VoiceOver checks, native navigation confirmation, browser prompt behavior, full keyboard-only export, 200â€“400% zoom and additional language/font coverage remain release checks. There is no autosave, draft recovery after closing the browser, public sharing, photo/rich-text support or historical snapshot archive. Retention policies, backups, HTTPS/session configuration, private production logs and writable framework cache/view directories remain deployment responsibilities.

### Final Phase 5 browser results

- All ten existing Phase 1–4 regression groups passed in the combined browser run: search, authentication/applications/sharing, employer statuses, registration/recovery, mobile/errors, moderation, private reports/audit/suspension, accessibility settings, recommendations, and accommodation workflows/revocation.
- The focused Phase 5 run then passed in Edge using temporary SQLite/profile and separate local ports: create/save, editor focus, repeated sections, reorder, saved HTML preview, 320px reflow, duplicate, explicit resume selection/consent, authorized employer preview and applicant revocation. No uncaught browser exceptions occurred. A synthetic editor screenshot was also visually inspected.
- The combined run did not finish Phase 5 on its first attempt: the Windows headless browser did not deliver native keyboard events consistently. The harness now reports that limitation and checks the focused native reorder button's activation as a fallback. Separate early runs also exposed overly broad checkbox selectors and missing waits for asynchronous action controls; selectors and wait conditions were corrected. Chrome sometimes failed before loading any app page; the final focused run used Edge. No claim of a fully native keyboard-only browser pass is made.
- Final Laravel verification after validation hardening: **84 passed, 892 assertions**. Frontend build/lint, PHP syntax/Pint, browser-script syntax and diff checks passed. PDF extraction/layout verification passed for both eight-page A4 templates. The non-blocking build chunk-size warning and manual accessibility/MySQL staging checks remain documented above.
