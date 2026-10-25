# PWDWork — final UI/UX and QA handover

## Gmail / HTTPS password recovery integration — 25 October 2026

Reused Laravel's broker, expiry, single-use tokens, password rules and session revocation. Added branded HTML/plain-text email, explicit Gmail SMTP/Brevo HTTPS selection, safe mailer/queue guards, fragment-based frontend links, origin-only referrers, private worker-free local delivery and sanitized failures. Existing `.env`, real credentials and MySQL records were not inspected or modified. No real email was sent and no migration/deployment was performed. See `PWDWORK_PASSWORD_RECOVERY_REPORT.md` for exact manual configuration, end-to-end testing and Render Free limitations.

Fresh results: **114 Laravel tests / 1,181 assertions**, including **15 recovery/email tests / 158 assertions**; **14 complete browser workflow groups**; React production build; ESLint; Oxlint; Laravel Pint; and syntax checks for **105 PHP sources** all passed. Mail/Notification fakes, a real in-memory mail transport, mocked HTTPS and isolated browser fixtures cover privacy, replay/expiry, wrong email, broker/request throttles, branded links, TLS configuration, delivery failures, safe logs, worker-free delivery, URL cleanup, role preservation and suspended-account restrictions. Real Gmail/Brevo inbox delivery and MySQL concurrency still need separate manual testing. No private provider key or real account password was used by these checks.

## Final row and footer alignment — 25 October 2026

The shared job grid now stretches cards within each content-sized row. The existing flex footer uses an automatic top margin, so details actions align at the card bottoms. Card content and accommodation information remain unclipped; the only flexible spacer is before the footer. There are no fixed card heights or equal-height constraints across unrelated rows. Existing recommendation and saved-job controls appear before the same footer, preserving their handlers and visible labels. Colors, shadows and themes are unchanged.

The fresh full browser regression suite passed **14 workflow groups**. This alignment change does not modify backend logic, MySQL, application environment configuration or existing functionality; no commit, push or deployment was performed.

Verification uses mixed accommodation counts in Featured Jobs, public/signed-in Find Jobs, Recommended Jobs and Saved Jobs. All **150 combinations passed**, measuring card, footer and action bottoms by actual responsive row, plus clipping and horizontal overflow, at five widths, two text scales and three themes. Synthetic extra labels exist only in the browser test DOM and are restored; no record is changed. The production build, ESLint and Oxlint passed, along with **380 general responsive/text checks**, **60 theme checks**, **32 card stress/preference checks** and **six accessibility preference checks**. There were no unexpected alerts or uncaught browser exceptions. Screenshots and JSON use `backend/storage/framework/cache/ui-alignment-*`; logs are `alignment-ui.log` and `alignment-full.log`.

## Final card depth refinement — 25 October 2026

The latest visual refinement strengthens job and filter surfaces within the existing design. It adds a gray-blue canvas, discernible card boundaries, two-level shadows, 18px corners, tinted card headings, mint support insets and a prominent details footer. The description clamp and automatic support spacer are removed; natural content height preserves full information without stretching short cards. Related saved/recommended/application/employer/overview cards reuse elevation tokens. Search logic, filters, permissions, moderation, statuses, matching and sharing are unchanged.

| Fresh check | Result |
| --- | --- |
| Production build | Passed; no dependency added |
| Oxlint and ESLint | Passed |
| Laravel regression suite | **99 tests, 1,023 assertions passed**, isolated in-memory SQLite |
| Responsive/text matrix | **380 checks passed**, 38 role-specific scenarios × five widths × normal/doubled base text |
| Light/dark/high-contrast review | **60 checks passed**; semantic text contrast threshold retained |
| Additional card checks | **32 passed**: 20 long-content checks, four saved/system reduced-motion checks, four forced-colors checks and four high-contrast border-state checks |
| Existing accessibility preferences | **Six passed** |
| Full browser workflows | **14 regression groups passed** |

