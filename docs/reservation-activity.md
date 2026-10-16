# Phase 12B: Reservation Activity and Admin Reservation Logs

`ReservationActivityTimeline` is a read-only presentation/query service. No activity table, audit blob, backfill, event writer or migration is needed. Business workflows and the Resident request-tracking UI are unchanged.

| Internal type | Authoritative source | Time | Actor |
| --- | --- | --- | --- |
| submitted | reservations | created_at | owning Resident; historical snapshot name when available |
| status_changed | reservation_status_histories | changed_at | changed_by |
| qr_issued | qr_tickets | generated_at | System (no stored issuer) |
| document_finalized | reservation_documents | generated_at | generated_by |
| checked_in | checkin_logs, checked_in only | verified_at | verified_by |
| payment_recorded | reservation_payments | paid_at | recorded_by |

Reservation creation occurs inside successful wizard submission's transaction. Missing optional sources produce no invented events. Free/legacy fees do not produce payments; scans do not produce arrivals; previews and printing do not produce finalized-document events. Payment descriptions use the stored payment amount, never current service fees. Missing actor references show Former account. Context uses a saved Resident name when present; legacy requests fall back to the existing account name. Service names use the linked Service because there is no historical service-name snapshot. Search includes current and saved Resident names.

Each event retains an internal source table/primary key for traceability; source IDs, credentials, document paths and serialized records are not rendered. Admin processing notes remain escaped, accessible through a disclosure. Admin-only access uses existing authenticated, active, verified Admin middleware. There are no mutation routes or controls. Residents do not receive Admin names, notes, or integrity indicators from this feature.

Times are interpreted according to existing UTC storage and displayed in Asia/Manila without changing application timezone. The detail timeline sorts ascending by source timestamp, then workflow priority, source primary key and event type. Priorities: submission 10; initial Pending 15; Under Review 20; other outcomes 25; Approved 30; QR issuance 40; finalization 50; Ready for Pickup 60; check-in 70; payment 80; Completed 90. Global logs reverse this complete order. Equal timestamps therefore have stable ordering across pages. Unknown status labels are neutral; absent timestamps are excluded rather than reconstructed.

Completed is one status-history event. No separate QR Used event exists. The completion event says QR marked Used only when QR used_at and used_by match the completion history changed_at and changed_by (a known actor is required). Mismatched/unmatched QR usage shows an Admin integrity notice on the stored QR-issued activity and, where applicable, the completion activity. Missing completion history is not invented or repaired. Both-NULL actors cannot prove matching attribution.

Stored QR issuance time is used exactly. Pre-000022 historical timestamps may be uncertain; this read model never reconstructs them. The existing Phase 12A.1 issuance repair and its historical limitations remain applicable.

The global `/admin/reservation-logs` feed uses database UNION ALL, joined context/actors, deterministic SQL ordering and 15-event database pagination. Search supports request number (number, #number or Request #number), Resident and Service; optional type filtering selects only the relevant source branch. Query parameters persist through pagination. Detail queries constrain every source branch to the reservation, and joined actors avoid N+1 queries. No complete historical collection is fetched into PHP for global pagination. Offset pagination is stable for an unchanged dataset; new concurrent records can shift page boundaries. A UNION sort/count still grows with history volume; no Reports/Analytics infrastructure or new indexes are introduced in this phase.

No arrival, payment, status, QR, document or history is written by reading these screens. Operational actions stay in their existing sections. No log editing/deletion, repairs, exports, reports, notifications or IoT are implemented.
