<!-- torsade/resources/js/pages/device-templates/Index.vue -->
<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import RowActions from '@/components/RowActions.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { namesInWords } from '@/lib/portPreview';
import { create, destroy, edit, index } from '@/routes/device-templates';
import { store as storeGenerics } from '@/routes/device-templates/generics';
import type { DataColumn, DeviceTemplate } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Device templates'),
                href: index(),
            },
        ],
    },
});

defineProps<{
    templates: DeviceTemplate[];
    // How many generic templates are not there yet.
    missingGenerics: number;
}>();

// Only decides which buttons are shown: the server checks every action.
const page = usePage();
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

// The manufacturer and the model together, as they are read on a label.
const make = (template: DeviceTemplate): string =>
    [template.manufacturer, template.model]
        .filter((part) => part !== null && part !== '')
        .join(' ');

const columns = computed<DataColumn<DeviceTemplate>[]>(() => [
    { id: 'name', header: trans('Name'), value: (template) => template.name },
    {
        id: 'type',
        header: trans('Type'),
        value: (template) => template.device_type_label,
        class: 'whitespace-nowrap',
    },
    {
        id: 'make',
        header: trans('Manufacturer and model'),
        value: (template) => make(template) || null,
    },
    {
        id: 'ports',
        header: trans('Ports'),
        value: (template) => template.ports_count,
        align: 'right',
    },
    {
        id: 'members',
        header: trans('Members'),
        value: (template) => template.members_count,
        align: 'right',
    },
    ...(canEdit.value
        ? [
              {
                  id: 'actions',
                  header: trans('Actions'),
                  value: () => null,
                  sortable: false,
                  align: 'right',
                  class: 'py-1',
              } satisfies DataColumn<DeviceTemplate>,
          ]
        : []),
]);

const addingGenerics = ref<boolean>(false);

const addGenerics = (): void => {
    // The server creates those that are missing, by their name, and leaves
    // the others as they are.
    router.post(
        storeGenerics().url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                addingGenerics.value = true;
            },
            onFinish: () => {
                addingGenerics.value = false;
            },
        },
    );
};

const templateToDelete = ref<DeviceTemplate | null>(null);

const confirmDeletion = (): void => {
    const template = templateToDelete.value;

    templateToDelete.value = null;

    if (!template) {
        return;
    }

    // A template that members of devices still use is refused by the
    // server, with a message that says what to do first.
    router.delete(destroy(template.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <Head :title="$t('Device templates')" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <Heading
                :title="$t('Device templates')"
                :description="
                    $t(
                        'What a kind of machine is made of: its ports, by groups. The ports of a device are copied from its template when a member is added; changing a template afterwards changes no device.',
                    )
                "
            />
            <div v-if="canEdit" class="flex flex-wrap gap-2.5">
                <Button
                    v-if="missingGenerics > 0"
                    type="button"
                    variant="outline"
                    :disabled="addingGenerics"
                    data-test="add-generic-templates"
                    @click="addGenerics"
                >
                    <Spinner v-if="addingGenerics" />
                    {{ $t('Add the generic templates') }}
                </Button>
                <Button as-child data-test="create-template-button">
                    <Link :href="create()">{{ $t('New template') }}</Link>
                </Button>
            </div>
        </div>

        <DataTable
            :rows="templates"
            :columns="columns"
            :label="$t('Device templates')"
            row-test="template-row"
            :row-anchor="(template) => `template-${template.id}`"
            :empty-text="
                canEdit
                    ? $t(
                          'No template yet. Add the generic ones, or create your own.',
                      )
                    : $t('No template yet.')
            "
        >
            <template #cell-name="{ row }">
                <Link
                    v-if="canEdit"
                    :href="edit(row.id)"
                    class="link font-medium"
                    >{{ row.name }}</Link
                >
                <span v-else class="font-medium">{{ row.name }}</span>
                <!-- The names its ports get on the first member. -->
                <div
                    v-if="row.port_groups.length > 0"
                    class="font-mono text-note text-muted-foreground"
                    data-test="template-names"
                >
                    {{ namesInWords(row.port_groups) }}
                </div>
            </template>
            <template #cell-make="{ row }">
                <span v-if="make(row)">{{ make(row) }}</span>
                <span v-else class="text-muted-foreground">{{
                    $t('Not specified')
                }}</span>
            </template>
            <template #cell-actions="{ row }">
                <RowActions
                    :edit-test="`edit-template-${row.id}`"
                    :delete-test="`delete-template-${row.id}`"
                    @edit="router.visit(edit(row.id).url)"
                    @delete="templateToDelete = row"
                />
            </template>
        </DataTable>
    </div>

    <ConfirmDialog
        :open="templateToDelete !== null"
        :title="$t('Delete this template?')"
        :description="
            $t(
                ':name will be deleted. A template that members of devices still use cannot be deleted. The devices keep the ports they have.',
                { name: templateToDelete?.name ?? '' },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="templateToDelete = null"
    />
</template>
