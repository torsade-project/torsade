<!-- torsade/resources/js/components/diagram/DiagramLayoutBar.vue -->
<script setup lang="ts">
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';

// While the nodes of a diagram are being placed by hand: what saves them,
// what forgets them, what gives the diagram back to the automatic layout
// after asking, and whether some moved. Each diagram says it in the words
// of what it places.
defineProps<{
    busy: boolean;
    dirty: boolean;
    // Whether a layout is saved, which the automatic layout would forget.
    canReset: boolean;
    error: string | null;
    texts: {
        // Nodes moved and not saved, or none.
        moved: string;
        still: string;
        // How a node is moved, with the mouse, a finger or the keyboard.
        help: string;
    };
}>();

const emit = defineEmits<{
    save: [];
    cancel: [];
    reset: [];
}>();

const confirmingReset = ref<boolean>(false);

const confirmReset = (): void => {
    confirmingReset.value = false;
    emit('reset');
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-lg border bg-card p-4 print:hidden"
        :aria-label="$t('Edit the layout')"
        data-test="layout-bar"
    >
        <div class="flex flex-wrap items-center gap-2.5">
            <Button
                type="button"
                :disabled="busy"
                data-test="save-layout"
                @click="emit('save')"
            >
                {{ $t('Save') }}
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="busy"
                data-test="cancel-layout"
                @click="emit('cancel')"
            >
                {{ $t('Cancel') }}
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="busy || !canReset"
                data-test="reset-layout"
                @click="confirmingReset = true"
            >
                {{ $t('Lay out automatically') }}
            </Button>
            <p
                class="text-sm"
                :class="dirty ? 'font-medium' : 'text-muted-foreground'"
                role="status"
                data-test="layout-state"
            >
                {{ dirty ? texts.moved : texts.still }}
            </p>
        </div>
        <p
            id="diagram-edit-help"
            class="max-w-prose text-sm text-muted-foreground"
        >
            {{ texts.help }}
        </p>
        <p
            v-if="error !== null"
            class="text-sm text-error"
            role="alert"
            data-test="layout-error"
        >
            {{ error }}
        </p>
    </section>

    <ConfirmDialog
        :open="confirmingReset"
        :title="$t('Lay the diagram out automatically again?')"
        :description="
            $t(
                'The positions placed by hand on this diagram will be deleted, and the diagram laid out on its own again.',
            )
        "
        :confirm-label="$t('Lay out automatically')"
        destructive
        @confirm="confirmReset"
        @cancel="confirmingReset = false"
    />
</template>
