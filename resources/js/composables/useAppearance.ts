// torsade/resources/js/composables/useAppearance.ts
import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

// The theme of someone who never chose one: the dark theme, the reference
// of the visual identity. "System" remains a choice, not the default.
const DEFAULT_APPEARANCE: Appearance = 'dark';

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

export function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';

        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const APPEARANCES: readonly string[] = ['light', 'dark', 'system'];

// The choice the cookie carries, which the server reads too.
const cookieAppearance = (): Appearance | null => {
    const value = /(?:^|;\s*)appearance=([^;]*)/.exec(document.cookie)?.[1];

    return value !== undefined && APPEARANCES.includes(value)
        ? (value as Appearance)
        : null;
};

// A browser may refuse its storage (private browsing, data of the site
// blocked): the choice is then read from the cookie, and the page still
// works.
const getStoredAppearance = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return localStorage.getItem('appearance') as Appearance | null;
    } catch {
        return cookieAppearance();
    }
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const handleSystemThemeChange = () => {
    const currentAppearance = getStoredAppearance();

    updateTheme(currentAppearance || DEFAULT_APPEARANCE);
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Initialize theme from saved preference or default to dark...
    const savedAppearance = getStoredAppearance();
    updateTheme(savedAppearance || DEFAULT_APPEARANCE);

    // Set up system theme change listener...
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const appearance = ref<Appearance>(DEFAULT_APPEARANCE);

export function useAppearance(): UseAppearanceReturn {
    onMounted(() => {
        const savedAppearance = getStoredAppearance();

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }
    });

    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return prefersDark() ? 'dark' : 'light';
        }

        return appearance.value;
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        // Store in localStorage for client-side persistence, when the
        // browser lets it: the cookie still carries the choice.
        try {
            localStorage.setItem('appearance', value);
        } catch {
            // Kept by the cookie alone.
        }

        // Store in cookie for SSR...
        setCookie('appearance', value);

        updateTheme(value);
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
