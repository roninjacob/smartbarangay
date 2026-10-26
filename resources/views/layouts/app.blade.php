<!DOCTYPE html>
<html lang="en" data-bs-theme="{{ auth()->user()?->preferredTheme() ?? 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#225FE5">
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/smartbarangay-mark.svg') }}">
    <title>@yield('title', 'SmartBarangay') · Barangay Calayo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="civic-ui civic-refined">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    @yield('content')
</body>
</html>
