// torsade/resources/js/composables/useLocationTarget.ts
import type { Ref } from 'vue';
import { nextTick, onMounted, ref } from 'vue';

/**
 * The line a link leads to on the page, named after "#" in its address
 * ("#address-12"), as the page opens. A result of the search leads there:
 * the line is brought into sight and marked. The browser does neither by
 * itself, the application changing pages without loading them again; the
 * line may also only be drawn once the table has turned to its page.
 */
export function useLocationTarget(): Ref<string | null> {
    const target = ref<string | null>(null);

    onMounted(async () => {
        const hash = decodeURIComponent(window.location.hash.slice(1));

        if (hash === '') {
            return;
        }

        target.value = hash;

        // Once the page is drawn, and the change of page has scrolled it.
        await nextTick();
        requestAnimationFrame(() =>
            requestAnimationFrame(() =>
                document
                    .getElementById(hash)
                    ?.scrollIntoView({ block: 'center' }),
            ),
        );
    });

    return target;
}
