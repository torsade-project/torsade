<?php

// torsade/app/Services/Home/HomeModules.php

namespace App\Services\Home;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;

/**
 * The modules of the home page, in their order. Each module decides who
 * may see it and prepares its own data; the page receives, in order, the
 * modules the user may see that have something to show. A module the user
 * may not see is not asked for anything.
 *
 * The list, the order and the settings are the same for everyone for now.
 * When each user chooses them, the choices will be checked by the rules
 * of each module, and a module missing from them, being new, will take
 * its place by default.
 */
final class HomeModules
{
    /**
     * @var list<class-string<HomeModule>>
     */
    public const array MODULES = [
        SearchModule::class,
        GettingStartedModule::class,
        SummaryModule::class,
        SitesModule::class,
        FullestSubnetsModule::class,
        RecentActivityModule::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<HomeModule>
     */
    public function all(): array
    {
        return array_map(fn (string $module): HomeModule => $this->container->make($module), self::MODULES);
    }

    /**
     * The modules of the home page of a user, in order, each with its
     * settings and its data.
     *
     * @param  array<string, array<string, mixed>>  $choices  Settings chosen, by key of module.
     * @return list<array{key: string, settings: array<string, int>, data: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    public function forUser(User $user, array $choices = []): array
    {
        $modules = [];

        foreach ($this->all() as $module) {
            if (! $module->authorize($user)) {
                continue;
            }

            $settings = $module->resolve($choices[$module->key()] ?? []);
            $data = $module->data($user, $settings);

            if ($data !== null) {
                $modules[] = ['key' => $module->key(), 'settings' => $settings, 'data' => $data];
            }
        }

        return $modules;
    }
}
