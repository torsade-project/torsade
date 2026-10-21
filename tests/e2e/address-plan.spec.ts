// torsade/tests/e2e/address-plan.spec.ts
import type { Locator, Page } from '@playwright/test';
import { expect, openSubnet, test } from './support/fixtures';

const plan = (page: Page): Locator =>
    page.getByRole('region', { name: "Plan d'adressage" });

// A cell is found by its tooltip: its address, its state, its host name.
const cell = (page: Page, title: string): Locator =>
    plan(page).getByTitle(title, { exact: true });

const cells = (page: Page): Locator => plan(page).getByTitle(/ — /);

const STATES = [
    'documented',
    'gateway',
    'planned',
    'retired',
    'dhcp_range',
    'reserved_range',
    'reservation',
    'free',
    'unusable',
] as const;

type State = (typeof STATES)[number];

// The cells of a state, found by the attribute they carry for that purpose.
const cellsIn = (page: Page, state: State): Locator =>
    plan(page).locator(`[title][data-state="${state}"]`);

// The shape of a cell, whatever its colour: its outline, what is inside,
// and whether a dot is drawn in its centre.
type Shape = {
    outline: 'thin' | 'thick' | 'dashed';
    inside:
        | 'empty'
        | 'full'
        | 'struck through'
        | 'lower half'
        | 'stripes'
        | 'dots';
    dot: boolean;
};

// The shape of each state: no two are alike, so that the plan reads without
// its colours.
const SHAPES: Record<State, Shape> = {
    free: { outline: 'thin', inside: 'empty', dot: false },
    unusable: { outline: 'thin', inside: 'struck through', dot: false },
    documented: { outline: 'thin', inside: 'full', dot: false },
    gateway: { outline: 'thin', inside: 'full', dot: true },
    reservation: { outline: 'thin', inside: 'lower half', dot: false },
    planned: { outline: 'dashed', inside: 'empty', dot: false },
    retired: { outline: 'thick', inside: 'empty', dot: false },
    dhcp_range: { outline: 'thin', inside: 'stripes', dot: false },
    reserved_range: { outline: 'thin', inside: 'dots', dot: false },
};

const shapeOf = (element: Locator): Promise<Shape> =>
    element.evaluate((node): Shape => {
        const style = getComputedStyle(node);
        const centre = getComputedStyle(node, '::after');
        const pattern = style.backgroundImage;
        const clear = /rgba\(0, 0, 0, 0\)|transparent/;

        let inside: Shape['inside'] = clear.test(style.backgroundColor)
            ? 'empty'
            : 'full';

        if (pattern.startsWith('repeating-linear-gradient')) {
            inside = 'stripes';
        } else if (pattern.startsWith('radial-gradient')) {
            inside = 'dots';
        } else if (/to (right top|top right)/.test(pattern)) {
            inside = 'struck through';
        } else if (pattern.includes('to top')) {
            inside = 'lower half';
        }

        return {
            outline:
                style.borderTopStyle === 'dashed'
                    ? 'dashed'
                    : Number.parseFloat(style.borderTopWidth) >= 2.5
                      ? 'thick'
                      : 'thin',
            inside,
            dot:
                centre.content !== 'none' &&
                centre.content !== 'normal' &&
                !clear.test(centre.backgroundColor) &&
                centre.backgroundColor !== style.backgroundColor,
        };
    });

const sizeOf = (element: Locator): Promise<string> =>
    element.evaluate((node) => {
        const box = node.getBoundingClientRect();

        return `${box.width} x ${box.height}`;
    });

const createSubnet = async (page: Page, cidr: string): Promise<void> => {
    await page.goto('/subnets/create');
    await page.getByLabel('Adresse du réseau').fill(cidr);
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByRole('heading', { name: cidr })).toBeVisible();
};

