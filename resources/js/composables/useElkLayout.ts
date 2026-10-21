// torsade/resources/js/composables/useElkLayout.ts
//
// ELK, in a worker of the application, for the component that lays a
// diagram out: started when it is mounted, stopped when it is not any more.
// Each layout asked for has a number: the answer to an older one is
// ignored.
import ELK from 'elkjs/lib/elk-api.js';
import type { ElkNode } from 'elkjs/lib/elk-api';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';
import { loadFonts } from '@/lib/diagram';

export type ElkLayout = {
    // Whether a layout is being worked out.
    working: Ref<boolean>;
    // Whether the last one could not be.
    failed: Ref<boolean>;
    // The graph laid out; null when it could not be, or when a newer one
    // was asked for meanwhile. The graph is built once the layout begins.
    layOut: (build: () => ElkNode) => Promise<ElkNode | null>;
};

/**
 * @param onFailure Told when a layout could not be worked out.
 */
export const useElkLayout = (onFailure: () => void): ElkLayout => {
    const working = ref<boolean>(false);
    const failed = ref<boolean>(false);

    let elk: InstanceType<typeof ELK> | null = null;
    let requested = 0;

    const fail = (): void => {
        working.value = false;
        failed.value = true;
        onFailure();
    };

    onMounted(() => {
        try {
            elk = new ELK({
                // The engine, in a worker of the application.
                workerFactory: () => {
                    const worker = new Worker(
                        new URL(
                            '../workers/diagram-layout.ts',
                            import.meta.url,
                        ),
                        { type: 'module' },
                    );

                    // A worker that could not be loaded, or that stopped on
                    // an error of its own, never answers: it is told at once.
                    worker.addEventListener('error', (event) => {
                        event.preventDefault();
                        fail();
                    });

                    return worker;
                },
            });
        } catch {
            fail();
        }
    });

    onBeforeUnmount(() => {
        elk?.terminateWorker();
        elk = null;
    });

    const layOut = async (build: () => ElkNode): Promise<ElkNode | null> => {
        if (elk === null) {
            return null;
        }

        const engine = elk;
        const id = ++requested;

        working.value = true;
        failed.value = false;

        // The boxes are measured with the fonts of the page, once they are
        // there.
        await loadFonts();

        try {
            const answer = await engine.layout(build());

            if (id !== requested) {
                return null;
            }

            working.value = false;

            return answer;
        } catch {
            if (id === requested) {
                fail();
            }

            return null;
        }
    };

    return { working, failed, layOut };
};
