<!-- torsade/resources/js/components/RowActions.vue -->
<script setup lang="ts">
import { Pencil, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';

// The two actions of a line in a dense table, small enough for the line to
// keep its height. Each button is named for assistive technologies and
// shows its name on hover. An action proper to a table comes first, in the
// slot.
defineProps<{
    // Values of the data-test attribute of each button.
    editTest?: string;
    deleteTest?: string;
}>();

const emit = defineEmits<{
    edit: [];
    delete: [];
}>();
</script>

<template>
    <div class="flex justify-end gap-1">
        <slot />
        <Button
            type="button"
            variant="ghost"
            size="icon-xs"
            :aria-label="$t('Edit')"
            :title="$t('Edit')"
            :data-test="editTest"
            @click="emit('edit')"
        >
            <Pencil aria-hidden="true" />
        </Button>
        <Button
            type="button"
            variant="ghost"
            size="icon-xs"
            class="hover:bg-error-surface hover:text-error"
            :aria-label="$t('Delete')"
            :title="$t('Delete')"
            :data-test="deleteTest"
            @click="emit('delete')"
        >
            <Trash2 aria-hidden="true" />
        </Button>
    </div>
</template>
