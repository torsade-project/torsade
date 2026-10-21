<?php

// torsade/tests/Feature/AppearanceTest.php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dark theme is the one of a visitor who never chose: the server sets
 * it on the page itself, so that it is there before any script runs.
 */
class AppearanceTest extends TestCase
{
    public function test_the_dark_theme_is_the_default_one(): void
    {
        $html = (string) $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html lang="fr" class="dark">/', $html);
        $this->assertStringContainsString("const appearance = 'dark';", $html);
    }

    public function test_the_light_theme_is_kept_when_it_was_chosen(): void
    {
        $html = (string) $this->withUnencryptedCookie('appearance', 'light')
            ->get(route('login'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<html[^>]*\bdark\b/', $html);
        $this->assertStringContainsString("const appearance = 'light';", $html);
    }

    public function test_the_dark_theme_is_kept_when_it_was_chosen(): void
    {
        $html = (string) $this->withUnencryptedCookie('appearance', 'dark')
            ->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html lang="fr" class="dark">/', $html);
    }

    public function test_the_system_theme_is_left_to_the_browser(): void
    {
        $html = (string) $this->withUnencryptedCookie('appearance', 'system')
            ->get(route('login'))->assertOk()->getContent();

        // The server cannot know: the page decides when it opens.
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*\bdark\b/', $html);
        $this->assertStringContainsString("const appearance = 'system';", $html);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownChoices(): array
    {
        return [
            'another word' => ['sepia'],
            'empty' => [''],
            'an attempt to leave the script' => ["';alert(1);//"],
            'markup' => ['"><script>alert(1)</script>'],
        ];
    }

    #[DataProvider('unknownChoices')]
    public function test_an_unknown_choice_is_ignored(string $choice): void
    {
        $html = (string) $this->withUnencryptedCookie('appearance', $choice)
            ->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html lang="fr" class="dark">/', $html);
        $this->assertStringContainsString("const appearance = 'dark';", $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }
}
