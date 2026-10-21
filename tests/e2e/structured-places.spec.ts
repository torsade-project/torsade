// torsade/tests/e2e/structured-places.spec.ts
import type { Locator, Page } from '@playwright/test';
import { addressTable, expect, openSubnet, test } from './support/fixtures';

// The places and the devices, on the demonstration network: the status of
// a site, its buildings, floors and rooms, a device kept in a room, its
// addresses attached and detached, the devices created from host names and
// the deviation of a device of another site.
//
// The demonstration has a site that is only planned, "Dépôt", without any
// place nor device: the first scenarios furnish it, and the following ones
// use what they created.

const DEVICE = 'sw-depot-01';

const openSite = async (page: Page, name: string): Promise<void> => {
    await page.goto('/sites');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name: `Site ${name}` }),
    ).toBeVisible();
};

const openDevice = async (page: Page, name: string): Promise<void> => {
    await page.goto('/devices');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name, exact: true }),
    ).toBeVisible();
};

// The window that asks to confirm has no name of its own.
const confirmation = (page: Page, title: string): Locator =>
    page.getByRole('dialog').filter({ hasText: title });

const building = (page: Page, name: string): Locator =>
    page.locator('[data-test="place-building"]').filter({ hasText: name });

const floor = (page: Page, name: string): Locator =>
    page.locator('[data-test="place-floor"]').filter({ hasText: name });

const room = (page: Page, name: string): Locator =>
    page.locator('[data-test="place-room"]').filter({ hasText: name });

test('a site has a status, shown in the list and on its page, filtered on and changed in its window', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/sites');

    const rows = page.locator('[data-test^="site-row"]');

    // Agreed with the site: "Prévu", not "Prévue".
    await expect(rows.filter({ hasText: 'Dépôt' })).toContainText('Prévu');
    await expect(rows.filter({ hasText: 'Siège' })).toContainText('Actif');

    // The filter keeps the sites of one status, and stays in the address.
    await page.getByLabel('Statut').selectOption({ label: 'Prévu' });
    await expect(page).toHaveURL(/status=planned/);
    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText('Dépôt');

    await page.getByLabel('Statut').selectOption({ label: 'Retiré' });
    await expect(
        page.getByText('Aucun site ne correspond à ce filtre.'),
    ).toBeVisible();

    await page.getByLabel('Statut').selectOption({ label: 'Tous les statuts' });
    await expect(rows.filter({ hasText: 'Atelier' })).toBeVisible();

    // On the page of the site, and in its window.
    await openSite(page, 'Dépôt');

    const status = page.locator('[data-test="site-status"]');

    await expect(status).toHaveText('Prévu');

    await page.locator('[data-test="edit-site"]').click();

    const dialog = page.getByRole('dialog', { name: 'Modifier le site' });

    await expect(dialog.getByLabel('Statut')).toHaveValue('planned');
    await dialog.getByLabel('Statut').selectOption({ label: 'Retiré' });
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Site modifié.')).toBeVisible();
    await expect(status).toHaveText('Retiré');

    // Back to what the demonstration says.
    await page.locator('[data-test="edit-site"]').click();
    await dialog.getByLabel('Statut').selectOption({ label: 'Prévu' });
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(status).toHaveText('Prévu');
});

