// torsade/tests/e2e/cabling.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, openSubnet, test } from './support/fixtures';

// The cabling, on the demonstration network: a patch panel created in a
// room, twenty-four jacks labelled as a series and cabled to its ports,
// these ports patched as a series to a switch, an address plugged into a
// jack and its whole path in the list of jacks, a deviation of VLAN that
// goes once the port is corrected, and a patched port that cannot be
// deleted.
//
// The scenarios follow one another: each one uses what the one before
// made. The cabling of the demonstration is left as it is, and the
// pictures of the screens count on it.

const PANEL = 'PB-D';

const openSite = async (page: Page, name: string): Promise<void> => {
    await page.goto('/sites');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name: `Site ${name}`, exact: true }),
    ).toBeVisible();
};

const openPanel = async (page: Page): Promise<void> => {
    await openSite(page, 'Siège');
    await page
        .locator(`[data-test="place-panel"][data-panel="${PANEL}"]`)
        .getByRole('link', { name: PANEL, exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: PANEL, exact: true }),
    ).toBeVisible();
};

const openDevice = async (page: Page, name: string): Promise<void> => {
    await page.goto('/devices');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name, exact: true }),
    ).toBeVisible();
};

// The line of a floor, and the line of a room, of the page of a site.
const floorLine = (page: Page, name: string): Locator =>
    page.locator('[data-test="place-floor"]').filter({
        has: page.getByText(name, { exact: true }),
    });

const cell = (page: Page, number: number): Locator =>
    page.locator(`[data-test="panel-cell"][data-port="${number}"]`);

const panelRow = (page: Page, number: number): Locator =>
    page.locator(`[data-test="panel-port-row"][data-port="${number}"]`);

const jackRow = (page: Page, label: string): Locator =>
    page.locator(`[data-test="jack-row"][data-jack="${label}"]`);

// The window that asks to confirm has no name of its own.
const confirmation = (page: Page, title: string): Locator =>
    page.getByRole('dialog').filter({ hasText: title });

test('a panel is created in a room, then twenty-four jacks are labelled as a series and cabled to its ports, all or none', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openSite(page, 'Siège');

    // The panel, in the technical room of the second floor, next to its
    // switch.
    const room = floorLine(page, '2e étage')
        .locator('[data-test="place-room"]')
        .filter({ hasText: 'Placard technique' });

    await room.locator('[data-test^="create-panel-"]').click();

    const panelWindow = page.locator('[data-test="patch-panel-dialog"]');

    await expect(panelWindow).toContainText(
        'Un panneau de brassage rangé dans Bâtiment principal › 2e étage › Placard technique.',
    );
    await panelWindow.getByLabel('Nom', { exact: true }).fill(PANEL);
    await panelWindow.getByLabel('Nombre de ports').fill('24');
    await panelWindow.getByLabel('Catégorie').fill('6A');
    await panelWindow.locator('[data-test="save-patch-panel"]').click();
    await expect(page.getByText('Panneau créé.')).toBeVisible();
    await expect(
        page.locator(`[data-test="place-panel"][data-panel="${PANEL}"]`),
    ).toContainText('0 port occupé sur 24');

    // The series, from the line of the floor: the labels and their ports
    // are shown while it is typed.
    const floor = floorLine(page, '2e étage');

    await expect(floor.locator('[data-test="floor-jacks"]').first()).toHaveText(
        '12 prises',
    );
    await floor.locator('[data-test^="create-jacks-"]').click();

    const series = page.locator('[data-test="jack-series-dialog"]');
    const preview = series.locator('[data-test="jacks-preview"]');

    await series.locator('[data-test="jacks-prefix"]').fill('E.');
    await series.locator('[data-test="jacks-first"]').fill('1');
    await series.locator('[data-test="jacks-last"]').fill('24');
    await series.locator('[data-test="jacks-panel"]').selectOption({
        label: `${PANEL} — Bâtiment principal › 2e étage › Placard technique`,
    });
    await expect(series.locator('[data-test="jacks-first-port"]')).toHaveValue(
        '1',
    );
    await expect(
        preview.locator('[data-test="jacks-preview-line"]'),
    ).toHaveCount(24);
    await expect(
        preview.locator('[data-test="jacks-preview-line"]').first(),
    ).toHaveText(/E\.01\s*→\s*1/);
    await expect(
        preview.locator('[data-test="jacks-preview-line"]').last(),
    ).toHaveText(/E\.24\s*→\s*24/);
    await expect(preview).toContainText(`24 prises, reliées à ${PANEL}`);

    // From the port 2, the series would go past the last port: the window
    // says so before anything is sent.
    await series.locator('[data-test="jacks-first-port"]').fill('2');
    await expect(series.locator('[data-test="jacks-port-hint"]')).toHaveText(
        `La série finirait sur le port 25 de ${PANEL}, au-delà de son dernier port, le 24.`,
    );
    await series.locator('[data-test="jacks-first-port"]').fill('1');
    await expect(series.locator('[data-test="jacks-port-hint"]')).toHaveCount(
        0,
    );

    await series.locator('[data-test="save-jack-series"]').click();
    await expect(page.getByText('24 prises créées.')).toBeVisible();
    await expect(series).toBeHidden();
    await expect(floor.locator('[data-test="floor-jacks"]').first()).toHaveText(
        '36 prises',
    );
    await expect(
        page.locator(`[data-test="place-panel"][data-panel="${PANEL}"]`),
    ).toContainText('24 ports occupés sur 24');

    // The same series again: refused whole, and nothing is created.
    await floor.locator('[data-test^="create-jacks-"]').click();
    await series.locator('[data-test="jacks-first"]').fill('20');
    await series.locator('[data-test="jacks-last"]').fill('30');
    await series.locator('[data-test="save-jack-series"]').click();
    await expect(series).toContainText(
        "L'étage a déjà des prises libellées E.20, E.21, E.22, E.23, E.24.",
    );
    await series.getByRole('button', { name: 'Annuler' }).click();
    await page.reload();
    await expect(
        floorLine(page, '2e étage')
            .locator('[data-test="floor-jacks"]')
            .first(),
    ).toHaveText('36 prises');

    // On the page of the panel, every port has its jack, and no cord.
    await openPanel(page);
    await expect(page.locator('[data-test="panel-cell"]')).toHaveCount(24);
    await expect(
        page.locator('[data-test="panel-cell"][data-state="jack"]'),
    ).toHaveCount(24);
    await expect(
        panelRow(page, 7).locator('[data-test="panel-port-jack"]'),
    ).toHaveText('E.07');
});

