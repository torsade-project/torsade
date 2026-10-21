// torsade/tests/e2e/support/global-setup.ts
import { rebuildDatabase } from './database';

// Rebuilds the test database before each run: the test accounts and the
// demonstration network.
export default async function globalSetup(): Promise<void> {
    await rebuildDatabase();
}
