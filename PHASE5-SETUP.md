# Phase 5 — Accessible Resume Builder

Implemented 25 October 2026. No migrations were applied to the existing MySQL database. No deployment or Git push was performed.

## Safe upgrade

1. Back up the existing database using your normal backup process and verify that it can be restored. Review the new migration and all pending migrations before changing your database.
2. In `backend`, install the committed dependency versions:

   ```powershell
   composer install --no-interaction --prefer-dist
   php artisan migrate:status
   ```

3. Review SQL for **only** the new additive migration:

   ```powershell
   php artisan migrate --pretend --path=database/migrations/2026_10_25_000001_create_resumes_tables.php
   ```

4. When ready, run this command yourself. It creates `resumes` and `resume_application_shares`; it does not update existing application/profile records:

   ```powershell
   php artisan migrate --path=database/migrations/2026_10_25_000001_create_resumes_tables.php
   ```

   Prerequisite: the existing users and applications tables from earlier phases must already exist. Do not use `migrate:fresh`, reset commands, imports that replace records, or an unreviewed blanket migration command. Rolling back this migration deletes the new resume data, so use a forward fix instead once resumes have been created.

5. From the repository root:

   ```powershell
   npm.cmd ci
   npm.cmd run build
   npm.cmd run lint
   ```

6. Start the existing frontend/API as usual. Sign in as an applicant and visit `/applicant/resumes`. Until the migration is applied, the builder returns a safe 503 and existing application APIs remain available. Share a saved resume from `/applicant/applications`; an employer sees it on their authorized application page.

## Dependencies and hosting

- Composer now locks `dompdf/dompdf` 3.1.6 and its four transitive packages. No paid service, external renderer, browser binary, queue worker or new environment variable is needed for production PDF generation.
- PHP 8.2+, DOM and mbstring are required. Use `composer check-platform-reqs` on the deployment host. Ensure the PHP process can write Laravel's `storage/framework/cache` and standard compiled-view/cache directories. Fonts are bundled with Dompdf. Remote resources, PDF JavaScript and embedded PHP are disabled.
- PDFs are generated in memory after authorization; no public storage URL or filesystem path is returned. JSON content and application snapshots reside in MySQL. Back up and protect these tables as personal data; apply your organization's retention and access policies.
- Keep existing environment-based API, CORS, Sanctum cookie, secure-session and trusted-proxy configuration. Use HTTPS, `APP_DEBUG=false`, a correct public document root (`backend/public` for the API), and private storage in production. No localhost or XAMPP production path was added.
- No changes to `backend/.env` are needed. No existing application status, accommodation preference or profile field is changed by a resume action.

## Data and consent

Resumes contain a versioned, validated JSON document: `personal`, optional `summary`, and arrays of education, work, volunteer, skills, certifications, projects, languages and references. Entries use `heading`, `organization`, `dates`, and `details`. Each section has at most 20 entries, long text fields have a 4,000-character limit, and aggregate content is limited to 120 KB. Title, template, ownership and modification dates are separate columns.

Profile import requires confirmation. Only name, email, location, summary, skills and the existing combined experience text are imported. Phone, PWD identification, accommodation preferences and request discussions are excluded. Existing experience is one free-text field; the importer places it in Work experience, where the applicant can edit or move volunteer information manually. Imported free text should be reviewed because applicants may previously have typed sensitive information themselves.

Sharing requires an owned application, an owned saved resume, and separate explicit consent. The application gets a snapshot of the saved title, template, version and document. Editing or deleting the personal resume does not change this snapshot. Sharing again explicitly replaces it. Revocation blocks future in-system preview/download access; it cannot recall copies already downloaded or viewed. Delete a personal resume and revoke application access separately. No resume is shared at creation or merely by applying for a job. Share it through the application page after applying.

This feature manages builder resumes. The earlier profile-upload workflow and its existing employer download endpoint remain as implemented in previous phases; a builder resume is never copied into that profile file or exposed through that endpoint.

## API reference

All endpoints below require Sanctum authentication and an active permitted account. Paths are relative to `/api`.

| Method | Path | Purpose / permitted role |
| --- | --- | --- |
| GET | `/applicant/resumes?page=1&per_page=12` | Paginated owned metadata; applicant, max 50/page |
| POST | `/applicant/resumes` | Create; applicant |
| POST | `/applicant/resumes/import-profile` | Import with `consent: true`; applicant |
| GET | `/applicant/resumes/{id}` | Owned editable document |
| PUT | `/applicant/resumes/{id}` | Save title/template/content with current `version`; stale writes return 409 |
| DELETE | `/applicant/resumes/{id}` | Delete personal resume |
| POST | `/applicant/resumes/{id}/duplicate` | Independent copy starting at version 1 |
| GET | `/applicant/resumes/{id}/preview` | Escaped saved HTML |
| GET | `/applicant/resumes/{id}/pdf` | Authenticated A4 PDF download |
| GET | `/applications/{id}/built-resume` | Active share metadata or null; owning applicant / company employer |
| PUT | `/applications/{id}/built-resume` | Explicit share/replace with `resume_id`, `consent: true`; owning applicant |
| DELETE | `/applications/{id}/built-resume` | Revoke future access; owning applicant |
| GET | `/applications/{id}/built-resume/preview` | Active snapshot HTML; owning applicant / company employer |
| GET | `/applications/{id}/built-resume/pdf` | Active snapshot PDF; same authorization |