test('the ports of the panel are patched as a series to a switch, and the ticks are cleared', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openPanel(page);

    const selected = page.locator('[data-test="selected-panel-ports"]');

    // Ports that do not follow one another make no series.
    for (const number of [1, 2, 4]) {
        await page.locator(`[data-test="select-panel-port-${number}"]`).check();
    }

    await expect(selected).toHaveText('3 ports sélectionnés');
    await expect(page.locator('[data-test="patch-series"]')).toBeDisabled();
    await expect(page.locator('[data-test="patch-series-problem"]')).toHaveText(
        'Les ports du panneau brassés en une fois se suivent : choisissez des ports consécutifs.',
    );
    await page.locator('[data-test="select-panel-port-3"]').check();
    await expect(page.locator('[data-test="patch-series"]')).toBeEnabled();
    await page.locator('[data-test="patch-series"]').click();

    const window = page.locator('[data-test="patch-cord-dialog"]');

    await expect(window).toContainText('Brasser 4 ports en série');
    await window.locator('[data-test="cord-device"]').selectOption({
        label: 'sw-etage-2 — Bâtiment principal › 2e étage › Placard technique',
    });

    // The ports already patched by the demonstration are not offered. The
    // uplink linked to a core switch is, even towards these ports of the
    // panel, which have a jack: the link would then run through the jack.
    const first = window.locator('[data-test="cord-port"]');

    await expect(first.locator('option', { hasText: /^1\/0\/9$/ })).toHaveCount(
        1,
    );
    await expect(first.locator('option', { hasText: /^1\/0\/1$/ })).toHaveCount(
        0,
    );
    await expect(first.locator('option', { hasText: '1/1/1' })).toHaveCount(1);

    await first.selectOption({ label: '1/0/9' });

    const lines = window.locator('[data-test="cord-preview-line"]');

    await expect(lines).toHaveCount(4);
    await expect(lines.first()).toHaveText(/PB-D 1\s*→\s*1\/0\/9/);
    await expect(lines.last()).toHaveText(/PB-D 4\s*→\s*1\/0\/12/);
    await window.getByLabel('Couleur').fill('Vert');
    await window.locator('[data-test="save-patch-cord"]').click();

    await expect(page.getByText('4 ports brassés.')).toBeVisible();
    await expect(window).toBeHidden();
    // Saved, the ticks are cleared.
    await expect(selected).toHaveText('Aucun port sélectionné');
    await expect(
        page.locator('[data-test="panel-cell"][data-state="patched"]'),
    ).toHaveCount(4);
    await expect(
        panelRow(page, 2).locator('[data-test="panel-port-opposite"]'),
    ).toHaveText(/sw-etage-2\s*›\s*1\/0\/10/);

    // A port of a panel without a jack, as on a riser panel, is offered
    // the uplink of the switch as well.
    await openSite(page, 'Siège');
    await page
        .locator('[data-test="place-panel"][data-panel="PB-C"]')
        .getByRole('link', { name: 'PB-C', exact: true })
        .click();
    await page.locator('[data-test="patch-20"]').click();
    await window.locator('[data-test="cord-device"]').selectOption({
        label: 'sw-etage-2 — Bâtiment principal › 2e étage › Placard technique',
    });
    await expect(first.locator('option', { hasText: '1/1/1' })).toHaveCount(1);
    await window.getByRole('button', { name: 'Annuler' }).click();
});

