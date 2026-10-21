// torsade/tests/e2e/jack-cords.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// The jack cords and the cabled paths, on the demonstration network: a
// port plugged into a jack from its line, then unplugged; a device plugged
// into a jack from the jack, its cord changed, then unplugged; a link
// created from the deviation of a cabling without a link, and a port in
// front plugged from the deviation of a port linked without a plug, each
// deviation then gone; every port of a device unplugged at once.
//
// Each scenario leaves the demonstration as it found it: the scenarios of
// the other files, and the pictures of the screens, count on it.

const openDevice = async (page: Page, name: string): Promise<void> => {
    await page.goto('/devices');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name, exact: true }),
    ).toBeVisible();
};

const portRow = (page: Page, name: string): Locator =>
    page.locator(`[data-test="port-row"][data-port="${name}"]`);

const jackRow = (page: Page, label: string): Locator =>
    page.locator(`[data-test="jack-row"][data-jack="${label}"]`);

// The window that asks to confirm has no name of its own.
const confirmation = (page: Page, title: string): Locator =>
    page.getByRole('dialog').filter({ hasText: title });

// The option of a list whose text starts as given, chosen by its value.
const choose = async (select: Locator, text: RegExp): Promise<void> => {
    const option = select.locator('option', { hasText: text });

    await expect(option).toHaveCount(1);
    await select.selectOption((await option.getAttribute('value')) ?? '');
};

test('a port is plugged into a jack from its line, then unplugged', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, 'sw-etage-2');

    // A port patched to a panel has one connector already: it offers no
    // jack.
    await expect(page.locator('[data-test="plug-port-1/0/1"]')).toBeDisabled();
    await page.locator('[data-test="plug-port-1/0/22"]').click();

    const window = page.locator('[data-test="jack-plug-dialog"]');

    await expect(window).toContainText('Brancher sur une prise');
    await expect(window).toContainText(
        'Le port 1/0/22 de sw-etage-2 est branché sur une prise murale de son site',
    );

    // The jacks of the site without a device, by building and floor, each
    // with its path; a jack where a device is plugged is not offered.
    const jacks = window.locator('[data-test="pluggable-jacks"]');

    await expect(jacks).toContainText('Bâtiment principal › 2e étage');
    await expect(
        window.locator('[data-test="pluggable-jack"][data-jack="C.10"]'),
    ).toHaveCount(0);

    const choice = window.locator(
        '[data-test="pluggable-jack"][data-jack="C.11"]',
    );

    await expect(choice.locator('[data-test="jack-path"]')).toHaveText(
        /PB-C 11\s*›\s*non brassée/,
    );
    await choice.getByRole('radio').check();
    await window.getByLabel('Couleur').fill('Blanc');
    await window.locator('[data-test="save-jack-plug"]').click();

    await expect(page.getByText('Port branché sur la prise.')).toBeVisible();
    await expect(window).toBeHidden();

    // "plugged into C.11", which leads to the jack, its whole place in the
    // tooltip.
    const plugged = portRow(page, '1/0/22').locator(
        '[data-test="port-jack-cord"]',
    );

    await expect(plugged).toHaveText(/branché sur\s*C\.11/);
    await expect(plugged.getByRole('link', { name: 'C.11' })).toHaveAttribute(
        'title',
        'Bâtiment principal › 2e étage › C.11',
    );

    // The path of the jack tells first the device plugged into it.
    await plugged.getByRole('link', { name: 'C.11' }).click();
    await expect(
        jackRow(page, 'C.11').locator('[data-test="jack-path-plugged"]'),
    ).toHaveText(/Branché :\s*sw-etage-2\s*1\/0\/22/);

    // Unplugged from the line of the port.
    await openDevice(page, 'sw-etage-2');
    await page.locator('[data-test="unplug-port-1/0/22"]').click();

    const unplug = confirmation(page, 'Débrancher ce port ?');

    await expect(unplug).toContainText(
        'Le cordon de 1/0/22 vers la prise C.11 est retiré',
    );
    await unplug.getByRole('button', { name: 'Débrancher' }).click();
    await expect(page.getByText('Port débranché de la prise.')).toBeVisible();
    await expect(
        portRow(page, '1/0/22').locator('[data-test="port-jack-cord"]'),
    ).toHaveCount(0);
    await expect(page.locator('[data-test="plug-port-1/0/22"]')).toBeEnabled();
});

