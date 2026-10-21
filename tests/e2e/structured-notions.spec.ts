// torsade/tests/e2e/structured-notions.spec.ts
import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// Two notions the interface has to make plain where they are used: the block
// of a site is the space that holds all of its subnets, not one of its
// networks; an entry of the catalogue stands for every site.

const BLOCK_OF_A_SITE =
    "Le bloc d'un site est l'enveloppe de tous ses sous-réseaux, par exemple 192.168.0.0/16 pour des VLAN en 192.168.10.0/24 et 192.168.20.0/24. Le réseau d'un LAN se déclare comme sous-réseau de son VLAN, pas comme bloc. Un site sans plan hiérarchique peut n'avoir aucun bloc.";

// Declares a subnet of a site, without a VLAN.
const createSubnet = async (
    page: Page,
    cidr: string,
    site: string,
    kind?: string,
): Promise<void> => {
    await page.goto('/subnets/create');
    await expect(
        page.getByRole('heading', { name: 'Nouveau sous-réseau' }),
    ).toBeVisible();

    if (kind) {
        await page.getByLabel('Type').selectOption({ label: kind });
    }

    await page.getByLabel('Adresse du réseau').fill(cidr);
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: site });
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByRole('heading', { name: cidr })).toBeVisible();
};

test('the form of a block says what the block of a site is, and how many subnets of the site it would hold', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // A site of its own, so that nothing here depends on the other
    // scenarios: two segments, and the public addresses of its carrier.
    await page.goto('/sites');
    await page.getByRole('button', { name: 'Nouveau site' }).click();

    const siteDialog = page.getByRole('dialog', { name: 'Nouveau site' });

    await siteDialog.getByLabel('Nom', { exact: true }).fill('Annexe');
    await siteDialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Site créé.')).toBeVisible();

    await createSubnet(page, '10.70.10.0/24', 'Annexe');
    await createSubnet(page, '10.70.30.0/24', 'Annexe');
    await createSubnet(page, '203.0.113.64/29', 'Annexe', 'Externe');

    // The page of a site without a block says what a block is.
    await page.goto('/sites');
    await page.getByRole('link', { name: 'Annexe', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Site Annexe' }),
    ).toBeVisible();

    const blocks = page.getByRole('region', { name: "Blocs d'adressage" });
    const deviations = page
        .getByRole('region', { name: 'Écarts' })
        .locator('[data-test="deviation-item"]');

    await expect(blocks).toContainText(BLOCK_OF_A_SITE);
    // Without a block, its subnets are compared with nothing.
    await expect(deviations).toHaveCount(0);

    // The form, opened from the site: the same words under the site.
    await blocks.getByRole('link', { name: 'Nouveau bloc' }).click();
    await expect(
        page.getByRole('heading', { name: 'Nouveau bloc' }),
    ).toBeVisible();

    const site = page.getByLabel('Site', { exact: true });
    const network = page.getByLabel('Adresse du réseau');
    const coverage = page.locator('[data-test="block-coverage"]');
    const none = page.locator('[data-test="block-coverage-none"]');

    await expect(site.locator('option:checked')).toHaveText('Annexe');
    await expect(page.locator('#site-help')).toContainText(BLOCK_OF_A_SITE);

    // Nothing is counted before a block is typed.
    await expect(coverage).toHaveText('');

    // One network of the site typed as its block: it would hold none of
    // its segments. The addresses of the carrier are not counted.
    await network.fill('10.70.1.0/24');
    await expect(coverage).toContainText(
        'Ce bloc contiendrait 0 des 2 sous-réseaux du site Annexe.',
    );
    await expect(none).toHaveText(
        "Aucun ne s'y trouverait : ils resteraient signalés hors des blocs du site.",
    );

    // The count follows what is typed, in the address or in the list.
    await network.fill('10.70.0.0/20');
    await expect(coverage).toHaveText(
        'Ce bloc contiendrait 1 des 2 sous-réseaux du site Annexe.',
    );
    await expect(none).toHaveCount(0);

    await page.getByLabel('Préfixe', { exact: true }).selectOption('16');
    await expect(network).toHaveValue('10.70.0.0/16');
    await expect(coverage).toHaveText(
        'Ce bloc contiendrait 2 des 2 sous-réseaux du site Annexe.',
    );

    // Neither a block of the organisation nor something that is not a block
    // is counted.
    await site.selectOption({ label: 'Organisation (aucun site)' });
    await expect(coverage).toHaveText('');
    await site.selectOption({ label: 'Annexe' });
    await expect(coverage).toContainText('2 des 2');
    await network.fill('10.70.1.7/24');
    await expect(coverage).toHaveText('');

    // The workshop has a block that holds its six segments: a second block
    // would hold none of them, and nothing would be reported for that.
    await site.selectOption({ label: 'Atelier' });
    await network.fill('10.20.1.0/24');
    await expect(coverage).toHaveText(
        'Ce bloc contiendrait 0 des 6 sous-réseaux du site Atelier.',
    );
    await expect(none).toHaveCount(0);
    await network.fill('10.20.0.0/16');
    await expect(coverage).toHaveText(
        'Ce bloc contiendrait 6 des 6 sous-réseaux du site Atelier.',
    );

    // It is told, never refused: the block that holds nothing is saved.
    await site.selectOption({ label: 'Annexe' });
    await network.fill('10.70.1.0/24');
    await expect(none).toBeVisible();
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText("Bloc d'adressage créé.")).toBeVisible();
    await expect(
        page.getByRole('heading', { name: '10.70.1.0/24' }),
    ).toBeVisible();

    // The site then reports its two segments, and each reason names the
    // block they are compared with.
    await page.goto('/sites');
    await page.getByRole('link', { name: 'Annexe', exact: true }).click();
    await expect(deviations).toHaveText([
        /10\.70\.10\.0\/24.*Hors des blocs du site \(10\.70\.1\.0\/24\)/s,
        /10\.70\.30\.0\/24.*Hors des blocs du site \(10\.70\.1\.0\/24\)/s,
    ]);

    // The block widened to the space that holds them: the form says so
    // before it is saved, and nothing is left to report.
    await blocks.getByRole('link', { name: '10.70.1.0/24' }).click();
    await page.locator('[data-test="edit-block"]').click();
    await expect(
        page.getByRole('heading', { name: 'Modifier le bloc' }),
    ).toBeVisible();
    await expect(coverage).toContainText('0 des 2');
    await expect(none).toBeVisible();

    await network.fill('10.70.0.0/16');
    await expect(coverage).toHaveText(
        'Ce bloc contiendrait 2 des 2 sous-réseaux du site Annexe.',
    );
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText("Bloc d'adressage modifié.")).toBeVisible();

    await page.goto('/sites');
    await page.getByRole('link', { name: 'Annexe', exact: true }).click();
    await expect(
        blocks.getByRole('link', { name: '10.70.0.0/16' }),
    ).toBeVisible();
    await expect(deviations).toHaveCount(0);

    // With a block, the section still says what a block is.
    await expect(blocks).toContainText(BLOCK_OF_A_SITE);
    await expect(page.locator('[data-test="site-blocks-empty"]')).toHaveCount(
        0,
    );
});

