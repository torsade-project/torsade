<!-- torsade/resources/js/components/network/LinkEditDialog.vue -->
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
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/links';
import type { DeviceLink, Option } from '@/types';

// What a link is, apart from its ends: its medium, its status and its
// description. Its two ports do not change: another port is another link.
const props = defineProps<{
    open: boolean;
    // The link to modify.
    link: DeviceLink | null;
    media: Option[];
    statuses: Option[];
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    medium: string;
    status: string;
    description: string;
}>({
    medium: '',
    status: 'active',
    description: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open || props.link === null) {
            return;
        }

        form.clearErrors();
        form.medium = props.link.medium ?? '';
        form.status = props.link.status;
        form.description = props.link.description ?? '';
    },
);

const submit = (): void => {
    if (props.link === null) {
        return;
    }

    form.put(update(props.link.id).url, {
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
        <DialogContent class="sm:max-w-xl" data-test="link-edit-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ $t('Edit the link') }}</DialogTitle>
                    <DialogDescription v-if="link">
                        <span class="font-mono text-sm"
                            >{{ link.near.device.name }} ›
                            {{ link.near.port.name }} ↔
                            {{ link.far.device.name }} ›
                            {{ link.far.port.name }}</span
                        >
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="link-edit-medium">{{ $t('Medium') }}</Label>
                        <NativeSelect
                            id="link-edit-medium"
                            v-model="form.medium"
                            name="medium"
                            :aria-invalid="
                                form.errors.medium ? 'true' : undefined
                            "
                        >
                            <option value="">{{ $t('Not specified') }}</option>
                            <option
                                v-for="option in media"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.medium" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="link-edit-status">{{ $t('Status') }}</Label>
                        <NativeSelect
                            id="link-edit-status"
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
                    <Label for="link-edit-description">{{
                        $t('Description')
                    }}</Label>
                    <Textarea
                        id="link-edit-description"
                        v-model="form.description"
                        name="description"
                        maxlength="1000"
                        rows="3"
                        :aria-invalid="
                            form.errors.description ? 'true' : undefined
                        "
                    />
                    <InputError :message="form.errors.description" />
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'The two ports of a link do not change: to plug it elsewhere, delete it and link the other port.',
                            )
                        }}
                    </p>
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
                        data-test="save-link-edit"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
