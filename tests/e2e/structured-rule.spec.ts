// torsade/tests/e2e/structured-rule.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// The addressing rule of the organisation, on the demonstration network:
// the rule itself, the number of sites, what the forms propose, the
// standard applied to a site and the ranges of the rule applied to a
// subnet. The demonstration has the usual rule, 10.<site>.<VLAN>.0/24, and
// a site numbered 30, "Dépôt", that has no VLAN yet.
//
// The scenarios follow one another: the first one ends with the rule it
// started from.

const PROPOSED = "Proposé par la règle d'adressage";

const openSite = async (page: Page, name: string): Promise<void> => {
    await page.goto('/sites');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name: `Site ${name}` }),
    ).toBeVisible();
};

// The window that asks to confirm a deletion has no name of its own.
const confirmation = (page: Page, title: string): Locator =>
    page.getByRole('dialog').filter({ hasText: title });

const createSite = async (
    page: Page,
    name: string,
    number: string | null,
    withBlock: boolean,
): Promise<void> => {
    await page.goto('/sites');
    await page.getByRole('button', { name: 'Nouveau site' }).click();

    const dialog = page.getByRole('dialog', { name: 'Nouveau site' });

    await dialog.getByLabel('Nom', { exact: true }).fill(name);

    if (number !== null) {
        await dialog.getByLabel('Numéro').fill(number);
    }

    const box = dialog.getByRole('checkbox', { name: 'Créer ce bloc' });

    if ((await box.count()) > 0 && !withBlock) {
        await box.uncheck();
    }

    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Site créé.')).toBeVisible();
    await expect(dialog).toBeHidden();
};

