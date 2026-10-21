// torsade/tests/e2e/physical-diagram.spec.ts
import { readFileSync } from 'node:fs';
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// The physical diagram of a site, on the demonstration network: reached
// from the menu and from the page of a site, drawn with its legend and its
// summary, moved and zoomed, grouped by place or not, saved as an SVG file;
// then the devices that are patched, which do not move to another site
// until their ports are unpatched.

const diagram = (page: Page): Locator => page.locator('[data-test="diagram"]');

const devices = (page: Page): Locator =>
    diagram(page).locator('a[data-device]');

const device = (page: Page, name: string): Locator =>
    diagram(page).locator(`a[data-device="${name}"]`);

// The view of the diagram, as its moves and its zoom set it.
const view = (page: Page): Promise<string | null> =>
    diagram(page).locator('[data-viewport]').getAttribute('transform');

// The diagram of a site, chosen in the list of the page, drawn.
const openDiagram = async (page: Page, site: string): Promise<void> => {
    await page.goto('/diagrams/physical');
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: site });
    await expect(page).toHaveURL(/\/diagrams\/physical\?site=\d+$/);
    await expect(
        page.getByRole('heading', { name: `Schéma physique du site ${site}` }),
    ).toBeVisible();
    await expect(
        device(page, 'fw-siege').or(devices(page).first()),
    ).toBeVisible();
};

test('the diagram of the head office is reached from the menu and drawn with its legend and its summary', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    const menu = page.getByRole('navigation', {
        name: 'Navigation principale',
    });

    await menu
        .getByRole('button', { name: 'Réseau physique', exact: true })
        .click();
    await menu
        .getByRole('link', { name: 'Schéma physique', exact: true })
        .click();

    // The first site by name, in the address of the page.
    await expect(page).toHaveURL(/\/diagrams\/physical\?site=\d+$/);
    await expect(page.getByLabel('Site', { exact: true })).toHaveValue(/\d+/);
    await expect(
        page.getByLabel('Site', { exact: true }).locator('option:checked'),
    ).toHaveText('Atelier');

    // Another site: the address follows, to be shared.
    const before = page.url();

    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Siège' });
    await expect(page).not.toHaveURL(before);
    await expect(page).toHaveURL(/\/diagrams\/physical\?site=\d+$/);

    for (const name of [
        'fw-siege',
        'sw-coeur-01',
        'sw-coeur-02',
        'sw-etage-1',
        'sw-etage-2',
        'sw-serveurs',
    ]) {
        await expect(device(page, name)).toBeVisible();
    }

    // At the edge, the switch of the workshop, with its site.
    await expect(device(page, 'sw-atelier-01')).toContainText('Atelier');
    // The stack says so.
    await expect(device(page, 'sw-serveurs')).toContainText(
        'stack de 2 membres',
    );
    // The aggregated link of the stack is one link drawn.
    await expect(diagram(page)).toContainText('Po1 ↔ Po1 · 2 liens');

    // The places, framed.
    await expect(diagram(page).locator('[data-place="building"]')).toHaveCount(
        1,
    );
    await expect(diagram(page)).toContainText('Local technique');

    await expect(page.locator('[data-test="diagram-legend"]')).toContainText(
        'Liaison prévue',
    );
    await expect(page.locator('[data-test="diagram-summary"]')).toHaveText(
        /^\d+ équipements, \d+ postes?, \d+ liaisons, \d+ écarts$/,
    );
    await expect(diagram(page)).toHaveAttribute(
        'aria-describedby',
        'diagram-summary',
    );

    // The page of a site leads to its diagram.
    await page.goto('/sites');
    await page.getByRole('link', { name: 'Atelier', exact: true }).click();
    await page.locator('[data-test="site-diagram"]').click();
    await expect(
        page.getByRole('heading', { name: 'Schéma physique du site Atelier' }),
    ).toBeVisible();
    await expect(device(page, 'sw-atelier-01')).toBeVisible();
});

test('a device of the diagram opens its page, and the warning of a deviation tells its reason', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openDiagram(page, 'Siège');

    // The fibre under study merges the VLAN 10 of the two sites.
    const fibre = diagram(page)
        .locator('[data-link][data-deviation]')
        .filter({ hasText: 'sw-atelier-01' });
    const key = await fibre.getAttribute('data-link');

    await expect(
        diagram(page).locator(`[data-link-warning="${key}"] > title`),
    ).toHaveText(
        "VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux",
    );
    // The link itself names its two ends.
    await expect(fibre.locator(':scope > title')).toContainText(
        'sw-coeur-02 › 1/1/3 ↔ sw-atelier-01 › 1/1/1',
    );

    await device(page, 'sw-coeur-01').click();
    await expect(
        page.getByRole('heading', { name: 'sw-coeur-01', exact: true }),
    ).toBeVisible();
});

