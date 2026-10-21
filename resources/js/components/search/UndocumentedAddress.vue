<!-- torsade/resources/js/components/search/UndocumentedAddress.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { trans } from '@/lib/i18n';
import type { UndocumentedAddress } from '@/types';

// An address that no record holds: the subnet it lies in and, for those
// who may write, the way to document it. Outside of every subnet, the
// sentence says so and nothing is offered.
const props = defineProps<{
    undocumented: UndocumentedAddress;
}>();

const emit = defineEmits<{
    // A link was followed: the window that shows this closes.
    navigate: [];
}>();

// A sentence of the catalogue, translated with a marker in place of the
// value it names, cut around it: the value is written in its own font,
// where the language puts it.
const MARKER = '\u0000';

const around = (sentence: string): [string, string] => {
    const [before = '', after = ''] = sentence.split(MARKER);

    return [before, after];
};

const sentence = computed(() =>
    around(trans(':address is not documented.', { address: MARKER })),
);
const subnetSentence = computed(() =>
    around(trans('It lies in the subnet :subnet.', { subnet: MARKER })),
);
</script>

<template>
    <div
        class="flex flex-col gap-2 rounded-lg border bg-background px-4 py-3"
        data-test="undocumented-address"
    >
        <p class="font-medium">
            {{ sentence[0]
            }}<span class="font-mono">{{ props.undocumented.address }}</span
            >{{ sentence[1] }}
        </p>
        <p v-if="props.undocumented.subnet" class="text-muted-foreground">
            {{ subnetSentence[0]
            }}<Link
                :href="props.undocumented.subnet.url"
                class="link font-mono"
                @click="emit('navigate')"
                >{{ props.undocumented.subnet.cidr }}</Link
            ><template v-if="props.undocumented.subnet.name">
                ({{ props.undocumented.subnet.name }})</template
            >{{ subnetSentence[1] }}
        </p>
        <p v-else class="text-muted-foreground">
            {{ $t('No documented subnet holds it.') }}
        </p>
        <div v-if="props.undocumented.document_url">
            <Button as-child size="sm" variant="outline">
                <Link
                    :href="props.undocumented.document_url"
                    data-test="document-address"
                    @click="emit('navigate')"
                    >{{ $t('Document this address') }}</Link
                >
            </Button>
        </div>
    </div>
</template>
