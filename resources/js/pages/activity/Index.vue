<!-- torsade/resources/js/pages/activity/Index.vue -->
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import ActivityTable from '@/components/ActivityTable.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { trans } from '@/lib/i18n';
import { index } from '@/routes/activity-log';
import type { ActivityEntry, ActivityFilters, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Activity log'),
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    entries: Paginated<ActivityEntry>;
    filters: ActivityFilters;
    users: { id: number; name: string; email: string }[];
    events: { value: string; label: string }[];
    errors: Partial<Record<keyof ActivityFilters, string>>;
}>();

const form = reactive<ActivityFilters>({ ...props.filters });

// Read-only page: filtering is a GET request, nothing is ever written.
const applyFilters = (): void => {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== ''),
    );

    router.get(index().url, query, { preserveState: true, replace: true });
};

const resetFilters = (): void => {
    form.user = '';
    form.event = '';
    form.from = '';
    form.to = '';

    applyFilters();
};
</script>

<template>
    <Head :title="$t('Activity log')" />

    <div class="flex flex-col gap-7">
        <Heading
            :title="$t('Activity log')"
            :description="
                $t(
                    'Who changed what and when. Entries cannot be modified or deleted.',
                )
            "
        />

        <form
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"
            data-dense
            data-test="activity-filters"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-2">
                <Label for="filter-user">{{ $t('User') }}</Label>
                <NativeSelect
                    id="filter-user"
                    v-model="form.user"
                    name="user"
                    :aria-invalid="errors.user ? 'true' : undefined"
                >
                    <option value="">{{ $t('All users') }}</option>
                    <option
                        v-for="user in users"
                        :key="user.id"
                        :value="String(user.id)"
                    >
                        {{ user.name }}
                    </option>
                </NativeSelect>
                <InputError :message="errors.user" />
            </div>

            <div class="grid gap-2">
                <Label for="filter-event">{{ $t('Event') }}</Label>
                <NativeSelect
                    id="filter-event"
                    v-model="form.event"
                    name="event"
                    :aria-invalid="errors.event ? 'true' : undefined"
                >
                    <option value="">{{ $t('All events') }}</option>
                    <option
                        v-for="event in events"
                        :key="event.value"
                        :value="event.value"
                    >
                        {{ event.label }}
                    </option>
                </NativeSelect>
                <InputError :message="errors.event" />
            </div>

            <div class="grid gap-2">
                <Label for="filter-from">{{ $t('From') }}</Label>
                <Input
                    id="filter-from"
                    v-model="form.from"
                    type="date"
                    name="from"
                    :aria-invalid="errors.from ? 'true' : undefined"
                />
                <InputError :message="errors.from" />
            </div>

            <div class="grid gap-2">
                <Label for="filter-to">{{ $t('To') }}</Label>
                <Input
                    id="filter-to"
                    v-model="form.to"
                    type="date"
                    name="to"
                    :aria-invalid="errors.to ? 'true' : undefined"
                />
                <InputError :message="errors.to" />
            </div>

            <div class="flex items-end gap-2">
                <Button type="submit" size="sm" data-test="apply-filters">{{
                    $t('Filter')
                }}</Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="resetFilters"
                >
                    {{ $t('Reset') }}
                </Button>
            </div>
        </form>

        <ActivityTable
            :entries="entries.data"
            :empty-text="$t('No entry matches these filters.')"
        />

        <Pagination
            :current-page="entries.current_page"
            :last-page="entries.last_page"
            :total="entries.total"
            :previous-url="entries.prev_page_url"
            :next-url="entries.next_page_url"
        />
    </div>
</template>
