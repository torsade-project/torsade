<?php

// torsade/tests/Feature/EmbeddedFontsTest.php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The typefaces of the interface are part of the build and are served by
 * the application: no browser ever fetches them from another host.
 */
class EmbeddedFontsTest extends TestCase
{
    /**
     * The weights the interface uses, per typeface.
     */
    private const TYPEFACES = [
        'Barlow' => ['file' => 'barlow', 'weights' => [400, 500, 600, 700]],
        'IBM Plex Mono' => ['file' => 'ibm-plex-mono', 'weights' => [400, 500]],
    ];

    private const SUBSETS = ['latin', 'latin-ext'];

    public function test_the_built_stylesheet_declares_each_typeface_from_a_file_of_the_build(): void
    {
        $faces = $this->fontFaces();

        $expected = [];

        foreach (self::TYPEFACES as $family => $typeface) {
            foreach ($typeface['weights'] as $weight) {
                foreach (self::SUBSETS as $subset) {
                    $expected[] = "{$family} {$weight} {$typeface['file']}-{$subset}-{$weight}-normal";
                }
            }
        }

        $declared = [];

        foreach ($faces as $face) {
            $this->assertCount(1, $face['urls'], 'A typeface is declared with one file.');

            $url = $face['urls'][0];

            $this->assertMatchesRegularExpression('~^/build/assets/[a-z0-9-]+-normal-[\w-]+\.woff2$~', $url);
            $this->assertFileExists(public_path(ltrim($url, '/')));
            $this->assertNotSame('', $face['range'], 'A subset is only used for its own characters.');

            // "barlow-latin-400-normal-qiz4Cze.woff2": the name without its hash.
            $name = (string) preg_replace('~^/build/assets/(.+-normal)-[\w-]+\.woff2$~', '$1', $url);

            $declared[] = "{$face['family']} {$face['weight']} {$name}";
        }

        sort($expected);
        sort($declared);

        // Nothing else: no other weight, no italic, no other subset.
        $this->assertSame($expected, $declared);
    }

    public function test_the_built_stylesheets_load_nothing_from_another_host(): void
    {
        $stylesheets = glob(public_path('build/assets/*.css')) ?: [];

        $this->assertNotEmpty($stylesheets);

        foreach ($stylesheets as $stylesheet) {
            $css = (string) file_get_contents($stylesheet);

            $this->assertDoesNotMatchRegularExpression('~@import\s~', $css, basename($stylesheet));

            preg_match_all('~url\(\s*["\']?([^"\')]+)~', $css, $urls);

            foreach ($urls[1] as $url) {
                $this->assertTrue(
                    str_starts_with($url, '/build/assets/') || str_starts_with($url, 'data:'),
                    basename($stylesheet)." loads {$url}.",
                );
            }
        }
    }

    public function test_the_build_ships_the_licence_of_each_typeface(): void
    {
        foreach (self::TYPEFACES as $family => $typeface) {
            $shipped = public_path("build/assets/{$typeface['file']}-LICENSE.txt");

            $this->assertFileExists($shipped, "The licence of {$family} is not in the build.");

            $text = (string) file_get_contents($shipped);

            // The full text of the licence, with the copyright notices.
            $this->assertStringContainsString('Copyright', $text);
            $this->assertStringContainsString('SIL OPEN FONT LICENSE Version 1.1', $text);
            $this->assertStringContainsString('PERMISSION & CONDITIONS', $text);
            $this->assertStringContainsString('DISCLAIMER', $text);
            $this->assertGreaterThan(4000, strlen($text));

            // As the installed package provides it, to the letter.
            $this->assertSame(
                (string) file_get_contents(base_path("node_modules/@fontsource/{$typeface['file']}/LICENSE")),
                $text,
                "The licence of {$family} differs from the one of its package.",
            );

            // Next to the files of the typeface.
            $this->assertNotEmpty(glob(public_path("build/assets/{$typeface['file']}-latin-*.woff2")));
        }
    }

    public function test_the_interface_uses_the_embedded_typefaces(): void
    {
        $css = $this->stylesheet();

        $this->assertMatchesRegularExpression('~--font-sans:\s*["\']?Barlow~', $css);
        $this->assertMatchesRegularExpression('~--font-mono:\s*["\']?IBM Plex Mono~', $css);
    }

    /**
     * @return list<array{family: string, weight: int, urls: list<string>, range: string}>
     */
    private function fontFaces(): array
    {
        preg_match_all('~@font-face\s*\{([^}]*)\}~', $this->stylesheet(), $blocks);

        $faces = [];

        foreach ($blocks[1] as $block) {
            preg_match('~font-family:\s*["\']?([^;"\']+)~', $block, $family);
            preg_match('~font-weight:\s*(\d+)~', $block, $weight);
            preg_match('~unicode-range:\s*([^;]+)~', $block, $range);
            preg_match_all('~url\(\s*["\']?([^"\')]+)~', $block, $urls);

            $faces[] = [
                'family' => trim($family[1] ?? ''),
                'weight' => (int) ($weight[1] ?? 0),
                'urls' => array_values($urls[1]),
                'range' => trim($range[1] ?? ''),
            ];
        }

        return $faces;
    }

    /**
     * The stylesheet of the application, as built by "npm run build".
     */
    private function stylesheet(): string
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('resources/css/app.css', $manifest);

        return (string) file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));
    }
}
