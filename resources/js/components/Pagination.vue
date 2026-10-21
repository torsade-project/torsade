<!-- torsade/resources/js/components/Pagination.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { formatCount } from '@/lib/numbers';

defineProps<{
    currentPage: number;
    lastPage: number;
    total: number;
    previousUrl: string | null;
    nextUrl: string | null;
}>();
</script>

<template>
    <nav
        class="flex flex-wrap items-center justify-between gap-4 text-sm text-muted-foreground"
        :aria-label="$t('Pagination')"
    >
        <p>
            {{
                $tc(
                    'Page :current of :last (:total entry)|Page :current of :last (:total entries)',
                    total,
                    {
                        current: currentPage,
                        last: lastPage,
                        total: formatCount(total),
                    },
                )
            }}
        </p>

        <div class="flex gap-2">
            <Button
                v-if="previousUrl"
                variant="outline"
                size="sm"
                as-child
                data-test="previous-page"
            >
                <Link :href="previousUrl" preserve-scroll>{{
                    $t('Previous')
                }}</Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>{{
                $t('Previous')
            }}</Button>

            <Button
                v-if="nextUrl"
                variant="outline"
                size="sm"
                as-child
                data-test="next-page"
            >
                <Link :href="nextUrl" preserve-scroll>{{ $t('Next') }}</Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>{{
                $t('Next')
            }}</Button>
        </div>
    </nav>
</template>
