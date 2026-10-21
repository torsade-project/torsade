<!-- torsade/resources/js/components/network/BreakdownDialog.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import RangeKindLabel from '@/components/network/RangeKindLabel.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/subnets/breakdown';
import type { BreakdownRange, Subnet } from '@/types';

// The standard breakdown of the addressing rule, shown before it is applied
// to a subnet that has no range: the ranges it would create, all of them or
// none.
const props = defineProps<{
    open: boolean;
    subnet: Subnet;
    // The ranges of the rule laid out in this subnet, as the server works
    // them out.
    ranges: BreakdownRange[];
}>();

const emit = defineEmits<{
    close: [];
}>();

// Nothing is sent but the wish: the server works the ranges out again, from
// the rule in force and from the subnet as it is stored.
const form = useForm<{ breakdown?: never }>({});

const fits = computed<boolean>(() => props.ranges.every((range) => range.fits));

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.clearErrors();
        }
    },
);

const submit = (): void => {
    form.post(store(props.subnet.id).url, {
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
        <DialogContent class="sm:max-w-lg" data-test="breakdown-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Apply the standard breakdown')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'The ranges of the addressing rule, shifted from the network address of :cidr. All of them are created, or none.',
                                { cidr: subnet.cidr },
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <ul class="flex flex-col gap-2.5">
                    <li
                        v-for="range in ranges"
                        :key="range.start_address"
                        class="panel flex flex-col gap-1.5 px-4 py-3"
                        :data-fits="range.fits"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <RangeKindLabel
                                :kind="range.kind"
                                :label="range.kind_label"
                            />
                            <span v-if="range.name" class="font-medium">{{
                                range.name
                            }}</span>
                        </div>
                        <span class="font-mono text-table">
                            {{ range.start_address }} – {{ range.end_address }}
                        </span>
                        <span
                            v-if="range.fits"
                            class="text-sm text-muted-foreground"
                            >{{
                                $tc(
                                    ':count address|:count addresses',
                                    range.size,
                                )
                            }}</span
                        >
                        <span v-else class="text-sm text-pair-orange">{{
                            $t('Goes beyond the usable addresses of the subnet')
                        }}</span>
                    </li>
                </ul>

                <p v-if="!fits" class="text-sm text-pair-orange">
                    {{
                        $t(
                            'The breakdown does not fit in this subnet (:first – :last): it cannot be applied here.',
                            {
                                first: subnet.first_usable,
                                last: subnet.last_usable,
                            },
                        )
                    }}
                </p>

                <InputError
                    :message="
                        (form.errors as Record<string, string | undefined>)
                            .breakdown
                    "
                />

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
                        :disabled="form.processing || !fits"
                        data-test="confirm-breakdown"
                    >
                        <Spinner v-if="form.processing" />
                        {{
                            $tc(
                                'Create this range|Create these :count ranges',
                                ranges.length,
                            )
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
