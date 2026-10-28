<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $artifact['reservation_id'] ? 'Print Certificate' : 'Print Blank Template' }} · SmartBarangay</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="certificate-print-page unified-certificate-print">
    <header class="certificate-print-controls">
        <h1 class="h3">{{ $artifact['reservation_id'] ? 'Print Certificate' : 'Print Blank Template' }}</h1>
        <p>Barangay Calayo · Nasugbu, Batangas</p>
        <p class="text-break">{{ $artifact['service'] }}@if($artifact['reservation_id']) · Request #{{ $artifact['reservation_id'] }}@endif</p>
        <a class="btn btn-outline-secondary" href="{{ $artifact['reservation_id'] ? route('admin.reservations.show', $artifact['reservation_id']) : route('admin.services.certificates.index', $artifact['service_id']) }}">Back</a>
        <button class="btn btn-primary" type="button" disabled data-certificate-browser-print>Print</button>
        <p class="app-note mt-2">Printing does not change request status. This print view is available for 10 minutes.</p>
        <p class="app-note" data-print-paper-summary role="status">Detecting PDF paper sizes…</p>
        <p class="app-note">Load matching paper and check the printer preview. Choose Actual size / 100% and turn off browser headers and footers. Legal (8.5 × 14 in) differs from Long / Folio (8.5 × 13 in). Your printer controls the final paper and scaling settings.</p>
    </header>
    <main>@include('admin.services.document-preview', ['previewHeading' => $artifact['reservation_id'] ? 'Preview Generated Document' : 'Preview Blank Template'])</main>
</body></html>
