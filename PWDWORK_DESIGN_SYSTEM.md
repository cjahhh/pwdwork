# PWDWork design system

Modern Professional — Navy, Teal and Mint. Implemented in the existing React application on 2026-10-25. No UI framework, image library, remote font or runtime dependency was added for this redesign.

## Direction and audit

The previous interface mixed large pill buttons, emoji illustrations, individually styled white panels and dense account links. Public pages and authenticated management screens lacked a common hierarchy. The applicant and employer root routes redirected to lists, while their unused dashboard files contained placeholder layouts. Some older informational sections used oversized step cards and inconsistent calls to action.

The new system uses a gray-blue canvas, white working surfaces, mint guidance areas and navy navigation. Teal identifies primary actions and active navigation; amber is a small brand/active-state detail. Cards have restrained borders and shadows. Public pages use a separate masthead and a purposeful navy hero. Signed-in workspaces use a role-aware sidebar and compact account header. Real activity replaces placeholder dashboard content.

## Tokens

Tokens live in `src/styles/modern.css`, loaded after the existing accessibility and shared UI styles. `src/index.css` also maps the established Tailwind brand utilities to the new palette. Existing utility classes continue to work; new components use semantic variables.

| Purpose | Token | Light value |
| --- | --- | --- |
| Page canvas | `--ui-canvas` | `#EAF1F7` |
| Working surface | `--ui-surface` | `#FFFFFF` |
| Recessed surface | `--ui-subtle` | `#EEF3F8` |
| Decorative divider | `--ui-line` | `#DFE7EF` |
| Elevated card border | `--ui-card-border` | `#CADAE5` |
| Card heading surface | `--ui-card-header` | `#F2F8FB` |
| Input/secondary control boundary | `--ui-control-line` | `#7D8FA3` |
| Body text | `--ui-ink` | `#1B2B40` |
| Supporting text | `--ui-muted` | `#536579` |
| Heading / navy | `--ui-heading`, `--ui-navy` | `#142D4E` |
| Deep navigation | `--ui-deep` | `#10243D` |
| Links / contextual accent | `--ui-accent` | `#116C7A` |
| Primary action | `--ui-action` | `#167D8D` |
| Primary hover | `--ui-action-hover` | `#0F6661` |
| Mint surface | `--ui-mint` | `#EAF8F5` |
| Text on mint | `--ui-mint-ink` | `#145B57` |
| Amber detail | `--ui-amber` | `#F3B544` |
| Success text / surface | `--ui-positive`, `--ui-positive-bg` | `#176248` / `#E7F6EE` |
| Warning text / surface | `--ui-warning`, `--ui-warning-bg` | `#76530B` / `#FFF5DB` |
| Danger text / surface | `--ui-danger`, `--ui-danger-bg` | `#A3203D` / `#FFF0F2` |

Dark mode substitutes a deep canvas (`#0F1D2E`), blue working surfaces (`#1B2C40`), pale body text and mint links. High contrast uses black surfaces, white text/control boundaries and the existing yellow focus indicator. Gradients are removed in high contrast. Light mint and amber are never body text on white. Semantic text pairs are measured by the browser QA harness; this is not a complete rendered-page contrast audit.

## Typography and spacing

- Preserve the existing Inter/system fallback stack; no font download is introduced. The dyslexia-friendly Arial/Verdana preference still overrides it.
- Body size is `1rem`, with a 1.65 line height. Supporting text is generally `.875rem`; compact metadata retains visible labels and can be enlarged by browser or preference settings.
- Page titles use `clamp(1.7rem, 2.5vw, 2.3rem)`, strong weight and modest negative tracking. The public hero uses a larger responsive title.
- Section headings are typically `1.35rem`; metric values use `2rem`. Eyebrows describe context and never replace headings.
- Spacing follows approximately `.25`, `.5`, `.75`, `1`, `1.5`, `2` and `3rem` steps. Page gutters scale between `1rem` and `2.5rem`.
- Standard panels have a `1rem` radius; elevated cards use `--ui-card-radius: 1.125rem` (18px at normal text size). Welcome/auth/featured surfaces use `1.5rem`. Controls use roughly `.65rem` radii.
- Primary and secondary actions have a minimum `2.75rem` height and wrap. Focus uses the established three-pixel outline and contrasting outer ring.

## Shared components and rules

| Component / pattern | Role |
| --- | --- |
| `Layout` + `Navbar` | Public masthead or authenticated workspace, skip link, route focus, notices, footer and account controls |
| `Icon` | Small original inline SVG set; decorative, hidden from assistive technology; visible text labels identify actions |
| `PageHeader` | Eyebrow, one page title, supporting description, optional actions and icon |
| `AuthLayout` | Navy explanatory panel and a focused authentication form; stacks on mobile |
| `WorkspaceOverview` | Shared applicant/employer overview built from four existing authorized API responses |
| `JobCard` + `JobGrid` | Tinted heading, company identity, full description, mint support inset and prominent details footer; natural content height |
| `.surface-card` | Reusable elevated surface for recommendations, saved jobs, applications and employer management cards |
| `StatusBadge` | Written state plus semantic color; existing status values and meanings are retained |
| `EmptyState` / `RequestState` | Persistent explanations, loading, sanitized errors and retry actions |
| `ReviewDialog` / global modal | Existing confirmation, scroll containment and focus restoration |
| `.panel`, `.mint-panel`, `.sharing-summary` | Normal work, contextual help and explicit sharing boundaries |
| `.discussion-message`, `.request-history` | Visible sender labels, distinct message shapes and chronological event markers |
| Resume workspace | Saved-resume cards, grouped editor, saved preview/export and native reorder controls |

