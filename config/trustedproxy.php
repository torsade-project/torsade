<?php

// torsade/config/trustedproxy.php
//
// The proxies trusted to carry the real address and scheme of the client
// in their X-Forwarded-* headers. The middleware of the framework reads
// this value on every request: a cached configuration still holds it.

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Addresses or CIDR prefixes, from the TRUSTED_PROXIES environment
    | variable as a comma-separated list ("192.0.2.1,198.51.100.0/24").
    | Empty by default: the X-Forwarded-* headers are then ignored, and the
    | limits on login attempts count by the address the connection comes
    | from. Behind a reverse proxy, its address goes here; without it, every
    | user would be counted as the proxy.
    |
    */

    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ))),

];
