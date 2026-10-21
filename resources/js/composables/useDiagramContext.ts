// torsade/resources/js/composables/useDiagramContext.ts
//
// What the frame of a diagram gives the nodes and the warnings drawn in
// it: whether the nodes are being placed by hand, how a node is seized and
// moved, and the tooltip that tells the reasons of a deviation.
import { inject } from 'vue';
import type { ComputedRef, InjectionKey, Ref } from 'vue';
import type { Position } from '@/lib/diagramManual';
import type { Deviation } from '@/types';

export type DiagramContext = {
    editing: ComputedRef<boolean>;
    // Tells how a node is moved from the keyboard, while they are.
    editHelp: ComputedRef<string | undefined>;
    // The node seized, which shows the ring of the focus as it moves.
    seized: Ref<string | null>;
    // With the mouse or a finger: the node follows the pointer.
    startMove: (event: PointerEvent, key: string, from: Position) => void;
    // From the keyboard: the arrows move the node that has the focus.
    onNodeKey: (event: KeyboardEvent, key: string, from: Position) => void;
    // The warning whose reasons are open, and the tooltip that tells them.
    tipKey: ComputedRef<string | null>;
    tipId: string;
    onWarningFocus: (
        event: FocusEvent,
        key: string,
        deviations: Deviation[],
    ) => void;
    hideTip: (key?: string) => void;
    toggleTip: (event: Event, key: string, deviations: Deviation[]) => void;
    onWarningKey: (
        event: KeyboardEvent,
        key: string,
        deviations: Deviation[],
    ) => void;
};

export const DIAGRAM_CONTEXT: InjectionKey<DiagramContext> = Symbol('diagram');

export const useDiagramContext = (): DiagramContext => {
    const context = inject(DIAGRAM_CONTEXT);

    if (context === undefined) {
        throw new Error('A node of a diagram is drawn in a diagram frame.');
    }

    return context;
};
