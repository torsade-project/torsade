// torsade/tests/e2e/users.spec.ts
import { expect, test } from './support/fixtures';

// The list of the accounts, for an administrator.

test("an administrator is not offered to reset their own password, only the others'", async ({
    page,
    logIn,
}) => {
    await logIn('admin');
    await page.goto('/users');

    const row = (name: string) =>
        page.getByRole('row').filter({ hasText: name });

    await expect(
        row('Admin de test').getByRole('button', {
            name: 'Réinitialiser le mot de passe',
        }),
    ).toHaveCount(0);
    await expect(
        row('Lecteur de test').getByRole('button', {
            name: 'Réinitialiser le mot de passe',
        }),
    ).toBeVisible();
});