test('the warning of a deviation is reached from the keyboard, its reasons open on the focus and close with Escape', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openDiagram(page, 'Siège');

    const warnings = diagram(page).locator('[data-test="link-deviation"]');
    const tooltip = page.locator('[data-test="deviation-tooltip"]');

    await expect(warnings.first()).toBeVisible();

    // From the first device, Tab goes through the devices, then reaches
    // the warnings.
    await devices(page).first().focus();

    let reached = false;

    for (let step = 0; step < 40 && !reached; step++) {
        await page.keyboard.press('Tab');
        reached = await page.evaluate(
            () =>
                document.activeElement?.getAttribute('data-test') ===
                'link-deviation',
        );
    }

    expect(reached).toBe(true);

    const focused = page.locator(':focus');

    await expect(focused).toHaveAttribute('role', 'button');
    await expect(focused).toHaveAccessibleName('Écart');

    // The reasons open on the focus, tied to the warning for a screen
    // reader, and say the same as its title.
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toHaveAttribute('role', 'tooltip');
    await expect(focused).toHaveAttribute(
        'aria-describedby',
        (await tooltip.getAttribute('id')) ?? '',
    );
    expect(await tooltip.innerText()).toBe(
        await focused.locator(':scope > title').textContent(),
    );

    // Escape closes them, the focus stays on the warning.
    await page.keyboard.press('Escape');
    await expect(tooltip).toBeHidden();
    await expect(focused).toHaveAttribute('data-test', 'link-deviation');

    // Enter opens them again; leaving the warning closes them.
    await page.keyboard.press('Enter');
    await expect(tooltip).toBeVisible();
    await page.keyboard.press('Shift+Tab');
    await expect(tooltip).toBeHidden();
});

test.describe('on a touch screen', () => {
    test.use({ hasTouch: true });

    test('a touch on the warning of a deviation opens its reasons, a touch elsewhere closes them', async ({
        page,
        logIn,
    }) => {
        await logIn('reader');
        await openDiagram(page, 'Siège');

        const warning = diagram(page)
            .locator('[data-test="link-deviation"]')
            .first();
        const tooltip = page.locator('[data-test="deviation-tooltip"]');

        await warning.scrollIntoViewIfNeeded();
        await warning.tap();
        await expect(tooltip).toBeVisible();
        expect(await tooltip.innerText()).toBe(
            await warning.locator(':scope > title').textContent(),
        );

        // Elsewhere on the page.
        await page.locator('[data-test="diagram-summary"]').tap();
        await expect(tooltip).toBeHidden();

        // A second touch on the warning closes them too.
        await warning.tap();
        await expect(tooltip).toBeVisible();
        await warning.tap();
        await expect(tooltip).toBeHidden();
    });
});

test('the deviations of the diagram are listed under it, each with its reason and a link', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openDiagram(page, 'Siège');

    const list = page.getByRole('region', { name: 'Écarts du schéma' });
    const items = list.locator('[data-test="deviation-item"]');
    const warnings = diagram(page).locator(
        '[data-test="link-deviation"], [data-test="device-deviation"]',
    );

    await expect(
        list.getByRole('heading', { name: 'Écarts du schéma' }),
    ).toBeVisible();
    // One line per warning of the diagram, but for the cabling of
    // ap-etage-2, drawn beside the link it contradicts: it repeats the
    // deviation of the link.
    await expect(items).toHaveCount((await warnings.count()) - 1);

    // The fibre under study, named by its two ends, with its reason.
    const fibre = items.filter({
        hasText: 'sw-coeur-02 › 1/1/3 ↔ sw-atelier-01 › 1/1/1',
    });

    await expect(fibre).toContainText(
        "VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux",
    );

    // Its link leads to the links of the site that differ.
    await fibre.getByRole('link').click();
    await expect(page).toHaveURL(/\/links\?/);

    const address = new URL(page.url());

    expect(address.searchParams.get('deviations')).toBe('1');
    expect(address.searchParams.get('site')).toMatch(/^\d+$/);
    await expect(page.locator('[data-test="filter-deviations"]')).toBeChecked();
});

