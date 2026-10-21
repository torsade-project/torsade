<!-- torsade/resources/js/pages/devices/Index.vue -->
<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import DeviceTable from '@/components/network/DeviceTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { trans } from '@/lib/i18n';
import { create, index } from '@/routes/devices';
import { index as sitesIndex } from '@/routes/sites';
import type { Device, DeviceFilters, Option, SiteOption } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Devices'),
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    devices: Device[];
    filters: DeviceFilters;
    sites: SiteOption[];
    types: Option[];
    statuses: Option[];
}>();

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

const filters = reactive<DeviceFilters>({ ...props.filters });

// Filtering and searching are a plain GET request: the address of the page
// keeps them, so that a filtered list can be bookmarked or shared.
const applyFilters = (): void => {
    const query = Object.fromEntries(
        Object.entries(filters)
            .map(([key, value]) => [key, value.trim()])
            .filter(([, value]) => value !== ''),
    );

    router.get(index().url, query, { preserveState: true, replace: true });
};

const hasFilters = computed<boolean>(() =>
    Object.values(props.filters).some((value) => value !== ''),
);
</script>

<template>
    <Head :title="$t('Devices')" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <Heading
                :title="$t('Devices')"
                :description="
                    $t(
                        'The machines of each site: firewalls, switches, servers, printers. The IP addresses attached to a device are its interfaces.',
                    )
                "
            />
            <Button
                v-if="canEdit && sites.length > 0"
                as-child
                data-test="create-device-button"
            >
                <Link
                    :href="
                        create({
                            query: filters.site ? { site: filters.site } : {},
                        })
                    "
                    >{{ $t('New device') }}</Link
                >
            </Button>
        </div>

        <!-- First use: a device belongs to a site, so a site comes first. -->
        <div
            v-if="sites.length === 0"
            class="flex flex-col items-center gap-4 rounded-lg border border-dashed p-8 text-center"
            data-test="no-site-notice"
        >
            <p class="text-muted-foreground">
                {{
                    $t(
                        'A device belongs to a site. Create a site first, then come back to declare its devices.',
                    )
                }}
            </p>
            <Button as-child variant="outline">
                <Link :href="sitesIndex()">{{ $t('Go to the sites') }}</Link>
            </Button>
        </div>

        <template v-else>
            <form
                class="flex flex-wrap items-end gap-4"
                data-test="device-filters"
                @submit.prevent="applyFilters"
            >
                <div class="grid w-full gap-2 sm:w-56">
                    <Label for="filter-site">{{ $t('Site') }}</Label>
                    <NativeSelect
                        id="filter-site"
                        v-model="filters.site"
                        name="site"
                        class="h-9.5"
                        @change="applyFilters"
                    >
                        <option value="">{{ $t('All sites') }}</option>
                        <option
                            v-for="site in sites"
                            :key="site.id"
                            :value="String(site.id)"
                        >
                            {{ site.name }}
                        </option>
                    </NativeSelect>
                </div>

                <div class="grid w-full gap-2 sm:w-48">
                    <Label for="filter-type">{{ $t('Type') }}</Label>
                    <NativeSelect
                        id="filter-type"
                        v-model="filters.type"
                        name="type"
                        class="h-9.5"
                        @change="applyFilters"
                    >
                        <option value="">{{ $t('All types') }}</option>
                        <option
                            v-for="type in types"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </option>
                    </NativeSelect>
                </div>

                <div class="grid w-full gap-2 sm:w-48">
                    <Label for="filter-status">{{ $t('Status') }}</Label>
                    <NativeSelect
                        id="filter-status"
                        v-model="filters.status"
                        name="status"
                        class="h-9.5"
                        @change="applyFilters"
                    >
                        <option value="">{{ $t('All statuses') }}</option>
                        <option
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </NativeSelect>
                </div>

                <div class="grid w-full gap-2 sm:w-72">
                    <Label for="filter-search">{{ $t('Search') }}</Label>
                    <div class="flex gap-2">
                        <Input
                            id="filter-search"
                            v-model="filters.search"
                            type="search"
                            name="search"
                            maxlength="100"
                            autocomplete="off"
                            class="h-9.5"
                            :placeholder="$t('Name, model or serial number')"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            class="h-9.5"
                            data-test="search-devices"
                        >
                            {{ $t('Search') }}
                        </Button>
                    </div>
                </div>
            </form>

            <DeviceTable
                :devices="devices"
                :empty-text="
                    hasFilters
                        ? $t('No device matches these filters.')
                        : $t('No device yet.')
                "
            />
        </template>
    </div>
</template>
