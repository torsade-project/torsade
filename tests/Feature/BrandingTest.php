<?php

// torsade/tests/Feature/BrandingTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The interface carries the name and the logo of the application, and
 * nothing of the framework it is built with: no logo, no icon, no visible
 * mention.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SHA-1 of the icon files that shipped with the framework's skeleton.
     */
    private const FRAMEWORK_ICONS = [
        'favicon.ico' => '4de5db574e6c589ef763ce57c4061f2269648ca4',
        'favicon.svg' => '139952bf6929a4d223bec823dc1953798895ec6c',
        'apple-touch-icon.png' => 'cdedf5b84942a5511948d1851ecd2c7930b5b1b9',
    ];

    /**
     * The two strands of the logo, drawn in a square of 32 units.
     */
    private const STRANDS = [
        'M3 16 C 8 5, 12 5, 16 16 S 24 27, 29 16',
        'M3 16 C 8 27, 12 27, 16 16 S 24 5, 29 16',
    ];

    /**
     * Technical identifiers that are not part of what a user sees: package
     * names, import paths, a constant and a comment of the front controller.
     */
    private const TECHNICAL_IDENTIFIERS = [
        '@laravel/',
        'laravel-vite-plugin',
        'vendor/laravel/',
        'LARAVEL_START',
        'Bootstrap Laravel and handle the request',
    ];

    public function test_the_framework_logo_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('js/components/AppLogoIcon.vue'));

        $files = Finder::create()->files()->in(resource_path('js'))->name(['*.vue', '*.ts']);

        foreach ($files as $file) {
            $this->assertStringNotContainsString('AppLogoIcon', $file->getContents(), $file->getRelativePathname());
        }
    }

    public function test_the_icons_are_not_those_of_the_framework(): void
    {
        foreach (self::FRAMEWORK_ICONS as $name => $frameworkHash) {
            $path = public_path($name);

            $this->assertFileExists($path);
            $this->assertNotSame($frameworkHash, sha1_file($path), "[{$name}] is still the framework's icon.");
        }

        // The path of the framework's logo, as it was drawn in the SVG icon.
        $this->assertStringNotContainsString('M162.041 38.7592', (string) file_get_contents(public_path('favicon.svg')));
    }

    public function test_the_logo_and_the_favicon_draw_the_same_two_strands(): void
    {
        $logo = (string) file_get_contents(resource_path('js/components/LogoMark.vue'));
        $favicon = (string) file_get_contents(public_path('favicon.svg'));

        foreach (self::STRANDS as $strand) {
            $this->assertStringContainsString('d="'.$strand.'"', $logo);
            $this->assertStringContainsString('d="'.$strand.'"', $favicon);
        }

        // In the interface, one strand follows the colour of the text and
        // the other is the orange pair of the theme: both stay readable in
        // the light and the dark theme.
        $this->assertStringContainsString('stroke-current', $logo);
        $this->assertStringContainsString('stroke-pair-orange', $logo);
        $this->assertStringContainsString('aria-hidden="true"', $logo);

        // The symbol holds no text: the name is written next to it.
        $this->assertStringNotContainsString('<text', $logo);
        $this->assertStringNotContainsString('<text', $favicon);
    }

    public function test_the_binary_icons_are_images_of_the_expected_sizes(): void
    {
        $apple = getimagesize(public_path('apple-touch-icon.png'));

        $this->assertIsArray($apple);
        $this->assertSame([180, 180, 'image/png'], [$apple[0], $apple[1], $apple['mime']]);

        // An ICO file: a header that counts its images, then one entry of
        // sixteen bytes per image, starting with its width and its height.
        $icon = (string) file_get_contents(public_path('favicon.ico'));
        $header = unpack('vreserved/vtype/vcount', $icon);

        $this->assertSame(['reserved' => 0, 'type' => 1, 'count' => 3], $header);

        $sizes = [];

        for ($entry = 0; $entry < 3; $entry++) {
            $sizes[] = [ord($icon[6 + 16 * $entry]), ord($icon[7 + 16 * $entry])];
        }

        $this->assertSame([[16, 16], [32, 32], [48, 48]], $sizes);
    }

    public function test_the_page_shell_points_to_the_three_icons(): void
    {
        $html = (string) $this->get(route('login'))->getContent();

        foreach (array_keys(self::FRAMEWORK_ICONS) as $name) {
            $this->assertStringContainsString('href="/'.$name.'"', $html);
        }
    }

    public function test_no_interface_file_mentions_the_framework(): void
    {
        $files = Finder::create()
            ->files()
            ->in([resource_path(), public_path(), lang_path()])
            // Compiled bundles, and modules generated from the registered routes.
            ->exclude(['build', 'actions', 'routes', 'wayfinder'])
            ->notName(['*.png', '*.ico']);

        $this->assertGreaterThan(0, $files->count());

        foreach ($files as $file) {
            $content = str_replace(self::TECHNICAL_IDENTIFIERS, '', $file->getContents());

            $this->assertFalse(
                stripos($content, 'laravel'),
                $file->getRelativePathname().' mentions the framework.',
            );
        }
    }

    public function test_the_login_page_shows_the_application_name_and_no_framework_brand(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('name', 'Torsade'));

        $html = (string) $response->getContent();

        $this->assertStringContainsString('<title>Torsade</title>', $html);
        $this->assertFalse(stripos($html, 'laravel'), 'The login page mentions the framework.');

        foreach ($response->headers->all() as $name => $values) {
            $this->assertFalse(stripos($name.': '.implode(', ', $values), 'laravel'), "The [{$name}] header mentions the framework.");
        }
    }

    public function test_a_signed_in_page_shows_no_framework_brand(): void
    {
        $html = (string) $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertFalse(stripos($html, 'laravel'), 'The dashboard mentions the framework.');
    }

    public function test_the_application_name_is_shown_as_text(): void
    {
        $sidebar = (string) file_get_contents(resource_path('js/components/AppLogo.vue'));
        $login = (string) file_get_contents(resource_path('js/layouts/auth/AuthSimpleLayout.vue'));

        // Both read the configured name and render it as text. The symbol
        // next to it is a component of its own, which holds no text.
        foreach ([$sidebar, $login] as $component) {
            $this->assertStringContainsString('usePage().props.name', $component);
            $this->assertStringNotContainsString('<svg', $component);
            $this->assertStringNotContainsString('<img', $component);
        }
    }
}
