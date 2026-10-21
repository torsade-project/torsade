// torsade/tests/e2e/screenshots.spec.ts
import path from 'node:path';
import type { Page } from '@playwright/test';
import { projectRoot } from './support/environment';
import { expect, openSubnet, test } from './support/fixtures';

// A picture of each main screen in both themes, for a human to look at:
// nothing compares them. They are kept with the results of the run.
const DIRECTORY = path.join(projectRoot, 'test-results', 'screenshots');

const THEMES = ['dark', 'light'] as const;

type Theme = (typeof THEMES)[number];

const capture = async (
    page: Page,
    theme: Theme,
    name: string,
): Promise<void> => {
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);

    // The picture is taken in the theme it is named after.
    expect(
        await page.evaluate(() =>
            document.documentElement.classList.contains('dark'),
        ),
    ).toBe(theme === 'dark');

    await page.screenshot({
        path: path.join(DIRECTORY, theme, `${name}.png`),
        fullPage: true,
        animations: 'disabled',
    });
};

// The address plan as someone who sees no colour sees it: the browser
// renders the page in shades of grey, the way its developer tools do for
// achromatopsia. The states must still be told apart by their shapes.
const captureInGrey = async (
    page: Page,
    theme: Theme,
    name: string,
): Promise<void> => {
    const plan = page.getByRole('region', { name: "Plan d'adressage" });
    const session = await page.context().newCDPSession(page);

    await expect(plan).toBeVisible();
    await session.send('Emulation.setEmulatedVisionDeficiency', {
        type: 'achromatopsia',
    });
    await plan.screenshot({
        path: path.join(DIRECTORY, theme, `${name}.png`),
        animations: 'disabled',
    });
    await session.send('Emulation.setEmulatedVisionDeficiency', {
        type: 'none',
    });
    await session.detach();
};

// The same screens on a telephone, where the sidebar leaves the page.
test.describe('screens on a narrow screen', () => {
    test.use({ viewport: { width: 360, height: 844 } });

    test('login, home, VLANs and subnet at 360 pixels', async ({
        page,
        logIn,
    }) => {
        await page.goto('/login');
        await expect(
            page.getByRole('button', { name: 'Se connecter' }),
        ).toBeVisible();
        await capture(page, 'dark', 'narrow-login');

        await logIn('admin');
        await capture(page, 'dark', 'narrow-home');

        await page.goto('/vlans');
        await expect(page.getByRole('table')).toBeVisible();
        await capture(page, 'dark', 'narrow-vlans');

        await openSubnet(page, '10.10.10.0/24');
        await capture(page, 'dark', 'narrow-subnet');

        // The navigation, opened over the page, with the group of the page
        // open.
        await page
            .getByRole('button', {
                name: 'Afficher ou masquer le menu latéral',
            })
            .click();
        await expect(
            page
                .getByRole('dialog')
                .getByRole('link', { name: 'Sous-réseaux' }),
        ).toBeVisible();
        await capture(page, 'dark', 'narrow-navigation');
    });
});

