// torsade/tests/e2e/search.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// The search window, opened from any page of the application.
const searchWindow = (page: Page): Locator =>
    page.getByRole('dialog', { name: 'Rechercher dans la documentation' });

const searchInput = (page: Page): Locator =>
    searchWindow(page).getByRole('combobox', {
        name: 'Rechercher dans la documentation',
    });

// Opens the window from the field of the header and types what is looked
// for, then waits for the results of that text.
const searchFor = async (page: Page, text: string): Promise<void> => {
    if (!(await searchWindow(page).isVisible())) {
        await page
            .getByRole('button', { name: 'Rechercher dans la documentation' })
            .click();
    }

    const answered = page.waitForResponse(
        (response) =>
            response.url().includes('/search/results') &&
            new URL(response.url()).searchParams.get('q') === text,
    );

    await searchInput(page).fill(text);
    await answered;
};

// The options of a group of the window, by the name of the group.
const group = (page: Page, name: string): Locator =>
    searchWindow(page).getByRole('group', { name: new RegExp(`^${name} :`) });

test('"/" and Ctrl+K open the window, Escape closes it, "/" in a field is a character', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await page.keyboard.press('/');
    await expect(searchWindow(page)).toBeVisible();
    await expect(searchInput(page)).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(searchWindow(page)).toBeHidden();

    await page.keyboard.press('Control+k');
    await expect(searchWindow(page)).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(searchWindow(page)).toBeHidden();

    // In the search field of the list of jacks, "/" is typed.
    await page.goto('/jacks');
    const field = page.getByLabel('Rechercher', { exact: true });
    await field.fill('B');
    await field.press('/');

    await expect(field).toHaveValue('B/');
    await expect(searchWindow(page)).toBeHidden();
});

test('Enter before the results of the text typed opens the page of its results', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await searchFor(page, '10.10.10.');
    await expect(
        searchWindow(page).getByRole('option').first(),
    ).toHaveAttribute('aria-selected', 'true');

    // The results shown are those of "10.10.10.": none of them is opened.
    await searchInput(page).press('2');
    await searchInput(page).press('0');
    await searchInput(page).press('Enter');

    await expect(page).toHaveURL(/\/search\?q=10\.10\.10\.20$/);
    await expect(
        page.getByRole('heading', { name: 'Résultats pour « 10.10.10.20 »' }),
    ).toBeVisible();
});

test('a late answer to the text typed over chooses none of its results', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    // The answer to "imp" is held until "imp-" is typed; that of "imp-"
    // until the end of the test.
    let typedOver: () => void = () => {};
    const isTypedOver = new Promise<void>((resolve) => {
        typedOver = resolve;
    });
    let ended: () => void = () => {};
    const hasEnded = new Promise<void>((resolve) => {
        ended = resolve;
    });
    await page.route('**/search/results**', async (route) => {
        const typed = new URL(route.request().url()).searchParams.get('q');

        if (typed === 'imp') {
            const response = await route.fetch();
            await isTypedOver;
            await route.fulfill({ response }).catch(() => undefined);
        } else {
            await hasEnded;
            await route.continue().catch(() => undefined);
        }
    });

    await page
        .getByRole('button', { name: 'Rechercher dans la documentation' })
        .click();
    const asked = page.waitForRequest(
        (request) =>
            request.url().includes('/search/results') &&
            new URL(request.url()).searchParams.get('q') === 'imp',
    );
    await searchInput(page).fill('imp');
    await asked;

    await searchInput(page).press('-');
    typedOver();

    // The results of "imp" are shown, none of them chosen.
    const options = searchWindow(page).getByRole('option');
    await expect(options.first()).toBeVisible();
    await expect(
        searchWindow(page).getByRole('option', { selected: true }),
    ).toHaveCount(0);

    await searchInput(page).press('Enter');
    await expect(page).toHaveURL(/\/search\?q=imp-$/);
    ended();
});

test('a search left before it answered is done again when the window opens again', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    // The answer is held until the window is closed: it never comes.
    let closed: () => void = () => {};
    const isClosed = new Promise<void>((resolve) => {
        closed = resolve;
    });
    await page.route('**/search/results**', async (route) => {
        await isClosed;
        await route.continue().catch(() => undefined);
    });

    await page
        .getByRole('button', { name: 'Rechercher dans la documentation' })
        .click();
    const asked = page.waitForRequest((request) =>
        request.url().includes('/search/results'),
    );
    await searchInput(page).fill('imp-acc');
    await asked;

    // Escape would also empty the field, as it does in any search field.
    await searchWindow(page).getByRole('button', { name: 'Fermer' }).click();
    await expect(searchWindow(page)).toBeHidden();
    closed();
    await page.unroute('**/search/results**');

    await page.keyboard.press('/');
    await expect(searchInput(page)).toHaveValue('imp-acc');
    await expect(searchWindow(page).getByRole('option').first()).toContainText(
        'imp-accueil',
    );
});

