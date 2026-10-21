<!-- torsade/resources/js/components/network/PatchCordEditDialog.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/patch-cords';
import type { PanelPort } from '@/types';

// The colour and the description of a cord. Its two ends are shown and do
// not change: to move a cord, the port is unpatched, then patched again.
const props = defineProps<{
    open: boolean;
    panel: { id: number; name: string };
    // The port of the panel the cord leaves from.
    port: PanelPort | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{ color: string; description: string }>({
    color: '',
    description: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.color = props.port?.cord?.color ?? '';
        form.description = props.port?.cord?.description ?? '';
    },
);

const cord = computed(() => props.port?.cord ?? null);

// A cord unpatched in the meantime is told on the field "cord".
const gone = computed<string | undefined>(
    () => (form.errors as Record<string, string | undefined>).cord,
);

const submit = (): void => {
    if (!cord.value) {
        return;
    }

    form.put(update(cord.value.id).url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent data-test="patch-cord-edit-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ $t('Edit the cord') }}</DialogTitle>
                    <DialogDescription>{{
                        $t(
                            'The two ends of a cord do not change: to move it to another port, unpatch the port, then patch it again.',
                        )
                    }}</DialogDescription>
                </DialogHeader>

                <!-- The two ends, which the window does not change. -->
                <dl
                    v-if="port && cord"
                    class="grid gap-x-4 gap-y-2 rounded-md bg-sunken px-3 py-2 text-sm sm:grid-cols-[auto_1fr]"
                    data-test="cord-ends"
                >
                    <dt class="text-muted-foreground">
                        {{ $t('Port of the panel') }}
                    </dt>
                    <dd class="min-w-0 font-mono break-words">
                        {{ panel.name }} {{ port.number
                        }}<template v-if="port.jack">
                            ({{ port.jack.label }})</template
                        >
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ $t('Port of the device') }}
                    </dt>
                    <dd class="min-w-0 break-words">
                        {{ cord.device.name }} ›
                        <span class="font-mono">{{ cord.port.name }}</span>
                    </dd>
                </dl>

                <InputError :message="gone" />

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[11rem_1fr]">
                    <div class="grid content-start gap-2">
                        <Label for="cord-edit-color">{{ $t('Colour') }}</Label>
                        <Input
                            id="cord-edit-color"
                            v-model="form.color"
                            name="color"
                            maxlength="32"
                            autocomplete="off"
                            :aria-invalid="
                                form.errors.color ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.color" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="cord-edit-description">{{
                            $t('Description')
                        }}</Label>
                        <Textarea
                            id="cord-edit-description"
                            v-model="form.description"
                            name="description"
                            maxlength="255"
                            rows="2"
                            :aria-invalid="
                                form.errors.description ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('close')"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="save-cord"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
