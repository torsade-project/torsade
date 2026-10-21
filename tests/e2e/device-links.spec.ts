// torsade/tests/e2e/device-links.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// What is plugged into what, on the demonstration network: a port linked
// to a port of another device, two ports linked as a series to an
// aggregate, a deviation between the two ends of a link that goes once the
// configuration is corrected, a linked port that cannot be deleted, and
// the list of every link.
//
// Each scenario leaves the links of the demonstration as it found them,
// but for the link the first one creates: the scenarios after it, and the
// pictures of the screens, count on them.

const openDevice = async (page: Page, name: string): Promise<void> => {
    await page.goto('/devices');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name, exact: true }),
    ).toBeVisible();
};

const portRow = (page: Page, name: string): Locator =>
    page.locator(`[data-test="port-row"][data-port="${name}"]`);

// A line of the section "Links" of the page of a device, by its port.
const linkRow = (page: Page, port: string): Locator =>
    page.locator(
        `[data-test="device-links"] [data-test="link-row"][data-link="${port}"]`,
    );

// The window that asks to confirm has no name of its own.
const confirmation = (page: Page, title: string): Locator =>
    page.getByRole('dialog').filter({ hasText: title });

test('a port is linked to a port of another device, and the link is shown on both sides', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, 'sw-etage-2');

    // A port has one link at most: only a port that is not linked offers
    // to be.
    await expect(page.locator('[data-test="link-port-1/1/1"]')).toBeDisabled();
    await page.locator('[data-test="link-port-1/1/2"]').click();

    const dialog = page.locator('[data-test="link-dialog"]');
    const found = dialog.locator('[data-test="linkable-device"]');
    const save = dialog.locator('[data-test="save-link"]');

    await expect(dialog).toContainText(
        "Le port 1/1/2 de sw-etage-2 est relié à un port libre d'un autre équipement",
    );
    await expect(save).toBeDisabled();

    // The opposite device is looked for by its name, on every site; the
    // device itself is not one of them.
    await dialog.locator('[data-test="link-search"]').fill('sw-');
    await expect(found.filter({ hasText: 'sw-quai' })).toContainText('Atelier');
    await expect(found.filter({ hasText: 'sw-serveurs' })).toContainText(
        'Siège',
    );
    await expect(
        dialog.locator(
            '[data-test="linkable-device"][data-device="sw-etage-2"]',
        ),
    ).toHaveCount(0);
    // A device without a port cannot be chosen, and says why.
    await expect(
        dialog.locator(
            '[data-test="linkable-device"][data-device="sw-atelier-02"]',
        ),
    ).toContainText('0 port libre');
    await expect(
        dialog.locator('[data-test="choose-device-sw-atelier-02"]'),
    ).toBeDisabled();

    await dialog.locator('[data-test="link-search"]').fill('coeur');
    await expect(found).toHaveCount(2);
    await dialog.locator('[data-test="choose-device-sw-coeur-01"]').click();
    await expect(dialog.locator('[data-test="chosen-device"]')).toContainText(
        'sw-coeur-01',
    );

    // Its free ports only, each with what it carries.
    const free = dialog.locator('[data-test="opposite-port"]');

    await expect(free).toHaveCount(47);
    await expect(
        dialog.locator('[data-test="opposite-port"][data-port="1/0/1"]'),
    ).toHaveCount(0);
    await expect(
        dialog.locator('[data-test="opposite-port"][data-port="1/0/2"]'),
    ).toContainText('Aucun mode');
    await expect(save).toBeDisabled();

    await dialog
        .locator('[data-test="opposite-port"][data-port="1/0/48"] input')
        .check();
    await dialog.getByLabel('Support').selectOption({ label: 'Cuivre' });
    await dialog.getByLabel('Statut').selectOption({ label: 'Prévue' });
    await dialog.getByLabel('Description').fill('Lien de secours du 2e étage');
    await save.click();

    await expect(page.getByText('1 liaison créée.')).toBeVisible();
    await expect(dialog).toBeHidden();

    // In the table of ports: the device and the port it faces.
    await expect(
        portRow(page, '1/1/2').locator('[data-test="port-link"]'),
    ).toHaveText(/sw-coeur-01\s*›\s*1\/0\/48/);
    await expect(page.locator('[data-test="link-port-1/1/2"]')).toBeDisabled();

    // In the section of the links of the device.
    const near = linkRow(page, '1/1/2');

    await expect(near).toContainText(/sw-coeur-01\s*›\s*1\/0\/48/);
    await expect(near).toContainText('Cuivre');
    await expect(near).toContainText('Prévue');
    await expect(near).toContainText('Lien de secours du 2e étage');

    // Its window changes what it is, not what it joins: the medium, the
    // status and the description.
    await page.locator('[data-test="edit-link-1/1/2"]').click();

    const edition = page.locator('[data-test="link-edit-dialog"]');

    await expect(edition).toContainText(
        /sw-etage-2\s*›\s*1\/1\/2\s*↔\s*sw-coeur-01\s*›\s*1\/0\/48/,
    );
    await expect(edition.getByLabel('Support')).toHaveValue('copper');
    await expect(edition.getByLabel('Statut')).toHaveValue('planned');
    await expect(edition.getByLabel('Description')).toHaveValue(
        'Lien de secours du 2e étage',
    );
    await edition
        .getByLabel('Support')
        .selectOption({ label: 'Fibre multimode' });
    await edition.locator('[data-test="save-link-edit"]').click();
    await expect(page.getByText('Liaison modifiée.')).toBeVisible();
    await expect(edition).toBeHidden();
    await expect(near).toContainText('Fibre multimode');
    await expect(near).not.toContainText('Cuivre');

    // And on the page of the other device, from its side.
    await near.getByRole('link', { name: 'sw-coeur-01', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'sw-coeur-01', exact: true }),
    ).toBeVisible();
    await expect(
        portRow(page, '1/0/48').locator('[data-test="port-link"]'),
    ).toHaveText(/sw-etage-2\s*›\s*1\/1\/2/);

    const far = linkRow(page, '1/0/48');

    await expect(far).toContainText(/sw-etage-2\s*›\s*1\/1\/2/);
    await expect(far).toContainText('Prévue');
    await expect(far).toContainText('Lien de secours du 2e étage');
});

