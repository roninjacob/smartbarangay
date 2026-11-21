# In-app notifications — Phase 13

Notification history begins at activation. There is no historical backfill.
This is database-only communication for Resident and Admin. No mail channel,
queue worker, polling, WebSockets, push, SMS, preferences or deletion is involved.
Existing authentication email verification/password reset remains separate.

## Authoritative policy

`ReservationNotifications` is the explicit allowlist, recipient resolver and
historical message factory. Unknown events do nothing. Production writers call
it inside their existing database transactions after authoritative persistence.
There are no model observers, GET producers or browser-supplied recipients/types.

| Authoritative event | Resident title / purpose | Eligible Admin title / purpose | Target |
| --- | --- | --- | --- |
| Reservation submitted | Request Submitted / confirmation | New Reservation / action required | Role's reservation details |
| Pending → Under Review | Request Under Review / status update | None | Own reservation |
| Under Review → Approved | Request Approved / success | None | Own reservation |
| Approved → Ready for Pickup | Ready for Pickup / action required | None | Own reservation |
| Real payment persisted | Payment Recorded / payment | None | Own reservation |
| Ready for Pickup → Completed | Request Completed / completion | None | Own reservation |
| Rejected | Request Rejected / important status | None | Own reservation |
| Resident cancels Pending | Request Cancelled / confirmation | Reservation Cancelled / operational update | Role's reservation details |

Resident messages address the request owner. Admin submission/cancellation messages
name the Resident and Service and describe the Resident-originated action. Admin
recipients must currently have role Admin, be active and email verified. All eligible
Admins receive their own record; zero eligible Admins does not prevent submission.
Admin status processing/payment never produces Admin self-workflow messages.

The small JSON payload contains title, message, category, reservation ID, event-time
Service label and, for Admin messages only, Resident display label. Messages are
frozen when stored, not recomputed from current profile/service/status/fee values.
Payment uses the persisted payment amount. Rejection uses generic wording; internal
processing notes and cancellation reasons are not copied. No QR credentials, private
paths, form answers, attachments, secrets, HTML or arbitrary URLs are projected.

## Persistence, transaction and replay behavior

One Laravel-native `notifications` table and `DatabaseNotification` model use the
existing User `Notifiable` relation. The central service writes through that relation
directly, avoiding a separate delivery channel or queue. UUID primary key; polymorphic
recipient uses the existing unsigned bigint user ID. `event_key` extends Laravel's
standard columns and has a unique recipient/type/ID/event constraint. Submission keys
use reservation ID, status/cancellation keys use status-history ID, payment keys use
payment ID. No text matching. `firstOrCreate` preserves original text/read state on
replay; uniqueness is also enforced by the database. Existing row locks and lifecycle
guards prevent repeated POSTs from creating new authoritative events.

Notifications and all recipient fan-out use the same synchronous transaction as
submission/status/history/approval QR/payment/cancellation. Any persistence error
rolls back both business event and notifications; errors are not swallowed. Partial
fan-out cannot commit. No after-commit external delivery or queue is required.

The forward migration `2026_10_17_000023_create_notifications_table.php` adds only
this table. Standard text JSON storage, nullable read timestamp and normal timestamps
support MariaDB 10.4.32 and SQLite. Composite indexes cover recipient history ordering
and unread filtering/counting. Polymorphic Laravel recipients have no foreign key;
reservation references live in the safe JSON payload, so unavailable targets fail
gracefully without deleting notification history. `down()` drops only this new table.
Real MariaDB migration is **not run during implementation**. Test migrations use
isolated SQLite only. No application business table is altered or backfilled.

## Read and access behavior

Both role-protected route groups require authentication, active account, verified
email and correct role; wrong-role access retains 403. All notification lookup and
mutation scopes through the current User relation. CSRF-protected POST actions:
Open marks that owned notification read and derives the role's internal details
route; Mark as read affects only that record; Mark all as read updates only the
current user's unread rows, with zero unread a safe no-op. No mark-unread/delete.

Open accepts no client target or reservation ID. It allowlists the stored notification
type for the current role, validates its integer reservation ID and checks current
reservation ownership for Residents before redirecting. Normal target middleware and
controller access checks apply again. Missing/inaccessible targets return to the
notification page with a safe message; no foreign request detail is revealed.

All GETs are read-only: bell rendering/opening, dashboard, history, All/Unread,
pagination and reservation views do not mark anything read. Read history stays visible.
Bell uses SQL unread count, displays 99+ above 99, and fetches only latest five rows.
History uses SQL filtering, 15-row pagination and descending created_at/UUID ordering
for deterministic ties. There is no per-item reservation/profile query and no polling.
Times are displayed explicitly in Asia/Manila without changing global timezone.
Bootstrap dropdown supports keyboard interaction; icons plus explicit Read/Unread
text avoid color-only meaning. Escaped Blade text and responsive wrapping protect
long labels and mobile layouts.

## Silent events and preserved boundaries

Login/logout, every GET/page view/dashboard/My Reservations/details, bell/history/
filter/pagination, Open/read/read-all, QR scans/verification (including invalid scans),
Check-In, preview, finalization, generated-file view/print/download, blank-template
preview/print/download, validation failure, rejected/duplicate booking, failed status
transition/approval/payment/completion/cancellation, duplicate payment and repeated
cancellation produce **no notification**. No recursive read notifications.

Check-In remains a separate persisted arrival, not QR use or a completion prerequisite.
Notification actions do not enter Reservation Activity Timeline. Payment remains
separate from completion; positive unpaid/integrity issues still block completion.
QR is consumed only by the existing Completed transaction. Document preview/final
byte identity, generated-only printing, immutable history and reservation capacity/
duplicate/status rules are unchanged.

Future email/SMS/push/realtime delivery, preferences, retention/deletion, historical
backfill, announcements, reports and hardware integration require separate approval.
Rollout: review → real database backup → inspect migration → explicitly authorized
normal forward migration → localhost smoke test → acceptance → user Git checkpoint.
