<!-- torsade/resources/js/components/network/TemplateChoiceDialog.vue -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
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
import { namesInWords } from '@/lib/portPreview';
import { index as templatesIndex } from '@/routes/device-templates';
import { store } from '@/routes/devices/template';
import type { TemplateChoice } from '@/types';

// The template of a device that has no member yet: its first member, the
// member 1, primary, is made from it, with its ports.
const props = defineProps<{
    open: boolean;
    // With its serial number when it has one: the number goes to the
    // member that is created.
    device: { id: number; name: string; serial_number?: string | null };
    templates: TemplateChoice[];
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{ device_template_id: number | null }>({
    device_template_id: null,
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.device_template_id = null;
    },
);

const template = computed<TemplateChoice | null>(
    () =>
        props.templates.find(
            (choice) => choice.id === form.device_template_id,
        ) ?? null,
);

const submit = (): void => {
    form.post(store(props.device.id).url, {
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
        <DialogContent class="sm:max-w-lg" data-test="template-choice-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ $t('Choose a template') }}</DialogTitle>
                    <DialogDescription>{{
                        $t(
                            ':name gets its first member, the member 1, primary, with the ports of the template.',
                            { name: device.name },
                        )
                    }}</DialogDescription>
                </DialogHeader>

                <p
                    v-if="device.serial_number"
                    class="text-sm text-muted-foreground"
                    data-test="serial-number-moves"
                >
                    {{
                        $t(
                            'Its serial number, :serial, goes to the member 1 and leaves the device.',
                            { serial: device.serial_number },
                        )
                    }}
                </p>

                <p
                    v-if="templates.length === 0"
                    class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                >
                    {{ $t('No template yet.') }}
                    <Link :href="templatesIndex()" class="link">{{
                        $t('Device templates')
                    }}</Link>
                </p>

                <div v-else class="grid gap-2">
                    <Label for="first-template">{{ $t('Template') }}</Label>
                    <NativeSelect
                        id="first-template"
                        v-model="form.device_template_id"
                        name="device_template_id"
                        required
                        :aria-invalid="
                            form.errors.device_template_id ? 'true' : undefined
                        "
                    >
                        <option :value="null" disabled>
                            {{ $t('Choose a template') }}
                        </option>
                        <option
                            v-for="choice in templates"
                            :key="choice.id"
                            :value="choice.id"
                        >
                            {{ choice.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.device_template_id" />

                    <div
                        v-if="template"
                        class="rounded-md bg-sunken px-3 py-2 text-sm"
                        role="status"
                        data-test="template-choice-preview"
                    >
                        <span class="text-muted-foreground">{{
                            $t('Ports created')
                        }}</span>
                        <span class="block font-mono">{{
                            namesInWords(template.port_groups) ||
                            $t('No port yet.')
                        }}</span>
                        <span class="block font-medium">{{
                            $tc(
                                ':count port|:count ports',
                                template.ports_count,
                            )
                        }}</span>
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
                        :disabled="
                            form.processing || form.device_template_id === null
                        "
                        data-test="save-template-choice"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