Card checks also compare the displayed description and accommodation list with the real fixture API, verify resting/pointer elevation, focus the details action and inspect its visible outline. Synthetic long text is inserted only into the test DOM, measured at 320, 375, 768, 1024 and 1440px with normal/200% base text, then restored. No real job record is modified. Native Windows keyboard dispatch may require the explicitly logged forced `:focus-visible` inspection fallback; that verifies styling and a real focus destination, not a full keyboard-only journey.

Visual review identified that the high-contrast theme's black action token could hide the new teal top accent and hover boundaries. A dedicated high-contrast card/action boundary override corrects this. New checks explicitly inspect all four card edges and the action border at rest and with hover/focus; border presence is no longer inferred from a box shadow or border width alone.

Before views are preserved as `backend/storage/framework/cache/ui-cards-before-*.png`. Current images and measurements use `ui-cards-*` and `ui-cards-results.json`. Focused Find Jobs views are `ui-cards-public-job-cards.png` and `ui-cards-applicant-job-cards.png`; focus and forced-colors images are alongside them. Card views were inspected in the actual local Edge browser using fictional API fixtures. Before and after images use the same synthetic dataset, although viewport scroll positions differ; they are visual references rather than a pixel-difference benchmark. Test logs are `cards-ui.log` and `cards-full.log` in the same ignored cache directory.

No `.env` credential, MySQL data, existing database schema, remote repository or deployment was changed. No migration command was applied to the existing database; test schema setup is restricted to throwaway SQLite fixtures. Earlier manual accessibility/device/zoom and production-launch prerequisites remain. This refinement makes no new WCAG or PDF/UA certification claim.

## Complete redesign verification — 25 October 2026

The Modern Professional Navy + Teal + Mint redesign supersedes the earlier presentation described below. It is implemented in the existing application, with a new public homepage, role-aware workspace sidebar, real applicant/employer overview pages, structured profile editor, job discovery rail, new job/recommendation cards, authentication layouts, notification states, private discussion/timeline styling and a themed Resume Builder workspace. Administration, public information pages and the footer share the same tokens. See `PWDWORK_DESIGN_SYSTEM.md` for palette, typography, components and responsive behavior.

Login and registration now land on `/applicant` or `/employer` overviews; all earlier list/profile routes remain available. Four existing authorized sources supply each overview. Browser assertions compare every displayed total with the API, deliberately block the interview metric, verify **Unavailable** rather than zero, verify that other metrics survive, and retry successfully. No new API endpoint, data migration, matching change, private-data cache or backend permission change was needed.

### Fresh test record

| Check | Latest result |
| --- | --- |
| Laravel suite | **99 tests passed; 1,023 assertions** |
| React production build | Passed; 149 transformed modules; route splitting retained |
| Oxlint / ESLint | Passed |
| Laravel Pint | Passed |
| PHP syntax | Passed, 100 application/config/route/test/database PHP sources |
| Classic Professional PDF | Passed, 8 A4 pages; searchable through the final entry; text within tested margins; no rasterized text |
| Modern Minimal PDF | Passed, same checks; intentionally entered “NA” retained; blank references omitted |
| Full browser workflow suite | **14 groups passed**, including the new public/mobile navigation and overview resilience checks |
| Responsive/text matrix | **380 checks passed**: 38 scenarios × 5 widths × 2 text scales; no overflow, unlabeled main form controls, unexpected alerts or uncaught exceptions |
| Theme review | **60 checks passed**: 10 role-specific representative screens × 3 themes × 2 widths; tested semantic text pairs meet 4.5:1 |
| Accessibility preferences | **6 checks passed** using the existing controls |

The theme check measures eight semantic foreground/background pairs per view, including primary-button text. Minimum measured contrast is **4.83:1** in light and dark modes and **21:1** in high contrast. These token checks supplement screenshot inspection; they do not cover every rendered component, hover state, image or user-generated document. Final visual corrections also ensure legacy dark form fields retain visible control boundaries and resume deletion keeps its danger styling.

The browser harness uses temporary isolated SQLite databases, fresh synthetic accounts and the production build through a local Vite preview. It checks 320, 375, 768, 1024 and 1440 CSS pixels at 100% and doubled base text. Expected missing-token feedback on the reset page remains visible. Actual accessibility preference controls are still exercised. Additional menu checks cover expanded state, Escape focus restoration and closing after selecting the current route; public search is checked for keyword/location preservation. The registration password visibility control is checked for value preservation. Public and signed-in job discovery are separate scenarios.

