<!-- torsade/resources/js/components/network/Deviation.vue -->
<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { trans } from '@/lib/i18n';
import { show as showDevice } from '@/routes/devices';
import { store as plugCord } from '@/routes/jack-cords';
import type { Deviation } from '@/types';

// Where what is documented differs from the standard of the organisation:
// a warning sign, the word "deviation" and the reason in words, in the
// orange of the pair. Never the colour alone, and never an error: a
// deviation refuses nothing.
withDefaults(
    defineProps<{
        deviations: Deviation[];
        // In a table, the heading of the column already says "deviation":
        // the word is only read out.
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

// What lifts a deviation is offered to whoever may change the network:
// hiding it is only a convenience, the server checks every change.
const page = usePage();
const canAct = computed<boolean>(() => page.props.auth.can.editNetwork);

// The port in front of a link plugged into the jack its own port is
// patched to, at once: the page then tells the cabling of the link.
const plugging = ref<boolean>(false);

const plug = (jack: number, port: number): void => {
    router.post(
        plugCord().url,
        { jack_id: jack, port_id: port },
        {
            preserveScroll: true,
            onStart: () => {
                plugging.value = true;
            },
            onFinish: () => {
                plugging.value = false;
            },
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Something went wrong.'),
                ),
        },
    );
};
</script>

<template>
    <ul
        v-if="deviations.length > 0"
        class="flex flex-col gap-1"
        data-test="deviations"
    >
        <!-- Two deviations of the same kind can be told about one object:
             a link, by each of its two ends. -->
        <li
            v-for="(deviation, index) in deviations"
            :key="`${deviation.kind}-${index}`"
            class="flex items-start gap-1.5 text-sm text-pair-orange"
            :data-deviation="deviation.kind"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
                <span :class="compact ? 'sr-only' : 'font-semibold'">{{
                    $t('Deviation:')
                }}</span>
                {{ deviation.reason }}
                <!-- The aggregate missing in front: the window of a new
                     aggregate of the device in front opens with it. -->
                <Link
                    v-if="
                        canAct &&
                        deviation.action?.type === 'create_opposite_aggregate'
                    "
                    :href="
                        showDevice(deviation.action.device, {
                            query: { opposite_of: deviation.action.aggregate },
                        })
                    "
                    class="link ml-1.5 font-medium whitespace-nowrap"
                    data-test="create-opposite-aggregate"
                    >{{ $t('Create the aggregate in front') }}</Link
                >
                <!-- Two ports cabled to each other without a link: the
                     window of a new link of the first one opens with the
                     second one chosen. -->
                <Link
                    v-else-if="
                        canAct && deviation.action?.type === 'create_link'
                    "
                    :href="
                        showDevice(deviation.action.device, {
                            query: {
                                link_port: deviation.action.port,
                                link_to: deviation.action.opposite_port,
                            },
                        })
                    "
                    class="link ml-1.5 font-medium whitespace-nowrap"
                    data-test="create-cabled-link"
                    >{{ $t('Create the link') }}</Link
                >
                <!-- The port in front of the link, plugged into the jack
                     where nothing is plugged. -->
                <button
                    v-else-if="canAct && deviation.action?.type === 'plug'"
                    type="button"
                    class="link ml-1.5 font-medium whitespace-nowrap"
                    :disabled="plugging"
                    data-test="plug-linked-port"
                    @click="
                        deviation.action?.type === 'plug' &&
                        plug(deviation.action.jack, deviation.action.port)
                    "
                >
                    {{ $t('Plug') }}
                </button>
            </span>
        </li>
    </ul>
</template>
