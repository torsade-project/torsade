<!-- torsade/resources/js/components/network/IpRangesSection.vue -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import BreakdownDialog from '@/components/network/BreakdownDialog.vue';
import RangeKindLabel from '@/components/network/RangeKindLabel.vue';
import RowActions from '@/components/RowActions.vue';
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
import { destroy, store, update } from '@/routes/ip-ranges';
import type {
    BreakdownRange,
    IpRange,
    Option,
    RangeKindValue,
    Subnet,
} from '@/types';

// The ranges of a subnet, managed without leaving its page: how its
// addresses are set aside, for a DHCP server, for static addresses, or kept
// in reserve.
const props = defineProps<{
    subnet: Subnet;
    ranges: IpRange[];
    kinds: Option[];
    canEdit: boolean;
    // The ranges of the addressing rule as they would be created here, when
    // the subnet has none and the person signed in may create some; null
    // otherwise.
    breakdown: BreakdownRange[] | null;
}>();

const breakdownOpen = ref<boolean>(false);

const form = useForm<{
    kind: RangeKindValue;
    start_address: string;
    end_address: string;
    name: string;
    description: string;
}>({
    kind: 'dhcp',
    start_address: '',
    end_address: '',
    name: '',
    description: '',
});

const dialogOpen = ref<boolean>(false);
const editedRange = ref<IpRange | null>(null);
const rangeToDelete = ref<IpRange | null>(null);

const openDialog = (range: IpRange | null): void => {
    editedRange.value = range;

    form.clearErrors();
    form.kind = range?.kind ?? 'dhcp';
    form.start_address = range?.start_address ?? '';
    form.end_address = range?.end_address ?? '';
    form.name = range?.name ?? '';
    form.description = range?.description ?? '';

    dialogOpen.value = true;
};

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editedRange.value) {
        form.put(update(editedRange.value.id).url, options);
    } else {
        form.post(store(props.subnet.id).url, options);
    }
};

const confirmDeletion = (): void => {
    const range = rangeToDelete.value;

    rangeToDelete.value = null;

    if (!range) {
        return;
    }

    router.delete(destroy(range.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <section
        class="flex flex-col gap-3"
        aria-labelledby="ip-ranges-title"
        data-test="ip-ranges"
    >
        <h2 id="ip-ranges-title" class="text-lg font-semibold">
            {{ $t('Ranges') }}
        </h2>
        <p class="text-sm text-muted-foreground">
            {{
                $t(
                    'How the addresses of the subnet are set aside. A static address cannot be inside a DHCP range; the next address is proposed in a static range, never in a reserved one.',
                )
            }}
        </p>

        <p
            v-if="ranges.length === 0"
            class="rounded-lg border border-dashed p-4 text-muted-foreground"
        >
            {{ $t('No range in this subnet.') }}
        </p>

        <ul v-else class="flex flex-col gap-2.5">
            <li
                v-for="range in ranges"
                :key="range.id"
                class="panel flex items-start justify-between gap-3 px-4 py-3.5"
                :data-test="`ip-range-${range.id}`"
                :data-kind="range.kind"
            >
                <div class="flex min-w-0 flex-col gap-1.5">
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
                        v-if="range.description"
                        class="text-sm text-muted-foreground"
                        >{{ range.description }}</span
                    >
                    <span class="text-sm text-muted-foreground">{{
                        $tc(':count address|:count addresses', range.size)
                    }}</span>
                </div>
                <RowActions
                    v-if="canEdit"
                    :edit-test="`edit-ip-range-${range.id}`"
                    :delete-test="`delete-ip-range-${range.id}`"
                    @edit="openDialog(range)"
                    @delete="rangeToDelete = range"
                />
            </li>
        </ul>

        <div v-if="canEdit" class="flex flex-wrap gap-2.5">
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-test="create-ip-range"
                @click="openDialog(null)"
            >
                {{ $t('Add a range') }}
            </Button>
            <!-- The ranges of the addressing rule, in one action: offered
                 while the subnet has none. -->
            <Button
                v-if="breakdown"
                type="button"
                variant="outline"
                size="sm"
                data-test="apply-breakdown"
                @click="breakdownOpen = true"
            >
                {{ $t('Apply the standard breakdown') }}
            </Button>
        </div>
    </section>

    <BreakdownDialog
        v-if="breakdown"
        :open="breakdownOpen"
        :subnet="subnet"
        :ranges="breakdown"
        @close="breakdownOpen = false"
    />

    <Dialog :open="dialogOpen" @update:open="dialogOpen = $event">
        <DialogContent class="sm:max-w-md" data-test="ip-range-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        editedRange ? $t('Edit the range') : $t('New range')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t('Usable addresses of :cidr: :first – :last.', {
                                cidr: subnet.cidr,
                                first: subnet.first_usable,
                                last: subnet.last_usable,
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="range-kind">{{ $t('Type') }}</Label>
                        <NativeSelect
                            id="range-kind"
                            v-model="form.kind"
                            name="kind"
                            :aria-invalid="
                                form.errors.kind ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="kind in kinds"
                                :key="kind.value"
                                :value="kind.value"
                            >
                                {{ kind.label }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="range-name">{{ $t('Name') }}</Label>
                        <Input
                            id="range-name"
                            v-model="form.name"
                            name="name"
                            maxlength="64"
                            autocomplete="off"
                            :aria-invalid="
                                form.errors.name ? 'true' : undefined
                            "
                        />
                    </div>
                </div>
                <InputError :message="form.errors.kind" />
                <InputError :message="form.errors.name" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="range-start">{{ $t('Start') }}</Label>
                        <Input
                            id="range-start"
                            v-model="form.start_address"
                            name="start_address"
                            required
                            autocomplete="off"
                            class="font-mono"
                            :aria-invalid="
                                form.errors.start_address ? 'true' : undefined
                            "
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="range-end">{{ $t('End') }}</Label>
                        <Input
                            id="range-end"
                            v-model="form.end_address"
                            name="end_address"
                            required
                            autocomplete="off"
                            class="font-mono"
                            :aria-invalid="
                                form.errors.end_address ? 'true' : undefined
                            "
                        />
                    </div>
                </div>
                <!-- Under both fields: a message may be about the whole range. -->
                <InputError :message="form.errors.start_address" />
                <InputError :message="form.errors.end_address" />

                <div class="grid gap-2">
                    <Label for="range-description">{{
                        $t('Description')
                    }}</Label>
                    <Input
                        id="range-description"
                        v-model="form.description"
                        name="description"
                        maxlength="1000"
                        autocomplete="off"
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
                        @click="dialogOpen = false"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="save-ip-range"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        :open="rangeToDelete !== null"
        :title="$t('Delete this range?')"
        :description="
            $t('The range :range will be deleted.', {
                range: rangeToDelete
                    ? `${rangeToDelete.start_address} – ${rangeToDelete.end_address}`
                    : '',
            })
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="rangeToDelete = null"
    />
</template>
