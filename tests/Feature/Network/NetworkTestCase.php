<?php

// torsade/tests/Feature/Network/NetworkTestCase.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Subnet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * What the tests of the network documentation share: an account of each
 * role, and a way to read the message attached to a field.
 */
abstract class NetworkTestCase extends TestCase
{
    use RefreshDatabase;

    protected function reader(): User
    {
        return User::factory()->reader()->create();
    }

    protected function editor(): User
    {
        return User::factory()->editor()->create();
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * A subnet written in CIDR notation, with no VLAN.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function subnet(string $cidr = '192.168.100.0/24', array $attributes = []): Subnet
    {
        return Subnet::factory()->cidr($cidr)->create($attributes);
    }

    /**
     * The message attached to a field by the last refused request.
     */
    protected function error(string $field): string
    {
        $errors = session('errors');

        if ($errors instanceof ViewErrorBag) {
            return $errors->first($field);
        }

        // Once saved, the JSON session keeps the error bags as plain arrays,
        // whose keys are the field names, dots included.
        $messages = is_array($errors) ? ($errors['default']['messages'] ?? []) : [];

        return (string) ($messages[$field][0] ?? '');
    }

    /**
     * The fields the last refused request has a message for.
     *
     * @return list<string>
     */
    protected function errorFields(): array
    {
        $errors = session('errors');

        if ($errors instanceof ViewErrorBag) {
            return array_keys($errors->getBag('default')->toArray());
        }

        return array_keys(is_array($errors) ? ($errors['default']['messages'] ?? []) : []);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    protected function assertRefused(TestResponse $response, string $field, ?string $message = null): void
    {
        $response->assertRedirect()->assertSessionHasErrors($field);

        if ($message !== null) {
            $this->assertSame($message, $this->error($field));
        }
    }

    protected function countEntries(ActivityEvent $event): int
    {
        return Activity::query()->where('event', $event->value)->count();
    }

    protected function lastEntry(ActivityEvent $event): Activity
    {
        return Activity::query()->where('event', $event->value)->orderByDesc('id')->firstOrFail();
    }
}
