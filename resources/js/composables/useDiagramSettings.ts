// torsade/resources/js/composables/useDiagramSettings.ts
import type { Ref } from 'vue';
import { ref, watch } from 'vue';

// How the physical diagram is shown, kept by the browser from one site, one
// page and one visit to the next, as the state of the menu is: each device
// in the frame of its place, and the end devices shown.
const STORAGE_KEY = 'physical-diagram';

type Settings = { grouped: boolean; endDevices: boolean };

const DEFAULTS: Settings = { grouped: true, endDevices: true };

// Without storage, or when it refuses to be read (private browsing, site
// data blocked), the defaults apply and the diagram still works.
const readSettings = (): Settings => {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(STORAGE_KEY) ?? '{}',
        );

        if (
            stored === null ||
            typeof stored !== 'object' ||
            Array.isArray(stored)
        ) {
            return { ...DEFAULTS };
        }

        const values = stored as Record<string, unknown>;

        return {
            grouped:
                typeof values.grouped === 'boolean'
                    ? values.grouped
                    : DEFAULTS.grouped,
            endDevices:
                typeof values.endDevices === 'boolean'
                    ? values.endDevices
                    : DEFAULTS.endDevices,
        };
    } catch {
        return { ...DEFAULTS };
    }
};

const writeSettings = (settings: Settings): void => {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch {
        // Not kept beyond this page: the diagram works all the same.
    }
};

export type UseDiagramSettingsReturn = {
    grouped: Ref<boolean>;
    endDevices: Ref<boolean>;
};

/**
 * The settings of the physical diagram, as the user last left them, for
 * every site.
 */
export function useDiagramSettings(): UseDiagramSettingsReturn {
    const settings = readSettings();
    const grouped = ref<boolean>(settings.grouped);
    const endDevices = ref<boolean>(settings.endDevices);

    watch([grouped, endDevices], ([group, ends]) =>
        writeSettings({ grouped: group, endDevices: ends }),
    );

    return { grouped, endDevices };
}
