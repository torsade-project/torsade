// torsade/tests/e2e/diagram-layout.spec.ts
import { readFileSync } from 'node:fs';
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// The physical diagram of the head office, placed by hand: devices moved
// with the mouse, a finger and the keyboard, on a grid of 8, their links
// following; the layout saved, found again, cancelled, given back to the
// automatic layout; a device added later on the row of those not placed;
// a save refused when the layout changed elsewhere; the moves not saved
// kept from being lost unawares. Each scenario leaves the diagram laid out
// automatically again, as the demonstration has it.

const diagram = (page: Page): Locator => page.locator('[data-test="diagram"]');

const device = (page: Page, name: string): Locator =>
    diagram(page).locator(`a[data-device="${name}"]`);

const position = async (
    page: Page,
    name: string,
): Promise<{ x: number; y: number }> => ({
    x: Number(await device(page, name).getAttribute('data-x')),
    y: Number(await device(page, name).getAttribute('data-y')),
});

const openHeadOffice = async (page: Page): Promise<void> => {
    await page.goto('/diagrams/physical');
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Siège' });
    await expect(
        page.getByRole('heading', { name: 'Schéma physique du site Siège' }),
    ).toBeVisible();
    await expect(device(page, 'fw-siege')).toBeVisible();
};

const startEditing = async (page: Page): Promise<void> => {
    await page.locator('[data-test="edit-layout"]').click();
    await expect(page.locator('[data-test="layout-bar"]')).toBeVisible();
    await expect(
        diagram(page).locator('[data-test="diagram-grid"]'),
    ).toHaveCount(1);
};

const saveLayout = async (page: Page): Promise<void> => {
    await page.locator('[data-test="save-layout"]').click();
    await expect(
        page.getByText(/^Disposition enregistrée : \d+ éléments placés\.$/),
    ).toBeVisible();
    await expect(page.locator('[data-test="layout-bar"]')).toBeHidden();
};

// Back to the automatic layout, through the page, with its confirmation.
const layOutAutomatically = async (page: Page): Promise<void> => {
    await startEditing(page);
    await page.locator('[data-test="reset-layout"]').click();

    const confirmation = page.getByRole('dialog', {
        name: 'Réorganiser automatiquement le schéma ?',
    });

    await expect(confirmation).toContainText(
        'Les positions placées à la main sur ce schéma seront supprimées',
    );
    await confirmation
        .getByRole('button', { name: 'Réorganiser automatiquement' })
        .click();
    await expect(
        page.getByText('Le schéma est de nouveau disposé automatiquement.'),
    ).toBeVisible();
    await expect(page.locator('[data-test="layout-bar"]')).toBeHidden();
};

// The points of the line of a link of a device, as drawn.
const linkPoints = (page: Page, name: string): Promise<string | null> =>
    diagram(page)
        .locator('[data-link]')
        .filter({ has: page.locator('title', { hasText: `${name} ›` }) })
        .first()
        .locator('polyline')
        .nth(1)
        .getAttribute('points');