test('a rule is created, and what it gives is worked out again while it is typed', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/addressing-rule');
    await expect(
        page.getByRole('heading', { name: "Règle d'adressage" }),
    ).toBeVisible();

    const summary = page.locator('[data-test="rule-summary"]');
    const application = page
        .getByRole('region', { name: 'Application de la règle' })
        .locator('[data-test="rule-application-row"]');

    // The demonstration has a rule: each of its three sites has declared
    // the block its number gives it.
    await expect(summary).toContainText(
        'Blocs de site en /16 dans 10.0.0.0/8 : numéros 0 à 255.',
    );
    await expect(application.filter({ hasText: 'Siège' })).toContainText(
        /10\s*10\.10\.0\.0\/16\s*Bloc déclaré/,
    );
    await expect(application.filter({ hasText: 'Dépôt' })).toContainText(
        /30\s*10\.30\.0\.0\/16\s*Bloc déclaré/,
    );

    // It is deleted, after a confirmation: nothing else goes with it.
    await page.getByRole('button', { name: 'Supprimer la règle' }).click();
    await confirmation(page, "Supprimer la règle d'adressage ?")
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(page.getByText("Règle d'adressage supprimée.")).toBeVisible();

    // Without a rule, the page says what one is.
    await expect(page.locator('[data-test="rule-missing"]')).toContainText(
        "Il n'y a pas de règle d'adressage.",
    );
    await expect(
        page.getByRole('region', { name: 'Application de la règle' }),
    ).toHaveCount(0);

    await page.getByRole('button', { name: 'Créer une règle' }).click();

    const siteLength = page.getByLabel('Niveau de site');
    const vlanLength = page.getByLabel('Longueur des sous-réseaux de VLAN');

    // The form starts from the block of the organisation.
    await expect(
        page.getByLabel("Bloc de l'organisation").locator('option:checked'),
    ).toContainText('10.0.0.0/8');
    await expect(siteLength).toHaveValue('16');
    await expect(vlanLength).toHaveValue('24');
    await expect(summary.getByRole('listitem')).toHaveText([
        'Blocs de site en /16 dans 10.0.0.0/8 : numéros 0 à 255.',
        'Sous-réseaux de VLAN en /24 : VID 1 à 255.',
        'Exemple : site 20, VLAN 10 → 10.20.10.0/24, passerelle 10.20.10.1.',
    ]);

    // The summary follows what is typed: larger blocks of sites, …
    await siteLength.selectOption('12');
    await expect(summary.getByRole('listitem')).toHaveText([
        'Blocs de site en /12 dans 10.0.0.0/8 : numéros 0 à 15.',
        /Sous-réseaux de VLAN en \/24 : VID 1 à 4\s094\./,
        'Exemple : site 15, VLAN 10 → 10.240.10.0/24, passerelle 10.240.10.1.',
    ]);

    // … lengths that make no rule, …
    await siteLength.selectOption('16');
    await vlanLength.fill('16');
    await expect(summary.getByRole('listitem')).toHaveCount(0);
    await expect(summary).toContainText('Les longueurs doivent croître');
    await vlanLength.fill('24');

    // … and the gateway on the last address.
    await page
        .getByLabel('Position de la passerelle')
        .selectOption({ label: 'Dernière adresse utilisable' });
    await expect(summary).toContainText(
        'Exemple : site 20, VLAN 10 → 10.20.10.0/24, passerelle 10.20.10.254.',
    );
    await page
        .getByLabel('Position de la passerelle')
        .selectOption({ label: 'Première adresse utilisable' });

    // The ranges a subnet is usually cut into. Each line shows what it
    // gives in the subnet of the example.
    const addRange = page.getByRole('button', { name: 'Ajouter une plage' });
    const range = (index: number): Locator =>
        page.locator(`[data-test="rule-range-${index}"]`);
    const example = (index: number): Locator =>
        page.locator(`[data-test="rule-range-example-${index}"]`);

    await addRange.click();
    await range(0).getByLabel('Type').selectOption({ label: 'Réservée' });
    await expect(range(0).getByLabel('Début')).toHaveValue('2');
    await range(0).getByLabel('Fin').fill('20');
    await range(0).getByLabel('Nom').fill('Infrastructure');
    await expect(example(0)).toHaveText('.2 à .20');

    // A new line starts after the one before.
    await addRange.click();
    await expect(range(1).getByLabel('Début')).toHaveValue('21');
    await range(1).getByLabel('Fin').fill('99');
    await range(1).getByLabel('Nom').fill('Adresses fixes');
    await expect(example(1)).toHaveText('.21 à .99');

    await addRange.click();
    await range(2).getByLabel('Type').selectOption({ label: 'DHCP' });
    await range(2).getByLabel('Fin').fill('300');
    await expect(example(2)).toHaveText(
        'Ne tient pas dans un sous-réseau en /24',
    );

    // The rule is checked as a whole, each refusal on its line.
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(range(2)).toContainText(
        "La fin vaut au plus 254 dans un sous-réseau en /24 : sa dernière adresse est l'adresse de diffusion.",
    );
    await expect(range(2).getByLabel('Fin')).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    await expect(page.locator('[data-test="rule-missing"]')).toHaveCount(0);

    await range(2).getByLabel('Fin').fill('200');
    await expect(example(2)).toHaveText('.100 à .200');
    await page.getByRole('button', { name: 'Enregistrer' }).click();

    // Saved: the table tells how it applies to each site.
    await expect(
        page.getByText("Règle d'adressage enregistrée."),
    ).toBeVisible();
    await expect(application.filter({ hasText: 'Atelier' })).toContainText(
        /20\s*10\.20\.0\.0\/16\s*Bloc déclaré/,
    );

    // A reader reads the rule and cannot change it.
    await page.reload();
    await expect(range(2).getByLabel('Fin')).toHaveValue('200');
});

