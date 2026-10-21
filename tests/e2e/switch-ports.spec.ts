// torsade/tests/e2e/switch-ports.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// What a switch is made of, on the demonstration network: a template and
// the names it gives to ports, the members of a device made from it, their
// ports ticked and configured several at a time, an aggregate over two
// members, and the ports that carry a VLAN.
//
// The demonstration has a switch without a member, at the workshop:
// "sw-atelier-02" gets its members here, and its ports carry the VLANs of
// the workshop. Each scenario uses what the ones before it created.

const TEMPLATE = 'Switch 12 ports et 2 SFP+';
const DEVICE = 'sw-atelier-02';

const openDevice = async (page: Page, name: string): Promise<void> => {
    await page.goto('/devices');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name, exact: true }),
    ).toBeVisible();
};

// The VLAN that has this subnet.
const openVlan = async (
    page: Page,
    cidr: string,
    name: string,
): Promise<void> => {
    await page.goto('/vlans');
    await page
        .getByRole('row', { name: new RegExp(cidr.replaceAll('.', '\\.')) })
        .getByRole('link', { name, exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: new RegExp(name) }),
    ).toBeVisible();
};

const templateRow = (page: Page, name: string): Locator =>
    page.locator('[data-test^="template-row-"]').filter({ hasText: name });

const portRow = (page: Page, name: string): Locator =>
    page.locator(`[data-test="port-row"][data-port="${name}"]`);

const memberRow = (page: Page, position: number): Locator =>
    page.locator(`[data-test="member-row-${position}"]`);

// The window that asks to confirm has no name of its own.
const confirmation = (page: Page, title: string): Locator =>
    page.getByRole('dialog').filter({ hasText: title });

test('a template is created from its groups of ports, which shows the names they give', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/device-templates');

    // The generic templates of the demonstration are there already: the
    // action that adds them is no longer offered.
    await expect(templateRow(page, 'Pare-feu 8 ports')).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Ajouter les modèles génériques' }),
    ).toHaveCount(0);

    await page.getByRole('link', { name: 'Nouveau modèle' }).click();
    await expect(
        page.getByRole('heading', { name: 'Nouveau modèle' }),
    ).toBeVisible();

    await page.getByLabel('Nom', { exact: true }).fill(TEMPLATE);
    await page.getByLabel('Type').selectOption({ label: 'Switch' });

    const preview = page.locator('[data-test="template-preview"]');
    const group = (place: number): Locator =>
        page.locator(`[data-test="template-group-${place}"]`);
    const hint = (place: number): Locator =>
        page.locator(`[data-test="template-group-hint-${place}"]`);

    await expect(preview).toContainText('Aucun port pour le moment.');
    await expect(preview).toContainText('0 port');

    // The names are shown while the group is typed.
    await page.locator('[data-test="add-template-group"]').click();
    await group(0).getByLabel('Quantité').fill('12');
    await expect(preview).toContainText('1/0/1 à 1/0/12');
    await expect(preview).toContainText('12 ports');

    // A new group starts where the one before it ends.
    await page.locator('[data-test="add-template-group"]').click();
    await expect(group(1).getByLabel('Premier numéro')).toHaveValue('13');
    await expect(preview).toContainText('1/0/1 à 1/0/12, puis 1/0/13 à 1/0/24');
    await expect(preview).toContainText('24 ports');

    // A pattern that cannot name ports is told on its line, and its ports
    // are left out of the names.
    await group(1).getByLabel('Motif').fill('{m}/1/');
    await expect(hint(1)).toHaveText(
        'Un motif contient {n}, le numéro du port, une fois : « {m}/0/{n} » ou « port{n} ».',
    );
    await expect(hint(0)).toHaveCount(0);
    await expect(preview).toContainText('12 ports');

    // So is a group that repeats a name of another one.
    await group(1).getByLabel('Motif').fill('{m}/0/{n}');
    await group(1).getByLabel('Premier numéro').fill('12');
    await expect(hint(1)).toHaveText(
        'Ce groupe donne le nom 1/0/12, que le groupe 1 donne déjà.',
    );

    // The uplinks of the switch.
    await group(1).getByLabel('Motif').fill('{m}/1/{n}');
    await group(1).getByLabel('Premier numéro').fill('1');
    await group(1).getByLabel('Quantité').fill('2');
    await group(1).getByLabel('Connecteur').selectOption({ label: 'SFP+' });
    await group(1).getByLabel('Débit').selectOption({ label: '10 Gbit/s' });

    await expect(hint(1)).toHaveCount(0);
    await expect(preview).toContainText('1/0/1 à 1/0/12, puis 1/1/1 à 1/1/2');
    await expect(preview).toContainText('14 ports');

    // The groups are put in order: the names follow.
    await page.getByRole('button', { name: 'Descendre le groupe 1' }).click();
    await expect(preview).toContainText('1/1/1 à 1/1/2, puis 1/0/1 à 1/0/12');
    await page.getByRole('button', { name: 'Monter le groupe 2' }).click();
    await expect(preview).toContainText('1/0/1 à 1/0/12, puis 1/1/1 à 1/1/2');

    await page.locator('[data-test="save-template"]').click();
    await expect(page.getByText('Modèle créé.')).toBeVisible();

    // In the list, with the names its ports get and their number.
    const row = templateRow(page, TEMPLATE);

    await expect(row.locator('[data-test="template-names"]')).toHaveText(
        '1/0/1 à 1/0/12, puis 1/1/1 à 1/1/2',
    );
    await expect(row.getByRole('cell').nth(3)).toHaveText('14');
    await expect(row.getByRole('cell').nth(4)).toHaveText('0');
});

