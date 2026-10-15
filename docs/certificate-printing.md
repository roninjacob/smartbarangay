# Certificate printing — Phase 10E

SmartBarangay keeps two separate sources. The action's reservation context determines the source, regardless of which page contains the action.

## Standalone service tools

**Preview Blank Template**, **Print Blank Template**, and **Download Blank PDF** use the original official service PDF for manual/contingency use. They do not require a reservation or a generated certificate. The master is never personalized or modified by these actions.

The existing private-path, extension, detected MIME, PDF structure, and 10 MB upload checks remain. Replacing/removing a master invalidates its old standalone print links.

## Reservation certificates

Every reservation-specific **Print Certificate** action uses that reservation's existing saved personalized PDF. This includes Reservation Details and request rows in Services & Documents.

There is no blank-master fallback for dynamic, legacy, manual, or incomplete reservations. Admins needing a blank copy use the standalone service tools instead.

- **Preview Document** creates a private canonical personalized PDF from historical inputs. It is temporary, not a finalized certificate.
- **Finalize Certificate** is explicit, limited to Approved / Ready for Pickup, and requires a current owner-bound preview token whose PDF has been served for review. It copies the exact reviewed bytes. Preview is now required before finalization.
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

## Current configuration and historical source

A configuration references a particular current master identity/revision and its SHA-256 content hash. Active configuration selection checks that relationship, PDF validity and size, field availability, and all stored placements. A replaced, missing, corrupt, or same-path modified source is unavailable for new dynamic requests. The final wizard submission repeats checks under the Service lock; configuration changes use that same lock. Its draft signature includes field definitions and saved placements. A stale draft is rejected without creating a reservation or retaining uploads/source copies.

Admin Configuration shows readiness and **Reconfigure for Current Master**. Reconfiguration explicitly retains field definitions, clears old placements, binds the selected current source, increments version, and leaves configuration inactive for review. It does not change historical reservations. Ordinary inactive/missing configurations retain the existing non-dynamic/manual reservation flow; inactive configurations are not used to collect dynamic information. A stale source is rejected even when the stale configuration was inactive. Unplaced fields remain supported as non-printing workflow information; a personalized certificate needs at least one valid printable placement.

The forward migration `2026_10_15_000018_add_source_content_hash_to_document_templates.php` adds only nullable `source_content_hash`. It does not backfill historical assertions. Pre-migration configurations without this evidence require explicit reconfiguration before dynamic use. Apply with `php artisan migrate` after review; implementation tests run it only in isolated SQLite.

Each newly submitted dynamic reservation copies its validated original master to a random private `reservation-sources/{reservation}/{random}.pdf` file. The existing template snapshot JSON holds service/master identity, original revision, private copy reference, MIME, size and SHA-256 alongside configuration identity/version. Snapshot answers, Resident values, labels and placements remain historical. On transaction failure, the new source joins attachment cleanup. A final document links the current master row only if identity AND revision still match; otherwise its nullable master foreign key stays null and the historical snapshot/source metadata supplies provenance. Master replacement/removal only cleans the service master; it cannot remove reservation-owned source copies. This deliberately favors simplicity over deduplication (up to the existing 10 MB master limit per dynamic request).

Historical rendering never substitutes a new master. Preserved-source access validates reservation-owned path, service identity, expected revision, size, SHA-256 and PDF structure. A source entry that is missing, corrupt or points elsewhere fails closed. Legacy requests without preserved metadata are supported only when the original revision still matches and the same configuration identity/version has a stored content hash proving the source. Otherwise Admin receives safe unavailable guidance. Missing original files/evidence cannot be reconstructed from current profiles or templates. No automatic real-record backfill or repair is performed.

## Canonical preview and finalization

`FinalCertificatePdf` is the sole personalized PDF renderer. `ReservationDocumentPreview` resolves historical inputs; it no longer relies on HTML text overlays. The renderer embeds DejaVu and handles all page dimensions/orientations, fitting, wrapping, alignment and placements. The minimum configured/fitted size is 4pt, consistent with the designer. The designer's optional browser sample remains explicitly illustrative; the reservation PDF Preview is the authoritative visual review.

A preview creates a random private `document-previews/{reservation}/{token}.pdf` file with 30-minute cache metadata tied to the requesting account and reservation. A SHA-256 fingerprint covers renderer policy/version, reservation/service identity, historical Resident/template snapshots, resolved source integrity metadata and printable placement/text inputs. Unrelated timestamps/current profile/current layout are excluded. Bump `CanonicalDocumentPreview::RENDERER` when rendering policy changes.

The PDF endpoint requires normal account/role/ownership middleware plus the preview token. It rechecks inputs, source, fingerprint and artifact hash, then marks that PDF served for review. Merely opening the HTML does not authorize finalization. Stale/expired/wrong-owner/wrong-reservation tokens receive a safe 410 response. Finalization checks eligibility and existing output under Service then Reservation locks, revalidates the token and authoritative inputs, and copies those exact reviewed bytes into permanent private storage with normal hash/size/generator/time metadata. It does not independently rerender. Changed input requires opening/reviewing a fresh preview; it never silently generates different bytes from a stale token.

Generated output remains immutable and independent of later source/configuration/profile changes. A repeated finalization fails without overwrite. A temporary artifact is not a generated document and does not satisfy prepared-document status indicators. Generated preview/download/print always retrieve the saved final output. No document action changes status/history, QR usage, payment, check-in or date_issued semantics. The latter remains its existing submission snapshot policy, not issuance/printing time.

Temporary cleanup: `php artisan smartbarangay:cleanup-document-previews` removes only safely named preview files older than one day, skipping any artifact whose cached token is still valid. Hourly scheduler registration exists; production still needs a real scheduler. Historical sources and generated files are never swept by this command. No queue is added. File/cache storage must be shared appropriately if deployment later uses multiple app servers.

## Layout validity

`DocumentLayout` validates the complete layout against the actual PDF page count/dimensions on designer save, field-position edits, current configuration selection and canonical rendering. Coordinates normalize to three decimal places (integer thousandths, matching storage). Same-page rectangles conflict only with positive-width AND positive-height intersection. Exact edge/corner contact is allowed; different pages do not conflict. Invalid dimensions, typography settings, page indexes and out-of-page coordinates are rejected. Client feedback warns and blocks an overlapping layout save; server validation remains authoritative. Existing invalid historical layout snapshots are not rewritten and cannot produce a misleading preview.

## Security, performance and remaining boundaries

Historical sources and preview artifacts stay on the private local disk; no public source endpoint is introduced. Generated-document authorization remains unchanged, including Resident owner-only historical access. Temporary tokens are opaque, account-bound, reservation-bound and expire. Content checks remain authoritative at action boundaries. Source/layout validation and PDF rendering are done on configuration, wizard or individual document actions, not reservation list rows. Existing lightweight generated-file hints remain hints only.

Legacy evidence/source loss cannot be automatically repaired. Finalized correction/regeneration/versioning remains out of scope. Browser printing still uses the saved PDF via PDF.js canvases at scale 2; identical source bytes do not guarantee printer hardware, scaling or color fidelity. Native print-dialog and physical output require separate manual verification.

No fees/payment gates, print counters/reprint limits, release tracking, batch printing, check-in, IoT or dependency migration are introduced. Phase 10 is ready for a separate review-only audit only after validation, not automatically accepted/closed.

Existing web routes are retained: blank service preview/download/print, historical canonical preview page/PDF, explicit generated-document POST, saved preview/download, reservation print, and common token print page/PDF. The existing configuration PATCH also accepts explicit reconfiguration. No new public web endpoint is added.
