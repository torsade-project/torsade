// torsade/tests/e2e/widths.spec.ts
import type { Page } from '@playwright/test';
import {
    addressTable,
    dataRows,
    expect,
    openSubnet,
    test,
} from './support/fixtures';

// From a small laptop to a wide screen, then a telephone.
const WIDTHS = [1024, 1280, 1920, 360];

const SCREENS = [
    '/dashboard',
    '/sites',
    '/catalog-vlans',
    '/vlans',
    '/vlans/create',
    '/address-blocks',
    '/address-blocks/create',
    '/subnets',
    '/subnets/create',
    '/diagrams/logical',
    '/devices',
    '/devices/create',
    '/links',
    '/diagrams/physical',
    '/jacks',
    '/device-templates',
    '/device-templates/create',
    '/users',
    '/users/create',
    '/activity-log',
    '/settings/profile',
    '/settings/appearance',
];

type Report = {
    // Whether the page itself can be scrolled sideways.
    pageScrolls: boolean;
    // What sticks out of the screen without a frame of its own to scroll in.
    cutOff: string[];
};

// A table or the address plan may be wider than the screen: it then scrolls
// inside its frame. Anything else that goes past the edge is cut off.
const sideways = (page: Page): Promise<Report> =>
    page.evaluate(() => {
        const width = document.documentElement.clientWidth;

        const scrollsInside = (element: Element): boolean => {
            for (
                let frame = element.parentElement;
                frame;
                frame = frame.parentElement
            ) {
                if (
                    ['auto', 'scroll'].includes(
                        getComputedStyle(frame).overflowX,
                    )
                ) {
                    return true;
                }
            }

            return false;
        };

        const cutOff: string[] = [];

        for (const element of document.body.querySelectorAll('*')) {
            const box = element.getBoundingClientRect();

            // One pixel or less: an element hidden on purpose, such as the
            // native input behind a drawn checkbox.
            if (
                box.width <= 1 ||
                box.height <= 1 ||
                (box.left >= -1 && box.right <= width + 1) ||
                getComputedStyle(element).visibility === 'hidden' ||
                scrollsInside(element)
            ) {
                continue;
            }

            cutOff.push(
                `<${element.tagName.toLowerCase()}> "${(element.textContent ?? '').trim().slice(0, 40)}" from ${Math.round(box.left)} to ${Math.round(box.right)}`,
            );
        }

        return {
            pageScrolls: document.documentElement.scrollWidth > width,
            cutOff: cutOff.slice(0, 5),
        };
    });

const settled = async (page: Page): Promise<void> => {
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
};

// The table of the addresses of a subnet is the widest of the application.
// On the screen of a laptop it must hold in its frame: an editor reaches
// the actions of a line without scrolling sideways.
test.describe('the addresses of a subnet on a screen 1280 pixels wide', () => {
    test.use({ viewport: { width: 1280, height: 900 } });

    // A subnet with many addresses, one whose addresses belong to devices,
    // and one with a long description and a deviation under a host name.
    for (const cidr of ['10.10.10.0/24', '10.10.99.0/24', '10.20.60.0/24']) {
        test(`the table of ${cidr} holds in its frame, actions included`, async ({
            page,
            logIn,
        }) => {
            await logIn('editor');
            await openSubnet(page, cidr);
            await settled(page);

            const table = addressTable(page);
            const frame = table.locator('xpath=ancestor::div[1]');

            await expect(table).toBeVisible();

            const { needed, available } = await frame.evaluate((element) => ({
                needed: element.scrollWidth,
                available: element.clientWidth,
            }));

            expect(needed, 'width the table needs').toBeLessThanOrEqual(
                available,
            );

            // The last column, with the actions of each line.
            const actions = dataRows(table)
                .first()
                .getByRole('button', { name: 'Supprimer' });

            await expect(actions).toBeInViewport({ ratio: 1 });

            const edge = await actions.evaluate(
                (element) => element.getBoundingClientRect().right,
            );
            const frameEdge = await frame.evaluate(
                (element) => element.getBoundingClientRect().right,
            );

            expect(edge).toBeLessThanOrEqual(frameEdge);
        });
    }
});

