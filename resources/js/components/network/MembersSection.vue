<!-- torsade/resources/js/components/network/MembersSection.vue -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import MemberDialog from '@/components/network/MemberDialog.vue';
import PlacePath from '@/components/network/PlacePath.vue';
import RowActions from '@/components/RowActions.vue';
import { Button } from '@/components/ui/button';
import { trans, transChoice } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { destroy } from '@/routes/device-members';
import type {
    DeviceMember,
    MemberLimits,
    Option,
    Place,
    TemplateChoice,
} from '@/types';

// The boxes a device is made of, by number: one for a switch alone,
// several for a stack. Members are added, modified and deleted from here.
const props = defineProps<{
    device: { id: number; name: string };
    members: DeviceMember[];
    templates: TemplateChoice[];
    roles: Option[];
    // The rooms of the site of the device.
    rooms: Place[];
    limits: MemberLimits;
    canEdit: boolean;
}>();

const dialogOpen = ref<boolean>(false);
const memberToEdit = ref<DeviceMember | null>(null);

const openDialog = (member: DeviceMember | null): void => {
    memberToEdit.value = member;
    dialogOpen.value = true;
};

// A device holds so many members at most.
const full = computed<boolean>(
    () => props.members.length > props.limits.max - props.limits.min,
);

const memberToDelete = ref<DeviceMember | null>(null);

// What deleting the member takes away with it.
const deletionWarning = computed<string>(() => {
    const member = memberToDelete.value;

    if (!member) {
        return '';
    }

    const names = { position: member.position, name: props.device.name };

    return member.ports_count === 0
        ? trans('The member :position of :name will be deleted.', names)
        : transChoice(
              'The member :position of :name will be deleted with its port.|The member :position of :name will be deleted with its :count ports.',
              member.ports_count,
              names,
          );
});

const confirmDeletion = (): void => {
    const member = memberToDelete.value;

    memberToDelete.value = null;

    if (!member) {
        return;
    }

    router.delete(destroy(member.id).url, {
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
        class="flex min-w-0 flex-col gap-3"
        :aria-label="$t('Members')"
        data-test="device-members"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">
                {{ $t('Members') }}
                <span class="font-normal text-muted-foreground">{{
                    formatCount(members.length)
                }}</span>
            </h2>
            <Button
                v-if="canEdit"
                type="button"
                size="sm"
                :disabled="full"
                data-test="add-member"
                @click="openDialog(null)"
            >
                {{ $t('Add a member') }}
            </Button>
        </div>

        <div class="table-frame">
            <table class="table-dense" :aria-label="$t('Members')">
                <thead>
                    <tr>
                        <th scope="col">{{ $t('Number') }}</th>
                        <th scope="col">{{ $t('Role') }}</th>
                        <th scope="col">{{ $t('Template') }}</th>
                        <th scope="col">{{ $t('Serial number') }}</th>
                        <th scope="col">{{ $t('Room') }}</th>
                        <th scope="col" class="text-right">
                            {{ $t('Ports') }}
                        </th>
                        <th v-if="canEdit" scope="col" class="text-right">
                            {{ $t('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="member in members"
                        :key="member.id"
                        :data-test="`member-row-${member.position}`"
                    >
                        <td class="font-mono text-sm font-medium">
                            {{ member.position }}
                        </td>
                        <td class="whitespace-nowrap">
                            {{ member.role_label }}
                        </td>
                        <td class="min-w-40">
                            <span v-if="member.template">{{
                                member.template.name
                            }}</span>
                            <span v-else class="text-muted-foreground">{{
                                $t('None')
                            }}</span>
                            <div
                                v-if="member.description"
                                class="text-note whitespace-pre-line text-muted-foreground"
                            >
                                {{ member.description }}
                            </div>
                        </td>
                        <td>
                            <span
                                v-if="member.serial_number"
                                class="font-mono text-sm break-all"
                                >{{ member.serial_number }}</span
                            >
                            <span v-else class="text-muted-foreground">{{
                                $t('Not specified')
                            }}</span>
                        </td>
                        <td class="min-w-40">
                            <PlacePath
                                v-if="member.room"
                                :path="member.room.path"
                                :technical="member.room.is_technical"
                            />
                            <span v-else class="text-muted-foreground">{{
                                $t('Same as the device')
                            }}</span>
                        </td>
                        <td class="text-right font-mono text-sm">
                            {{ formatCount(member.ports_count) }}
                        </td>
                        <td v-if="canEdit" class="py-1">
                            <RowActions
                                :edit-test="`edit-member-${member.position}`"
                                :delete-test="`delete-member-${member.position}`"
                                @edit="openDialog(member)"
                                @delete="memberToDelete = member"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <MemberDialog
        :open="dialogOpen"
        :device="device"
        :member="memberToEdit"
        :members="members"
        :templates="templates"
        :roles="roles"
        :rooms="rooms"
        :limits="limits"
        @close="dialogOpen = false"
    />

    <ConfirmDialog
        :open="memberToDelete !== null"
        :title="$t('Delete this member?')"
        :description="deletionWarning"
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="memberToDelete = null"
    />
</template>
