<!-- torsade/resources/js/components/AppSidebarHeader.vue -->
<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import SearchButton from '@/components/search/SearchButton.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

// The line above a page: the button that folds the sidebar, or opens it on
// a narrow screen, the path to the page, then the field of the search.
withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <div class="flex min-h-9.5 items-center gap-2">
        <SidebarTrigger class="-ml-2 shrink-0" />
        <div class="min-w-0 flex-1">
            <Breadcrumbs
                v-if="breadcrumbs && breadcrumbs.length > 0"
                :breadcrumbs="breadcrumbs"
            />
        </div>
        <SearchButton />
    </div>
</template>
