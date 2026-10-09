<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Print Certificate · SmartBarangay</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="certificate-print-page unified-certificate-print">
    <header class="certificate-print-controls">
        <h1 class="h3">Print Certificate</h1>
        <p>Barangay Calayo · Nasugbu, Batangas</p>
        <p class="text-break">{{ $artifact['service'] }}@if($artifact['reservation_id']) · Request #{{ $artifact['reservation_id'] }}@endif</p>
        <a class="btn btn-outline-secondary" href="{{ $artifact['reservation_id'] ? route('admin.reservations.show', $artifact['reservation_id']) : route('admin.services.certificates.index', $artifact['service_id']) }}">Back</a>
        <button class="btn btn-primary" type="button" disabled data-certificate-browser-print>Print</button>
        <p class="app-note mt-2">Printing does not change request status. This print view is available for 10 minutes.</p>
    </header>
    <main>@include('admin.services.document-preview')</main>
</body></html>
