<!-- torsade/resources/js/layouts/app/AppSidebarLayout.vue -->
<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import SearchDialog from '@/components/search/SearchDialog.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <!-- The page never scrolls sideways: what is wider than the screen,
             a table or the address plan, scrolls inside its own frame. Its
             side margins stay narrow enough, beside the sidebar, for the
             widest tables to keep their actions in sight at 1280 pixels. -->
        <AppContent
            variant="sidebar"
            class="min-w-0 gap-7 overflow-x-clip px-4 pt-4 pb-10 sm:px-6 lg:px-8 lg:pt-7 lg:pb-14"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster />
        <!-- One search window for every page, with its shortcuts. -->
        <SearchDialog />
    </AppShell>
</template>
