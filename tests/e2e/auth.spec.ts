// torsade/tests/e2e/auth.spec.ts
import { randomBytes } from 'node:crypto';
import {
    accounts,
    expect,
    password,
    submitLogin,
    test,
} from './support/fixtures';

const randomSecret = (): string => randomBytes(18).toString('base64url');

test('a user logs in from the keyboard and reaches the home page', async ({
    page,
}) => {
    await page.goto('/login');

    // The e-mail field has the focus when the page opens.
    await expect(page.getByLabel('Adresse e-mail')).toBeFocused();
    await page.keyboard.type(accounts.admin.email);
    await page.keyboard.press('Tab');
    await expect(
        page.getByLabel('Mot de passe', { exact: true }),
    ).toBeFocused();
    await page.keyboard.type(password());
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page).toHaveTitle(/Accueil/);
    await expect(page.getByText(accounts.admin.name).first()).toBeVisible();
});

test('a wrong password is refused with a message', async ({ page }) => {
    await submitLogin(page, accounts.admin.email, randomSecret());

    await expect(
        page.getByText(
            'Ces identifiants ne correspondent à aucun compte actif.',
        ),
    ).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);

    // No session was opened.
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});

test('a first access with a temporary password imposes a new one, then opens the home page', async ({
    page,
}) => {
    // The screens of authentication have no search.
    await page.goto('/login');
    await expect(
        page.getByRole('button', { name: 'Rechercher dans la documentation' }),
    ).toHaveCount(0);

    await submitLogin(page, accounts.newcomer.email, password());

    await expect(page).toHaveURL(/\/change-password$/);

    // Nor has the change of a temporary password: not even its shortcut.
    await expect(
        page.getByRole('button', { name: 'Rechercher dans la documentation' }),
    ).toHaveCount(0);
    await page.keyboard.press('/');
    await expect(page.getByRole('dialog')).toHaveCount(0);

    // Nothing else can be reached until the password is changed.
    await page.goto('/vlans');
    await expect(page).toHaveURL(/\/change-password$/);

    const chosen = randomSecret();

    await page
        .getByLabel('Mot de passe temporaire', { exact: true })
        .fill(password());
    await page.getByLabel('Nouveau mot de passe', { exact: true }).fill(chosen);
    await page
        .getByLabel('Confirmer le mot de passe', { exact: true })
        .fill(chosen);
    await page
        .getByRole('button', { name: 'Enregistrer et continuer' })
        .click();

    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page).toHaveTitle(/Accueil/);
    await expect(page.getByText(accounts.newcomer.name).first()).toBeVisible();

    // The other pages are open now.
    await page.goto('/vlans');
    await expect(page).toHaveURL(/\/vlans$/);
});
