<!-- torsade/resources/js/pages/Dashboard.vue -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Component } from 'vue';
import Heading from '@/components/Heading.vue';
import FullestSubnetsModule from '@/components/home/FullestSubnetsModule.vue';
import GettingStartedModule from '@/components/home/GettingStartedModule.vue';
import RecentActivityModule from '@/components/home/RecentActivityModule.vue';
import SearchModule from '@/components/home/SearchModule.vue';
import SitesModule from '@/components/home/SitesModule.vue';
import SummaryModule from '@/components/home/SummaryModule.vue';
import { trans } from '@/lib/i18n';
import { dashboard } from '@/routes';
import type { HomeModuleData } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Home'),
                href: dashboard(),
            },
        ],
    },
});

// The home page: the modules the server sent, in their order, each drawn
// by the component of its key. A key this page does not know is left
// aside.
defineProps<{
    modules: HomeModuleData[];
}>();

const components: Record<string, Component> = {
    search: SearchModule,
    'getting-started': GettingStartedModule,
    summary: SummaryModule,
    sites: SitesModule,
    'fullest-subnets': FullestSubnetsModule,
    'recent-activity': RecentActivityModule,
};

// The modules that share a row from 1280 pixels on; the others take the
// whole width.
const HALF_WIDTH = ['sites', 'fullest-subnets'];
</script>

<template>
    <Head :title="$t('Home')" />

    <div class="flex flex-col gap-7">
        <Heading :title="$t('Home')" />

        <div class="grid min-w-0 gap-6 xl:grid-cols-2">
            <template v-for="module in modules" :key="module.key">
                <component
                    :is="components[module.key]"
                    v-if="components[module.key]"
                    v-bind="module.data"
                    class="min-w-0"
                    :class="{
                        'xl:col-span-2': !HALF_WIDTH.includes(module.key),
                    }"
                />
            </template>
        </div>
    </div>
</template>