test('a building, a floor and a technical room are created on the page of a site, and a device is kept in the room', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openSite(page, 'Dépôt');

    const places = page.getByRole('region', { name: 'Lieux' });

    await expect(
        places.locator('[data-test="site-places-empty"]'),
    ).toBeVisible();

    // --- A building ---------------------------------------------------------
    await places.getByRole('button', { name: 'Nouveau bâtiment' }).click();

    const buildingDialog = page.getByRole('dialog', {
        name: 'Nouveau bâtiment',
    });

    await buildingDialog.getByLabel('Nom', { exact: true }).fill('Entrepôt');
    await buildingDialog.getByLabel('Code').fill('e1');
    await buildingDialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Bâtiment créé.')).toBeVisible();
    await expect(buildingDialog).toBeHidden();

    const warehouse = building(page, 'Entrepôt');

    // The code is written in capitals.
    await expect(warehouse).toContainText('E1');
    await expect(warehouse).toContainText(
        'Aucun étage dans ce bâtiment pour le moment.',
    );

    // --- Its floors, shown from the lowest up -------------------------------
    const addFloor = async (level: string, name: string): Promise<Locator> => {
        await warehouse.getByRole('button', { name: 'Nouvel étage' }).click();

        const dialog = page.getByRole('dialog', { name: 'Nouvel étage' });

        await dialog.getByLabel('Niveau').fill(level);
        await dialog.getByLabel('Nom', { exact: true }).fill(name);
        await dialog.getByRole('button', { name: 'Enregistrer' }).click();

        return dialog;
    };

    await expect(await addFloor('0', 'Rez-de-chaussée')).toBeHidden();
    await expect(floor(page, 'Rez-de-chaussée')).toBeVisible();
    await expect(await addFloor('-1', 'Sous-sol')).toBeHidden();

    const floors = warehouse.locator('[data-test="place-floor"]');

    await expect(floors).toHaveCount(2);
    await expect(floors.nth(0)).toContainText('Sous-sol');
    await expect(floors.nth(0)).toContainText('Niveau -1');
    await expect(floors.nth(1)).toContainText('Rez-de-chaussée');

    // A building has one floor per level: the refusal names the floor that
    // is already there.
    const refused = await addFloor('0', 'Mezzanine');

    await expect(refused.getByLabel('Niveau')).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    await expect(refused).toContainText(
        "L'étage Rez-de-chaussée de ce bâtiment est déjà au niveau 0.",
    );
    await refused.getByRole('button', { name: 'Annuler' }).click();
    await expect(floors).toHaveCount(2);

    // --- A technical room ---------------------------------------------------
    const ground = floor(page, 'Rez-de-chaussée');

    await ground.getByRole('button', { name: 'Nouveau local' }).click();

    const roomDialog = page.getByRole('dialog', { name: 'Nouveau local' });

    await expect(roomDialog).toContainText(
        'Un local de Entrepôt › Rez-de-chaussée.',
    );
    await roomDialog.getByLabel('Nom', { exact: true }).fill('Local réseau');
    await roomDialog.getByRole('checkbox', { name: 'Local technique' }).check();
    await roomDialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Local créé.')).toBeVisible();

    const network = room(page, 'Local réseau');

    await expect(network.locator('[data-test="technical-label"]')).toHaveText(
        'Technique',
    );
    await expect(network.locator('[data-test="room-devices"]')).toHaveText(
        '0 équipement',
    );

    // --- A place is only deleted once empty ---------------------------------
    await warehouse.getByRole('button', { name: 'Supprimer' }).first().click();
    await confirmation(page, 'Supprimer ce bâtiment ?')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(
        page.getByText(
            "Des étages sont encore rattachés à ce bâtiment (2) : supprimez-les d'abord.",
        ),
    ).toBeVisible();

    await ground.getByRole('button', { name: 'Supprimer' }).first().click();
    await confirmation(page, 'Supprimer cet étage ?')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(
        page.getByText(
            "Un local est encore rattaché à cet étage : supprimez-le d'abord.",
        ),
    ).toBeVisible();

    // --- A device kept in that room -----------------------------------------
    await network.getByRole('link', { name: 'Ajouter un équipement' }).click();
    await expect(
        page.getByRole('heading', { name: 'Nouvel équipement' }),
    ).toBeVisible();

    // The site and the room come already chosen; the room is offered with
    // its path, and says it is technical.
    const site = page.getByLabel('Site', { exact: true });
    const place = page.getByLabel('Local', { exact: true });

    await expect(site.locator('option:checked')).toHaveText('Dépôt');
    await expect(place.locator('option:checked')).toHaveText(
        'Entrepôt › Rez-de-chaussée › Local réseau (Technique)',
    );

    await page.getByLabel('Nom', { exact: true }).fill(DEVICE);
    await page.getByLabel('Type', { exact: true }).selectOption({
        label: 'Switch',
    });
    await page.getByLabel('Fabricant').fill('Trois Brins Matériel');
    await page.getByLabel('Modèle').fill('SW-24');
    await page.getByLabel('Numéro de série').fill('DEMO-DEPOT-1');
    await page.getByLabel('Statut').selectOption({ label: 'Prévu' });
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Équipement créé.')).toBeVisible();

    // Its page: what it is, and where.
    await expect(page.getByRole('heading', { name: DEVICE })).toBeVisible();
    await expect(page.locator('[data-test="device-type"]')).toContainText(
        'Switch',
    );
    await expect(page.locator('[data-status="planned"]').first()).toHaveText(
        'Prévu',
    );
    await expect(page.locator('[data-test="device-site"]')).toHaveText('Dépôt');
    await expect(page.locator('[data-test="place-path"]')).toContainText(
        'Entrepôt › Rez-de-chaussée › Local réseau',
    );
    await expect(page.locator('[data-test="technical-label"]')).toHaveText(
        'Technique',
    );
    await expect(page.locator('[data-test="device-make"]')).toHaveText(
        'Trois Brins Matériel SW-24',
    );
    await expect(page.getByText('DEMO-DEPOT-1')).toBeVisible();

    // Back on the site: the room counts it, the table of devices lists it
    // with its place, and the room can no longer be deleted.
    await page.locator('[data-test="device-site"]').click();
    await expect(room(page, 'Local réseau')).toContainText('1 équipement');

    const row = page
        .getByRole('region', { name: 'Équipements' })
        .getByRole('row', { name: new RegExp(DEVICE) });

    await expect(row).toContainText(
        'Entrepôt › Rez-de-chaussée › Local réseau',
    );
    await expect(row).toContainText('Technique');

    await room(page, 'Local réseau')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    // The window says what keeps a room from being deleted.
    await expect(confirmation(page, 'Supprimer ce local ?')).toContainText(
        "Un local où des équipements, des membres d'équipement ou des panneaux de brassage sont encore rangés ne peut pas être supprimé.",
    );
    await confirmation(page, 'Supprimer ce local ?')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(
        page.getByText(
            "Ce local contient encore 1 équipement : videz-le d'abord.",
        ),
    ).toBeVisible();
});