test('a reader reads the rule and how it applies', async ({ page, logIn }) => {
    await logIn('reader');

    // Through the menu, in the group of the standard.
    const menu = page.getByRole('navigation', {
        name: 'Navigation principale',
    });

    await menu.getByRole('button', { name: 'Norme', exact: true }).click();
    await menu
        .getByRole('link', { name: "Règle d'adressage", exact: true })
        .click();

    await expect(page.locator('[data-test="rule-read"]')).toContainText(
        'Exemple : site 20, VLAN 10 → 10.20.10.0/24, passerelle 10.20.10.1.',
    );
    await expect(page.locator('[data-test="rule-read"]')).toContainText(
        /Réservée\s*2 –\s*20\s*Infrastructure\s*\.2 à \.20/,
    );
    await expect(page.locator('[data-test="rule-form"]')).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Supprimer la règle' }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('region', { name: 'Application de la règle' }),
    ).toContainText('Bloc déclaré');
});

test('a number typed in the window of a site shows the block the rule expects, which is created with the site', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/sites');
    await page.getByRole('button', { name: 'Nouveau site' }).click();

    const dialog = page.getByRole('dialog', { name: 'Nouveau site' });
    const expected = dialog.locator('[data-test="site-expected-block"]');
    const box = dialog.getByRole('checkbox', { name: 'Créer ce bloc' });

    await dialog.getByLabel('Nom', { exact: true }).fill('Agence');

    // Without a number, nothing is expected.
    await expect(expected).toHaveText('');
    await expect(box).toHaveCount(0);

    // A number the rule reaches: its block, and the box to create it.
    await dialog.getByLabel('Numéro').fill('40');
    await expect(expected).toHaveText(
        /Bloc attendu par la règle :\s*10\.40\.0\.0\/16/,
    );
    await expect(box).toBeChecked();

    // A number beyond the rule is said so, and stays accepted.
    await dialog.getByLabel('Numéro').fill('300');
    await expect(expected).toContainText(
        "La règle permet les numéros 0 à 255 : aucun bloc n'est attendu pour celui-ci, et le site sera signalé comme un écart.",
    );
    await expect(box).toHaveCount(0);

    await dialog.getByLabel('Numéro').fill('40');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Site créé.')).toBeVisible();

    // The list shows the number; the site has its block, and nothing
    // differs from the rule.
    await expect(page.getByRole('row', { name: /Agence/ })).toContainText('40');
    await page.getByRole('link', { name: 'Agence', exact: true }).click();
    await expect(page.locator('[data-test="site-number"]')).toHaveText(
        /N°\s*40/,
    );
    await expect(
        page
            .getByRole('region', { name: "Blocs d'adressage" })
            .getByRole('link', { name: '10.40.0.0/16' }),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="site-rule-deviations"]'),
    ).toHaveCount(0);

    // Its window now says the block is declared, and offers nothing.
    await page.locator('[data-test="edit-site"]').click();

    const edition = page.getByRole('dialog', { name: 'Modifier le site' });

    await expect(
        edition.locator('[data-test="site-expected-block"]'),
    ).toContainText('déjà déclaré pour ce site.');
    await expect(
        edition.getByRole('checkbox', { name: 'Créer ce bloc' }),
    ).toHaveCount(0);
    await edition.getByRole('button', { name: 'Annuler' }).click();
});

