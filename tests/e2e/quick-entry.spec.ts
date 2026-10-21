// torsade/tests/e2e/quick-entry.spec.ts
import {
    addressTable,
    dataRows,
    expect,
    openSubnet,
    test,
} from './support/fixtures';

const HOST_NAME_ERROR = /Un nom d'hôte ne contient que des lettres/;

test('three addresses are entered from the keyboard alone, one of them refused then corrected', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openSubnet(page, '10.20.70.0/24');

    const nothingDocumented = page.getByText(
        "Aucune adresse n'est encore documentée dans ce sous-réseau.",
    );

    await expect(nothingDocumented).toBeVisible();

    // From here on, the mouse is not used any more.
    await page
        .getByRole('button', { name: 'Ajouter plusieurs adresses' })
        .focus();
    await page.keyboard.press('Enter');

    const entry = page.getByRole('region', {
        name: 'Ajouter plusieurs adresses',
    });
    const lines = dataRows(entry);
    const address = (line: number) =>
        lines.nth(line).getByLabel('Adresse', { exact: true });
    const hostName = (line: number) => lines.nth(line).getByLabel("Nom d'hôte");

    // A line opens on the lowest free address, selected: typing replaces it.
    await expect(lines).toHaveCount(1);
    await expect(address(0)).toBeFocused();
    await expect(address(0)).toHaveValue('10.20.70.1');

    // First line: Enter goes from field to field, and opens the next line
    // from the last one.
    await page.keyboard.type('10.20.70.30');
    await page.keyboard.press('Enter');
    await expect(hostName(0)).toBeFocused();
    await page.keyboard.type('poste-atelier-30');
    await page.keyboard.press('Enter');
    await page.keyboard.type('02-00-0A-14-46-1E');
    // Type, assignment, status, then the description.
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await page.keyboard.type('Poste de contrôle');
    await page.keyboard.press('Enter');

    // Second line, with Tab this time, and a host name that is not one.
    await expect(lines).toHaveCount(2);
    await expect(address(1)).toBeFocused();
    await expect(address(1)).toHaveValue('10.20.70.1');
    await page.keyboard.type('10.20.70.10');
    await page.keyboard.press('Tab');
    await expect(hostName(1)).toBeFocused();
    await page.keyboard.type('poste atelier 10');
    for (let field = 0; field < 5; field++) {
        await page.keyboard.press('Tab');
    }
    await page.keyboard.press('Enter');

    // Third line, saved from the field that has the focus.
    await expect(lines).toHaveCount(3);
    await expect(address(2)).toBeFocused();
    await page.keyboard.type('10.20.70.20');
    await page.keyboard.press('Enter');
    await page.keyboard.type('poste-atelier-20');
    await page.keyboard.press('Control+Enter');

    // The refusal shows on the line in error and only there, the focus is
    // in the field to correct, and nothing was saved.
    await expect(lines.nth(1).getByText(HOST_NAME_ERROR)).toBeVisible();
    await expect(entry.getByText(HOST_NAME_ERROR)).toHaveCount(1);
    await expect(hostName(1)).toHaveAttribute('aria-invalid', 'true');
    await expect(hostName(1)).toBeFocused();
    await expect(
        entry.getByText(
            "Rien n'a été enregistré : corrigez les lignes en erreur, puis enregistrez à nouveau.",
        ),
    ).toBeVisible();
    await expect(lines).toHaveCount(3);
    await expect(address(0)).toHaveValue('10.20.70.30');
    await expect(nothingDocumented).toBeVisible();

    // The faulty name is selected: typing replaces it.
    await page.keyboard.type('poste-atelier-10');
    await page.keyboard.press('Control+Enter');

    await expect(page.getByText('3 adresses IP créées.')).toBeVisible();
    await expect(entry).toBeHidden();

    // The three addresses are in the table, in the order of the addresses
    // and not in the order they were typed.
    await expect(dataRows(addressTable(page))).toHaveText([
        /10\.20\.70\.10.*poste-atelier-10/s,
        /10\.20\.70\.20.*poste-atelier-20/s,
        /10\.20\.70\.30.*poste-atelier-30.*02:00:0a:14:46:1e.*Poste de contrôle/s,
    ]);
});