test('a room is moved to another floor of its site from its window, and its devices follow', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openSite(page, 'Dépôt');

    const edit = async (): Promise<Locator> => {
        await room(page, 'Local réseau')
            .getByRole('button', { name: 'Modifier' })
            .click();

        return page.getByRole('dialog', { name: 'Modifier le local' });
    };

    // The floors of the site, each with its building: no other site.
    let dialog = await edit();

    await expect(dialog.getByLabel('Étage').locator('option')).toHaveText([
        'Entrepôt › Sous-sol',
        'Entrepôt › Rez-de-chaussée',
    ]);
    await expect(
        dialog.getByLabel('Étage').locator('option:checked'),
    ).toHaveText('Entrepôt › Rez-de-chaussée');

    await dialog
        .getByLabel('Étage')
        .selectOption({ label: 'Entrepôt › Sous-sol' });
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Local modifié.')).toBeVisible();
    await expect(
        floor(page, 'Sous-sol').locator('[data-test="place-room"]'),
    ).toContainText('Local réseau');
    await expect(
        floor(page, 'Rez-de-chaussée').locator('[data-test="place-room"]'),
    ).toHaveCount(0);

    // The device kept in it is where its room is.
    await expect(
        page
            .getByRole('region', { name: 'Équipements' })
            .getByRole('row', { name: new RegExp(DEVICE) }),
    ).toContainText('Entrepôt › Sous-sol › Local réseau');

    // And back, for the scenarios that follow.
    dialog = await edit();
    await dialog
        .getByLabel('Étage')
        .selectOption({ label: 'Entrepôt › Rez-de-chaussée' });
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        floor(page, 'Rez-de-chaussée').locator('[data-test="place-room"]'),
    ).toContainText('Local réseau');
});