test('an address of the demonstration, then Enter, opens its subnet at its line', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await searchFor(page, '10.10.10.20');

    const addresses = group(page, 'Adresses IP');
    await expect(addresses.getByRole('option')).toHaveCount(1);
    await expect(addresses.getByRole('option')).toContainText('imp-accueil');
    // The first result is the active one.
    await expect(addresses.getByRole('option')).toHaveAttribute(
        'aria-selected',
        'true',
    );

    await searchInput(page).press('Enter');

    await expect(page).toHaveURL(/\/subnets\/\d+#address-\d+$/);
    await expect(
        page.getByRole('heading', { name: '10.10.10.0/24' }),
    ).toBeVisible();

    const row = page.locator(new URL(page.url()).hash);
    await expect(row).toContainText('imp-accueil');
    await expect(row).toBeInViewport();
});

test('the VLAN number 10 finds the VLANs of two sites and the catalogue', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await searchFor(page, '10');

    const vlans = group(page, 'VLAN');
    await expect(
        vlans.getByRole('option').filter({ hasText: '10 — Bureautique' }),
    ).toHaveCount(2);
    await expect(vlans.getByRole('option').first()).toContainText('Siège');
    await expect(vlans.getByRole('option').nth(1)).toContainText('Atelier');
    await expect(
        group(page, 'Catalogue des VLAN').getByRole('option').first(),
    ).toContainText('10 — Bureautique');

    // The groups of the forms recognised come first.
    await expect(
        searchWindow(page).getByRole('group').first(),
    ).toHaveAccessibleName(/^VLAN :/);
});

test('a MAC address of the demonstration finds its address', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    // 10.10.10.20, written in capitals with hyphens.
    await searchFor(page, '02-00-0A-0A-0A-14');

    const option = group(page, 'Adresses IP').getByRole('option');
    await expect(option).toHaveCount(1);
    await expect(option).toContainText('10.10.10.20');
    await expect(option).toContainText('02:00:0a:0a:0a:14');
});

test('a device followed by a port opens the page of the device', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await searchFor(page, 'sw-etage-1 1/0/24');

    await expect(
        group(page, 'Ports').getByRole('option').first(),
    ).toContainText('sw-etage-1 1/0/24');

    await searchInput(page).press('Enter');

    await expect(page).toHaveURL(/\/devices\/\d+#port-\d+$/);
    await expect(
        page.getByRole('heading', { name: 'sw-etage-1', exact: true }),
    ).toBeVisible();
    await expect(page.locator(new URL(page.url()).hash)).toBeInViewport();
});

test('an address that is not documented is told, without an action for a reader', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await searchFor(page, '10.10.10.50');
    await expect(searchWindow(page)).toContainText(
        "10.10.10.50 n'est pas documentée.",
    );
    await expect(searchWindow(page)).toContainText(
        'Elle se trouve dans le sous-réseau 10.10.10.0/24',
    );
    await expect(
        searchWindow(page).getByRole('link', {
            name: 'Documenter cette adresse',
        }),
    ).toHaveCount(0);
});

test('an editor documents an address that is not, from the search', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    await searchFor(page, '10.10.10.50');
    await searchWindow(page)
        .getByRole('link', { name: 'Documenter cette adresse' })
        .click();

    // The window of a new address of that subnet, filled in with it.
    const dialog = page.getByRole('dialog', { name: 'Nouvelle adresse IP' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Adresse', { exact: true })).toHaveValue(
        '10.10.10.50',
    );
    await expect(page).toHaveURL(/\/subnets\/\d+\?address=10\.10\.10\.50$/);

    await dialog.getByRole('button', { name: 'Annuler' }).click();
    await expect(
        page.getByRole('heading', { name: '10.10.10.0/24' }),
    ).toBeVisible();
});

test('the keyboard goes through the results, a combobox tells the screen readers', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await searchFor(page, 'imp-');

    const input = searchInput(page);
    const options = searchWindow(page).getByRole('option');

    await expect(input).toHaveAttribute('aria-expanded', 'true');
    await expect(input).toHaveAttribute('aria-controls', 'search-listbox');
    await expect(searchWindow(page).getByRole('listbox')).toBeVisible();
    await expect(searchWindow(page).getByRole('status')).toHaveText(
        /^\d+ résultats?$/,
    );

    const first = await options.first().getAttribute('id');
    const second = await options.nth(1).getAttribute('id');
    await expect(input).toHaveAttribute('aria-activedescendant', first ?? '');

    await input.press('ArrowDown');
    await expect(input).toHaveAttribute('aria-activedescendant', second ?? '');
    await expect(options.nth(1)).toHaveAttribute('aria-selected', 'true');

    await input.press('ArrowUp');
    await expect(input).toHaveAttribute('aria-activedescendant', first ?? '');

    // The part that matches is marked, never by colour alone.
    await expect(options.first().locator('mark').first()).toHaveText('imp-');
});

