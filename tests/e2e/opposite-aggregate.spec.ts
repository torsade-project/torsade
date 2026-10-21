// torsade/tests/e2e/opposite-aggregate.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// An aggregate declared on one side only, on the demonstration network: the
// aggregate of the core switch in front of the stack of the servers is
// deleted, and its deviation offers to create it again, filled in from the
// aggregate of the stack. The scenario leaves the aggregate as the
// demonstration has it, for the scenarios after it.

const CORE = 'sw-coeur-01';
const DESCRIPTION = 'Vers sw-serveurs : un lien vers chacun de ses membres';
const REASON =
    'sw-serveurs › Po1 : en face, ports hors agrégat sur sw-coeur-01';

const openDevice = async (page: Page, name: string): Promise<void> => {
    await page.goto('/devices');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(
        page.getByRole('heading', { name, exact: true }),
    ).toBeVisible();
};

// The first link of the stack, on the page of the links, among the links
// with a deviation: with an aggregate on one side only, each link of the
// aggregate has its own line.
const stackLink = async (page: Page): Promise<Locator> => {
    await page.goto('/links?deviations=1');

    const line = page.locator(
        '[data-test="link-row"][data-link="sw-serveurs › 1/1/1"]',
    );

    await expect(line).toBeVisible();

    return line;
};

// The window that asks to confirm has no name of its own.
const confirmation = (page: Page, title: string): Locator =>
    page.getByRole('dialog').filter({ hasText: title });

const switchTo = async (
    page: Page,
    logIn: (account: 'reader' | 'editor') => Promise<void>,
    account: 'reader' | 'editor',
): Promise<void> => {
    await page.context().clearCookies();
    await logIn(account);
};

test('the aggregate missing in front is created from the deviation of the page of the links', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // The core switch loses its aggregate: its two ports are freed, and the
    // aggregate of the stack faces two loose ports.
    await openDevice(page, CORE);
    await page.locator('[data-test="delete-aggregate-Po1"]').click();

    const deletion = confirmation(page, 'Supprimer cet agrégat ?');

    await deletion.getByRole('button', { name: 'Supprimer' }).click();
    await expect(page.getByText('Agrégat supprimé.')).toBeVisible();

    let line = await stackLink(page);

    await expect(line.locator('[data-test="deviations"]')).toContainText(
        REASON,
    );

    const action = line.locator('[data-test="create-opposite-aggregate"]');

    await expect(action).toHaveText("Créer l'agrégat en face");

    const address = await action.getAttribute('href');

    expect(address).toMatch(/opposite_of=\d+/);

    // A reader is told the deviation, without the action; the address of
    // the action opens no window for them.
    await switchTo(page, logIn, 'reader');
    line = await stackLink(page);
    await expect(line.locator('[data-test="deviations"]')).toContainText(
        REASON,
    );
    await expect(
        line.locator('[data-test="create-opposite-aggregate"]'),
    ).toHaveCount(0);

    await page.goto(address ?? '');
    await expect(
        page.getByRole('heading', { name: CORE, exact: true }),
    ).toBeVisible();
    await expect(page.locator('[data-test="aggregate-dialog"]')).toHaveCount(0);

    // The editor follows the action: the window of a new aggregate of the
    // core switch opens, filled in from the aggregate of the stack.
    await switchTo(page, logIn, 'editor');
    line = await stackLink(page);
    await line.locator('[data-test="create-opposite-aggregate"]').click();
    await expect(page).toHaveURL(address ?? '');

    const dialog = page.getByRole('dialog', { name: 'Nouvel agrégat' });

    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText(CORE);
    await expect(dialog.locator('[data-test="aggregate-draft"]')).toHaveText(
        "En face de sw-serveurs › Po1, avec son protocole, son mode et ses VLAN. Les ports cochés perdent leur propre mode et leurs VLAN : ceux de l'agrégat s'appliquent à eux.",
    );
    await expect(dialog.getByLabel('Nom', { exact: true })).toHaveValue('Po1');
    await expect(dialog.getByLabel('Protocole')).toHaveValue('lacp');
    await expect(
        dialog.getByRole('checkbox', { name: '1/1/1', exact: true }),
    ).toBeChecked();
    await expect(
        dialog.getByRole('checkbox', { name: '1/1/2', exact: true }),
    ).toBeChecked();
    await expect(
        dialog.getByRole('checkbox', { name: '1/1/3', exact: true }),
    ).not.toBeChecked();
    await expect(dialog.getByLabel('Mode')).toHaveValue('trunk');

    await dialog.getByLabel('Description').fill(DESCRIPTION);
    await dialog.locator('[data-test="save-aggregate"]').click();
    await expect(page.getByText('Agrégat créé.')).toBeVisible();
    await expect(dialog).toBeHidden();
    await expect(
        page.locator('[data-test="aggregate-row"][data-aggregate="Po1"]'),
    ).toContainText('Trunk, natif 99 : 10, 20, 30, 40, 50');

    // The deviation is gone: the two links are one aggregated link again,
    // between the two aggregates.
    await page.goto('/links');

    const aggregated = page
        .locator('[data-test="aggregated-link-row"]')
        .filter({ hasText: /sw-serveurs\s*›\s*Po1/ });

    await expect(aggregated).toContainText(/sw-coeur-01\s*›\s*Po1/);
    await expect(aggregated.locator('[data-test="deviations"]')).toHaveCount(0);

    await page.goto('/links?deviations=1');
    await expect(page.getByText(REASON)).toHaveCount(0);
    await expect(
        page.locator('[data-test="create-opposite-aggregate"]'),
    ).toHaveCount(0);
});
