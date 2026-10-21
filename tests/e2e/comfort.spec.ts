// torsade/tests/e2e/comfort.spec.ts
import type { Page } from '@playwright/test';
import { expect, openSubnet, test } from './support/fixtures';

type Focused = {
    what: string;
    style: string;
    width: number;
    visible: boolean;
};

// The element that has the keyboard focus, and the outline drawn around it.
const focused = (page: Page): Promise<Focused | null> =>
    page.evaluate(() => {
        const element = document.activeElement;

        if (!element || element === document.body) {
            return null;
        }

        const style = getComputedStyle(element);
        const transparent = /rgba\(\s*0,\s*0,\s*0,\s*0\s*\)|transparent/;

        return {
            what: `<${element.tagName.toLowerCase()}> ${(element.getAttribute('aria-label') ?? element.textContent ?? '').trim().slice(0, 30)}`,
            style: style.outlineStyle,
            width: Number.parseFloat(style.outlineWidth),
            visible: !transparent.test(style.outlineColor),
        };
    });

// Walks through a page with the Tab key and checks each stop.
const everyStopShowsItsFocus = async (
    page: Page,
    stops: number,
): Promise<string[]> => {
    const seen: string[] = [];

    for (let stop = 0; stop < stops; stop++) {
        await page.keyboard.press('Tab');

        const element = await focused(page);

        if (element === null) {
            continue;
        }

        expect(element, element.what).toMatchObject({
            style: 'solid',
            visible: true,
        });
        expect(element.width, element.what).toBeGreaterThanOrEqual(2);

        seen.push(element.what);
    }

    return seen;
};

test('the keyboard focus is visible on every interactive element', async ({
    page,
    logIn,
}) => {
    // Fields, a checkbox and a button.
    await page.goto('/login');
    await expect(page.getByLabel('Adresse e-mail')).toBeFocused();

    const login = await everyStopShowsItsFocus(page, 5);

    expect(login.length).toBeGreaterThanOrEqual(3);

    // Links of the navigation, buttons, a list, sortable headers and the
    // actions of the lines of a table.
    await logIn('editor');
    await page.goto('/vlans');
    await expect(page.getByRole('table')).toBeVisible();

    const list = await everyStopShowsItsFocus(page, 22);

    expect(list.some((what) => what.startsWith('<a>'))).toBe(true);
    expect(list.some((what) => what.startsWith('<button>'))).toBe(true);
    expect(list.some((what) => what.startsWith('<select>'))).toBe(true);

    // The address plan and the fields of a window.
    await openSubnet(page, '10.10.10.0/24');
    await everyStopShowsItsFocus(page, 14);

    await page.getByRole('button', { name: 'Ajouter une adresse' }).click();
    await expect(
        page.getByRole('dialog', { name: 'Nouvelle adresse IP' }),
    ).toBeVisible();

    const dialog = await everyStopShowsItsFocus(page, 9);

    expect(dialog.some((what) => what.startsWith('<input>'))).toBe(true);
    expect(dialog.some((what) => what.startsWith('<select>'))).toBe(true);
});

test.describe('when the system asks for less movement', () => {
    test.use({ reducedMotion: 'reduce' });

    test('nothing is animated, and windows still open and close', async ({
        page,
        logIn,
    }) => {
        await logIn('editor');
        await page.goto('/sites');
        await expect(page.getByRole('table')).toBeVisible();

        // No element of the page keeps a transition or an animation that
        // lasts.
        const lasting = await page.evaluate(() => {
            const seconds = (value: string): number =>
                Math.max(
                    ...value
                        .split(',')
                        .map((part) =>
                            part.trim().endsWith('ms')
                                ? Number.parseFloat(part) / 1000
                                : Number.parseFloat(part),
                        ),
                );

            return [...document.body.querySelectorAll('*')]
                .map((element) => {
                    const style = getComputedStyle(element);

                    return Math.max(
                        seconds(style.transitionDuration),
                        seconds(style.animationDuration),
                    );
                })
                .filter((duration) => duration > 0.001).length;
        });

        expect(lasting).toBe(0);

        await page.getByRole('button', { name: 'Nouveau site' }).click();

        const dialog = page.getByRole('dialog', { name: 'Nouveau site' });

        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Annuler' }).click();
        await expect(dialog).toBeHidden();
    });
});

test('transitions run when the system does not ask for less movement', async ({
    page,
    logIn,
}) => {
    // The control of the test above: without the setting, the buttons of
    // the same page do have a transition.
    await logIn('editor');
    await page.goto('/sites');

    const duration = await page
        .getByRole('button', { name: 'Nouveau site' })
        .evaluate((node) =>
            Number.parseFloat(getComputedStyle(node).transitionDuration),
        );

    expect(duration).toBeGreaterThan(0.05);
});