test('a device is plugged into a jack from the jack, its cord changed, then unplugged', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/jacks');

    const row = jackRow(page, 'C.12');

    await row
        .getByRole('button', { name: 'Brancher un équipement sur C.12' })
        .click();

    const window = page.locator('[data-test="jack-device-plug-dialog"]');

    await expect(window).toContainText('Brancher un équipement');

    // A device of the site, then one of its ports without a connector.
    await choose(window.locator('[data-test="plug-device"]'), /^sw-etage-2 — /);

    const port = window.locator('[data-test="plug-port"]');

    await expect(
        port.locator('option', { hasText: /^1\/0\/23 · / }),
    ).toHaveCount(1);
    // A port patched to a panel is not offered.
    await expect(
        port.locator('option', { hasText: /^1\/0\/1 · / }),
    ).toHaveCount(0);
    await choose(port, /^1\/0\/23 · /);
    await window.locator('[data-test="save-jack-device-plug"]').click();

    await expect(page.getByText('Port branché sur la prise.')).toBeVisible();
    await expect(row.locator('[data-test="jack-path-plugged"]')).toHaveText(
        /Branché :\s*sw-etage-2\s*1\/0\/23/,
    );

    // Its colour changes in the window of the cord; its two ends stay.
    await row
        .getByRole('button', { name: 'Modifier le cordon de prise de C.12' })
        .click();

    const cord = page.locator('[data-test="jack-cord-edit-dialog"]');

    await expect(cord.locator('[data-test="jack-cord-ends"]')).toContainText(
        /C\.12[\s\S]*sw-etage-2 › 1\/0\/23/,
    );
    await cord.getByLabel('Couleur').fill('Gris');
    await cord.locator('[data-test="save-jack-cord"]').click();
    await expect(page.getByText('Cordon modifié.')).toBeVisible();

    // Unplugged from the window of the cord, after confirmation.
    await row
        .getByRole('button', { name: 'Modifier le cordon de prise de C.12' })
        .click();
    await expect(cord.getByLabel('Couleur')).toHaveValue('Gris');
    await cord.locator('[data-test="unplug-jack-cord"]').click();

    const unplug = confirmation(page, 'Débrancher cet équipement ?');

    await expect(unplug).toContainText(
        'Le cordon de sw-etage-2 1/0/23 vers la prise C.12 est retiré',
    );
    await unplug.getByRole('button', { name: 'Débrancher' }).click();
    await expect(page.getByText('Port débranché de la prise.')).toBeVisible();
    await expect(row.locator('[data-test="jack-path-plugged"]')).toHaveCount(0);
});

test('the link of a cabling without a link is created from its deviation, which is then gone', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, 'sw-atelier-01');

    const row = portRow(page, '1/0/9');

    await expect(
        row.locator('[data-deviation="cabling_without_link"]'),
    ).toContainText(
        'sw-atelier-01 1/0/9 et ap-atelier-1 eth0 sont câblés par PB-A 9 › H.03, sans liaison',
    );
    await row.locator('[data-test="create-cabled-link"]').click();

    // The window of the port opens with the port in front chosen.
    const window = page.locator('[data-test="link-dialog"]');

    await expect(window).toContainText('Le port 1/0/9 de sw-atelier-01');
    await expect(window.locator('[data-test="chosen-device"]')).toContainText(
        'ap-atelier-1',
    );
    await expect(
        window
            .locator('[data-test="opposite-port"][data-port="eth0"]')
            .getByRole('radio'),
    ).toBeChecked();

    // Open, the window is no longer asked for by the address of the page.
    await expect(page).not.toHaveURL(/link_port|link_to/);
    await window.locator('[data-test="save-link"]').click();

    await expect(page.getByText('1 liaison créée.')).toBeVisible();
    await expect(row.locator('[data-test="port-link"]')).toHaveText(
        /ap-atelier-1\s*›\s*eth0/,
    );
    await expect(
        row.locator('[data-deviation="cabling_without_link"]'),
    ).toHaveCount(0);

    // The cabling is the path of the new link.
    await expect(
        page
            .locator('[data-test="device-links"] [data-test="link-path"]')
            .filter({ hasText: 'H.03' }),
    ).toHaveText(/par\s*PB-A 9 › H\.03/);

    // Back as the demonstration has it.
    await page
        .locator('[data-test="device-links"]')
        .locator('[data-test="delete-link-1/0/9"]')
        .click();
    await confirmation(page, 'Supprimer cette liaison ?')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(page.getByText('Liaison supprimée.')).toBeVisible();
    await expect(
        row.locator('[data-deviation="cabling_without_link"]'),
    ).toHaveCount(1);

    // The page found again does not open the window once more.
    await expect(window).toBeHidden();
});

