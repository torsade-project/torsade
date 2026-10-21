<!-- torsade/resources/js/layouts/auth/AuthSimpleLayout.vue -->
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import LogoMark from '@/components/LogoMark.vue';
import { home } from '@/routes';

// The pages a visitor sees before the application opens: login, password
// to change, two-factor code. The name and the symbol, then one panel.
defineProps<{
    title?: string;
    description?: string;
}>();

const name = usePage().props.name;
</script>

<template>
    <div
        class="flex min-h-svh items-center justify-center bg-background px-4 py-12"
    >
        <main class="flex w-full max-w-105 flex-col gap-8">
            <div class="flex flex-col items-start gap-3.5">
                <Link
                    :href="home()"
                    class="flex items-center gap-3.5 rounded-lg"
                    data-test="app-name"
                >
                    <span
                        class="flex size-14 shrink-0 items-center justify-center rounded-lg border bg-card"
                    >
                        <LogoMark class="size-9.5 text-foreground" />
                    </span>
                    <span class="flex flex-col gap-0.5">
                        <!-- The name of the application, as plain text. -->
                        <span class="text-brand-lg font-semibold">{{
                            name
                        }}</span>
                        <span class="text-muted-foreground">{{
                            $t('Network documentation')
                        }}</span>
                    </span>
                </Link>
                <!-- The four pairs of a cable. -->
                <div class="flex w-33 gap-1" aria-hidden="true">
                    <span class="h-1 flex-1 rounded-full bg-pair-orange" />
                    <span class="h-1 flex-1 rounded-full bg-pair-green" />
                    <span class="h-1 flex-1 rounded-full bg-pair-blue" />
                    <span class="h-1 flex-1 rounded-full bg-pair-brown" />
                </div>
            </div>

            <div
                class="flex flex-col gap-5 rounded-xl border bg-card p-6 sm:p-8"
            >
                <div class="flex flex-col gap-1.5">
                    <h1 class="text-heading font-semibold">{{ title }}</h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </main>
    </div>
</template>
