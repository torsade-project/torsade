// torsade/playwright.config.ts
import { randomBytes } from 'node:crypto';
import { defineConfig, devices } from '@playwright/test';
import {
    baseURL,
    port,
    projectRoot,
    serverEnvironment,
} from './tests/e2e/support/environment';

// The password of the test accounts is drawn for each run. It only lives in
// the environment of this process, which the test workers and the seeder
// inherit: it is written nowhere.
process.env.E2E_PASSWORD ??= randomBytes(24).toString('base64url');

export default defineConfig({
    testDir: 'tests/e2e',
    outputDir: 'test-results',
    globalSetup: './tests/e2e/support/global-setup.ts',

    // One browser at a time: the tests share one database and one
    // single-threaded server, and some of them write.
    workers: 1,
    fullyParallel: false,
    retries: 0,

    // The server answers one request at a time: a page and its scripts may
    // take a few seconds on a busy machine.
    timeout: 60_000,
    expect: { timeout: 10_000 },
    forbidOnly: Boolean(process.env.CI),
    reporter: 'list',

    use: {
        baseURL,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        trace: 'retain-on-failure',
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],

    webServer: {
        // Without --no-reload, the server drops the environment it is given
        // and would open the development database.
        command: `php artisan serve --no-reload --host=127.0.0.1 --port=${port}`,
        cwd: projectRoot,
        env: serverEnvironment,
        url: `${baseURL}/login`,
        reuseExistingServer: false,
        timeout: 60_000,
    },
});
