// torsade/tests/e2e/site-code.spec.ts
import { expect, test } from './support/fixtures';

// The help of the code of a site says what the code is used for in
// Torsade: no name of a device is made from it.

test('the help of the code of a site says where the code is used', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await page.goto('/sites');
    await page.getByRole('button', { name: 'Nouveau site' }).click();

    const dialog = page.getByRole('dialog', { name: 'Nouveau site' });
    const code = dialog.getByLabel('Code', { exact: true });

    await expect(code).toHaveAccessibleDescription(
        "Facultatif : lettres, chiffres et tirets. Il nomme le site dans le résumé d'un port, « Trunk : 10 · ATE 60 », et dans le nom d'un schéma exporté, « schema-physique-SIE-2026-10-18.svg ».",
    );
    await expect(dialog).not.toContainText('HV-MAI-01');
});
