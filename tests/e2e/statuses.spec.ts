// torsade/tests/e2e/statuses.spec.ts
import type { Locator } from '@playwright/test';
import {
    addressTable,
    dataRows,
    expect,
    openSubnet,
    test,
} from './support/fixtures';

// The status cell of a line: its text, exactly.
const status = (row: Locator, label: string): Locator =>
    row.getByRole('cell', { name: label, exact: true });

test('a status agrees with the object it describes', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // A VLAN is "actif", "prévu" or "retiré", in its list and its filter.
    await page.goto('/vlans');

    const vlans = page.getByRole('table');

    await expect(
        status(
            vlans.getByRole('row', { name: /192\.168\.1\.0\/24/ }),
            'Retiré',
        ),
    ).toBeVisible();
    await expect(
        status(vlans.getByRole('row', { name: /10\.20\.70\.0\/24/ }), 'Prévu'),
    ).toBeVisible();
    await expect(
        status(vlans.getByRole('row', { name: /10\.10\.20\.0\/24/ }), 'Actif'),
    ).toBeVisible();
    await expect(
        page.getByLabel('Statut', { exact: true }).getByRole('option'),
    ).toHaveText(['Tous les statuts', 'Prévu', 'Actif', 'Retiré']);

    // So is a subnet.
    await page.goto('/subnets');

    const subnets = page.getByRole('table');

    await expect(
        status(
            subnets.getByRole('row', { name: /192\.168\.1\.0\/24/ }),
            'Retiré',
        ),
    ).toBeVisible();
    await expect(
        status(
            subnets.getByRole('row', { name: /10\.20\.70\.0\/24/ }),
            'Prévu',
        ),
    ).toBeVisible();

    // Their forms offer the same words.
    await page.goto('/vlans/create');
    await expect(
        page.getByLabel('Statut', { exact: true }).getByRole('option'),
    ).toHaveText(['Prévu', 'Actif', 'Retiré']);

    await page.goto('/subnets/create');
    await expect(
        page.getByLabel('Statut', { exact: true }).getByRole('option'),
    ).toHaveText(['Prévu', 'Actif', 'Retiré']);

    // An IP address is "active", "prévue" or "retirée", in the table of
    // its subnet, which is itself "actif".
    await openSubnet(page, '10.10.20.0/24');

    await expect(page.getByText('Actif', { exact: true })).toBeVisible();

    const addresses = dataRows(addressTable(page));

    await expect(
        status(addresses.filter({ hasText: '10.10.20.31' }), 'Prévue'),
    ).toBeVisible();
    await expect(
        status(addresses.filter({ hasText: '10.10.20.40' }), 'Retirée'),
    ).toBeVisible();
    await expect(
        status(addresses.filter({ hasText: 'nas-01' }), 'Active'),
    ).toBeVisible();

    // No line of the table is left with the other form.
    await expect(
        addressTable(page).getByRole('cell', { name: 'Actif', exact: true }),
    ).toHaveCount(0);
    await expect(
        addressTable(page).getByRole('cell', { name: 'Prévu', exact: true }),
    ).toHaveCount(0);
    await expect(
        addressTable(page).getByRole('cell', { name: 'Retiré', exact: true }),
    ).toHaveCount(0);

    // The window of one address, then the quick entry of several.
    await page.getByRole('button', { name: 'Ajouter une adresse' }).click();

    const dialog = page.getByRole('dialog', { name: 'Nouvelle adresse IP' });

    await expect(
        dialog.getByLabel('Statut', { exact: true }).getByRole('option'),
    ).toHaveText(['Prévue', 'Active', 'Retirée']);
    await dialog.getByRole('button', { name: 'Annuler' }).click();
    await expect(dialog).toBeHidden();

    await page
        .getByRole('button', { name: 'Ajouter plusieurs adresses' })
        .click();

    await expect(
        page
            .getByRole('region', { name: 'Ajouter plusieurs adresses' })
            .getByLabel('Statut', { exact: true })
            .getByRole('option'),
    ).toHaveText(['Prévue', 'Active', 'Retirée']);
});

test('the activity log writes a status the way its object is written', async ({
    page,
    logIn,
}) => {
    await logIn('admin');
    await page.goto('/activity-log');

    const log = page.getByRole('table');

    // The demonstration data: a planned address, then a planned VLAN.
    await page
        .getByLabel('Événement')
        .selectOption({ label: 'Adresse IP créée' });
    await page.getByRole('button', { name: 'Filtrer' }).click();
    await expect(page).toHaveURL(/event=ip_address\.created/);

    await expect(log.getByText('Statut : Active').first()).toBeVisible();
    await expect(log.getByText('Statut : Actif', { exact: true })).toHaveCount(
        0,
    );

    await page.getByLabel('Événement').selectOption({ label: 'VLAN créé' });
    await page.getByRole('button', { name: 'Filtrer' }).click();
    await expect(page).toHaveURL(/event=vlan\.created/);

    await expect(
        log.getByText('Statut : Actif', { exact: true }).first(),
    ).toBeVisible();
    await expect(
        log.getByText('Statut : Prévu', { exact: true }).first(),
    ).toBeVisible();
    await expect(log.getByText('Statut : Active')).toHaveCount(0);
});
