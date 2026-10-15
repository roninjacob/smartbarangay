<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Print Link Unavailable · SmartBarangay</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="certificate-print-page">
    <main class="dashboard-panel p-3 p-md-4">
        <h1 class="h3">Print link unavailable</h1>
        <p role="alert">{{ $message }}</p>
        <a class="btn btn-primary" href="{{ route('admin.reservations.index') }}">Open Reservation Management</a>
        <p class="app-note mt-3 mb-0">SmartBarangay · Barangay Calayo, Nasugbu, Batangas</p>
    </main>
</body>
</html>
