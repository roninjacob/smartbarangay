# Settings and appearance (Phase 15)

The modern public landing page remains available at `/` for guests and signed-in
users. It lists active service names/descriptions without fees, private files or
resident data, paginates at 12 services, and links to role-authorized entry points.
Choosing a service still happens inside the existing reservation wizard. Contact
details are not invented. Public content is now maintained in the Blade page;
there is no public-content administration UI or public settings table.

## Account settings

Both active, verified roles have a Settings sidebar entry:

- Admin: `GET /admin/settings`, `PUT /admin/settings`
- Resident: `GET /resident/settings`, `PUT /resident/settings`

The shared page has Appearance and About sections. Only Admin sees the reserved
System controls section. That section has no editable switches, submission fields
or operational behavior; future controls require separate authorization.

`users.theme_preference` is a five-character string defaulting to `light`.
Only exact `light` and `dark` values are accepted by the update endpoint. The
controller derives the account from authentication, rechecks eligibility under a
row lock, and writes only this preference. Extra identity, privilege or system
fields are rejected. The column is not generally mass assignable.

The switch previews Bootstrap's `data-bs-theme` immediately. **Save appearance**
persists it to the database; unsaved previews do not persist. Returning through
browser history restores the rendered saved preference. Authenticated pages
render the saved theme on the root HTML element before CSS loads, so account
preferences survive logout/login and other browser sessions. Guests use light
mode; no shared-browser localStorage preference leaks between accounts.

Custom civic styles complement Bootstrap dark components and honor reduced
motion. PDF documents and canvases are not inverted or modified. Theme changes
do not affect reservation states, QR lifecycle, payments, schedules or printing.

## Selective local migration pivot

The uncommitted public settings migration was rolled back by exact path and
batch after a verified database backup. Its table was empty. The corresponding
model, service, request, Admin controller/view, JavaScript and feature tests were
removed. The public landing page, styling and authenticated `/` behavior remain.

Replacement forward migration:
`2026_10_24_000024_add_theme_preference_to_users_table.php`.
It adds only the account preference, with existing users defaulting to light.
Existing business-column fingerprints across 18 tables matched before and after
both operations. This rollback is a local correction of an uncommitted migration,
not an instruction to roll back deployed history elsewhere.

The database backup excludes private storage and environment configuration; no
restore rehearsal was performed. No automatic Git checkpoint is performed.

## Validation checkpoint

- 28 focused preference/public-page tests: 184 assertions.
- Full regression: 1,017 PHP tests, 10,262 assertions.
- 19 existing JavaScript tests, Vite build, targeted Pint, routes and diff checks passed.
- Admin browser light/dark preview and settings layout checked at 1440, 768,
  390 and 320 pixels without page-level horizontal overflow. Reload discards
  the unsaved preview correctly; the real account preference was not changed.
- Resident access, navigation, saving and logout/login persistence covered by
  isolated feature tests; no real Resident login was used for browser QA.
- The preserved public page rendered with authenticated role-aware actions.
- Backup directory and direct SQL URL remained denied with HTTP 403.