Use primary teal for the main next action. Use bordered secondary controls for navigation and supporting actions. Destructive controls retain a written label and danger styling. Disable controls only for existing busy/eligibility conditions; the redesign adds no new approval steps.

Company branding is existing authorized text/initials. No fictional logo, partner, testimonial, employment outcome, accessibility certification or business statistic is introduced. “Reviewed employer” describes account review and explicitly does not certify workplace support.

## Layout and responsive behavior

The desktop workspace has a `15.5rem` navy sidebar and a flexible content column. Sidebar links include icons and text, a written role label and a marked active route. Sidebar navigation can scroll independently when needed. At 64rem and below the workspace becomes a single column with an expandable inline menu. This is a disclosure, not a modal: it does not trap focus. Escape closes it and restores focus to its trigger. Navigation also closes it after selection.

Public navigation uses desktop links or the same named menu trigger. Account actions remain available on mobile. At narrow widths the brand and account row wrap instead of squeezing. The public search stacks. Job discovery uses a desktop filter rail and a mobile filter stack; no filter is removed. Signed-in jobseekers retain their workspace navigation on both search and job-detail pages; public visitors retain the public masthead.

Overview metric cards use four, two or one column. Long values, labels and user-entered content reflow. The Resume Builder retains a two-column editor/preview on sufficiently wide screens and stacks on smaller screens. The preview is the last saved document, not an invented live version. PDF typography remains independent of site themes and reading preferences.

## Privacy and data behavior

The redesign changes presentation and the login landing destinations: applicants now open `/applicant`, employers `/employer`, and administrators `/admin`. All previous routes remain.

Dashboard totals use the existing paginated API totals. “Interview-stage applications” is a status count, not a claim about future appointments. Employer “Open listings” is an availability count, not the number approved for public display. A failed individual API response shows **Unavailable**, preserves other metrics and can be retried. Empty recent activity has useful navigation; there is no fake chart or analytics series.

Professional profile content, optional accommodation preferences, private requests and resume snapshots retain separate existing consent. The UI does not fetch private profiles on request detail pages or store private data in local storage. Matching calculations, API contracts, backend permissions, moderation, queues and authentication are unchanged by this redesign.

## Review and maintenance

The final QA record is `PWDWORK_FINAL_QA_REPORT.md`; implementation history is `PWDWORK_IMPLEMENTATION_REPORT.md`. Synthetic before/after screenshots and matrix results are in the ignored `backend/storage/framework/cache/` directory. Before images come from the previous Phase 6C run, which followed the Windows default dark preference; the new run explicitly exercises light, dark and high contrast. They demonstrate structural changes, not a controlled same-theme benchmark.

Maintain semantic tokens instead of adding one-off colors. Keep field labels, status text, source caveats, sharing explanations and native buttons. Test any new component in all three themes, at 320px, and with enlarged text. Manual screen-reader use, complete keyboard-only journeys, actual browser zoom and device testing remain necessary; no WCAG certification or PDF/UA claim is made.

## Final card depth refinement

The Find Jobs surface now separates the page canvas (`#EAF1F7`) from cards using a stronger `#CADAE5` boundary, an 18px radius and two-level soft elevation:

```css
--ui-card-shadow: 0 8px 24px #142d4e1a, 0 2px 6px #142d4e0a;
--ui-card-hover-shadow: 0 14px 32px #142d4e29;
```

Dark mode uses a `#60778E` card boundary, `#21374B` heading surface and dark shadows. High contrast removes elevation and keeps white boundaries. Forced-colors mode uses Canvas/CanvasText and ButtonFace/LinkText system colors, with no shadow dependency. It leaves the browser's automatic forced-color handling enabled.

Job cards have five content groups: company/work labels and title; employer/review/location; full description; mint accommodation inset; and a full-width details action. Existing source fields and labels remain. Description line clamping was removed, and accommodation chips wrap without clipping. Each grid row sizes itself to its tallest card, and cards stretch only within that row. The flex footer uses `margin-top: auto`; content keeps its natural spacing and only the remaining space above the footer grows. No fixed heights or global equal-height rows are used. Footers retain consistent internal spacing and full-width actions. Cards are not clickable whole-card wrappers.

The old automatic margin above support information was removed. The header and accommodation area provide hierarchy through tinted surfaces rather than large blank regions. Recommended cards retain a secondary details action so their existing save/apply actions stay distinct. Existing recommendation information/actions and saved-job status/removal controls render before the shared footer, keeping details links at the bottom across these views too. Nested cards flex to fill their existing containers. Application, employer and overview cards reuse the border/shadow tokens while retaining their own layouts and status controls.

Pointer hover strengthens shadow and border without translating or scaling the card. Keyboard focus remains the established visible outline, with an additional focus-within boundary. Both the saved reduced-motion option and the system preference suppress transitions. Filter panels reuse the elevated surface, a teal heading boundary and selected-checkbox tint; individual filter options do not gain extra borders.

Fresh verification and before/after artifacts are recorded under the latest card-refinement section of `PWDWORK_FINAL_QA_REPORT.md`.
