// torsade/tests/e2e/two-factor.spec.ts
import path from 'node:path';
import type { Locator, Page } from '@playwright/test';
import { projectRoot } from './support/environment';
import { expect, password, test } from './support/fixtures';

// A picture of the window in each theme is kept with the other pictures of
// the run, for a human to look at, or to point a telephone at.
const picture = (theme: string): string =>
    path.join(
        projectRoot,
        'test-results',
        'screenshots',
        theme,
        'two-factor-setup.png',
    );

const WHITE = 'rgb(255, 255, 255)';

// Opens the security settings, where the password is asked once more, and
// starts the two-factor setup. Returns the QR code and the window it is in.
const openSetup = async (
    page: Page,
): Promise<{ dialog: Locator; code: Locator }> => {
    await page.goto('/settings/security');

    if (new URL(page.url()).pathname.endsWith('/confirm-password')) {
        await page.getByLabel('Mot de passe', { exact: true }).fill(password());
        await page
            .getByRole('button', { name: 'Confirmer le mot de passe' })
            .click();
    }

    await expect(page).toHaveURL(/\/settings\/security$/);

    // "Enable" the first time, "continue" once a secret exists.
    await page
        .getByRole('button', {
            name: /^(Activer la double authentification|Poursuivre la configuration)$/,
        })
        .click();

    const dialog = page.getByRole('dialog');
    const code = dialog.getByRole('img', {
        name: 'QR code de la double authentification',
    });

    await expect(code).toBeVisible();

    return { dialog, code };
};

// What the drawing is made of: its colours, and the empty margin left
// around the code on each side, counted in modules.
const measure = (code: Locator) =>
    code.evaluate((frame) => {
        const drawing = frame.querySelector('svg');
        const paper = frame.querySelector('rect');
        const ink = frame.querySelector('path');

        if (!drawing || !paper || !ink) {
            throw new Error(
                'The QR code is not the drawing that was expected.',
            );
        }

        const outer = frame.getBoundingClientRect();
        const inner = drawing.getBoundingClientRect();
        const modules = ink.getBBox().width;
        const module = ink.getBoundingClientRect().width / modules;

        return {
            background: getComputedStyle(frame).backgroundColor,
            paper: getComputedStyle(paper).fill,
            ink: getComputedStyle(ink).fill,
            filters: [frame, drawing, ink].map(
                (element) => getComputedStyle(element).filter,
            ),
            modules,
            margins: [
                inner.left - outer.left,
                inner.top - outer.top,
                outer.right - inner.right,
                outer.bottom - inner.bottom,
            ].map((margin) => margin / module),
        };
    });

// The brightness, from 0 to 255, of four points of a picture of the code:
// the empty margin, then the corner pattern every QR code starts with, which
// is a dark square, a light ring and a dark centre. The picture is read in a
// blank page of its own: the policy of the application rightly refuses an
// image given by a data: address.
const brightness = async (page: Page, code: Locator, modules: number) => {
    const picture = (await code.screenshot()).toString('base64');
    const blank = await page.context().newPage();

    try {
        return await readBrightness(blank, picture, modules);
    } finally {
        await blank.close();
    }
};

const readBrightness = (page: Page, picture: string, modules: number) =>
    page.evaluate(
        async ({ source, count }) => {
            const image = new Image();

            image.src = `data:image/png;base64,${source}`;
            await image.decode();

            const canvas = document.createElement('canvas');

            canvas.width = image.width;
            canvas.height = image.height;

            const context = canvas.getContext('2d');

            if (!context) {
                throw new Error('No canvas to read the picture with.');
            }

            context.drawImage(image, 0, 0);

            // With a margin of four modules on each side.
            const module = image.width / (count + 8);
            const at = (column: number, line: number): number => {
                const [red, green, blue] = context.getImageData(
                    Math.round((column + 4.5) * module),
                    Math.round((line + 4.5) * module),
                    1,
                    1,
                ).data;

                return Math.round(
                    0.2126 * red + 0.7152 * green + 0.0722 * blue,
                );
            };

            return {
                margin: at(-2.5, -2.5),
                corner: at(0, 0),
                ring: at(1, 1),
                centre: at(3, 3),
            };
        },
        { source: picture, count: modules },
    );

const expectDarkOnWhite = async (page: Page, code: Locator): Promise<void> => {
    const drawing = await measure(code);

    // White paper, dark ink, and nothing that turns them around.
    expect(drawing.background).toBe(WHITE);
    expect(drawing.paper).toBe(WHITE);
    expect(drawing.ink).toBe('rgb(28, 33, 38)');
    expect(drawing.filters).toEqual(['none', 'none', 'none']);

    // A QR code has 21 modules, or 4 more for each size above.
    expect(drawing.modules).toBeGreaterThanOrEqual(21);
    expect((drawing.modules - 17) % 4).toBe(0);

    // The empty margin: four modules at least, on each side.
    for (const margin of drawing.margins) {
        expect(margin).toBeGreaterThanOrEqual(3.99);
    }

    // And on the picture itself, as a camera would see it.
    const seen = await brightness(page, code, drawing.modules);

    expect(seen.margin).toBeGreaterThan(245);
    expect(seen.ring).toBeGreaterThan(245);
    expect(seen.corner).toBeLessThan(60);
    expect(seen.centre).toBeLessThan(60);
};

test('the QR code of the two-factor setup is dark on white in both themes', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    const root = page.locator('html');

    // The dark theme, the default one.
    await expect(root).toHaveClass(/(^|\s)dark(\s|$)/);

    const dark = await openSetup(page);

    await expectDarkOnWhite(page, dark.code);

    // The secret is still given as text, next to the code.
    const key = dark.dialog.getByRole('textbox');

    await expect(key).toHaveValue(/^[A-Z2-7]{16,}$/);
    await dark.dialog.screenshot({
        path: picture('dark'),
        animations: 'disabled',
    });

    // The light theme.
    await page.evaluate(() => {
        window.localStorage.setItem('appearance', 'light');
        document.cookie = 'appearance=light;path=/;SameSite=Lax';
    });
    await page.reload();
    await expect(root).not.toHaveClass(/(^|\s)dark(\s|$)/);

    const light = await openSetup(page);

    await expectDarkOnWhite(page, light.code);
    await expect(light.dialog.getByRole('textbox')).toHaveValue(
        /^[A-Z2-7]{16,}$/,
    );
    await light.dialog.screenshot({
        path: picture('light'),
        animations: 'disabled',
    });
});