Initial review found a real narrow-screen overflow in the new homepage employer CTA; its flex sizing and maximum width were corrected. A concurrent theme-matrix attempt hit a browser protocol timeout during fixture creation. The new contrast helper initially omitted CSS three-digit hex notation; it was corrected and rerun without weakening the contrast threshold. The final matrix passes all assertions with a more tolerant protocol timeout. Signed-in job search and job details were added after review identified that they should retain the applicant sidebar.

### Visual review and evidence

Existing Phase 6C images were preserved as `backend/storage/framework/cache/ui-before-redesign-*.png`. Current synthetic screenshots use `ui-redesign-*`; structured measurements use `ui-redesign-results.json`. Browser workflow logs use `redesign-full.log` and `redesign-ui.log`. These ignored local artifacts contain fictional fixture data, not the local MySQL records. Before screenshots used the earlier Windows dark preference; current screenshots deliberately include all three themes. They are structural references, not a same-theme visual benchmark.

Reviewed areas include the homepage, job discovery, applicant and employer overviews, administrative overview, resume dashboard/editor, requests and mobile views. Manual review should also cover all open menus and dialogs, long real-world names and translated content, actual 200–400% browser zoom and physical mobile devices. Automated token contrast checks do not evaluate every possible rendered overlap or user-generated document.

### Deployment and accessibility disposition

No `.env` credential, existing MySQL record, migration, Render/Aiven resource, remote repository or deployment was changed. No dependency was added by this redesign. Prior production security controls and their regression coverage remain in place. This does not promote the project to public-launch readiness: local PHP 8.2.12, deployment/container/storage/worker validation, owner-approved support/privacy/terms/retention decisions, real database/TLS/mail configuration and staging operational checks remain as described in the earlier handover below.

Manual keyboard-only and NVDA/JAWS/VoiceOver checks remain required. Windows headless keyboard dispatch can require the explicitly logged focused-native-control fallback. Doubled CSS text checks do not certify actual browser zoom behavior. Resume PDFs retain selectable text; PDF/UA tagging is not validated. No accessibility certification is claimed.

### Manual acceptance checklist

- [ ] Follow public navigation and hero search using only the keyboard; open/close mobile menus and inspect visible focus.
- [ ] Complete each role's main journey with NVDA or another supported screen reader, including dialogs and status announcements.
- [ ] Inspect light, dark and high contrast at real 200–400% browser zoom and on a physical phone/tablet.
- [ ] Review long names, detailed accommodation requests, repeated resume entries and multi-page PDF output with representative approved synthetic content.
- [ ] Confirm the approved support contact, operator identity, privacy/terms/retention text and production service configuration.
- [ ] Validate patched production PHP, Render build/runtime/storage/queue behavior, Aiven certificate verification, backups and restore in separate staging.

---

## Earlier Phase 6C handover (historical baseline)

Date: 25 October 2026. Academic project: Integrative Programming and Technologies.

The existing React/Laravel application was polished in place. No existing MySQL records, authentication rules, API routes, matching formula, application statuses, sharing authorization or local environment credentials were changed. No migration, SQL import, deployment, GitHub push, hosting purchase or hosting-account connection was performed. Browser data and screenshots use isolated synthetic fixtures only.

## 1. Pages and workflows reviewed

| Area | Screens reviewed |
| --- | --- |
| Public | Home, Find Jobs, job details, For Employers, About, login, registration, forgot password, reset password with missing-token state, and Not Found |
| Applicant | Profile, recommendations, applications, saved jobs, accommodation request list/create/detail and private discussion, resume editor/preview/export/sharing, notifications, reports |
| Employer | Company profile, listing management/create/edit, applicants/status/interview controls, accommodation request list/detail/response, shared builder previews, legacy uploaded-file labels, notifications, reports |
| Administration | Overview, employer verification, job moderation, user management, reports, audit logs, notifications and review dialogs |
| Shared | Route layout, navigation, pagination, form controls, loading/error/empty states, status badges, confirmation dialogs and all existing accessibility preferences |

