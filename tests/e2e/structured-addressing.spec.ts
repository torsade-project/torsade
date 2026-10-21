// torsade/tests/e2e/structured-addressing.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, openSubnet, test } from './support/fixtures';

// The structure of the addressing, on the demonstration network: the VLAN
// catalogue, the address blocks, the kinds of subnets, the kinds of ranges,
// and what differs from the standard of the organisation.

const deviationsOf = (scope: Locator): Locator =>
    scope.locator('[data-test="deviations"]');

// Opens the page of an object from its list, by the link that names it.
const openFromList = async (
    page: Page,
    list: string,
    link: string,
): Promise<void> => {
    await page.goto(list);
    await page.getByRole('link', { name: link, exact: true }).first().click();
};

test('an entry of the catalogue is created, and the VLANs that bear its number are attached to it at once', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/catalog-vlans');

    // The table: one line per entry, one column per site.
    const table = page.getByRole('table', { name: 'Catalogue des VLAN' });

    // The two sites of the demonstration each have their column; other
    // scenarios may have added sites.
    const headers = table.getByRole('columnheader');

    await expect(
        headers.filter({ hasText: /^\s*(Atelier|Siège)\s*$/ }),
    ).toHaveText(['Atelier', 'Siège']);

    const columns = (await headers.allTextContents()).map((name) =>
        name.trim(),
    );
    const workshop = columns.indexOf('Atelier');
    const office = columns.indexOf('Siège');

    // The cells of a line, in the order of the columns.
    const cellsOf = (name: string): Locator =>
        table.getByRole('row', { name: new RegExp(name) }).locator('td, th');

    // Followed on both sites, as the catalogue says.
    await expect(cellsOf('Bureautique').nth(workshop)).toHaveText('10');
    await expect(cellsOf('Bureautique').nth(office)).toHaveText('10');
    // Followed on one site only: a dash on the other.
    await expect(cellsOf('Serveurs').nth(workshop)).toContainText('—');
    await expect(cellsOf('Serveurs').nth(workshop)).toContainText('Aucun VLAN');
    await expect(cellsOf('Serveurs').nth(office)).toHaveText('20');
    // Followed with another number: the cell shows the real number and
    // tells the deviation.
    await expect(cellsOf('Vidéosurveillance').nth(office)).toHaveText('50');
    await expect(cellsOf('Vidéosurveillance').nth(workshop)).toContainText(
        '150',
    );
    await expect(
        deviationsOf(cellsOf('Vidéosurveillance').nth(workshop)),
    ).toHaveText(/Écart\s*:\s*VID 150, catalogue : 50/);
    await expect(deviationsOf(table)).toHaveCount(1);

    // Both sites have a telephony VLAN numbered 30, and the catalogue has
    // no entry for it yet.
    await expect(table.getByRole('row', { name: /Téléphonie/ })).toHaveCount(0);

    await page.getByRole('button', { name: 'Nouvelle entrée' }).click();

    const dialog = page.getByRole('dialog', {
        name: 'Nouvelle entrée du catalogue',
    });

    await dialog.getByLabel('VID').fill('30');
    await dialog.getByLabel('Nom', { exact: true }).fill('Téléphonie');
    await dialog.getByLabel('Description').fill('Postes téléphoniques.');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();

    // The page of the new entry: no VLAN follows it yet, and the two VLANs
    // that bear its number are proposed.
    await expect(page.getByText('Entrée du catalogue créée.')).toBeVisible();
    await expect(
        page.getByRole('heading', {
            name: 'Entrée 30 — Téléphonie du catalogue',
        }),
    ).toBeVisible();

    const onSites = page.getByRole('region', { name: 'Sur chaque site' });

    for (const site of [/Atelier/, /Siège/]) {
        await expect(onSites.getByRole('row', { name: site })).toContainText(
            'Aucun VLAN ne suit cette entrée sur ce site.',
        );
    }

    const attach = page.getByRole('region', {
        name: 'Rattacher des VLAN existants',
    });
    const candidates = attach.getByRole('checkbox');
    const submit = attach.getByRole('button', {
        name: 'Rattacher la sélection',
    });

    await expect(candidates).toHaveCount(2);
    await expect(attach.getByRole('listitem')).toHaveText([
        /Atelier.*30.*Téléphonie/s,
        /Siège.*30.*Téléphonie/s,
    ]);
    // Nothing is ticked: nothing to attach yet.
    await expect(submit).toBeDisabled();

    await attach.getByRole('button', { name: 'Tout cocher' }).click();
    await expect(candidates.nth(0)).toBeChecked();
    await expect(candidates.nth(1)).toBeChecked();
    await submit.click();

    // Both are attached in one action.
    await expect(page.getByText('2 VLAN rattachés.')).toBeVisible();
    await expect(
        attach.getByText('Aucun VLAN non rattaché ne porte le numéro 30.'),
    ).toBeVisible();
    await expect(onSites.getByRole('row', { name: /Atelier/ })).toContainText(
        /30\s*— Téléphonie/,
    );
    await expect(onSites.getByRole('row', { name: /Siège/ })).toContainText(
        /30\s*— Téléphonie/,
    );

    // And the table of the catalogue shows them.
    await page.goto('/catalog-vlans');
    await expect(cellsOf('Téléphonie').nth(workshop)).toHaveText('30');
    await expect(cellsOf('Téléphonie').nth(office)).toHaveText('30');
});