test('the form of a device offers the rooms of its site, and a name is unique within the site whatever its case', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, DEVICE);
    await page.locator('[data-test="edit-device"]').click();
    await expect(
        page.getByRole('heading', { name: "Modifier l'équipement" }),
    ).toBeVisible();

    const site = page.getByLabel('Site', { exact: true });
    const place = page.getByLabel('Local', { exact: true });

    await expect(place.locator('option')).toHaveText([
        'Non précisé',
        'Entrepôt › Rez-de-chaussée › Local réseau (Technique)',
    ]);

    // Another site: the room is left, and the rooms offered are its own.
    await site.selectOption({ label: 'Atelier' });
    await expect(place.locator('option:checked')).toHaveText('Non précisé');
    await expect(place.locator('option')).toHaveText([
        'Non précisé',
        'Halle de production › Mezzanine › Bureaux',
        'Halle de production › Rez-de-chaussée › Hall de production',
        'Halle de production › Rez-de-chaussée › Local technique (Technique)',
        "Halle de production › Rez-de-chaussée › Quai d'expédition",
    ]);

    // Nothing is saved: the device stays where it is.
    await page.getByRole('link', { name: 'Annuler' }).click();
    await expect(page.locator('[data-test="device-site"]')).toHaveText('Dépôt');

    // A second device of the same name on the site, written otherwise.
    await page.goto('/devices/create');
    await page.getByLabel('Site', { exact: true }).selectOption({
        label: 'Dépôt',
    });
    await page.getByLabel('Nom', { exact: true }).fill('SW-Depot-01');
    await page.getByLabel('Type', { exact: true }).selectOption({
        label: 'Switch',
    });
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByLabel('Nom', { exact: true })).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    await expect(
        page.getByText(`Le site Dépôt a déjà un équipement nommé ${DEVICE}.`),
    ).toBeVisible();
});

test('the list of devices shows where each one is, and is filtered and searched', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await page.goto('/devices');

    const table = page.getByRole('table', { name: 'Équipements' });
    const rows = table.locator('[data-test^="device-row"]');

    for (const heading of [
        'Nom',
        'Type',
        'Site',
        'Lieu',
        'Adresses',
        'Statut',
    ]) {
        await expect(
            table.getByRole('columnheader', { name: heading }),
        ).toBeVisible();
    }

    // A firewall with its four interfaces, in the technical room.
    const firewall = table.getByRole('row', { name: /fw-siege/ });

    await expect(firewall).toContainText('Pare-feu');
    await expect(firewall).toContainText(
        'Bâtiment principal › Rez-de-chaussée › Local technique',
    );
    await expect(firewall).toContainText('Technique');
    await expect(firewall.getByRole('cell').nth(4)).toHaveText('4');

    // A device whose place is not known says so.
    await expect(
        table.getByRole('row', { name: /onduleur-atelier/ }),
    ).toContainText('Non précisé');

    // By site, by type and by status: the address keeps the filters.
    await page.getByLabel('Site', { exact: true }).selectOption({
        label: 'Atelier',
    });
    await expect(page).toHaveURL(/site=\d+/);
    await expect(rows.filter({ hasText: 'fw-siege' })).toHaveCount(0);
    await expect(rows.filter({ hasText: 'fw-atelier' })).toHaveCount(1);

    await page.getByLabel('Type', { exact: true }).selectOption({
        label: 'Pare-feu',
    });
    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText('fw-atelier');

    await page.getByLabel('Statut').selectOption({ label: 'Prévu' });
    await expect(
        page.getByText('Aucun équipement ne correspond à ces filtres.'),
    ).toBeVisible();

    await page.goto('/devices');
    await page.getByLabel('Statut').selectOption({ label: 'Prévu' });
    await expect(rows.filter({ hasText: 'nas-02' })).toHaveCount(1);
    await expect(rows.filter({ hasText: 'nas-01' })).toHaveCount(0);

    // By name, by model and by serial number.
    await page.goto('/devices');

    const search = page.getByLabel('Rechercher', { exact: true });

    await search.fill('PF-200');
    await search.press('Enter');
    await expect(page).toHaveURL(/search=PF-200/);
    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText('fw-siege');

    await search.fill('demo-depot');
    await page.getByRole('button', { name: 'Rechercher', exact: true }).click();
    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText(DEVICE);

    await search.fill('ap-');
    await search.press('Enter');
    await expect(rows.filter({ hasText: 'ap-accueil' })).toHaveCount(1);
    await expect(rows.filter({ hasText: 'ap-quai' })).toHaveCount(1);
    await expect(rows.filter({ hasText: 'fw-siege' })).toHaveCount(0);

    // A reader reads, and is offered nothing to write.
    await expect(
        page.getByRole('link', { name: 'Nouvel équipement' }),
    ).toHaveCount(0);
});