test('two members are added to a device from the template, each with its ports', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, DEVICE);

    const ports = page.locator('[data-test="port-row"]');

    // A device without a member has no port, and no list of members.
    await expect(page.locator('[data-test="device-ports"]')).toContainText(
        "Cet équipement n'a pas encore de port.",
    );
    await expect(page.locator('[data-test="device-members"]')).toHaveCount(0);

    // Choosing a template creates its member 1, primary, with its ports.
    await page.locator('[data-test="choose-template"]').click();

    const choice = page.locator('[data-test="template-choice-dialog"]');

    await choice.getByLabel('Modèle').selectOption({ label: TEMPLATE });
    await expect(
        choice.locator('[data-test="template-choice-preview"]'),
    ).toContainText('1/0/1 à 1/0/12, puis 1/1/1 à 1/1/2');
    await expect(
        choice.locator('[data-test="template-choice-preview"]'),
    ).toContainText('14 ports');
    // Said before anything is saved: the serial number of the device
    // becomes the one of its first member.
    await expect(
        choice.locator('[data-test="serial-number-moves"]'),
    ).toContainText(/Son numéro de série, DEMO-[0-9A-F]{8}, passe au membre 1/);
    await choice.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(memberRow(page, 1)).toContainText('Principal');
    await expect(memberRow(page, 1)).toContainText(/DEMO-[0-9A-F]{8}/);
    await expect(memberRow(page, 1)).toContainText(TEMPLATE);
    await expect(memberRow(page, 1)).toContainText("Comme l'équipement");
    await expect(ports).toHaveCount(14);

    // The second member: its number and the template of the first one are
    // proposed, and the names of its ports bear its number.
    await page.locator('[data-test="add-member"]').click();

    const dialog = page.locator('[data-test="member-dialog"]');

    await expect(dialog.getByLabel('Numéro', { exact: true })).toHaveValue('2');
    await expect(dialog.locator('[data-test="member-preview"]')).toContainText(
        '2/0/1 à 2/0/12, puis 2/1/1 à 2/1/2',
    );
    await expect(dialog.locator('[data-test="member-preview"]')).toContainText(
        '14 ports',
    );

    // A device has one primary member at most: the refusal is told on the
    // role, and nothing is created.
    await dialog.getByLabel('Rôle').selectOption({ label: 'Principal' });
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        dialog.getByText(
            'Cet équipement a déjà un membre principal, le membre 1 : il en a un au plus.',
        ),
    ).toBeVisible();
    await expect(ports).toHaveCount(14);

    await dialog.getByLabel('Rôle').selectOption({ label: 'Secondaire' });
    await dialog.getByLabel('Numéro de série').fill('SN-ESSAI-0002');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(memberRow(page, 2)).toContainText('Secondaire');
    await expect(memberRow(page, 2)).toContainText('SN-ESSAI-0002');
    await expect(ports).toHaveCount(28);
    await expect(
        page.locator('[data-test="member-ports-2"] [data-test="port-row"]'),
    ).toHaveCount(14);
    await expect(portRow(page, '2/1/2')).toContainText('SFP+');

    // With members, the form of the device no longer asks for its serial
    // number: each member has its own.
    await page.locator('[data-test="edit-device"]').click();
    await expect(
        page.getByRole('heading', { name: "Modifier l'équipement" }),
    ).toBeVisible();
    await expect(page.getByLabel('Numéro de série')).toHaveCount(0);
    await expect(
        page.locator('[data-test="serial-number-note"]'),
    ).toBeVisible();

    // The template is used by two members: it is not deleted.
    await page.goto('/device-templates');

    const row = templateRow(page, TEMPLATE);

    await expect(row.getByRole('cell').nth(4)).toHaveText('2');
    await row.getByRole('button', { name: 'Supprimer' }).click();
    await confirmation(page, 'Supprimer ce modèle ?')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(
        page.getByText(
            "Des membres d'équipements utilisent encore ce modèle (2) : retirez-leur ce modèle d'abord.",
        ),
    ).toBeVisible();
    await expect(row).toBeVisible();
});