test('a VLAN whose site already follows the entry is refused on its own line', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // The workshop follows the entry 50 with its VLAN 150. A second VLAN
    // of the workshop, numbered 50, is then proposed by the entry.
    await page.goto('/vlans/create');
    await expect(
        page.getByRole('heading', { name: 'Nouveau VLAN' }),
    ).toBeVisible();
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Atelier' });
    await page.getByLabel('VID').fill('50');
    await page.getByLabel('Nom', { exact: true }).fill('Caméras du quai');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('VLAN créé.')).toBeVisible();

    await openFromList(page, '/catalog-vlans', 'Vidéosurveillance');
    await expect(
        page.getByRole('heading', { name: /Entrée 50 — Vidéosurveillance/ }),
    ).toBeVisible();

    const attach = page.getByRole('region', {
        name: 'Rattacher des VLAN existants',
    });
    const line = attach.getByRole('listitem').filter({ hasText: 'Atelier' });

    await expect(line).toContainText('Caméras du quai');
    await line.getByRole('checkbox').check();
    await attach
        .getByRole('button', { name: 'Rattacher la sélection' })
        .click();

    // The refusal is told on the line of the VLAN, and nothing is attached.
    await expect(line).toContainText(
        'Le site Atelier a déjà un VLAN rattaché à cette entrée (150 — Vidéosurveillance).',
    );
    await expect(line.getByRole('checkbox')).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    await expect(page.getByText(/VLAN rattaché/)).toHaveCount(1);

    await page.reload();
    await expect(
        page
            .getByRole('region', { name: 'Rattacher des VLAN existants' })
            .getByRole('checkbox'),
    ).toHaveCount(1);
});

