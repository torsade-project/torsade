// torsade/tests/e2e/support/environment.ts
import { fileURLToPath } from 'node:url';

// The browser tests run against a local server of their own, on a fixed
// port, so that they never meet a development server.
export const port = 8125;

export const baseURL = `http://127.0.0.1:${port}`;

export const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));

// The only database the tests may rebuild.
export const testDatabase = 'torsade_test';

// What the server and the Artisan commands of a run receive on top of the
// local configuration. The seeder of the test accounts only accepts the
// testing environment.
export const serverEnvironment = {
    APP_ENV: 'testing',
    DB_DATABASE: testDatabase,
} as const;