test('an address is plugged into a jack, and the list of jacks shows its whole path', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openSubnet(page, '10.10.10.0/24');

    // The window of an address looks for the jack by its label.
    await page
        .getByRole('row', { name: /10\.10\.10\.117/ })
        .getByRole('button', { name: 'Modifier' })
        .click();

    const window = page.locator('[data-test="ip-address-dialog"]');

    await window.locator('[data-test="jack-search"]').fill('E.03');
    await window.locator('[data-test="jack-choice"][data-jack="E.03"]').click();
    await expect(window.locator('[data-test="jack-chosen"]')).toContainText(
        'Bâtiment principal › 2e étage',
    );
    await window.locator('[data-test="save-ip-address"]').click();
    await expect(page.getByText('Adresse IP modifiée.')).toBeVisible();

    // The table of the subnet shows the jack and its path.
    await expect(
        page
            .getByRole('row', { name: /10\.10\.10\.117/ })
            .locator('[data-test="address-jack"]'),
    ).toHaveText(
        /E\.03\s*›\s*PB-D 3\s*›\s*sw-etage-2\s*1\/0\/11\s*›\s*Accès 10, voix 30/,
    );

    // The list of jacks, searched by label.
    await page.goto('/jacks');
    await page.getByLabel('Rechercher', { exact: true }).fill('E.03');
    await page.locator('[data-test="search-jacks"]').click();
    await expect(page.locator('[data-test="jack-row"]')).toHaveCount(1);

    const row = jackRow(page, 'E.03');

    await expect(row).toContainText('Siège · Bâtiment principal › 2e étage');
    await expect(row.locator('[data-test="jack-path"]')).toHaveText(
        /PB-D 3\s*›\s*sw-etage-2\s*1\/0\/11\s*›\s*Accès 10, voix 30/,
    );
    await expect(row.locator('[data-test="jack-addresses"]')).toContainText(
        '10.10.10.117',
    );
    await expect(row.locator('[data-test="jack-addresses"]')).toContainText(
        'pc-be-03',
    );

    // A jack cabled but not patched says where its path stops.
    await page.getByLabel('Rechercher', { exact: true }).fill('E.05');
    await page.locator('[data-test="search-jacks"]').click();
    await expect(
        jackRow(page, 'E.05').locator('[data-test="jack-path-missing"]'),
    ).toHaveText('non brassée');
});

test('a deviation of VLAN is shown on the jack, then gone once the port is corrected', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // A camera, on the network of the cameras, plugged into a jack whose
    // port carries the offices and the telephones.
    await openSubnet(page, '10.10.50.0/24');
    await page
        .getByRole('row', { name: /10\.10\.50\.15/ })
        .getByRole('button', { name: 'Modifier' })
        .click();

    const window = page.locator('[data-test="ip-address-dialog"]');

    await window.locator('[data-test="jack-search"]').fill('E.04');
    await window.locator('[data-test="jack-choice"][data-jack="E.04"]').click();
    await window.locator('[data-test="save-ip-address"]').click();
    await expect(page.getByText('Adresse IP modifiée.')).toBeVisible();

    const reason =
        'sw-etage-2 1/0/12 en accès VLAN 10, adresse 10.10.50.15 du VLAN 50';

    await expect(
        page.getByRole('row', { name: /10\.10\.50\.15/ }),
    ).toContainText(reason);

    // The list of the jacks with a deviation tells it too.
    await page.goto('/jacks?deviations=1');
    await expect(jackRow(page, 'E.04')).toContainText(reason);

    // The port is given the VLAN of the cameras.
    await openDevice(page, 'sw-etage-2');
    await page
        .locator('[data-test="port-row"][data-port="1/0/12"]')
        .getByRole('checkbox')
        .check();
    await page.locator('[data-test="set-port-mode"]').click();

    const mode = page.locator('[data-test="port-mode-dialog"]');

    await mode
        .getByLabel('VLAN', { exact: true })
        .selectOption({ label: '50 — Vidéosurveillance' });
    await mode.getByRole('button', { name: 'Appliquer' }).click();
    await expect(page.getByText('1 port modifié.')).toBeVisible();

    await page.goto('/jacks?deviations=1');
    await expect(jackRow(page, 'E.04')).toHaveCount(0);
    // The deviations the demonstration keeps on purpose are still there.
    await expect(jackRow(page, 'C.09')).toBeVisible();
    await expect(jackRow(page, 'R.05')).toBeVisible();
    await expect(jackRow(page, 'B.10')).toBeVisible();
});

