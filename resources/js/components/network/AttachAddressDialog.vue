<!-- torsade/resources/js/components/network/AttachAddressDialog.vue -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onUnmounted, ref, watch } from 'vue';
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
import { trans } from '@/lib/i18n';
import { store } from '@/routes/devices/ip-addresses';
import type { AttachableAddresses } from '@/types';

// A documented address that belongs to no device, looked for by its first
// digits or by a part of its host name, then attached to the device. Every
// site is looked into: an address of another site is accepted, and shown
// as a deviation.
const props = defineProps<{
    open: boolean;
    device: { id: number; name: string };
    // What the server found for what was typed. It is only worked out when
    // this window asks: it is not there before.
    attachable?: AttachableAddresses;
}>();

const emit = defineEmits<{
    close: [];
}>();

const search = ref<string>('');
const searching = ref<boolean>(false);
const error = ref<string>('');
// The address being attached.
const attaching = ref<number | null>(null);

// Only the list is asked again, and the address of the page stays what it
// is: what is typed here is not a filter of the page.
const look = (): void => {
    router.reload({
        only: ['attachable'],
        data: { search: search.value.trim() },
        preserveUrl: true,
        onStart: () => {
            searching.value = true;
        },
        onFinish: () => {
            searching.value = false;
        },
    });
};

let timer: ReturnType<typeof setTimeout> | undefined;

// The list follows what is typed, once the typing pauses.
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(look, 250);
});

onUnmounted(() => clearTimeout(timer));

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        error.value = '';
        attaching.value = null;

        if (search.value === '') {
            look();
        } else {
            // The watcher above asks for the list.
            search.value = '';
        }
    },
);

const attach = (id: number): void => {
    router.post(
        store(props.device.id).url,
        { ip_address_id: id },
        {
            preserveScroll: true,
            onStart: () => {
                attaching.value = id;
                error.value = '';
            },
            onFinish: () => {
                attaching.value = null;
            },
            onSuccess: () => emit('close'),
            // An address attached elsewhere in the meantime is refused,
            // with a message that names the device that has it.
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ?? trans('Something went wrong.');
            },
        },
    );
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-2xl" data-test="attach-address-dialog">
            <DialogHeader>
                <DialogTitle>{{ $t('Attach an address') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'The documented addresses that belong to no device, on every site. An address of another site than :device is accepted, and shown as a deviation.',
                            { device: device.name },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label for="attach-search">{{
                    $t('Address or host name')
                }}</Label>
                <div class="flex items-center gap-2.5">
                    <Input
                        id="attach-search"
                        v-model="search"
                        type="search"
                        name="search"
                        maxlength="253"
                        autocomplete="off"
                        :placeholder="$t('10.10.20 or srv-')"
                        data-test="attach-search"
                    />
                    <Spinner v-if="searching" />
                </div>
                <InputError :message="error" />
            </div>

            <!-- Always there, so that a screen reader is told when the
                 list changes. -->
            <div role="status" data-test="attachable-addresses">
                <p
                    v-if="attachable === undefined"
                    class="text-muted-foreground"
                >
                    {{ $t('Searching…') }}
                </p>
                <p
                    v-else-if="attachable.items.length === 0"
                    class="rounded-lg border border-dashed p-4 text-muted-foreground"
                >
                    {{ $t('No address without a device matches.') }}
                </p>
                <template v-else>
                    <ul
                        class="panel flex max-h-80 flex-col divide-y overflow-y-auto"
                    >
                        <li
                            v-for="address in attachable.items"
                            :key="address.id"
                            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 px-4 py-2.5"
                            data-test="attachable-address"
                        >
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <div
                                    class="flex flex-wrap items-baseline gap-x-3"
                                >
                                    <span
                                        class="font-mono text-sm font-medium"
                                        >{{ address.address }}</span
                                    >
                                    <span v-if="address.hostname">{{
                                        address.hostname
                                    }}</span>
                                </div>
                                <span class="text-note text-muted-foreground">
                                    <span class="font-mono">{{
                                        address.subnet
                                    }}</span>
                                    <template v-if="address.site">
                                        — {{ address.site }}
                                    </template>
                                </span>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="attaching !== null"
                                :data-test="`attach-${address.address}`"
                                @click="attach(address.id)"
                            >
                                <Spinner v-if="attaching === address.id" />
                                {{ $t('Attach') }}
                            </Button>
                        </li>
                    </ul>
                    <p
                        v-if="attachable.more"
                        class="mt-2 text-sm text-muted-foreground"
                        data-test="attachable-more"
                    >
                        {{
                            $tc(
                                'Only the first address is listed: type more to find the others.|Only the first :count addresses are listed: type more to find the others.',
                                attachable.items.length,
                            )
                        }}
                    </p>
                </template>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" @click="emit('close')">
                    {{ $t('Close') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