The automated layout matrix visits **34 role-specific screen scenarios**, including applicant and employer request details, at **320, 375, 768, 1024 and 1440 CSS pixels**, with **100% and 200% base text**: **340 checks**. Shared notifications/reports are visited under each applicable role. Applicant/employer landing redirects remain their existing job-search/listing entry points; no replacement dashboard or architecture was introduced. Unrouted prototype pages remain outside the live route table and were not removed.

The layout review checks horizontal overflow, labels on main form fields, unexpected page alerts and uncaught JavaScript exceptions. The reset page's missing-token alert is explicitly expected and remains accessible; it is not suppressed in the application. Screenshots of Home, search, applications, editor, employer applicants and admin overview at mobile/desktop sizes were reviewed. Synthetic artifacts are under ignored `backend/storage/framework/cache/ui-*`; they are local QA aids, not files to publish or production data.

## 2. Visual improvements

- Added a small centralized UI stylesheet with navy/teal/neutral tokens and matching dark/high-contrast values. Existing yellow accents, focus rings, reduced-motion, reading-font and cursor settings remain.
- Standardized previously unstyled buttons/selects/inputs, secondary pagination controls, submit actions, readable field labels, responsive panel spacing and page-heading hierarchy. Existing purpose-built Tailwind page layouts were retained.
- Added reusable `StatusBadge` and `EmptyState` components. Application, request, notification and admin statuses now use visible text plus consistent emphasis; meaning does not depend on color alone.
- Grouped application filtering and refresh actions; emphasized interview information and the distinction between consent controls. Loading feedback is static and announced, with no new animation.
- Improved homepage feature explanations by reusing the existing truthful feature section. About now identifies the academic context supplied by the owner. No impact totals, contacts, certifications or legal assurances were invented.
- Job cards explain when support information is unreported; details retain paragraph/newline formatting and show accommodations as readable groups. No support is inferred or scored differently.
- Empty saved-job, resume, notification, application and moderation views provide the next relevant action or explanation rather than a blank area.

## 3. Verified defects and functional fixes

| Defect | Final behavior | Verification |
| --- | --- | --- |
| Unshared builder heading implied an actively shared resume | Unshared cards say “Resume Builder” and “Not currently shared”; active cards say “Shared Resume Builder snapshot” with version/date | Browser assertions cover both states |
| “Download resume” did not identify the older profile file | “Uploaded profile resume” and “Download uploaded profile resume” are separate from builder sharing | Page review and preserved download authorization tests |
| Entirely blank repeatable resume entries printed section headings | Whitespace/null/empty entries are omitted from rendered sections in both templates; stored content is unchanged | New Laravel test + PDF extraction checks |
| Content filtering could accidentally discard meaningful “0” / “NA” text | Deliberately entered values remain; no automatic “NA” placeholder is generated | Both-template regression assertions and synthetic PDF fixtures |
| Resume dashboard briefly looked empty during loading and lacked refresh recovery | Loading indicator, retryable refresh and empty-state gating; out-of-range page adjusts after last-item deletion | Browser workflows and layout review |
| Save message announced updated preview before the preview request completed | Saved/loading status precedes “Preview updated” only after successful preview response | Code review and saved-preview browser test |
| Builder delete/import used inconsistent confirmations | Existing accessible review dialog now explains deletion/snapshot behavior and the precise profile import | Browser cancel/confirm/focus checks; backend consent tests retained |
| Deleting a card could lose focus during dialog cleanup | Focus is scheduled after modal cleanup and moves to the dashboard heading | Strict deletion-focus browser regression |
| Closing shared preview removed the focused control without a destination | Focus returns to “Preview shared resume”; revocation moves focus to the sharing heading | Browser focus assertion and UI review |
| 200% text overflowed saved-job cards at 320px | Card headers and constrained grid children wrap | Matrix regression at 320px / 200% text |
| 200% text overflowed employer applicant identity rows at 320px | Identity row wraps, and text flex children may shrink/reflow | Matrix regression at 320px / 200% text |