test('two ports are linked as a series to an aggregate of the opposite device, which makes an aggregated link', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, 'sw-serveurs');

    const links = page.locator('[data-test="device-links"]');
    const aggregated = links.locator(
        '[data-test="aggregated-link-row"][data-link="Po1"]',
    );

    // The uplink of the stack: one line for its two links, which opens.
    await expect(aggregated).toContainText('Liaison agrégée, 2 liens');
    await expect(aggregated).toContainText(/sw-coeur-01\s*›\s*Po1/);
    await expect(links.locator('[data-test="link-row"]')).toHaveCount(0);
    await page.locator('[data-test="toggle-link-Po1"]').click();
    await expect(links.locator('[data-test="link-row"]')).toHaveCount(2);

    // Its two links are deleted, one after the other: their ports stay.
    await page.locator('[data-test="delete-link-1/1/1"]').click();

    const deletion = confirmation(page, 'Supprimer cette liaison ?');

    await expect(deletion).toContainText(
        'La liaison entre sw-serveurs › 1/1/1 et sw-coeur-01 › 1/1/1 sera supprimée. Ses deux ports restent',
    );
    await deletion.getByRole('button', { name: 'Supprimer' }).click();
    await expect(aggregated).toContainText('Liaison agrégée, 1 lien');

    await page.locator('[data-test="delete-link-2/1/1"]').click();
    await deletion.getByRole('button', { name: 'Supprimer' }).click();
    await expect(links).toContainText(
        "Aucun port de cet équipement n'est encore relié.",
    );
    await expect(page.locator('[data-test="port-row"]')).toHaveCount(56);
    await expect(
        portRow(page, '1/1/1').locator('[data-test="port-link"]'),
    ).toHaveCount(0);

    // The two ports of the aggregate of the stack, one on each member,
    // are ticked and linked together.
    await portRow(page, '1/1/1').getByRole('checkbox').click();
    await portRow(page, '2/1/1').getByRole('checkbox').click();
    await page.locator('[data-test="link-ports"]').click();

    const dialog = page.locator('[data-test="link-dialog"]');
    const save = dialog.locator('[data-test="save-link"]');
    const count = dialog.locator('[data-test="opposite-ports-count"]');
    const opposite = (port: string): Locator =>
        dialog.locator(`[data-test="opposite-port"][data-port="${port}"]`);

    await expect(dialog).toContainText(
        'Les 2 ports sélectionnés sur sw-serveurs sont reliés, dans leur ordre, à autant de ports libres',
    );
    await dialog.locator('[data-test="link-search"]').fill('coeur-01');
    await dialog.locator('[data-test="choose-device-sw-coeur-01"]').click();
    await expect(count).toHaveText('0 / 2');

    // The ports of the opposite aggregate say what the aggregate carries.
    await expect(opposite('1/1/1')).toContainText(
        'via Po1 : Trunk, natif 99 : 10, 20, 30, 40, 50',
    );

    // Ticked in any order: as many as there are ports to link, no more.
    await opposite('1/1/2').locator('input').check();
    await expect(count).toHaveText('1 / 2');
    await expect(save).toBeDisabled();
    await opposite('1/1/1').locator('input').check();
    await expect(count).toHaveText('2 / 2');
    await expect(opposite('1/0/2').locator('input')).toBeDisabled();

    // Which port goes with which is shown before anything is saved: each
    // side in the order of its device.
    await expect(dialog.locator('[data-test="link-pairs"] li')).toHaveText([
        '1/1/1 ↔ sw-coeur-01 › 1/1/1',
        '2/1/1 ↔ sw-coeur-01 › 1/1/2',
    ]);

    await dialog.getByLabel('Support').selectOption({ label: 'Câble direct' });
    await save.click();
    await expect(page.getByText('2 liaisons créées.')).toBeVisible();
    // The ports ticked are linked: they are no longer ticked.
    await expect(page.locator('[data-test="selected-ports"]')).toHaveText(
        'Aucun port sélectionné',
    );
    await expect(
        portRow(page, '1/1/1').getByRole('checkbox'),
    ).not.toBeChecked();

    // One aggregated link again, between the two aggregates, with nothing
    // that differs.
    await page.reload();
    await expect(aggregated).toContainText('Liaison agrégée, 2 liens');
    await expect(aggregated).toContainText(/sw-coeur-01\s*›\s*Po1/);
    await expect(aggregated).toContainText('Câble direct');
    await expect(aggregated.locator('[data-test="deviations"]')).toHaveCount(0);
    await expect(links.locator('[data-test="link-row"]')).toHaveCount(0);

    await page.locator('[data-test="toggle-link-Po1"]').click();
    await expect(linkRow(page, '1/1/1')).toContainText(
        /sw-coeur-01\s*›\s*1\/1\/1/,
    );
    await expect(linkRow(page, '2/1/1')).toContainText(
        /sw-coeur-01\s*›\s*1\/1\/2/,
    );
    await expect(
        portRow(page, '2/1/1').locator('[data-test="port-link"]'),
    ).toHaveText(/sw-coeur-01\s*›\s*1\/1\/2/);
});

