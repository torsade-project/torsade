// torsade/resources/js/composables/useManualPlacing.ts
//
// The nodes of a diagram placed by hand: where each one is while they are
// being placed, from the positions saved, or from where the automatic
// layout put it; whether any moved; and the order they keep meanwhile, so
// that a node that moves keeps the focus.
import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { snap } from '@/lib/diagramManual';
import type { Position } from '@/lib/diagramManual';

export type ManualPlacing = {
    // Whether the diagram keeps positions given rather than its own.
    manual: ComputedRef<boolean>;
    // Where the nodes are: while they are being placed, where they were
    // moved; otherwise, where they were saved.
    current: ComputedRef<Record<string, Position>>;
    draft: Ref<Record<string, Position>>;
    dirty: ComputedRef<boolean>;
    // A node placed, on the grid.
    moveTo: (key: string, position: Position) => void;
    // The items in the order their nodes had when the placing began.
    inOrder: <T>(items: T[], key: (item: T) => string) => T[];
};

// The positions in words, to tell whether any node moved.
const fingerprint = (positions: Record<string, Position>): string =>
    JSON.stringify(
        Object.keys(positions)
            .sort()
            .map((key) => [key, positions[key].x, positions[key].y]),
    );

export const useManualPlacing = (options: {
    editing: () => boolean;
    // Where the nodes were placed by hand, by their key; none for the
    // automatic layout.
    positions: () => Record<string, Position>;
    // Where the automatic layout put each node.
    automatic: () => { key: string; x: number; y: number }[];
    // The keys of the nodes in the order they are drawn and read.
    drawn: () => string[];
}): ManualPlacing => {
    const draft = ref<Record<string, Position>>({});
    const startedFrom = ref<string>('');
    const order = ref<string[]>([]);

    const manual = computed<boolean>(
        () => options.editing() || Object.keys(options.positions()).length > 0,
    );

    const current = computed<Record<string, Position>>(() =>
        options.editing() ? draft.value : options.positions(),
    );

    const fromAutomatic = (
        nodes: { key: string; x: number; y: number }[],
    ): Record<string, Position> =>
        Object.fromEntries(
            nodes.map((node) => [
                node.key,
                { x: snap(node.x), y: snap(node.y) },
            ]),
        );

    // When the placing begins, from the positions saved, or from where the
    // automatic layout put each node.
    watch(options.editing, (editing) => {
        if (!editing) {
            draft.value = {};
            startedFrom.value = '';

            return;
        }

        const saved = options.positions();

        draft.value =
            Object.keys(saved).length > 0
                ? { ...saved }
                : fromAutomatic(options.automatic());
        startedFrom.value = fingerprint(draft.value);
        order.value = options.drawn();
    });

    // A diagram laid out again as its placing begins, with more nodes, as
    // the end devices it then shows: the placing starts from that layout,
    // as long as nothing was saved nor moved.
    watch(
        () => options.automatic(),
        (nodes) => {
            if (
                !options.editing() ||
                nodes.length === 0 ||
                Object.keys(options.positions()).length > 0 ||
                fingerprint(draft.value) !== startedFrom.value
            ) {
                return;
            }

            draft.value = fromAutomatic(nodes);
            startedFrom.value = fingerprint(draft.value);
            order.value = options.drawn();
        },
    );

    const dirty = computed<boolean>(
        () =>
            options.editing() && fingerprint(draft.value) !== startedFrom.value,
    );

    const moveTo = (key: string, position: Position): void => {
        draft.value = {
            ...draft.value,
            [key]: { x: snap(position.x), y: snap(position.y) },
        };
    };

    const inOrder = <T>(items: T[], key: (item: T) => string): T[] => {
        if (!options.editing()) {
            return items;
        }

        const rank = new Map(order.value.map((one, index) => [one, index]));

        return [...items].sort(
            (a, b) =>
                (rank.get(key(a)) ?? items.length) -
                (rank.get(key(b)) ?? items.length),
        );
    };

    return { manual, current, draft, dirty, moveTo, inOrder };
};
