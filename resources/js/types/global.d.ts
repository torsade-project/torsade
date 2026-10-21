// torsade/resources/js/types/global.d.ts
import type { Directive } from 'vue';
import type { Replacements } from '@/lib/i18n';
import type { Auth } from '@/types/auth';
import type { CatalogConflict } from '@/types/network';
import type { FlashToast } from '@/types/ui';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        // What the server sends once, with the answer that follows an
        // action.
        flashDataType: {
            toast?: FlashToast;
            catalogConflict?: CatalogConflict;
            [key: string]: unknown;
        };
        sharedPageProps: {
            name: string;
            timezone: string;
            // Null for a visitor who is not signed in.
            appVersion: string | null;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
        $t: (key: string, replacements?: Replacements) => string;
        $tc: (
            key: string,
            count: number,
            replacements?: Replacements,
        ) => string;
    }
}