The only backend application change is the resume **view-rendering** correction. Models, saved JSON, immutable application snapshot data, sharing/revocation rules and matching calculations are untouched. Existing client save locks, API validation, throttling and unsaved-navigation protection remain. New destructive/import dialogs use the established review-dialog component; browser-controlled unsaved-change prompts remain native.

## 4. Resume and accommodation finalization

The editor groups contact/settings fields, uses readable entry panels and offers a desktop preview alongside editing. Smaller screens stack the same controls. “Go to saved preview” provides a keyboard-operable focus destination; export remains an authenticated download. Both Classic Professional and Modern Minimal share the escaped server document.

Synthetic PDFs for both templates were generated as **eight A4 pages**. Inspection verified searchable/selectable text through the final entry, expected names/skills/languages, no raster replacement of text, and text within tested margins. Blank references are absent and explicitly entered “NA” remains. This does not establish PDF/UA tagging or comprehensive script/font support.

Request list/detail status labels, confirmed-versus-proposed arrangements and the existing private discussion/history/actions retain their business meaning. Applicant acceptance is still required for employer alternatives; withdrawal blocks future employer reads. Profile preferences, individual discussions and builder snapshots retain independent consent. Uploaded profile files remain the earlier, separate workflow and are described honestly.

## 5. Accessibility and responsive verification

Verified by automation:

- All requested viewport widths at normal and doubled base text on the 34 scenarios, with no horizontal overflow after the two fixes.
- Main input/select/textarea labels on matrix screens; existing buttons retain accessible names and keyboard-visible focus.
- Actual preference controls toggle and restore high contrast, dark mode, reduced motion, reading font and large cursor; text-size preference changes root text size.
- Dialog focus, import cancellation, deletion focus, request confirmation/withdrawal focus and closing shared previews.
- Existing errors and saves remain live-region announcements. API errors remain sanitized and are styled as readable feedback.
- Repeatable entries retain Move up/Move down buttons; drag and drop is not required. No new timed interaction or motion was added.

**Important limits:** doubled CSS base text is an automated reflow test, not a claim of actual browser-menu zoom at every OS/display combination. Real 200–400% browser zoom and device testing remain manual. Native CDP keyboard dispatch is unreliable on this Windows headless setup; the harness explicitly logs its focused native button/form fallback. Full keyboard-only and assistive-reader conformance cannot be inferred from that fallback. No WCAG certification or PDF/UA compliance is claimed.

## 6. Test results and commands

Executed baseline after current changes: **99 Laravel tests passed, 1,023 assertions**, including all earlier security/authorization regressions and the new both-template rendering test (30 assertions). This is a fresh run, not the older Phase 6A count.

| Check | Result |
| --- | --- |
| `php artisan test --compact` | 99 passed / 1,023 assertions |
| `npm.cmd run build` | Passed; main JS about 352.21 kB minified / 109.67 kB gzip, no chunk-size warning |
| `npm.cmd run lint` (existing Oxlint) | Passed |
| `npm.cmd run lint:eslint` | Passed with supported ESLint 10 flat configuration |
| `php vendor/bin/pint --test` | Passed |
| PHP syntax | 100 source/config/test/migration files passed, generated cache excluded |
| PDF fixture + `scripts/verify-resume-pdf.py` | Both eight-page A4 templates passed text/content/bounds/raster checks |
| Browser-script syntax and `git diff --check` | Passed |
| Layout matrix | Passed all 340 checks and six preference checks; no unexpected alerts, unlabeled main controls, overflow or uncaught exceptions |
| Full workflow browser suite | Passed all twelve Phase 1–6 groups against the final production assets, including new resume dialog/focus assertions |

ESLint, `@eslint/js` and `globals` are **development-only** additions. Existing React-aware Oxlint checks remain enabled. No runtime UI library or paid service was introduced. An initial ESLint 9 installation warned it was unsupported; it was replaced by the supported current ESLint 10 and the unused React plugin removed. Dependency installation reported zero vulnerabilities; a separate new Composer advisory audit was not needed or claimed because Composer dependencies did not change.