test('a block is created, and its page shows what is left of it', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/address-blocks');

    // The blocks are nested by their addresses: the block of the
    // organisation, then the blocks of the three sites it holds.
    const rows = page.getByRole('row').filter({
        has: page.getByRole('link', { name: /^10\.\d+\.0\.0\/\d+$/ }),
    });

    await expect(rows).toHaveText([
        /10\.0\.0\.0\/8.*Adressage interne.*Organisation/s,
        /10\.10\.0\.0\/16.*Siège/s,
        /10\.20\.0\.0\/16.*Atelier/s,
        /10\.30\.0\.0\/16.*Dépôt.*Prévu/s,
    ]);
    await expect(rows.nth(0)).toHaveAttribute('data-depth', '0');
    await expect(rows.nth(1)).toHaveAttribute('data-depth', '1');
    await expect(rows.nth(2)).toHaveAttribute('data-depth', '1');

    await page.getByRole('link', { name: 'Nouveau bloc' }).click();
    await expect(
        page.getByRole('heading', { name: 'Nouveau bloc' }),
    ).toBeVisible();

    // An address typed with host bits is refused, with the block that was
    // probably meant.
    await page.getByLabel('Adresse du réseau').fill('10.60.0.7/16');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        page.getByText(
            "10.60.0.7/16 n'est pas une adresse de réseau. Vouliez-vous dire 10.60.0.0/16 ?",
        ),
    ).toBeVisible();

    // A block of a site over the block of another site is refused, and the
    // message names the block in the way.
    await page.getByLabel('Adresse du réseau').fill('10.10.64.0/18');
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Atelier' });
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        page.getByText('Ce bloc chevauche le bloc 10.10.0.0/16 du site Siège.'),
    ).toBeVisible();

    await page.getByLabel('Adresse du réseau').fill('10.60.0.0/16');
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Organisation (aucun site)' });
    await page.getByLabel('Nom', { exact: true }).fill('Futur site');
    await page.getByRole('button', { name: 'Enregistrer' }).click();

    // The page of the block: nothing in it yet, free as a whole.
    await expect(page.getByText("Bloc d'adressage créé.")).toBeVisible();
    await expect(
        page.getByRole('heading', { name: '10.60.0.0/16' }),
    ).toBeVisible();
    await expect(
        page.getByText("Bloc de l'organisation, Futur site"),
    ).toBeVisible();

    const free = page.getByRole('region', { name: 'Espace libre' });
    const prefixes = free.getByRole('listitem');

    await expect(prefixes).toHaveText([/10\.60\.0\.0\/16.*65\s536 adresses/s]);
    await expect(
        page
            .getByText('Aucun sous-réseau dans ce bloc pour l’instant.')
            .or(
                page.getByText(
                    "Aucun sous-réseau dans ce bloc pour l'instant.",
                ),
            ),
    ).toBeVisible();

    // A subnet is declared in the middle of the block.
    await page.goto('/subnets/create');
    await page.getByLabel('Adresse du réseau').fill('10.60.4.0/24');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        page.getByRole('heading', { name: '10.60.4.0/24' }),
    ).toBeVisible();

    await openFromList(page, '/address-blocks', '10.60.0.0/16');

    // It is listed, counted, and what is left is cut around it into the
    // largest prefixes that fit.
    await expect(
        page
            .getByRole('region', { name: 'Sous-réseaux' })
            .getByRole('link', { name: '10.60.4.0/24' }),
    ).toBeVisible();
    await expect(page.getByText(/256 sur 65\s536/)).toBeVisible();
    await expect(prefixes).toHaveText([
        /10\.60\.0\.0\/22.*1\s024 adresses/s,
        /10\.60\.5\.0\/24.*256 adresses/s,
        /10\.60\.6\.0\/23/,
        /10\.60\.8\.0\/21/,
        /10\.60\.16\.0\/20/,
        /10\.60\.32\.0\/19/,
        /10\.60\.64\.0\/18/,
        /10\.60\.128\.0\/17/,
    ]);
    await expect(page.getByText('en 8 préfixes')).toBeVisible();

    // In the list, the new block is under the block of the organisation.
    await page.goto('/address-blocks');
    await expect(
        page.getByRole('row', { name: /10\.60\.0\.0\/16/ }),
    ).toHaveAttribute('data-depth', '1');
});

