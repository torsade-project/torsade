<?php

// torsade/app/Http/Responses/LockoutResponse.php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a locked login answers: the message of the login form, with the time
 * left to wait.
 *
 * It does what the response it replaces does, except that the message
 * agrees with the number of seconds: "1 seconde", "2 secondes".
 */
class LockoutResponse implements LockoutResponseContract
{
    public function __construct(private readonly LoginRateLimiter $limiter) {}

    /**
     * @param  Request  $request
     *
     * @throws ValidationException
     */
    public function toResponse($request): Response
    {
        $seconds = (int) $this->limiter->availableIn($request);

        throw ValidationException::withMessages([
            Fortify::username() => [
                trans_choice('auth.throttle', $seconds, [
                    'seconds' => $seconds,
                    'minutes' => (int) ceil($seconds / 60),
                ]),
            ],
        ])->status(Response::HTTP_TOO_MANY_REQUESTS);
    }
}
