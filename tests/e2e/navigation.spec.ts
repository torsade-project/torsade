// torsade/tests/e2e/navigation.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// The menu follows the order in which a network is documented: home and the
// sites at the top, then named groups that fold, where by which conventions,
// then what exists. The group of the page shown is open; what the user opens
// or closes is kept by the browser.
const navigation = (scope: Page | Locator): Locator =>
    scope.getByRole('navigation', { name: 'Navigation principale' });

const TOP = ['Accueil', 'Sites'];

const GROUPS = [
    {
        title: 'Norme',
        entries: [
            "Blocs d'adressage",
            "Règle d'adressage",
            'Catalogue des VLAN',
        ],
    },
    {
        title: 'Réseau logique',
        entries: ['VLAN', 'Sous-réseaux', 'Schéma logique'],
    },
    {
        title: 'Réseau physique',
        entries: [
            'Équipements',
            'Liaisons',
            'Schéma physique',
            'Prises',
            "Modèles d'équipement",
        ],
    },
    { title: 'Administration', entries: ['Utilisateurs', 'Journal'] },
];

const ALL = [...TOP, ...GROUPS.flatMap((group) => group.entries)];

const title = (scope: Locator, name: string): Locator =>
    scope.getByRole('button', { name, exact: true });

const entries = async (scope: Locator): Promise<string[]> =>
    (await scope.getByRole('link').allInnerTexts()).map((text) => text.trim());

// The groups open, by their titles, in order.
const openGroups = async (scope: Locator): Promise<string[]> =>
    scope
        .locator('[data-test="nav-group-title"][aria-expanded="true"]')
        .allInnerTexts()
        .then((titles) => titles.map((text) => text.trim()));

const openEvery = async (scope: Locator): Promise<void> => {
    const closed = scope.locator(
        '[data-test="nav-group-title"][aria-expanded="false"]',
    );

    while ((await closed.count()) > 0) {
        await closed.first().click();
    }
};

test('the menu shows home and the sites, then its groups in order, each with its entries', async ({
    page,
    logIn,
}) => {
    await logIn('admin');

    // Each title of a group is a button; on the home page, which belongs
    // to no group, every group is closed at first.
    await expect(
        navigation(page).locator('[data-test="nav-group-title"]'),
    ).toHaveText(GROUPS.map((group) => group.title));
    expect(await openGroups(navigation(page))).toEqual([]);
    expect(await entries(navigation(page))).toEqual(TOP);

    await openEvery(navigation(page));
    expect(await entries(navigation(page))).toEqual(ALL);

    // Each group is a list named by its title, which the title controls.
    for (const group of GROUPS) {
        const button = title(navigation(page), group.title);
        const list = navigation(page).getByRole('list', {
            name: group.title,
            exact: true,
        });

        await expect(button).toHaveAttribute('aria-expanded', 'true');
        await expect(list.getByRole('link')).toHaveText(group.entries);
        expect(await list.getAttribute('id')).toBe(
            await button.getAttribute('aria-controls'),
        );
    }
});

test('a group opens and closes on a click, on Enter and on Space', async ({
    page,
    logIn,
}) => {
    await logIn('admin');

    const button = title(navigation(page), 'Réseau logique');
    const subnets = navigation(page).getByRole('link', {
        name: 'Sous-réseaux',
        exact: true,
    });

    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await expect(subnets).toBeHidden();

    await button.click();
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await expect(subnets).toBeVisible();

    await button.click();
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await expect(subnets).toBeHidden();

    // From the keyboard.
    await button.focus();
    await page.keyboard.press('Enter');
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await expect(subnets).toBeVisible();
    await page.keyboard.press('Space');
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await expect(subnets).toBeHidden();
    await page.keyboard.press('Space');
    await expect(button).toHaveAttribute('aria-expanded', 'true');

    // The entry opens its page, highlighted there, and alone to be.
    await subnets.click();
    await expect(page).toHaveURL(/\/subnets$/);
    await expect(subnets).toHaveAttribute('aria-current', 'page');
    await expect(navigation(page).locator('[aria-current="page"]')).toHaveCount(
        1,
    );
});

