<!-- torsade/resources/js/layouts/settings/Layout.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { trans } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: trans('Profile'),
        href: editProfile(),
    },
    {
        title: trans('Security'),
        href: editSecurity(),
    },
    {
        title: trans('Appearance'),
        href: editAppearance(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="flex flex-col gap-7">
        <Heading
            :title="$t('Settings')"
            :description="$t('Manage your profile and account settings')"
        />

        <div class="flex flex-col gap-7 lg:flex-row lg:gap-10">
            <nav
                class="flex shrink-0 flex-row flex-wrap gap-0.5 lg:w-48 lg:flex-col"
                :aria-label="$t('Settings')"
            >
                <Link
                    v-for="item in sidebarNavItems"
                    :key="toUrl(item.href)"
                    :href="item.href"
                    :aria-current="
                        isCurrentOrParentUrl(item.href) ? 'page' : undefined
                    "
                    class="flex min-h-10 items-center rounded-md px-3 transition-colors hover:bg-accent"
                    :class="
                        isCurrentOrParentUrl(item.href)
                            ? 'bg-accent font-semibold text-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                >
                    {{ item.title }}
                </Link>
            </nav>

            <div class="flex max-w-2xl min-w-0 flex-1 flex-col gap-10">
                <slot />
            </div>
        </div>
    </div>
</template>
