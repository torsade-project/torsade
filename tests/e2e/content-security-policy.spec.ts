// torsade/tests/e2e/content-security-policy.spec.ts
import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';

// The policy of the application in a real browser: what it refuses is
// reported, and fails the test that brought it about; what the
// application inserts itself wears the nonce of the first document, which
// stays in force across the navigations of Inertia.

// The nonce of the policy of the document, read from its header.
const nonceOfThePolicy = async (page: Page, path: string): Promise<string> => {
    const response = await page.goto(path);
    const policy =
        (await response?.headerValue('content-security-policy')) ?? '';

    return /'nonce-([^']+)'/.exec(policy)?.[1] ?? '';
};

test('a script without the nonce is refused, and the refusal is reported', async ({
    page,
    policyViolations,
}) => {
    await page.goto('/login');
    await page.evaluate(() => {
        const script = document.createElement('script');

        script.textContent = 'document.body.dataset.ran = "oui"';
        document.head.append(script);
    });

    await expect
        .poll(() => policyViolations.join('\n'))
        .toContain('script-src');
    await expect(page.locator('body')).not.toHaveAttribute('data-ran', 'oui');

    // Brought about on purpose: it does not fail this test.
    policyViolations.length = 0;
});

test('a style element without the nonce is refused, and the refusal is reported', async ({
    page,
    policyViolations,
}) => {
    await page.goto('/login');
    await page.evaluate(() => {
        const style = document.createElement('style');

        style.textContent = 'body { visibility: hidden; }';
        document.head.append(style);
    });

    await expect.poll(() => policyViolations.join('\n')).toContain('style-src');
    await expect(page.locator('body')).toBeVisible();

    policyViolations.length = 0;
});

test('what the application inserts wears the nonce of the first document, across navigations', async ({
    page,
    logIn,
}) => {
    await logIn('reader');

    const nonce = await nonceOfThePolicy(page, '/dashboard');

    expect(nonce).not.toBe('');

    // The meta tag of the document holds the nonce of its policy.
    expect(
        await page.evaluate(
            () =>
                document.querySelector<HTMLMetaElement>(
                    'meta[property="csp-nonce"]',
                )?.nonce,
        ),
    ).toBe(nonce);

    // A navigation of Inertia keeps the document, and so its policy.
    await page
        .getByRole('navigation', { name: 'Navigation principale' })
        .getByRole('link', { name: 'Sites', exact: true })
        .click();
    await expect(page).toHaveURL(/\/sites$/);

    const styles = await page.evaluate(() =>
        [...document.querySelectorAll('style')].map((style) => style.nonce),
    );

    expect(styles.length).toBeGreaterThan(0);

    for (const styleNonce of styles) {
        expect(styleNonce).toBe(nonce);
    }
});
