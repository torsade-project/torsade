// torsade/tests/e2e/logical-diagram.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// The logical diagram of the sites of the demonstration: the subnets as
// bars, the internet, the devices plugged into them, from the top down, the
// gateway tagged; each subnet and each device a link to its page; the bars
// and the internet placed by hand, saved, found again, then given back to
// the automatic layout; the deviations told on the bars. Each scenario
// leaves the diagram laid out automatically, as the demonstration has it.

const diagram = (page: Page): Locator => page.locator('[data-test="diagram"]');

const device = (page: Page, name: string): Locator =>
    diagram(page).locator(`a[data-device="${name}"]`);

const bar = (page: Page, cidr: string): Locator =>
    diagram(page).locator(`a[data-subnet="${cidr}"]`);

const internet = (page: Page): Locator =>
    diagram(page).locator('g[data-internet]');

const position = async (node: Locator): Promise<{ x: number; y: number }> => ({
    x: Number(await node.getAttribute('data-x')),
    y: Number(await node.getAttribute('data-y')),
});

const open = async (page: Page, site: string, first: string): Promise<void> => {
    await page.goto('/diagrams/logical');
    await page
        .getByLabel('Site', { exact: true })
        .selectOption({ label: site });
    await expect(
        page.getByRole('heading', { name: `Schéma logique du site ${site}` }),
    ).toBeVisible();
    await expect(device(page, first)).toBeVisible();
};

const startEditing = async (page: Page): Promise<void> => {
    await page.locator('[data-test="edit-layout"]').click();
    await expect(page.locator('[data-test="layout-bar"]')).toBeVisible();
    await expect(
        diagram(page).locator('[data-test="diagram-grid"]'),
    ).toHaveCount(1);
};

test('the logical diagram of the head office draws its subnets, the internet and its devices from the top down, with its legend and its summary', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await open(page, 'Siège', 'fw-siege');

    // The bars: the address, the name and the VLAN, the gateway and the
    // other addresses.
    await expect(bar(page, '10.10.10.0/24')).toContainText(
        'VLAN 10 Bureautique',
    );
    await expect(bar(page, '10.10.10.0/24')).toContainText(
        /Passerelle\s*10\.10\.10\.254/,
    );
    await expect(bar(page, '10.10.10.0/24')).toContainText(
        /\d+ autres adresses/,
    );
    await expect(bar(page, '203.0.113.8/29')).toContainText(
        'Accès Internet du siège',
    );
    await expect(bar(page, '172.16.255.0/31')).toContainText(
        'Tunnel VPN siège – atelier',
    );

    // The internet, linked to the access of the carrier alone.
    await expect(internet(page)).toContainText('Internet');
    await expect(
        diagram(page).locator('[data-connection^="internet-"]'),
    ).toHaveCount(1);

    // Every switch of the head office, and the firewall of the workshop at
    // the edge, by the tunnel.
    for (const name of [
        'sw-coeur-01',
        'sw-coeur-02',
        'sw-etage-1',
        'sw-etage-2',
        'sw-serveurs',
    ]) {
        await expect(device(page, name)).toBeVisible();
    }

    await expect(device(page, 'fw-atelier')).toContainText('Atelier');

    // The firewall is the gateway of the network of the administration.
    await expect(
        diagram(page)
            .locator('[data-test="connection-label"][data-label-of="fw-siege"]')
            .filter({ hasText: 'passerelle' }),
    ).toContainText('10.10.99.254');

    // From the top down: the internet, the access of the carrier, the
    // firewall, the segments, the switches.
    const heights = [
        (await position(internet(page))).y,
        (await position(bar(page, '203.0.113.8/29'))).y,
        (await position(device(page, 'fw-siege'))).y,
        (await position(bar(page, '10.10.10.0/24'))).y,
        (await position(device(page, 'sw-coeur-01'))).y,
    ];

    expect([...heights].sort((a, b) => a - b)).toEqual(heights);
    expect(new Set(heights).size).toBe(heights.length);

    // Its own legend, and what it shows in words.
    const legend = page.locator('[data-test="diagram-legend"]');

    for (const entry of [
        'Sous-réseau',
        'Sous-réseau prévu',
        'Internet',
        'Branchement à un sous-réseau',
        'Passerelle du sous-réseau',
        'Écart',
    ]) {
        await expect(legend).toContainText(entry);
    }

    await expect(page.locator('[data-test="diagram-summary"]')).toHaveText(
        /^\d+ sous-réseaux, Internet, \d+ équipements, \d+ branchements, \d+ écarts?$/,
    );

    // A reader reads it, saves it and prints it, and places nothing.
    await expect(page.locator('[data-test="save-diagram"]')).toBeEnabled();
    await expect(page.locator('[data-test="edit-layout"]')).toHaveCount(0);
});