test('the table of the rule tells the state of each site, and creates a block that is missing', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // A site numbered without its block, one beyond the rule, one without
    // a number.
    await createSite(page, 'Relais', '41', false);
    await createSite(page, 'Lointain', '300', false);
    await createSite(page, 'Passage', null, false);

    await page.goto('/addressing-rule');

    const rows = page
        .getByRole('region', { name: 'Application de la règle' })
        .locator('[data-test="rule-application-row"]');
    const row = (name: string): Locator => rows.filter({ hasText: name });

    await expect(row('Siège')).toHaveAttribute('data-state', 'declared');
    await expect(row('Relais')).toHaveAttribute('data-state', 'missing');
    await expect(row('Relais')).toContainText(
        /41\s*10\.41\.0\.0\/16\s*Écart\s*:\s*Bloc attendu non déclaré/,
    );
    await expect(row('Lointain')).toHaveAttribute('data-state', 'outside');
    await expect(row('Lointain')).toContainText(
        /300\s*—\s*Écart\s*:\s*Numéro hors des limites de la règle \(0 à 255\)/,
    );
    await expect(row('Passage')).toHaveAttribute('data-state', 'unnumbered');
    await expect(row('Passage')).toContainText('Sans numéro');

    // The numbered sites come first, by number.
    await expect(rows.nth(0)).toContainText('Siège');
    await expect(rows.nth(1)).toContainText('Atelier');

    // The site itself says what differs, on its page.
    await openSite(page, 'Lointain');
    await expect(
        page.locator('[data-test="site-rule-deviations"]'),
    ).toContainText('Numéro 300 : la règle en permet de 0 à 255');

    // The block that is missing is created from the table.
    await page.goto('/addressing-rule');
    await row('Relais').getByRole('button', { name: 'Créer ce bloc' }).click();
    await expect(page.getByText('Bloc 10.41.0.0/16 créé.')).toBeVisible();
    await expect(row('Relais')).toHaveAttribute('data-state', 'declared');
    await expect(
        row('Relais').getByRole('link', { name: 'Bloc déclaré' }),
    ).toBeVisible();
    await expect(
        rows.getByRole('button', { name: 'Créer ce bloc' }),
    ).toHaveCount(0);
});

test('a rule without a level of sites proposes the same subnets to every site and expects no block', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/addressing-rule');

    const summary = page.locator('[data-test="rule-summary"]');
    const siteLength = page.getByLabel('Niveau de site');
    const region = page.getByRole('region', {
        name: 'Application de la règle',
    });

    // The levels the block leaves, and none at all, which the form says
    // suits a single site.
    await expect(siteLength.locator('option')).toHaveText([
        'Aucun (un seul site)',
        ...Array.from(
            { length: 15 },
            (_, index) => `Blocs de site en /${index + 9}`,
        ),
    ]);
    await expect(page.locator('#rule-site-length-help')).toHaveText(
        'Sans niveau de site, tous les sites attendent les mêmes sous-réseaux : ce choix convient à une installation à un seul site.',
    );

    await siteLength.selectOption({ label: 'Aucun (un seul site)' });
    await expect(summary.getByRole('listitem')).toHaveText([
        'Aucun niveau de site.',
        /Sous-réseaux de VLAN en \/24 dans 10\.0\.0\.0\/8 : VID 1 à 4\s094\./,
        'Exemple : VLAN 20 → 10.0.20.0/24, passerelle 10.0.20.1.',
    ]);

    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        page.getByText("Règle d'adressage enregistrée."),
    ).toBeVisible();

    // No block of a site is expected any more.
    await expect(region).toContainText("La règle n'attend aucun bloc de site.");
    await expect(
        region.locator('[data-test="rule-application-row"]'),
    ).toHaveCount(0);

    // The form of a VLAN proposes the same subnet whatever the site,
    // numbered or not.
    await page.goto('/vlans/create');

    const site = page.getByLabel('Site', { exact: true });
    const network = page.getByLabel('Adresse du réseau');
    const networkHint = page.locator('[data-test="network-hint"]');

    await site.selectOption({ label: 'Siège' });
    await page.getByLabel('VID').fill('20');
    await page.getByLabel('Créer le sous-réseau de ce VLAN maintenant').check();
    await expect(network).toHaveValue('10.0.20.0');
    await expect(page.getByLabel('Passerelle')).toHaveValue('10.0.20.1');
    await expect(networkHint).toHaveText(PROPOSED);

    await site.selectOption({ label: 'Passage' });
    await expect(network).toHaveValue('10.0.20.0');
    await expect(networkHint).toHaveText(PROPOSED);

    // The window of a site expects no block, whatever its number.
    await page.goto('/sites');
    await page.getByRole('button', { name: 'Nouveau site' }).click();

    const dialog = page.getByRole('dialog', { name: 'Nouveau site' });

    for (const number of ['40', '300']) {
        await dialog.getByLabel('Numéro').fill(number);
        await expect(
            dialog.locator('[data-test="site-expected-block"]'),
        ).toHaveText('');
    }

    await expect(
        dialog.getByRole('checkbox', { name: 'Créer ce bloc' }),
    ).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Annuler' }).click();

    // The rule of the demonstration is set back.
    await page.goto('/addressing-rule');
    await siteLength.selectOption({ label: 'Blocs de site en /16' });
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        page.getByText("Règle d'adressage enregistrée."),
    ).toBeVisible();
    await expect(summary.getByRole('listitem').first()).toHaveText(
        'Blocs de site en /16 dans 10.0.0.0/8 : numéros 0 à 255.',
    );
    await expect(
        region.locator('[data-test="rule-application-row"]').first(),
    ).toContainText('Bloc déclaré');
});

