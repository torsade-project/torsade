// torsade/tests/e2e/diagram-cabling.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// The cabling on the physical diagram of the demonstration: the patch
// panels a connection goes through, the jacks naming the segments, the end
// devices, shown or hidden as the user last left them for every site, and
// the panels placed by hand.

const diagram = (page: Page): Locator => page.locator('[data-test="diagram"]');

const device = (page: Page, name: string): Locator =>
    diagram(page).locator(`a[data-device="${name}"]`);

const panel = (page: Page, name: string): Locator =>
    diagram(page).locator(`a[data-panel="${name}"]`);

const endDevices = (page: Page): Locator =>
    page.locator('[data-test="show-end-devices"]');

const openDiagram = async (page: Page, site: string): Promise<void> => {
    await page.goto('/diagrams/physical');
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: site });
    await expect(
        page.getByRole('heading', { name: `Schéma physique du site ${site}` }),
    ).toBeVisible();
    await expect(diagram(page).locator('a[data-device]').first()).toBeVisible();
};

test('the panels a connection goes through are drawn with the ports used, the jacks naming the segments', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openDiagram(page, 'Siège');

    // The panels of the floors, each with its ports used alone.
    await expect(panel(page, 'PB-B')).toBeVisible();
    await expect(panel(page, 'PB-C')).toBeVisible();
    await expect(panel(page, 'PB-A')).toHaveCount(0);
    await expect(panel(page, 'PB-B').locator('[data-panel-port]')).toHaveCount(
        3,
    );

    // The jacks, on the segments from the panels to the devices plugged in,
    // in the order of the names of their ends.
    const jacks = diagram(page).locator('[data-test="jack-label"]');

    await expect(jacks).toHaveCount(4);
    expect(
        (await jacks.allTextContents()).map((text) => text.trim()).sort(),
    ).toEqual(['B.13', 'B.15', 'B.16', 'C.10']);

    // The printer is cabled by its address: an end device, smaller.
    await expect(device(page, 'imp-compta')).toHaveAttribute(
        'data-end-device',
        'true',
    );
    await expect(device(page, 'sw-etage-1')).not.toHaveAttribute(
        'data-end-device',
        'true',
    );

    // The tooltip of a link cabled through a panel tells its path.
    await expect(
        diagram(page)
            .locator('[data-segment="rack"]')
            .filter({ hasText: 'ap-etage-1' })
            .locator('title'),
    ).toContainText('par PB-B 13 › B.13');

    // The legend has the panel, the jack and the end device.
    const legend = page.locator('[data-test="diagram-legend"]');

    for (const word of ['Panneau', 'Prise', 'Poste']) {
        await expect(legend).toContainText(word);
    }

    // A panel leads to its page.
    await panel(page, 'PB-B').click();
    await expect(page.getByRole('heading', { name: /PB-B/ })).toBeVisible();
});

test('the end devices are hidden with their connections, counted on what they are joined to, and the choice is kept for every site', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openDiagram(page, 'Siège');

    const summary = page.locator('[data-test="diagram-summary"]');

    await expect(endDevices(page)).toBeChecked();
    await expect(summary).toContainText('1 poste');

    await endDevices(page).uncheck();

    // The printer goes, with the port of the panel that only served it;
    // the switch it is cabled to says so. The summary still counts it.
    await expect(device(page, 'imp-compta')).toHaveCount(0);
    await expect(device(page, 'sw-etage-1')).toHaveAttribute(
        'data-hidden-end-devices',
        '1',
    );
    await expect(
        device(page, 'sw-etage-1').locator('[data-test="hidden-end-devices"]'),
    ).toHaveText('1 poste');
    await expect(panel(page, 'PB-B').locator('[data-panel-port]')).toHaveCount(
        2,
    );
    await expect(summary).toContainText('1 poste');

    // Kept after a reload, and on another site.
    await page.reload();
    await expect(endDevices(page)).not.toBeChecked();
    await expect(device(page, 'imp-compta')).toHaveCount(0);

    await page.locator('[data-test="group-by-place"]').uncheck();
    await openDiagram(page, 'Atelier');
    await expect(endDevices(page)).not.toBeChecked();
    await expect(
        page.locator('[data-test="group-by-place"]'),
    ).not.toBeChecked();

    // Back as they were.
    await endDevices(page).check();
    await page.locator('[data-test="group-by-place"]').check();
    await openDiagram(page, 'Siège');
    await expect(device(page, 'imp-compta')).toBeVisible();
});

