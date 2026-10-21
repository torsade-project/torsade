// torsade/tests/e2e/external-requests.spec.ts
import { expect, openSubnet, test } from './support/fixtures';

// Every test watches the requests of the browser and fails if one leaves
// the application. These two check the watch itself, then walk through the
// screens, where the browser asks the server for every file of the build.
test.use({ assetsFromServer: true });

test('a request to another host is caught and blocked', async ({
    page,
    requests,
}) => {
    await page.goto('/login');

    // A reserved name that resolves nowhere: the request must not even try.
    await expect(page.goto('https://external.invalid/probe')).rejects.toThrow();

    expect(requests.external).toEqual(['https://external.invalid/probe']);

    // This one was expected.
    requests.external.length = 0;
});

test('no screen of the application calls another host', async ({
    page,
    logIn,
    requests,
    baseURL,
}) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    await logIn('admin');

    const paths = [
        '/dashboard',
        '/sites',
        '/catalog-vlans',
        '/vlans',
        '/vlans/create',
        '/address-blocks',
        '/address-blocks/create',
        '/subnets',
        '/subnets/create',
        '/users',
        '/users/create',
        '/activity-log',
        '/settings/profile',
        '/settings/appearance',
        '/settings/security',
        '/an-address-that-does-not-exist',
    ];

    for (const path of paths) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
    }

    await openSubnet(page, '10.10.10.0/24');
    await page.waitForLoadState('networkidle');

    // The health check: one word of plain text, and nothing loaded with it.
    const before = requests.local.length;
    const health = await page.goto('/up');

    await page.waitForLoadState('networkidle');

    expect(health?.status()).toBe(200);
    expect(health?.headers()['content-type']).toBe('text/plain; charset=UTF-8');
    expect(await health?.text()).toBe('OK');
    expect(
        requests.local
            .slice(before)
            // A browser may ask for the icon of any address it shows.
            .filter((url) => !url.endsWith('/favicon.ico')),
    ).toEqual([`${baseURL}/up`]);

    // The pages, their scripts and their styles all came from the
    // application itself.
    expect(requests.local.length).toBeGreaterThan(paths.length);
    expect(
        requests.local.filter((url) => !url.startsWith(`${baseURL}/`)),
    ).toEqual([]);
    expect(requests.external).toEqual([]);
});
