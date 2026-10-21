// torsade/tests/e2e/diagram-volume.spec.ts
import { execFileSync } from 'node:child_process';
import { projectRoot, serverEnvironment } from './support/environment';
import { expect, test } from './support/fixtures';

// How long a large diagram takes to appear: a site of a hundred and fifty
// devices joined by three hundred links, then a site of three hundred end
// devices cabled through four patch panels, from the opening of its page
// to its drawing. Each site is added for its test, then taken away, so
// that the tests that follow find the demonstration network as it was.

const LIMIT = 20_000;

const seed = (mode: 'add' | 'cabled' | 'remove'): void => {
    execFileSync(
        'php',
        ['artisan', 'db:seed', '--class=DiagramVolumeSeeder', '--force'],
        {
            cwd: projectRoot,
            env: {
                ...process.env,
                ...serverEnvironment,
                DIAGRAM_VOLUME: mode,
            },
            encoding: 'utf8',
        },
    );
};

test('a site of 150 devices and 300 links is drawn in less than 20 seconds', async ({
    page,
    logIn,
}) => {
    test.setTimeout(180_000);
    seed('add');

    try {
        await logIn('reader');
        await page.goto('/diagrams/physical');

        const site = await page
            .getByLabel('Site', { exact: true })
            .locator('option', { hasText: 'Volume du schéma' })
            .getAttribute('value');

        expect(site).not.toBeNull();

        const start = Date.now();

        await page.goto(`/diagrams/physical?site=${site ?? ''}`);
        await expect(
            page.locator('[data-test="diagram"] a[data-device]'),
        ).toHaveCount(150, { timeout: LIMIT });

        const elapsed = Date.now() - start;

        test.info().annotations.push({
            type: 'layout time',
            description: `${elapsed} ms`,
        });
        console.log(
            `The diagram of 150 devices and 300 links appeared in ${elapsed} ms.`,
        );

        expect(elapsed).toBeLessThan(LIMIT);
        await expect(page.locator('[data-test="diagram-summary"]')).toHaveText(
            '150 équipements, 0 poste, 300 liaisons, 0 écart',
        );
    } finally {
        seed('remove');
    }
});

test('a site of 300 end devices cabled through its panels is drawn in less than 20 seconds', async ({
    page,
    logIn,
}) => {
    test.setTimeout(240_000);
    seed('cabled');

    try {
        await logIn('reader');
        await page.goto('/diagrams/physical');

        const site = await page
            .getByLabel('Site', { exact: true })
            .locator('option', { hasText: 'Volume du câblage' })
            .getAttribute('value');

        expect(site).not.toBeNull();

        const start = Date.now();

        await page.goto(`/diagrams/physical?site=${site ?? ''}`);
        await expect(
            page.locator('[data-test="diagram"] a[data-device]'),
        ).toHaveCount(325, { timeout: LIMIT });

        const elapsed = Date.now() - start;

        test.info().annotations.push({
            type: 'layout time',
            description: `${elapsed} ms`,
        });
        console.log(
            `The diagram of 300 end devices cabled through 4 panels appeared in ${elapsed} ms.`,
        );

        expect(elapsed).toBeLessThan(LIMIT);
        await expect(
            page.locator('[data-test="diagram"] a[data-panel]'),
        ).toHaveCount(4);
        await expect(page.locator('[data-test="diagram-summary"]')).toHaveText(
            '25 équipements, 300 postes, 0 liaison, 150 écarts',
        );

        // Without the end devices, the switches alone.
        const hidden = Date.now();

        await page.locator('[data-test="show-end-devices"]').uncheck();
        await expect(
            page.locator('[data-test="diagram"] a[data-device]'),
        ).toHaveCount(25, { timeout: LIMIT });
        await expect(
            page.locator('[data-test="diagram"] a[data-panel]'),
        ).toHaveCount(0);

        console.log(
            `Without its end devices, it was laid out again in ${Date.now() - hidden} ms.`,
        );

        await page.locator('[data-test="show-end-devices"]').check();
    } finally {
        seed('remove');
    }
});
