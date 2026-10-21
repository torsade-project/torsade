// torsade/tests/e2e/vlans-across-sites.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// VLANs carried out of their site, on the demonstration network: the
// firewall of the head office routes the networks of the workshop on an
// aggregate of their own, two VLANs of one number never share a port, and
// the fibre under study between the two sites merges their VLAN 10 until
// one of its ends is corrected.

const MERGED =
    "VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux";

const openDevice = async (page: Page, name: string): Promise<void> => {
    await page.goto('/devices');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name, exact: true }),
    ).toBeVisible();
};

const portRow = (page: Page, name: string): Locator =>
    page.locator(`[data-test="port-row"][data-port="${name}"]`);

const aggregateRow = (page: Page, name: string): Locator =>
    page.locator(`[data-test="aggregate-row"][data-aggregate="${name}"]`);

const linkRow = (page: Page, port: string): Locator =>
    page.locator(
        `[data-test="device-links"] [data-test="link-row"][data-link="${port}"]`,
    );

// The group of the VLANs of a site among the tagged VLANs of a window.
const siteGroup = (dialog: Locator, prefix: string, site: string): Locator =>
    dialog.locator(`[data-test^="${prefix}-site-"]`).filter({ hasText: site });

test('the VLANs of the workshop are tagged on an aggregate of the firewall of the head office, which faces the workshop without a deviation', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // At the workshop, an aggregate of two fibre ports for its networks.
    await openDevice(page, 'sw-atelier-01');
    await page.locator('[data-test="add-aggregate"]').click();

    const dialog = page.locator('[data-test="aggregate-dialog"]');

    await dialog.getByLabel('Nom', { exact: true }).fill('Po1');
    await dialog.getByRole('checkbox', { name: '1/1/3', exact: true }).check();
    await dialog.getByRole('checkbox', { name: '1/1/4', exact: true }).check();
    await dialog.getByLabel('Mode').selectOption({ label: 'Trunk' });
    // Every VLAN of its own site.
    await dialog.locator('[data-test="aggregate-tag-all"]').click();
    await dialog.locator('[data-test="save-aggregate"]').click();
    await expect(page.getByText('Agrégat créé.')).toBeVisible();
    await expect(aggregateRow(page, 'Po1')).toContainText(
        /Trunk : 10, .*60, 70, 99, 150/,
    );

    // The VLANs of the workshop, as many as the tests before this one left.
    const vlans = /Trunk : ([\d, ]+\d)/.exec(
        await aggregateRow(page, 'Po1').innerText(),
    )?.[1];

    expect(vlans).toBeDefined();

    // On the firewall of the head office, the VLANs of its own site come
    // first; those of the workshop are folded away.
    await openDevice(page, 'fw-siege');
    await page.locator('[data-test="add-aggregate"]').click();
    await dialog.getByLabel('Nom', { exact: true }).fill('Po2');
    await dialog.getByRole('checkbox', { name: 'port5', exact: true }).check();
    await dialog.getByRole('checkbox', { name: 'port6', exact: true }).check();
    await dialog.getByLabel('Mode').selectOption({ label: 'Trunk' });

    const own = dialog.locator('[data-test="aggregate-own-site"]');
    const workshop = siteGroup(dialog, 'aggregate', 'Atelier');
    const toggle = workshop.getByRole('button', { name: /^Atelier/ });

    await expect(own).toContainText('Siège');
    await expect(
        own.getByRole('checkbox', { name: '10 — Bureautique' }),
    ).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(
        workshop.getByRole('checkbox', { name: '60 — Production' }),
    ).toBeHidden();

    // The single choices follow the same groups.
    await expect(
        dialog.getByLabel('VLAN natif').locator('optgroup').first(),
    ).toHaveAttribute('label', 'Siège');
    await expect(
        dialog.getByLabel('VLAN natif').locator('optgroup').nth(1),
    ).toHaveAttribute('label', 'Atelier');

    // Opened, every VLAN of the workshop is tagged at once.
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await workshop.getByRole('button', { name: 'Tous' }).click();
    await expect(toggle).toContainText(
        `${vlans?.split(', ').length} VLAN cochés`,
    );
    await expect(
        own.getByRole('checkbox', { name: '10 — Bureautique' }),
    ).not.toBeChecked();
    await dialog.locator('[data-test="save-aggregate"]').click();
    await expect(page.getByText('Agrégat créé.')).toBeVisible();

    // What it carries names the site of these VLANs, on the aggregate and
    // on its ports.
    await expect(aggregateRow(page, 'Po2')).toContainText(
        `Trunk : ATE ${vlans}`,
    );
    await expect(
        portRow(page, 'port5').locator('[data-test="port-via-aggregate"]'),
    ).toContainText(`Trunk : ATE ${vlans}`);

    // Opened again, the group of the workshop is open: the aggregate
    // carries its VLANs.
    await page.locator('[data-test="edit-aggregate-Po2"]').click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(
        workshop.getByRole('checkbox', { name: '60 — Production' }),
    ).toBeChecked();
    await dialog.getByRole('button', { name: 'Annuler' }).click();

    // Its two ports linked to the aggregate of the workshop.
    await portRow(page, 'port5').getByRole('checkbox').click();
    await portRow(page, 'port6').getByRole('checkbox').click();
    await page.locator('[data-test="link-ports"]').click();

    const link = page.locator('[data-test="link-dialog"]');
    const opposite = (port: string): Locator =>
        link.locator(`[data-test="opposite-port"][data-port="${port}"]`);

    await link.locator('[data-test="link-search"]').fill('atelier-01');
    await link.locator('[data-test="choose-device-sw-atelier-01"]').click();
    // The ports of the workshop say what they carry from their own site.
    await expect(opposite('1/1/3')).toContainText(`via Po1 : Trunk : ${vlans}`);
    await opposite('1/1/3').locator('input').check();
    await opposite('1/1/4').locator('input').check();
    await link.locator('[data-test="save-link"]').click();
    await expect(page.getByText('2 liaisons créées.')).toBeVisible();

    // One aggregated link, with nothing that differs: the same VLANs of
    // the workshop at both ends.
    await page.reload();

    const aggregated = page.locator(
        '[data-test="device-links"] [data-test="aggregated-link-row"][data-link="Po2"]',
    );

    await expect(aggregated).toContainText('Liaison agrégée, 2 liens');
    await expect(aggregated).toContainText(/sw-atelier-01\s*›\s*Po1/);
    await expect(aggregated.locator('[data-test="deviations"]')).toHaveCount(0);
});

