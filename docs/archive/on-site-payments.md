# On-site payments (Phase 11B)

Payments are immutable, private reservation transaction records in `reservation_payments`. Each reservation has at most one payment (unique reservation_id), with restrictive reservation/Admin foreign keys, DECIMAL(10,2) amount, UTC paid_at, and recording Admin. There is no historical backfill.

Only an authenticated, active, verified Admin can record payment, with explicit on-site collection confirmation. The service locks and re-reads the reservation in a transaction. It requires Ready for Pickup, a positive known historical fee_snapshot, and no payment. Amount and audit fields are server-derived; client values are rejected. A unique constraint additionally protects repeated/concurrent requests.

Status is shared across Admin/Resident views: positive snapshot without payment is Unpaid; an exactly matching payment is Paid; zero without payment is No Payment Required; NULL without payment is Fee Not Recorded. An inconsistent payment surfaces an integrity issue and blocks another payment. Current Service prices never substitute for a historical snapshot. Residents can only view their own status, amount and localized payment time; recorder identity is Admin-only.

Payment model updates/deletions are rejected and no edit/delete endpoints exist. These application safeguards are not a substitute for database administrator access controls: direct SQL can bypass model events.

Dashboard collections use SQL SUM/COUNT on amount and paid_at, never schedule/reservation dates or current Service prices. Asia/Manila day/month boundaries are converted to UTC before querying. Inconsistent payments are excluded and an explicit integrity warning is displayed. Ready for Pickup Unpaid counts positive snapshots without a payment, excluding free and unknown fees. Queries are bounded independently of reservation volume.

Recording payment does not modify reservation status/history, QR, documents or certificate contents. Documents have no payment gate. No receipts, methods, references, partial payments, corrections, voids, refunds, exports or Reports module are implemented; these require separate approval.

## Phase 11C: payment before completion

For Ready for Pickup reservations, subject to the existing transition requirements:

| Historical fee / payment state | Completion eligibility |
|---|---|
| Positive fee + Unpaid | Cannot Complete. |
| Positive fee + valid matching Paid payment | Can Complete. |
| Explicit 0.00 / No Payment Required | Can Complete without a payment. |
| Legacy NULL / Fee Not Recorded | Retains legacy completion eligibility without a fabricated fee or payment. |
| Payment Integrity Issue | Cannot Complete. |

Blocked completion leaves reservation status, status history and QR usage unchanged. Recording payment does **not** automatically Complete the reservation; Admin must perform the separate completion action.

`ReservationPayments::completionBlockReason()` supplies the shared server/UI rule. The Admin status-update route is the only production completion writer. QR verification only resolves the reservation and redirects to its details; subsequent completion uses that same route. The controller checks the rule on a freshly locked reservation inside its existing transaction, before any completion writes. Payment recording also locks the reservation first; completion then locks the QR, so no inverse lock order is introduced. A competing payment either commits first and is visible to completion, or the unpaid completion rejects first. SQLite tests do not prove real MariaDB concurrency.

Admin processing hides the completion form when blocked, with a clear explanation; direct requests are still rejected by the server. Payment recording remains Ready for Pickup only and does not auto-complete. Completion retains atomic status/history/QR usage and does not rewrite PDFs or alter collection totals. No Phase 11C migration is needed.

Migration: `2026_10_16_000020_create_reservation_payments_table.php`. New table only; no existing data update. The controlled Phase 11B rollout applied it to MariaDB in batch 13 after backup and acceptance tests. Synthetic payment 1/reservations 9–11 remain in that development database; their accounts/service/schedule are inactive. Phase 11C QA uses isolated SQLite and does not modify those retained records. Never use migrate:fresh or db:wipe on real data. A rollback drops payment history and is not an approved correction workflow.
