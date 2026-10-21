<?php

// torsade/app/Http/Middleware/SecurityHeaders.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outermost middleware of the application, so that every response —
 * success, redirect, maintenance, error 403/404/405/419/429/500/503 — carries
 * the same headers. It also generates the per-request nonce used by
 * the Content-Security-Policy and stamped on every inline script and style,
 * on the <meta> tag the browser code reads at the first load, and on the
 * tags Vite emits.
 */
class SecurityHeaders
{
    /**
     * Browser features Torsade never uses, all disabled for every origin.
     * Only names that current browsers recognise: an unknown one makes them
     * log a warning on every page.
     */
    private const DISABLED_FEATURES = [
        'accelerometer',
        'autoplay',
        'camera',
        'display-capture',
        'encrypted-media',
        'fullscreen',
        'geolocation',
        'gyroscope',
        'magnetometer',
        'microphone',
        'midi',
        'payment',
        'picture-in-picture',
        'publickey-credentials-get',
        'screen-wake-lock',
        'usb',
        'xr-spatial-tracking',
    ];

    public function __construct(private readonly Application $app) {}

    /**
     * Add the security headers to every response.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // A nonce for the response, generated before the view is rendered
        // so that Vite and the Blade templates can stamp it on their tags.
        $nonce = $this->prepareNonce();

        $response = $next($request);

        // PHP announces its version in this header unless told otherwise in
        // php.ini: nothing about the technical stack is disclosed.
        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        // An internal tool has no place in a search engine. robots.txt asks
        // crawlers to stay away; this header is obeyed by those that reach
        // a page anyway.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', implode(', ', array_map(
            fn (string $feature): string => $feature.'=()',
            self::DISABLED_FEATURES,
        )));

        // Keep the window from sharing its browsing context with other
        // origins, and refuse that other origins embed Torsade's resources.
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // In development with the debug mode, the page Laravel shows for an
        // exception carries scripts and styles of its own, without the
        // nonce: it goes without a policy, and only there. Production
        // never does.
        if (! $this->isTheDebugPageOfDevelopment($response)) {
            $response->headers->set('Content-Security-Policy', $this->policy($request, $nonce));
        }

        if ($this->shouldSendHsts($request)) {
            // One year, without includeSubDomains and without preload: the
            // installer only turns HSTS on with an explicit option, and
            // never with a self-signed certificate.
            $response->headers->set(
                'Strict-Transport-Security',
                sprintf('max-age=%d', (int) config('security.hsts.max_age', 31_536_000)),
            );
        }

        return $response;
    }

    /**
     * The nonce goes to Vite, which stamps it on every tag it emits (@vite
     * directive); Blade templates read it with the Vite::cspNonce() helper.
     * A new one is drawn on every call: the middleware is the outermost one
     * and runs once per request, and the test suite reuses the application
     * instance between requests.
     */
    private function prepareNonce(): string
    {
        $nonce = Str::random(24);

        Vite::useCspNonce($nonce);

        return $nonce;
    }

    private function policy(Request $request, string $nonce): string
    {
        $self = "'self'";
        $none = "'none'";
        $scriptSources = [$self, sprintf("'nonce-%s'", $nonce)];
        $styleSources = [$self, sprintf("'nonce-%s'", $nonce)];
        $connectSources = [$self];

        // In development the Vite dev server loads its modules and refreshes
        // the page over a WebSocket, from its own origin, and inserts the
        // styles of the components from JavaScript as it serves them. The
        // styles then take 'unsafe-inline', and only here; a browser ignores
        // that keyword beside a nonce, so the styles drop the nonce in
        // development. Scripts keep it. Production never ships this
        // exception, and a test proves it.
        if ($this->app->environment('local') && $this->viteIsHot()) {
            $viteOrigin = $this->viteDevServerOrigin();

            if ($viteOrigin !== null) {
                $scriptSources[] = $viteOrigin;
                $styleSources = [$self, $viteOrigin, "'unsafe-inline'"];
                $connectSources[] = $viteOrigin;
                $connectSources[] = $this->asWebSocket($viteOrigin);
            }
        }

        return implode('; ', [
            "default-src {$self}",
            'script-src '.implode(' ', $scriptSources),
            'style-src '.implode(' ', $styleSources),
            "img-src {$self}",
            "font-src {$self}",
            'connect-src '.implode(' ', $connectSources),
            // The diagrams work out their layout in a dedicated worker,
            // loaded from the build on the same origin.
            "worker-src {$self}",
            "object-src {$none}",
            "base-uri {$none}",
            "form-action {$self}",
            "frame-ancestors {$none}",
        ]);
    }

    /**
     * Whether this response should carry HSTS. On an HTTPS request, when
     * the configuration turns it on.
     */
    private function shouldSendHsts(Request $request): bool
    {
        return (bool) config('security.hsts.enabled', false) && $request->isSecure();
    }

    private function isTheDebugPageOfDevelopment(Response $response): bool
    {
        return $this->app->environment('local')
            && (bool) config('app.debug')
            && $response->getStatusCode() >= 500;
    }

    private function viteIsHot(): bool
    {
        return is_file(Vite::hotFile());
    }

    private function viteDevServerOrigin(): ?string
    {
        $contents = @file_get_contents(Vite::hotFile());

        if (! is_string($contents)) {
            return null;
        }

        $origin = trim($contents);

        return $origin === '' ? null : rtrim($origin, '/');
    }

    private function asWebSocket(string $origin): string
    {
        return (string) preg_replace('#^http#i', 'ws', $origin);
    }
}
