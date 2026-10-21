<?php

// torsade/app/Support/Actor.php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who is performing an action, for the activity log: a signed-in user, an
 * anonymous visitor, or the console.
 */
final readonly class Actor
{
    public const SOURCE_WEB = 'web';

    public const SOURCE_CONSOLE = 'console';

    private function __construct(
        public ?User $user,
        public ?string $ip,
        public string $source,
    ) {}

    /**
     * The user behind an HTTP request, or an anonymous visitor.
     */
    public static function fromRequest(Request $request): self
    {
        $user = $request->user();

        return new self($user instanceof User ? $user : null, $request->ip(), self::SOURCE_WEB);
    }

    /**
     * A given user acting through an HTTP request, when the request itself
     * does not carry them yet (login) or any more (logout).
     */
    public static function forUser(User $user, Request $request): self
    {
        return new self($user, $request->ip(), self::SOURCE_WEB);
    }

    /**
     * An HTTP request whose author is not (or no longer) a signed-in user.
     */
    public static function anonymous(Request $request): self
    {
        return new self(null, $request->ip(), self::SOURCE_WEB);
    }

    /**
     * An Artisan command: no user and no IP address.
     */
    public static function console(): self
    {
        return new self(null, null, self::SOURCE_CONSOLE);
    }

    public function isUser(User $user): bool
    {
        return $this->user !== null && $this->user->is($user);
    }
}
