// torsade/tests/e2e/network.spec.ts
import { expect, test } from './support/fixtures';

test('an editor creates a site, then a VLAN with its subnet, and opens the subnet page', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // The site, in its window.
    await page
        .getByRole('link', { name: 'Sites', exact: true })
        .first()
        .click();
    await expect(page.getByRole('heading', { name: 'Sites' })).toBeVisible();
    await page.getByRole('button', { name: 'Nouveau site' }).click();

    const dialog = page.getByRole('dialog', { name: 'Nouveau site' });

    await dialog.getByLabel('Nom', { exact: true }).fill('Entrepôt');
    await dialog.getByLabel('Description').fill('Stockage et expéditions');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(page.getByText('Site créé.')).toBeVisible();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('row', { name: /Entrepôt/ })).toBeVisible();

    // The VLAN and its subnet, in one form, reached through the menu.
    const menu = page.getByRole('navigation', {
        name: 'Navigation principale',
    });

    await menu
        .getByRole('button', { name: 'Réseau logique', exact: true })
        .click();
    await menu.getByRole('link', { name: 'VLAN', exact: true }).click();
    await page.getByRole('link', { name: 'Nouveau VLAN' }).click();

    // The list of VLANs has a "Site" field too, in its filters: the form
    // must be there before anything is filled in.
    await expect(
        page.getByRole('heading', { name: 'Nouveau VLAN' }),
    ).toBeVisible();

    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Entrepôt' });
    await page.getByLabel('VID', { exact: true }).fill('300');
    await page.getByLabel('Nom', { exact: true }).fill('Logistique');
    await page.getByLabel('Créer le sous-réseau de ce VLAN maintenant').check();
    await page.getByLabel('Adresse du réseau').fill('10.30.40.0');
    await page.getByLabel('Préfixe').selectOption('24');
    await page.getByLabel('Passerelle').fill('10.30.40.254');
    await page.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(page.getByText('VLAN créé.')).toBeVisible();
    await expect(
        page.getByRole('heading', { name: /300.*Logistique/ }),
    ).toBeVisible();
    await expect(page.getByText('Entrepôt').first()).toBeVisible();

    // The subnet page, from the page of the VLAN.
    await page.getByRole('link', { name: '10.30.40.0/24' }).click();

    await expect(
        page.getByRole('heading', { name: '10.30.40.0/24' }),
    ).toBeVisible();
    await expect(page.getByText('255.255.255.0')).toBeVisible();
    await expect(page.getByText('10.30.40.254').first()).toBeVisible();
    await expect(page.getByText('10.30.40.255').first()).toBeVisible();
    await expect(
        page.getByText(
            "Aucune adresse n'est encore documentée dans ce sous-réseau.",
        ),
    ).toBeVisible();

    // It is in the list of subnets too.
    await page.goto('/subnets');
    await expect(
        page.getByRole('row', { name: /10\.30\.40\.0\/24.*Logistique/ }),
    ).toBeVisible();
});