Reproduce local checks from the repository root:

```powershell
npm.cmd ci
npm.cmd run build
npm.cmd run lint
npm.cmd run lint:eslint
cd backend
php artisan test
php vendor/bin/pint --test
php tests/resume-pdf-fixture.php
cd ..
# Optional local Python inspection tools: pypdf and pymupdf in a disposable environment.
python scripts/verify-resume-pdf.py
$env:PWDWORK_SMOKE_PRODUCTION='1'
node scripts/browser-smoke.mjs
```

For the matrix, also set `PWDWORK_SMOKE_UI_ONLY=1` and optionally `PWDWORK_UI_LABEL=review`. Configure the three documented smoke ports and `BROWSER_PATH` if needed; unset focused/matrix flags for a full workflow run. All database migration/writes inside these automated suites are restricted to guarded temporary SQLite databases, never existing MySQL. Windows can hold a temporary browser profile open during cleanup; the harness reports the leftover synthetic-data path without treating cleanup as a failed assertion.

The first 100%-text review flagged only the expected missing-token reset alert; the harness classification was corrected without hiding the alert. The expanded 200%-text review exposed the two real overflows and both were fixed. A workflow run caught a real delete-dialog focus race, fixed by scheduling focus after cleanup. A preference test initially omitted the existing “On/Off” suffix in button labels; the selector was corrected. Final results below distinguish these initial failures from unresolved issues.

## 7. Files changed

New:

- `src/styles/ui.css`, `src/components/StatusBadge.jsx`, `src/components/EmptyState.jsx`
- `backend/tests/Feature/PhaseSixCTest.php`
- `eslint.config.js`
- `PWDWORK_FINAL_QA_REPORT.md`

Modified:

- Shared UI: `src/index.css`, `src/main.jsx`, `src/components/JobCard.jsx`, `Pagination.jsx`, `RequestState.jsx`, `ResumeSharing.jsx`.
- Pages: `HomePage.jsx`, `AboutPage.jsx`, `JobDetailsPage.jsx`, `ProfilePage.jsx`, `ApplicantApplicationsEnhancedPage.jsx`, `ApplicantSavedJobsPage.jsx`, `EmployerApplicantsEnhancedPage.jsx`, `AccommodationRequestsPage.jsx`, `NotificationsPage.jsx`, `ResumesPage.jsx`, `ResumesPage.css`, `admin/AdminQueuePage.jsx` under `src/pages`.
- PDF/testing: `backend/resources/views/resumes/document.blade.php`, `backend/tests/resume-pdf-fixture.php`, `scripts/verify-resume-pdf.py`, `scripts/browser-smoke.mjs`.
- Tooling/documentation: `package.json`, `package-lock.json`, `PWDWORK_IMPLEMENTATION_REPORT.md`.

No database migration, `.env`, backend controller/API authorization or deployment infrastructure file was changed for Phase 6C.

## 8. Highest-risk issues before public launch

