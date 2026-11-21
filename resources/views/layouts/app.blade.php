<!DOCTYPE html>
<html lang="en" data-bs-theme="{{ auth()->user()?->preferredTheme() ?? 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1F5EA8">
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/smartbarangay-mark.svg') }}">
    @php
        // Preload the Latin subset of Plus Jakarta Sans so the first paint already uses it (no swap reflow).
        try { $fontPreload = \Illuminate\Support\Facades\Vite::asset('node_modules/@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-wght-normal.woff2'); } catch (\Throwable) { $fontPreload = null; }
    @endphp
    @if($fontPreload)<link rel="preload" href="{{ $fontPreload }}" as="font" type="font/woff2" crossorigin>@endif
    <title>@yield('title', 'SmartBarangay') · Barangay Calayo</title>
    @unless(auth()->user()?->is_active && auth()->user()?->hasVerifiedEmail())
    {{-- Visitors without a saved account preference: apply the theme chosen in this browser before paint. --}}
    <script>try{if(localStorage.getItem('smartbarangay-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}</script>
    @endunless
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="civic-ui civic-refined">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    @yield('content')
</body>
</html>
