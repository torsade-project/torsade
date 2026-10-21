// torsade/tests/e2e/support/database.ts
import { execFileSync } from 'node:child_process';
import {
    baseURL,
    projectRoot,
    serverEnvironment,
    testDatabase,
} from './environment';

const artisan = (
    parameters: string[],
    environment: Record<string, string> = {},
): string =>
    execFileSync('php', ['artisan', ...parameters], {
        cwd: projectRoot,
        env: { ...process.env, ...serverEnvironment, ...environment },
        encoding: 'utf8',
    });

/**
 * Waits for the test server to have served what it was asked before: it
 * answers one request at a time, so its answer to the health check comes
 * once the requests in front of it are done. A request of the test before,
 * still being served while its browser was closed, would otherwise read or
 * write tables that are being dropped and created again. Before the run,
 * when the server is not up yet, there is nothing to wait for.
 */
const quietServer = async (): Promise<void> => {
    await fetch(`${baseURL}/up`).catch(() => undefined);
};

/**
 * Rebuilds the test database: every table is dropped and migrated again,
 * then the test accounts are created, with the demonstration network
 * unless an empty installation is asked for.
 *
 * The database is about to be emptied. If the application would open
 * another one than the test database, for instance because its
 * configuration is cached and ignores the environment, nothing is run.
 */
export async function rebuildDatabase(
    { demonstration }: { demonstration: boolean } = { demonstration: true },
): Promise<void> {
    const description: unknown = JSON.parse(artisan(['db:show', '--json']));
    const database = (
        description as { platform?: { config?: { database?: unknown } } }
    ).platform?.config?.database;

    if (database !== testDatabase) {
        throw new Error(
            `The browser tests only run on the "${testDatabase}" database, and the application would use "${String(database)}". Clear the cached configuration (php artisan config:clear) and try again.`,
        );
    }

    await quietServer();

    artisan(['migrate:fresh', '--seed', '--seeder=E2eSeeder', '--force'], {
        E2E_DEMONSTRATION: demonstration ? 'yes' : 'no',
    });
}
