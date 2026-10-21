<!-- torsade/resources/js/components/NavUser.vue -->
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useSidebar } from '@/components/ui/sidebar';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useInitials } from '@/composables/useInitials';
import { trans } from '@/lib/i18n';
import type { Role } from '@/types';

// The block of the signed-in user, at the foot of the sidebar: who is
// connected, with which role, on which version. It opens the menu of the
// account.
const page = usePage();
const user = computed(() => page.props.auth.user);
const { isMobile, state } = useSidebar();
const { getInitials } = useInitials();

const roleLabels: Record<Role, string> = {
    admin: trans('Administrator'),
    editor: trans('Editor'),
    reader: trans('Reader'),
};

// Only signed-in users see this layout, hence the version.
const details = computed<string>(() =>
    page.props.appVersion
        ? trans(':role, version :version', {
              role: roleLabels[user.value.role],
              version: page.props.appVersion,
          })
        : roleLabels[user.value.role],
);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md bg-background p-3 text-left transition-colors group-data-[collapsible=icon]:size-10 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0 hover:bg-sidebar-accent data-[state=open]:bg-sidebar-accent"
                data-test="sidebar-menu-button"
            >
                <span
                    class="hidden text-sm font-semibold text-foreground group-data-[collapsible=icon]:inline"
                    aria-hidden="true"
                    >{{ getInitials(user.name) }}</span
                >
                <span
                    class="flex min-w-0 flex-1 flex-col gap-0.5 group-data-[collapsible=icon]:sr-only"
                >
                    <span class="truncate font-semibold text-foreground">{{
                        user.name
                    }}</span>
                    <span
                        class="truncate text-note text-muted-foreground"
                        data-test="app-version"
                        >{{ details }}</span
                    >
                </span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            class="w-(--reka-dropdown-menu-trigger-width) min-w-56"
            :side="
                isMobile ? 'bottom' : state === 'collapsed' ? 'left' : 'bottom'
            "
            align="end"
            :side-offset="4"
        >
            <UserMenuContent :user="user" />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