test('a device and a subnet of the diagram lead to their pages', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await open(page, 'Siège', 'fw-siege');

    await device(page, 'fw-siege').click();
    await expect(
        page.getByRole('heading', { name: 'fw-siege', exact: true }),
    ).toBeVisible();

    await page.goBack();
    await bar(page, '10.10.10.0/24').click();
    await expect(page).toHaveURL(/\/subnets\/\d+$/);
    await expect(page.getByText('10.10.10.0/24').first()).toBeVisible();
});

test('a bar and the internet are moved, saved and found again, then laid out automatically again', async ({
    page,
    logIn,
}) => {
    await logIn('editor');
    await open(page, 'Siège', 'fw-siege');

    // Where the automatic layout puts the bar of the offices.
    const automatic = await position(bar(page, '10.10.10.0/24'));

    await startEditing(page);

    await expect(page.locator('[data-test="layout-state"]')).toHaveText(
        "Rien n'a été déplacé pour l'instant.",
    );

    // With the mouse: the bar of the offices, dragged.
    const offices = bar(page, '10.10.10.0/24');
    const before = await position(offices);

    await offices.scrollIntoViewIfNeeded();

    const box = await offices.boundingBox();

    expect(box).not.toBeNull();

    if (box !== null) {
        await page.mouse.move(box.x + 12, box.y + 12);
        await page.mouse.down();
        await page.mouse.move(box.x + 112, box.y + 72, { steps: 6 });
        await expect(offices).toBeFocused();
        await page.mouse.up();
    }

    const dragged = await position(offices);

    expect(dragged.x).toBeGreaterThan(before.x);
    expect(dragged.y).toBeGreaterThan(before.y);
    expect(dragged.x % 8).toBe(0);
    expect(dragged.y % 8).toBe(0);

    // With the keyboard: the internet, by 8 to the right, then by 40 down
    // with Shift.
    const cloud = await position(internet(page));

    await internet(page).focus();
    await expect(internet(page)).toHaveAttribute(
        'aria-describedby',
        'diagram-edit-help',
    );
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('Shift+ArrowDown');
    expect(await position(internet(page))).toEqual({
        x: cloud.x + 8,
        y: cloud.y + 40,
    });
    await expect(page.locator('[data-test="layout-state"]')).toHaveText(
        'Des déplacements ne sont pas encore enregistrés.',
    );

    // Every node drawn is saved, then found again after a reload.
    const placed = {
        offices: await position(offices),
        internet: await position(internet(page)),
        firewall: await position(device(page, 'fw-siege')),
    };

    await page.locator('[data-test="save-layout"]').click();
    await expect(
        page.getByText(/^Disposition enregistrée : \d+ éléments placés\.$/),
    ).toBeVisible();
    await expect(page.locator('[data-test="layout-bar"]')).toBeHidden();

    await page.reload();
    await expect(device(page, 'fw-siege')).toBeVisible();
    expect({
        offices: await position(bar(page, '10.10.10.0/24')),
        internet: await position(internet(page)),
        firewall: await position(device(page, 'fw-siege')),
    }).toEqual(placed);
    await expect(diagram(page).locator('[data-unplaced]')).toHaveCount(0);

    // Back to the automatic layout, after a confirmation.
    await startEditing(page);
    await page.locator('[data-test="reset-layout"]').click();

    const confirmation = page.getByRole('dialog', {
        name: 'Réorganiser automatiquement le schéma ?',
    });

    await confirmation
        .getByRole('button', { name: 'Réorganiser automatiquement' })
        .click();
    await expect(
        page.getByText('Le schéma est de nouveau disposé automatiquement.'),
    ).toBeVisible();
    await expect(page.locator('[data-test="layout-bar"]')).toBeHidden();
    expect(await position(bar(page, '10.10.10.0/24'))).toEqual(automatic);
});

test('the deviations of the workshop are told on their bar, from the keyboard, and in their list', async ({
    page,
    logIn,
}) => {
    await logIn('reader');
    await open(page, 'Atelier', 'fw-atelier');

    // The backup server of the head office, at the edge, by its address on
    // the network of production.
    await expect(device(page, 'srv-sauvegarde-01')).toContainText('Siège');

    // The VLAN numbered otherwise than in the catalogue, on its bar.
    const warning = diagram(page).locator(
        '[data-test="subnet-deviation"][data-subnet-warning="10.20.50.0/24"]',
    );

    await warning.focus();

    const tooltip = page.locator('[data-test="deviation-tooltip"]');

    await expect(tooltip).toContainText('VID 150, catalogue : 50');
    await page.keyboard.press('Escape');
    await expect(tooltip).toHaveCount(0);

    const list = page.locator('[data-test="diagram-deviations"]');

    await expect(list).toContainText('10.20.50.0/24');
    await expect(list).toContainText('VID 150, catalogue : 50');
});
