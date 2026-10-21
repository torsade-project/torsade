// torsade/tests/e2e/mac-search.spec.ts
import type { Page } from '@playwright/test';
import {
    addressTable,
    dataRows,
    expect,
    openSubnet,
    test,
} from './support/fixtures';

// Documents one address through the window of the subnet page.
const addAddress = async (
    page: Page,
    address: string,
    hostName: string,
    mac: string,
): Promise<void> => {
    await page.getByRole('button', { name: 'Ajouter une adresse' }).click();

    const dialog = page.getByRole('dialog', { name: 'Nouvelle adresse IP' });

    await dialog.getByLabel('Adresse', { exact: true }).fill(address);
    await dialog.getByLabel("Nom d'hôte").fill(hostName);
    await dialog.getByLabel('Adresse MAC').fill(mac);
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(dialog).toBeHidden();
    await expect(
        dataRows(addressTable(page)).filter({ hasText: hostName }),
    ).toHaveCount(1);
};

test('a search is compared with the MAC addresses only when it can be one', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // The guest network of the demonstration, where nothing is documented.
    await openSubnet(page, '10.10.40.0/24');

    // A MAC address typed with hyphens and capitals, one that holds the
    // digits "ca", and a machine whose name reads "compta".
    await addAddress(
        page,
        '10.10.40.1',
        'portail-invites',
        'AA-BB-CC-DD-EE-FF',
    );
    await addAddress(page, '10.10.40.2', 'borne-hall', '02:00:CA:FE:00:01');
    await addAddress(page, '10.10.40.3', 'pc-compta-invite', '');

    const rows = dataRows(addressTable(page));
    const search = page.getByRole('searchbox');

    const portal = /10\.10\.40\.1(?!\d).*portail-invites.*aa:bb:cc:dd:ee:ff/s;
    const terminal = /10\.10\.40\.2(?!\d).*borne-hall.*02:00:ca:fe:00:01/s;
    const accounting = /10\.10\.40\.3(?!\d).*pc-compta-invite/s;

    await expect(rows).toHaveText([portal, terminal, accounting]);

    // "compta" is a name, not the digits "c" and "a": the MAC address
    // that holds "ca" is not found.
    await search.fill('compta');
    await expect(rows).toHaveText([accounting]);

    await search.fill('pc-compta');
    await expect(rows).toHaveText([accounting]);

    // A piece of MAC address is found whatever its case and its
    // separators.
    for (const typed of [
        'AA-BB',
        'aa:bb',
        'aabb',
        'AABB.CCDD',
        'aa bb cc',
        'aa:bb:cc:dd:ee:ff',
        'AA-BB-CC-DD-EE-FF',
        'aabbccddeeff',
    ]) {
        await search.fill(typed);
        await expect(rows, typed).toHaveText([portal]);
    }

    // The digits "ca" do find the address that holds them, when they are
    // typed as digits.
    for (const typed of ['ca', 'CA:FE', 'cafe', '02-00-ca']) {
        await search.fill(typed);
        await expect(rows, typed).toHaveText([terminal]);
    }

    // One letter that is not a digit, and the text is no MAC address.
    await search.fill('aa:bb:zz');
    await expect(rows).toHaveCount(1);
    await expect(
        page.getByText('Aucune adresse ne correspond à cette recherche.'),
    ).toBeVisible();

    // The other columns are searched as before.
    await search.fill('borne');
    await expect(rows).toHaveText([terminal]);

    await search.fill('10.10.40.3');
    await expect(rows).toHaveText([accounting]);

    await search.fill('');
    await expect(rows).toHaveCount(3);
});
