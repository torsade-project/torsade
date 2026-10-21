// torsade/tests/e2e/home.spec.ts
import type { Page } from '@playwright/test';
import { rebuildDatabase } from './support/database';
import { expect, test } from './support/fixtures';

// The home page, made of modules: the search field, the first steps, the
// summary, the sites, the fullest subnets and, for an administrator, the
// recent activity.

// The titles of the modules, in their order on the page.
const moduleTitles = (page: Page): Promise<string[]> =>
    page
        .getByRole('region')
        .filter({ has: page.getByRole('heading', { level: 2 }) })
        .getByRole('heading', { level: 2 })
        .allTextContents();

test('an administrator sees the recent activity, after the other modules', async ({
    page,
    logIn,
}) => {
    await logIn('admin');

    expect((await moduleTitles(page)).map((title) => title.trim())).toEqual([
        'Rechercher',
        'Synthèse',
        'Sites',
        'Sous-réseaux les plus remplis',
        'Activité récente',
    ]);

    const activity = page.getByRole('region', { name: 'Activité récente' });
    await expect(
        activity.getByRole('table', { name: 'Journal' }),
    ).toBeVisible();
    await expect(
        activity.getByRole('row').filter({ has: page.getByRole('cell') }),
    ).toHaveCount(10);

    await activity.getByRole('link', { name: 'Ouvrir le journal' }).click();
    await expect(page).toHaveURL(/\/activity-log$/);
});

test('a reader has no recent activity, nor the way to the log', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await expect(page.getByRole('region', { name: 'Synthèse' })).toBeVisible();
    await expect(
        page.getByRole('region', { name: 'Activité récente' }),
    ).toHaveCount(0);
    await expect(page.locator('a[href$="/activity-log"]')).toHaveCount(0);
});

test('the summary leads to the lists, the addresses excepted, and says what is planned', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    const summary = page.getByRole('region', { name: 'Synthèse' });

    await expect(summary.getByRole('link', { name: /Sites/ })).toHaveAttribute(
        'href',
        /\/sites$/,
    );
    await expect(
        summary.getByRole('link', { name: /Adresses IP/ }),
    ).toHaveCount(0);
    await expect(summary.getByText('Adresses IP')).toBeVisible();
    await expect(
        summary.getByText(/^dont \d+ prévues?$/).first(),
    ).toBeVisible();

    await summary.getByRole('link', { name: /Équipements/ }).click();
    await expect(page).toHaveURL(/\/devices$/);
});

test('the sites and the fullest subnets lead to their pages', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    const sites = page.getByRole('region', { name: 'Sites' });
    await expect(sites.getByRole('link', { name: 'Siège' })).toBeVisible();

    // Each site says its status, in service included.
    const site = (name: string) =>
        sites
            .locator('[data-test="home-site"]')
            .filter({ has: page.getByRole('link', { name, exact: true }) });
    await expect(site('Siège').locator('[data-status="active"]')).toHaveText(
        'Actif',
    );
    await expect(site('Dépôt').locator('[data-status="planned"]')).toHaveText(
        'Prévu',
    );
    await expect(
        sites.getByRole('link', { name: 'Schéma physique' }).first(),
    ).toHaveAttribute('href', /\/diagrams\/physical\?site=\d+$/);

    const subnets = page.getByRole('region', {
        name: 'Sous-réseaux les plus remplis',
    });
    await expect(subnets.getByRole('listitem')).toHaveCount(5);
    await expect(
        subnets.getByText(/^\d+ \/ \d+ \([\d,]+\s%\)$/).first(),
    ).toBeVisible();

    await subnets.getByRole('link').first().click();
    await expect(page).toHaveURL(/\/subnets\/\d+$/);
});

test('what is typed in the search field of the home page goes on in the window', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    await page
        .getByRole('region', { name: 'Rechercher' })
        .getByRole('searchbox', { name: 'Rechercher dans la documentation' })
        .pressSequentially('imp-acc');

    const window = page.getByRole('dialog', {
        name: 'Rechercher dans la documentation',
    });
    const field = window.getByRole('combobox');

    await expect(field).toBeFocused();
    await expect(field).toHaveValue('imp-acc');
    await expect(window.getByRole('option').first()).toContainText(
        'imp-accueil',
    );
});

test('what is typed in the field of the home page is searched once the typing pauses', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    const searched: (string | null)[] = [];
    page.on('request', (request) => {
        if (request.url().includes('/search/results')) {
            searched.push(new URL(request.url()).searchParams.get('q'));
        }
    });

    // A single digit is worth a search: it waits for the second one.
    await page
        .getByRole('region', { name: 'Rechercher' })
        .getByRole('searchbox', { name: 'Rechercher dans la documentation' })
        .pressSequentially('10');

    const window = page.getByRole('dialog', {
        name: 'Rechercher dans la documentation',
    });
    await expect(window.getByRole('combobox')).toHaveValue('10');
    await expect(
        window
            .getByRole('group', { name: /^VLAN :/ })
            .getByRole('option')
            .first(),
    ).toContainText('10 — Bureautique');

    expect(searched).toEqual(['10']);
});

test('a module the page does not know is left aside', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    // The page as the server would send it with a module of a later
    // version.
    await page.route('**/dashboard', async (route) => {
        const response = await route.fetch();
        const body = (await response.text()).replace(
            '"modules":[',
            '"modules":[{"key":"later","settings":{},"data":{"title":"?"}},',
        );

        await route.fulfill({ response, body });
    });
    await page.reload();

    await expect(page.getByRole('region', { name: 'Synthèse' })).toBeVisible();
    await expect(
        page.getByRole('region', { name: 'Rechercher' }),
    ).toBeVisible();
});

test.describe('on an installation where nothing is documented', () => {
    test.beforeAll(async () => rebuildDatabase({ demonstration: false }));

    // The tests that follow find the demonstration network again.
    test.afterAll(async () => rebuildDatabase());

    test('an editor sees the first steps', async ({ page, logIn }) => {
        await logIn('editor');

        const steps = page.getByRole('region', { name: 'Premiers pas' });

        await expect(steps.getByRole('listitem')).toHaveCount(7);
        await expect(steps.getByRole('listitem').first()).toContainText(
            'Créer un site',
        );
        await expect(steps).toContainText('étape facultative');
        await expect(steps).not.toContainText('Fait');

        await steps.getByRole('link', { name: 'Sites' }).click();
        await expect(page).toHaveURL(/\/sites$/);
    });

    test('a reader is told who creates the first site', async ({
        page,
        logIn,
    }) => {
        await logIn('reader');

        const steps = page.getByRole('region', { name: 'Premiers pas' });

        await expect(steps).toContainText(
            'Un éditeur ou un administrateur doit créer le premier site.',
        );
        await expect(steps.getByRole('listitem')).toHaveCount(0);
    });
});
