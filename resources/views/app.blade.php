{{-- torsade/resources/views/app.blade.php --}}
@php
    use Illuminate\Support\Facades\Vite;
    $nonce = Vite::cspNonce();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'dark') === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- The nonce of the policy, for the code that inserts a style or a
             script: Inertia only replaces the page, never this document,
             whose policy stays the one in force. Vite reads it here too. --}}
        <meta property="csp-nonce" nonce="{{ $nonce }}">
        {{-- The field of the code of the second factor inserts its own
             style element unless this one exists; its rules are in the
             stylesheet of the build. --}}
        <style id="input-otp-style" nonce="{{ $nonce }}"></style>

        @include('partials.theme', ['appearance' => $appearance ?? 'dark', 'nonce' => $nonce])

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Torsade') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