test('a point-to-point subnet has neither gateway nor ranges, in its form and on its page', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/subnets/create');
    await expect(
        page.getByRole('heading', { name: 'Nouveau sous-réseau' }),
    ).toBeVisible();

    const gateway = page.getByLabel('Passerelle');
    const vlan = page.getByLabel('VLAN', { exact: true });
    const prefix = page.getByLabel('Préfixe', { exact: true });

    // A segment, the kind a subnet starts with, has everything.
    await expect(page.getByLabel('Type')).toHaveValue('segment');
    await expect(gateway).toBeVisible();
    await expect(vlan).toBeVisible();

    // A loopback is a /32, carried by no VLAN, without a gateway.
    await page.getByLabel('Type').selectOption({ label: 'Loopback' });
    await expect(gateway).toBeHidden();
    await expect(vlan).toBeHidden();
    await expect(prefix).toHaveValue('32');

    // A point-to-point link is a /30 or a /31, without a gateway; a VLAN
    // may carry it.
    await page.getByLabel('Type').selectOption({ label: 'Point à point' });
    await expect(gateway).toBeHidden();
    await expect(vlan).toBeVisible();
    await expect(prefix).toHaveValue('31');

    await page.getByLabel('Adresse du réseau').fill('10.255.0.8/31');
    await page.getByLabel('Nom', { exact: true }).fill('Liaison de secours');
    await page.getByRole('button', { name: 'Enregistrer' }).click();

    // Its page: its kind under its address, no gateway among the facts,
    // and no section for ranges.
    await expect(
        page.getByRole('heading', { name: '10.255.0.8/31' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="subnet-context"]')).toHaveText(
        'Point à point, Liaison de secours',
    );

    const summary = page.locator('[data-test="subnet-summary"]');

    await expect(summary.getByText('Masque')).toBeVisible();
    await expect(summary.getByText('Passerelle')).toHaveCount(0);
    await expect(page.getByRole('region', { name: 'Plages' })).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Ajouter une plage' }),
    ).toHaveCount(0);

    // Both addresses of the link are free, and can be documented.
    await expect(
        page.getByRole('region', { name: 'Adresses libres' }),
    ).toContainText('10.255.0.8');

    // A segment shows both, for comparison.
    await openSubnet(page, '10.10.10.0/24');
    await expect(
        page.locator('[data-test="subnet-summary"]').getByText('Passerelle'),
    ).toBeVisible();
    await expect(page.getByRole('region', { name: 'Plages' })).toBeVisible();

    // The server refuses what the form hides: a point-to-point link cannot
    // be given a size of its own.
    await page.goto('/subnets/create');
    await page.getByLabel('Type').selectOption({ label: 'Point à point' });
    await page.getByLabel('Adresse du réseau').fill('10.255.1.0/24');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        page.getByText(
            "Le préfixe d'un sous-réseau de type « Point à point » est /30 ou /31.",
        ),
    ).toBeVisible();
});

