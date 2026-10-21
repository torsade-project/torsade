// torsade/tests/e2e/panel-jacks.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// From the page of a patch panel, a port is cabled to a jack without a
// horizontal cable of any floor of its site, offered by building and floor.

const LABEL = 'T.99';

const openSite = async (page: Page, name: string): Promise<void> => {
    await page.goto('/sites');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name: `Site ${name}`, exact: true }),
    ).toBeVisible();
};

const floorLine = (page: Page, name: string): Locator =>
    page.locator('[data-test="place-floor"]').filter({
        has: page.getByText(name, { exact: true }),
    });

test('a jack of another floor of the site is offered to a port of a panel', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openSite(page, 'Siège');

    // A jack of the second floor, cabled to no panel.
    await floorLine(page, '2e étage')
        .locator('[data-test^="create-jack-"]')
        .click();

    const jackWindow = page.locator('[data-test="jack-dialog"]');
    await jackWindow.getByLabel('Libellé').fill(LABEL);
    await jackWindow.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(jackWindow).toBeHidden();

    // The panel of the first floor.
    await page
        .locator('[data-test="place-panel"][data-panel="PB-B"]')
        .getByRole('link', { name: 'PB-B', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'PB-B', exact: true }),
    ).toBeVisible();

    await page.locator('[data-test="attach-jack-20"]').click();

    const attachWindow = page.locator('[data-test="jack-attach-dialog"]');
    const choice = attachWindow.locator('[data-test="attach-jack"]');

    // Grouped by building and floor: the jack of the second floor is
    // there, under its floor.
    await expect(
        choice.locator(
            'optgroup[label="Bâtiment principal › 2e étage"] option',
            {
                hasText: LABEL,
            },
        ),
    ).toHaveCount(1);

    await choice.selectOption({ label: LABEL });
    await attachWindow.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(attachWindow).toBeHidden();
    await expect(
        page.locator('[data-test="panel-port-row"][data-port="20"]'),
    ).toContainText(LABEL);

    // The jack is taken away again, for the tests that follow.
    await page.goto('/jacks');
    const row = page.locator(`[data-test="jack-row"][data-jack="${LABEL}"]`);
    await row.getByRole('button', { name: 'Supprimer' }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(row).toHaveCount(0);
});