// The table of the ports of a device is as dense: a stack with its
// modes, its aggregates, its links and its descriptions holds in its frame
// too, and so does a core switch, most of whose ports named here are
// linked.
test.describe('the ports of a device on a screen 1280 pixels wide', () => {
    test.use({ viewport: { width: 1280, height: 900 } });

    for (const name of [
        'sw-serveurs',
        'sw-etage-1',
        'fw-siege',
        'sw-coeur-01',
    ]) {
        test(`the table of the ports of ${name} holds in its frame, actions included`, async ({
            page,
            logIn,
        }) => {
            await logIn('editor');
            await page.goto('/devices');
            await page.getByRole('link', { name, exact: true }).click();
            await expect(
                page.getByRole('heading', { name, exact: true }),
            ).toBeVisible();
            await settled(page);

            const table = page.getByRole('table', {
                name: 'Ports',
                exact: true,
            });
            const frame = table.locator('xpath=ancestor::div[1]');

            await expect(table).toBeVisible();

            const { needed, available } = await frame.evaluate((element) => ({
                needed: element.scrollWidth,
                available: element.clientWidth,
            }));

            expect(needed, 'width the table needs').toBeLessThanOrEqual(
                available,
            );

            // The last column, with the actions of each line.
            const actions = page
                .locator('[data-test="port-row"]')
                .first()
                .getByRole('button', { name: 'Supprimer' });

            await actions.scrollIntoViewIfNeeded();
            await expect(actions).toBeInViewport({ ratio: 1 });

            const edge = await actions.evaluate(
                (element) => element.getBoundingClientRect().right,
            );
            const frameEdge = await frame.evaluate(
                (element) => element.getBoundingClientRect().right,
            );

            expect(edge).toBeLessThanOrEqual(frameEdge);

            // The page itself does not scroll sideways, and nothing of it
            // is cut off.
            expect(await sideways(page), name).toEqual({
                pageScrolls: false,
                cutOff: [],
            });
        });
    }
});

// The tables of links: the one of a core switch, with its deviations and
// its aggregated link open, and the list of every link, with its two ends
// in full.
test.describe('the links on a screen 1280 pixels wide', () => {
    test.use({ viewport: { width: 1280, height: 900 } });

    const holds = async (page: Page): Promise<void> => {
        const table = page.getByRole('table', {
            name: 'Liaisons',
            exact: true,
        });
        const frame = table.locator('xpath=ancestor::div[1]');

        await expect(table).toBeVisible();
        await settled(page);

        const { needed, available } = await frame.evaluate((element) => ({
            needed: element.scrollWidth,
            available: element.clientWidth,
        }));

        expect(needed, 'width the table needs').toBeLessThanOrEqual(available);

        // The last column, with the actions of each line.
        const actions = table
            .locator('[data-test="link-row"]')
            .first()
            .getByRole('button', { name: 'Supprimer' });

        await actions.scrollIntoViewIfNeeded();
        await expect(actions).toBeInViewport({ ratio: 1 });

        const edge = await actions.evaluate(
            (element) => element.getBoundingClientRect().right,
        );
        const frameEdge = await frame.evaluate(
            (element) => element.getBoundingClientRect().right,
        );

        expect(edge).toBeLessThanOrEqual(frameEdge);

        // The page itself does not scroll sideways, and nothing of it is
        // cut off.
        expect(await sideways(page)).toEqual({
            pageScrolls: false,
            cutOff: [],
        });
    };

    test('the table of the links of sw-coeur-01 holds in its frame, actions included', async ({
        page,
        logIn,
    }) => {
        await logIn('editor');
        await page.goto('/devices');
        await page
            .getByRole('link', { name: 'sw-coeur-01', exact: true })
            .click();
        await expect(
            page.getByRole('heading', { name: 'sw-coeur-01', exact: true }),
        ).toBeVisible();
        await page.locator('[data-test="toggle-link-Po1"]').click();

        await holds(page);
    });

    test('the list of every link holds in its frame, actions included', async ({
        page,
        logIn,
    }) => {
        await logIn('editor');
        await page.goto('/links');

        await holds(page);
    });
});