1. **Unpatched local PHP 8.2.12 must not become an internet-facing runtime.** Use a patched supported production runtime, apply the Phase 6A security requirements and rerun the production gate/staging suite. The local XAMPP installation was not upgraded.
2. **Render build/runtime is not implemented or validated.** This repository has Nginx/PHP/worker *templates*, but no application Dockerfile, `.dockerignore` or Render blueprint. Those templates assume a conventional Linux host and are not a ready-to-run Render deployment. Render documents Docker as the PHP deployment route. [Render Docker documentation](https://render.com/docs/docker).
3. **Aiven connection and migration must be configured and tested separately.** Use a new database/user, provider host/port, protected credentials and project CA via the existing Laravel MySQL SSL option. Verify encryption and certificate/host validation in staging; do not copy a URI into public Vite variables or disable certificate checks. No Aiven service was contacted. [Aiven certificate requirements](https://aiven.io/docs/platform/concepts/tls-ssl-certificates).
4. **Durability and worker setup remain unresolved.** Render filesystems are ephemeral by default; private uploads need a deliberately chosen persistent disk/private object store. Disks are runtime-only and cannot be shared across service instances, so the API and background worker storage/key/queue design must be verified. Recovery mail needs a real running worker and tested SMTP. [Render disk limitations](https://render.com/docs/disks), [background workers](https://render.com/docs/background-workers).
5. **Public policy/operator configuration is still pending.** Support mailbox, operator identity, approved privacy/terms, retention and rights-request handling, backup/restore ownership and administrator safeguards need owner review. Existing honest “pending” notices are not completed legal policies.

The original `PHASE6-DEPLOYMENT.md` remains the generic architecture/backup/migration guide. The user's now-stated intended target is **Render + Aiven MySQL**; earlier VPS price comparisons are not a selection or proof of Render compatibility. Prefer one approved HTTPS origin for React/API or properly configured sibling subdomains so Sanctum cookies work; avoid assuming arbitrary default cross-site platform domains will work. [Sanctum SPA requirements](https://laravel.com/docs/12.x/sanctum).

For cloud demonstration data, keep local records separate. Do not bypass the production demo-seeder guard or import the historical SQL dump. Create approved fictional accounts/jobs through normal workflows or a separately reviewed, allowlisted demo-data procedure after owner approval. No cloud seeding was performed here.

## 9. Remaining manual verification checklist

- [ ] Complete one applicant journey using only Tab/Shift+Tab/Enter/Space: registration, profile, filters, save/apply, requests/messages, builder reorder/save/preview/download/share/revoke.
- [ ] Repeat employer review/scheduling/arrangement responses and admin review dialogs using keyboard only; verify Escape, focus return and background inaccessibility for modal dialogs.
- [ ] Test NVDA or JAWS on Windows and VoiceOver where available: labels, headings, validation, live status, iframe titles, filter changes and private discussion/history.
- [ ] Check actual browser 100%, 200% and 400% zoom, mobile software keyboard, long translated/non-Latin content, screen orientation and forced-colors modes. Measure contrast in normal/dark/high-contrast and hover/focus/disabled states.
- [ ] Review full exported PDFs in target readers, including page breaks, chosen glyphs and reading order. Validate tags separately before any PDF/UA claim.
- [ ] Use an approved patched Linux/container runtime and actual MySQL to verify transaction locking, stale versions, concurrent applications, migration behavior, SSL verification, production routing/CSP/headers/cookies and API failure recovery.
- [ ] Verify real SMTP/reset delivery, worker restart/decryption, private file durability across restart/deploy, backup restoration and code rollback in a separate staging environment.
- [ ] Confirm deployment exclusions keep `.env`, SQL dumps, local data, synthetic screenshots/browser profiles, logs and test-only Python tools out of public images/artifacts.
- [ ] Approve demonstration data and public wording, support/policy/contact configuration, retention, operator responsibilities and the deployment budget before launch.

## 10. Release-readiness assessment

| Classification | Assessment |
| --- | --- |
| Ready for academic presentation | The existing UI and locally verified workflows are prepared for a controlled presentation using approved synthetic data. Use the final results below and the manual checklist before presenting. |
| Ready for deployment | Local build/test artifacts are prepared; **no public deployment approval or complete Render runtime is claimed**. |
| Needs owner configuration | Render/Aiven resources and secrets, domain/origin/proxy/CA, private storage, SMTP/worker, support, policy/operator details, data and backup ownership. |
| Requires further testing | Real patched-runtime/MySQL/HTTPS/SMTP/storage/restore/load tests, actual keyboard/screen-reader/zoom/device checks and external security review as appropriate. |
| Must be fixed before public launch | Unpatched runtime exposure, missing validated Render build/runtime and persistent storage/worker arrangements, and unresolved public policy/security/operational requirements. |

The application remains private/local; no existing database records or environment secrets were modified. Source control changes are left for owner review and were not pushed or deployed.

### Final verification completion

All final checks above were executed. The last full workflow browser run and the separate 340-check matrix/preference run exited successfully. The strict delete-focus assertion that failed initially now passes; it was not relaxed. Existing native-keyboard fallback and manual assistive-technology/real-zoom limitations remain. No automated functional/security defect remains unresolved in this final run, and no deployment approval is implied by these results.