test('a range of ports is ticked, then put in access mode on a VLAN with a voice VLAN', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, DEVICE);

    const selected = page.locator('[data-test="selected-ports"]');

    await expect(selected).toHaveText('Aucun port sélectionné');
    await expect(page.locator('[data-test="set-port-mode"]')).toBeDisabled();

    // A click, then a click with the Shift key: every port between them.
    await portRow(page, '1/0/3').getByRole('checkbox').click();
    await expect(selected).toHaveText('1 port sélectionné');
    await portRow(page, '1/0/10')
        .getByRole('checkbox')
        .click({ modifiers: ['Shift'] });
    await expect(selected).toHaveText('8 ports sélectionnés');
    await expect(portRow(page, '1/0/6').getByRole('checkbox')).toBeChecked();
    await expect(
        portRow(page, '1/0/11').getByRole('checkbox'),
    ).not.toBeChecked();

    // A whole member at a time, added to what is ticked, then taken away.
    await page.locator('[data-test="select-member-2"]').check();
    await expect(selected).toHaveText('22 ports sélectionnés');
    await page.locator('[data-test="select-member-2"]').uncheck();
    await expect(selected).toHaveText('8 ports sélectionnés');

    // The same mode and the same VLANs for the eight ports.
    await page.locator('[data-test="set-port-mode"]').click();

    const dialog = page.locator('[data-test="port-mode-dialog"]');

    await expect(dialog).toContainText('les 8 ports sélectionnés sur');
    await dialog
        .getByLabel('VLAN', { exact: true })
        .selectOption({ label: '10 — Bureautique' });
    await dialog.locator('[data-test="bulk-voice"]').check();

    const voice = dialog.locator('#bulk-voice-vlan');

    // The VLAN of the port is not offered as its voice VLAN. The VLANs of
    // the site of the device come first, those of the head office after.
    await expect(voice.locator('optgroup').first()).toHaveAttribute(
        'label',
        'Atelier',
    );
    await expect(
        voice
            .locator('optgroup[label="Atelier"] option')
            .filter({ hasText: '10 — Bureautique' }),
    ).toHaveCount(0);
    await expect(
        voice
            .locator('optgroup[label="Siège"] option')
            .filter({ hasText: '10 — Bureautique' }),
    ).toHaveCount(1);
    await voice.selectOption({ label: '30 — Téléphonie' });
    await dialog.getByRole('button', { name: 'Appliquer' }).click();

    await expect(page.getByText('8 ports modifiés.')).toBeVisible();

    for (const number of [3, 4, 5, 6, 7, 8, 9, 10]) {
        await expect(portRow(page, `1/0/${number}`)).toContainText(
            'Accès 10, voix 30',
        );
    }

    await expect(portRow(page, '1/0/2')).not.toContainText('Accès');
    await expect(portRow(page, '1/0/11')).not.toContainText('Accès');

    // What is ticked stays ticked: the same ports are disabled, then
    // enabled again.
    await expect(selected).toHaveText('8 ports sélectionnés');
    await page.locator('[data-test="disable-ports"]').click();
    await expect(page.getByText('8 ports désactivés.')).toBeVisible();
    await expect(portRow(page, '1/0/5')).toContainText('Désactivé');
    await expect(portRow(page, '1/0/2')).toContainText('Activé');
    await page.locator('[data-test="enable-ports"]').click();
    await expect(page.getByText('8 ports activés.')).toBeVisible();
    await expect(portRow(page, '1/0/5')).not.toContainText('Désactivé');

    // The window of one of them shows what it carries, and says what is
    // plugged into it.
    await page.locator('[data-test="edit-port-1/0/3"]').click();

    const port = page.locator('[data-test="port-dialog"]');

    await expect(port.getByLabel('Mode')).toHaveValue('access');
    await expect(port.locator('[data-test="port-voice"]')).toBeChecked();
    await expect(
        port.getByLabel('VLAN', { exact: true }).locator('option:checked'),
    ).toHaveText('10 — Bureautique');
    await expect(port.locator('#port-voice-vlan option:checked')).toHaveText(
        '30 — Téléphonie',
    );
    await port.getByLabel('Description').fill('Bureau 12');
    await port.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(page.getByText('Port modifié.')).toBeVisible();
    await expect(portRow(page, '1/0/3')).toContainText('Bureau 12');
    await expect(portRow(page, '1/0/3')).toContainText('Accès 10, voix 30');
});