test('an address is attached to a device, shown as a deviation when it is on another site, then detached', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, DEVICE);

    const addresses = page.getByRole('region', { name: 'Adresses' });
    const deviations = page.getByRole('region', { name: 'Écarts' });

    await expect(addresses).toContainText(
        "Aucune adresse n'est rattachée à cet équipement pour le moment.",
    );
    await expect(deviations).toContainText('Aucun écart.');

    // --- An address that already exists, looked for on every site -----------
    await addresses
        .getByRole('button', { name: 'Rattacher une adresse' })
        .click();

    const window = page.getByRole('dialog', { name: 'Rattacher une adresse' });
    const found = window.locator('[data-test="attachable-address"]');

    await expect(found.first()).toBeVisible();

    // By the beginning of its address: 10.10.20.11, .15 and .16. The
    // addresses in between belong to a device, and are not offered.
    await window.getByLabel("Adresse ou nom d'hôte").fill('10.10.20.1');
    await expect(found).toHaveCount(3);
    await expect(found.filter({ hasText: '10.10.20.16' })).toHaveCount(1);
    await expect(found.filter({ hasText: '10.10.20.12' })).toHaveCount(0);

    // By a part of its host name.
    await window.getByLabel("Adresse ou nom d'hôte").fill('messagerie');
    await expect(found).toHaveCount(1);
    await expect(found).toContainText('10.10.20.16');
    await expect(found).toContainText('srv-messagerie-01');
    await expect(found).toContainText('10.10.20.0/24 — Siège');

    await found.getByRole('button', { name: 'Rattacher' }).click();
    await expect(
        page.getByText('Adresse 10.10.20.16 rattachée.'),
    ).toBeVisible();
    await expect(window).toBeHidden();

    // The address of the page is still the one of the device.
    await expect(page).toHaveURL(/\/devices\/\d+$/);

    // Its line: where it is, and that it is on another site.
    const line = addresses.getByRole('row', { name: /10\.10\.20\.16/ });
    const reason = 'Sous-réseau du site Siège, équipement du site Dépôt';

    await expect(line).toContainText('10.10.20.0/24');
    await expect(line).toContainText('20 — Serveurs');
    await expect(line).toContainText('srv-messagerie-01');
    await expect(line).toContainText('Active');
    await expect(line).toContainText(reason);
    await expect(
        deviations.locator('[data-test="deviation-item"]'),
    ).toHaveCount(1);
    await expect(deviations).toContainText(reason);

    // In the table of its subnet too, with a link to the device.
    await line.getByRole('link', { name: '10.10.20.0/24' }).click();
    await expect(
        page.getByRole('heading', { name: '10.10.20.0/24' }),
    ).toBeVisible();

    const inSubnet = addressTable(page).getByRole('row', {
        name: /10\.10\.20\.16/,
    });

    await expect(inSubnet).toContainText(reason);
    // An address on the site of its device differs from nothing.
    await expect(
        addressTable(page)
            .getByRole('row', { name: /10\.10\.20\.12/ })
            .locator('[data-test="deviations"]'),
    ).toHaveCount(0);

    await inSubnet.getByRole('link', { name: DEVICE }).click();
    await expect(page.getByRole('heading', { name: DEVICE })).toBeVisible();

    // --- Detached, it stays documented --------------------------------------
    await line.getByRole('button', { name: 'Détacher' }).click();
    await expect(page.getByText('Adresse 10.10.20.16 détachée.')).toBeVisible();
    await expect(line).toHaveCount(0);
    await expect(deviations).toContainText('Aucun écart.');

    await openSubnet(page, '10.10.20.0/24');
    await expect(inSubnet).toContainText('srv-messagerie-01');
    await expect(inSubnet.getByRole('link', { name: DEVICE })).toHaveCount(0);
});

