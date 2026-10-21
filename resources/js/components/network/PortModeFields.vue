<!-- torsade/resources/js/components/network/PortModeFields.vue -->
<script setup lang="ts">
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import VlanOptions from '@/components/network/VlanOptions.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { PortModeValue, VlanChoice, VlanGroup } from '@/types';

// The mode of a port, or of an aggregate of ports, and the VLANs carried in
// that mode. In access mode, one VLAN, and a voice VLAN behind a box to
// tick. In trunk mode, a native VLAN at most and the VLANs carried tagged.
// The server enforces the same rules.
//
// A port carries VLANs of any site: those of the site of the device come
// first, then those of the other sites, each under its name. The VLANs of
// another site are folded away, unless the port already carries one of
// them.
//
// The fields start from the values they are given: a window shows them
// anew each time it opens.
const props = withDefaults(
    defineProps<{
        // Starts the identifiers of the fields: unique in the page.
        idPrefix: string;
        // The VLANs of the site of the device, then those of the other
        // sites.
        vlans: VlanGroup[];
        errors: {
            mode?: string;
            untagged_vlan_id?: string;
            tagged_vlan_ids?: string;
        };
        // Whether "no mode" is offered.
        allowNone?: boolean;
    }>(),
    {
        allowNone: true,
    },
);

const mode = defineModel<PortModeValue | null>('mode', { required: true });
const untagged = defineModel<number | null>('untagged', { required: true });
const tagged = defineModel<number[]>('tagged', { required: true });

// Whether the access port also carries a voice VLAN.
const voice = ref<boolean>(mode.value === 'access' && tagged.value.length > 0);

// The select holds a text: "" stands for no mode.
const modeChoice = computed<string>({
    get: () => mode.value ?? '',
    set: (value) => {
        mode.value = value === '' ? null : (value as PortModeValue);
        // What was carried tagged belongs to the mode that is left.
        tagged.value = [];
        voice.value = false;

        if (mode.value === null) {
            untagged.value = null;
        }
    },
});

const onVoiceChange = (): void => {
    if (!voice.value) {
        tagged.value = [];
    }
};

const voiceVlan = computed<number | null>({
    get: () => tagged.value[0] ?? null,
    set: (value) => {
        tagged.value = value === null ? [] : [value];
    },
});

// The site of the device, whose VLANs come first.
const own = computed<VlanGroup>(
    () => props.vlans[0] ?? { site: { id: 0, name: '' }, vlans: [] },
);

// A VLAN is carried once: the untagged one is not offered as tagged.
const others = computed<VlanGroup[]>(() =>
    props.vlans.map((group) => ({
        site: group.site,
        vlans: group.vlans.filter((vlan) => vlan.id !== untagged.value),
    })),
);

const ownOthers = computed<VlanChoice[]>(() => others.value[0]?.vlans ?? []);

// The groups of the other sites, among the tagged VLANs.
const foreign = computed<VlanGroup[]>(() =>
    others.value.slice(1).filter((group) => group.vlans.length > 0),
);

// The sites whose group is open: at first, those of a VLAN already
// carried.
const carried = new Set<number>([
    ...(untagged.value === null ? [] : [untagged.value]),
    ...tagged.value,
]);

const open = ref<Set<number>>(
    new Set(
        props.vlans
            .slice(1)
            .filter((group) => group.vlans.some((vlan) => carried.has(vlan.id)))
            .map((group) => group.site.id),
    ),
);

const toggleSite = (siteId: number): void => {
    const next = new Set(open.value);

    if (next.has(siteId)) {
        next.delete(siteId);
    } else {
        next.add(siteId);
    }

    open.value = next;
};

const onUntaggedChange = (): void => {
    tagged.value = tagged.value.filter((id) => id !== untagged.value);
};

const toggleTagged = (id: number): void => {
    tagged.value = tagged.value.includes(id)
        ? tagged.value.filter((one) => one !== id)
        : [...tagged.value, id];
};

