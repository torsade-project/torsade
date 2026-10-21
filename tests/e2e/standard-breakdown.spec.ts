// torsade/tests/e2e/standard-breakdown.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// The page "Apply the standard" shows, on each line, the ranges the
// standard breakdown of the addressing rule would create in its subnet,
// worked out again as the subnet is corrected. Nothing is created here.

const openStandard = async (page: Page, site: string): Promise<void> => {
    await page.goto('/sites');
    await page.getByRole('link', { name: site, exact: true }).click();
    await page.getByRole('link', { name: 'Appliquer la norme' }).click();
    await expect(
        page.getByRole('heading', {
            name: `Appliquer la norme au site ${site}`,
        }),
    ).toBeVisible();
};

// What each range of a line reads: its kind, then its bounds.
const rangesOf = (line: Locator): Promise<string[]> =>
    line
        .locator('[data-test="breakdown-range"]')
        .evaluateAll((items) =>
            items.map((item) =>
                [
                    item.querySelector('[data-test="range-kind"]')?.textContent,
                    item.querySelector('[data-test="breakdown-bounds"]')
                        ?.textContent,
                ]
                    .map((text) => (text ?? '').replace(/\s+/g, ' ').trim())
                    .join(' '),
            ),
        );

test('each line shows the ranges of the breakdown, worked out again as its subnet is corrected', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openStandard(page, 'Dépôt');

    // The production of the catalogue, on the site numbered 30.
    const line = page.locator('[data-test="standard-line-60"]');
    const subnet = line.getByLabel('Sous-réseau', { exact: true });
    const breakdown = line.getByRole('checkbox', { name: 'Découpage type' });

    await expect(subnet).toHaveValue('10.30.60.0/24');
    expect(await rangesOf(line)).toEqual([
        'Réservée 10.30.60.2 – 10.30.60.20',
        'Fixe 10.30.60.21 – 10.30.60.99',
        'DHCP 10.30.60.100 – 10.30.60.200',
    ]);
    await expect(breakdown).toBeChecked();
    await expect(breakdown).toBeEnabled();

    // Another subnet: the ranges follow it.
    await subnet.fill('10.30.160.0/24');
    expect(await rangesOf(line)).toEqual([
        'Réservée 10.30.160.2 – 10.30.160.20',
        'Fixe 10.30.160.21 – 10.30.160.99',
        'DHCP 10.30.160.100 – 10.30.160.200',
    ]);

    // Too small for the breakdown: the line says so, and the box is
    // inactive.
    await subnet.fill('10.30.160.0/26');
    await expect(
        line.locator('[data-test="breakdown-range"]').filter({
            hasText: 'hors du sous-réseau',
        }),
    ).toHaveCount(2);
    await expect(line).toContainText(
        "Le découpage type ne tient pas dans ce sous-réseau : aucune plage n'est créée.",
    );
    await expect(breakdown).toBeDisabled();
    await expect(breakdown).not.toBeChecked();

    // Not a subnet yet: no range to show.
    await subnet.fill('10.30.160');
    await expect(line.locator('[data-test="breakdown-range"]')).toHaveCount(0);
    await expect(line).toContainText(
        'Saisissez le sous-réseau pour voir les plages du découpage type.',
    );
    await expect(breakdown).toBeDisabled();

    // The subnet put back: the box is offered again, ticked as before.
    await subnet.fill('10.30.60.0/24');
    await expect(breakdown).toBeEnabled();
    await expect(breakdown).toBeChecked();
});
