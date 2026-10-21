<!-- torsade/resources/js/components/home/RecentActivityModule.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ActivityTable from '@/components/ActivityTable.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import type { ActivityEntry } from '@/types';

// The latest entries of the activity log, as its page shows them, and the
// way to it.
defineProps<{
    entries: ActivityEntry[];
    url: string;
}>();
</script>

<template>
    <HomePanel :title="$t('Recent activity')" test-id="recent-activity">
        <template #action>
            <Link :href="url" class="link text-note">{{
                $t('Open the activity log')
            }}</Link>
        </template>

        <ActivityTable
            :entries="entries"
            :empty-text="$t('Nothing has been recorded yet.')"
        />
    </HomePanel>
</template>
