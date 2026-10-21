<!-- torsade/resources/js/components/home/SitesModule.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import HomePanel from '@/components/home/HomePanel.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import { formatCount } from '@/lib/numbers';
import type { HomeSite } from '@/types';

// The sites in service or planned, with their status, what each holds and
// the way to its page and its two diagrams; past the limit, the way to
// every site.
defineProps<{
    sites: HomeSite[];
    total: number;
    url: string;
}>();
</script>

<template>
    <HomePanel :title="$t('Sites')" test-id="sites">
        <template v-if="total > sites.length" #action>
            <Link :href="url" class="link text-note">{{
                $tc('See the :count site|See the :count sites', total)
            }}</Link>
        </template>

        <p v-if="sites.length === 0" class="text-muted-foreground">
            {{ $t('No site documented.') }}
        </p>

        <ul v-else class="flex flex-col divide-y">
            <li
                v-for="site in sites"
                :key="site.id"
                class="flex flex-col gap-1 py-2.5 first:pt-0 last:pb-0"
                data-test="home-site"
            >
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <Link :href="site.url" class="link font-medium">{{
                        site.name
                    }}</Link>
                    <span
                        v-if="site.code"
                        class="font-mono text-note text-muted-foreground"
                        >{{ site.code }}</span
                    >
                    <StatusBadge
                        class="text-note"
                        :status="site.status"
                        :label="site.status_label"
                    />
                </div>
                <dl
                    class="flex flex-wrap gap-x-4 gap-y-0.5 text-note text-muted-foreground"
                >
                    <div class="flex gap-1.5">
                        <dt>{{ $t('VLANs') }}</dt>
                        <dd class="tabular-nums" data-test="site-vlans">
                            {{ formatCount(site.vlans_count) }}
                        </dd>
                    </div>
                    <div class="flex gap-1.5">
                        <dt>{{ $t('Subnets') }}</dt>
                        <dd class="tabular-nums" data-test="site-subnets">
                            {{ formatCount(site.subnets_count) }}
                        </dd>
                    </div>
                    <div class="flex gap-1.5">
                        <dt>{{ $t('Devices') }}</dt>
                        <dd class="tabular-nums" data-test="site-devices">
                            {{ formatCount(site.devices_count) }}
                        </dd>
                    </div>
                </dl>
                <p class="flex flex-wrap gap-x-4 gap-y-0.5 text-note">
                    <Link :href="site.physical_diagram_url" class="link">{{
                        $t('Physical diagram')
                    }}</Link>
                    <Link :href="site.logical_diagram_url" class="link">{{
                        $t('Logical diagram')
                    }}</Link>
                </p>
            </li>
        </ul>
    </HomePanel>
</template>