test('the group of the page shown is open when it loads', async ({
    page,
    logIn,
}) => {
    await logIn('admin');

    for (const [path, group] of [
        ['/subnets', 'Réseau logique'],
        ['/addressing-rule', 'Norme'],
        ['/jacks', 'Réseau physique'],
        ['/activity-log', 'Administration'],
    ]) {
        await page.goto(path);
        expect(await openGroups(navigation(page))).toEqual([group]);
        await expect(
            navigation(page).locator('[aria-current="page"]'),
        ).toBeVisible();
    }

    // A page below an entry opens its group too: a subnet, under "Subnets".
    await page.goto('/subnets');
    await page.getByRole('link', { name: '10.10.10.0/24' }).first().click();
    await expect(page).toHaveURL(/\/subnets\/\d+$/);
    expect(await openGroups(navigation(page))).toEqual(['Réseau logique']);
});

test('what the user opens or closes is kept from one page to the next', async ({
    page,
    logIn,
}) => {
    await logIn('admin');

    // On the page of the subnets, "Norme" opened and "Réseau logique",
    // the group of the page, closed.
    await page.goto('/subnets');
    await title(navigation(page), 'Norme').click();
    await title(navigation(page), 'Réseau logique').click();
    expect(await openGroups(navigation(page))).toEqual(['Norme']);

    // Another page, reached through the menu, then a reload: "Norme"
    // stays open, the group of the new page opens with it.
    await title(navigation(page), 'Réseau physique').click();
    await navigation(page)
        .getByRole('link', { name: 'Équipements', exact: true })
        .click();
    await expect(page).toHaveURL(/\/devices$/);
    expect(await openGroups(navigation(page))).toEqual([
        'Norme',
        'Réseau physique',
    ]);

    await page.reload();
    expect(await openGroups(navigation(page))).toEqual([
        'Norme',
        'Réseau physique',
    ]);

    // The home page belongs to no group: only the choices of the user.
    await page.goto('/dashboard');
    expect(await openGroups(navigation(page))).toEqual([
        'Norme',
        'Réseau physique',
    ]);
});

test('without storage in the browser, the menu works with the group of the page open', async ({
    page,
    logIn,
}) => {
    // Storage that throws, as it can in a private window or with the data
    // of the site blocked.
    await page.addInitScript(() => {
        const refuse = (): never => {
            throw new DOMException('Storage is disabled', 'SecurityError');
        };

        Storage.prototype.getItem = refuse;
        Storage.prototype.setItem = refuse;
    });

    await logIn('admin');

    await page.goto('/subnets');
    expect(await openGroups(navigation(page))).toEqual(['Réseau logique']);

    const button = title(navigation(page), 'Norme');

    await button.click();
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await expect(
        navigation(page).getByRole('link', { name: "Blocs d'adressage" }),
    ).toBeVisible();

    // Nothing is kept: a reload shows the group of the page alone.
    await page.reload();
    expect(await openGroups(navigation(page))).toEqual(['Réseau logique']);
});

test('a group without an entry the user may open is not shown', async ({
    page,
    logIn,
}) => {
    for (const account of ['editor', 'reader'] as const) {
        await logIn(account);

        await expect(
            navigation(page).locator('[data-test="nav-group-title"]'),
        ).toHaveText(['Norme', 'Réseau logique', 'Réseau physique']);
        await openEvery(navigation(page));
        expect(await entries(navigation(page))).toEqual(
            ALL.filter((entry) => !['Utilisateurs', 'Journal'].includes(entry)),
        );
        await expect(
            navigation(page).getByText('Administration', { exact: true }),
        ).toHaveCount(0);

        await page.context().clearCookies();
    }
});

