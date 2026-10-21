<!-- torsade/resources/js/components/network/JackDialog.vue -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
import { store, update } from '@/routes/jacks';
import type { EditableJack, Option, PanelChoice } from '@/types';

// A wall jack, created on its floor, or modified. Its horizontal cable ends
// on a port of a patch panel of the same site that has no other jack, or
// on none yet.
const props = defineProps<{
    open: boolean;
    // The floor a new jack is on, with where it is.
    floor: { id: number; path: string } | null;
    // The jack to modify, or null for a new one.
    jack: EditableJack | null;
    statuses: Option[];
    // The panels of the site with their free ports, asked for when the
    // window opens: they are not there before.
    panelChoices?: PanelChoice[];
    // What the page needs to know which site the panels are of, when its
    // address does not tell it.
    choicesQuery?: Record<string, number>;
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    label: string;
    location: string;
    patch_panel_port_id: number | null;
    status: string;
    description: string;
}>({
    label: '',
    location: '',
    patch_panel_port_id: null,
    status: 'active',
    description: '',
});

// The panel chosen: only its ports are offered.
const panelId = ref<number | null>(null);
const loading = ref<boolean>(false);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        const jack = props.jack;

        form.clearErrors();
        form.label = jack?.label ?? '';
        form.location = jack?.location ?? '';
        form.patch_panel_port_id = jack?.panel_port?.id ?? null;
        form.status = jack?.status ?? 'active';
        form.description = jack?.description ?? '';
        panelId.value = jack?.panel_port?.panel.id ?? null;

        // Only the panels are asked again, and the address of the page
        // stays what it is.
        router.reload({
            only: ['panelChoices'],
            data: props.choicesQuery ?? {},
            preserveUrl: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        });
    },
);

// The panels offered: those of the site, and, until they come, the one the
// jack is cabled to.
const panels = computed<PanelChoice[]>(() => {
    if (props.panelChoices) {
        return props.panelChoices;
    }

    const current = props.jack?.panel_port;

    return current
        ? [
              {
                  id: current.panel.id,
                  name: current.panel.name,
                  place: '',
                  port_count: 0,
                  free: [],
              },
          ]
        : [];
});

// The free ports of the panel chosen, and the port of the jack itself when
// it is on that panel: it is not free, since the jack has it.
const ports = computed<{ id: number; number: number }[]>(() => {
    const panel = panels.value.find((choice) => choice.id === panelId.value);
    const current = props.jack?.panel_port;
    const free = panel ? [...panel.free] : [];

    if (current && current.panel.id === panelId.value) {
        if (!free.some((port) => port.id === current.id)) {
            free.push({ id: current.id, number: current.number });
        }
    }

    return free.sort((a, b) => a.number - b.number);
});

const choosePanel = (value: number | null): void => {
    panelId.value = value;

    // The first free port, which the jack is most often cabled to.
    form.patch_panel_port_id =
        value === null ? null : (ports.value[0]?.id ?? null);
};

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    if (props.jack) {
        form.put(update(props.jack.id).url, options);
    } else if (props.floor) {
        form.post(store(props.floor.id).url, options);
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
        <DialogContent class="sm:max-w-xl" data-test="jack-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        jack ? $t('Edit the jack') : $t('New jack')
                    }}</DialogTitle>
                    <DialogDescription v-if="floor">{{
                        $t('A wall jack on :floor.', { floor: floor.path })
                    }}</DialogDescription>
                </DialogHeader>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[10rem_1fr]">
                    <div class="grid content-start gap-2">
                        <Label for="jack-label">{{ $t('Label') }}</Label>
                        <Input
                            id="jack-label"
                            v-model="form.label"
                            name="label"
                            required
                            maxlength="32"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono"
                            aria-describedby="jack-label-help"
                            :aria-invalid="
                                form.errors.label ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.label" />
                        <p
                            id="jack-label-help"
                            class="text-sm text-muted-foreground"
                        >
                            {{ $t('As written on the jack: B.15.') }}
                        </p>
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="jack-location">{{ $t('Location') }}</Label>
                        <Input
                            id="jack-location"
                            v-model="form.location"
                            name="location"
                            maxlength="100"
                            autocomplete="off"
                            aria-describedby="jack-location-help"
                            :aria-invalid="
                                form.errors.location ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.location" />
                        <p
                            id="jack-location-help"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                $t(
                                    'Optional. Where it is on the floor: an office, a wall.',
                                )
                            }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[1fr_8rem]">
                    <div class="grid content-start gap-2">
                        <Label for="jack-panel">{{ $t('Patch panel') }}</Label>
                        <NativeSelect
                            id="jack-panel"
                            :model-value="panelId"
                            name="panel"
                            data-test="jack-panel"
                            @update:model-value="
                                (value) =>
                                    choosePanel(
                                        typeof value === 'number'
                                            ? value
                                            : null,
                                    )
                            "
                        >
                            <option :value="null">
                                {{ $t('Not cabled to a panel') }}
                            </option>
                            <option
                                v-for="panel in panels"
                                :key="panel.id"
                                :value="panel.id"
                            >
                                {{ panel.name
                                }}<template v-if="panel.place">
                                    — {{ panel.place }}</template
                                >
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="jack-port">{{ $t('Port') }}</Label>
                        <NativeSelect
                            id="jack-port"
                            v-model="form.patch_panel_port_id"
                            name="patch_panel_port_id"
                            class="font-mono"
                            data-test="jack-port"
                            :disabled="panelId === null"
                            :aria-invalid="
                                form.errors.patch_panel_port_id
                                    ? 'true'
                                    : undefined
                            "
                        >
                            <option v-if="panelId === null" :value="null">
                                —
                            </option>
                            <option
                                v-for="port in ports"
                                :key="port.id"
                                :value="port.id"
                            >
                                {{ port.number }}
                            </option>
                        </NativeSelect>
                    </div>
                </div>
                <div class="-mt-3 grid gap-1.5">
                    <InputError :message="form.errors.patch_panel_port_id" />
                    <p
                        v-if="loading"
                        class="text-sm text-muted-foreground"
                        role="status"
                    >
                        {{ $t('Loading the panels…') }}
                    </p>
                    <p
                        v-else-if="panelId !== null && ports.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('Every port of this panel already has a jack.') }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'The port of a panel of the site where its horizontal cable ends. Only the ports without a jack are offered.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-2 sm:max-w-56">
                    <Label for="jack-status">{{ $t('Status') }}</Label>
                    <NativeSelect
                        id="jack-status"
                        v-model="form.status"
                        name="status"
                        :aria-invalid="form.errors.status ? 'true' : undefined"
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

                <div class="grid gap-2">
                    <Label for="jack-description">{{
                        $t('Description')
                    }}</Label>
                    <Textarea
                        id="jack-description"
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
                        data-test="save-jack"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