test('a VLAN tagged on one side only is a deviation, which goes once the port is corrected', async ({
    page,
    logIn,
}) => {
    const reason = 'VLAN 40 seulement sur sw-etage-2';

    await logIn('editor');
    await openDevice(page, 'sw-coeur-02');

    const row = linkRow(page, '1/1/1');
    const list = page.locator('[data-test="deviation-list"]');
    const port = page.locator('[data-test="port-dialog"]');
    const guests = port.getByRole('checkbox', { name: '40 — Wi-Fi invités' });

    // The uplink of the second floor: its two trunks do not tag the same
    // VLANs. The link says so, with its reason, and so does the list of
    // the deviations of the page.
    await expect(row.locator('[data-test="deviations"]')).toContainText(reason);
    await expect(row).toContainText(
        "Le VLAN 40 des invités n'est pas étiqueté côté sw-coeur-02",
    );
    await expect(list).toContainText(reason);
    await expect(portRow(page, '1/1/1')).toContainText(
        'Trunk, natif 99 : 10, 30',
    );

    // In that list, the link leads to the section that shows it.
    await list
        .getByRole('link', { name: '1/1/1 ↔ sw-etage-2 › 1/1/1', exact: true })
        .click();
    await expect(page).toHaveURL(/#device-links$/);
    await expect(
        page.locator('[data-test="device-links"]').getByRole('heading'),
    ).toBeInViewport();

    // The VLAN is tagged on the port of this device: the two ends agree.
    await page.locator('[data-test="edit-port-1/1/1"]').click();
    await expect(guests).not.toBeChecked();
    await guests.check();
    await port.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(portRow(page, '1/1/1')).toContainText(
        'Trunk, natif 99 : 10, 30, 40',
    );
    await expect(row.locator('[data-test="deviations"]')).toHaveCount(0);
    await expect(list).not.toContainText(reason);
    // The other deviation of the device is still told.
    await expect(list).toContainText("Natif 5 d'un côté, 99 de l'autre");

    // Nothing differs on the page of the other end either.
    await row.getByRole('link', { name: 'sw-etage-2', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'sw-etage-2', exact: true }),
    ).toBeVisible();
    await expect(
        linkRow(page, '1/1/1').locator('[data-test="deviations"]'),
    ).toHaveCount(0);
    await expect(list).not.toContainText(reason);
    // What its cabling keeps on purpose is still told: a telephone on a
    // port without the VLAN of the telephones.
    await expect(list).toContainText('1/0/14');

    // Taken away again, the deviation is back: nothing was stored.
    await openDevice(page, 'sw-coeur-02');
    await page.locator('[data-test="edit-port-1/1/1"]').click();
    await expect(guests).toBeChecked();
    await guests.uncheck();
    await port.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(row.locator('[data-test="deviations"]')).toContainText(reason);
    await expect(list).toContainText(reason);
});

