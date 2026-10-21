<!-- torsade/resources/js/components/network/JackAttachDialog.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/patch-panel-ports/jack';
import type { FreeJackGroup } from '@/types';

// A jack of the site of a panel cabled to one of its ports: its horizontal
// cable ends there. The jacks of every floor of the site that are not
// cabled yet are offered, by building and floor.
const props = defineProps<{
    open: boolean;
    // The port of the panel, as the panel names it: "PB-A 15".
    port: { id: number; name: string } | null;
    jacks: FreeJackGroup[];
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{ jack_id: number | null }>({ jack_id: null });

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.jack_id = props.jacks[0]?.jacks[0]?.id ?? null;
    },
);

const submit = (): void => {
    if (!props.port) {
        return;
    }

    form.put(update(props.port.id).url, {
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
        <DialogContent data-test="jack-attach-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ $t('Cable a jack') }}</DialogTitle>
                    <DialogDescription v-if="port">{{
                        $t(
                            'The jack whose horizontal cable ends on the port :port.',
                            { port: port.name },
                        )
                    }}</DialogDescription>
                </DialogHeader>

                <p
                    v-if="jacks.length === 0"
                    class="text-sm text-muted-foreground"
                    data-test="no-free-jack"
                >
                    {{
                        $t(
                            'Every jack of this site is already cabled. Jacks are added to a floor from the page of its site.',
                        )
                    }}
                </p>

                <div v-else class="grid gap-2">
                    <Label for="attach-jack">{{ $t('Jack') }}</Label>
                    <NativeSelect
                        id="attach-jack"
                        v-model="form.jack_id"
                        name="jack_id"
                        required
                        data-test="attach-jack"
                        :aria-invalid="form.errors.jack_id ? 'true' : undefined"
                    >
                        <optgroup
                            v-for="group in jacks"
                            :key="group.floor"
                            :label="group.place"
                        >
                            <option
                                v-for="jack in group.jacks"
                                :key="jack.id"
                                :value="jack.id"
                            >
                                {{ jack.label
                                }}<template v-if="jack.location">
                                    — {{ jack.location }}</template
                                >
                            </option>
                        </optgroup>
                    </NativeSelect>
                    <InputError :message="form.errors.jack_id" />
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
                        :disabled="form.processing || jacks.length === 0"
                        data-test="save-jack-attach"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
