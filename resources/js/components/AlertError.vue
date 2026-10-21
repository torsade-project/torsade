<!-- torsade/resources/js/components/AlertError.vue -->
<script setup lang="ts">
import { AlertCircle } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { trans } from '@/lib/i18n';

type Props = {
    errors: string[];
    title?: string;
};

const props = defineProps<Props>();

const uniqueErrors = computed(() => Array.from(new Set(props.errors)));
</script>

<template>
    <Alert variant="destructive">
        <AlertCircle class="size-4" />
        <AlertTitle>{{
            props.title ?? trans('Something went wrong.')
        }}</AlertTitle>
        <AlertDescription>
            <ul class="list-inside list-disc text-sm">
                <li v-for="(error, index) in uniqueErrors" :key="index">
                    {{ error }}
                </li>
            </ul>
        </AlertDescription>
    </Alert>
</template>