test('a linked port is not deleted, nor is its member', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, 'sw-etage-1');

    const ports = page.locator('[data-test="port-row"]');

    await expect(ports).toHaveCount(28);

    // The port of its uplink: the refusal names its link.
    await page.locator('[data-test="delete-port-1/1/1"]').click();
    await confirmation(page, 'Supprimer ce port ?')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(
        page.getByText(
            "1/1/1 est relié à sw-coeur-01 › 1/1/3 : supprimez d'abord la liaison.",
        ),
    ).toBeVisible();
    await expect(portRow(page, '1/1/1')).toBeVisible();

    // Among the ports ticked, one linked port keeps all of them.
    await portRow(page, '1/1/2').getByRole('checkbox').click();
    await portRow(page, '1/1/4').getByRole('checkbox').click();
    await page.locator('[data-test="delete-ports"]').click();
    await confirmation(page, 'Supprimer les ports sélectionnés ?')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(
        page.getByText(
            "1/1/2 est relié à sw-coeur-02 › 1/1/2 : supprimez d'abord la liaison.",
        ),
    ).toBeVisible();
    await expect(ports).toHaveCount(28);

    // Its member holds two linked ports: both links are named.
    await page.locator('[data-test="delete-member-1"]').click();
    await confirmation(page, 'Supprimer ce membre ?')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(
        page.getByText(
            "1/1/1 est relié à sw-coeur-01 › 1/1/3, 1/1/2 est relié à sw-coeur-02 › 1/1/2 : supprimez d'abord ces liaisons.",
        ),
    ).toBeVisible();
    await expect(ports).toHaveCount(28);
    await expect(
        portRow(page, '1/1/1').locator('[data-test="port-link"]'),
    ).toHaveText(/sw-coeur-01\s*›\s*1\/1\/3/);
});

