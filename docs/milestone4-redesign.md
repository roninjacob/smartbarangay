# Milestone 4: approved civic UI redesign

## Implementation

The approved prototype is integrated into the real Blade application, using Bootstrap 5 and a shared presentation layer. The source of the visual system is `resources/css/civic-redesign.css`: typography, colors, surfaces, spacing, buttons, fields, tables, status badges, dialogs and responsive rules. It extends existing components instead of replacing controllers or introducing a dashboard framework. Light and dark tokens are separate. No external font, animation package or new framework is required.

The new home/doorway/checkmark logo is an ordinary community-service identity, not an official seal. Its reusable SVG is `public/images/smartbarangay-mark.svg`; the Blade component is used by public, authentication and authenticated branding, with the same mark as favicon.

Public landing has the approved three-line hero and softer journey/service sections. All authentication forms inherit the new typography, field and button treatments. Registration requirements, verification and account protection remain unchanged.

The shared Admin/Resident shell has updated navigation, panels, tables, inputs, alerts, profile cards, settings, notifications and responsive treatment. Admin reports are grouped under Reports without changing routes. Admin Reservation Details has section jump links for information, payment, QR, certificate when present and processing; these are navigation only.

Resident screens include an existing-route bottom navigation at widths below 992px, a prominent real request action and readable recent-request cards with actual historical appointment data and status-specific explanations. It does not invent schedules, counts or processing promises. The full sidebar remains available via the existing offcanvas menu. Main content has bottom padding so the navigation does not cover the last controls. Modal and offcanvas layers remain above the bottom navigation.

The existing Bootstrap dialogs, focus traps, form-submission protections and QR success timing are preserved. Feedback animation is short; reduced-motion disables decorative motion. New JavaScript focuses an existing wizard validation summary, supplies keyboard-scroll regions for comparison tables, and auto-submits only the marked GET worklist filter when its status selection changes. It does not mutate business state.

## Boundaries

No routes, business services, migrations, database configuration, transaction rules or document-renderer algorithms changed. The ReservationController list action now adds one bounded read-only aggregate for the approved worklist overview; status writers remain unchanged. The redesign is scoped to `body.civic-ui`; standalone certificate print pages do not receive that class. PDF canvas geometry, document positions and @page rules were not changed. Generated-only printing, review-before-finalization, ownership, payment gates, check-in and QR consumption remain authoritative in their existing code.

Core modules share the new design rather than using unrelated page templates. Specialized controls such as the designer, report graphs and scanner retain their established behavior; surface/toolbar styles are updated without replacing their algorithms.

## Validation approach

Feature tests add Resident quick-navigation role boundaries, Admin report destinations and public registration/branding checks, including no business-record creation when viewing. Existing presentation assertions were adjusted for the approved headline, human-readable AM/PM times and the two navigation landmarks (one current destination each). Authorization, ownership and read-only assertions remain in place.

Browser QA uses the actual application and production build on loopback port 8019, with separate SQLite, sessions, private files, cache and an environment-bound synthetic empty reporting manifest outside the repository. Synthetic users/services/requests/PDFs are used; the real database and real reporting manifest are untouched. The QA harness initially needed its own persistent cache for cross-request print tokens; only that external harness was adjusted.

The navigation matrix covers 9 Resident/public destinations and 15 Admin/public destinations at 320/390/768/1440px. Six additional processing/requirements/configuration/designer screens are checked at those widths. Another pass covers all 15 Admin destinations at 390px with dark appearance saved only on the synthetic account. No page-level horizontal overflow was found. Wide comparison tables scroll in their own regions. Long synthetic service names are included.

Actual PDF.js designer loading and protected generated-certificate printing were checked. The print button becomes enabled and the standalone print body remains outside redesign scope. No native printer-dialog or physical print test is claimed. Camera permission/hardware scanning and a formal assistive-technology or exhaustive contrast audit are not claimed; existing scanner JavaScript tests are run.

QA screenshots and detailed matrices remain outside Git under `C:/Users/ronin/.codex/tmp/m4-live-qa`. The original concept prototype on port 8018 is separate and is not deployed as application code.

## Deployment/checkpoint

No migration or live-data setup is required for this UI change. Build with `npm run build` for production; development can continue with the existing Vite server. Clear stale browser assets with a reload if needed. Review the intended diff before the user's normal commit/push. Do not include private QA files, credentials, SQL backups, .env or AGENTS.md.

The previously identified active Indigency configuration and demographic placement setup remain operational tasks requiring explicit Admin review. This redesign does not repair/reconfigure official sources or rewrite historical requests.
## Final checkpoint results

- Full isolated PHP suite: 1,070 tests / 10,747 assertions passed.
- Focused UI/authentication/dashboard/reservation rerun: 72 tests / 634 assertions passed.
- JavaScript: 19 tests passed; civic-interface syntax check passed.
- Production Vite build, targeted Pint, route listing and git diff --check passed.
- 135 browser layout checks recorded: 36 Resident/public, 60 Admin/public, 24 detail/document tools and 15 dark Admin checks. No page-level horizontal overflow in that matrix.
- Actual modal, protected PDF designer rendering and generated-only print controls inspected with synthetic data. Final screenshot recapture was interrupted when the browser QA connection became unavailable; previously saved Admin screenshot and layout matrix remain available. This does not claim a physical-device, hardware-camera or printer acceptance test.

Branch: feature/milestone4-redesign. Base: d950632. No commit/push, migration, real account/profile/configuration changes or real transaction fixtures performed. Ready for user visual review; final aesthetic acceptance belongs to the user before the Git checkpoint.
## Reservation Management reference correction

The user requested the specific prototype section rather than shared styling alone. The actual list now has three live operational cards, a Resident-first avatar/name/reference column, separate Service and Appointment columns, soft status badges, an action column and the reference's compact search/status/clear bar. The overview is explicitly independent of table filters. It does not display sample counts or demo residents.

Needs review = current Pending + Under Review. Ready for pickup = current Ready for Pickup. Completed today = distinct currently Completed reservations with an actual Completed history event within today's Asia/Manila date, using half-open UTC bounds. It never infers completion from updated_at; legacy rows without time evidence are not fabricated. Repeated history rows cannot multiply the count. This is an operational overview over all records, not a reporting endpoint; existing reporting exclusions and dashboard totals are unchanged.

Search continues to use existing validated GET parameters; Enter/search icon applies the filter, and changing status submits only that explicitly marked GET form. Clear resets filters. Native no-JavaScript submission remains supported. Pagination remains database-backed with preserved filters. Avatar images use the existing authorized serving endpoint; the optional two-initial fallback does not change other avatar consumers.

The table scrolls within its region at intermediate widths and becomes labeled stacked records on phones. Real localhost browser checks passed at 1440/768/390/320px, including status auto-submit, zero-match search and Clear filters. No real transaction data was modified. Added isolated tests cover Manila midnight boundaries, duplicate history events, legacy missing history, filter independence, data preservation and the bounded query count.
Reference-correction validation: 34 focused tests / 476 assertions passed; full isolated suite 1,071 tests / 10,764 assertions passed; all 19 JS tests, production build, targeted Pint and diff check passed. Actual operational counts were displayed without copying demo values. Existing live records were viewed/filtered only, with no reservation/status/payment/QR changes, no migration and no commit/push. Screenshot: C:/Users/ronin/.codex/tmp/m4-worklist-reference-match.png (outside Git).