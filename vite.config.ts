// torsade/vite.config.ts
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import type { Plugin } from 'vite-plus';
import { defineConfig, lazyPlugins } from 'vite-plus';

// What is embedded in the build and asks that its licence travels with it:
// the typefaces, and the engine that lays the diagrams out. Each licence is
// copied as the installed package provides it, under the name given.
const EMBEDDED_LICENCES = [
    { file: 'barlow-LICENSE.txt', from: '@fontsource/barlow/LICENSE' },
    {
        file: 'ibm-plex-mono-LICENSE.txt',
        from: '@fontsource/ibm-plex-mono/LICENSE',
    },
    { file: 'elkjs-LICENSE.txt', from: 'elkjs/LICENSE.md' },
];

// At each build, the licences are copied next to the files of the build,
// copyright notices included: they are then part of whatever is shipped
// from it. A package without its licence fails the build.
const embeddedLicences = (): Plugin => {
    let forTheBrowser = true;

    return {
        name: 'torsade:embedded-licences',
        apply: 'build',
        configResolved(config) {
            forTheBrowser = !config.build.ssr;
        },
        generateBundle() {
            if (!forTheBrowser) {
                return;
            }

            for (const licence of EMBEDDED_LICENCES) {
                this.emitFile({
                    type: 'asset',
                    fileName: `assets/${licence.file}`,
                    source: readFileSync(
                        fileURLToPath(
                            new URL(
                                `./node_modules/${licence.from}`,
                                import.meta.url,
                            ),
                        ),
                    ),
                });
            }
        },
    };
};

export default defineConfig({
    build: {
        // Every file of the build is served from the application, never
        // written into another as a data: address, which the policy of the
        // fonts and the images does not allow.
        assetsInlineLimit: 0,
    },
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        embeddedLicences(),
    ]),
    server: {
        watch: {
            ignored: ['**/vendor/**'],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