test('devices are moved with the mouse and the keyboard on a grid of 8, saved, and found again', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openHeadOffice(page);
    await startEditing(page);

    // Nothing moved yet.
    await expect(page.locator('[data-test="layout-state"]')).toHaveText(
        "Rien n'a été déplacé pour l'instant.",
    );

    // With the mouse: the switch of the first floor, dragged; its link
    // follows it.
    const before = await position(page, 'sw-etage-1');
    const line = await linkPoints(page, 'sw-etage-1');

    expect(line).not.toBeNull();
    await device(page, 'sw-etage-1').scrollIntoViewIfNeeded();

    const box = await device(page, 'sw-etage-1').boundingBox();

    expect(box).not.toBeNull();

    if (box !== null) {
        await page.mouse.move(box.x + 12, box.y + 12);
        await page.mouse.down();
        await page.mouse.move(box.x + 112, box.y + 72, { steps: 6 });
        // While it moves, the device keeps the ring of the focus.
        await expect(device(page, 'sw-etage-1')).toBeFocused();
        await page.mouse.up();
    }

    const dragged = await position(page, 'sw-etage-1');

    expect(dragged.x).toBeGreaterThan(before.x);
    expect(dragged.y).toBeGreaterThan(before.y);
    expect(dragged.x % 8).toBe(0);
    expect(dragged.y % 8).toBe(0);
    expect(await linkPoints(page, 'sw-etage-1')).not.toBe(line);
    await expect(page.locator('[data-test="layout-state"]')).toHaveText(
        'Des déplacements ne sont pas encore enregistrés.',
    );

    // With the keyboard: the firewall, by 8 to the right, then by 40 down
    // with Shift. Its page is not followed while devices are placed.
    const firewall = await position(page, 'fw-siege');

    await device(page, 'fw-siege').focus();
    await expect(device(page, 'fw-siege')).toHaveAttribute(
        'aria-describedby',
        'diagram-edit-help',
    );
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('Shift+ArrowDown');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/diagrams\/physical\?site=\d+$/);
    expect(await position(page, 'fw-siege')).toEqual({
        x: firewall.x + 8,
        y: firewall.y + 40,
    });

    // Every device drawn is saved, then found again after a reload.
    const names = await diagram(page)
        .locator('a[data-device]')
        .evaluateAll((links) =>
            links.map((link) => link.getAttribute('data-device') ?? ''),
        );
    const placed = Object.fromEntries(
        await Promise.all(
            names.map(
                async (name) => [name, await position(page, name)] as const,
            ),
        ),
    );

    await saveLayout(page);
    await page.reload();
    await expect(device(page, 'fw-siege')).toBeVisible();

    for (const name of names) {
        expect(await position(page, name), name).toEqual(placed[name]);
    }

    // Placed by hand, nothing is marked as not placed, and the frames of
    // the places surround their devices.
    await expect(diagram(page).locator('[data-unplaced]')).toHaveCount(0);

    const surrounds = await diagram(page).evaluate((svg) => {
        const fw = svg.querySelector(
            'a[data-device="fw-siege"] rect:nth-of-type(2)',
        );

        if (!(fw instanceof SVGGraphicsElement)) {
            return false;
        }

        const d = fw.getBBox();

        // The frame of its room, among those of the rooms.
        return [...svg.querySelectorAll('[data-place="room"] > rect')].some(
            (rect) => {
                if (!(rect instanceof SVGGraphicsElement)) {
                    return false;
                }

                const r = rect.getBBox();

                return (
                    d.x >= r.x &&
                    d.y >= r.y &&
                    d.x + d.width <= r.x + r.width &&
                    d.y + d.height <= r.y + r.height
                );
            },
        );
    });

    expect(surrounds).toBe(true);

    // Unticked, "Regrouper par lieu" takes the frames away and moves no
    // device.
    await page.locator('[data-test="group-by-place"]').uncheck();
    await expect(diagram(page).locator('[data-place]')).toHaveCount(0);
    expect(await position(page, 'fw-siege')).toEqual(placed['fw-siege']);
    await page.locator('[data-test="group-by-place"]').check();

    // The file saved shows the layout drawn.
    const download = page.waitForEvent('download');

    await page.locator('[data-test="save-diagram"]').click();

    const file = await download;
    const content = readFileSync((await file.path()) ?? '', 'utf8');

    expect(content).toContain(`x="${placed['fw-siege'].x}"`);
    expect(content).not.toContain('url(#');

    await layOutAutomatically(page);
    await expect(
        diagram(page).locator('[data-test="diagram-grid"]'),
    ).toHaveCount(0);
});

test('"Annuler" goes back to the layout saved', async ({ page, logIn }) => {
    await logIn('editor');
    await openHeadOffice(page);

    const before = await position(page, 'sw-coeur-01');

    await startEditing(page);
    await device(page, 'sw-coeur-01').focus();
    await page.keyboard.press('Shift+ArrowLeft');
    expect((await position(page, 'sw-coeur-01')).x).not.toBe(before.x);

    await page.locator('[data-test="cancel-layout"]').click();
    await expect(page.locator('[data-test="layout-bar"]')).toBeHidden();
    expect(await position(page, 'sw-coeur-01')).toEqual(before);

    // Nothing was saved: "Réorganiser automatiquement" has nothing to do.
    await startEditing(page);
    await expect(page.locator('[data-test="reset-layout"]')).toBeDisabled();
    await page.locator('[data-test="cancel-layout"]').click();
});

test('a device moved with a finger follows it', async ({ page, logIn }) => {
    await logIn('editor');
    await openHeadOffice(page);
    await startEditing(page);

    await device(page, 'sw-etage-2').scrollIntoViewIfNeeded();

    const before = await position(page, 'sw-etage-2');
    const box = await device(page, 'sw-etage-2').boundingBox();
    const screen = await page.context().newCDPSession(page);

    expect(box).not.toBeNull();

    if (box !== null) {
        const finger = (dx: number, dy: number) => [
            { x: box.x + 12 + dx, y: box.y + 12 + dy, id: 1 },
        ];

        await screen.send('Input.dispatchTouchEvent', {
            type: 'touchStart',
            touchPoints: finger(0, 0),
        });

        for (const step of [20, 40, 60]) {
            await screen.send('Input.dispatchTouchEvent', {
                type: 'touchMove',
                touchPoints: finger(step, step / 2),
            });
        }

        await screen.send('Input.dispatchTouchEvent', {
            type: 'touchEnd',
            touchPoints: [],
        });
    }

    const after = await position(page, 'sw-etage-2');

    expect(after.x).toBeGreaterThan(before.x);
    expect(after.x % 8).toBe(0);
    expect(after.y % 8).toBe(0);

    await page.locator('[data-test="cancel-layout"]').click();
});

