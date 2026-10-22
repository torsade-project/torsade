<?php

// torsade/tests/Feature/VersionTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The version number has a single source, the VERSION file. It is shown to
 * signed-in users and by "php artisan about", never to an anonymous visitor.
 */
class VersionTest extends TestCase
{
    use RefreshDatabase;

    private function versionInFile(): string
    {
        return trim((string) file_get_contents(base_path('VERSION')));
    }

    public function test_the_version_file_holds_a_semantic_version_and_a_line_feed(): void
    {
        $content = (string) file_get_contents(base_path('VERSION'));

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+\n$/', $content);
        $this->assertSame("0.20.0\n", $content);
    }

    public function test_the_configuration_reads_the_version_file(): void
    {
        $this->assertSame($this->versionInFile(), config('app.version'));
    }

    public function test_the_version_is_dev_when_the_file_is_missing_or_empty(): void
    {
        $this->assertSame('dev', Version::fromFile(base_path('no-such-VERSION-file')));

        $empty = tempnam(sys_get_temp_dir(), 'ver');
        $this->assertNotFalse($empty);
        file_put_contents($empty, "  \n");

        try {
            $this->assertSame('dev', Version::fromFile($empty));
        } finally {
            unlink($empty);
        }
    }

    public function test_artisan_about_shows_the_version(): void
    {
        Artisan::call('about', ['--only' => 'torsade', '--json' => true]);

        $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($this->versionInFile(), $about['torsade']['version'] ?? null);
    }

    public function test_a_signed_in_user_receives_the_version(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('appVersion', $this->versionInFile()));
    }

    public function test_the_sidebar_displays_the_version_it_receives(): void
    {
        // The block of the signed-in user, at the foot of the sidebar: the
        // version follows the role, and only when the server sent one.
        $sidebar = (string) file_get_contents(resource_path('js/components/AppSidebar.vue'));
        $userBlock = (string) file_get_contents(resource_path('js/components/NavUser.vue'));

        $this->assertStringContainsString('<NavUser />', $sidebar);
        $this->assertMatchesRegularExpression('/page\.props\.appVersion\s*\?/', $userBlock);
        $this->assertStringContainsString('version: page.props.appVersion', $userBlock);
    }

    public function test_an_anonymous_visitor_learns_nothing_about_the_version(): void
    {
        $version = $this->versionInFile();

        foreach ([route('login'), '/', '/this-page-does-not-exist', route('dashboard')] as $url) {
            $response = $this->get($url);

            $this->assertStringNotContainsString($version, (string) $response->getContent(), "The body of [{$url}] holds the version.");

            foreach ($response->headers->all() as $name => $values) {
                $this->assertStringNotContainsString($version, implode(', ', $values), "The [{$name}] header of [{$url}] holds the version.");
            }
        }

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('appVersion', null));
    }

    public function test_the_changelog_has_an_entry_for_the_current_version(): void
    {
        $changelog = (string) file_get_contents(base_path('CHANGELOG.md'));

        $this->assertStringContainsString('## [Unreleased]', $changelog);
        $this->assertMatchesRegularExpression('/^## \['.preg_quote($this->versionInFile(), '/').'\] - \d{4}-\d{2}-\d{2}$/m', $changelog);

        // Most recent first: the first released section is the current version.
        preg_match_all('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $releases);

        $this->assertSame($this->versionInFile(), $releases[1][0] ?? null);
    }
}
