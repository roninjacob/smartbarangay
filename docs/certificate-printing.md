# Official certificate printing

Official certificates accept PDF only (maximum 10 MB). Extension, detected MIME, PDF structure, catalog and pages are validated server-side. Password-protected or malformed PDFs must be exported again as readable PDFs.

Admin uploads the official Service PDF, previews it, and selects Print Certificate. The protected print page displays that exact PDF with full-page portrait/landscape and multi-page support. Print opens the browser print dialog. No conversion, personalization or LibreOffice dependency exists.

Approved reservation details show the same official Service PDF and Print Certificate. Earlier statuses cannot print. Printing creates no generated document and changes no status, attachments, QR tickets or history.

Ready for Pickup remains manual. Viewing/printing cannot prove physical preparation. Without a historical preparation indicator, the warning requires a reason saved to status history; manually prepared certificates can continue.

PDFs remain on private local storage. Admin access requires authentication, active account and verified email. Print snapshots belong to the requesting Admin, expire after 10 minutes and invalidate when the Service PDF changes or the reservation ceases to be Approved.

Historical Service files and reservation document records/files are preserved. Old non-PDF Service files cannot preview, download or print through the active workflow; upload a PDF replacement explicitly. Existing migrations, model relationships and historical preparation indicators remain for data integrity. No automatic historical deletion or schema reset occurs.

Dependency: smalot/pdfparser validates readable PDF structure. PHPWord and its math dependency were removed. No operating-system conversion software is required.

## Refactor file inventory

Created:
- `app/Services/CertificatePreparationState.php` — historical preparation indicator only.
- `tests/Support/CertificatePdf.php` — valid PDF fixture with a real page tree and cross-reference table.

Modified:
- `app/Http/Controllers/Admin/ReservationController.php`
- `app/Http/Controllers/Admin/ReservationDocumentController.php`
- `app/Http/Controllers/Admin/ServiceDocumentTemplateController.php`
- `app/Http/Controllers/Admin/ServiceCertificateController.php`
- `app/Http/Requests/Admin/SaveServiceRequest.php`
- `app/Models/ServiceDocumentTemplate.php`
- `app/Rules/OfficialDocumentTemplate.php`
- `app/Services/CertificatePreview.php`
- `app/Services/CertificatePrintRenderer.php`
- `app/Services/ServiceDocumentTemplates.php`
- `routes/web.php`
- `composer.json`, `composer.lock`, `.env.example`
- `resources/js/reservations.js`, `resources/css/services.css`
- `resources/views/admin/reservations/document-preparation.blade.php`
- `resources/views/admin/reservations/show.blade.php`
- `resources/views/admin/services/form.blade.php`
- `resources/views/admin/services/index.blade.php`
- `resources/views/admin/services/certificates.blade.php`
- `resources/views/admin/services/document-preview.blade.php`
- `tests/Feature/ServiceCertificateTest.php`
- `tests/Feature/ServiceDocumentTemplateTest.php`
- `docs/certificate-printing.md`

Deleted obsolete active workflow files:
- `app/Services/DocumentMergeFields.php`
- `app/Services/DocxTemplateRenderer.php`
- `app/Services/ReservationDocumentPreparer.php`
- `app/Http/Requests/Admin/PrepareCertificatesRequest.php`
- `resources/views/admin/services/merge-fields.blade.php`
- `resources/views/admin/reservations/print.blade.php`
- `config/certificates.php`
- `tests/Support/FakeCertificatePrintRenderer.php`
- `tests/Feature/ReservationDocumentTest.php` — superseded by PDF workflow tests in `ServiceCertificateTest`.

Routes: reservation printing is now `GET /admin/reservations/{reservation}/certificate/print` (existing route name retained). Removed reservation document generation/download/Quick Preview/print-ready routes and batch generation POST. Kept protected Service preview/download/print and the common token print page/PDF routes.

Migrations: none. The historical document table and relationships remain necessary to preserve existing transaction records and the preparation indicator; no historical rows/files were automatically removed.
