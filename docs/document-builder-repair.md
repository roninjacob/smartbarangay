# Document Builder loading and saving review

## Evidence and root-cause boundary

The reported message comes from a catch-all browser failure handler. It cannot establish whether upload, storage, PDF delivery, worker loading or rendering failed.

The supplied `Barangay_Clearance_Template (1).pdf` was inspected read-only. It is a 1,601-byte, one-page A4 PDF. MIME detection, existing official-PDF validation and FPDI page import all succeed. Poppler rendered the blank template, with local font-substitution warnings. PDF.js rendered it inside the actual builder without captured browser warnings/errors.

A separate synthetic two-page PDF also loaded before the changes, and its Previous/Next navigation works. The provided PDF was copied into isolated private QA storage using the existing upload service. A Resident Name placement was saved, then the editor was refreshed and the same placement/version reappeared. These observations establish local format compatibility and local persistence. They do not identify the hosted failure.

With matching deployed PHP code, the builder is rendered only after the master file, revision and content hash pass server-side checks. A later viewer failure therefore warrants examining the protected PDF response and worker request first. Cached pages, concurrent replacement and differing deployed versions can change that inference.

The live hosted URL, PDF request status/headers, worker response and deployed file/configuration checks have not been supplied. No production root cause is confirmed. No production account, database, environment or uploaded document was changed.

## Confirmed defects corrected

1. Storage success was trusted before metadata commit. A fault-injected adapter reporting success with a missing file or same-length corrupt content previously allowed metadata updates. Uploads now require readable persisted bytes, matching size and SHA-256 before committing metadata. Failure becomes a safe validation message, the transaction rolls back and only the attempted new file is cleaned up.
2. PDF fetch/render errors were indistinguishable. The builder now explicitly requests the protected same-origin PDF with authenticated credentials, checks redirects/status/MIME/signature and reads at most 10 MB with a 30-second bound. Specific safe messages distinguish missing/stale files, denied/expired access, HTML responses, server/network failure, unavailable viewer assets, worker startup, password protection and rendering failure. No raw server body or credential-bearing URL is displayed/logged.
3. Saving assumed every response contained valid JSON and an incremented version. It now checks response type, session errors, validation errors and the exact next version. An interrupted/ambiguous save instructs the Admin to reopen before retrying. Unsaved placements remain in the current page, and existing server-side revision, overlap and authorization rules remain enforced.

The existing PDF.js worker import and host-independent Vite worker URL were retained. The worker asset regression test passes. There is no speculative worker rewrite, storage symlink or public PDF route.

## Exact files

Modified existing files.

```text
app/Services/ServiceDocumentTemplates.php
resources/js/document-designer.js
tests/Feature/ServiceDocumentTemplateTest.php
tests/Feature/DocumentDesignerTest.php
```

New files.

```text
resources/js/document-builder-requests.js
tests/Js/document-builder-requests.test.mjs
docs/document-builder-repair.md
```

No migration, route change, upload-validation relaxation, historical-data repair, UI redesign, IoT or QR implementation change is included. Existing unrelated untracked files were preserved. Nothing was staged, committed, pushed or deployed.

## Testing and local verification

- Focused document/template/reservation/QR PHP suite passed with 159 tests and 1,497 assertions.
- All 51 JavaScript tests passed, including seven new request-contract tests and the real Vite worker asset test.
- Full PHP suite passed with 1,246 tests and 12,219 assertions. Vite build, targeted Pint, PHP syntax and diff checks passed.
- New PHP tests inject missing/corrupt writes, prove rollback and preservation of the previous PDF, verify missing-file behavior without erasing placements, and exercise the complete normal HTTP workflow through reservation approval and valid QR verification.
- Existing tests cover successful uploads, malformed/unsupported uploads, secure viewing, authorization, stale layout revision and historical document behavior.
- Actual in-app browser testing used private SQLite and a loopback-only PHP server serving the existing application's public directory. Synthetic and supplied PDFs loaded, a field was placed and saved, refresh preserved it, and page navigation worked. Captured warning/error logs were empty for the inspected builder states.

Private evidence is under `C:\Users\ronin\.smartbarangay\pdf-review-20261110-215309`, including `provided-pdf-saved-layout.png`. The provided source PDF was read only. The QA copy belongs exclusively to the isolated environment.

For local acceptance use a disposable database configured independently of real MariaDB, with private storage outside the web root.

1. Sign in as an active verified synthetic Admin and create a new synthetic service with a recorded fee and the sample valid PDF.
2. Create its document configuration against that PDF, open Design Document Layout and confirm the canvas/page counter.
3. Place a system field and a required custom field, adjust them without overlaps, then Save Layout.
4. Reload and confirm placements remain. Test Next/Previous using a multipage sample.
5. Activate the reviewed configuration. Sign in as a synthetic Resident, complete required profile/answers/uploads and submit a future appointment through the wizard.
6. As Admin, perform Pending → Under Review → Approved. QR issuance occurs on approval. Verify that ticket through the existing QR page.

