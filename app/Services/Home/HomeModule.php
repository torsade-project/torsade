<?php

// torsade/app/Services/Home/HomeModule.php

namespace App\Services\Home;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A module of the home page: a key the browser draws it by, who may see
 * it, its settings with their bounds and their default values, and the
 * data it shows, which it prepares itself. A module the user may not see
 * sends nothing at all.
 *
 * The settings are fixed for now; the choice of each user will be checked
 * by the same rules.
 */
abstract class HomeModule
{
    /**
     * The key of the module, the same in the browser.
     */
    abstract public function key(): string;

    /**
     * Whether the user may see the module.
     */
    abstract public function authorize(User $user): bool;

    /**
     * What the module shows with these settings, or null when it has
     * nothing to show the user this time.
     *
     * @param  array<string, int>  $settings
     * @return array<string, mixed>|null
     */
    abstract public function data(User $user, array $settings): ?array;

    /**
     * The settings of the module: whole numbers, each with its bounds and
     * its default value.
     *
     * @return array<string, array{min: int, max: int, default: int}>
     */
    public function settings(): array
    {
        return [];
    }

    /**
     * The rules a chosen setting must follow. A setting left out takes its
     * default value; one that is given, even blank, must be a whole number
     * within its bounds.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return array_map(
            fn (array $setting): array => ['filled', 'integer', 'min:'.$setting['min'], 'max:'.$setting['max']],
            $this->settings(),
        );
    }

    /**
     * The settings to use: those chosen, once checked, and the default
     * value of each one that was not.
     *
     * @param  array<string, mixed>  $chosen
     * @return array<string, int>
     *
     * @throws ValidationException
     */
    public function resolve(array $chosen = []): array
    {
        $settings = $this->settings();

        /** @var array<string, int|string> $valid */
        $valid = Validator::make(array_intersect_key($chosen, $settings), $this->rules())->validate();

        return array_map(
            fn (string $name): int => (int) ($valid[$name] ?? $settings[$name]['default']),
            array_combine(array_keys($settings), array_keys($settings)),
        );
    }
}