test('the plan of a subnet shows the state of each of its addresses', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    // The office network of the demonstration: gateway .254, DHCP range
    // .100 to .199, static addresses and reservations.
    await openSubnet(page, '10.10.10.0/24');

    // One cell per address, on eight lines of thirty-two.
    await expect(cells(page)).toHaveCount(256);

    for (const label of ['.0', '.32', '.64', '.96', '.128', '.160', '.192']) {
        await expect(
            plan(page).getByText(label, { exact: true }),
        ).toBeVisible();
    }

    await expect(plan(page).getByText('.224', { exact: true })).toBeVisible();

    const office = {
        unusable: cell(page, '10.10.10.0 — Non utilisable'),
        gateway: cell(page, '10.10.10.254 — Passerelle'),
        documented: cell(page, '10.10.10.20 — Documentée — imp-accueil'),
        dhcp_range: cell(page, '10.10.10.150 — Plage DHCP'),
        reservation: cell(
            page,
            '10.10.10.111 — Réservation DHCP — pc-compta-01',
        ),
        free: cell(page, '10.10.10.1 — Libre'),
    };

    for (const [state, element] of Object.entries(office)) {
        await expect(element, state).toBeVisible();
    }

    await expect(cell(page, '10.10.10.255 — Non utilisable')).toBeVisible();
    await expect(cell(page, '10.10.10.100 — Plage DHCP')).toBeVisible();
    await expect(cell(page, '10.10.10.199 — Plage DHCP')).toBeVisible();
    await expect(cell(page, '10.10.10.200 — Libre')).toBeVisible();

    // The legend names the nine states.
    await expect(plan(page).getByRole('listitem')).toHaveText([
        'Documentée',
        'Passerelle',
        'Prévue',
        'Retirée',
        'Plage DHCP',
        'Plage réservée',
        'Réservation DHCP',
        'Libre',
        'Non utilisable',
    ]);

    // The drawing is hidden from assistive technologies, which get a
    // summary in words instead.
    await expect(
        plan(page).locator('[aria-hidden="true"]').getByTitle(/ — /),
    ).toHaveCount(256);

    const summary = plan(page).getByText(
        /^Plan d'adressage de 10\.10\.10\.0\/24/,
    );

    for (const sentence of [
        '256 adresses.',
        'Documentée : 6.',
        'Passerelle : 1.',
        'Prévue : 0.',
        'Retirée : 0.',
        'Plage DHCP : 86.',
        'Plage réservée : 0.',
        'Réservation DHCP : 14.',
        'Libre : 147.',
        'Non utilisable : 2.',
    ]) {
        await expect(summary).toContainText(sentence);
    }

    // Each cell carries its state, and there are as many of each as the
    // summary says.
    for (const [state, element] of Object.entries(office)) {
        await expect(element, state).toHaveAttribute('data-state', state);
    }

    await expect(cellsIn(page, 'documented')).toHaveCount(6);
    await expect(cellsIn(page, 'gateway')).toHaveCount(1);
    await expect(cellsIn(page, 'dhcp_range')).toHaveCount(86);
    await expect(cellsIn(page, 'reservation')).toHaveCount(14);
    await expect(cellsIn(page, 'free')).toHaveCount(147);
    await expect(cellsIn(page, 'unusable')).toHaveCount(2);
    await expect(cellsIn(page, 'planned')).toHaveCount(0);
    await expect(cellsIn(page, 'retired')).toHaveCount(0);
    await expect(cellsIn(page, 'reserved_range')).toHaveCount(0);

    // The server network has a planned address and a retired one.
    await openSubnet(page, '10.10.20.0/24');

    await expect(cell(page, '10.10.20.31 — Prévue — nas-02')).toHaveAttribute(
        'data-state',
        'planned',
    );
    await expect(
        cell(page, '10.10.20.40 — Retirée — srv-intranet-01'),
    ).toHaveAttribute('data-state', 'retired');
    await expect(cell(page, '10.10.20.254 — Passerelle')).toHaveAttribute(
        'data-state',
        'gateway',
    );
});

test('each state has a shape of its own, in the plan and in the legend', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    // The nine shapes differ from one another without any colour.
    expect(
        new Set(Object.values(SHAPES).map((shape) => JSON.stringify(shape)))
            .size,
    ).toBe(9);

    const found = new Set<State>();

    // The three demonstration networks that hold the nine states between
    // them: the office network, the server network, and the management
    // network with its reserved range.
    for (const cidr of ['10.10.10.0/24', '10.10.20.0/24', '10.10.99.0/24']) {
        await openSubnet(page, cidr);

        // The legend: nine entries, each one carrying its state and drawn
        // as the cell it explains, at the same size.
        const entries = plan(page).getByRole('listitem');

        await expect(entries).toHaveCount(STATES.length);

        const size = await sizeOf(cellsIn(page, 'free').first());

        for (const [position, state] of STATES.entries()) {
            const entry = entries.nth(position);
            const sample = entry.locator('[data-state]');

            await expect(entry, state).toHaveAttribute('data-state', state);
            await expect(sample, state).toHaveAttribute('data-state', state);
            expect(await shapeOf(sample), `legend, ${state}`).toEqual(
                SHAPES[state],
            );
            expect(await sizeOf(sample), `legend, ${state}`).toBe(size);
        }

        // The plan: every cell of a state has the shape of that state.
        for (const state of STATES) {
            const sameState = cellsIn(page, state);
            const count = await sameState.count();

            if (count === 0) {
                continue;
            }

            found.add(state);

            for (const position of new Set([0, count - 1])) {
                expect(
                    await shapeOf(sameState.nth(position)),
                    `${cidr}, ${state}`,
                ).toEqual(SHAPES[state]);
            }
        }
    }

    // Every state of the demonstration data was met in a plan.
    expect([...found].sort()).toEqual([...STATES].sort());
});

test('a plan is drawn up to 512 addresses, and a message says so beyond', async ({
    page,
    logIn,
}) => {
    await logIn('editor');

    // A /23: 512 addresses, the largest subnet with a plan.
    await createSubnet(page, '10.99.0.0/23');

    await expect(cells(page)).toHaveCount(512);
    await expect(cell(page, '10.99.0.0 — Non utilisable')).toBeVisible();
    await expect(cell(page, '10.99.0.255 — Libre')).toBeVisible();
    await expect(cell(page, '10.99.1.0 — Libre')).toBeVisible();
    await expect(cell(page, '10.99.1.255 — Non utilisable')).toBeVisible();
    await expect(plan(page).getByText('.0.0', { exact: true })).toBeVisible();
    await expect(plan(page).getByText('.1.224', { exact: true })).toBeVisible();

    // A /22: 1024 addresses, no plan.
    await createSubnet(page, '10.98.0.0/22');

    await expect(
        plan(page).getByText(
            /Ce sous-réseau compte 1\s024 adresses : le plan n'est dessiné que jusqu'à 512 adresses\./,
        ),
    ).toBeVisible();
    await expect(cells(page)).toHaveCount(0);
    await expect(plan(page).getByRole('listitem')).toHaveCount(0);

    // The free addresses are still listed.
    await expect(
        page.getByRole('region', { name: 'Adresses libres' }),
    ).toContainText('10.98.0.1 – 10.98.3.254');
});
