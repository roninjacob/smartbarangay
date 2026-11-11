# Phase 3 certificate system fields

The existing field registry, designer, saved positions, protected source PDF, reservation snapshots and canonical preview pipeline supply the new fields. The phase uses the current schema. Local and production Resident records were preserved throughout implementation.

## Field contract

Existing internal identifiers and types are retained. Saved numeric field IDs, custom labels and coordinates remain attached to their templates. Full Name and Complete Address reuse `resident_name` and `resident_address`.

| Designer field | Internal identifier | Type | Authoritative value |
| --- | --- | --- | --- |
| Full Name | `resident_name` | text | Structured full-name formatter, falling back to legacy full name |
| First Name | `resident_first_name` | text | Structured first name, nullable for unsplit accounts |
| Middle Name | `resident_middle_name` | text | Structured middle name, optional |
| Last Name | `resident_last_name` | text | Structured last name, nullable for unsplit accounts |
| Suffix | `resident_suffix` | text | Structured suffix, optional |
| Date of Birth | `resident_birthdate` | date | Existing ResidentProfile birthdate in ISO format |
| Age at Submission | `resident_age` | number | Completed birthdays at the saved submission reference date |
| Contact Number | `resident_contact` | text | Recorded contact number |
| Civil Status | `resident_civil_status` | text | Existing optional profile value |
| Occupation | `resident_occupation` | text | Existing optional profile value |
| Complete Address | `resident_address` | text | Phase 2 canonical projection or original legacy address |
| House / Unit Number | `resident_house_unit_number` | text | Existing profile house/unit field |
| Street / Sitio / Purok | `resident_address_street` | text | Existing profile street field |
| Additional Zone / Purok | `resident_address_zone_purok` | text | Existing profile zone field |
| Barangay | `barangay_name` | text | Barangay Calayo |
| Municipality | `municipality_name` | text | Nasugbu |
| Province | `province_name` | text | Batangas |
| Document Date (ISO) | `date_issued` | date | Issue date as YYYY-MM-DD |
| Document Date (Full) | `document_date_full` | text | November 12, 2026 |
| Document Day | `document_day` | number | 12 |
| Document Day (Ordinal) | `document_day_ordinal` | text | 12th |
| Document Month | `document_month` | text | November |
| Document Month Number | `document_month_number` | number | 11, with January represented as 1 |
| Document Month Abbreviated | `document_month_abbreviated` | text | Nov |
| Document Year | `document_year` | number | 2026 |

The original ten identifiers are `resident_name`, `resident_address`, `resident_contact`, `date_issued`, `barangay_name`, `resident_birthdate`, `resident_civil_status`, `resident_occupation`, `resident_address_street` and `resident_address_zone_purok`. The registry contains 25 system fields after this phase.

Each field can be positioned independently using the existing toolbox and Save Layout flow. Samples come from a single server-generated example set with a common Philippine date. Required fields use the existing profile-completion messages and validation. Missing optional values render as empty PDF text. Resident-supplied system answers are rejected by the existing custom-answer allowlist.

## Snapshot, age and issue-date policy

Personal and address values are copied into the reservation snapshot under the existing final-submission User lock. Individual names are read only when the account has structured first and last names. Unsplit names are preserved in full, with unavailable components left null.

Age is captured with `resident_age_reference_date`, the Asia/Manila calendar date when the submission profile snapshot is taken. It remains fixed with that request. Birthdays advance age on the birthday. February 29 birthdays advance on March 1 during non-leap years. A missing, invalid or future birthdate yields unavailable age, and an age of zero remains a valid numeric value.

New requests carry the server-owned `_document_issue_policy = finalization_v1` marker inside the existing snapshot JSON. Submission forms display provisional date components from the submission date. Pre-finalization PDF previews use the current Asia/Manila date and save that date alongside the protected preview artifact. The artifact fingerprint includes the issue date even when its date fields have no printable positions.

Finalization captures a single UTC `generated_at` timestamp under the existing transaction. Its Philippine date must match the reviewed preview date. The exact reviewed PDF bytes are promoted to the generated file. Crossing local midnight requires a new preview and review. Token expiry, ownership, HEAD/GET review evidence, source hash, text-fit and saved field validation remain enforced. Date values recomputed at issuance must satisfy the saved field metadata, including numeric limits and maximum text lengths.