test('an address is created from the page of a device, which is not deleted while it has one', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, DEVICE);

    const addresses = page.getByRole('region', { name: 'Adresses' });

    await addresses.getByRole('button', { name: 'Nouvelle adresse' }).click();

    const window = page.getByRole('dialog', { name: 'Nouvelle adresse IP' });

    // The device is known: the subnet is what is chosen here.
    await expect(window).toContainText(`Une adresse de ${DEVICE}`);
    await expect(window.getByLabel('Équipement')).toHaveCount(0);

    await window.getByLabel('Sous-réseau').selectOption({
        label: '10.10.99.0/24 — 99 — Administration réseau — Siège',
    });
    await expect(window).toContainText(
        'Adresses utilisables de 10.10.99.0/24 : 10.10.99.1 – 10.10.99.254.',
    );

    // The rules of the addressing plan are the same from here.
    await window.getByLabel('Adresse', { exact: true }).fill('10.10.99.11');
    await window.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(window).toContainText('Cette adresse est déjà documentée.');

    await window.getByLabel('Adresse', { exact: true }).fill('10.10.99.60');
    await window.getByLabel("Nom d'hôte").fill(DEVICE);
    await window.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Adresse IP créée.')).toBeVisible();
    await expect(window).toBeHidden();

    // Back on the device, with its new interface.
    await expect(page.getByRole('heading', { name: DEVICE })).toBeVisible();
    await expect(
        addresses.getByRole('row', { name: /10\.10\.99\.60/ }),
    ).toContainText('99 — Administration réseau');

    // --- A device that has an address is not deleted ------------------------
    await page.locator('[data-test="delete-device"]').click();
    await confirmation(page, 'Supprimer cet équipement ?')
        .getByRole('button', { name: 'Supprimer' })
        .click();

    const refusal = page.locator('[data-test="deletion-refused"]');

    await expect(refusal).toContainText(
        "Une adresse IP est encore rattachée à cet équipement : détachez-la d'abord.",
    );

    // The refusal leads to what lifts it.
    await refusal
        .getByRole('button', { name: 'Détacher toutes les adresses' })
        .click();

    const detaching = confirmation(page, 'Détacher toutes les adresses ?');

    await expect(detaching).toContainText(
        `L'adresse de ${DEVICE} ne lui sera plus rattachée.`,
    );
    await detaching.getByRole('button', { name: 'Détacher' }).click();
    await expect(page.getByText('1 adresse détachée.')).toBeVisible();
    await expect(refusal).toHaveCount(0);
    await expect(addresses).toContainText(
        "Aucune adresse n'est rattachée à cet équipement pour le moment.",
    );

    // The address is still documented, under its host name.
    await openSubnet(page, '10.10.99.0/24');
    await expect(
        addressTable(page).getByRole('row', { name: /10\.10\.99\.60/ }),
    ).toContainText(DEVICE);
});

