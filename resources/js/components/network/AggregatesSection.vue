<!-- torsade/resources/js/components/network/AggregatesSection.vue -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import AggregateDialog from '@/components/network/AggregateDialog.vue';
import Deviation from '@/components/network/Deviation.vue';
import RowActions from '@/components/RowActions.vue';
import { Button } from '@/components/ui/button';
import { trans, transChoice } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { destroy } from '@/routes/port-aggregates';
import type {
    AggregateDraft,
    DeviceMember,
    Option,
    Port,
    PortAggregate,
    VlanGroup,
} from '@/types';

// The aggregates of ports of a device: several of its ports used as one
// link, on one or several of its members. The mode and the VLANs are those
// of the aggregate.
const props = defineProps<{
    device: { id: number; name: string };
    members: DeviceMember[];
    // Every port of the device: those of an aggregate name it.
    ports: Port[];
    aggregates: PortAggregate[];
    protocols: Option[];
    // The VLANs a port can carry: those of the site of the device first,
    // then those of the other sites.
    vlans: VlanGroup[];
    canEdit: boolean;
    // The aggregate to create in front of another one, asked from its
    // deviation: its window opens with the page.
    draft?: AggregateDraft | null;
}>();

// The ports of an aggregate, member by member.
const portsOf = (
    aggregate: PortAggregate,
): { position: number; names: string[] }[] =>
    props.members
        .map((member) => ({
            position: member.position,
            names: props.ports
                .filter(
                    (port) =>
                        port.member_id === member.id &&
                        port.aggregate?.id === aggregate.id,
                )
                .map((port) => port.name),
        }))
        .filter((group) => group.names.length > 0);

const countOf = (aggregate: PortAggregate): number =>
    props.ports.filter((port) => port.aggregate?.id === aggregate.id).length;

const dialogOpen = ref<boolean>(false);
const aggregateToEdit = ref<PortAggregate | null>(null);
const draftToOpen = ref<AggregateDraft | null>(null);

const openDialog = (aggregate: PortAggregate | null): void => {
    aggregateToEdit.value = aggregate;
    draftToOpen.value = null;
    dialogOpen.value = true;
};

watch(
    () => props.draft,
    (draft) => {
        if (draft && props.canEdit) {
            aggregateToEdit.value = null;
            draftToOpen.value = draft;
            dialogOpen.value = true;
        }
    },
    { immediate: true },
);

const aggregateToDelete = ref<PortAggregate | null>(null);

// What deleting the aggregate does to its ports.
const deletionWarning = computed<string>(() => {
    const aggregate = aggregateToDelete.value;

    if (!aggregate) {
        return '';
    }

    const names = { aggregate: aggregate.name, name: props.device.name };
    const count = countOf(aggregate);

    return count === 0
        ? trans('The aggregate :aggregate of :name will be deleted.', names)
        : transChoice(
              'The aggregate :aggregate of :name will be deleted. Its port is free again, without a mode nor a VLAN.|The aggregate :aggregate of :name will be deleted. Its :count ports are free again, without a mode nor a VLAN.',
              count,
              names,
          );
});

const confirmDeletion = (): void => {
    const aggregate = aggregateToDelete.value;

    aggregateToDelete.value = null;

    if (!aggregate) {
        return;
    }

    router.delete(destroy(aggregate.id).url, {
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
        id="device-aggregates"
        class="flex min-w-0 scroll-mt-4 flex-col gap-3"
        :aria-label="$t('Aggregates')"
        data-test="device-aggregates"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">
                {{ $t('Aggregates') }}
                <span class="font-normal text-muted-foreground">{{
                    formatCount(aggregates.length)
                }}</span>
            </h2>
            <Button
                v-if="canEdit"
                type="button"
                size="sm"
                data-test="add-aggregate"
                @click="openDialog(null)"
            >
                {{ $t('New aggregate') }}
            </Button>
        </div>

        <p
            v-if="aggregates.length === 0"
            class="rounded-lg border border-dashed p-4 text-muted-foreground"
        >
            {{ $t('No aggregate of ports on this device.') }}
        </p>

        <div v-else class="table-frame">
            <table class="table-dense" :aria-label="$t('Aggregates')">
                <thead>
                    <tr>
                        <th scope="col">{{ $t('Name') }}</th>
                        <th scope="col">{{ $t('Protocol') }}</th>
                        <th scope="col">{{ $t('Ports') }}</th>
                        <th scope="col">{{ $t('Mode and VLANs') }}</th>
                        <th v-if="canEdit" scope="col" class="text-right">
                            {{ $t('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="aggregate in aggregates"
                        :key="aggregate.id"
                        data-test="aggregate-row"
                        :data-aggregate="aggregate.name"
                    >
                        <td>
                            <span
                                class="font-mono text-sm font-medium whitespace-nowrap"
                                >{{ aggregate.name }}</span
                            >
                            <div
                                v-if="aggregate.description"
                                class="text-note whitespace-pre-line text-muted-foreground"
                            >
                                {{ aggregate.description }}
                            </div>
                            <!-- Its links lead to several devices, or to
                                 ports that are not one same aggregate. -->
                            <Deviation
                                class="mt-1 min-w-44"
                                :deviations="aggregate.deviations"
                                compact
                            />
                        </td>
                        <td class="whitespace-nowrap">
                            {{ aggregate.protocol_label }}
                        </td>
                        <td class="min-w-44">
                            <div
                                v-for="group in portsOf(aggregate)"
                                :key="group.position"
                            >
                                <span class="text-muted-foreground">{{
                                    $t('Member :position:', {
                                        position: group.position,
                                    })
                                }}</span>
                                {{ ' ' }}
                                <span class="font-mono text-sm">{{
                                    group.names.join(', ')
                                }}</span>
                            </div>
                            <span
                                v-if="countOf(aggregate) === 0"
                                class="text-muted-foreground"
                                >{{ $t('No port') }}</span
                            >
                        </td>
                        <td class="min-w-36">
                            <span v-if="aggregate.summary">{{
                                aggregate.summary
                            }}</span>
                            <span v-else class="text-muted-foreground">{{
                                $t('None')
                            }}</span>
                        </td>
                        <td v-if="canEdit" class="py-1">
                            <RowActions
                                :edit-test="`edit-aggregate-${aggregate.name}`"
                                :delete-test="`delete-aggregate-${aggregate.name}`"
                                @edit="openDialog(aggregate)"
                                @delete="aggregateToDelete = aggregate"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <AggregateDialog
        :open="dialogOpen"
        :device="device"
        :aggregate="aggregateToEdit"
        :members="members"
        :ports="ports"
        :protocols="protocols"
        :vlans="vlans"
        :draft="draftToOpen"
        @close="dialogOpen = false"
    />

    <ConfirmDialog
        :open="aggregateToDelete !== null"
        :title="$t('Delete this aggregate?')"
        :description="deletionWarning"
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="aggregateToDelete = null"
    />
</template>
