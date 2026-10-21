<!-- torsade/resources/js/pages/catalog/Show.vue -->
<script setup lang="ts">
import {
    Head,
    Link,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import CatalogVlanDialog from '@/components/network/CatalogVlanDialog.vue';
import Deviation from '@/components/network/Deviation.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { attach, destroy, index, show } from '@/routes/catalog-vlans';
import { create as createVlan, show as showVlan } from '@/routes/vlans';
import type {
    CatalogCandidate,
    CatalogEntry,
    CatalogSite,
    CatalogVlan,
} from '@/types';

// An entry of the catalogue: the VLAN that follows it on each site, and the
// VLANs that already bear its number and could be attached to it.
const props = defineProps<{
    entry: CatalogEntry;
    sites: CatalogSite[];
    // The VLANs that bear the number of the entry and follow no entry yet.
    candidates: CatalogCandidate[];
}>();

const label = computed<string>(
    () => `${props.entry.vid} — ${props.entry.name}`,
);

setLayoutProps({
    breadcrumbs: [
        { title: trans('VLAN catalogue'), href: index() },
        { title: props.entry.name, href: show(props.entry.id) },
    ],
});

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

// One line per site, with the VLAN that follows the entry there, if any.
const lines = computed<{ site: CatalogSite; vlan: CatalogVlan | null }[]>(
    () => {
        const bySite = new Map(
            props.entry.vlans.map((vlan) => [vlan.site_id, vlan]),
        );

        return props.sites.map((site) => ({
            site,
            vlan: bySite.get(site.id) ?? null,
        }));
    },
);

const hasDeviations = computed<boolean>(() =>
    props.entry.vlans.some((vlan) => vlan.deviations.length > 0),
);

// --- Attaching several VLANs at once ---------------------------------------

const checked = ref<number[]>([]);

const form = useForm<{ vlans: number[] }>({ vlans: [] });

const allChecked = computed<boolean>(
    () =>
        props.candidates.length > 0 &&
        checked.value.length === props.candidates.length,
);

const toggleAll = (): void => {
    checked.value = allChecked.value
        ? []
        : props.candidates.map((candidate) => candidate.id);
};

// The server tells each refusal by the place of the VLAN in what was sent.
const errorOf = (candidate: CatalogCandidate): string | undefined => {
    const line = form.vlans.indexOf(candidate.id);

    return line === -1
        ? undefined
        : (form.errors as Record<string, string>)[`vlans.${line}`];
};

const submitAttachment = (): void => {
    // In the order of the list, so that a refusal is found on its line.
    form.vlans = props.candidates
        .map((candidate) => candidate.id)
        .filter((id) => checked.value.includes(id));

    form.post(attach(props.entry.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            checked.value = [];
        },
    });
};

// --- Editing and deleting the entry -----------------------------------------

const dialogOpen = ref<boolean>(false);
const confirmingDeletion = ref<boolean>(false);