// Every VLAN of one site, added to those already ticked.
const tagAll = (vlans: VlanChoice[]): void => {
    tagged.value = [
        ...tagged.value,
        ...vlans
            .map((vlan) => vlan.id)
            .filter((id) => !tagged.value.includes(id)),
    ];
};

const ticked = (group: VlanGroup): number =>
    group.vlans.filter((vlan) => tagged.value.includes(vlan.id)).length;

const label = (vlan: VlanChoice): string => `${vlan.vid} — ${vlan.name}`;
</script>

<template>
    <div class="grid gap-5" data-test="port-mode-fields">
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-mode`">{{ $t('Mode') }}</Label>
            <NativeSelect
                :id="`${idPrefix}-mode`"
                v-model="modeChoice"
                name="mode"
                class="sm:max-w-56"
                :aria-describedby="`${idPrefix}-mode-help`"
                :aria-invalid="errors.mode ? 'true' : undefined"
            >
                <option v-if="allowNone" value="">{{ $t('None') }}</option>
                <option value="access">{{ $t('Access') }}</option>
                <option value="trunk">{{ $t('Trunk') }}</option>
            </NativeSelect>
            <InputError :message="errors.mode" />
            <p
                :id="`${idPrefix}-mode-help`"
                class="text-sm text-muted-foreground"
            >
                <template v-if="mode === 'access'">{{
                    $t(
                        'One VLAN, untagged, and at most a voice VLAN, tagged, for a telephone with a workstation behind it.',
                    )
                }}</template>
                <template v-else-if="mode === 'trunk'">{{
                    $t(
                        'Tagged VLANs and, at most, one untagged VLAN: the native VLAN.',
                    )
                }}</template>
                <template v-else>{{
                    $t('A port that is not configured, or routed: no VLAN.')
                }}</template>
            </p>
        </div>

        <p
            v-if="mode !== null && own.vlans.length === 0"
            class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
        >
            {{ $t('The site of the device has no VLAN yet.') }}
        </p>

        <template v-if="mode === 'access'">
            <div class="grid gap-2">
                <Label :for="`${idPrefix}-untagged`">{{ $t('VLAN') }}</Label>
                <NativeSelect
                    :id="`${idPrefix}-untagged`"
                    v-model="untagged"
                    name="untagged_vlan_id"
                    required
                    :aria-invalid="errors.untagged_vlan_id ? 'true' : undefined"
                    @change="onUntaggedChange"
                >
                    <option :value="null" disabled>
                        {{ $t('Choose a VLAN') }}
                    </option>
                    <VlanOptions :groups="vlans" />
                </NativeSelect>
                <InputError :message="errors.untagged_vlan_id" />
            </div>

            <div class="grid gap-2">
                <label class="flex items-center gap-2.5">
                    <input
                        v-model="voice"
                        type="checkbox"
                        class="size-4.5 shrink-0 rounded-xs accent-primary"
                        :data-test="`${idPrefix}-voice`"
                        @change="onVoiceChange"
                    />
                    <span>{{ $t('Voice VLAN') }}</span>
                </label>
                <NativeSelect
                    v-if="voice"
                    :id="`${idPrefix}-voice-vlan`"
                    v-model="voiceVlan"
                    name="voice_vlan_id"
                    required
                    :aria-label="$t('Voice VLAN')"
                    :aria-invalid="errors.tagged_vlan_ids ? 'true' : undefined"
                >
                    <option :value="null" disabled>
                        {{ $t('Choose a VLAN') }}
                    </option>
                    <VlanOptions :groups="others" />
                </NativeSelect>
                <InputError :message="errors.tagged_vlan_ids" />
            </div>
        </template>

        <template v-if="mode === 'trunk'">
            <div class="grid gap-2">
                <Label :for="`${idPrefix}-untagged`">{{
                    $t('Native VLAN')
                }}</Label>
                <NativeSelect
                    :id="`${idPrefix}-untagged`"
                    v-model="untagged"
                    name="untagged_vlan_id"
                    :aria-invalid="errors.untagged_vlan_id ? 'true' : undefined"
                    @change="onUntaggedChange"
                >
                    <option :value="null">{{ $t('None') }}</option>
                    <VlanOptions :groups="vlans" />
                </NativeSelect>
                <InputError :message="errors.untagged_vlan_id" />
            </div>

            <div
                class="grid gap-2"
                role="group"
                :aria-labelledby="`${idPrefix}-tagged-label`"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p
                        :id="`${idPrefix}-tagged-label`"
                        class="text-sm font-medium"
                    >
                        {{ $t('Tagged VLANs') }}
                        <span class="font-normal text-muted-foreground">{{
                            tagged.length
                        }}</span>
                    </p>
                    <div class="flex gap-1.5">
                        <!-- Every VLAN of the site of the device: those of
                             another site are ticked within their group. -->
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="ownOthers.length === 0"
                            :data-test="`${idPrefix}-tag-all`"
                            @click="tagAll(ownOthers)"
                        >
                            {{ $t('All of them') }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="tagged.length === 0"
                            @click="tagged = []"
                        >
                            {{ $t('None of them') }}
                        </Button>
                    </div>
                </div>
                <div
                    class="grid max-h-60 gap-3 overflow-y-auto rounded-md border p-3"
                    :aria-invalid="errors.tagged_vlan_ids ? 'true' : undefined"
                >
                    <div class="grid gap-2" :data-test="`${idPrefix}-own-site`">
                        <p
                            v-if="foreign.length > 0"
                            class="text-sm font-medium text-muted-foreground"
                        >
                            {{ own.site.name }}
                        </p>
                        <div class="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                            <label
                                v-for="vlan in ownOthers"
                                :key="vlan.id"
                                class="flex min-w-0 items-center gap-2.5"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4.5 shrink-0 rounded-xs accent-primary"
                                    :checked="tagged.includes(vlan.id)"
                                    @change="toggleTagged(vlan.id)"
                                />
                                <span class="truncate">{{ label(vlan) }}</span>
                            </label>
                            <p
                                v-if="ownOthers.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                {{ $t('No other VLAN.') }}
                            </p>
                        </div>
                    </div>

                    <!-- The VLANs of the other sites, one group each. -->
                    <div
                        v-for="group in foreign"
                        :key="group.site.id"
                        class="grid gap-2 border-t pt-2"
                        :data-test="`${idPrefix}-site-${group.site.id}`"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <button
                                type="button"
                                class="-ml-1 inline-flex min-w-0 items-center gap-1.5 rounded-sm px-1 text-sm font-medium"
                                :aria-expanded="open.has(group.site.id)"
                                :aria-controls="`${idPrefix}-site-${group.site.id}-vlans`"
                                @click="toggleSite(group.site.id)"
                            >
                                <component
                                    :is="
                                        open.has(group.site.id)
                                            ? ChevronDown
                                            : ChevronRight
                                    "
                                    class="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{
                                    group.site.name
                                }}</span>
                                <span
                                    v-if="ticked(group) > 0"
                                    class="font-normal text-muted-foreground"
                                    >{{
                                        $tc(
                                            ':count VLAN ticked|:count VLANs ticked',
                                            ticked(group),
                                        )
                                    }}</span
                                >
                            </button>
                            <Button
                                v-if="open.has(group.site.id)"
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="tagAll(group.vlans)"
                            >
                                {{ $t('All of them') }}
                            </Button>
                        </div>
                        <div
                            v-show="open.has(group.site.id)"
                            :id="`${idPrefix}-site-${group.site.id}-vlans`"
                            class="grid gap-x-4 gap-y-2 sm:grid-cols-2"
                        >
                            <label
                                v-for="vlan in group.vlans"
                                :key="vlan.id"
                                class="flex min-w-0 items-center gap-2.5"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4.5 shrink-0 rounded-xs accent-primary"
                                    :checked="tagged.includes(vlan.id)"
                                    @change="toggleTagged(vlan.id)"
                                />
                                <span class="truncate">{{ label(vlan) }}</span>
                            </label>
                        </div>
                    </div>
                </div>
                <InputError :message="errors.tagged_vlan_ids" />
            </div>
        </template>
    </div>
</template>