test('a number or a name already in the catalogue leads to its entry instead of a second one', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/catalog-vlans');

    // The page first says what an entry is.
    await expect(
        page.getByText(/^Une entrée vaut pour tous les sites\. Voici/),
    ).toBeVisible();

    const entries = page
        .getByRole('table', { name: 'Catalogue des VLAN' })
        .getByRole('row')
        .filter({ hasText: 'Bureautique' });

    await expect(entries).toHaveCount(1);

    await page.getByRole('button', { name: 'Nouvelle entrée' }).click();

    const dialog = page.getByRole('dialog', {
        name: 'Nouvelle entrée du catalogue',
    });
    const save = dialog.getByRole('button', { name: 'Enregistrer' });
    const link = dialog.getByRole('link', {
        name: "Ouvrir l'entrée 10 — Bureautique",
    });

    await expect(dialog).toContainText(
        'Une entrée vaut pour tous les sites : créez-la une fois, puis rattachez-y le VLAN de chaque site.',
    );

    // A second "10 Bureautique", meant for another site: the refusal says
    // what to do instead, and leads to the entry.
    await dialog.getByLabel('VID').fill('10');
    await dialog.getByLabel('Nom', { exact: true }).fill('Bureautique Atelier');
    await save.click();

    await expect(dialog).toContainText(
        "Le VLAN 10 « Bureautique » est déjà au catalogue. Une entrée vaut pour tous les sites : rattachez-y le VLAN de ce site depuis la page de l'entrée.",
    );
    await expect(link).toHaveCount(1);

    // Another number with the name of the entry: the name is refused, with
    // the same link, and the message about the number is gone.
    await dialog.getByLabel('VID').fill('11');
    await dialog.getByLabel('Nom', { exact: true }).fill('bureautique');
    await save.click();

    await expect(dialog).toContainText(
        "Le nom « Bureautique » est déjà celui de l'entrée 10.",
    );
    await expect(dialog.getByText(/est déjà au catalogue/)).toHaveCount(0);
    await expect(link).toHaveCount(1);

    // Both at once: each message has its link.
    await dialog.getByLabel('VID').fill('10');
    await save.click();
    await expect(dialog.getByText(/est déjà au catalogue/)).toBeVisible();
    await expect(
        dialog.getByText(/est déjà celui de l'entrée 10/),
    ).toBeVisible();
    await expect(link).toHaveCount(2);

    // The link opens the entry.
    await link.first().click();
    await expect(
        page.getByRole('heading', {
            name: 'Entrée 10 — Bureautique du catalogue',
        }),
    ).toBeVisible();
    await expect(dialog).toBeHidden();

    // Nothing was created.
    await page.goto('/catalog-vlans');
    await expect(entries).toHaveCount(1);
});

