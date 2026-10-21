<!-- torsade/resources/js/components/home/GettingStartedModule.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import HomePanel from '@/components/home/HomePanel.vue';
import type { GettingStartedStep } from '@/types';

// The first steps of a documentation, numbered, each with the way to its
// page; a step done says so with a tick and in words, never by its colour
// alone. A reader is only told who creates the first site.
defineProps<{
    steps: GettingStartedStep[] | null;
}>();
</script>

<template>
    <HomePanel :title="$t('Getting started')" test-id="getting-started">
        <p v-if="steps === null" class="text-muted-foreground">
            {{
                $t(
                    'Nothing has been documented yet. An editor or an administrator has to create the first site.',
                )
            }}
        </p>

        <ol v-else class="flex flex-col gap-3">
            <li
                v-for="(step, position) in steps"
                :key="step.key"
                class="flex items-start gap-3"
                :data-test="`step-${step.key}`"
                :data-done="step.done"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-full border font-mono text-note tabular-nums"
                    :class="
                        step.done
                            ? 'border-pair-green text-pair-green'
                            : 'border-border-strong text-muted-foreground'
                    "
                    aria-hidden="true"
                >
                    <Check v-if="step.done" class="size-4" />
                    <template v-else>{{ position + 1 }}</template>
                </span>
                <div class="flex min-w-0 flex-col gap-0.5">
                    <p class="flex flex-wrap items-baseline gap-x-2.5">
                        <span
                            class="font-medium"
                            :class="{ 'text-muted-foreground': step.done }"
                            >{{ step.title }}</span
                        >
                        <span
                            v-if="step.optional"
                            class="text-note text-muted-foreground"
                            >{{ $t('optional step') }}</span
                        >
                        <span
                            v-if="step.done"
                            class="text-note font-medium text-pair-green"
                            >{{ $t('Done') }}</span
                        >
                    </p>
                    <p class="flex flex-wrap gap-x-4 gap-y-0.5">
                        <Link
                            v-for="link in step.links"
                            :key="link.url"
                            :href="link.url"
                            class="link text-note"
                            >{{ link.label }}</Link
                        >
                    </p>
                </div>
            </li>
        </ol>
    </HomePanel>
</template>