test('reduced to its icons, the menu shows every entry flat, a line between its groups', async ({
    page,
    logIn,
}) => {
    await logIn('admin');

    // Whatever is open or closed.
    await title(navigation(page), 'Norme').click();

    await page
        .getByRole('button', { name: 'Afficher ou masquer le menu latéral' })
        .click();

    const titles = navigation(page).locator('[data-test="nav-group-title"]');

    await expect(titles).toHaveCount(GROUPS.length);

    for (const button of await titles.all()) {
        await expect(button).toBeHidden();
    }

    await expect(
        navigation(page).locator('[data-test="nav-group-separator"]'),
    ).toHaveCount(GROUPS.length);

    for (const line of await navigation(page)
        .locator('[data-test="nav-group-separator"]')
        .all()) {
        await expect(line).toBeVisible();
    }

    // The entries are all there, in the same order, each named and each
    // with its tooltip.
    const links = navigation(page).getByRole('link');

    await expect(links).toHaveCount(ALL.length);

    for (const [index, name] of ALL.entries()) {
        await expect(links.nth(index)).toBeVisible();
        await expect(links.nth(index)).toHaveAccessibleName(name);
    }

    await links.nth(ALL.indexOf('Liaisons')).hover();
    await expect(page.getByRole('tooltip', { name: 'Liaisons' })).toBeVisible();

    // Opened again, for the tests that follow.
    await page
        .getByRole('button', { name: 'Afficher ou masquer le menu latéral' })
        .click();
    await expect(titles.first()).toBeVisible();
});

// Each entry whole, every group open: a label wider than its place would
// be cut off with an ellipsis.
const cutOff = async (scope: Locator): Promise<string[]> =>
    scope
        .locator('[data-test="nav-entry-label"]')
        .evaluateAll((labels) =>
            labels
                .filter((label) => label.scrollWidth > label.clientWidth)
                .map((label) => label.textContent?.trim() ?? ''),
        );

for (const width of [1024, 1280, 1920]) {
    test(`no entry of the menu is cut off at ${width} pixels`, async ({
        page,
        logIn,
    }) => {
        await page.setViewportSize({ width, height: 900 });
        await logIn('admin');

        // The longest entry, highlighted, beside the light of its page.
        await page.goto('/device-templates');
        await openEvery(navigation(page));
        expect(await entries(navigation(page))).toEqual(ALL);
        expect(await cutOff(navigation(page))).toEqual([]);
    });
}

test('no entry of the menu of a telephone is cut off at 360 pixels', async ({
    page,
    logIn,
}) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await logIn('admin');
    await page.goto('/device-templates');

    await page
        .getByRole('button', { name: 'Afficher ou masquer le menu latéral' })
        .click();

    const panel = navigation(page.getByRole('dialog'));

    await openEvery(panel);
    expect(await entries(panel)).toEqual(ALL);
    expect(await cutOff(panel)).toEqual([]);
});

test('the menu scrolls only when it is taller than the window, with a thin bar', async ({
    page,
    logIn,
}) => {
    await logIn('admin');

    const content = page.locator('[data-sidebar="content"]').first();
    const frame = () =>
        content.evaluate((node) => ({
            overflow: getComputedStyle(node).overflowY,
            scrolls: node.scrollHeight > node.clientHeight,
            thin: node.classList.contains('menu-scrollbar'),
        }));

    // Closed, the groups fit in the window: nothing to scroll.
    await page.setViewportSize({ width: 1280, height: 900 });
    expect(await frame()).toEqual({
        overflow: 'auto',
        scrolls: false,
        thin: true,
    });

    // All open on a short window, the menu scrolls inside the bar.
    await page.setViewportSize({ width: 1280, height: 600 });
    await openEvery(navigation(page));
    expect(await frame()).toEqual({
        overflow: 'auto',
        scrolls: true,
        thin: true,
    });
});