// The workshop has a VLAN 70 that follows no entry of the catalogue, and the
// catalogue has no entry 70. The three scenarios below follow one another.
const openExtensionVlan = async (page: Page): Promise<void> => {
    await page.goto('/vlans');
    await page
        .getByRole('link', { name: 'Extension atelier', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'VLAN 70 — Extension atelier' }),
    ).toBeVisible();
};

test('the page of a VLAN proposes the entry that has its number, once there is one', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    const attach = page.locator('[data-test="attach-to-entry"]');

    // No entry has the number 70: nothing to attach to.
    await openExtensionVlan(page);
    await expect(page.getByText('Hors catalogue')).toBeVisible();
    await expect(page.locator('[data-test="edit-vlan"]')).toBeVisible();
    await expect(attach).toHaveCount(0);

    // The entry is created, once for every site.
    await page.goto('/catalog-vlans');
    await page.getByRole('button', { name: 'Nouvelle entrée' }).click();

    const dialog = page.getByRole('dialog', {
        name: 'Nouvelle entrée du catalogue',
    });

    await dialog.getByLabel('VID').fill('70');
    await dialog.getByLabel('Nom', { exact: true }).fill('Extension');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Entrée du catalogue créée.')).toBeVisible();

    // The VLAN that bears its number now offers to follow it.
    await openExtensionVlan(page);
    await expect(attach).toHaveText('Rattacher à 70 — Extension');

    // A VLAN that already follows an entry, or whose number is not in the
    // catalogue, offers nothing.
    await page.goto('/vlans');
    await page
        .getByRole('link', { name: 'Vidéosurveillance', exact: true })
        .last()
        .click();
    await expect(
        page.getByRole('heading', { name: 'VLAN 150 — Vidéosurveillance' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="edit-vlan"]')).toBeVisible();
    await expect(attach).toHaveCount(0);

    await page.goto('/vlans');
    await page
        .getByRole('link', { name: 'Ancien réseau', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'VLAN 5 — Ancien réseau' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="edit-vlan"]')).toBeVisible();
    await expect(attach).toHaveCount(0);
});

test('a reader is not offered to attach a VLAN', async ({ page, logIn }) => {
    await logIn('reader');
    await openExtensionVlan(page);

    await expect(page.getByText('Hors catalogue')).toBeVisible();
    await expect(page.locator('[data-test="attach-to-entry"]')).toHaveCount(0);
    await expect(page.locator('[data-test="edit-vlan"]')).toHaveCount(0);
});

test('the button attaches the VLAN, and its line then shows its entry', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openExtensionVlan(page);

    const attach = page.locator('[data-test="attach-to-entry"]');

    await attach.click();

    // The page of the VLAN comes back, with its entry and what differs
    // from it; nothing is left to attach.
    await expect(page.getByText('1 VLAN rattaché.')).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'VLAN 70 — Extension atelier' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="catalog-link"]')).toHaveText(
        /70\s*— Extension/,
    );
    await expect(page.locator('[data-test="deviations"]')).toContainText(
        'Nom Extension atelier, catalogue : Extension',
    );
    await expect(attach).toHaveCount(0);

    // In the list of VLANs, its line shows its entry.
    await page.goto('/vlans');
    await expect(
        page
            .getByRole('row', { name: /Extension atelier/ })
            .getByRole('link', { name: /70\s*— Extension$/ }),
    ).toBeVisible();

    // And on the page of the entry, the line of the workshop shows the VLAN.
    await page.goto('/catalog-vlans');
    await page.getByRole('link', { name: 'Extension', exact: true }).click();
    await expect(
        page
            .getByRole('region', { name: 'Sur chaque site' })
            .getByRole('row', { name: /Atelier/ }),
    ).toContainText(/70\s*— Extension atelier/);
});