test('an aggregate spans the two members in trunk mode, and its ports take its VLANs', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, DEVICE);

    // A port with a mode of its own, which will join the aggregate.
    await page.locator('[data-test="edit-port-1/1/1"]').click();

    const port = page.locator('[data-test="port-dialog"]');

    await port.getByLabel('Mode').selectOption({ label: 'Trunk' });
    await port.locator('[data-test="port-tag-all"]').click();
    await port.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(portRow(page, '1/1/1')).toContainText('Trunk :');

    await expect(page.locator('[data-test="device-aggregates"]')).toContainText(
        'Aucun agrégat de ports sur cet équipement.',
    );
    await page.locator('[data-test="add-aggregate"]').click();

    const dialog = page.locator('[data-test="aggregate-dialog"]');

    await dialog.getByLabel('Nom', { exact: true }).fill('Po1');
    await expect(dialog.getByLabel('Protocole')).toHaveValue('lacp');

    // One port on each member.
    await dialog.getByRole('checkbox', { name: '1/1/1', exact: true }).check();
    await dialog.getByRole('checkbox', { name: '2/1/1', exact: true }).check();

    // Said before anything is saved: the port that has a mode loses it.
    await expect(dialog.locator('[data-test="aggregate-losing"]')).toHaveText(
        "Le port 1/1/1 perdra son mode et ses VLAN propres : ceux de l'agrégat s'y appliquent.",
    );

    await dialog.getByLabel('Mode').selectOption({ label: 'Trunk' });
    await dialog
        .getByLabel('VLAN natif')
        .selectOption({ label: '99 — Administration réseau' });
    await dialog.getByRole('checkbox', { name: '10 — Bureautique' }).check();
    await dialog.getByRole('checkbox', { name: '60 — Production' }).check();
    await dialog.locator('[data-test="save-aggregate"]').click();

    await expect(page.getByText('Agrégat créé.')).toBeVisible();

    const aggregate = page.locator(
        '[data-test="aggregate-row"][data-aggregate="Po1"]',
    );

    await expect(aggregate).toContainText('LACP');
    await expect(aggregate).toContainText('Membre 1 : 1/1/1');
    await expect(aggregate).toContainText('Membre 2 : 2/1/1');
    await expect(aggregate).toContainText('Trunk, natif 99 : 10, 60');

    // In the table of ports, a port of the aggregate shows what the
    // aggregate carries, in place of VLANs of its own: the table has no
    // column for the aggregate.
    await expect(
        portRow(page, '1/1/1').locator('[data-test="port-via-aggregate"]'),
    ).toHaveText('via Po1 : Trunk, natif 99 : 10, 60');
    await expect(
        portRow(page, '2/1/1').locator('[data-test="port-via-aggregate"]'),
    ).toHaveText('via Po1 : Trunk, natif 99 : 10, 60');
    await expect(
        portRow(page, '1/1/2').locator('[data-test="port-via-aggregate"]'),
    ).toHaveCount(0);
    await expect(
        page
            .getByRole('table', { name: 'Ports', exact: true })
            .getByRole('columnheader', { name: 'Agrégat' }),
    ).toHaveCount(0);

    // Its window says so, and offers no mode.
    await page.locator('[data-test="edit-port-2/1/1"]').click();
    await expect(
        port.locator('[data-test="port-aggregate-note"]'),
    ).toContainText("Ce port fait partie de l'agrégat Po1");
    await expect(port.getByLabel('Mode')).toHaveCount(0);
    await port.getByRole('button', { name: 'Annuler' }).click();

    // A mode for several ports, one of the aggregate among them: nothing
    // is done, and the port in the way is named.
    await portRow(page, '1/1/1').getByRole('checkbox').click();
    await portRow(page, '1/1/2').getByRole('checkbox').click();
    await page.locator('[data-test="set-port-mode"]').click();

    const bulk = page.locator('[data-test="port-mode-dialog"]');

    await bulk
        .getByLabel('VLAN', { exact: true })
        .selectOption({ label: '60 — Production' });
    await bulk.getByRole('button', { name: 'Appliquer' }).click();
    await expect(
        bulk.getByText(
            "Le port 1/1/1 fait partie d'un agrégat : son mode et ses VLAN sont ceux de l'agrégat.",
        ),
    ).toBeVisible();
    await bulk.getByRole('button', { name: 'Annuler' }).click();
    await expect(portRow(page, '1/1/2')).not.toContainText('Accès');
});