test('without the memory of the browser, the diagram shows everything, grouped by place', async ({
    page,
    logIn,
}) => {
    // The storage refuses to be read and written, as in private browsing
    // with the data of sites blocked.
    await page.addInitScript(() => {
        const refuse = (): never => {
            throw new DOMException('Blocked', 'SecurityError');
        };

        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            get: refuse,
        });
    });

    await logIn('reader');
    await openDiagram(page, 'Siège');

    await expect(endDevices(page)).toBeChecked();
    await expect(page.locator('[data-test="group-by-place"]')).toBeChecked();

    // Changed, it works all the same, for this page.
    await endDevices(page).uncheck();
    await expect(device(page, 'imp-compta')).toHaveCount(0);
});

test('while the devices are placed, the end devices are shown, and a panel is placed and saved as a device', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDiagram(page, 'Siège');
    await endDevices(page).uncheck();
    await expect(device(page, 'imp-compta')).toHaveCount(0);

    await page.locator('[data-test="edit-layout"]').click();
    await expect(page.locator('[data-test="layout-bar"]')).toBeVisible();

    // Shown, placed where the layout with them puts them, and the box
    // cannot be changed meanwhile.
    await expect(device(page, 'imp-compta')).toBeVisible();
    await expect(device(page, 'imp-compta')).not.toHaveAttribute(
        'data-unplaced',
        'true',
    );
    await expect(endDevices(page)).toBeChecked();
    await expect(endDevices(page)).toBeDisabled();

    // The panel of the first floor, moved from the keyboard.
    const before = Number(await panel(page, 'PB-B').getAttribute('data-x'));

    await panel(page, 'PB-B').focus();
    await page.keyboard.press('Shift+ArrowRight');
    await expect(panel(page, 'PB-B')).toHaveAttribute(
        'data-x',
        String(before + 40),
    );

    await page.locator('[data-test="save-layout"]').click();
    await expect(
        page.getByText(/^Disposition enregistrée : \d+ éléments placés\.$/),
    ).toBeVisible();

    // Hidden again as before; the panel keeps its place after a reload.
    await expect(endDevices(page)).not.toBeChecked();
    await expect(device(page, 'imp-compta')).toHaveCount(0);
    await endDevices(page).check();
    await page.reload();
    await expect(panel(page, 'PB-B')).toHaveAttribute(
        'data-x',
        String(before + 40),
    );

    // Back to the automatic layout, for the tests that follow.
    await page.locator('[data-test="edit-layout"]').click();
    await page.locator('[data-test="reset-layout"]').click();
    await page
        .getByRole('dialog', {
            name: 'Réorganiser automatiquement le schéma ?',
        })
        .getByRole('button', { name: 'Réorganiser' })
        .click();
    await expect(
        page.getByText('Le schéma est de nouveau disposé automatiquement.'),
    ).toBeVisible();
});

test('the cablings of the workshop that differ are drawn and listed, with the link to create', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDiagram(page, 'Atelier');

    // Placed by hand: the panel of the technical room too.
    await expect(panel(page, 'PB-A')).toBeVisible();
    await expect(panel(page, 'PB-A')).not.toHaveAttribute(
        'data-unplaced',
        'true',
    );

    const items = page
        .getByRole('region', { name: 'Écarts du schéma' })
        .locator('[data-test="deviation-item"]');

    await expect(items.filter({ hasText: 'PB-A 9 › H.03' })).toContainText(
        'sans liaison',
    );
    await expect(items.filter({ hasText: 'PB-A 10 › H.04' })).toContainText(
        'leur liaison est retirée',
    );

    // The link to create leads to the window of the port, the port in
    // front chosen.
    await items
        .filter({ hasText: 'PB-A 9 › H.03' })
        .getByRole('link', { name: 'Créer la liaison' })
        .click();

    const dialog = page.getByRole('dialog', { name: /Relier/ });

    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Annuler' }).click();
});