The generated document's existing `generated_at` stores the permanent issue timestamp. Every date component on the saved certificate comes from the same issue date. Reopening or downloading serves the existing private PDF bytes and content hash. Personal profile edits, later birthdays, template edits and future clock changes cannot alter that PDF.

Existing snapshots without the new policy marker keep their original `date_issued` semantics and stored values. Older finalized PDFs retain their original bytes. Older reservations receive no invented individual names, ages or date fields. Configuring new fields affects later requests through the existing template/version snapshot mechanism.

## Compatibility and limits

An existing custom field whose internal name now matches a new system identifier keeps its custom source, ID and answers. Its unchanged name remains editable. New custom fields cannot claim reserved system identifiers. To select the system version in a template with such a collision, an Admin must explicitly rename the custom question first. Existing request snapshots retain the original custom question and source.

Age means age at submission, as labelled in the toolbox. The issue-date policy for older requests remains historical. Missing required structured components require Resident profile completion and the existing residency reapproval process. Changes to an already submitted request or finalized certificate continue to follow the current historical-record safeguards.

## Safe local acceptance

1. Use an authorized local Admin and a synthetic service/PDF. Open its existing Document Builder. Check the personal, address, ISO/full-date and individual date-component toolbox choices.
2. Place Full Name, First Name, Age at Submission, Complete Address, Document Date (Full), plain Day, Ordinal Day, Month and Year in separate boxes. Save Layout and reopen. Verify field IDs, labels and positions persist.
3. As an approved synthetic Resident, complete the current reservation wizard. Confirm optional middle/suffix values can be empty. Configure either as required in a separate synthetic template to confirm the profile-completion message appears. A legacy resident keeps their original full name and unavailable components.
4. Approve the synthetic request through the existing Admin process. Open its personalized PDF preview and inspect every page. Review the protected PDF and finalize on the displayed Philippine date. A previous-date preview must be reviewed again.
5. Reopen the saved certificate and compare bytes/hash after changing the synthetic profile. Verify the original name, reference age and saved date remain. Check the existing QR verification and explicit check-in process independently.
6. Keep clock-boundary and malformed/tampered cases in isolated automated tests. Avoid changing the laptop clock or real reservations to simulate them.

The phase requires matching application files and compiled Vite assets. Hosting deployment remains an owner-managed checkpoint. The existing database schema supports the implementation.

## Validation results

Validation on November 12, 2026 passed the full PHP suite with 1,365 tests and 13,381 assertions. The focused system-field/designer suite passed 82 tests with 556 assertions. The new field feature/unit classes contribute 61 regression cases, including all 31 ordinal days, birthday/leap-day cases, calendar boundaries, timezone conversion, mapping persistence, legacy compatibility, date tampering, access isolation and exact-byte finalization.

All 56 JavaScript tests, the Vite production build, targeted Pint, PHP syntax checks for the eleven changed/new PHP files and `git diff --check` passed. Browser-based visual placement and physical print inspection remain manual acceptance steps for the owner. Tests use synthetic PDFs and isolated SQLite, and the full suite includes existing reservation, authorization, certificate, QR and IoT regressions.

## Exact file manifest

### Added

- `app/Services/DocumentSystemFields.php`
- `tests/Unit/DocumentSystemFieldsTest.php`
- `tests/Feature/CertificateSystemFieldsTest.php`
- `docs/certificate-system-fields.md`

### Modified

- `app/Models/DocumentField.php`
- `app/Services/ReservationDocumentInformation.php`
- `app/Services/ReservationDocumentPreview.php`
- `app/Services/CanonicalDocumentPreview.php`
- `app/Services/GeneratedReservationDocuments.php`
- `app/Http/Controllers/Admin/DocumentLayoutController.php`
- `app/Http/Requests/Admin/SaveDocumentFieldRequest.php`
- `resources/js/document-designer.js`
- `resources/views/admin/document-builder/field.blade.php`
- `resources/views/reservations/document-preview.blade.php`
- `resources/views/reservations/generated-document.blade.php`
- `resources/views/resident/reservations/document-information.blade.php`
- `tests/Feature/DocumentDesignerTest.php`