test('a reserved range shows in the plan, and the next free address comes from the static range', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // The management network of the head office: a static range from .1
    // to .99, a reserved range from .200 to .249.
    await openSubnet(page, '10.10.99.0/24');

    // Each range says its kind.
    const ranges = page.getByRole('region', { name: 'Plages' });

    await expect(ranges.locator('[data-test="range-kind"]')).toHaveText([
        'Fixe',
        'Réservée',
    ]);
    await expect(ranges.getByRole('listitem')).toHaveText([
        /Fixe.*Équipements réseau.*10\.10\.99\.1 – 10\.10\.99\.99/s,
        /Réservée.*Extension.*10\.10\.99\.200 – 10\.10\.99\.249/s,
    ]);

    // The plan: the reserved range is a state of its own, the ninth of the
    // legend; the free addresses of the static range are simply free.
    const plan = page.getByRole('region', { name: "Plan d'adressage" });

    await expect(plan.getByRole('listitem')).toHaveCount(9);
    await expect(
        plan.getByRole('listitem').filter({ hasText: 'Plage réservée' }),
    ).toHaveAttribute('data-state', 'reserved_range');
    await expect(
        plan.locator('[title][data-state="reserved_range"]'),
    ).toHaveCount(50);
    await expect(
        plan.getByTitle('10.10.99.200 — Plage réservée', { exact: true }),
    ).toHaveAttribute('data-state', 'reserved_range');
    await expect(
        plan.getByTitle('10.10.99.249 — Plage réservée', { exact: true }),
    ).toBeVisible();
    await expect(
        plan.getByTitle('10.10.99.250 — Libre', { exact: true }),
    ).toHaveAttribute('data-state', 'free');
    await expect(
        plan.getByTitle('10.10.99.1 — Libre', { exact: true }),
    ).toHaveAttribute('data-state', 'free');
    await expect(plan.getByText(/Plage réservée : 50\./)).toBeAttached();

    // The next free address is the lowest one of the static range, and
    // the page says where it was taken from.
    const free = page.getByRole('region', { name: 'Adresses libres' });
    const next = free.locator('[data-test="next-free-address-card"]');

    await expect(next).toContainText('10.10.99.1');
    await expect(
        next.locator('[data-test="next-free-address-source"]'),
    ).toHaveText('Plage fixe « Équipements réseau »');

    // The free addresses are cut at the bounds of the ranges, and each
    // interval says where it lies.
    const intervals = free.locator('[data-test="free-interval"]');

    await expect(intervals.first()).toContainText('10.10.99.1 – 10.10.99.10');
    await expect(intervals.first()).toContainText(
        'Plage fixe — Équipements réseau',
    );
    await expect(
        intervals.filter({ hasText: '10.10.99.200 – 10.10.99.249' }),
    ).toContainText('Plage réservée — Extension');
    await expect(
        intervals.filter({ hasText: '10.10.99.100 – 10.10.99.199' }),
    ).toContainText('Hors plage');

    // "Use" opens the window of a new address, filled in with it.
    await next.getByRole('button', { name: 'Utiliser 10.10.99.1' }).click();

    const dialog = page.getByRole('dialog', { name: 'Nouvelle adresse IP' });

    await expect(dialog.getByLabel('Adresse', { exact: true })).toHaveValue(
        '10.10.99.1',
    );
    await dialog.getByLabel("Nom d'hôte").fill('sw-accueil');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog).toBeHidden();

    // Once taken, the next one of the static range is proposed.
    await expect(next).toContainText('10.10.99.2');
    await expect(
        plan.getByTitle('10.10.99.1 — Documentée — sw-accueil', {
            exact: true,
        }),
    ).toHaveAttribute('data-state', 'documented');

    // The quick entry proposes addresses the same way: in the static
    // range, never in the reserved one.
    await page
        .getByRole('button', { name: 'Ajouter plusieurs adresses' })
        .click();
    await expect(
        page
            .getByRole('region', { name: 'Ajouter plusieurs adresses' })
            .getByLabel('Adresse', { exact: true })
            .first(),
    ).toHaveValue('10.10.99.2');
});

