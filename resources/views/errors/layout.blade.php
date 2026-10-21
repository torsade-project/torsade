{{-- torsade/resources/views/errors/layout.blade.php --}}
{{-- An error page: the name of the application, what happened, and the way
     back. It only needs the stylesheet, no script of the interface, and
     says nothing about the version or the software that runs. --}}
@php
    use Illuminate\Support\Facades\Vite;
    // The middleware that shares the theme does not run for every error.
    $appearance = \App\Http\Middleware\HandleAppearance::choice(request());
    $nonce = Vite::cspNonce();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $appearance === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title') - {{ config('app.name', 'Torsade') }}</title>

        @include('partials.theme', ['appearance' => $appearance, 'nonce' => $nonce])

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased">
        <div class="flex min-h-svh items-center justify-center bg-background px-4 py-12">
            <main class="flex w-full max-w-105 flex-col gap-8">
                <div class="flex flex-col items-start gap-3.5">
                    <a href="{{ url('/') }}" class="flex items-center gap-3.5 rounded-lg">
                        <span class="flex size-14 shrink-0 items-center justify-center rounded-lg border bg-card">
                            @include('partials.logo-mark', ['class' => 'size-9.5 text-foreground'])
                        </span>
                        <span class="flex flex-col gap-0.5">
                            <span class="text-brand-lg font-semibold">{{ config('app.name', 'Torsade') }}</span>
                            <span class="text-muted-foreground">{{ __('Network documentation') }}</span>
                        </span>
                    </a>
                    <div class="flex w-33 gap-1" aria-hidden="true">
                        <span class="h-1 flex-1 rounded-full bg-pair-orange"></span>
                        <span class="h-1 flex-1 rounded-full bg-pair-green"></span>
                        <span class="h-1 flex-1 rounded-full bg-pair-blue"></span>
                        <span class="h-1 flex-1 rounded-full bg-pair-brown"></span>
                    </div>
                </div>

                <div class="flex flex-col gap-5 rounded-xl border bg-card p-6 sm:p-8">
                    <div class="flex flex-col gap-1.5">
                        <p class="font-mono text-note text-muted-foreground">{{ __('Error :code', ['code' => trim($__env->yieldContent('code'))]) }}</p>
                        <h1 class="text-heading font-semibold">@yield('title')</h1>
                    </div>
                    @hasSection('message')
                        <p class="text-muted-foreground">@yield('message')</p>
                    @endif
                    <a href="{{ url('/') }}" class="inline-flex h-10.5 items-center justify-center self-start rounded-md border border-border-strong px-4 font-medium text-foreground transition-colors hover:bg-accent">
                        {{ __('Back to the home page') }}
                    </a>
                </div>
            </main>
        </div>
    </body>
</html>