test('the form of a VLAN proposes the subnet the rule expects, and follows the number of the VLAN', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/vlans/create');
    await expect(
        page.getByRole('heading', { name: 'Nouveau VLAN' }),
    ).toBeVisible();

    const site = page.getByLabel('Site', { exact: true });
    const vid = page.getByLabel('VID');
    const network = page.getByLabel('Adresse du réseau');
    const prefix = page.getByLabel('Préfixe', { exact: true });
    const gateway = page.getByLabel('Passerelle');
    const networkHint = page.locator('[data-test="network-hint"]');
    const gatewayHint = page.locator('[data-test="gateway-hint"]');

    await site.selectOption({ label: 'Dépôt' });
    await vid.fill('10');
    await page.getByLabel('Nom', { exact: true }).fill('Essai de proposition');
    await page.getByLabel('Créer le sous-réseau de ce VLAN maintenant').check();

    // The site 30 and the VLAN 10: 10.30.10.0/24, gateway on the first
    // address, each said to be proposed.
    await expect(network).toHaveValue('10.30.10.0');
    await expect(prefix).toHaveValue('24');
    await expect(gateway).toHaveValue('10.30.10.1');
    await expect(networkHint).toHaveText(PROPOSED);
    await expect(gatewayHint).toHaveText(PROPOSED);
    await expect(page.getByLabel('Appliquer le découpage type')).toBeChecked();

    // The proposal follows the number of the VLAN, …
    await vid.fill('21');
    await expect(network).toHaveValue('10.30.21.0');
    await expect(gateway).toHaveValue('10.30.21.1');

    // … says why there is none for a number the rule does not cover, …
    await vid.fill('300');
    await expect(network).toHaveValue('');
    await expect(gateway).toHaveValue('');
    await expect(networkHint).toHaveText('La règle ne couvre pas le VID 300');
    await expect(gatewayHint).toHaveCount(0);

    // … or for a site that has no number, …
    await vid.fill('21');
    await expect(network).toHaveValue('10.30.21.0');
    await site.selectOption({ label: 'Passage' });
    await expect(network).toHaveValue('');
    await expect(networkHint).toHaveText(
        "Le site Passage n'a pas de numéro : la règle ne propose rien",
    );

    // … and comes back with a site and a number it covers.
    await site.selectOption({ label: 'Dépôt' });
    await expect(network).toHaveValue('10.30.21.0');
    await expect(gateway).toHaveValue('10.30.21.1');

    // A value changed by hand is no longer said to be proposed, and is
    // left alone when the number changes again.
    await gateway.fill('10.30.22.254');
    await expect(gatewayHint).toHaveCount(0);
    await expect(networkHint).toHaveText(PROPOSED);
    await vid.fill('22');
    await expect(network).toHaveValue('10.30.22.0');
    await expect(gateway).toHaveValue('10.30.22.254');

    // Saved with the ranges of the rule: only the box is sent, and the
    // server lays them out in the subnet it has saved.
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('VLAN créé.')).toBeVisible();
    await page.getByRole('link', { name: '10.30.22.0/24' }).click();
    await expect(
        page.getByRole('heading', { name: '10.30.22.0/24' }),
    ).toBeVisible();
    await expect(
        page.getByRole('region', { name: 'Plages' }).getByRole('listitem'),
    ).toHaveText([
        /Réservée.*Infrastructure.*10\.30\.22\.2 – 10\.30\.22\.20/s,
        /Fixe.*Adresses fixes.*10\.30\.22\.21 – 10\.30\.22\.99/s,
        /DHCP.*10\.30\.22\.100 – 10\.30\.22\.200/s,
    ]);
});

