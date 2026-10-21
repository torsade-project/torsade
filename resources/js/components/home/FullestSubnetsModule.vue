<!-- torsade/resources/js/components/home/FullestSubnetsModule.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import HomePanel from '@/components/home/HomePanel.vue';
import { formatCount, formatShare } from '@/lib/numbers';
import type { FullSubnet } from '@/types';

// The subnets the closest to full. The occupancy is always written in
// figures; its bar only draws it, and is left aside by screen readers.
defineProps<{
    subnets: FullSubnet[];
}>();

const width = (subnet: FullSubnet): string =>
    `${subnet.usable_count > 0 ? Math.max(2, Math.min(100, (subnet.documented_count / subnet.usable_count) * 100)) : 0}%`;
</script>

<template>
    <HomePanel :title="$t('Fullest subnets')" test-id="fullest-subnets">
        <p v-if="subnets.length === 0" class="text-muted-foreground">
            {{ $t('No subnet documented.') }}
        </p>

        <ul v-else class="flex flex-col divide-y">
            <li
                v-for="subnet in subnets"
                :key="subnet.id"
                class="flex flex-col gap-1.5 py-2.5 first:pt-0 last:pb-0"
                data-test="home-subnet"
            >
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <Link :href="subnet.url" class="link font-mono">{{
                        subnet.cidr
                    }}</Link>
                    <span v-if="subnet.name">{{ subnet.name }}</span>
                </div>
                <p
                    v-if="subnet.vlan || subnet.site"
                    class="text-note text-muted-foreground"
                >
                    <template v-if="subnet.vlan">{{
                        $t('VLAN :label', {
                            label: `${subnet.vlan.vid} — ${subnet.vlan.name}`,
                        })
                    }}</template
                    ><template v-if="subnet.vlan && subnet.site"> · </template
                    ><template v-if="subnet.site">{{ subnet.site }}</template>
                </p>
                <div class="flex items-center gap-3">
                    <div
                        class="h-1.5 min-w-16 flex-1 overflow-hidden rounded-full bg-sunken"
                        aria-hidden="true"
                    >
                        <div
                            class="h-full rounded-full bg-pair-blue"
                            :style="{ width: width(subnet) }"
                        />
                    </div>
                    <span
                        class="text-note whitespace-nowrap text-muted-foreground tabular-nums"
                        data-test="home-occupancy"
                        >{{
                            $t(':documented out of :usable (:share)', {
                                documented: formatCount(
                                    subnet.documented_count,
                                ),
                                usable: formatCount(subnet.usable_count),
                                share: formatShare(
                                    subnet.documented_count,
                                    subnet.usable_count,
                                ),
                            })
                        }}</span
                    >
                </div>
            </li>
        </ul>
    </HomePanel>
</template>