test('a device added after the layout is saved lines up on the row of those not placed', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openHeadOffice(page);
    await startEditing(page);
    await saveLayout(page);

    // A switch of the head office, created afterwards.
    await page.goto('/devices/create');
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Siège' });
    await page.getByLabel('Nom', { exact: true }).fill('sw-ajout-schema');
    await page
        .getByLabel('Type', { exact: true })
        .selectOption({ label: 'Switch' });
    await page.locator('[data-test="save-device"]').click();
    await expect(
        page.getByRole('heading', { name: 'sw-ajout-schema', exact: true }),
    ).toBeVisible();

    await openHeadOffice(page);

    const added = device(page, 'sw-ajout-schema');

    await expect(added).toHaveAttribute('data-unplaced', 'true');
    await expect(added.locator('[data-test="device-unplaced"]')).toHaveText(
        'Non placé',
    );

    // Below every device placed.
    const lowest = await diagram(page)
        .locator('a[data-device]:not([data-unplaced])')
        .evaluateAll((links) =>
            Math.max(
                ...links.map((link) => Number(link.getAttribute('data-y'))),
            ),
        );

    expect((await position(page, 'sw-ajout-schema')).y).toBeGreaterThan(lowest);

    // Taken away again, with the layout.
    await layOutAutomatically(page);
    await added.click();
    await page.getByRole('button', { name: 'Supprimer' }).first().click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Supprimer' })
        .click();
    await expect(page).toHaveURL(/\/devices$/);
});

test('a save is refused when the layout was changed in another tab', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openHeadOffice(page);
    await startEditing(page);
    await device(page, 'fw-siege').focus();
    await page.keyboard.press('ArrowRight');

    // Meanwhile, in another tab, the layout is saved.
    const other = await page.context().newPage();

    await openHeadOffice(other);
    await startEditing(other);
    await device(other, 'fw-siege').focus();
    await other.keyboard.press('ArrowLeft');
    await saveLayout(other);
    await other.close();

    // The first tab is told, in words, and nothing of it is saved.
    await page.locator('[data-test="save-layout"]').click();
    await expect(page.locator('[data-test="layout-error"]')).toHaveText(
        'La disposition a été modifiée entre-temps : rechargez la page.',
    );
    await expect(page.locator('[data-test="layout-bar"]')).toBeVisible();

    await page.locator('[data-test="cancel-layout"]').click();
    await page.reload();
    await layOutAutomatically(page);
});

test('leaving the page with moves not saved asks first', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await openHeadOffice(page);
    await startEditing(page);
    await device(page, 'fw-siege').focus();
    await page.keyboard.press('ArrowDown');

    const menu = page.getByRole('navigation', {
        name: 'Navigation principale',
    });
    const links = menu.getByRole('link', { name: 'Liaisons', exact: true });
    let asked = '';

    // Through a link: refused, the page stays with its moves.
    page.once('dialog', (dialog) => {
        asked = dialog.message();
        void dialog.dismiss();
    });
    await links.click();
    await expect(page).toHaveURL(/\/diagrams\/physical\?site=\d+$/);
    expect(asked).toBe(
        'Des déplacements ne sont pas enregistrés. Quitter la page et les perdre ?',
    );
    await expect(page.locator('[data-test="layout-state"]')).toHaveText(
        'Des déplacements ne sont pas encore enregistrés.',
    );

    // Closing the tab asks the browser's own question.
    let unload = '';

    page.once('dialog', (dialog) => {
        unload = dialog.type();
        void dialog.dismiss();
    });
    await page.close({ runBeforeUnload: true });
    await expect.poll(() => unload).toBe('beforeunload');

    // Through a link, accepted: the page is left, nothing saved.
    page.once('dialog', (dialog) => void dialog.accept());
    await links.click();
    await expect(page).toHaveURL(/\/links$/);

    await openHeadOffice(page);
    await expect(page.locator('[data-test="edit-layout"]')).toBeVisible();
    await expect(
        diagram(page).locator('[data-test="diagram-grid"]'),
    ).toHaveCount(0);
});

test('a reader sees the layout saved, without "Modifier la disposition"', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await openHeadOffice(page);

    await expect(page.locator('[data-test="edit-layout"]')).toHaveCount(0);
    await expect(page.locator('[data-test="save-diagram"]')).toBeVisible();

    // The workshop of the demonstration is placed by hand, but for the
    // access point of the quay, on the row below the others.
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: 'Atelier' });
    await expect(
        page.getByRole('heading', { name: 'Schéma physique du site Atelier' }),
    ).toBeVisible();
    await expect(device(page, 'ap-quai')).toHaveAttribute(
        'data-unplaced',
        'true',
    );
    await expect(
        device(page, 'ap-quai').locator('[data-test="device-unplaced"]'),
    ).toHaveText('Non placé');
    await expect(diagram(page).locator('[data-unplaced]')).toHaveCount(1);
    expect(await position(page, 'sw-quai')).toEqual({ x: 96, y: 520 });
    expect((await position(page, 'ap-quai')).y).toBeGreaterThan(520);
    await expect(page.locator('[data-test="edit-layout"]')).toHaveCount(0);
});