test('the form of a subnet proposes the same for the VLAN that is chosen', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // A VLAN of the site 30 without a subnet.
    await page.goto('/vlans/create');
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Dépôt' });
    await page.getByLabel('VID').fill('23');
    await page.getByLabel('Nom', { exact: true }).fill('Essai sans réseau');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('VLAN créé.')).toBeVisible();

    // From its page, the form of a subnet opens with the VLAN chosen.
    await page.getByRole('link', { name: 'Rattacher un sous-réseau' }).click();
    await expect(
        page.getByRole('heading', { name: 'Nouveau sous-réseau' }),
    ).toBeVisible();

    const network = page.getByLabel('Adresse du réseau');
    const gateway = page.getByLabel('Passerelle');
    const vlan = page.getByLabel('VLAN', { exact: true });
    const box = page.getByLabel('Appliquer le découpage type');

    await expect(network).toHaveValue('10.30.23.0');
    await expect(page.getByLabel('Préfixe', { exact: true })).toHaveValue('24');
    await expect(gateway).toHaveValue('10.30.23.1');
    await expect(page.locator('[data-test="network-hint"]')).toHaveText(
        PROPOSED,
    );
    await expect(page.locator('[data-test="gateway-hint"]')).toHaveText(
        PROPOSED,
    );
    await expect(box).toBeChecked();

    // Without a VLAN, nothing is proposed and the ranges are not offered.
    await vlan.selectOption({ label: 'Aucun VLAN' });
    await expect(network).toHaveValue('');
    await expect(gateway).toHaveValue('');
    await expect(page.locator('[data-test="network-hint"]')).toHaveCount(0);
    await expect(box).toHaveCount(0);

    // With the VLAN again, the proposal is back.
    await vlan.selectOption({ label: '23 — Essai sans réseau' });
    await expect(network).toHaveValue('10.30.23.0');

    // A subnet too small for the ranges of the rule is refused on the box,
    // and nothing is saved.
    await network.fill('10.30.23.0/27');
    await expect(page.locator('[data-test="network-hint"]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        page.getByText(
            'Le découpage type ne tient pas dans 10.30.23.0/27 : la plage 10.30.23.21 – 10.30.23.99 dépasse ses adresses utilisables (10.30.23.1 – 10.30.23.30).',
        ),
    ).toBeVisible();
    await expect(box).toHaveAttribute('aria-invalid', 'true');

    await network.fill('10.30.23.0/24');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        page.getByRole('heading', { name: '10.30.23.0/24' }),
    ).toBeVisible();
    await expect(
        page.getByRole('region', { name: 'Plages' }).getByRole('listitem'),
    ).toHaveCount(3);
});

