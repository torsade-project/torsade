<?php

// torsade/tests/Unit/PasswordDefaultsTest.php

namespace Tests\Unit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class PasswordDefaultsTest extends TestCase
{
    private function passes(string $password): bool
    {
        return Validator::make(['password' => $password], ['password' => Password::default()])->passes();
    }

    public function test_passwords_shorter_than_twelve_characters_are_refused(): void
    {
        $this->assertFalse($this->passes(str_repeat('a', 11)));
    }

    public function test_passwords_of_twelve_characters_are_accepted(): void
    {
        $this->assertTrue($this->passes(str_repeat('a', 12)));
    }

    public function test_the_rule_is_the_same_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertFalse($this->passes(str_repeat('a', 11)));
        $this->assertTrue($this->passes(str_repeat('a', 12)));
    }

    public function test_validating_a_password_calls_no_external_service(): void
    {
        // A well-known leaked password: an uncompromised() rule would look it
        // up through an external API, which this would turn into a failure.
        Http::preventStrayRequests();
        Http::fake();

        $this->assertTrue($this->passes('password1234'));

        Http::assertNothingSent();
    }
}