test('the window of an address chooses its device, those of the site of the subnet first', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openSubnet(page, '10.20.60.0/24');

    const table = addressTable(page);
    const line = table.getByRole('row', { name: /10\.20\.60\.33/ });

    // The device has no column of its own: it is written under the host
    // name.
    await expect(
        table.getByRole('columnheader', { name: 'Équipement' }),
    ).toHaveCount(0);

    // The demonstration has an interface of a server of the head office on
    // this network of the workshop.
    await expect(
        table.getByRole('row', { name: /10\.20\.60\.50/ }),
    ).toContainText('Sous-réseau du site Atelier, équipement du site Siège');

    await line.getByRole('button', { name: 'Modifier' }).click();

    const window = page.getByRole('dialog', { name: "Modifier l'adresse IP" });
    const device = window.getByLabel('Équipement');

    await expect(device.locator('option:checked')).toHaveText(
        'Aucun équipement',
    );
    // The devices of the workshop come first.
    await expect(device.locator('optgroup').first()).toHaveAttribute(
        'label',
        'Site du sous-réseau',
    );
    await expect(
        device.locator('optgroup').first().locator('option').first(),
    ).toContainText('Atelier');
    await expect(device.locator('optgroup').nth(1)).toHaveAttribute(
        'label',
        'Autres sites',
    );

    await device.selectOption({ label: 'srv-supervision-01 — Atelier' });
    await window.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Adresse IP modifiée.')).toBeVisible();

    // The line names the device, with a link, and nothing differs: both
    // are of the workshop. The host name is what it was.
    await expect(
        line.getByRole('link', { name: 'srv-supervision-01' }),
    ).toBeVisible();
    await expect(line.locator('[data-test="deviations"]')).toHaveCount(0);
    await expect(line).toContainText('palettiseur-01');

    // Taken away the same way.
    await line.getByRole('button', { name: 'Modifier' }).click();
    await expect(device.locator('option:checked')).toHaveText(
        'srv-supervision-01 — Atelier',
    );
    await device.selectOption({ label: 'Aucun équipement' });
    await window.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Adresse IP modifiée.')).toBeVisible();
    await expect(
        line.getByRole('link', { name: 'srv-supervision-01' }),
    ).toHaveCount(0);
});