test('the standard is applied to a site: a line in the way refuses everything, then the rest is created at once', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // A VLAN numbered 40 on the site, outside the catalogue: the entry 40
    // cannot be applied over it.
    await page.goto('/vlans/create');
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Dépôt' });
    await page.getByLabel('VID').fill('40');
    await page.getByLabel('Nom', { exact: true }).fill('Wi-Fi visiteurs');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('VLAN créé.')).toBeVisible();

    await openSite(page, 'Dépôt');
    await page.getByRole('link', { name: 'Appliquer la norme' }).click();
    await expect(
        page.getByRole('heading', { name: 'Appliquer la norme au site Dépôt' }),
    ).toBeVisible();

    const line = (vid: number): Locator =>
        page.locator(`[data-test="standard-line-${vid}"]`);
    const submit = page.locator('[data-test="apply-standard"]');

    // One line per entry of the catalogue, all ticked, with the subnet the
    // rule expects for the site 30; one status for all, "planned". The
    // demonstration has six entries; other scenarios may have added some.
    const lines = page.locator('[data-test^="standard-line-"]');

    await expect(line(99)).toBeVisible();

    const total = await lines.count();

    expect(total).toBeGreaterThanOrEqual(6);
    await expect(page.getByLabel('Statut de ce qui est créé')).toHaveValue(
        'planned',
    );
    await expect(
        line(10).getByLabel('Sous-réseau', { exact: true }),
    ).toHaveValue('10.30.10.0/24');
    await expect(line(10).getByLabel('Passerelle')).toHaveValue('10.30.10.1');
    await expect(line(10).locator('[data-test="network-hint"]')).toHaveText(
        PROPOSED,
    );
    await expect(
        line(10).getByRole('checkbox', { name: 'Créer le sous-réseau' }),
    ).toBeChecked();
    await expect(
        line(10).getByRole('checkbox', { name: 'Découpage type' }),
    ).toBeChecked();
    await expect(submit).toHaveText(`Créer les ${total} VLAN cochés`);

    // Four entries are kept, and one of them gets another number: its
    // subnet follows.
    await page.getByRole('button', { name: 'Tout décocher' }).click();
    await expect(submit).toBeDisabled();

    for (const vid of [10, 20, 40, 50]) {
        await line(vid).getByRole('checkbox').first().check();
    }

    await line(20).getByLabel('VID').fill('120');
    await expect(
        line(20).getByLabel('Sous-réseau', { exact: true }),
    ).toHaveValue('10.30.120.0/24');
    await expect(line(20).getByLabel('Passerelle')).toHaveValue('10.30.120.1');
    await expect(submit).toHaveText('Créer les 4 VLAN cochés');

    await submit.click();

    // The entry 40 is refused on its line, and nothing is created.
    await expect(line(40)).toContainText(
        'Le VLAN 40 « Wi-Fi visiteurs » existe déjà sur ce site, hors catalogue : rattachez-le depuis sa page.',
    );
    await expect(line(40).getByLabel('VID')).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    await expect(line(10).getByLabel('VID')).not.toHaveAttribute(
        'aria-invalid',
        'true',
    );
    await expect(
        page.getByRole('heading', { name: 'Appliquer la norme au site Dépôt' }),
    ).toBeVisible();

    // Without that line, the three others are created together.
    await line(40).getByRole('checkbox').first().uncheck();
    await expect(submit).toHaveText('Créer les 3 VLAN cochés');
    await submit.click();

    await expect(
        page.getByText('3 VLAN créés, 3 sous-réseaux créés.'),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'Site Dépôt' }),
    ).toBeVisible();

    // The VLANs are attached to their entries, planned like their subnets.
    const vlans = page.locator('[data-test="site-vlan-row"]');

    await expect(vlans.filter({ hasText: 'Bureautique' })).toContainText(
        /10.*Bureautique.*10\.30\.10\.0\/24.*Prévu/s,
    );
    await expect(vlans.filter({ hasText: 'Vidéosurveillance' })).toContainText(
        '10.30.50.0/24',
    );
    // The entry created with another number is shown as a deviation.
    await expect(vlans.filter({ hasText: 'Serveurs' })).toContainText(
        'VID 120, catalogue : 20',
    );

    // Their subnets were cut into the ranges of the rule.
    await page.getByRole('link', { name: '10.30.10.0/24' }).first().click();
    await expect(
        page.getByRole('heading', { name: '10.30.10.0/24' }),
    ).toBeVisible();
    await expect(
        page.getByRole('region', { name: 'Plages' }).getByRole('listitem'),
    ).toHaveCount(3);

    // What is left to apply: the two entries left out, and the one in
    // the way.
    await openSite(page, 'Dépôt');
    await page.getByRole('link', { name: 'Appliquer la norme' }).click();
    await expect(lines).toHaveCount(total - 3);
    await expect(line(40)).toBeVisible();
    await expect(line(10)).toHaveCount(0);
});

