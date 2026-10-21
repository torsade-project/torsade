<!-- torsade/resources/js/components/network/JackPlugDialog.vue -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import JackPath from '@/components/network/JackPath.vue';
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
import { store } from '@/routes/jack-cords';
import type { PluggableJackGroup } from '@/types';

// A port of a device plugged into a wall jack of its site, on the side of
// the desk: a switch of a meeting room, an access point on the ceiling. The
// jacks of the site that are not retired and have no device plugged into
// them yet are offered, by building and floor, each with its path towards
// the panel and the switch.
const props = defineProps<{
    open: boolean;
    device: { id: number; name: string };
    port: { id: number; name: string } | null;
    // Asked for when the window opens.
    jacks?: PluggableJackGroup[];
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    jack_id: number | null;
    port_id: number | null;
    color: string;
    description: string;
}>({
    jack_id: null,
    port_id: null,
    color: '',
    description: '',
});

const loading = ref<boolean>(false);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.jack_id = null;
        form.port_id = props.port?.id ?? null;
        form.color = '';
        form.description = '';

        // Only the jacks are asked again, and the address of the page stays
        // what it is. The list waits from the start: the jacks of a port
        // opened before are not shown meanwhile, nor that none is free.
        loading.value = true;
        router.reload({
            only: ['pluggableJacks'],
            preserveUrl: true,
            onFinish: () => {
                loading.value = false;
            },
        });
    },
);

const groups = computed<PluggableJackGroup[]>(() =>
    loading.value ? [] : (props.jacks ?? []),
);

const submit = (): void => {
    form.post(store().url, {
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
        <DialogContent class="sm:max-w-2xl" data-test="jack-plug-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ $t('Plug into a jack') }}</DialogTitle>
                    <DialogDescription v-if="port">{{
                        $t(
                            'The port :port of :device is plugged into a wall jack of its site, on the side of the desk. A port has one connector: a port plugged into a jack is patched to no panel.',
                            { port: port.name, device: device.name },
                        )
                    }}</DialogDescription>
                </DialogHeader>

                <div
                    class="grid gap-2"
                    role="group"
                    aria-labelledby="plug-jacks-label"
                >
                    <p id="plug-jacks-label" class="text-sm font-medium">
                        {{ $t('Jack') }}
                    </p>
                    <!-- Always there, so that a screen reader is told when the
                         list comes. -->
                    <div role="status">
                        <p v-if="loading" class="text-muted-foreground">
                            {{ $t('Loading the jacks…') }}
                        </p>
                        <p
                            v-else-if="groups.length === 0"
                            class="rounded-lg border border-dashed p-4 text-muted-foreground"
                            data-test="no-pluggable-jack"
                        >
                            {{
                                $t(
                                    'No jack of this site is free: a device is already plugged into each of them, or they are retired.',
                                )
                            }}
                        </p>
                    </div>
                    <div
                        v-if="groups.length > 0"
                        class="grid max-h-72 gap-3 overflow-y-auto rounded-md border p-3"
                        data-test="pluggable-jacks"
                    >
                        <div
                            v-for="group in groups"
                            :key="group.floor"
                            class="grid gap-1.5"
                        >
                            <p class="text-sm text-muted-foreground">
                                {{ group.place }}
                            </p>
                            <label
                                v-for="jack in group.jacks"
                                :key="jack.id"
                                class="flex min-w-0 items-start gap-2.5"
                                data-test="pluggable-jack"
                                :data-jack="jack.label"
                            >
                                <input
                                    v-model="form.jack_id"
                                    type="radio"
                                    name="jack_id"
                                    class="mt-0.5 size-4.5 shrink-0 accent-primary"
                                    :value="jack.id"
                                />
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <span>
                                        <span
                                            class="font-mono text-sm font-medium"
                                            >{{ jack.label }}</span
                                        >
                                        <span
                                            v-if="jack.location"
                                            class="text-sm text-muted-foreground"
                                        >
                                            — {{ jack.location }}</span
                                        >
                                    </span>
                                    <JackPath
                                        class="text-sm"
                                        :path="jack.path"
                                        :with-jack="false"
                                    />
                                </span>
                            </label>
                        </div>
                    </div>
                    <InputError :message="form.errors.jack_id" />
                    <InputError :message="form.errors.port_id" />
                </div>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[11rem_1fr]">
                    <div class="grid content-start gap-2">
                        <Label for="plug-color">{{ $t('Colour') }}</Label>
                        <Input
                            id="plug-color"
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
                        <Label for="plug-description">{{
                            $t('Description')
                        }}</Label>
                        <Textarea
                            id="plug-description"
                            v-model="form.description"
                            name="description"
                            maxlength="255"
                            rows="1"
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
                        :disabled="form.processing || form.jack_id === null"
                        data-test="save-jack-plug"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Plug') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
