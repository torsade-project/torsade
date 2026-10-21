<!-- torsade/resources/js/components/network/CidrInput.vue -->
<script setup lang="ts">
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Option } from '@/types';

// The address of a subnet. It can be typed in full, "192.168.100.0/24", or
// as an address with the prefix length chosen in the list, which also shows
// the mask. The two fields are kept in step.
const network = defineModel<string>('network', { required: true });
const prefixLength = defineModel<number | null>('prefixLength', {
    required: true,
});

defineProps<{
    // Prefix of the element identifiers, unique on the page.
    idPrefix: string;
    prefixes: Option<number>[];
    networkError?: string;
    prefixError?: string;
    // A sentence under the address, in muted text: where a value comes
    // from, or why none is proposed.
    networkHint?: string;
}>();

// A prefix length typed after the address moves the list.
watch(network, (value) => {
    const typed = /\/(\d{1,2})$/.exec(value.trim());

    if (!typed) {
        return;
    }

    const length = Number(typed[1]);

    if (length >= 1 && length <= 32) {
        prefixLength.value = length;
    }
});

// A choice in the list rewrites the prefix length typed after the address:
// the server reads the one in the text. Nothing is touched when the text
// already says the same, which is the case while it is being typed.
watch(prefixLength, (length) => {
    const typed = /\/(\d*)$/.exec(network.value.trim());

    if (length !== null && typed && typed[1] !== String(length)) {
        network.value = network.value.trim().replace(/\/\d*$/, `/${length}`);
    }
});
</script>

<template>
    <div class="grid gap-5 sm:grid-cols-2">
        <div class="grid content-start gap-2">
            <Label :for="`${idPrefix}-network`">{{
                $t('Network address')
            }}</Label>
            <Input
                :id="`${idPrefix}-network`"
                v-model="network"
                name="network"
                required
                autocomplete="off"
                class="font-mono"
                placeholder="192.168.100.0/24"
                :aria-describedby="
                    networkHint ? `${idPrefix}-network-hint` : undefined
                "
                :aria-invalid="networkError ? 'true' : undefined"
            />
            <InputError :message="networkError" />
            <p
                v-if="networkHint"
                :id="`${idPrefix}-network-hint`"
                class="text-sm text-muted-foreground"
                data-test="network-hint"
            >
                {{ networkHint }}
            </p>
        </div>

        <div class="grid content-start gap-2">
            <Label :for="`${idPrefix}-prefix`">{{ $t('Prefix length') }}</Label>
            <NativeSelect
                :id="`${idPrefix}-prefix`"
                v-model="prefixLength"
                name="prefix_length"
                class="font-mono"
                :aria-invalid="prefixError ? 'true' : undefined"
            >
                <option
                    v-for="prefix in prefixes"
                    :key="prefix.value"
                    :value="prefix.value"
                >
                    {{ prefix.label }}
                </option>
            </NativeSelect>
            <InputError :message="prefixError" />
        </div>
    </div>
</template>