test('a reader is not offered to apply the standard', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openSite(page, 'Dépôt');

    await expect(page.locator('[data-test="apply-standard"]')).toHaveCount(0);
    await expect(page.locator('[data-test="edit-site"]')).toHaveCount(0);
});

test('the ranges of the rule are applied to an existing subnet, all of them or none', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    const createSubnet = async (
        cidr: string,
        gatewayAddress: string,
    ): Promise<void> => {
        await page.goto('/subnets/create');
        await page.getByLabel('Adresse du réseau').fill(cidr);
        await page.getByLabel('Passerelle').fill(gatewayAddress);
        await page.getByRole('button', { name: 'Enregistrer' }).click();
        await expect(page.getByRole('heading', { name: cidr })).toBeVisible();
    };

    const ranges = page.getByRole('region', { name: 'Plages' });
    const apply = ranges.getByRole('button', {
        name: 'Appliquer le découpage type',
    });
    const dialog = page.getByRole('dialog', {
        name: 'Appliquer le découpage type',
    });

    // A subnet whose gateway lies where the DHCP range would be.
    await createSubnet('10.30.201.0/24', '10.30.201.150');
    await expect(ranges).toContainText('Aucune plage dans ce sous-réseau.');
    await apply.click();

    // The window shows the ranges that would be created.
    await expect(dialog.getByRole('listitem')).toHaveText([
        /Réservée.*Infrastructure.*10\.30\.201\.2 – 10\.30\.201\.20.*19 adresses/s,
        /Fixe.*Adresses fixes.*10\.30\.201\.21 – 10\.30\.201\.99.*79 adresses/s,
        /DHCP.*10\.30\.201\.100 – 10\.30\.201\.200.*101 adresses/s,
    ]);
    await dialog.getByRole('button', { name: 'Créer ces 3 plages' }).click();

    // The rules of ranges refuse the DHCP range: none of the three is kept.
    await expect(dialog).toContainText(
        'La plage 10.30.201.100 – 10.30.201.200 ne peut pas être créée. Une plage DHCP ne peut pas contenir la passerelle (10.30.201.150).',
    );
    await dialog.getByRole('button', { name: 'Annuler' }).click();
    await page.reload();
    await expect(ranges).toContainText('Aucune plage dans ce sous-réseau.');

    // A subnet where they fit: the three ranges are created at once.
    await createSubnet('10.30.200.0/24', '10.30.200.1');
    await apply.click();
    await dialog.getByRole('button', { name: 'Créer ces 3 plages' }).click();
    await expect(page.getByText('3 plages créées.')).toBeVisible();
    await expect(dialog).toBeHidden();
    await expect(ranges.getByRole('listitem')).toHaveText([
        /Réservée.*Infrastructure.*10\.30\.200\.2 – 10\.30\.200\.20/s,
        /Fixe.*Adresses fixes.*10\.30\.200\.21 – 10\.30\.200\.99/s,
        /DHCP.*10\.30\.200\.100 – 10\.30\.200\.200/s,
    ]);

    // Once the subnet has ranges, the action is no longer offered.
    await expect(apply).toHaveCount(0);

    // Nor is it for a subnet too small, where the window says why.
    await createSubnet('10.30.202.0/27', '10.30.202.1');
    await apply.click();
    await expect(dialog).toContainText(
        'Le découpage ne tient pas dans ce sous-réseau (10.30.202.1 – 10.30.202.30) : il ne peut pas y être appliqué.',
    );
    await expect(
        dialog.getByRole('button', { name: 'Créer ces 3 plages' }),
    ).toBeDisabled();
});
