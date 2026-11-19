<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.browser-guard')
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'CFCI Scholarship Management') }}</title>
        <link rel="icon" type="image/webp" href="{{ asset('images/favicon.webp') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="app-shell min-h-screen">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="page-heading">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main id="main-content">
                @if(\App\Services\Eligibility\OfficialRuleCatalog::demonstrationDatabase())
                    <p class="records-alert" role="note"><strong>{{ \App\Services\Eligibility\OfficialRuleCatalog::DEMO_LABEL }}</strong> — isolated defense database. Records, certification and financial transactions are fictional. No official submission or money transfer is performed.</p>
                @endif
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
