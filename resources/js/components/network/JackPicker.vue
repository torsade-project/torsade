<!-- torsade/resources/js/components/network/JackPicker.vue -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { JackChoices } from '@/types';

// The field "Jack" of the window of an address: the wall jack its machine is
// plugged into, looked for by its label among the jacks the page offers,
// those of the site of the subnet. The list is asked for when the field is
// used, never before.
const props = defineProps<{
    // The id of the field, for its label.
    id: string;
    // The jack chosen, as the window opened with it.
    initial: { id: number; label: string; place: string } | null;
    // Whether the window is open: the field starts again each time it is.
    open: boolean;
    choices?: JackChoices;
    // What the page needs to know which jacks to offer, when its address
    // does not tell it.
    query?: Record<string, number>;
    invalid?: boolean;
}>();

const model = defineModel<number | null>({ required: true });

const chosen = ref<{ id: number; label: string; place: string } | null>(null);
const search = ref<string>('');
const searching = ref<boolean>(false);
// Whether the list was asked for since the window opened.
const asked = ref<boolean>(false);

watch(
    () => props.open,
    (open) => {
        if (open) {
            chosen.value = props.initial;
            search.value = '';
            asked.value = false;
        }
    },
    { immediate: true },
);

// Only the list is asked again, and the address of the page stays what it
// is: what is typed here is not a filter of the page.
const look = (): void => {
    asked.value = true;

    router.reload({
        only: ['jackChoices'],
        data: { jack_search: search.value.trim(), ...props.query },
        preserveUrl: true,
        onStart: () => {
            searching.value = true;
        },
        onFinish: () => {
            searching.value = false;
        },
    });
};

let timer: ReturnType<typeof setTimeout> | undefined;

// The list follows what is typed, once the typing pauses; emptied as the
// window opens, the field asks for nothing.
watch(search, () => {
    if (!asked.value) {
        return;
    }

    clearTimeout(timer);
    timer = setTimeout(look, 250);
});

onUnmounted(() => clearTimeout(timer));

const lookOnce = (): void => {
    if (!asked.value) {
        look();
    }
};

const choose = (jack: { id: number; label: string; place: string }): void => {
    chosen.value = jack;
    model.value = jack.id;
};

const unplug = (): void => {
    chosen.value = null;
    model.value = null;
};
</script>

<template>
    <div class="grid gap-2">
        <div
            v-if="chosen"
            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-md border px-3 py-2"
            data-test="jack-chosen"
        >
            <span class="flex flex-wrap items-baseline gap-x-2">
                <span class="font-mono font-medium">{{ chosen.label }}</span>
                <span class="text-sm text-muted-foreground">{{
                    chosen.place
                }}</span>
            </span>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                data-test="unplug-jack"
                @click="unplug"
            >
                {{ $t('Unplug') }}
            </Button>
        </div>

        <template v-else>
            <Input
                :id="id"
                v-model="search"
                type="search"
                name="jack_search"
                maxlength="32"
                autocomplete="off"
                spellcheck="false"
                :placeholder="$t('Label of the jack')"
                :aria-invalid="invalid ? 'true' : undefined"
                data-test="jack-search"
                @focus="lookOnce"
            />
            <div
                v-if="asked"
                class="max-h-44 overflow-y-auto rounded-md border"
                role="status"
            >
                <p
                    v-if="searching && !choices"
                    class="px-3 py-2 text-sm text-muted-foreground"
                >
                    {{ $t('Looking for jacks…') }}
                </p>
                <p
                    v-else-if="!choices || choices.items.length === 0"
                    class="px-3 py-2 text-sm text-muted-foreground"
                    data-test="no-jack-found"
                >
                    {{ $t('No jack matches this search.') }}
                </p>
                <ul v-else class="flex flex-col">
                    <li v-for="jack in choices.items" :key="jack.id">
                        <button
                            type="button"
                            class="flex w-full flex-wrap items-baseline gap-x-2 px-3 py-1.5 text-left hover:bg-accent focus-visible:bg-accent"
                            data-test="jack-choice"
                            :data-jack="jack.label"
                            @click="choose(jack)"
                        >
                            <span class="font-mono font-medium">{{
                                jack.label
                            }}</span>
                            <span class="text-sm text-muted-foreground"
                                >{{ jack.site }} · {{ jack.place }}</span
                            >
                        </button>
                    </li>
                    <li
                        v-if="choices.more"
                        class="px-3 py-1.5 text-sm text-muted-foreground"
                    >
                        {{ $t('More jacks match: type more of the label.') }}
                    </li>
                </ul>
            </div>
        </template>
    </div>
</template>
