<!-- torsade/resources/js/components/ConfirmDialog.vue -->
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description: string;
        confirmLabel: string;
        destructive?: boolean;
    }>(),
    {
        destructive: false,
    },
);

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('cancel');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-md" data-test="confirm-dialog">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button type="button" variant="outline" @click="emit('cancel')">
                    {{ $t('Cancel') }}
                </Button>
                <Button
                    type="button"
                    :variant="destructive ? 'destructive' : 'default'"
                    data-test="confirm-button"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
