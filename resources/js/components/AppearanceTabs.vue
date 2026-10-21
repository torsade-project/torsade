<!-- torsade/resources/js/components/AppearanceTabs.vue -->
<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';
import { trans } from '@/lib/i18n';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: trans('Light') },
    { value: 'dark', Icon: Moon, label: trans('Dark') },
    { value: 'system', Icon: Monitor, label: trans('System') },
] as const;
</script>

<template>
    <div
        class="inline-flex gap-1 self-start rounded-md border bg-sunken p-1"
        role="group"
        :aria-label="$t('Appearance')"
    >
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            :aria-pressed="appearance === value"
            :class="[
                'flex h-9 items-center rounded-sm px-3.5 transition-colors',
                appearance === value
                    ? 'bg-card font-semibold text-foreground'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
            ]"
            @click="updateAppearance(value)"
        >
            <component :is="Icon" class="-ml-1 size-4" aria-hidden="true" />
            <span class="ml-1.5 text-sm">{{ label }}</span>
        </button>
    </div>
</template>