for (const theme of THEMES) {
    test.describe(`screens in the ${theme} theme`, () => {
        // The theme is a choice of the user: the server reads it in a
        // cookie, the page in the local storage.
        test.beforeEach(async ({ context, page, baseURL }) => {
            await context.addCookies([
                { name: 'appearance', value: theme, url: baseURL },
            ]);
            await page.addInitScript(
                (value) => window.localStorage.setItem('appearance', value),
                theme,
            );
        });

        test('login', async ({ page }) => {
            await page.goto('/login');
            await expect(
                page.getByRole('button', { name: 'Se connecter' }),
            ).toBeVisible();

            await capture(page, theme, 'login');
        });

        test('home, menu, VLANs, subnet, users and activity log', async ({
            page,
            logIn,
        }) => {
            await logIn('admin');

            await page.goto('/dashboard');
            await capture(page, theme, 'home');

            // The menu in its groups, one of them open and the others
            // closed.
            await page.setViewportSize({ width: 1280, height: 1000 });
            await page.waitForLoadState('networkidle');

            const menu = page.getByRole('navigation', {
                name: 'Navigation principale',
            });

            await menu
                .getByRole('button', { name: 'Réseau physique', exact: true })
                .click();
            await expect(
                menu.getByRole('link', { name: 'Liaisons', exact: true }),
            ).toBeVisible();
            await expect(
                menu.getByRole('button', { name: 'Norme', exact: true }),
            ).toHaveAttribute('aria-expanded', 'false');
            await menu.screenshot({
                path: path.join(DIRECTORY, theme, 'menu.png'),
                animations: 'disabled',
            });
            await page.setViewportSize({ width: 1280, height: 720 });

            await page.goto('/vlans');
            await expect(page.getByRole('table')).toBeVisible();
            await capture(page, theme, 'vlans');

            await openSubnet(page, '10.10.10.0/24');
            await capture(page, theme, 'subnet');
            await captureInGrey(page, theme, 'plan-in-grey-office');

            // The network that has a planned and a retired address.
            await openSubnet(page, '10.10.20.0/24');
            await page.waitForLoadState('networkidle');
            await captureInGrey(page, theme, 'plan-in-grey-servers');

            // The network cut into ranges, with its reserved range.
            await openSubnet(page, '10.10.99.0/24');
            await page.waitForLoadState('networkidle');
            await capture(page, theme, 'subnet-with-ranges');
            await captureInGrey(page, theme, 'plan-in-grey-management');

            await page.goto('/users');
            await expect(page.getByRole('table')).toBeVisible();
            await capture(page, theme, 'users');

            await page.goto('/activity-log');
            await expect(page.getByRole('table')).toBeVisible();
            await capture(page, theme, 'activity-log');
        });

        // The home page on a screen and on a telephone, the search window
        // with results, the page of results, and the standard applied to a
        // site with the ranges of each line.
        test('home at 1280 and 360 pixels, search, results and the standard with its ranges', async ({
            page,
            logIn,
        }) => {
            await page.setViewportSize({ width: 1280, height: 900 });
            await logIn('admin');
            await capture(page, theme, 'home-1280');

            await page.setViewportSize({ width: 360, height: 844 });
            await page.reload();
            await expect(
                page.getByRole('region', { name: 'Synthèse' }),
            ).toBeVisible();
            await capture(page, theme, 'home-360');

            await page.setViewportSize({ width: 1280, height: 900 });
            await page.reload();
            await page
                .getByRole('button', {
                    name: 'Rechercher dans la documentation',
                })
                .click();

            const window = page.getByRole('dialog', {
                name: 'Rechercher dans la documentation',
            });

            await window.getByRole('combobox').fill('imp-');
            await expect(window.getByRole('option').first()).toBeVisible();
            await capture(page, theme, 'search-window');
            await page.keyboard.press('Escape');

            await page.goto('/search?q=sw-');
            await expect(
                page.getByRole('heading', { name: 'Résultats pour « sw- »' }),
            ).toBeVisible();
            await capture(page, theme, 'search-results');

            await page.goto('/sites');
            await page
                .getByRole('link', { name: 'Dépôt', exact: true })
                .click();
            await page
                .getByRole('link', { name: 'Appliquer la norme' })
                .click();
            await expect(
                page.locator('[data-test="breakdown-range"]').first(),
            ).toBeVisible();
            await capture(page, theme, 'standard-with-ranges');
        });

        // The physical diagram of the head office, on a screen and on a
        // telephone.
        test('the physical diagram of the head office at 1280 and 360 pixels', async ({
            page,
            logIn,
        }) => {
            await page.setViewportSize({ width: 1280, height: 900 });
            await logIn('admin');

            await page.goto('/diagrams/physical');
            await page
                .getByLabel('Site', { exact: true })
                .selectOption({ label: 'Siège' });
            await expect(
                page.getByRole('heading', {
                    name: 'Schéma physique du site Siège',
                }),
            ).toBeVisible();

            const firewall = page.locator(
                '[data-test="diagram"] a[data-device="fw-siege"]',
            );

            // With its patch panels, the jacks of its cabling and the
            // printer cabled by its address; then without the end devices.
            await expect(firewall).toBeVisible();
            await expect(
                page.locator('[data-test="diagram"] a[data-panel="PB-B"]'),
            ).toBeVisible();
            await capture(page, theme, 'diagram-physical');

            await page.locator('[data-test="show-end-devices"]').uncheck();
            await expect(
                page.locator(
                    '[data-test="diagram"] a[data-device="imp-compta"]',
                ),
            ).toHaveCount(0);
            await expect(firewall).toBeVisible();
            await capture(page, theme, 'diagram-physical-without-end-devices');
            await page.locator('[data-test="show-end-devices"]').check();
            await expect(
                page.locator(
                    '[data-test="diagram"] a[data-device="imp-compta"]',
                ),
            ).toBeVisible();

            // The deviations of the diagram, listed under it.
            const deviations = page.getByRole('region', {
                name: 'Écarts du schéma',
            });

            await expect(
                deviations.locator('[data-test="deviation-item"]').first(),
            ).toBeVisible();
            await deviations.screenshot({
                path: path.join(DIRECTORY, theme, 'diagram-deviations.png'),
                animations: 'disabled',
            });

            // The diagram while its devices are placed by hand: the bar
            // above it, the grid, a device moved.
            await page.locator('[data-test="edit-layout"]').click();
            await expect(
                page.locator('[data-test="layout-bar"]'),
            ).toBeVisible();
            await page
                .locator('[data-test="diagram"] a[data-device="sw-etage-1"]')
                .focus();
            await page.keyboard.press('Shift+ArrowRight');
            await expect(page.locator('[data-test="layout-state"]')).toHaveText(
                'Des déplacements ne sont pas encore enregistrés.',
            );
            await capture(page, theme, 'diagram-edit');
            await page.locator('[data-test="cancel-layout"]').click();
            await expect(page.locator('[data-test="layout-bar"]')).toBeHidden();

            await page.setViewportSize({ width: 360, height: 844 });
            await expect(firewall).toBeVisible();
            await capture(page, theme, 'diagram-physical-narrow');
        });

        // The logical diagram of the head office: its subnets, the
        // internet and its devices, the gateways tagged.
        test('the logical diagram of the head office at 1280 and 360 pixels', async ({
            page,
            logIn,
        }) => {
            await page.setViewportSize({ width: 1280, height: 1000 });
            await logIn('admin');

            await page.goto('/diagrams/logical');
            await page
                .getByLabel('Site', { exact: true })
                .selectOption({ label: 'Siège' });
            await expect(
                page.getByRole('heading', {
                    name: 'Schéma logique du site Siège',
                }),
            ).toBeVisible();

            const firewall = page.locator(
                '[data-test="diagram"] a[data-device="fw-siege"]',
            );

            await expect(firewall).toBeVisible();
            await capture(page, theme, 'diagram-logical');

            await page.setViewportSize({ width: 360, height: 844 });
            await expect(firewall).toBeVisible();
            await capture(page, theme, 'diagram-logical-narrow');
        });

        // The window that plugs a port of a switch into a wall jack: the
        // free jacks of its site, by floor, each with its path.
        test('the window that plugs a port into a jack', async ({
            page,
            logIn,
        }) => {
            await page.setViewportSize({ width: 1280, height: 900 });
            await logIn('admin');

            await page.goto('/devices');
            await page
                .getByRole('link', { name: 'sw-etage-2', exact: true })
                .click();
            await page.locator('[data-test="plug-port-1/0/22"]').click();

            const window = page.locator('[data-test="jack-plug-dialog"]');

            await expect(
                window.locator('[data-test="pluggable-jack"]').first(),
            ).toBeVisible();
            await capture(page, theme, 'jack-plug-window');
            await window.getByRole('button', { name: 'Annuler' }).click();
        });

        // The window of an aggregate of the firewall of the head office,
        // with the VLANs of the workshop opened and ticked.
        test('the window of an aggregate with the VLANs of another site', async ({
            page,
            logIn,
        }) => {
            await page.setViewportSize({ width: 1280, height: 1000 });
            await logIn('admin');

            await page.goto('/devices');
            await page
                .getByRole('link', { name: 'fw-siege', exact: true })
                .click();
            await page.locator('[data-test="add-aggregate"]').click();

            const dialog = page.locator('[data-test="aggregate-dialog"]');
            const workshop = dialog
                .locator('[data-test^="aggregate-site-"]')
                .filter({ hasText: 'Atelier' });

            await dialog.getByLabel('Nom', { exact: true }).fill('Po2');
            await dialog
                .getByRole('checkbox', { name: 'port5', exact: true })
                .check();
            await dialog
                .getByRole('checkbox', { name: 'port6', exact: true })
                .check();
            await dialog.getByLabel('Mode').selectOption({ label: 'Trunk' });
            await workshop.getByRole('button', { name: /^Atelier/ }).click();
            await workshop.getByRole('button', { name: 'Tous' }).click();
            await expect(
                workshop.getByRole('checkbox', { name: '60 — Production' }),
            ).toBeChecked();

            // The window alone, its list of tagged VLANs scrolled to the
            // group of the workshop.
            await workshop
                .getByRole('checkbox', { name: '150 — Vidéosurveillance' })
                .scrollIntoViewIfNeeded();
            await page.evaluate(() => document.fonts.ready);
            await dialog.screenshot({
                path: path.join(
                    DIRECTORY,
                    theme,
                    'aggregate-window-other-site.png',
                ),
                animations: 'disabled',
            });
        });

        // The window of the aggregate missing in front of the stack of the
        // servers, filled in from its deviation. The aggregate of the core
        // switch is deleted for it, then saved again as it was.
        test('the window of the aggregate in front, filled in', async ({
            page,
            logIn,
        }) => {
            await page.setViewportSize({ width: 1280, height: 1000 });
            await logIn('admin');

            await page.goto('/devices');
            await page
                .getByRole('link', { name: 'sw-coeur-01', exact: true })
                .click();
            await page.locator('[data-test="delete-aggregate-Po1"]').click();
            await page
                .getByRole('dialog')
                .filter({ hasText: 'Supprimer cet agrégat ?' })
                .getByRole('button', { name: 'Supprimer' })
                .click();
            await expect(page.getByText('Agrégat supprimé.')).toBeVisible();

            await page.goto('/links?deviations=1');
            await page
                .locator(
                    '[data-test="link-row"][data-link="sw-serveurs › 1/1/1"] [data-test="create-opposite-aggregate"]',
                )
                .click();

            const dialog = page.locator('[data-test="aggregate-dialog"]');

            await expect(dialog.getByLabel('Mode')).toHaveValue('trunk');

            // The list of ports scrolled to the two ports ticked, the window
            // itself kept at its top.
            await dialog
                .locator('[data-test="aggregate-ports"]')
                .evaluate((list) => {
                    const ticked = list.querySelector('input:checked');

                    list.scrollTop =
                        (ticked?.getBoundingClientRect().top ?? 0) -
                        list.getBoundingClientRect().top -
                        48;
                });
            await page.evaluate(() => document.fonts.ready);
            await dialog.screenshot({
                path: path.join(
                    DIRECTORY,
                    theme,
                    'aggregate-window-in-front.png',
                ),
                animations: 'disabled',
            });

            await dialog
                .getByLabel('Description')
                .fill('Vers sw-serveurs : un lien vers chacun de ses membres');
            await dialog.locator('[data-test="save-aggregate"]').click();
            await expect(page.getByText('Agrégat créé.')).toBeVisible();
        });

        // Beyond the main screens: one of each other kind, to check that
        // the same rules apply everywhere.
        test('lists, forms, windows, settings and error page', async ({
            page,
            logIn,
        }) => {
            await logIn('admin');

            await page.goto('/sites');
            await expect(page.getByRole('table')).toBeVisible();
            await capture(page, theme, 'other-sites');

            await page.getByRole('button', { name: 'Nouveau site' }).click();
            await expect(
                page.getByRole('dialog', { name: 'Nouveau site' }),
            ).toBeVisible();
            await capture(page, theme, 'other-site-window');

            // The structure of the addressing: a site, the catalogue and
            // one of its entries, the blocks and one of them.
            await page.getByRole('button', { name: 'Annuler' }).click();
            await page
                .getByRole('link', { name: 'Siège', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: 'Site Siège' }),
            ).toBeVisible();
            await capture(page, theme, 'structure-site');

            // The places and the devices: a site with its buildings,
            // floors and rooms, the list of devices, a device whose second
            // interface is on another site, and the page that turns host
            // names into devices.
            await page.goto('/sites');
            await page
                .getByRole('link', { name: 'Atelier', exact: true })
                .click();
            await expect(
                page.locator('[data-test="place-room"]').first(),
            ).toBeVisible();
            await capture(page, theme, 'places-site');

            await page
                .getByRole('link', {
                    name: "Créer des équipements depuis les noms d'hôte",
                })
                .click();
            await expect(
                page.locator('[data-test="hostname-lines"]'),
            ).toBeVisible();
            await capture(page, theme, 'places-devices-from-hostnames');

            await page.goto('/devices');
            await expect(page.getByRole('table')).toBeVisible();
            await capture(page, theme, 'places-devices');

            await page
                .getByRole('link', { name: 'srv-sauvegarde-01', exact: true })
                .click();
            await expect(
                page.locator('[data-test="device-address-row"]'),
            ).toHaveCount(2);
            await capture(page, theme, 'places-device');

            // What switches are made of: the list of templates, the form
            // of a template with the names it gives to ports, a stack with
            // its members, its aggregates and its ports, and the window of
            // a port in trunk mode.
            await page.goto('/device-templates');
            await expect(page.getByRole('table')).toBeVisible();
            await capture(page, theme, 'switch-templates');

            await page
                .getByRole('link', {
                    name: 'Switch 24 ports PoE+ et 4 SFP+',
                    exact: true,
                })
                .click();
            await expect(
                page.locator('[data-test="template-preview"]'),
            ).toContainText('28 ports');
            await capture(page, theme, 'switch-template-form');

            await page.goto('/devices');
            await page
                .getByRole('link', { name: 'sw-serveurs', exact: true })
                .click();
            await expect(page.locator('[data-test="port-row"]')).toHaveCount(
                56,
            );
            await capture(page, theme, 'switch-stack');

            await page.locator('[data-test="edit-port-1/0/9"]').click();
            await expect(
                page
                    .locator('[data-test="port-dialog"]')
                    .getByLabel('VLAN natif'),
            ).toBeVisible();
            await capture(page, theme, 'switch-port-trunk');
            await page
                .locator('[data-test="port-dialog"]')
                .getByRole('button', { name: 'Annuler' })
                .click();

            // What is plugged into what: the list of every link, the links
            // of a core switch with its aggregated link open, and the
            // window that links a port to a port of another device.
            await page.goto('/links');
            await expect(
                page.locator('[data-test="link-row"]').first(),
            ).toBeVisible();
            await capture(page, theme, 'links-list');

            await page.goto('/devices');
            await page
                .getByRole('link', { name: 'sw-coeur-01', exact: true })
                .click();
            await page.locator('[data-test="toggle-link-Po1"]').click();
            await expect(
                page.locator(
                    '[data-test="device-links"] [data-test="link-row"][data-link="1/1/2"]',
                ),
            ).toBeVisible();
            await capture(page, theme, 'links-device');

            await page.locator('[data-test="link-port-1/0/2"]').click();

            const linking = page.locator('[data-test="link-dialog"]');

            await linking.locator('[data-test="link-search"]').fill('etage');
            await linking
                .locator('[data-test="choose-device-sw-etage-1"]')
                .click();
            await linking
                .locator('[data-test="opposite-port"][data-port="1/1/3"] input')
                .check();
            await capture(page, theme, 'links-dialog');
            await linking.getByRole('button', { name: 'Annuler' }).click();

            // The cabling: the list of the jacks with their paths, the page
            // of a panel, and the window that labels jacks as a series.
            await page.goto('/jacks');
            await expect(
                page.locator('[data-test="jack-row"]').first(),
            ).toBeVisible();
            await capture(page, theme, 'cabling-jacks');

            await page.goto('/sites');
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
            await capture(page, theme, 'cabling-panel');

            await page.goto('/sites');
            await page
                .getByRole('link', { name: 'Siège', exact: true })
                .click();
            await page
                .locator('[data-test="place-floor"]')
                .filter({ has: page.getByText('1er étage', { exact: true }) })
                .locator('[data-test^="create-jacks-"]')
                .click();

            const series = page.locator('[data-test="jack-series-dialog"]');

            await series.locator('[data-test="jacks-prefix"]').fill('B.');
            await series.locator('[data-test="jacks-first"]').fill('17');
            await series.locator('[data-test="jacks-last"]').fill('24');
            await series.locator('[data-test="jacks-panel"]').selectOption({
                label: 'PB-B — Bâtiment principal › 1er étage › Placard technique',
            });
            await series.locator('[data-test="jacks-first-port"]').fill('17');
            await expect(
                series.locator('[data-test="jacks-preview-line"]'),
            ).toHaveCount(8);
            await capture(page, theme, 'cabling-jack-series');
            await series.getByRole('button', { name: 'Annuler' }).click();

            // The window that changes a cord, and the confirmation of the
            // ports unpatched as a series; both are left without saving.
            await page
                .locator('[data-test="place-panel"][data-panel="PB-B"]')
                .getByRole('link', { name: 'PB-B', exact: true })
                .click();
            await page.locator('[data-test="edit-cord-1"]').click();
            await expect(
                page.locator('[data-test="patch-cord-edit-dialog"]'),
            ).toBeVisible();
            await capture(page, theme, 'cabling-cord-edit');
            await page
                .locator('[data-test="patch-cord-edit-dialog"]')
                .getByRole('button', { name: 'Annuler' })
                .click();

            for (const number of [1, 2, 3]) {
                await page
                    .locator(`[data-test="select-panel-port-${number}"]`)
                    .check();
            }

            await page.locator('[data-test="unpatch-series"]').click();
            await expect(
                page.getByRole('dialog').filter({
                    hasText: 'Débrasser les ports cochés ?',
                }),
            ).toBeVisible();
            await capture(page, theme, 'cabling-unpatch-series');
            await page
                .getByRole('dialog')
                .getByRole('button', { name: 'Annuler' })
                .click();

            await page.goto('/catalog-vlans');
            await expect(page.getByRole('table')).toBeVisible();
            await capture(page, theme, 'structure-catalogue');

            await page
                .getByRole('link', { name: 'Vidéosurveillance', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: /Entrée 50/ }),
            ).toBeVisible();
            await capture(page, theme, 'structure-catalogue-entry');

            await page.goto('/address-blocks');
            await expect(page.getByRole('table')).toBeVisible();
            await capture(page, theme, 'structure-blocks');

            await page
                .getByRole('link', { name: '10.10.0.0/16', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: '10.10.0.0/16' }),
            ).toBeVisible();
            await capture(page, theme, 'structure-block');

            // The form of that block, which says how many subnets of the
            // site the block would hold.
            await page.locator('[data-test="edit-block"]').click();
            await expect(
                page.getByRole('heading', { name: 'Modifier le bloc' }),
            ).toBeVisible();
            await expect(
                page.locator('[data-test="block-coverage"]'),
            ).toContainText('sous-réseaux du site Siège');
            await capture(page, theme, 'structure-block-form');

            // The addressing rule: its form, what it gives on an example,
            // and how it applies to each site.
            await page.goto('/addressing-rule');
            await expect(
                page.locator('[data-test="rule-summary"]'),
            ).toContainText('Exemple : site 20, VLAN 10');
            await capture(page, theme, 'structure-rule');

            // The same form without a level of sites, not saved: what it
            // gives, and what it tells of several sites.
            await page
                .getByLabel('Niveau de site')
                .selectOption({ label: 'Aucun (un seul site)' });
            await expect(
                page.locator('[data-test="rule-summary"]'),
            ).toContainText('Aucun niveau de site.');
            await capture(page, theme, 'structure-rule-without-site-level');

            // The standard applied to the site that is being planned: one
            // line per entry of the catalogue, with what the rule proposes.
            await page.goto('/sites');
            await page
                .getByRole('link', { name: 'Dépôt', exact: true })
                .click();
            await page
                .getByRole('link', { name: 'Appliquer la norme' })
                .click();
            await expect(
                page.getByRole('heading', {
                    name: 'Appliquer la norme au site Dépôt',
                }),
            ).toBeVisible();
            await capture(page, theme, 'structure-standard');

            // The form of a VLAN, with the subnet the rule proposes for
            // its site and its number.
            await page.goto('/vlans/create');
            await page
                .getByLabel('Site', { exact: true })
                .selectOption({ label: 'Dépôt' });
            await page.getByLabel('VID').fill('10');
            await page
                .getByLabel('Créer le sous-réseau de ce VLAN maintenant')
                .check();
            await expect(page.getByLabel('Adresse du réseau')).toHaveValue(
                '10.30.10.0',
            );
            await capture(page, theme, 'structure-vlan-proposal');

            await page.goto('/subnets');
            await expect(page.getByRole('table')).toBeVisible();
            await capture(page, theme, 'other-subnets');

            await page.goto('/vlans/create');
            await page
                .getByLabel('Créer le sous-réseau de ce VLAN maintenant')
                .check();
            await capture(page, theme, 'other-vlan-form');

            // A form refused by the server: the messages under the fields.
            await page.goto('/subnets/create');
            await page.getByLabel('Adresse du réseau').fill('10.10.10.7/24');
            await page.getByLabel('Passerelle').fill('passerelle');
            await page.getByRole('button', { name: 'Enregistrer' }).click();
            await expect(page.getByLabel('Passerelle')).toHaveAttribute(
                'aria-invalid',
                'true',
            );
            await capture(page, theme, 'other-subnet-form-errors');

            await openSubnet(page, '10.20.70.0/24');
            await page
                .getByRole('button', { name: 'Ajouter plusieurs adresses' })
                .click();
            await expect(
                page.getByRole('region', {
                    name: 'Ajouter plusieurs adresses',
                }),
            ).toBeVisible();
            await capture(page, theme, 'other-quick-entry');

            await page.goto('/users/create');
            await capture(page, theme, 'other-user-form');

            await page.goto('/settings/appearance');
            await capture(page, theme, 'other-settings');

            await page.goto('/an-address-that-does-not-exist');
            await capture(page, theme, 'other-error-page');
        });
    });
}
