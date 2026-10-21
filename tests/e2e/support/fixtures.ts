// torsade/tests/e2e/support/fixtures.ts
import fs from 'node:fs';
import path from 'node:path';
import { test as base, expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { projectRoot } from './environment';

// The accounts created for the tests: one per role, and one that still has
// its temporary password.
export const accounts = {
    admin: { email: 'admin@example.test', name: 'Admin de test' },
    editor: { email: 'editor@example.test', name: 'Éditeur de test' },
    reader: { email: 'reader@example.test', name: 'Lecteur de test' },
    newcomer: { email: 'newcomer@example.test', name: 'Arrivant de test' },
} as const;

export type Account = keyof typeof accounts;

// The password drawn for this run, shared by the four accounts.
export const password = (): string => {
    const value = process.env.E2E_PASSWORD;

    if (!value) {
        throw new Error('E2E_PASSWORD is not set.');
    }

    return value;
};

// Requests that do not leave the browser.
const INTERNAL_PROTOCOLS = ['data:', 'blob:', 'about:'];

// The scripts, styles and fonts of the build, as "npm run build" wrote them.
const BUILD_ASSETS = path.join(projectRoot, 'public', 'build', 'assets');

// The file of the build behind an address, if there is one.
const buildAsset = (pathname: string): string | null => {
    const prefix = '/build/assets/';

    if (!pathname.startsWith(prefix)) {
        return null;
    }

    const file = path.join(
        BUILD_ASSETS,
        path.basename(decodeURIComponent(pathname.slice(prefix.length))),
    );

    return fs.existsSync(file) ? file : null;
};

type Options = {
    // Whether the files of the build are asked to the server like the rest.
    assetsFromServer: boolean;
};

type Fixtures = {
    // What the browser asked for during the test.
    requests: { local: string[]; external: string[] };
    // What the Content-Security-Policy refused during the test.
    policyViolations: string[];
    logIn: (account: Account) => Promise<void>;
};

// The name under which the pages report what the policy refused.
const REPORT = 'reportPolicyViolation';

export const test = base.extend<Fixtures & Options>({
    // The test server answers one request per connection, and Windows keeps
    // a closed connection for minutes before its port can be used again:
    // a run that asked the server for the forty files of every page would
    // use up the ports of the machine. The pages and their data come from
    // the server; the files of the build are read from the disk, unless a
    // test is about the way they are served.
    assetsFromServer: [false, { option: true }],

    // The application never calls another host. Every request is watched:
    // one that would leave the local server is blocked, and fails the test.
    requests: [
        async ({ context, baseURL, assetsFromServer }, use) => {
            const origin = new URL(baseURL ?? '').origin;
            const requests = {
                local: [] as string[],
                external: [] as string[],
            };

            await context.route('**/*', async (route) => {
                const url = new URL(route.request().url());

                if (
                    url.origin === origin ||
                    INTERNAL_PROTOCOLS.includes(url.protocol)
                ) {
                    requests.local.push(url.href);

                    const asset = assetsFromServer
                        ? null
                        : buildAsset(url.pathname);

                    if (asset) {
                        await route.fulfill({ path: asset });
                    } else {
                        await route.continue();
                    }

                    return;
                }

                requests.external.push(url.href);
                await route.abort();
            });

            await use(requests);

            expect(
                requests.external,
                'requests sent to another host than the application',
            ).toEqual([]);
        },
        { auto: true },
    ],

    // Nothing the application does is refused by its policy. Every page,
    // window and frame reports what the policy refuses, from a script
    // installed in the context before any of its own, so that a full
    // reload keeps it; the console, which also tells of a refusal, is read
    // too. A test that brings about one fails. The policy is never turned
    // off for the tests.
    policyViolations: [
        async ({ context, bypassCSP }, use) => {
            expect(bypassCSP, 'the policy is turned off').toBeFalsy();

            const violations: string[] = [];

            await context.exposeBinding(
                REPORT,
                (_source, violation: string) => {
                    violations.push(violation);
                },
            );
            await context.addInitScript((report: string) => {
                document.addEventListener(
                    'securitypolicyviolation',
                    (event) => {
                        const send = (
                            window as unknown as Record<
                                string,
                                (text: string) => void
                            >
                        )[report];

                        send?.(
                            [
                                event.effectiveDirective,
                                event.blockedURI || 'inline',
                                `${event.sourceFile}:${event.lineNumber}`,
                                event.sample,
                            ].join(' '),
                        );
                    },
                );
            }, REPORT);

            context.on('console', (message) => {
                if (/Content Security Policy/i.test(message.text())) {
                    violations.push(`console: ${message.text()}`);
                }
            });

            await use(violations);

            expect(
                violations,
                'what the Content-Security-Policy refused',
            ).toEqual([]);
        },
        { auto: true },
    ],

    logIn: async ({ page }, use) => {
        await use(async (account) => {
            await submitLogin(page, accounts[account].email, password());

            // The home page tells the session is open. The name of the
            // account would too, but on a narrow screen it is folded away
            // with the navigation.
            await expect(page).toHaveURL(/\/dashboard$/);
            await expect(page).toHaveTitle(/Accueil/);
        });
    },
});

export { expect };

// Fills in and sends the login form, whatever the answer is.
export const submitLogin = async (
    page: Page,
    email: string,
    secret: string,
): Promise<void> => {
    await page.goto('/login');
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(secret);
    await page.getByRole('button', { name: 'Se connecter' }).click();
};

// The table of the documented addresses of a subnet page: the one whose
// columns can be sorted by assignment.
export const addressTable = (page: Page): Locator =>
    page
        .getByRole('table')
        .filter({ has: page.getByRole('button', { name: 'Affectation' }) });

// The lines of a table that hold data, without the header line.
export const dataRows = (table: Locator): Locator =>
    table.getByRole('row').filter({ has: table.page().getByRole('cell') });

// Opens the page of a subnet from the list of subnets.
export const openSubnet = async (page: Page, cidr: string): Promise<void> => {
    await page.goto('/subnets');
    await page.getByRole('link', { name: cidr, exact: true }).click();
    await expect(page.getByRole('heading', { name: cidr })).toBeVisible();
};