// The jacks and the ports of a panel: a path can be long, and goes to
// the next line rather than push the actions of a line out of sight.
test.describe('the cabling on a screen 1280 pixels wide', () => {
    test.use({ viewport: { width: 1280, height: 900 } });

    const holds = async (
        page: Page,
        name: string,
        row: string,
    ): Promise<void> => {
        const table = page.getByRole('table', { name, exact: true });
        const frame = table.locator('xpath=ancestor::div[1]');

        await expect(table).toBeVisible();
        await settled(page);

        const { needed, available } = await frame.evaluate((element) => ({
            needed: element.scrollWidth,
            available: element.clientWidth,
        }));

        expect(needed, 'width the table needs').toBeLessThanOrEqual(available);

        // The last column, with the actions of each line.
        const actions = table
            .locator(`[data-test="${row}"]`)
            .first()
            .locator('td')
            .last()
            .getByRole('button')
            .last();

        await actions.scrollIntoViewIfNeeded();
        await expect(actions).toBeInViewport({ ratio: 1 });

        const edge = await actions.evaluate(
            (element) => element.getBoundingClientRect().right,
        );
        const frameEdge = await frame.evaluate(
            (element) => element.getBoundingClientRect().right,
        );

        expect(edge).toBeLessThanOrEqual(frameEdge);

        expect(await sideways(page)).toEqual({
            pageScrolls: false,
            cutOff: [],
        });
    };

    test('the list of jacks holds in its frame, actions included', async ({
        page,
        logIn,
    }) => {
        await logIn('editor');
        await page.goto('/jacks');

        await holds(page, 'Prises', 'jack-row');
    });

    test('the ports of a panel hold in their frame, actions included', async ({
        page,
        logIn,
    }) => {
        await logIn('editor');
        await page.goto('/sites');
        await page.getByRole('link', { name: 'Siège', exact: true }).click();
        await page
            .locator('[data-test="place-panel"][data-panel="PB-B"]')
            .getByRole('link', { name: 'PB-B', exact: true })
            .click();

        await holds(page, 'Ports', 'panel-port-row');
    });
});

// The windows of a cord on a telephone: nothing goes past the screen, and
// nothing in them has to be scrolled sideways.
test.describe('the windows of a cord on a screen 360 pixels wide', () => {
    test.use({ viewport: { width: 360, height: 844 } });

    const holds = async (page: Page, window: string): Promise<void> => {
        const dialog = page.locator(window);

        await expect(dialog).toBeVisible();
        await settled(page);
        // Opened, a window fades in: it is measured once it is still.
        await page.waitForTimeout(500);

        const { needed, available } = await dialog.evaluate((element) => ({
            needed: element.scrollWidth,
            available: element.clientWidth,
        }));

        expect(needed, `${window} needs`).toBeLessThanOrEqual(available);
        expect(await sideways(page), window).toEqual({
            pageScrolls: false,
            cutOff: [],
        });
    };

    test('the window that changes a cord and the one that unpatches the ports ticked hold', async ({
        page,
        logIn,
    }) => {
        await logIn('editor');
        await page.goto('/sites');
        await page.getByRole('link', { name: 'Siège', exact: true }).click();
        await page
            .locator('[data-test="place-panel"][data-panel="PB-B"]')
            .getByRole('link', { name: 'PB-B', exact: true })
            .click();

        await page.locator('[data-test="edit-cord-1"]').click();
        await holds(page, '[data-test="patch-cord-edit-dialog"]');
        await page
            .locator('[data-test="patch-cord-edit-dialog"]')
            .getByRole('button', { name: 'Annuler' })
            .click();

        for (const number of [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]) {
            await page
                .locator(`[data-test="select-panel-port-${number}"]`)
                .check();
        }

        await page.locator('[data-test="unpatch-series"]').click();
        await holds(page, '[data-test="confirm-dialog"]');
        await page
            .locator('[data-test="confirm-dialog"]')
            .getByRole('button', { name: 'Annuler' })
            .click();
    });
});