test('the devices of a site are created from the host names of its addresses, all of what is ticked or nothing', async ({
    page,
    logIn,
    context,
}) => {
    await logIn('editor');
    await openSite(page, 'Atelier');
    await page
        .getByRole('link', {
            name: "Créer des équipements depuis les noms d'hôte",
        })
        .click();
    await expect(
        page.getByRole('heading', {
            name: "Créer les équipements de Atelier depuis les noms d'hôte",
        }),
    ).toBeVisible();

    const lines = page.locator('[data-test^="hostname-line-"]');
    const line = (hostname: string): Locator =>
        page.locator(`[data-test="hostname-line-${hostname}"]`);
    const submit = page.locator('[data-test="create-hostname-devices"]');
    const total = await lines.count();

    // Every name is ticked when the page opens.
    expect(total).toBeGreaterThan(20);
    await expect(lines.getByRole('checkbox', { checked: true })).toHaveCount(
        total,
    );
    const ticked = page.locator('[data-test="hostnames-ticked"]');

    await expect(ticked).toHaveText(`${total} noms d'hôte cochés`);

    // A line: the name, its addresses, the type proposed and what will be
    // done.
    await expect(line('automate-ligne-1')).toContainText('1 adresse');
    await expect(line('automate-ligne-1')).toContainText('10.20.60.10');
    const type = (hostname: string): Locator =>
        line(hostname).getByRole('combobox', { name: `Type de ${hostname}` });
    const status = (hostname: string): Locator =>
        line(hostname).getByRole('combobox', {
            name: `Statut de ${hostname}`,
        });

    await expect(type('automate-ligne-1')).toHaveValue('other');
    await expect(type('cam-atelier-1')).toHaveValue('camera');
    // In service, unless every address of the name is only planned.
    await expect(status('automate-ligne-1')).toHaveValue('active');
    await expect(status('automate-ligne-3')).toHaveValue('planned');
    await expect(
        line('automate-ligne-1').locator('[data-test="hostname-action"]'),
    ).toHaveText('Créer');
    // What already belongs to a device is not proposed.
    await expect(line('fw-atelier')).toHaveCount(0);
    await expect(line('srv-sauvegarde-01')).toHaveCount(0);

    await page.getByRole('button', { name: 'Tout décocher' }).click();
    await expect(ticked).toHaveText("0 nom d'hôte coché");
    await expect(submit).toBeDisabled();

    // Two names, one of them with another type than the one proposed.
    await line('automate-ligne-1').getByRole('checkbox').check();
    await type('automate-ligne-1').selectOption({ label: 'Serveur' });
    await line('cam-atelier-1').getByRole('checkbox').check();
    await status('cam-atelier-1').selectOption({ label: 'Prévu' });
    await expect(submit).toHaveText('Traiter les 2 lignes cochées');
    await submit.click();

    // Back on the site, with what was done.
    await expect(
        page.getByText('2 équipements créés, 2 adresses rattachées.'),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'Site Atelier' }),
    ).toBeVisible();

    const devices = page.getByRole('region', { name: 'Équipements' });

    await expect(
        devices.getByRole('row', { name: /automate-ligne-1/ }),
    ).toContainText('Serveur');
    await expect(
        devices.getByRole('row', { name: /cam-atelier-1/ }),
    ).toContainText('Caméra');
    await expect(
        devices.getByRole('row', { name: /cam-atelier-1/ }),
    ).toContainText('Prévu');

    // The address is an interface of its device, and its host name is what
    // it was.
    await devices.getByRole('link', { name: 'automate-ligne-1' }).click();
    await expect(
        page
            .getByRole('region', { name: 'Adresses' })
            .getByRole('row', { name: /10\.20\.60\.10/ }),
    ).toContainText('automate-ligne-1');

    // --- All or nothing -----------------------------------------------------
    await openSite(page, 'Atelier');
    await page
        .getByRole('link', {
            name: "Créer des équipements depuis les noms d'hôte",
        })
        .click();
    await expect(lines).toHaveCount(total - 2);
    await page.getByRole('button', { name: 'Tout décocher' }).click();
    await line('cam-quai-atelier').getByRole('checkbox').check();
    await line('trefileuse-01').getByRole('checkbox').check();

    // In the meantime, someone else does one of the two names.
    const other = await context.newPage();

    await other.goto(page.url());
    await other.getByRole('button', { name: 'Tout décocher' }).click();
    await other
        .locator('[data-test="hostname-line-cam-quai-atelier"]')
        .getByRole('checkbox')
        .check();
    await other.locator('[data-test="create-hostname-devices"]').click();
    await expect(
        other.getByText('1 équipement créé, 1 adresse rattachée.'),
    ).toBeVisible();
    await other.close();

    // The refusal is told on its line, and the other line is not done.
    await submit.click();
    await expect(line('cam-quai-atelier')).toContainText(
        "Plus aucune adresse sans équipement de ce site ne porte ce nom d'hôte.",
    );
    await expect(
        line('cam-quai-atelier').getByRole('checkbox'),
    ).toHaveAttribute('aria-invalid', 'true');
    await expect(
        line('trefileuse-01').getByRole('checkbox'),
    ).not.toHaveAttribute('aria-invalid', 'true');

    await openSite(page, 'Atelier');
    await expect(
        page
            .getByRole('region', { name: 'Équipements' })
            .getByRole('row', { name: /trefileuse-01/ }),
    ).toHaveCount(0);

    // --- A name the site already has a device for ---------------------------
    await openSite(page, 'Siège');
    await page
        .getByRole('link', {
            name: "Créer des équipements depuis les noms d'hôte",
        })
        .click();

    await expect(
        line('sw-etage-2').locator('[data-test="hostname-action"]'),
    ).toHaveText("Rattacher à l'équipement existant sw-etage-2");
    // Its type is its own: nothing is chosen.
    await expect(line('sw-etage-2').getByRole('combobox')).toHaveCount(0);

    await page.getByRole('button', { name: 'Tout décocher' }).click();
    await line('sw-etage-2').getByRole('checkbox').check();
    await expect(submit).toHaveText('Traiter la ligne cochée');
    await submit.click();
    await expect(
        page.getByText('1 rattachement, 1 adresse rattachée.'),
    ).toBeVisible();
    await expect(
        page
            .getByRole('region', { name: 'Équipements' })
            .getByRole('row', { name: /sw-etage-2/ })
            .getByRole('cell')
            .nth(4),
    ).toHaveText('1');
});