Existing applicant/employer application lists additionally return `builtResumeShare` metadata (or null); they do not return document content. Metadata is eager-loaded. Resume choices are fetched only when opened. Resume/PDF endpoints are throttled. Private responses use no-store headers and errors exclude debug traces, SQL, private content and filesystem paths.

## Verification

```powershell
cd backend
php artisan test
php vendor/bin/pint --test app/Http/Controllers/ResumeController.php app/Http/Controllers/JobListingController.php app/Http/Middleware/EnsureResumesReady.php app/Http/Requests/SaveResumeRequest.php app/Http/Requests/ShareResumeRequest.php app/Models/Application.php app/Models/Resume.php app/Models/ResumeApplicationShare.php app/Services/ResumeRenderer.php database/migrations/2026_10_25_000001_create_resumes_tables.php tests/Feature/PhaseFiveTest.php tests/resume-pdf-fixture.php bootstrap/app.php routes/api.php
cd ..
npm.cmd run build
npm.cmd run lint
node scripts/browser-smoke.mjs
```

Laravel tests enforce SQLite `:memory:` and refuse existing database connections. Browser smoke creates a temporary SQLite database and isolated browser profile. Its migration operations apply only to that throwaway database. Set `PWDWORK_SMOKE_API_PORT`, `PWDWORK_SMOKE_FRONTEND_PORT`, `PWDWORK_SMOKE_BROWSER_PORT` and optionally `BROWSER_PATH` if local ports or Chrome/Edge differ.

Optional deeper PDF verification (development tools only): install `pypdf` and `pymupdf` into a disposable Python environment, run `php tests/resume-pdf-fixture.php` from `backend`, then run `python scripts/verify-resume-pdf.py` from the repository root. It inspects two synthetic eight-page documents for searchable text, final content, A4 dimensions, text bounds and absence of raster screenshots. Fixture generation never accesses a database. It also writes first-page PNGs beside the PDFs under ignored `backend/storage/framework/cache`.

## Accessibility and limitations

- The editor uses labels, legends, native controls, visible focus, save/error announcements, keyboard reorder buttons and flexible mobile layout. It inherits existing text size, high contrast, dark mode, reduced motion, cursor and font settings. Downloads use authenticated requests and can be activated by keyboard.
- The router now uses React Router's data-router provider to protect unsaved edits during in-app/back navigation. Refresh/tab close uses native beforeunload protection. Browsers control the wording and may suppress prompts without prior user interaction. Unsaved drafts are held in memory and are not persisted to browser storage.
- The continuous HTML preview and PDF use the same escaped server template; browser pagination and font rendering may differ. The document itself uses fixed, high-contrast print typography, independent of editor theme preferences.
- PDFs contain selectable/searchable text, but are **not validated PDF/UA** and may lack accessibility tagging/reading-order guarantees in assistive readers. Use the structured HTML preview when needed. Broad Latin-script support comes from bundled DejaVu fonts; CJK, complex scripts, emoji and unusual glyphs need appropriate fonts and further validation before claiming support.
- Full WCAG 2.2 AA conformance is not claimed. Manual NVDA/JAWS/VoiceOver testing, zoom, contrast preferences, native confirmation dialogs and unusual long-content/font cases should be checked before release. Automated tests do not replace these checks.
- No public share links, autosave, rich-text/HTML, photos, drag-only sorting, saved draft recovery, historical snapshot archive or automatic sharing at application submission is included.

See `PWDWORK_IMPLEMENTATION_REPORT.md` for executed test results and the file inventory.

Final browser verification passed for the existing Phase 1–4 regression groups and a focused Phase 5 Edge run. For a focused repeat on Windows:

```powershell
$env:PWDWORK_SMOKE_PHASE5_ONLY='1'
$env:BROWSER_PATH='C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
node scripts/browser-smoke.mjs
```

Use the actual Edge/Chrome executable path on your machine. Unset `PWDWORK_SMOKE_PHASE5_ONLY` to run every phase. Native headless keyboard dispatch was unreliable here; the harness logs a fallback to focused native button activation, so full keyboard-only validation remains manual. Final Laravel result: 84 tests, 892 assertions passed. The full file inventory, browser run details and PDF verification results are in the implementation report.
