<?php

// torsade/tests/Unit/TemporaryPasswordTest.php

namespace Tests\Unit;

use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class TemporaryPasswordTest extends TestCase
{
    public function test_it_is_at_least_twenty_characters_long(): void
    {
        $this->assertGreaterThanOrEqual(20, strlen(TemporaryPassword::generate()));
    }

    public function test_it_only_contains_letters_and_digits(): void
    {
        foreach (range(1, 50) as $i) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', TemporaryPassword::generate());
        }
    }

    public function test_it_is_different_every_time(): void
    {
        $passwords = array_map(fn (): string => TemporaryPassword::generate(), range(1, 200));

        $this->assertCount(200, array_unique($passwords));
    }

    public function test_it_satisfies_the_password_rule_of_the_application(): void
    {
        $validator = Validator::make(
            ['password' => TemporaryPassword::generate()],
            ['password' => Password::default()],
        );

        $this->assertTrue($validator->passes());
    }
}
