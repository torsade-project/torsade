<!-- torsade/resources/js/components/network/CatalogVlanDialog.vue -->
<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
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
import { show, store, update } from '@/routes/catalog-vlans';
import type { CatalogEntry, CatalogOption } from '@/types';

// A short form in a window: an entry of the catalogue is created or
// changed without leaving the page.
const props = defineProps<{
    open: boolean;
    // The entry to modify, or null for a new one.
    entry: CatalogEntry | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    // A number once typed: the field is a numeric input.
    vid: string | number;
    name: string;
    description: string;
}>({
    vid: '',
    name: '',
    description: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.vid = props.entry ? String(props.entry.vid) : '';
        form.name = props.entry?.name ?? '';
        form.description = props.entry?.description ?? '';
    },
);

// A number or a name that is already in the catalogue is refused with the
// entry that has it: whoever types "10" for a second site is looking for
// the entry 10, and is led to it. The link only lasts as long as the message
// it goes with.
const page = usePage();

const inTheWay = (field: 'vid' | 'name'): CatalogOption | null =>
    form.errors[field] ? (page.flash?.catalogConflict?.[field] ?? null) : null;

const entryWithVid = computed<CatalogOption | null>(() => inTheWay('vid'));
const entryWithName = computed<CatalogOption | null>(() => inTheWay('name'));

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    if (props.entry) {
        form.put(update(props.entry.id).url, options);
    } else {
        form.post(store().url, options);
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
        <DialogContent class="sm:max-w-md" data-test="catalog-vlan-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        entry
                            ? $t('Edit the catalogue entry')
                            : $t('New catalogue entry')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            entry
                                ? $t(
                                      'The VLANs attached to this entry are not changed: what then differs is shown as a deviation.',
                                  )
                                : $t(
                                      'An entry stands for every site: create it once, then attach the VLAN of each site to it.',
                                  )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 sm:grid-cols-[8rem_1fr]">
                    <div class="grid content-start gap-2">
                        <Label for="catalog-vid">{{ $t('VID') }}</Label>
                        <Input
                            id="catalog-vid"
                            v-model="form.vid"
                            type="number"
                            name="vid"
                            required
                            min="1"
                            max="4094"
                            step="1"
                            class="font-mono"
                            :aria-invalid="form.errors.vid ? 'true' : undefined"
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="catalog-name">{{ $t('Name') }}</Label>
                        <Input
                            id="catalog-name"
                            v-model="form.name"
                            name="name"
                            required
                            maxlength="64"
                            autocomplete="off"
                            :aria-invalid="
                                form.errors.name ? 'true' : undefined
                            "
                        />
                    </div>
                </div>
                <div
                    v-if="form.errors.vid"
                    class="flex flex-col items-start gap-1"
                >
                    <InputError :message="form.errors.vid" />
                    <Link
                        v-if="entryWithVid"
                        :href="show(entryWithVid.id)"
                        class="link text-sm"
                        data-test="catalog-conflict-vid"
                        >{{
                            $t('Open the entry :label', {
                                label: `${entryWithVid.vid} — ${entryWithVid.name}`,
                            })
                        }}</Link
                    >
                </div>
                <div
                    v-if="form.errors.name"
                    class="flex flex-col items-start gap-1"
                >
                    <InputError :message="form.errors.name" />
                    <Link
                        v-if="entryWithName"
                        :href="show(entryWithName.id)"
                        class="link text-sm"
                        data-test="catalog-conflict-name"
                        >{{
                            $t('Open the entry :label', {
                                label: `${entryWithName.vid} — ${entryWithName.name}`,
                            })
                        }}</Link
                    >
                </div>

                <div class="grid gap-2">
                    <Label for="catalog-description">{{
                        $t('Description')
                    }}</Label>
                    <Textarea
                        id="catalog-description"
                        v-model="form.description"
                        name="description"
                        maxlength="1000"
                        rows="3"
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
                        data-test="save-catalog-vlan"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