test('a patched port is not deleted, and is once unpatched', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, 'sw-etage-2');

    const row = page.locator('[data-test="port-row"][data-port="1/0/9"]');

    // The line of the port says where it is patched to.
    await expect(row.locator('[data-test="port-cord"]')).toHaveText(
        /brassé vers\s*E\.01/,
    );

    await page.locator('[data-test="delete-port-1/0/9"]').click();
    await confirmation(page, 'Supprimer ce port ?')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(
        page.getByText(
            "1/0/9 est brassé vers PB-D 1 (E.01) : débrassez-le d'abord.",
        ),
    ).toBeVisible();
    await expect(row).toBeVisible();

    // Unpatched from the page of the panel, the port goes back to its jack
    // alone.
    await row.locator('[data-test="port-cord"]').getByRole('link').click();
    await expect(
        page.getByRole('heading', { name: PANEL, exact: true }),
    ).toBeVisible();
    await page.locator('[data-test="unpatch-1"]').click();
    await confirmation(page, 'Débrasser ce port ?')
        .getByRole('button', { name: 'Débrasser' })
        .click();
    await expect(page.getByText('Port débrassé.')).toBeVisible();
    await expect(cell(page, 1)).toHaveAttribute('data-state', 'jack');

    await openDevice(page, 'sw-etage-2');
    await expect(row.locator('[data-test="port-cord"]')).toHaveCount(0);
});

test('the colour of a cord is changed in its window, its two ends staying, and the panel shows it', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openPanel(page);

    // A port without a cord has nothing to change.
    await expect(page.locator('[data-test="edit-cord-1"]')).toBeDisabled();

    await page.locator('[data-test="edit-cord-2"]').click();

    const window = page.locator('[data-test="patch-cord-edit-dialog"]');

    await expect(window).toContainText(
        "Les deux bouts d'un cordon ne changent pas",
    );
    await expect(window.locator('[data-test="cord-ends"]')).toContainText(
        'PB-D 2 (E.02)',
    );
    await expect(window.locator('[data-test="cord-ends"]')).toContainText(
        /sw-etage-2\s*›\s*1\/0\/10/,
    );
    await expect(window.getByLabel('Couleur')).toHaveValue('Vert');
    await window.getByLabel('Couleur').fill('Orange');
    await window.getByLabel('Description').fill('Poste du fond');
    await window.locator('[data-test="save-cord"]').click();

    await expect(page.getByText('Cordon modifié.')).toBeVisible();
    await expect(window).toBeHidden();
    await expect(panelRow(page, 2)).toContainText('Orange');
    // Its two ends are those it had.
    await expect(
        panelRow(page, 2).locator('[data-test="panel-port-opposite"]'),
    ).toHaveText(/sw-etage-2\s*›\s*1\/0\/10/);
});

test('three ports are unpatched as a series, then the ticks are cleared and the ports are free', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openPanel(page);

    const unpatch = page.locator('[data-test="unpatch-series"]');

    // A port without a cord makes nothing to unpatch.
    await page.locator('[data-test="select-panel-port-5"]').check();
    await expect(unpatch).toBeDisabled();

    // Three patched ports, and the one without a cord, left out.
    for (const number of [2, 3, 4]) {
        await page.locator(`[data-test="select-panel-port-${number}"]`).check();
    }

    await expect(unpatch).toBeEnabled();
    await unpatch.click();

    const window = confirmation(page, 'Débrasser les ports cochés ?');

    await expect(window).toContainText(
        '3 cordons seront retirés : PB-D 2 ↔ sw-etage-2 › 1/0/10, PB-D 3 ↔ sw-etage-2 › 1/0/11, PB-D 4 ↔ sw-etage-2 › 1/0/12. Les ports restent.',
    );
    await window.getByRole('button', { name: 'Débrasser' }).click();

    await expect(page.getByText('3 cordons supprimés.')).toBeVisible();
    await expect(page.locator('[data-test="selected-panel-ports"]')).toHaveText(
        'Aucun port sélectionné',
    );

    for (const number of [2, 3, 4]) {
        await expect(cell(page, number)).toHaveAttribute('data-state', 'jack');
    }

    await expect(
        page.locator('[data-test="panel-cell"][data-state="patched"]'),
    ).toHaveCount(0);
});

test('a cord between two rooms is named by what tells the rooms apart', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openSite(page, 'Siège');
    await page
        .locator('[data-test="place-panel"][data-panel="PB-A"]')
        .getByRole('link', { name: 'PB-A', exact: true })
        .click();

    // The rescue cord of the demonstration, from the ground floor to the
    // technical room of the first floor: the building is not repeated.
    await expect(panelRow(page, 24)).toContainText(
        'Panneau dans Rez-de-chaussée › Local technique, sw-etage-1 dans 1er étage › Placard technique',
    );
});
