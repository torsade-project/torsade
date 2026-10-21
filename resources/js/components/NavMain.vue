<!-- torsade/resources/js/components/NavMain.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { useId } from 'vue';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

// A group of entries of the menu. With a title, the group folds: its title
// is a button that opens and closes it, and its entries sit under it. The
// menu reduced to its icons shows every entry flat, whatever is open, and
// drops the titles: the order of the entries tells enough.
defineProps<{
    items: NavItem[];
    title?: string;
    open?: boolean;
}>();

const emit = defineEmits<{ toggle: [] }>();

const titleId = useId();
const listId = useId();

// An entry stays highlighted on the pages below it: the page of a subnet
// belongs to "Subnets".
const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="grid gap-0.5" data-test="nav-group">
        <SidebarMenuButton
            v-if="title"
            :id="titleId"
            type="button"
            size="sm"
            class="font-medium text-muted-foreground group-data-[collapsible=icon]:hidden"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="listId"
            data-test="nav-group-title"
            @click="emit('toggle')"
        >
            <span class="flex-1">{{ title }}</span>
            <ChevronRight
                class="transition-transform duration-150"
                :class="{ 'rotate-90': open }"
                aria-hidden="true"
            />
        </SidebarMenuButton>
        <SidebarMenu
            :id="title ? listId : undefined"
            class="gap-0.5"
            :class="
                title
                    ? [
                          'ml-2.5 w-auto border-l border-sidebar-border pl-1.5 group-data-[collapsible=icon]:ml-0 group-data-[collapsible=icon]:flex group-data-[collapsible=icon]:w-full group-data-[collapsible=icon]:border-l-0 group-data-[collapsible=icon]:pl-0',
                          { hidden: !open },
                      ]
                    : undefined
            "
            :aria-labelledby="title ? titleId : undefined"
            :data-test="title ? 'nav-group-entries' : undefined"
        >
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentOrParentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link
                        :href="item.href"
                        :aria-current="
                            isCurrentOrParentUrl(item.href) ? 'page' : undefined
                        "
                    >
                        <component :is="item.icon" aria-hidden="true" />
                        <!-- Reduced to its icon, the entry keeps its name for
                             a screen reader. -->
                        <span
                            class="flex-1 truncate group-data-[collapsible=icon]:sr-only"
                            data-test="nav-entry-label"
                            >{{ item.title }}</span
                        >
                        <!-- The light of the current entry, like the one of a
                             port in use. -->
                        <span
                            v-if="isCurrentOrParentUrl(item.href)"
                            class="size-1.75 shrink-0 rounded-full bg-pair-green group-data-[collapsible=icon]:hidden"
                            aria-hidden="true"
                        />
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </div>
</template>
