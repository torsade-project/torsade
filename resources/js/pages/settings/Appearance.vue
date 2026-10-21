<!-- torsade/resources/js/pages/settings/Appearance.vue -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { trans } from '@/lib/i18n';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Appearance settings'),
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="$t('Appearance settings')" />

    <section class="flex flex-col gap-5">
        <Heading
            variant="small"
            :title="$t('Appearance settings')"
            :description="$t('Choose the light or dark theme of the interface')"
        />
        <AppearanceTabs />
    </section>
</template>
