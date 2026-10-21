// torsade/tests/e2e/appearance.spec.ts
import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

const pageBackground = (page: Page): Promise<string> =>
    page.evaluate(() => getComputedStyle(document.body).backgroundColor);

const DARK = /(^|\s)dark(\s|$)/;

test('the theme switches between light and dark, and the choice is kept after a reload', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await page.goto('/settings/appearance');

    const root = page.locator('html');

    await page.getByRole('button', { name: 'Sombre' }).click();
    await expect(root).toHaveClass(DARK);

    const dark = await pageBackground(page);

    await page.reload();
    await expect(root).toHaveClass(DARK);
    expect(await pageBackground(page)).toBe(dark);

    await page.getByRole('button', { name: 'Clair' }).click();
    await expect(root).not.toHaveClass(DARK);

    const light = await pageBackground(page);

    expect(light).not.toBe(dark);

    await page.reload();
    await expect(root).not.toHaveClass(DARK);
    expect(await pageBackground(page)).toBe(light);

    // The choice follows the user on the other pages.
    await page.goto('/vlans');
    await expect(root).not.toHaveClass(DARK);
    expect(await pageBackground(page)).toBe(light);
});

test.describe('for someone who never chose a theme', () => {
    // The system of this visitor prefers the light theme.
    test.use({ colorScheme: 'light' });

    test('the dark theme is the default one, whatever the system prefers', async ({
        page,
        logIn,
    }) => {
        const root = page.locator('html');

        // Before any script runs: the server sets the theme on the page.
        const response = await page.goto('/login');

        expect(await response?.text()).toMatch(/<html lang="fr" class="dark">/);
        await expect(root).toHaveClass(DARK);

        const dark = await pageBackground(page);

        await logIn('reader');
        await expect(root).toHaveClass(DARK);
        expect(await pageBackground(page)).toBe(dark);

        // The settings show the dark theme as the current choice.
        await page.goto('/settings/appearance');
        await expect(
            page.getByRole('button', { name: 'Sombre' }),
        ).toHaveAttribute('aria-pressed', 'true');
        await expect(
            page.getByRole('button', { name: 'Système' }),
        ).toHaveAttribute('aria-pressed', 'false');

        // Following the system remains a choice, kept like the others.
        await page.getByRole('button', { name: 'Système' }).click();
        await expect(root).not.toHaveClass(DARK);
        expect(await pageBackground(page)).not.toBe(dark);

        await page.reload();
        await expect(root).not.toHaveClass(DARK);
        await expect(
            page.getByRole('button', { name: 'Système' }),
        ).toHaveAttribute('aria-pressed', 'true');
    });
});

test('when the browser refuses its storage, the pages still work and the theme is kept by its cookie', async ({
    page,
    logIn,
}) => {
    // Storage that throws, as it can in a private window or with the data
    // of the site blocked.
    await page.addInitScript(() => {
        const refuse = (): never => {
            throw new DOMException('Storage is disabled', 'SecurityError');
        };

        Storage.prototype.getItem = refuse;
        Storage.prototype.setItem = refuse;
    });

    await logIn('reader');

    const root = page.locator('html');

    await expect(root).toHaveClass(DARK);

    await page.goto('/settings/appearance');
    await page.getByRole('button', { name: 'Clair' }).click();
    await expect(root).not.toHaveClass(DARK);

    // The server reads the cookie: the next page comes in the light theme.
    const response = await page.goto('/vlans');

    expect(await response?.text()).toMatch(/<html lang="fr" class="">/);
    await expect(page.getByRole('heading', { name: 'VLAN' })).toBeVisible();
    // And the page, once its scripts have run, stays in it.
    await expect(root).not.toHaveClass(DARK);
});