test('the diagram moves, zooms and fits, from its buttons, the wheel with Ctrl, a pinch and the keyboard', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openDiagram(page, 'Siège');

    const start = await view(page);

    expect(start).toBe('translate(0 0) scale(1)');

    await page.locator('[data-test="zoom-in"]').click();
    expect(await view(page)).toContain('scale(1.25)');
    await page.locator('[data-test="zoom-out"]').click();
    await page.locator('[data-test="zoom-out"]').click();
    expect(await view(page)).toContain('scale(0.8)');
    await page.locator('[data-test="fit"]').click();
    expect(await view(page)).toBe(start);

    // Dragging the background, from its corner, moves it.
    await diagram(page).scrollIntoViewIfNeeded();

    const box = await diagram(page).boundingBox();

    expect(box).not.toBeNull();

    if (box !== null) {
        await page.mouse.move(box.x + 8, box.y + 8);
        await page.mouse.down();
        await page.mouse.move(box.x + 108, box.y + 58, { steps: 5 });
        await page.mouse.up();
        expect(await view(page)).not.toBe(start);

        // The wheel alone scrolls the page, not the diagram.
        await page.locator('[data-test="fit"]').click();
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.wheel(0, 200);
        expect(await view(page)).toBe(start);

        // With Ctrl, it zooms.
        await page.keyboard.down('Control');
        await page.mouse.wheel(0, -200);
        await page.keyboard.up('Control');
        expect(await view(page)).toMatch(/scale\(1\.\d+\)/);
        await page.locator('[data-test="fit"]').click();

        // Two fingers that move apart, on the background, zoom in: the
        // second one goes from 20 to 80 pixels away from the first one.
        const screen = await page.context().newCDPSession(page);
        const fingers = (gap: number) => [
            { x: box.x + 10, y: box.y + 10, id: 1 },
            { x: box.x + 10 + gap, y: box.y + 10, id: 2 },
        ];

        await screen.send('Input.dispatchTouchEvent', {
            type: 'touchStart',
            touchPoints: fingers(20),
        });

        for (const gap of [40, 60, 80]) {
            await screen.send('Input.dispatchTouchEvent', {
                type: 'touchMove',
                touchPoints: fingers(gap),
            });
        }

        await screen.send('Input.dispatchTouchEvent', {
            type: 'touchEnd',
            touchPoints: [],
        });
        expect(
            Number(/scale\(([\d.]+)\)/.exec((await view(page)) ?? '')?.[1]),
        ).toBeCloseTo(4, 1);
        await page.locator('[data-test="fit"]').click();
    }

    // From the keyboard, the devices are links in the order of reading,
    // from top to bottom by their middles, then from left to right; + and
    // − zoom.
    const order = await devices(page).evaluateAll((links) =>
        links.map((link) => {
            const rect = (
                link.querySelector('rect') as SVGGraphicsElement
            ).getBBox();

            return {
                name: link.getAttribute('data-device'),
                y: rect.y + rect.height / 2,
                x: rect.x,
            };
        }),
    );

    expect(order.map((one) => one.name)).toEqual(
        [...order]
            .sort((a, b) => a.y - b.y || a.x - b.x)
            .map((one) => one.name),
    );
    expect(order[0]?.name).toBe('fw-siege');

    await devices(page).first().focus();
    await expect(device(page, 'fw-siege')).toBeFocused();
    await page.keyboard.press('+');
    expect(await view(page)).toContain('scale(1.25)');
    await page.keyboard.press('-');
    expect(await view(page)).toContain('scale(1)');
    await page.keyboard.press('Tab');
    await expect(device(page, order[1]?.name ?? '')).toBeFocused();
});

test('unticked, "Group by place" lays the diagram out without its frames', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openDiagram(page, 'Siège');

    const frames = diagram(page).locator('[data-place]');

    await expect(frames.first()).toBeVisible();
    await page.locator('[data-test="group-by-place"]').uncheck();
    await expect(frames).toHaveCount(0);
    await expect(device(page, 'fw-siege')).toBeVisible();

    await page.locator('[data-test="group-by-place"]').check();
    await expect(frames.first()).toBeVisible();
});

