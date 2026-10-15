# Certificate printing — Phase 10E

SmartBarangay keeps two separate sources. The action's reservation context determines the source, regardless of which page contains the action.

## Standalone service tools

**Preview Blank Template**, **Print Blank Template**, and **Download Blank PDF** use the original official service PDF for manual/contingency use. They do not require a reservation or a generated certificate. The master is never personalized or modified by these actions.

The existing private-path, extension, detected MIME, PDF structure, and 10 MB upload checks remain. Replacing/removing a master invalidates its old standalone print links.

## Reservation certificates

Every reservation-specific **Print Certificate** action uses that reservation's existing saved personalized PDF. This includes Reservation Details and request rows in Services & Documents.

There is no blank-master fallback for dynamic, legacy, manual, or incomplete reservations. Admins needing a blank copy use the standalone service tools instead.

- **Preview Document** is a temporary historical-data overlay, not an official print source.
- **Generate Certificate** explicitly invokes Phase 10D after approval, when the original source and complete snapshots support generation. Viewing the preview is optional.
- **Preview Generated Document**, **Download**, and **Print Certificate** read the saved output without generating or overwriting anything.

Admin reservation printing remains limited to **Approved** and **Ready for Pickup**. Completed is not added. Existing Resident owner-only preview/download access, including historical files, retains its existing rules.

If no generated record exists, the print entry returns Admin to Reservation Details with a clear message. If a record exists but the PDF path, MIME, size, hash metadata, file, or content hash is invalid, private retrieval fails safely. It never substitutes the master or repairs metadata. List actions use lightweight metadata/existence/size hints rather than reading and hashing every PDF; authoritative access always rechecks the file, so a subsequently changed file can still be refused.

## Private print links

Print links belong to the requesting Admin and expire after **10 minutes**. Authentication, active account, verified email, and Admin role remain required. Print-page and PDF requests revalidate ownership and current reservation eligibility. Generated links also revalidate the current document identity, size, safe reservation-owned path, and content hash. Generated output remains independent of subsequent master replacement/removal.

Any old master print artifact associated with a reservation is rejected at both endpoints with **410 Gone** and an instruction to reopen Reservation Details. It is never retargeted to different bytes. Valid standalone master tokens remain supported. No global cache/session flush is performed. Expiration blocks new server retrieval; it does not revoke bytes already loaded into a browser.

## Browser printing and lifecycle

The common PDF.js/browser print page remains in use. It prepares all pages as canvases at scale 2, applies each page's dimensions/orientation through named print-page rules, hides application controls in print media, and enables Print only after all pages are prepared. It does not force A4/Letter. This is browser canvas printing, not a claim of native vector printing or physical-printer fidelity.

Printing, cancelling the dialog, previewing, and downloading do not change reservation status/history, QR used_at/used_by, check-in records, generated-file count, or document metadata. Closing the dialog is not recorded as physical printing success.

Ready for Pickup remains a manual transition. The existing confirmation and reason requirement for requests without a prepared-document indicator remain; this phase does not impose a universal generated-PDF status gate. Historical DOCX preparation indicators remain read-only compatibility data, not printable reservation certificates.

## Limits and follow-up work

- Old/incomplete requests may lack snapshots needed for generation. Do not fabricate them from today's profile/layout.
- Ungenerated requests can lose their historical source when the master is replaced; template archiving is outside 10E.
- Regeneration/versioning remains unimplemented; existing certificates are not overwritten.
- F03 stale-configuration selection and F04 designer/final-renderer typography differences remain follow-up findings.
- No fees/payment gates, print counters/reprint limits, release tracking, batch printing, check-in, IoT, dependency changes, or schema changes are introduced.

Existing routes are retained: service preview/download/print, reservation certificate print, generated preview/download, and the common token print page/PDF endpoints.