test('the page of a site lists what differs from the standard of the organisation', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    // The workshop: its code, its block, and its camera VLAN numbered 150
    // where the catalogue says 50.
    await openFromList(page, '/sites', 'Atelier');
    await expect(
        page.getByRole('heading', { name: 'Site Atelier' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="site-code"]')).toContainText('ATE');
    await expect(
        page
            .getByRole('region', { name: "Blocs d'adressage" })
            .getByRole('link', { name: '10.20.0.0/16' }),
    ).toBeVisible();

    const deviations = page.getByRole('region', { name: 'Écarts' });
    const items = deviations.locator('[data-test="deviation-item"]');

    // The VLAN differs from its entry of the catalogue, and its subnet
    // from the one the addressing rule expects for the number 150.
    await expect(items).toHaveText([
        /VLAN 150 — Vidéosurveillance.*Écart\s*:\s*VID 150, catalogue : 50/s,
        /10\.20\.50\.0\/24.*Écart\s*:\s*Attendu par la règle : 10\.20\.150\.0\/24/s,
    ]);

    // The same deviation is told in the line of the VLAN, with the icon
    // and the reason, never by a colour alone.
    const vlans = page.getByRole('region', { name: 'VLAN', exact: true });
    const cameras = vlans.getByRole('row', { name: /Vidéosurveillance/ });

    await expect(cameras).toContainText('50 — Vidéosurveillance');
    await expect(deviationsOf(cameras)).toContainText(
        'VID 150, catalogue : 50',
    );
    await expect(deviationsOf(cameras).locator('svg')).toHaveCount(1);
    // A VLAN that follows its entry to the letter has none.
    await expect(
        deviationsOf(vlans.getByRole('row', { name: /Production/ })),
    ).toHaveCount(0);

    // The link leads to the VLAN, where the deviation is told again.
    await items.first().getByRole('link').click();
    await expect(
        page.getByRole('heading', { name: /VLAN 150 — Vidéosurveillance/ }),
    ).toBeVisible();
    await expect(deviationsOf(page.getByRole('main')).first()).toContainText(
        'VID 150, catalogue : 50',
    );
    // Under its subnet, what the rule expected instead.
    await expect(
        deviationsOf(page.locator('[data-test="vlan-subnet"]')),
    ).toContainText('Attendu par la règle : 10.20.150.0/24');

    // The head office: a segment outside of its block, told with its
    // reason, and its subnets that no VLAN carries. The addresses of the
    // carrier are outside of the block too, and nothing is said about them:
    // only a segment is expected in the blocks of its site.
    await openFromList(page, '/sites', 'Siège');
    await expect(page.locator('[data-test="site-code"]')).toContainText('SIE');
    await expect(items).toHaveText([
        /192\.168\.1\.0\/24.*Hors des blocs du site \(10\.10\.0\.0\/16\)/s,
    ]);
    await expect(
        page
            .getByRole('region', { name: 'Sous-réseaux sans VLAN' })
            .getByRole('row')
            .filter({ has: page.getByRole('cell') }),
    ).toHaveText([
        /10\.10\.255\.1\/32.*Loopback/s,
        /203\.0\.113\.8\/29.*Externe/s,
    ]);

    // The subnet says so on its own page too.
    await items.first().getByRole('link').click();
    await expect(
        page.getByRole('heading', { name: '192.168.1.0/24' }),
    ).toBeVisible();
    await expect(deviationsOf(page.getByRole('main'))).toContainText(
        'Hors des blocs du site (10.10.0.0/16)',
    );
});

test('the forms follow what is chosen in them: an entry fills in a VLAN, a VLAN gives its site, a range says its kind', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // --- A new VLAN, from an entry of the catalogue.
    await page.goto('/vlans/create');
    await expect(
        page.getByRole('heading', { name: 'Nouveau VLAN' }),
    ).toBeVisible();

    const entry = page.getByLabel('Entrée du catalogue');
    const vid = page.getByLabel('VID');
    const name = page.getByLabel('Nom', { exact: true });

    await expect(vid).toHaveValue('');
    await entry.selectOption({ label: '60 — Production' });

    // The number and the name of the entry are filled in, and stay free.
    await expect(vid).toHaveValue('60');
    await expect(name).toHaveValue('Production');
    await entry.selectOption({ label: '20 — Serveurs' });
    await expect(vid).toHaveValue('20');
    await expect(name).toHaveValue('Serveurs');
    await name.fill('Serveurs de production');
    await expect(vid).toHaveValue('20');

    // From the page of an entry, the form opens with the site and the
    // entry already chosen.
    await openFromList(page, '/catalog-vlans', 'Serveurs');
    await page
        .getByRole('region', { name: 'Sur chaque site' })
        .getByRole('row', { name: /Atelier/ })
        .getByRole('link', { name: 'Créer ce VLAN' })
        .click();
    await expect(
        page.getByRole('heading', { name: 'Nouveau VLAN' }),
    ).toBeVisible();
    await expect(
        page.getByLabel('Site', { exact: true }).locator('option:checked'),
    ).toHaveText('Atelier');
    await expect(entry.locator('option:checked')).toHaveText('20 — Serveurs');
    await expect(vid).toHaveValue('20');
    await expect(name).toHaveValue('Serveurs');

    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('VLAN créé.')).toBeVisible();
    await expect(page.locator('[data-test="catalog-link"]')).toHaveText(
        /20\s*— Serveurs/,
    );

    // --- A new subnet: the VLAN that carries it gives it its site.
    await page.getByRole('link', { name: 'Rattacher un sous-réseau' }).click();
    await expect(
        page.getByRole('heading', { name: 'Nouveau sous-réseau' }),
    ).toBeVisible();

    const site = page.getByLabel('Site', { exact: true });

    await expect(site).toBeDisabled();
    await expect(site.locator('option:checked')).toHaveText('Atelier');
    await expect(
        page.getByText(
            'Le site du VLAN : un sous-réseau est sur le site du VLAN qui le porte.',
        ),
    ).toBeVisible();

    // Without a VLAN, the site is chosen freely.
    await page.getByLabel('VLAN', { exact: true }).selectOption({
        label: 'Aucun VLAN',
    });
    await expect(site).toBeEnabled();
    await site.selectOption({ label: 'Siège' });

    await page.getByLabel('Adresse du réseau').fill('10.61.0.0/24');
    await page.getByLabel('Passerelle').fill('10.61.0.254');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        page.getByRole('heading', { name: '10.61.0.0/24' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="subnet-context"]')).toHaveText(
        'Site Siège, Segment',
    );

    // --- A range says its kind, in its window and on its card.
    const ranges = page.getByRole('region', { name: 'Plages' });

    await ranges.getByRole('button', { name: 'Ajouter une plage' }).click();

    const dialog = page.getByRole('dialog', { name: 'Nouvelle plage' });

    await expect(dialog.getByLabel('Type').locator('option')).toHaveText([
        'DHCP',
        'Fixe',
        'Réservée',
    ]);
    await dialog.getByLabel('Type').selectOption({ label: 'Réservée' });
    await dialog.getByLabel('Nom', { exact: true }).fill('Réserve');
    await dialog.getByLabel('Début').fill('10.61.0.200');
    await dialog.getByLabel('Fin').fill('10.61.0.220');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(dialog).toBeHidden();
    await expect(page.getByText('Plage créée.')).toBeVisible();
    await expect(ranges.getByRole('listitem')).toHaveText([
        /Réservée.*Réserve.*10\.61\.0\.200 – 10\.61\.0\.220.*21 adresses/s,
    ]);
    await expect(
        page
            .getByRole('region', { name: "Plan d'adressage" })
            .locator('[title][data-state="reserved_range"]'),
    ).toHaveCount(21);

    // A DHCP range over the gateway is refused in the window, and the
    // same range is accepted as a static one.
    await ranges.getByRole('button', { name: 'Ajouter une plage' }).click();
    await dialog.getByLabel('Début').fill('10.61.0.240');
    await dialog.getByLabel('Fin').fill('10.61.0.254');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(
        dialog.getByText(
            'Une plage DHCP ne peut pas contenir la passerelle (10.61.0.254).',
        ),
    ).toBeVisible();

    await dialog.getByLabel('Type').selectOption({ label: 'Fixe' });
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog).toBeHidden();
    await expect(ranges.locator('[data-test="range-kind"]')).toHaveText([
        'Réservée',
        'Fixe',
    ]);

    // The next free address now comes from that static range.
    await expect(
        page.locator('[data-test="next-free-address-card"]'),
    ).toContainText('10.61.0.240');
    await expect(
        page.locator('[data-test="next-free-address-source"]'),
    ).toHaveText('Plage fixe');
});