test('the diagram is saved as an SVG file named after the site, with its colours written as values', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openDiagram(page, 'Siège');

    const downloading = page.waitForEvent('download');

    await page.locator('[data-test="save-diagram"]').click();

    const file = await downloading;

    expect(file.suggestedFilename()).toMatch(
        /^schema-physique-SIE-\d{4}-\d{2}-\d{2}\.svg$/,
    );

    const content = readFileSync((await file.path()) ?? '', 'utf8');

    for (const name of [
        'fw-siege',
        'sw-coeur-01',
        'sw-atelier-01',
        'Po1 ↔ Po1 · 2 liens',
    ]) {
        expect(content).toContain(name);
    }

    expect(content).toContain('<title>Schéma physique du site Siège</title>');
    expect(content).not.toContain('var(');
    expect(content).not.toContain('class=');
    // The fonts of the theme, the interface one for the names and the
    // technical one for the ports, then their generic families.
    expect(content).toContain('font-family="Barlow, sans-serif"');
    expect(content).toMatch(
        /font-family="(&quot;)?IBM Plex Mono(&quot;)?, monospace"/,
    );
    // The whole diagram, not the part in sight.
    expect(content).not.toMatch(/transform="translate\([^)]*\) scale/);
});

test('the file saved takes the light theme, whatever the one on screen', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openDiagram(page, 'Siège');

    // The page is in the dark theme, the default one.
    await expect(page.locator('html')).toHaveClass(/(^|\s)dark(\s|$)/);

    // What the frame and a device look like in the light theme, as the
    // page prints them.
    const light = await page.evaluate(() => {
        const root = document.documentElement;
        const frame = document.querySelector(
            'svg[data-test="diagram"]',
        )?.parentElement;
        const box = document.querySelector(
            '[data-test="diagram"] a[data-device="fw-siege"] rect + rect',
        );

        root.classList.add('print-light');

        const values = {
            frame: frame ? getComputedStyle(frame).backgroundColor : '',
            device: box ? getComputedStyle(box).fill : '',
        };

        root.classList.remove('print-light');

        return values;
    });

    const dark = await page.evaluate(() => {
        const frame = document.querySelector(
            'svg[data-test="diagram"]',
        )?.parentElement;

        return frame ? getComputedStyle(frame).backgroundColor : '';
    });

    expect(light.frame).not.toBe(dark);

    const downloading = page.waitForEvent('download');

    await page.locator('[data-test="save-diagram"]').click();

    const content = readFileSync(
        (await (await downloading).path()) ?? '',
        'utf8',
    );

    // The background of the file, and the box of a device.
    expect(content).toMatch(
        new RegExp(
            `</title><rect [^>]*fill="${light.frame.replace(/[()]/g, '\\$&')}"`,
        ),
    );
    expect(content).toContain(`fill="${light.device}"`);

    // The page itself stays in its theme.
    await expect(page.locator('html')).not.toHaveClass(/print-light/);
});

test('a site without anything to draw says so, and the layout tells when it is under way or when it fails', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // Nothing to draw at the depot: a way to create a device.
    await openSitePage(page, 'Dépôt');
    await expect(page.locator('[data-test="diagram-empty"]')).toContainText(
        "Ce site n'a aucun équipement à dessiner",
    );
    await page
        .locator('[data-test="diagram-empty"]')
        .getByRole('link', { name: 'Nouvel équipement' })
        .click();
    await expect(page.getByLabel('Site', { exact: true })).toHaveValue(/\d+/);

    // The engine slow to come: the layout says it is under way.
    await page.route('**/build/assets/diagram-layout-*.js', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 1500));
        await route.continue();
    });
    await page.goto('/diagrams/physical');
    await expect(page.locator('[data-test="diagram-working"]')).toHaveText(
        'Calcul du schéma…',
    );
    await expect(devices(page).first()).toBeVisible();
    await page.unrouteAll();

    // The engine unavailable: a message, never a blank page.
    await page.route('**/build/assets/diagram-layout-*.js', (route) =>
        route.abort(),
    );
    await page.reload();
    await expect(page.locator('[data-test="diagram-failed"]')).toContainText(
        "Le schéma n'a pas pu être calculé.",
    );
    await expect(page.locator('[data-test="save-diagram"]')).toBeDisabled();
});

// The page of a site, from the list of sites, then its diagram.
const openSitePage = async (page: Page, site: string): Promise<void> => {
    await page.goto('/sites');
    await page.getByRole('link', { name: site, exact: true }).click();
    await page.locator('[data-test="site-diagram"]').click();
    await expect(
        page.getByRole('heading', { name: `Schéma physique du site ${site}` }),
    ).toBeVisible();
};