test('in the window, a status keeps its dot, but a retired one on the option reached', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    // The address replaced by srv-erp-01, retired, after that address.
    await searchFor(page, 'srv-erp-01');
    const retired = group(page, 'Adresses IP')
        .getByRole('option')
        .filter({ hasText: 'srv-intranet-01' });
    const status = retired.locator('[data-status="retired"]');
    const dot = status.locator('[data-test="status-dot"]');
    await expect(retired).toHaveAttribute('aria-selected', 'false');
    await expect(status).toHaveText('Retirée');
    await expect(dot).toBeVisible();
    const before = await status.boundingBox();

    // Reached, it lies on the highlight, where the dot does not read: the
    // dot is hidden, the label stays where it was.
    await retired.hover();
    await expect(retired).toHaveAttribute('aria-selected', 'true');
    await expect(status).toHaveText('Retirée');
    await expect(dot).toBeHidden();
    expect(await status.boundingBox()).toEqual(before);

    // A planned object keeps its ring, reached or not.
    await searchFor(page, 'nas-02');
    const planned = searchWindow(page).getByRole('option').first();
    await expect(planned).toHaveAttribute('aria-selected', 'true');
    await expect(
        planned.locator('[data-status="planned"] [data-test="status-dot"]'),
    ).toBeVisible();
});

test('a search refused or a server that fails is told, the window stays usable', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await page
        .getByRole('button', { name: 'Rechercher dans la documentation' })
        .click();
    await searchInput(page).fill('a'.repeat(101));
    await expect(searchWindow(page)).toContainText(
        'Une recherche contient 100 caractères au plus.',
    );

    await page.route('**/search/results**', (route) =>
        route.fulfill({ status: 500, body: 'Server Error' }),
    );
    await searchInput(page).fill('imprimante');
    await expect(searchWindow(page)).toContainText(
        "La recherche n'a pas abouti. Réessayez dans un instant.",
    );

    await page.unroute('**/search/results**');
    await searchFor(page, 'imp-accueil');
    await expect(
        group(page, 'Adresses IP').getByRole('option').first(),
    ).toContainText('10.10.10.20');
});

test('the page of results shows every group and more results on demand', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await searchFor(page, '10.10.');
    await searchWindow(page)
        .getByRole('link', { name: 'Voir tous les résultats' })
        .click();

    await expect(page).toHaveURL(/\/search\?q=10\.10\.$/);
    await expect(
        page.getByRole('heading', { name: 'Résultats pour « 10.10. »' }),
    ).toBeVisible();

    const addresses = page.getByRole('region', { name: /^Adresses IP/ });
    await expect(addresses.getByRole('listitem')).toHaveCount(25);

    await addresses.getByRole('button', { name: 'Afficher plus' }).click();
    await expect(addresses.getByRole('listitem')).toHaveCount(50);

    // The address of the page can be shared.
    await page.goto('/search?q=sw-etage-1');
    await expect(
        page
            .getByRole('region', { name: /^Équipements/ })
            .getByRole('link', { name: /sw-etage-1/ }),
    ).toBeVisible();
});

test('typing fast stays far below the limit of searches', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    const searches: string[] = [];
    page.on('request', (request) => {
        if (request.url().includes('/search/results')) {
            searches.push(request.url());
        }
    });

    await page
        .getByRole('button', { name: 'Rechercher dans la documentation' })
        .click();
    await searchInput(page).pressSequentially('sw-etage-1 1/0/24', {
        delay: 40,
    });

    await expect(
        group(page, 'Ports').getByRole('option').first(),
    ).toContainText('sw-etage-1 1/0/24');

    // One search once the typing pauses, a few more at most: far from the
    // 120 a minute a user may send.
    expect(searches.length).toBeGreaterThanOrEqual(1);
    expect(searches.length).toBeLessThanOrEqual(3);
});

test('Escape closes the window and keeps the text, as "Close" does', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await searchFor(page, 'imp-acc');
    await expect(searchWindow(page).getByRole('option').first()).toContainText(
        'imp-accueil',
    );

    // The field of search is not emptied on its way out.
    await searchInput(page).press('Escape');
    await expect(searchWindow(page)).toBeHidden();

    await page.keyboard.press('/');
    await expect(searchInput(page)).toHaveValue('imp-acc');
    await expect(searchWindow(page).getByRole('option').first()).toContainText(
        'imp-accueil',
    );
});

test('the former addresses of the search lead to the new ones, the query with them', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await page.goto('/recherche?q=sw-etage-1');
    await expect(page).toHaveURL(/\/search\?q=sw-etage-1$/);
    await expect(
        page.getByRole('heading', { name: 'Résultats pour « sw-etage-1 »' }),
    ).toBeVisible();

    const answer = await page.request.get(
        '/recherche/resultats?q=10.10.10.20',
        {
            maxRedirects: 0,
        },
    );

    expect(answer.status()).toBe(301);
    expect(answer.headers()['location']).toMatch(
        /\/search\/results\?q=10\.10\.10\.20$/,
    );
});