test('the page of a VLAN lists the ports and the aggregates that carry it, device by device', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openVlan(page, '10.20.10.0/24', 'Bureautique');

    const section = page.locator('[data-test="vlan-ports"]');
    const device = (name: string): Locator =>
        section.locator(`[data-test="vlan-device"][data-device="${name}"]`);
    const carriers = device(DEVICE).locator('[data-test="vlan-carrier"]');

    // The eight access ports, then the aggregate, which carries it tagged.
    await expect(device(DEVICE)).toContainText('9 ports');
    await expect(carriers).toHaveCount(9);
    await expect(carriers.first()).toContainText('1/0/3');
    await expect(carriers.first()).toContainText('Non étiqueté');
    await expect(carriers.last()).toContainText('Po1');
    await expect(carriers.last()).toContainText('Agrégat');
    await expect(carriers.last()).toContainText('Étiqueté');

    // The devices of the demonstration that carry it are listed too, by
    // name: the two ends of the uplink of the quay.
    await expect(
        section.locator('[data-test="vlan-device"]').first(),
    ).toHaveAttribute('data-device', 'sw-atelier-01');
    await expect(section.locator('[data-test="vlan-device"]')).toHaveCount(3);
    await expect(device('sw-quai')).toContainText('1 port');

    // Each device leads to its page.
    await expect(
        device(DEVICE).getByRole('link', { name: DEVICE, exact: true }),
    ).toBeVisible();

    // The same ports carry the telephony as their voice VLAN.
    await openVlan(page, '10.20.30.0/24', 'Téléphonie');
    await expect(carriers).toHaveCount(8);
    await expect(carriers.first()).toContainText('1/0/3');
    await expect(carriers.first()).toContainText('Voix');

    // The native VLAN of the aggregate.
    await openVlan(page, '10.20.99.0/24', 'Administration réseau');
    await expect(carriers).toHaveCount(1);
    await expect(carriers.first()).toContainText('Po1');
    await expect(carriers.first()).toContainText('Natif');

    // A VLAN no port carries.
    await openVlan(page, '10.20.70.0/24', 'Extension atelier');
    await expect(section).toContainText('Aucun port ne porte ce VLAN.');
});

