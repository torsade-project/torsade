<?php

// torsade/app/Http/Controllers/HealthController.php

namespace App\Http\Controllers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The health check: tells a probe whether the application can be used,
 * that is whether it reaches its database and is not under maintenance,
 * and nothing else.
 *
 * It is open to anyone who can reach the server, so it gives nothing away:
 * a word of plain text, no view, no session, no cookie, no detail about a
 * failure, nothing about the version or the software that runs.
 */
class HealthController extends Controller
{
    /**
     * Calls accepted from one IP address in a minute. A probe needs far
     * less; the limit keeps the check from being used to open connections
     * to the database at will.
     */
    public const int MAX_PER_MINUTE = 60;

    /**
     * The cache store that counts the calls. It lives on the local disk,
     * never in the database: the check has to answer, and stay limited,
     * when the database is down.
     */
    public const string LIMITER_STORE = 'health';

    public function __invoke(Request $request): Response
    {
        try {
            $limiter = new RateLimiter(Cache::store(self::LIMITER_STORE));
            $key = 'health:'.$request->ip();

            if ($limiter->tooManyAttempts($key, self::MAX_PER_MINUTE)) {
                return $this->answer('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => (string) $limiter->availableIn($key),
                ]);
            }

            $limiter->hit($key, 60);

            // Under maintenance the application cannot be used, whatever
            // the state of its database: the same word says so.
            if (app()->isDownForMaintenance()) {
                return $this->answer('Unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
            }

            // Opening the connection is the whole check: no query is sent.
            DB::connection()->getPdo();
        } catch (Throwable) {
            // Whatever went wrong stays on the server.
            return $this->answer('Unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->answer('OK', Response::HTTP_OK);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function answer(string $word, int $status, array $headers = []): Response
    {
        return response($word, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            // The state of a moment: never kept by a browser or a proxy.
            'Cache-Control' => 'no-store, private',
            ...$headers,
        ]);
    }
}