test('the list of links is filtered down to the links with a deviation', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await page.goto('/links');

    const rows = page.locator('[data-test="link-row"]');
    const count = page.locator('[data-test="links-count"]');
    const filters = page.locator('[data-test="link-filters"]');
    const differing = page.locator('[data-test="filter-deviations"]');

    // The fifteen links of the demonstration and the one linked above; the
    // two of the stack are one aggregated link.
    await expect(count).toHaveText('16 liaisons');
    await expect(rows).toHaveCount(14);
    await expect(page.locator('[data-test="aggregated-link-row"]')).toHaveText(
        /sw-serveurs\s*›\s*Po1/,
    );
    // Each end with its device and its site.
    await expect(
        page.locator('[data-test="link-row"][data-link="sw-quai › 8"]'),
    ).toContainText('Atelier');
    // A reader reads: no line offers to write.
    await expect(page.getByRole('button', { name: 'Supprimer' })).toHaveCount(
        0,
    );

    // Only the links whose two ends do not agree, or that the cabling does
    // not confirm, each with its reason.
    await differing.check();
    await expect(page).toHaveURL(/deviations=1/);
    await expect(count).toHaveText('6 liaisons sur 16');
    await expect(rows).toHaveCount(6);
    await expect(rows.nth(0)).toContainText(
        'sw-atelier-01 1/0/10 et ap-atelier-2 eth0 sont encore câblés par PB-A 10 › H.04 ; leur liaison est retirée',
    );
    await expect(rows.nth(1)).toContainText("Natif 99 d'un côté, 5 de l'autre");
    await expect(rows.nth(2)).toContainText(
        "VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux",
    );
    await expect(rows.nth(3)).toContainText(
        "sw-etage-1 1/0/22 est relié à ap-salle-reunion eth0 et brassé vers B.14, où rien n'est branché",
    );
    await expect(rows.nth(4)).toContainText(
        'Le câblage de ap-etage-2 eth0 mène à sw-etage-2 1/0/16 ; la liaison, à sw-etage-2 1/0/21',
    );
    await expect(rows.nth(5)).toContainText('VLAN 40 seulement sur sw-etage-2');

    // Combined with a site: at the workshop, the cords of a retired link,
    // and the fibre under study, which merges the VLAN 10 of the two
    // sites.
    await filters.getByLabel('Site').selectOption({ label: 'Atelier' });
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toContainText(/sw-atelier-01\s*›\s*1\/0\/10/);
    await expect(rows.nth(1)).toContainText(/sw-atelier-01\s*›\s*1\/1\/1/);
    await differing.uncheck();
    await expect(rows).toHaveCount(3);
    await expect(count).toHaveText('3 liaisons sur 16');
    await expect(
        page.locator('[data-test="link-row"][data-link="sw-quai › 8"]'),
    ).toContainText(/sw-atelier-01\s*›\s*1\/0\/24/);

    // And with a status: the two planned links of the demonstration, and
    // the one linked above.
    await filters.getByLabel('Site').selectOption({ label: 'Tous les sites' });
    await filters.getByLabel('Statut').selectOption({ label: 'Prévue' });
    await expect(page).toHaveURL(/status=planned/);
    await expect(rows).toHaveCount(3);
    await expect(count).toHaveText('3 liaisons sur 16');

    // The address of the page keeps the filters: it can be shared.
    await page.reload();
    await expect(filters.getByLabel('Statut')).toHaveValue('planned');
    await expect(rows).toHaveCount(3);
});