const confirmDeletion = (): void => {
    confirmingDeletion.value = false;

    // An entry that still has VLANs attached is refused by the server, with
    // a message that says what to do first.
    router.delete(destroy(props.entry.id).url, {
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <Head :title="$t('Catalogue entry :label', { label })" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div class="flex min-w-0 flex-col gap-3">
                <h1 class="text-title font-semibold">
                    {{ $t('Catalogue entry :label', { label }) }}
                </h1>
                <p class="text-muted-foreground">
                    {{
                        $t(
                            'The number and the name a VLAN is expected to have on every site.',
                        )
                    }}
                </p>
            </div>
            <div v-if="canEdit" class="flex flex-wrap gap-2.5">
                <Button
                    type="button"
                    variant="outline"
                    data-test="edit-catalog-vlan"
                    @click="dialogOpen = true"
                >
                    {{ $t('Edit') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    data-test="delete-catalog-vlan"
                    @click="confirmingDeletion = true"
                >
                    {{ $t('Delete') }}
                </Button>
            </div>
        </div>

        <p
            v-if="entry.description"
            class="max-w-prose whitespace-pre-line text-muted-foreground"
        >
            {{ entry.description }}
        </p>

        <section
            class="flex flex-col gap-4"
            :aria-label="$t('On each site')"
            data-test="catalog-sites"
        >
            <Heading
                variant="small"
                :title="$t('On each site')"
                :description="
                    $t('An entry is followed by one VLAN per site at most.')
                "
            />

            <p
                v-if="sites.length === 0"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
            >
                {{ $t('No site yet.') }}
            </p>

            <div v-else class="table-frame">
                <table class="table-dense" :aria-label="$t('On each site')">
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('Site') }}</th>
                            <th scope="col">{{ $t('VLAN') }}</th>
                            <th scope="col">{{ $t('Status') }}</th>
                            <th v-if="hasDeviations" scope="col">
                                {{ $t('Deviation') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in lines"
                            :key="line.site.id"
                            :data-test="`catalog-site-line-${line.site.id}`"
                        >
                            <th
                                scope="row"
                                class="font-medium whitespace-nowrap"
                            >
                                {{ line.site.name }}
                            </th>
                            <template v-if="line.vlan">
                                <td class="whitespace-nowrap">
                                    <Link
                                        :href="showVlan(line.vlan.id)"
                                        class="link"
                                    >
                                        <span class="font-mono text-sm">{{
                                            line.vlan.vid
                                        }}</span>
                                        — {{ line.vlan.name }}
                                    </Link>
                                </td>
                                <td>
                                    <StatusBadge
                                        :status="line.vlan.status"
                                        :label="line.vlan.status_label"
                                    />
                                </td>
                                <td v-if="hasDeviations">
                                    <Deviation
                                        :deviations="line.vlan.deviations"
                                        compact
                                    />
                                </td>
                            </template>
                            <template v-else>
                                <td
                                    :colspan="hasDeviations ? 3 : 2"
                                    class="text-muted-foreground"
                                >
                                    <div
                                        class="flex flex-wrap items-center gap-x-4 gap-y-1"
                                    >
                                        <span>{{
                                            $t(
                                                'No VLAN follows this entry on this site.',
                                            )
                                        }}</span>
                                        <Link
                                            v-if="canEdit"
                                            :href="
                                                createVlan({
                                                    query: {
                                                        site: line.site.id,
                                                        catalog: entry.id,
                                                    },
                                                })
                                            "
                                            class="link"
                                            >{{ $t('Create this VLAN') }}</Link
                                        >
                                    </div>
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-if="canEdit"
            class="flex flex-col gap-4"
            :aria-label="$t('Attach existing VLANs')"
            data-test="catalog-attach"
        >
            <Heading
                variant="small"
                :title="$t('Attach existing VLANs')"
                :description="
                    $t(
                        'The VLANs that bear the number :vid and follow no entry of the catalogue yet, on every site. Tick those that are this VLAN: they are attached together, or none is.',
                        { vid: entry.vid },
                    )
                "
            />

            <p
                v-if="candidates.length === 0"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
            >
                {{
                    $t('No unattached VLAN bears the number :vid.', {
                        vid: entry.vid,
                    })
                }}
            </p>

            <form
                v-else
                class="flex flex-col gap-4"
                @submit.prevent="submitAttachment"
            >
                <ul class="panel flex flex-col divide-y">
                    <li
                        v-for="candidate in candidates"
                        :key="candidate.id"
                        class="flex flex-col gap-1.5 px-4 py-3"
                        :data-test="`catalog-candidate-${candidate.id}`"
                    >
                        <label
                            class="flex flex-wrap items-center gap-x-3 gap-y-1"
                        >
                            <input
                                v-model="checked"
                                type="checkbox"
                                name="vlans[]"
                                :value="candidate.id"
                                class="size-4.5 shrink-0 rounded-xs accent-primary"
                                :aria-invalid="
                                    errorOf(candidate) ? 'true' : undefined
                                "
                            />
                            <span class="font-medium">{{
                                candidate.site.name
                            }}</span>
                            <span>
                                <span class="font-mono text-sm">{{
                                    candidate.vid
                                }}</span>
                                — {{ candidate.name }}
                            </span>
                            <StatusBadge
                                class="text-sm text-muted-foreground"
                                :status="candidate.status"
                                :label="candidate.status_label"
                            />
                        </label>
                        <InputError :message="errorOf(candidate)" />
                    </li>
                </ul>

                <InputError :message="form.errors.vlans" />

                <div class="flex flex-wrap items-center gap-2.5">
                    <Button
                        type="submit"
                        :disabled="form.processing || checked.length === 0"
                        data-test="attach-vlans"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Attach the selection') }}
                    </Button>
                    <Button type="button" variant="outline" @click="toggleAll">
                        {{ allChecked ? $t('Untick all') : $t('Tick all') }}
                    </Button>
                </div>
            </form>
        </section>
    </div>

    <CatalogVlanDialog
        :open="dialogOpen"
        :entry="entry"
        @close="dialogOpen = false"
    />

    <ConfirmDialog
        :open="confirmingDeletion"
        :title="$t('Delete this catalogue entry?')"
        :description="
            $t(
                ':label will be deleted. An entry that still has VLANs attached cannot be deleted.',
                { label },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="confirmingDeletion = false"
    />
</template>
