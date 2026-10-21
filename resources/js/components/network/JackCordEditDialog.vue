<!-- torsade/resources/js/components/network/JackCordEditDialog.vue -->
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
import { update } from '@/routes/jack-cords';
import type { JackCordValues } from '@/types';

// The colour and the description of a jack cord. Its two ends are shown and
// do not change: to move it, the port is unplugged, then plugged again,
// which the window offers too.
const props = defineProps<{
    open: boolean;
    cord: JackCordValues | null;
    // Its two ends, as the page names them: the jack, "B.07", and the port
    // with its device, "ap-salle-reunion › 1".
    ends: { jack: string; port: string } | null;
}>();

const emit = defineEmits<{
    close: [];
    // The cord is to be taken away: the page asks first.
    unplug: [];
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
        form.color = props.cord?.color ?? '';
        form.description = props.cord?.description ?? '';
    },
);

// A cord unplugged in the meantime is told on the field "cord".
const gone = computed<string | undefined>(
    () => (form.errors as Record<string, string | undefined>).cord,
);

const submit = (): void => {
    if (!props.cord) {
        return;
    }

    form.put(update(props.cord.id).url, {
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
        <DialogContent data-test="jack-cord-edit-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ $t('Edit the jack cord') }}</DialogTitle>
                    <DialogDescription>{{
                        $t(
                            'The two ends of a cord do not change: to plug the port into another jack, unplug it, then plug it again.',
                        )
                    }}</DialogDescription>
                </DialogHeader>

                <!-- The two ends, which the window does not change. -->
                <dl
                    v-if="ends"
                    class="grid gap-x-4 gap-y-2 rounded-md bg-sunken px-3 py-2 text-sm sm:grid-cols-[auto_1fr]"
                    data-test="jack-cord-ends"
                >
                    <dt class="text-muted-foreground">{{ $t('Jack') }}</dt>
                    <dd class="min-w-0 font-mono break-words">
                        {{ ends.jack }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ $t('Port of the device') }}
                    </dt>
                    <dd class="min-w-0 break-words">{{ ends.port }}</dd>
                </dl>

                <InputError :message="gone" />

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[11rem_1fr]">
                    <div class="grid content-start gap-2">
                        <Label for="jack-cord-edit-color">{{
                            $t('Colour')
                        }}</Label>
                        <Input
                            id="jack-cord-edit-color"
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
                        <Label for="jack-cord-edit-description">{{
                            $t('Description')
                        }}</Label>
                        <Textarea
                            id="jack-cord-edit-description"
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
                        class="sm:mr-auto"
                        data-test="unplug-jack-cord"
                        @click="emit('unplug')"
                    >
                        {{ $t('Unplug') }}
                    </Button>
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
                        data-test="save-jack-cord"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