// The windows that plug a device into a jack, that change its cord and
// that plug a port into a jack, from the smallest screen to the widest.
for (const width of WIDTHS) {
    test.describe(`the windows of a jack cord on a screen ${width} pixels wide`, () => {
        test.use({ viewport: { width, height: width < 1024 ? 844 : 900 } });

        const holds = async (page: Page, window: string): Promise<void> => {
            const dialog = page.locator(window);

            await expect(dialog).toBeVisible();
            await settled(page);
            // Opened, a window fades in: it is measured once it is still.
            await page.waitForTimeout(500);

            const { needed, available } = await dialog.evaluate((element) => ({
                needed: element.scrollWidth,
                available: element.clientWidth,
            }));

            expect(needed, `${window} needs`).toBeLessThanOrEqual(available);
            expect(await sideways(page), window).toEqual({
                pageScrolls: false,
                cutOff: [],
            });

            await dialog.getByRole('button', { name: 'Annuler' }).click();
            await expect(dialog).toBeHidden();
        };

        test('the windows of a jack cord hold', async ({ page, logIn }) => {
            await logIn('editor');

            // From the line of a port of a device.
            await page.goto('/devices');
            await page
                .getByRole('link', { name: 'sw-etage-2', exact: true })
                .click();
            await page.locator('[data-test="plug-port-1/0/22"]').click();
            await holds(page, '[data-test="jack-plug-dialog"]');

            // From the line of a jack, free, then with a device plugged.
            await page.goto('/jacks');
            await page
                .locator('[data-test="jack-row"][data-jack="C.12"]')
                .getByRole('button', {
                    name: 'Brancher un équipement sur C.12',
                })
                .click();
            await holds(page, '[data-test="jack-device-plug-dialog"]');

            await page
                .locator('[data-test="jack-row"][data-jack="B.13"]')
                .getByRole('button', {
                    name: 'Modifier le cordon de prise de B.13',
                })
                .click();
            await holds(page, '[data-test="jack-cord-edit-dialog"]');
        });
    });
}

