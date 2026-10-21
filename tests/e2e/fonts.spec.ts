// torsade/tests/e2e/fonts.spec.ts
import type { Locator } from '@playwright/test';
import {
    addressTable,
    dataRows,
    expect,
    openSubnet,
    test,
} from './support/fixtures';

// These tests are about the way the fonts are served: the browser asks
// the server for every file of the build.
test.use({ assetsFromServer: true });

const fontFamily = (element: Locator): Promise<string> =>
    element.evaluate((node) => getComputedStyle(node).fontFamily);

test.beforeEach(async ({ page, logIn }) => {
    await logIn('reader');
    await openSubnet(page, '10.10.10.0/24');
    await page.evaluate(() => document.fonts.ready);
});

test('the typefaces of the interface are loaded from the application itself', async ({
    page,
    requests,
    baseURL,
}) => {
    const cells = dataRows(addressTable(page)).first().getByRole('cell');

    // The text is set in Barlow, the addresses in IBM Plex Mono.
    expect(await fontFamily(page.locator('body'))).toMatch(/^"?Barlow/);
    expect(await fontFamily(cells.nth(0))).toMatch(/^"?IBM Plex Mono/);
    expect(await fontFamily(cells.nth(1))).toMatch(/^"?Barlow/);

    const loaded = await page.evaluate(() =>
        [...document.fonts]
            .filter((font) => font.status === 'loaded')
            .map((font) => font.family.replaceAll('"', '')),
    );

    expect(loaded).toContain('Barlow');
    expect(loaded).toContain('IBM Plex Mono');

    // The font files came from the application, like everything else.
    const fontFiles = requests.local.filter((url) => url.endsWith('.woff2'));

    expect(fontFiles.length).toBeGreaterThan(0);
    expect(
        fontFiles.filter((url) => !url.startsWith(`${baseURL}/build/assets/`)),
    ).toEqual([]);
    expect(requests.external).toEqual([]);
});

test('figures have the same width in tables, so that columns line up', async ({
    page,
}) => {
    // Measured in the host name cell, set in the text typeface: in Barlow,
    // "1" is narrower than "0" unless tabular figures are on.
    const cell = dataRows(addressTable(page)).first().getByRole('cell').nth(1);

    expect(await fontFamily(cell)).toMatch(/^"?Barlow/);

    const widths = await cell.evaluate((node) => {
        const measure = (text: string, proportional = false): number => {
            const probe = document.createElement('span');

            probe.textContent = text;

            if (proportional) {
                probe.style.fontVariantNumeric = 'normal';
            }

            node.append(probe);

            const { width } = probe.getBoundingClientRect();

            probe.remove();

            return width;
        };

        return {
            ones: measure('1111111111'),
            zeros: measure('0000000000'),
            proportionalOnes: measure('1111111111', true),
        };
    });

    expect(widths.ones).toBeGreaterThan(0);
    expect(widths.ones).toBeCloseTo(widths.zeros, 1);

    // Without the setting, the same figures would not line up.
    expect(widths.proportionalOnes).toBeLessThan(widths.zeros - 1);
});
