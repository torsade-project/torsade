<!-- torsade/resources/js/components/ActivityTable.vue -->
<script setup lang="ts">
import { formatDateTime } from '@/lib/dates';
import type { ActivityEntry } from '@/types';

// Entries of the activity log, the newest first: when, what, who, on
// what, the values before and after, and from which address. The page of
// the log and the home page show them the same way.
defineProps<{
    entries: ActivityEntry[];
    emptyText: string;
}>();
</script>

<template>
    <div class="table-frame">
        <table class="table-dense" :aria-label="$t('Activity log')">
            <thead>
                <tr>
                    <th scope="col">{{ $t('Date') }}</th>
                    <th scope="col">{{ $t('Event') }}</th>
                    <th scope="col">{{ $t('Author') }}</th>
                    <th scope="col">{{ $t('Object concerned') }}</th>
                    <th scope="col">{{ $t('Changes') }}</th>
                    <th scope="col">{{ $t('IP address') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="entries.length === 0">
                    <td
                        colspan="6"
                        class="py-8 text-center text-muted-foreground"
                    >
                        {{ emptyText }}
                    </td>
                </tr>
                <tr
                    v-for="entry in entries"
                    :key="entry.id"
                    class="align-top"
                    :data-test="`activity-row-${entry.id}`"
                >
                    <td class="whitespace-nowrap">
                        {{ formatDateTime(entry.created_at) }}
                    </td>
                    <td>
                        <div class="font-medium">
                            {{ entry.event_label }}
                        </div>
                        <div
                            v-for="(detail, position) in entry.details"
                            :key="position"
                            class="text-note text-muted-foreground"
                        >
                            {{ detail }}
                        </div>
                    </td>
                    <td>
                        <template v-if="entry.author.type === 'user'">
                            <div>{{ entry.author.name }}</div>
                            <div class="text-note text-muted-foreground">
                                {{ entry.author.email }}
                            </div>
                        </template>
                        <template v-else-if="entry.author.type === 'console'">
                            {{ $t('Console') }}
                        </template>
                        <span v-else class="text-muted-foreground">{{
                            $t('Not signed in')
                        }}</span>
                    </td>
                    <td>
                        <template v-if="entry.subject">
                            <div>{{ entry.subject.title }}</div>
                            <div
                                v-if="entry.subject.detail"
                                class="text-note text-muted-foreground"
                            >
                                {{ entry.subject.detail }}
                            </div>
                        </template>
                    </td>
                    <td>
                        <ul v-if="entry.changes.length" class="space-y-1">
                            <li
                                v-for="change in entry.changes"
                                :key="change.field"
                            >
                                <span class="text-muted-foreground"
                                    >{{ change.field }}{{ $t(':') }}</span
                                >
                                <span
                                    v-if="change.old !== null"
                                    class="line-through"
                                    >{{ change.old }}</span
                                >
                                <span
                                    v-if="
                                        change.old !== null &&
                                        change.new !== null
                                    "
                                    aria-hidden="true"
                                >
                                    →
                                </span>
                                <span v-if="change.new !== null">{{
                                    change.new
                                }}</span>
                            </li>
                        </ul>
                    </td>
                    <td class="font-mono text-note whitespace-nowrap">
                        {{ entry.ip }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
