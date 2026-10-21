<!-- torsade/resources/js/components/AppSidebar.vue -->
<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Blocks,
    BookMarked,
    Cable,
    EthernetPort,
    House,
    Layers,
    LayoutTemplate,
    MapPin,
    Network,
    Ruler,
    ScrollText,
    Server,
    Users,
    Waypoints,
    Workflow,
} from '@lucide/vue';
import { computed, onUnmounted } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useMenuGroups } from '@/composables/useMenuGroups';
import { trans } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index as activityLog } from '@/routes/activity-log';
import { index as addressBlocks } from '@/routes/address-blocks';
import { show as addressingRule } from '@/routes/addressing-rule';
import { index as catalogVlans } from '@/routes/catalog-vlans';
import { index as deviceTemplates } from '@/routes/device-templates';
import { index as devices } from '@/routes/devices';
import {
    logical as logicalDiagram,
    physical as physicalDiagram,
} from '@/routes/diagrams';
import { index as jacks } from '@/routes/jacks';
import { index as links } from '@/routes/links';
import { index as sites } from '@/routes/sites';
import { index as subnets } from '@/routes/subnets';
import { index as users } from '@/routes/users';
import { index as vlans } from '@/routes/vlans';
import type { NavItem } from '@/types';

const page = usePage();

// On a narrow screen the sidebar opens over the page: once a page is
// reached, it gets out of the way.
const { setOpenMobile } = useSidebar();

onUnmounted(router.on('navigate', () => setOpenMobile(false)));

// Hiding an entry is only a convenience: each page is protected by a gate
// on the server. No entry is shown for features that do not exist yet.

// The menu follows the order in which a network is documented: where (the
// sites), by which conventions (the standard), then what exists (the
// logical network, the physical network). Separating the standard from
// what exists also tells what a deviation is: the gap between the two. A
// group whose entries are all hidden is not shown. Home and the sites stay
// at the top; each other group folds under its title.
const groups = computed<{ key: string; title?: string; items: NavItem[] }[]>(
    () => {
        const can = page.props.auth.can;

        return [
            {
                key: 'start',
                items: [
                    { title: trans('Home'), href: dashboard(), icon: House },
                    { title: trans('Sites'), href: sites(), icon: MapPin },
                ],
            },
            {
                key: 'standard',
                title: trans('Standard'),
                items: [
                    {
                        title: trans('Address blocks'),
                        href: addressBlocks(),
                        icon: Blocks,
                    },
                    {
                        title: trans('Addressing rule'),
                        href: addressingRule(),
                        icon: Ruler,
                    },
                    {
                        title: trans('VLAN catalogue'),
                        href: catalogVlans(),
                        icon: BookMarked,
                    },
                ],
            },
            {
                key: 'logical',
                title: trans('Logical network'),
                items: [
                    { title: trans('VLANs'), href: vlans(), icon: Layers },
                    {
                        title: trans('Subnets'),
                        href: subnets(),
                        icon: Network,
                    },
                    {
                        title: trans('Logical diagram'),
                        href: logicalDiagram(),
                        icon: Waypoints,
                    },
                ],
            },
            {
                key: 'physical',
                title: trans('Physical network'),
                items: [
                    {
                        title: trans('Devices'),
                        href: devices(),
                        icon: Server,
                    },
                    { title: trans('Links'), href: links(), icon: Cable },
                    {
                        title: trans('Physical diagram'),
                        href: physicalDiagram(),
                        icon: Workflow,
                    },
                    {
                        title: trans('Jacks'),
                        href: jacks(),
                        icon: EthernetPort,
                    },
                    {
                        title: trans('Device templates'),
                        href: deviceTemplates(),
                        icon: LayoutTemplate,
                    },
                ],
            },
            {
                key: 'administration',
                title: trans('Administration'),
                items: [
                    ...(can.manageUsers
                        ? [
                              {
                                  title: trans('Users'),
                                  href: users(),
                                  icon: Users,
                              },
                          ]
                        : []),
                    ...(can.viewActivityLog
                        ? [
                              {
                                  title: trans('Activity log'),
                                  href: activityLog(),
                                  icon: ScrollText,
                              },
                          ]
                        : []),
                ],
            },
        ].filter((group) => group.items.length > 0);
    },
);

const { isCurrentOrParentUrl } = useCurrentUrl();

// The group of the page shown, which opens with it.
const currentGroup = computed<string | null>(
    () =>
        groups.value.find(
            (group) =>
                group.title !== undefined &&
                group.items.some((item) => isCurrentOrParentUrl(item.href)),
        )?.key ?? null,
);

const { open, toggle } = useMenuGroups(currentGroup);
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader
            class="px-3.5 pt-5 pb-0 group-data-[collapsible=icon]:px-1"
        >
            <Link
                :href="dashboard()"
                class="flex items-center gap-2.5 rounded-md px-2 py-1 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0"
            >
                <AppLogo />
            </Link>
        </SidebarHeader>

        <!-- The menu scrolls only when it is taller than the window, with a
             thin bar in the colours of the theme. -->
        <SidebarContent
            class="menu-scrollbar overflow-x-hidden overflow-y-auto px-3.5 py-6 group-data-[collapsible=icon]:px-1"
        >
            <nav
                class="flex flex-col gap-2 group-data-[collapsible=icon]:gap-1.5"
                :aria-label="$t('Main navigation')"
            >
                <template v-for="(group, index) in groups" :key="group.key">
                    <!-- Reduced to its icons, the menu shows its entries flat,
                         a line between two groups. -->
                    <div
                        v-if="index > 0"
                        class="mx-2 hidden h-px bg-sidebar-border group-data-[collapsible=icon]:block"
                        aria-hidden="true"
                        data-test="nav-group-separator"
                    />
                    <NavMain
                        :title="group.title"
                        :items="group.items"
                        :open="open[group.key] ?? false"
                        @toggle="toggle(group.key)"
                    />
                </template>
            </nav>
        </SidebarContent>

        <SidebarFooter
            class="px-3.5 pt-0 pb-5 group-data-[collapsible=icon]:px-1"
        >
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
