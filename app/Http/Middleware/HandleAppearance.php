<?php

// torsade/app/Http/Middleware/HandleAppearance.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * The themes a user can choose in the appearance settings.
     *
     * @var list<string>
     */
    public const array CHOICES = ['light', 'dark', 'system'];

    /**
     * The theme of someone who never chose one: the dark theme is the
     * reference of the visual identity.
     */
    public const string DEFAULT = 'dark';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', self::choice($request));

        return $next($request);
    }

    /**
     * The choice kept in the browser. The cookie is written by the page and
     * is not encrypted: anything else than a known theme is ignored.
     */
    public static function choice(Request $request): string
    {
        $choice = $request->cookie('appearance');

        return is_string($choice) && in_array($choice, self::CHOICES, true)
            ? $choice
            : self::DEFAULT;
    }
}
