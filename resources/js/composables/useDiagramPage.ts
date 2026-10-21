// torsade/resources/js/composables/useDiagramPage.ts
//
// The page of a diagram of a site: the site chosen in a list, which is in
// the address of the page; the nodes placed by hand, saved all at once over
// the layout read with the page, or given back to the automatic layout;
// moves not saved, which are not lost without asking first; the file saved
// and the page printed.
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';
import { trans } from '@/lib/i18n';
import { safeFileName } from '@/lib/svgExport';

type Site = { id: number; name: string; code: string | null };

export type DiagramPage = {
    siteId: Ref<string>;
    editing: Ref<boolean>;
    dirty: Ref<boolean>;
    busy: Ref<boolean>;
    layoutError: Ref<string | null>;
    startEditing: () => void;
    cancelEditing: () => void;
    chooseSite: () => void;
    saveLayout: () => void;
    resetLayout: () => void;
    print: () => void;
    // "schema-physique-SIE-2026-10-15.svg": the code of the site, or its
    // name without one, and the day, in the pattern given.
    fileName: (pattern: string) => string;
};

export const useDiagramPage = (options: {
    site: () => Site | null;
    // The version of the layout read with the page.
    version: () => string | null;
    // What is asked before moves not saved are lost.
    leave: () => string;
    // The page of the diagram of a site, and where its layout is saved and
    // given back to the automatic layout.
    pageOf: (site: string) => string;
    updateOf: (site: number) => string;
    resetOf: (site: number) => string;
    // Where every node drawn is, as it is saved.
    positions: () => Record<string, number | string>[];
    // The whole diagram in sight, before it is printed.
    fit: () => void;
}): DiagramPage => {
    const current = options.site();
    const siteId = ref<string>(current === null ? '' : String(current.id));

    const editing = ref<boolean>(false);
    const dirty = ref<boolean>(false);
    const busy = ref<boolean>(false);
    const layoutError = ref<string | null>(null);

    const startEditing = (): void => {
        layoutError.value = null;
        editing.value = true;
    };

    // Back to the layout saved.
    const cancelEditing = (): void => {
        editing.value = false;
        dirty.value = false;
        layoutError.value = null;
    };

    // A navigation the user already agreed to.
    let leaving = false;

    const removeGuard = router.on('before', (event) => {
        if (leaving) {
            leaving = false;

            return;
        }

        if (dirty.value && !busy.value && !window.confirm(options.leave())) {
            event.preventDefault();
        }
    });

    const onBeforeUnload = (event: BeforeUnloadEvent): void => {
        if (dirty.value) {
            event.preventDefault();
            event.returnValue = '';
        }
    };

    const chooseSite = (): void => {
        if (dirty.value) {
            if (!window.confirm(options.leave())) {
                const site = options.site();

                siteId.value = site === null ? '' : String(site.id);

                return;
            }

            leaving = true;
        }

        router.get(options.pageOf(siteId.value));
    };

    const done = {
        preserveScroll: true,
        onSuccess: (): void => {
            editing.value = false;
            dirty.value = false;
        },
        onError: (errors: Record<string, string>): void => {
            layoutError.value =
                Object.values(errors)[0] ??
                trans('The layout could not be saved.');
        },
        onFinish: (): void => {
            busy.value = false;
        },
    };

    // Every node drawn, all at once, over the layout read with the page.
    const saveLayout = (): void => {
        const site = options.site();
        const version = options.version();

        if (site === null || version === null) {
            return;
        }

        layoutError.value = null;
        busy.value = true;
        router.put(
            options.updateOf(site.id),
            { version, positions: options.positions() },
            done,
        );
    };

    // The positions go: the diagram is laid out on its own again.
    const resetLayout = (): void => {
        const site = options.site();

        if (site === null) {
            return;
        }

        layoutError.value = null;
        busy.value = true;
        router.delete(options.resetOf(site.id), done);
    };

    const fileName = (pattern: string): string => {
        const site = options.site();
        const today = new Date();
        const day = [
            today.getFullYear(),
            String(today.getMonth() + 1).padStart(2, '0'),
            String(today.getDate()).padStart(2, '0'),
        ].join('-');

        return trans(pattern, {
            site: safeFileName(site?.code ?? site?.name ?? ''),
            day,
        });
    };

    // Printed, the page keeps its title, the legend and the whole diagram,
    // in the light theme whatever the one on screen.
    let printing: MediaQueryList | null = null;

    const beforePrint = (): void => {
        document.documentElement.classList.add('print-light');
        options.fit();
    };

    const afterPrint = (): void => {
        document.documentElement.classList.remove('print-light');
    };

    const onPrintChange = (event: MediaQueryListEvent): void => {
        if (event.matches) {
            beforePrint();
        } else {
            afterPrint();
        }
    };

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);
        printing = window.matchMedia('print');
        printing.addEventListener('change', onPrintChange);
        window.addEventListener('beforeprint', beforePrint);
        window.addEventListener('afterprint', afterPrint);
    });

    onBeforeUnmount(() => {
        removeGuard();
        window.removeEventListener('beforeunload', onBeforeUnload);
        printing?.removeEventListener('change', onPrintChange);
        window.removeEventListener('beforeprint', beforePrint);
        window.removeEventListener('afterprint', afterPrint);
        afterPrint();
    });

    const print = (): void => {
        window.print();
    };

    return {
        siteId,
        editing,
        dirty,
        busy,
        layoutError,
        startEditing,
        cancelEditing,
        chooseSite,
        saveLayout,
        resetLayout,
        print,
        fileName,
    };
};
