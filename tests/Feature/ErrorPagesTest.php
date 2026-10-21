<?php

// torsade/tests/Feature/ErrorPagesTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Error pages carry the name and the themes of the application, say what
 * happened in French, and reveal nothing about what runs behind.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A route that fails with the status it is asked for.
        Route::get('/fail/{status}', function (int $status): never {
            throw new HttpException($status);
        })->middleware('web');
    }

    public function test_an_unknown_address_shows_the_page_of_the_application(): void
    {
        $html = (string) $this->get('/an-address-that-does-not-exist')->assertNotFound()->getContent();

        $this->assertStringContainsString('<title>Page introuvable - Torsade</title>', $html);
        $this->assertStringContainsString('Erreur 404', $html);
        $this->assertStringContainsString('L&#039;adresse demandée ne correspond à aucune page.', $html);
        $this->assertStringContainsString('Retour à l&#039;accueil', $html);
        $this->assertStringContainsString('Documentation du réseau', $html);

        // The two strands of the logo, and the stylesheet of the build.
        $this->assertStringContainsString('M3 16 C 8 5, 12 5, 16 16 S 24 27, 29 16', $html);
        $this->assertMatchesRegularExpression('~<link rel="stylesheet" href="[^"]*/build/assets/app-[\w-]+\.css"~', $html);
    }

    public function test_a_refusal_gives_its_reason(): void
    {
        $this->actingAs(User::factory()->reader()->create())
            ->get(route('users.index'))
            ->assertForbidden()
            ->assertSee('Accès refusé')
            ->assertSee('Erreur 403')
            ->assertSee("Cette action n'est pas autorisée.");
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function statuses(): array
    {
        return [
            '401' => [401, 'Non autorisé'],
            '403' => [403, 'Accès refusé'],
            '404' => [404, 'Page introuvable'],
            '419' => [419, 'Page expirée'],
            '429' => [429, 'Trop de requêtes'],
            '500' => [500, 'Erreur du serveur'],
            '503' => [503, 'Service indisponible'],
            // Statuses without a page of their own.
            '405' => [405, 'La requête a été refusée'],
            '418' => [418, 'La requête a été refusée'],
            '502' => [502, 'Erreur du serveur'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_each_status_has_a_page_in_french(int $status, string $title): void
    {
        $html = (string) $this->get("/fail/{$status}")->assertStatus($status)->getContent();

        $this->assertStringContainsString("<title>{$title} - Torsade</title>", $html);
        $this->assertStringContainsString("Erreur {$status}", $html);
        $this->assertStringContainsString('<html lang="fr"', $html);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusCodes(): array
    {
        return array_map(fn (array $set): array => [$set[0]], self::statuses());
    }

    #[DataProvider('statusCodes')]
    public function test_an_error_page_reveals_nothing_about_what_runs(int $status): void
    {
        $response = $this->get("/fail/{$status}");
        $html = (string) $response->getContent();

        $this->assertStringNotContainsString((string) config('app.version'), strip_tags($html));
        $this->assertFalse(stripos($html, 'laravel'));
        $this->assertFalse(stripos($html, 'symfony'));
        $this->assertFalse(stripos($html, 'php'));
        $this->assertFalse(stripos($html, 'HttpException'));

        // Only the stylesheet is loaded: no script of the interface, and
        // nothing from another host.
        $this->assertStringNotContainsString('type="module"', $html);
        $this->assertDoesNotMatchRegularExpression('~(?:src|href)="(?:https?:)?//(?!torsade\.test|localhost)~', $html);

        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function test_an_error_page_follows_the_theme_of_the_visitor(): void
    {
        $this->assertMatchesRegularExpression(
            '/<html lang="fr" class="dark">/',
            (string) $this->get('/an-address-that-does-not-exist')->getContent(),
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<html[^>]*\bdark\b/',
            (string) $this->withUnencryptedCookie('appearance', 'light')->get('/an-address-that-does-not-exist')->getContent(),
        );

        $system = (string) $this->withUnencryptedCookie('appearance', 'system')->get('/an-address-that-does-not-exist')->getContent();

        $this->assertStringContainsString("const appearance = 'system';", $system);

        // Anything else than a known theme falls back to the default one.
        $this->assertMatchesRegularExpression(
            '/<html lang="fr" class="dark">/',
            (string) $this->withUnencryptedCookie('appearance', "';alert(1);//")->get('/an-address-that-does-not-exist')->getContent(),
        );
    }
}