test('deleting an aggregate frees its ports, and deleting a member deletes its ports', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, DEVICE);

    await page.locator('[data-test="delete-aggregate-Po1"]').click();

    const aggregateDeletion = confirmation(page, 'Supprimer cet agrégat ?');

    await expect(aggregateDeletion).toContainText(
        'Ses 2 ports sont libérés, sans mode ni VLAN.',
    );
    await aggregateDeletion.getByRole('button', { name: 'Supprimer' }).click();
    await expect(page.getByText('Agrégat supprimé.')).toBeVisible();

    // Free, without a mode nor a VLAN: not even the one it had before it
    // joined the aggregate.
    await expect(portRow(page, '1/1/1')).not.toContainText('via Po1');
    await expect(portRow(page, '1/1/1')).not.toContainText('Trunk');
    await expect(portRow(page, '2/1/1')).not.toContainText('via Po1');

    // The confirmation gives the number of ports that go with the member.
    await page.locator('[data-test="delete-member-2"]').click();

    const memberDeletion = confirmation(page, 'Supprimer ce membre ?');

    await expect(memberDeletion).toContainText(
        `Le membre 2 de ${DEVICE} sera supprimé avec ses 14 ports.`,
    );
    await memberDeletion.getByRole('button', { name: 'Supprimer' }).click();
    await expect(
        page.getByText('Membre supprimé avec ses 14 ports.'),
    ).toBeVisible();

    await expect(memberRow(page, 2)).toHaveCount(0);
    await expect(page.locator('[data-test="port-row"]')).toHaveCount(14);
    await expect(portRow(page, '1/0/3')).toContainText('Accès 10, voix 30');
});

test('one port alone is added without the number of the port in its pattern', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openDevice(page, DEVICE);

    await page.locator('[data-test="add-ports"]').click();

    const dialog = page.locator('[data-test="port-series-dialog"]');
    const save = dialog.locator('[data-test="save-port-series"]');

    // Several ports cannot all bear the same name: the window says so
    // while the series is typed, and when a pattern can do without "{n}".
    await dialog.getByLabel('Motif').fill('MGMT');
    await dialog.getByLabel('Premier numéro').fill('1');
    await dialog.getByLabel('Dernier numéro').fill('2');
    await expect(dialog.locator('[data-test="series-hint"]')).toHaveText(
        "Pour plusieurs ports, un motif contient {n}, le numéro du port, une fois : « {m}/0/{n} » ou « port{n} ». Un port seul peut s'en passer : « MGMT ».",
    );

    // One port, its first and its last number being the same: named as
    // it is written.
    await dialog.getByLabel('Dernier numéro').fill('1');
    await expect(dialog.locator('[data-test="series-hint"]')).toHaveCount(0);
    await expect(dialog.locator('[data-test="series-preview"]')).toContainText(
        'MGMT',
    );
    await save.click();

    await expect(page.getByText('1 port ajouté.')).toBeVisible();
    await expect(page.locator('[data-test="port-row"]')).toHaveCount(15);
    await expect(portRow(page, 'MGMT')).toBeVisible();
});