test('two VLANs of one number are refused on one port', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, 'fw-siege');

    await page.locator('[data-test="edit-port-port3"]').click();

    const dialog = page.locator('[data-test="port-dialog"]');
    const workshop = siteGroup(dialog, 'port', 'Atelier');

    await dialog.getByLabel('Mode').selectOption({ label: 'Trunk' });
    await dialog
        .locator('[data-test="port-own-site"]')
        .getByRole('checkbox', { name: '10 — Bureautique' })
        .check();
    await workshop.getByRole('button', { name: /^Atelier/ }).click();
    await workshop.getByRole('checkbox', { name: '10 — Bureautique' }).check();
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(dialog).toContainText(
        'Le VLAN 10 du site Siège et le VLAN 10 du site Atelier ne peuvent pas passer sur le même port.',
    );

    // Nothing was saved.
    await dialog.getByRole('button', { name: 'Annuler' }).click();
    await expect(portRow(page, 'port3')).not.toContainText('Trunk');
});

test('the fibre that merges the VLAN 10 of the two sites is a deviation, which goes once one end is corrected', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, 'sw-coeur-02');

    const row = linkRow(page, '1/1/3');
    const list = page.locator('[data-test="deviation-list"]');

    await expect(row.locator('[data-test="deviations"]')).toContainText(MERGED);
    await expect(list).toContainText(MERGED);
    await expect(portRow(page, '1/1/3')).toContainText('Trunk : 10 · ATE 60');

    // The end of the head office takes the VLAN 10 of the workshop: the
    // group of the workshop is open, the port carrying its VLAN 60.
    await page.locator('[data-test="edit-port-1/1/3"]').click();

    const dialog = page.locator('[data-test="port-dialog"]');
    const workshop = siteGroup(dialog, 'port', 'Atelier');

    await expect(
        workshop.getByRole('button', { name: /^Atelier/ }),
    ).toHaveAttribute('aria-expanded', 'true');
    await dialog
        .locator('[data-test="port-own-site"]')
        .getByRole('checkbox', { name: '10 — Bureautique' })
        .uncheck();
    await workshop.getByRole('checkbox', { name: '10 — Bureautique' }).check();
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(portRow(page, '1/1/3')).toContainText('Trunk : ATE 10, 60');
    await expect(row.locator('[data-test="deviations"]')).toHaveCount(0);
    await expect(list).not.toContainText(MERGED);
});
