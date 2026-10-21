<?php

// torsade/config/security.php
//
// Settings that protect the application in production. Every value is read
// from the configuration, never with env() outside of this file, so that a
// cached configuration still holds them. The trusted proxies are in
// config/trustedproxy.php, where the framework reads them.

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | When enabled on an HTTPS request, the browser is told to come back only
    | in HTTPS for the next year. Off by default, since the installer only
    | turns it on when an explicit option is given, after the certificate
    | chain of a provided certificate has been checked. Never on a selfsigned
    | certificate: the browser would then refuse to pass its warning, and a
    | computer without the authority would lose access to the application.
    |
    */

    'hsts' => [
        'enabled' => (bool) env('HSTS_ENABLED', false),
        'max_age' => 31_536_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted Hosts
    |--------------------------------------------------------------------------
    |
    | In production, a request whose Host header is neither the host of
    | APP_URL (without its subdomains) nor one of these additional hosts
    | receives a 4xx answer without detail. The framework turns the check
    | off in local and testing environments.
    |
    | The additional hosts come from the TRUSTED_HOSTS environment variable
    | as a comma-separated list ("torsade.example.test,10.0.0.42").
    |
    */

    'trusted_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_HOSTS', '')),
    ))),

];