test('devices without a link are drawn, with where a link is declared', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // A switch at the depot, which has none.
    await page.goto('/devices/create');
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Dépôt' });
    await page.getByLabel('Nom', { exact: true }).fill('sw-essai-schema');
    await page
        .getByLabel('Type', { exact: true })
        .selectOption({ label: 'Switch' });
    await page.locator('[data-test="save-device"]').click();
    await expect(
        page.getByRole('heading', { name: 'sw-essai-schema', exact: true }),
    ).toBeVisible();

    await openSitePage(page, 'Dépôt');
    await expect(device(page, 'sw-essai-schema')).toBeVisible();
    await expect(page.locator('[data-test="diagram-no-link"]')).toContainText(
        "Une liaison se déclare depuis la page d'un équipement",
    );
    // Nothing differs there: the list of the deviations says so.
    await expect(
        page.getByRole('region', { name: 'Écarts du schéma' }),
    ).toContainText('Aucun écart.');

    // Taken away again.
    await device(page, 'sw-essai-schema').click();
    await page.getByRole('button', { name: 'Supprimer' }).first().click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(page).toHaveURL(/\/devices$/);
});

test('a patched device does not move to another site until all its ports are unpatched', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    const openDevice = async (): Promise<void> => {
        await page.goto('/devices');
        await page
            .getByRole('link', { name: 'sw-etage-2', exact: true })
            .click();
        await expect(
            page.getByRole('heading', { name: 'sw-etage-2', exact: true }),
        ).toBeVisible();
    };

    const move = async (site: string, room?: string): Promise<void> => {
        await page
            .getByRole('link', { name: 'Modifier', exact: true })
            .first()
            .click();
        await page
            .getByLabel('Site', { exact: true })
            .selectOption({ label: site });

        if (room !== undefined) {
            await page
                .getByLabel('Local', { exact: true })
                .selectOption({ label: room });
        }

        await page.locator('[data-test="save-device"]').click();
    };

    // The cord the test stands on is its own: a port of the riser panel
    // PB-C patched to the first free access port of the switch. The test
    // unpatches every port of the switch, so it never rests on the cords
    // of the demonstration, which a run before it may have taken away.
    await page.goto('/sites');
    await page.getByRole('link', { name: 'Siège', exact: true }).click();
    await page
        .locator('[data-test="place-panel"][data-panel="PB-C"]')
        .getByRole('link', { name: 'PB-C', exact: true })
        .click();
    await page.locator('[data-test="patch-20"]').click();

    const cord = page.locator('[data-test="patch-cord-dialog"]');
    const port = cord.locator('[data-test="cord-port"]');
    const accessPort = port.locator('option', { hasText: /^1\/0\/\d+$/ });

    await cord.locator('[data-test="cord-device"]').selectOption({
        label: 'sw-etage-2 — Bâtiment principal › 2e étage › Placard technique',
    });
    await expect(accessPort.first()).toBeAttached();
    await port.selectOption({
        label: (await accessPort.first().textContent())?.trim() ?? '',
    });
    await cord.locator('[data-test="save-patch-cord"]').click();
    await expect(page.getByText('1 port brassé.')).toBeVisible();

    await openDevice();
    await move('Atelier');

    // Refused, the cords named.
    await expect(
        page.getByText(
            /est brassé vers PB-C \d+.*avant de déplacer l'équipement vers un autre site\./,
        ),
    ).toBeVisible();

    await openDevice();

    const unpatch = page.locator('[data-test="unpatch-all"]');

    await expect(unpatch).toBeEnabled();
    await unpatch.click();

    const confirmation = page
        .getByRole('dialog')
        .filter({ hasText: 'Débrasser tous les ports de sw-etage-2 ?' });

    await expect(confirmation).toContainText(
        /PB-C \d+ ↔ sw-etage-2 › 1\/0\/\d+/,
    );
    await confirmation.getByRole('button', { name: 'Débrasser' }).click();
    await expect(page.getByText(/^\d+ cordons? supprimés?\.$/)).toBeVisible();
    // Nothing left to take away: the button is gone.
    await expect(unpatch).toHaveCount(0);

    // Now it moves; and back to its cupboard.
    await move('Atelier');
    await expect(page.getByText('Équipement modifié.')).toBeVisible();
    await expect(page.getByText('Atelier').first()).toBeVisible();

    await move(
        'Siège',
        'Bâtiment principal › 2e étage › Placard technique (Technique)',
    );
    await expect(page.getByText('Équipement modifié.')).toBeVisible();
});
