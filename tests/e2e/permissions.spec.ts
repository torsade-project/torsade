// torsade/tests/e2e/permissions.spec.ts
import type { Page } from '@playwright/test';
import { expect, openSubnet, test } from './support/fixtures';

// A list page is drawn once its table is there.
const openList = async (page: Page, path: string): Promise<void> => {
    await page.goto(path);
    await expect(page.getByRole('table')).toBeVisible();
};

// Every page that offers a way to write, and the controls it shows to a
// user who may write.
const PAGES: {
    title: string;
    open: (page: Page) => Promise<void>;
    controls: string[];
}[] = [
    {
        title: 'the sites',
        open: (page) => openList(page, '/sites'),
        controls: ['Nouveau site', 'Modifier', 'Supprimer'],
    },
    {
        title: 'a site',
        open: async (page) => {
            await openList(page, '/sites');
            await page
                .getByRole('link', { name: 'Siège', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: 'Site Siège' }),
            ).toBeVisible();
        },
        controls: [
            'Modifier',
            'Supprimer',
            'Nouveau bloc',
            'Nouveau VLAN',
            'Nouveau sous-réseau',
            'Nouveau bâtiment',
            'Nouvel étage',
            'Nouveau local',
            'Ajouter une prise',
            'Ajouter des prises en série',
            'Ajouter un panneau',
            'Ajouter un équipement',
            "Créer des équipements depuis les noms d'hôte",
            'Nouvel équipement',
        ],
    },
    {
        title: 'the devices',
        open: (page) => openList(page, '/devices'),
        controls: ['Nouvel équipement'],
    },
    {
        title: 'a device',
        open: async (page) => {
            await openList(page, '/devices');
            await page
                .getByRole('link', { name: 'fw-siege', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: 'fw-siege', exact: true }),
            ).toBeVisible();
        },
        controls: [
            'Modifier',
            'Supprimer',
            'Rattacher une adresse',
            'Nouvelle adresse',
            'Détacher toutes les adresses',
            'Détacher',
            // Its member, its ports, its aggregates and its links.
            'Ajouter un membre',
            'Nouvel agrégat',
            'Ajouter des ports',
            'Mode et VLAN',
            'Retirer le mode',
            'Relier en série',
            'Relier…',
            'Activer',
            'Désactiver',
            'Tout désélectionner',
        ],
    },
    {
        title: 'a device without a port',
        open: async (page) => {
            await openList(page, '/devices');
            await page
                .getByRole('link', { name: 'sw-atelier-02', exact: true })
                .click();
            await expect(
                page.getByRole('heading', {
                    name: 'sw-atelier-02',
                    exact: true,
                }),
            ).toBeVisible();
        },
        controls: ['Choisir un modèle', 'Ajouter des ports'],
    },
    {
        title: 'the links',
        open: (page) => openList(page, '/links'),
        controls: ['Modifier', 'Supprimer'],
    },
    {
        title: 'the jacks',
        open: (page) => openList(page, '/jacks'),
        controls: ['Modifier', 'Supprimer'],
    },
    {
        title: 'a patch panel',
        open: async (page) => {
            await openList(page, '/sites');
            await page
                .getByRole('link', { name: 'Siège', exact: true })
                .click();
            await page
                .locator('[data-test="place-panel"][data-panel="PB-B"]')
                .getByRole('link', { name: 'PB-B', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: 'PB-B', exact: true }),
            ).toBeVisible();
        },
        controls: [
            'Modifier',
            'Supprimer',
            'Brasser en série',
            'Débrasser',
            'Vider la sélection',
            'Modifier le cordon du port 1',
            // On the line of a port: patched, with a jack, or free.
            'Débrasser le port 1',
            'Brasser le port 17',
            'Détacher la prise B.01',
            'Relier une prise au port 17',
        ],
    },
    {
        title: 'the device templates',
        open: (page) => openList(page, '/device-templates'),
        controls: ['Nouveau modèle', 'Modifier', 'Supprimer'],
    },
    {
        title: 'the catalogue',
        open: (page) => openList(page, '/catalog-vlans'),
        controls: ['Nouvelle entrée', 'Modifier', 'Supprimer'],
    },
    {
        title: 'an entry of the catalogue',
        open: async (page) => {
            await openList(page, '/catalog-vlans');
            // An entry that the workshop does not follow yet.
            await page
                .getByRole('link', { name: 'Serveurs', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: /Entrée 20 — Serveurs/ }),
            ).toBeVisible();
        },
        controls: ['Modifier', 'Supprimer', 'Créer ce VLAN'],
    },
    {
        title: 'the VLANs',
        open: (page) => openList(page, '/vlans'),
        controls: ['Nouveau VLAN'],
    },
    {
        title: 'a VLAN',
        open: async (page) => {
            await openList(page, '/vlans');
            await page
                .getByRole('row', { name: /10\.10\.10\.0\/24/ })
                // The name of the VLAN, not its entry of the catalogue.
                .getByRole('link', { name: 'Bureautique', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: /Bureautique/ }),
            ).toBeVisible();
        },
        controls: ['Modifier', 'Supprimer'],
    },
    {
        title: 'the blocks',
        open: (page) => openList(page, '/address-blocks'),
        controls: ['Nouveau bloc'],
    },
    {
        title: 'a block',
        open: async (page) => {
            await openList(page, '/address-blocks');
            await page
                .getByRole('link', { name: '10.10.0.0/16', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: '10.10.0.0/16' }),
            ).toBeVisible();
        },
        controls: ['Modifier', 'Supprimer'],
    },
    {
        title: 'the subnets',
        open: (page) => openList(page, '/subnets'),
        controls: ['Nouveau sous-réseau'],
    },
    {
        title: 'a subnet',
        open: (page) => openSubnet(page, '10.10.10.0/24'),
        controls: [
            'Modifier',
            'Supprimer',
            'Ajouter une plage',
            'Ajouter une adresse',
            'Ajouter plusieurs adresses',
        ],
    },
];

// A control is a button or a link, whichever the page uses.
const control = (page: Page, name: string) =>
    page
        .getByRole('button', { name, exact: true })
        .or(page.getByRole('link', { name, exact: true }));

test('an editor sees the write controls', async ({ page, logIn }) => {
    await logIn('editor');

    for (const { title, open, controls } of PAGES) {
        await open(page);

        for (const name of controls) {
            await expect(
                control(page, name).first(),
                `"${name}" on the page of ${title}`,
            ).toBeVisible();
        }
    }
});

test('a reader sees no write control', async ({ page, logIn }) => {
    await logIn('reader');

    for (const { title, open, controls } of PAGES) {
        await open(page);

        for (const name of controls) {
            await expect(
                control(page, name),
                `"${name}" on the page of ${title}`,
            ).toHaveCount(0);
        }
    }
});

test('a reader who opens the address of a form gets the 403 page', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    for (const path of [
        '/vlans/create',
        '/address-blocks/create',
        '/subnets/create',
        '/devices/create',
        '/device-templates/create',
        '/users/create',
    ]) {
        const response = await page.goto(path);

        expect(response?.status(), path).toBe(403);
        await expect(
            page.getByText("Cette action n'est pas autorisée."),
            path,
        ).toBeVisible();
    }

    // The page that turns the host names of a site into devices, too.
    await page.goto('/sites');
    await page.getByRole('link', { name: 'Siège', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Site Siège' }),
    ).toBeVisible();

    const response = await page.goto(`${page.url()}/hostname-devices`);

    expect(response?.status()).toBe(403);
});
