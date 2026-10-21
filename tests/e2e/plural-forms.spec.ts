// torsade/tests/e2e/plural-forms.spec.ts
import fs from 'node:fs';
import path from 'node:path';
import { chooseForm, pluralIndex } from '../../resources/js/lib/plural';
import { projectRoot } from './support/environment';
import { expect, openSubnet, test } from './support/fixtures';

// The strings of the interface, as the pages read them.
const catalogue = JSON.parse(
    fs.readFileSync(path.join(projectRoot, 'lang', 'fr.json'), 'utf8'),
) as Record<string, string>;

test('the form picked for a number is the French one: singular for zero and one', () => {
    expect([0, 1, 2, 37].map((count) => pluralIndex('fr', count))).toEqual([
        0, 0, 1, 1,
    ]);

    // Another language keeps its own rule: "0 lines" in English.
    expect([0, 1, 2, 37].map((count) => pluralIndex('en', count))).toEqual([
        1, 0, 1, 1,
    ]);

    const forms = (message: string, locale = 'fr'): string[] =>
        [0, 1, 2, 37].map((count) => chooseForm(message, count, locale));

    expect(forms('ligne|lignes')).toEqual([
        'ligne',
        'ligne',
        'lignes',
        'lignes',
    ]);
    expect(forms('line|lines', 'en')).toEqual([
        'lines',
        'line',
        'lines',
        'lines',
    ]);

    // A form written for given numbers wins over the rule of the language.
    expect(forms('{0} aucune|{1} une seule|[2,*] plusieurs')).toEqual([
        'aucune',
        'une seule',
        'plusieurs',
        'plusieurs',
    ]);
    expect(forms('[0,1] peu|[2,36] davantage|[37,*] beaucoup')).toEqual([
        'peu',
        'peu',
        'davantage',
        'beaucoup',
    ]);

    // A message with a single form is the same for every number.
    expect(forms(':items et :count de plus')).toEqual(
        Array(4).fill(':items et :count de plus'),
    );

    // Every string of the catalogue that has forms: the first one for zero
    // and one, the second one from two on.
    const withForms = Object.entries(catalogue).filter(([key]) =>
        key.includes('|'),
    );

    expect(withForms.length).toBeGreaterThanOrEqual(10);

    for (const [key, translation] of withForms) {
        const [singular, plural] = translation.split('|');

        expect(singular, key).not.toEqual(plural);
        expect(forms(translation), key).toEqual([
            singular,
            singular,
            plural,
            plural,
        ]);
    }
});

test('the quick entry counts its lines: one line, two lines', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openSubnet(page, '10.20.70.0/24');

    await page
        .getByRole('button', { name: 'Ajouter plusieurs adresses' })
        .click();

    const entry = page.getByRole('region', {
        name: 'Ajouter plusieurs adresses',
    });
    const count = entry.getByText(/^\d+ lignes? sur \d+$/);
    const addLine = entry.getByRole('button', { name: 'Ajouter une ligne' });
    const dropLine = entry.getByRole('button', { name: 'Annuler cette ligne' });

    await expect(count).toHaveText('1 ligne sur 100');

    await addLine.click();
    await expect(count).toHaveText('2 lignes sur 100');

    await addLine.click();
    await expect(count).toHaveText('3 lignes sur 100');

    await dropLine.last().click();
    await expect(count).toHaveText('2 lignes sur 100');

    await dropLine.last().click();
    await expect(count).toHaveText('1 ligne sur 100');

    // The entry never goes without a line: dropping the last one opens a
    // new one.
    await dropLine.click();
    await expect(dropLine).toHaveCount(1);
    await expect(count).toHaveText('1 ligne sur 100');

    // Nothing is saved.
    await entry.getByRole('button', { name: 'Annuler', exact: true }).click();
    await expect(entry).toBeHidden();
});

test('a range counts its addresses: one address, two addresses', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openSubnet(page, '10.20.70.0/24');

    const ranges = page.getByRole('region', { name: 'Plages' });
    const noRange = ranges.getByText('Aucune plage dans ce sous-réseau.');
    const size = ranges.getByText(/^\d+ adresses?$/);

    await expect(noRange).toBeVisible();

    // A range of a single address.
    await ranges.getByRole('button', { name: 'Ajouter une plage' }).click();

    const creation = page.getByRole('dialog', { name: 'Nouvelle plage' });

    await creation.getByLabel('Début').fill('10.20.70.200');
    await creation.getByLabel('Fin').fill('10.20.70.200');
    await creation.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(creation).toBeHidden();
    await expect(ranges.getByText('10.20.70.200 – 10.20.70.200')).toBeVisible();
    await expect(size).toHaveText('1 adresse');

    // The same range, one address longer.
    await ranges.getByRole('button', { name: 'Modifier' }).click();

    const edition = page.getByRole('dialog', {
        name: 'Modifier la plage',
    });

    await edition.getByLabel('Fin').fill('10.20.70.201');
    await edition.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(edition).toBeHidden();
    await expect(ranges.getByText('10.20.70.200 – 10.20.70.201')).toBeVisible();
    await expect(size).toHaveText('2 adresses');

    // The subnet is left as it was found.
    await ranges.getByRole('button', { name: 'Supprimer' }).click();
    await page
        .getByRole('dialog')
        .filter({ hasText: 'Supprimer cette plage ?' })
        .getByRole('button', { name: 'Supprimer' })
        .click();

    await expect(noRange).toBeVisible();
});

test('a list counts its entries: no entry, several entries', async ({
    page,
    logIn,
}) => {
    await logIn('admin');
    await page.goto('/activity-log');

    const pagination = page.getByRole('navigation', { name: 'Pagination' });

    // The log holds at least the logins of this run.
    await expect(pagination).toContainText(
        /^Page 1 sur \d+ \((?:[2-9]|\d[\d\s]+) entrées\)/,
    );

    // A day when nothing happened.
    await page.getByLabel('Du', { exact: true }).fill('2001-01-01');
    await page.getByLabel('Au', { exact: true }).fill('2001-01-01');
    await page.getByRole('button', { name: 'Filtrer' }).click();

    await expect(
        page.getByText('Aucune entrée ne correspond à ces filtres.'),
    ).toBeVisible();
    await expect(pagination).toContainText('Page 1 sur 1 (0 entrée)');
    await expect(pagination).not.toContainText('entrées');
});