```powershell
php artisan test --compact --filter='ServiceDocumentTemplateTest|DocumentDesignerTest|DocumentBuilderTest|ResidentDocumentInformationTest|ReservationDocumentPreviewTest|DocumentAutomationHardeningTest|GeneratedReservationDocumentTest|QrTicketsTest|QrVerificationTest'
node --test tests/Js/*.test.*
npm run build
git diff --check
```

## Read-only Hostinger diagnostics

In the failing authenticated browser tab, open Developer Tools → Network, reload the builder and inspect the request ending in `document-layout/pdf?revision=...` plus the hashed `pdf.worker.min-*.mjs` request. Record only status, Content-Type, redirect destination category, transferred size and safe error category. Do not share cookies, Authorization headers, raw PDF contents, resident answers or a HAR containing credentials.

| Observed response | Interpretation and next check |
| --- | --- |
| PDF 200, application/pdf, starts with `%PDF-` | Delivery is plausible. Check worker startup and the console rendering category. |
| PDF redirect or HTML | Check authentication/session, correct HTTPS origin, proxy/routing and application URL. |
| PDF 403/419 | Check verified/active Admin access and session state. |
| PDF 404 | Inspect the private file/reference and configuration binding. Do not overwrite database paths to guess a location. |
| PDF 500/503 | Review the corresponding private Laravel/PHP log entry, withholding traces/bindings from public responses. |
| Worker 404 or HTML | Check the complete matching Vite asset release and public document root. |
| Worker 200 with a non-JavaScript MIME type | Review the hosting `.mjs` MIME configuration through its supported controls. |
| Worker/main version mismatch | Publish a matching complete asset release during the normal approved deployment. |

From the private Laravel application directory in Hostinger SSH, the following diagnostic performs SELECTs and file reads only. Replace `123` with the service ID visible in the failing editor URL. Do not save this script under public_html.

```bash
php <<'PHP'
<?php
try {
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$service = App\Models\Service::findOrFail(123);
$pdf = $service->documentTemplate;
$configuration = $service->dynamicDocumentTemplate;
$disk = Illuminate\Support\Facades\Storage::disk('local');
$scoped = $pdf && preg_match('#\Aservice-document-templates/'.$service->id.'/[A-Za-z0-9]{40}\.pdf\z#D', $pdf->stored_path);
$exists = $scoped && $disk->exists($pdf->stored_path);
$readable = $exists && is_readable($disk->path($pdf->stored_path));
$data = ['metadata_present' => (bool) $pdf, 'path_scoped' => (bool) $scoped,
    'file_present' => (bool) $exists, 'file_readable' => (bool) $readable,
    'size_matches' => $exists && $disk->size($pdf->stored_path) === $pdf->file_size,
    'configuration_present' => (bool) $configuration,
    'source_hash_recorded' => is_string($configuration?->source_content_hash),
    'binding_matches' => $readable && $configuration && $configuration->matchesCurrentPdf(),
    'stored_pdf_valid' => $readable && (new App\Rules\OfficialDocumentTemplate)->validPdf($disk->path($pdf->stored_path)),
    'storage_writable' => is_writable($disk->path('service-document-templates')),
    'upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size')];
echo json_encode($data, JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['diagnostic_failed' => true, 'category' => get_class($exception)]).PHP_EOL;
}
PHP
```

This deliberately omits paths, hashes, credentials and file contents. CLI PHP settings may differ from web PHP settings, so confirm upload/post limits in the hosting PHP panel too. The upload limit must support the existing 10 MB application limit with multipart overhead. Never use world-writable permissions as a remedy.

## Deployment and safe recovery

Laravel source/vendor/config/storage must remain in the private application directory outside public_html. Only public entry points and built assets belong in public_html. The local disk resolves through that application's `storage_path('app/private')`. These controllers intentionally use the private local disk regardless of the default disk environment setting.

Publish matching application code and the complete Vite build/manifest through the normal approved release process. Preserve the persistent private storage tree, application key, current database and deployment-specific public index bootstrap. Do not upload `public/hot`, point the worker at a local Vite server or expose private storage with storage:link. No dependency update or new migration is required by this repair.

No recovery of existing documents was shown necessary by local inspection. Hosted recovery depends on the read-only results.

- Missing underlying file requires locating its exact verified original in a private backup. Restore only after owner approval, with backup and identity/hash verification. Do not substitute another PDF or silently edit references.
- Present file with permission problems requires narrowly scoped owner-approved access correction.
- A new master with a stale configuration requires explicit Admin review through existing reconfiguration controls. That process clears old placements, so back up/review them first. Never update source hashes directly to bypass that gate.
- Keep historical reservation source/generated files and snapshots intact. Current master recovery must not rewrite historical documents or bypass required resident answers.

## Acceptance verdict

| Area | Local result | Hosted result |
| --- | --- | --- |
| Provided PDF validation and preview | PASS | PARTIAL, failing request evidence needed |
| Field placement, Save Layout and reopen | PASS | PARTIAL, live request evidence needed |
| Required reservation workflow | PASS automated | Not exercised against production |
| QR generation and verification | PASS automated after normal approval | Not exercised against production |

Overall **PARTIAL**. The reproducible local integrity/diagnostic defects are corrected. The exact reported production failure remains unconfirmed until the hosted HTTP/worker/storage evidence is available.
