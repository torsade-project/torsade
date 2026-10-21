// torsade/tests/e2e/address-table.spec.ts
import type { Locator } from '@playwright/test';
import {
    addressTable,
    dataRows,
    expect,
    openSubnet,
    test,
} from './support/fixtures';

// The demonstration subnet of the office network: twenty addresses.
const SUBNET = '10.10.10.0/24';
const COUNT = 20;

// The addresses of the lines, in the order they are displayed.
const displayedAddresses = async (rows: Locator): Promise<string[]> =>
    (await rows.allInnerTexts()).map(
        (text) => /\d+\.\d+\.\d+\.\d+/.exec(text)?.[0] ?? '',
    );

const lastByte = (address: string): number => Number(address.split('.')[3]);

test.beforeEach(async ({ page, logIn }) => {
    await logIn('reader');
    await openSubnet(page, SUBNET);
});

test('the addresses are filtered by host name, then by MAC address', async ({
    page,
}) => {
    const rows = dataRows(addressTable(page));
    const search = page.getByRole('searchbox');

    await expect(rows).toHaveCount(COUNT);

    await search.fill('compta');
    await expect(rows).toHaveText([
        /10\.10\.10\.21.*imp-compta/s,
        /10\.10\.10\.111.*pc-compta-01/s,
        /10\.10\.10\.112.*pc-compta-02/s,
    ]);

    // A MAC address is found in another notation than the one displayed.
    await search.fill('0200.0A0A.0A14');
    await expect(rows).toHaveText([
        /10\.10\.10\.20.*imp-accueil.*02:00:0a:0a:0a:14/s,
    ]);

    await search.fill('aucune-machine-de-ce-nom');
    await expect(rows).toHaveCount(1);
    await expect(
        page.getByText('Aucune adresse ne correspond à cette recherche.'),
    ).toBeVisible();

    await search.fill('');
    await expect(rows).toHaveCount(COUNT);
});

test('the addresses are sorted by address, in both directions', async ({
    page,
}) => {
    const table = addressTable(page);
    const rows = dataRows(table);
    const header = table.getByRole('columnheader', {
        name: 'Adresse',
        exact: true,
    });

    // Ascending when the page opens, in the order of the numbers:
    // 10.10.10.30 comes before 10.10.10.110.
    await expect(header).toHaveAttribute('aria-sort', 'ascending');
    await expect(rows).toHaveCount(COUNT);

    const ascending = await displayedAddresses(rows);

    expect(ascending).toEqual(
        [...ascending].sort((a, b) => lastByte(a) - lastByte(b)),
    );
    expect(ascending[0]).toBe('10.10.10.20');
    expect(ascending.indexOf('10.10.10.30')).toBeLessThan(
        ascending.indexOf('10.10.10.110'),
    );

    await header.getByRole('button').click();
    await expect(header).toHaveAttribute('aria-sort', 'descending');
    await expect(rows.first()).toContainText('10.10.10.123');
    expect(await displayedAddresses(rows)).toEqual([...ascending].reverse());

    await header.getByRole('button').click();
    await expect(header).toHaveAttribute('aria-sort', 'ascending');
    await expect(rows.first()).toContainText('10.10.10.20');
    expect(await displayedAddresses(rows)).toEqual(ascending);
});