test('the port in front of a link is plugged from the deviation of a port linked without a plug, which is then gone', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, 'sw-etage-1');

    const row = portRow(page, '1/0/22');

    await expect(
        row.locator('[data-deviation="linked_without_plug"]'),
    ).toContainText(
        "sw-etage-1 1/0/22 est relié à ap-salle-reunion eth0 et brassé vers B.14, où rien n'est branché",
    );
    await row.locator('[data-test="plug-linked-port"]').click();

    await expect(page.getByText('Port branché sur la prise.')).toBeVisible();
    await expect(
        row.locator('[data-deviation="linked_without_plug"]'),
    ).toHaveCount(0);
    await expect(
        page
            .locator('[data-test="device-links"] [data-test="link-path"]')
            .filter({ hasText: 'B.14' }),
    ).toHaveText(/par\s*PB-B 14 › B\.14/);

    // Back as the demonstration has it: the access point unplugged.
    await openDevice(page, 'ap-salle-reunion');
    await page.locator('[data-test="unplug-port-eth0"]').click();
    await confirmation(page, 'Débrancher ce port ?')
        .getByRole('button', { name: 'Débrancher' })
        .click();
    await expect(page.getByText('Port débranché de la prise.')).toBeVisible();
});

test('every port of a device is unplugged at once, the button there only when one is plugged', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // A switch patched to a panel, plugged into no jack.
    await openDevice(page, 'sw-etage-1');
    await expect(page.locator('[data-test="unpatch-all"]')).toBeVisible();
    await expect(page.locator('[data-test="unplug-all"]')).toHaveCount(0);

    // An access point plugged into a jack, patched to no panel.
    await openDevice(page, 'ap-etage-1');
    await expect(page.locator('[data-test="unpatch-all"]')).toHaveCount(0);
    await page.locator('[data-test="unplug-all"]').click();

    const unplug = confirmation(
        page,
        'Débrancher tous les ports de ap-etage-1 ?',
    );

    await expect(unplug).toContainText('eth0 ↔ B.13');
    await unplug.getByRole('button', { name: 'Débrancher' }).click();
    await expect(page.getByText('1 cordon supprimé.')).toBeVisible();
    await expect(page.locator('[data-test="unplug-all"]')).toHaveCount(0);

    // Back as the demonstration has it: plugged again into B.13.
    await page.locator('[data-test="plug-port-eth0"]').click();

    const window = page.locator('[data-test="jack-plug-dialog"]');

    await window
        .locator('[data-test="pluggable-jack"][data-jack="B.13"]')
        .getByRole('radio')
        .check();
    await window.getByLabel('Couleur').fill('Blanc');
    await window.getByLabel('Description').fill('Au plafond du plateau');
    await window.locator('[data-test="save-jack-plug"]').click();
    await expect(page.getByText('Port branché sur la prise.')).toBeVisible();
    await expect(page.locator('[data-test="unplug-all"]')).toBeVisible();
});

test('the ports of a retired device offer no jack, until it is active again', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, 'sw-quai');

    const offered = page.locator('[data-test^="plug-port-"]:enabled');

    const setStatus = async (status: string): Promise<void> => {
        await page.locator('[data-test="edit-device"]').click();
        await expect(
            page.getByRole('heading', { name: "Modifier l'équipement" }),
        ).toBeVisible();
        await page.getByLabel('Statut').selectOption({ label: status });
        await page.getByRole('button', { name: 'Enregistrer' }).click();
        await expect(
            page.getByRole('heading', { name: 'sw-quai', exact: true }),
        ).toBeVisible();
    };

    expect(await offered.count()).toBeGreaterThan(0);

    await setStatus('Retiré');
    await expect(offered).toHaveCount(0);

    // Back as the demonstration has it.
    await setStatus('Actif');
    await expect(offered.first()).toBeVisible();
});

test('a reader is offered no jack cord to lay, change or take away', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openDevice(page, 'ap-etage-1');

    await expect(
        portRow(page, 'eth0').locator('[data-test="port-jack-cord"]'),
    ).toHaveText(/branché sur\s*B\.13/);
    await expect(page.locator('[data-test="unplug-all"]')).toHaveCount(0);
    await expect(page.locator('[data-test="unplug-port-eth0"]')).toHaveCount(0);

    await page.goto('/jacks');
    await expect(
        page.getByRole('button', { name: /^Brancher un équipement sur / }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: /^Modifier le cordon de prise de / }),
    ).toHaveCount(0);

    // Nor the actions of the deviations of the cabling.
    await openDevice(page, 'sw-etage-1');
    await expect(page.locator('[data-test="plug-linked-port"]')).toHaveCount(0);
    await openDevice(page, 'sw-atelier-01');
    await expect(page.locator('[data-test="create-cabled-link"]')).toHaveCount(
        0,
    );
});