for (const width of WIDTHS) {
    test.describe(`on a screen ${width} pixels wide`, () => {
        test.use({ viewport: { width, height: width < 1024 ? 844 : 900 } });

        test('no screen scrolls sideways and nothing is cut off', async ({
            page,
            logIn,
        }) => {
            await page.goto('/login');
            await expect(
                page.getByRole('button', { name: 'Se connecter' }),
            ).toBeVisible();
            await settled(page);
            expect(await sideways(page), '/login').toEqual({
                pageScrolls: false,
                cutOff: [],
            });

            await logIn('admin');

            for (const screen of SCREENS) {
                await page.goto(screen);
                await expect(page.getByRole('heading').first()).toBeVisible();
                await settled(page);

                expect(await sideways(page), screen).toEqual({
                    pageScrolls: false,
                    cutOff: [],
                });
            }

            // The pages of one object, reached from their lists: a site,
            // an entry of the catalogue, a block and a device.
            for (const [list, link, heading] of [
                ['/sites', 'Siège', 'Site Siège'],
                ['/catalog-vlans', 'Bureautique', /Entrée 10 — Bureautique/],
                ['/address-blocks', '10.10.0.0/16', '10.10.0.0/16'],
                // A stack, with its members, its aggregates and its ports.
                ['/devices', 'sw-serveurs', 'sw-serveurs'],
            ] as const) {
                await page.goto(list);
                await page
                    .getByRole('link', { name: link, exact: true })
                    .first()
                    .click();
                await expect(
                    page.getByRole('heading', { name: heading }).first(),
                ).toBeVisible();
                await settled(page);

                expect(await sideways(page), `${list} › ${link}`).toEqual({
                    pageScrolls: false,
                    cutOff: [],
                });
            }

            // The busiest screen: the page of a subnet, with its address
            // plan and its table, then with the quick entry open.
            await openSubnet(page, '10.10.10.0/24');
            await settled(page);
            expect(await sideways(page), 'subnet').toEqual({
                pageScrolls: false,
                cutOff: [],
            });

            await page
                .getByRole('button', { name: 'Ajouter plusieurs adresses' })
                .click();
            await expect(
                page.getByRole('region', {
                    name: 'Ajouter plusieurs adresses',
                }),
            ).toBeVisible();
            expect(await sideways(page), 'quick entry').toEqual({
                pageScrolls: false,
                cutOff: [],
            });

            // An error page too.
            await page.goto('/an-address-that-does-not-exist');
            await settled(page);
            expect(await sideways(page), 'error page').toEqual({
                pageScrolls: false,
                cutOff: [],
            });
        });

        test('the home page, the search window and the page of results hold', async ({
            page,
            logIn,
        }) => {
            await logIn('admin');

            // Every module of the home page, the recent activity included.
            await expect(
                page.getByRole('region', { name: 'Activité récente' }),
            ).toBeVisible();
            await settled(page);
            expect(await sideways(page), 'home').toEqual({
                pageScrolls: false,
                cutOff: [],
            });

            // The window, with results of several groups.
            await page
                .getByRole('button', {
                    name: 'Rechercher dans la documentation',
                })
                .click();

            const window = page.getByRole('dialog', {
                name: 'Rechercher dans la documentation',
            });

            await window.getByRole('combobox').fill('sw-');
            await expect(window.getByRole('option').first()).toBeVisible();
            await settled(page);
            expect(await sideways(page), 'search window').toEqual({
                pageScrolls: false,
                cutOff: [],
            });

            // On a telephone, the window takes the whole screen.
            if (width < 640) {
                const box = await window.boundingBox();

                expect(box?.width).toBe(width);
            }

            await page.keyboard.press('Escape');

            await page.goto('/recherche?q=10.10.');
            await expect(
                page.getByRole('heading', {
                    name: 'Résultats pour « 10.10. »',
                }),
            ).toBeVisible();
            await settled(page);
            expect(await sideways(page), 'page of results').toEqual({
                pageScrolls: false,
                cutOff: [],
            });
        });

        if (width >= 1024) {
            test('the sidebar is part of the page', async ({ page, logIn }) => {
                await logIn('admin');

                const navigation = page.getByRole('navigation', {
                    name: 'Navigation principale',
                });

                await expect(navigation).toBeVisible();
                await navigation
                    .getByRole('button', {
                        name: 'Réseau logique',
                        exact: true,
                    })
                    .click();
                await expect(
                    navigation.getByRole('link', { name: 'Sous-réseaux' }),
                ).toBeVisible();
                await expect(
                    page.getByRole('button', { name: /^Admin de test/ }),
                ).toBeVisible();
            });
        } else {
            test('the sidebar is folded away and opens over the page', async ({
                page,
                logIn,
            }) => {
                await logIn('admin');

                await expect(
                    page.getByRole('navigation', {
                        name: 'Navigation principale',
                    }),
                ).toBeHidden();

                await page
                    .getByRole('button', {
                        name: 'Afficher ou masquer le menu latéral',
                    })
                    .click();

                const panel = page.getByRole('dialog');

                await expect(
                    panel.getByRole('navigation', {
                        name: 'Navigation principale',
                    }),
                ).toBeVisible();
                await expect(panel.getByText('Admin de test')).toBeVisible();
                expect((await sideways(page)).pageScrolls).toBe(false);

                // An entry leads to its page, where the plan and the table
                // scroll inside their own frame.
                await panel
                    .getByRole('button', {
                        name: 'Réseau logique',
                        exact: true,
                    })
                    .click();
                await panel.getByRole('link', { name: 'Sous-réseaux' }).click();
                await expect(
                    page.getByRole('heading', { name: 'Sous-réseaux' }),
                ).toBeVisible();

                await openSubnet(page, '10.10.10.0/24');

                const plan = page
                    .getByRole('region', { name: "Plan d'adressage" })
                    .getByRole('group', { name: "Plan d'adressage" });

                const frame = await plan.evaluate((node) => ({
                    scrolls: getComputedStyle(node).overflowX,
                    content: node.scrollWidth,
                    visible: node.clientWidth,
                }));

                expect(frame.scrolls).toBe('auto');
                expect(frame.content).toBeGreaterThan(frame.visible);
                expect(frame.visible).toBeLessThanOrEqual(width);
            });
        }
    });
}
