// torsade/resources/js/composables/useMenuGroups.ts
import type { Ref } from 'vue';
import { ref, watch } from 'vue';

// What the user opened or closed in the menu, kept by the browser from one
// page and one visit to the next.
const STORAGE_KEY = 'menu-groups';

type Choices = Record<string, boolean>;

// Without storage, or when it refuses to be read (private browsing, site
// data blocked), nothing is remembered and the menu still works.
const readChoices = (): Choices => {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(STORAGE_KEY) ?? '{}',
        );

        if (
            stored === null ||
            typeof stored !== 'object' ||
            Array.isArray(stored)
        ) {
            return {};
        }

        return Object.fromEntries(
            Object.entries(stored).filter(
                (entry): entry is [string, boolean] =>
                    typeof entry[1] === 'boolean',
            ),
        );
    } catch {
        return {};
    }
};

const writeChoices = (choices: Choices): void => {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(choices));
    } catch {
        // Not kept beyond this page: the menu works all the same.
    }
};

export type UseMenuGroupsReturn = {
    open: Ref<Choices>;
    toggle: (key: string) => void;
};

/**
 * Which groups of the menu are open. The group of the page shown is open
 * when it loads, and opens again when a page of a closed group is reached;
 * the others are as the user left them.
 */
export function useMenuGroups(
    current: Ref<string | null>,
): UseMenuGroupsReturn {
    const choices = readChoices();
    const open = ref<Choices>({ ...choices });

    watch(
        current,
        (key) => {
            if (key !== null) {
                open.value = { ...open.value, [key]: true };
            }
        },
        { immediate: true },
    );

    const toggle = (key: string): void => {
        const value = !(open.value[key] ?? false);

        open.value = { ...open.value, [key]: value };
        choices[key] = value;
        writeChoices(choices);
    };

    return { open, toggle };
}
