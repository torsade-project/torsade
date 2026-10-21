<!-- torsade/resources/js/components/network/PatchPanelDialog.vue -->
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/patch-panels';
import type { Option, PatchPanelSummary } from '@/types';

// A patch panel, created in the room it is kept in, or modified. Its ports
// are numbered from 1 to its number of ports: more ports adds some after
// the last one, fewer takes the last ones away, unless one of them has a
// jack or a cord.
const props = defineProps<{
    open: boolean;
    // The room a new panel is kept in, with where it is.
    room: { id: number; path: string } | null;
    // The panel to modify, or null for a new one.
    panel: PatchPanelSummary | null;
    connectors: Option[];
    statuses: Option[];
}>();

const emit = defineEmits<{
    close: [];
}>();

const MAX_PORTS = 96;

const form = useForm<{
    name: string;
    port_count: number | string;
    connector: string;
    category: string;
    status: string;
    description: string;
}>({
    name: '',
    port_count: 24,
    connector: 'rj45',
    category: '',
    status: 'active',
    description: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        const panel = props.panel;

        form.clearErrors();
        form.name = panel?.name ?? '';
        form.port_count = panel?.port_count ?? 24;
        form.connector = panel?.connector ?? 'rj45';
        form.category = panel?.category ?? '';
        form.status = panel?.status ?? 'active';
        form.description = panel?.description ?? '';
    },
);

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    if (props.panel) {
        form.put(update(props.panel.id).url, options);
    } else if (props.room) {
        form.post(store(props.room.id).url, options);
    }
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-xl" data-test="patch-panel-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        panel
                            ? $t('Edit the patch panel')
                            : $t('New patch panel')
                    }}</DialogTitle>
                    <DialogDescription v-if="room">{{
                        $t('A patch panel kept in :room.', { room: room.path })
                    }}</DialogDescription>
                </DialogHeader>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[1fr_9rem]">
                    <div class="grid content-start gap-2">
                        <Label for="panel-name">{{ $t('Name') }}</Label>
                        <Input
                            id="panel-name"
                            v-model="form.name"
                            name="name"
                            required
                            maxlength="64"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono"
                            aria-describedby="panel-name-help"
                            :aria-invalid="
                                form.errors.name ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.name" />
                        <p
                            id="panel-name-help"
                            class="text-sm text-muted-foreground"
                        >
                            {{ $t('As written on the panel: PB-A.') }}
                        </p>
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="panel-ports">{{
                            $t('Number of ports')
                        }}</Label>
                        <Input
                            id="panel-ports"
                            v-model="form.port_count"
                            type="number"
                            name="port_count"
                            required
                            min="1"
                            :max="MAX_PORTS"
                            inputmode="numeric"
                            class="font-mono"
                            :aria-invalid="
                                form.errors.port_count ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.port_count" />
                    </div>
                </div>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="panel-connector">{{
                            $t('Connector')
                        }}</Label>
                        <NativeSelect
                            id="panel-connector"
                            v-model="form.connector"
                            name="connector"
                            :aria-invalid="
                                form.errors.connector ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="option in connectors"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.connector" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="panel-category">{{ $t('Category') }}</Label>
                        <Input
                            id="panel-category"
                            v-model="form.category"
                            name="category"
                            maxlength="16"
                            autocomplete="off"
                            :aria-invalid="
                                form.errors.category ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.category" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="panel-status">{{ $t('Status') }}</Label>
                        <NativeSelect
                            id="panel-status"
                            v-model="form.status"
                            name="status"
                            :aria-invalid="
                                form.errors.status ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="option in statuses"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.status" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="panel-description">{{
                        $t('Description')
                    }}</Label>
                    <Textarea
                        id="panel-description"
                        v-model="form.description"
                        name="description"
                        maxlength="1000"
                        rows="2"
                        :aria-invalid="
                            form.errors.description ? 'true' : undefined
                        "
                    />
                    <InputError :message="form.errors.description" />
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
                        data-test="save-patch-panel"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
