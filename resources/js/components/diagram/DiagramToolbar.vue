<!-- torsade/resources/js/components/diagram/DiagramToolbar.vue -->
<script setup lang="ts">
import { Download, Move, Printer } from '@lucide/vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

// Above a diagram of a site: the site it shows, what the diagram itself
// offers, then placing its nodes by hand, saving it as a file, printing it.
defineProps<{
    sites: { id: number; name: string }[];
    // Whether the layout may be placed by hand from here.
    canEdit: boolean;
    // Whether the diagram is drawn, for what acts on the drawing.
    ready: boolean;
}>();

const siteId = defineModel<string>('siteId', { required: true });

const emit = defineEmits<{
    chooseSite: [];
    edit: [];
    save: [];
    print: [];
}>();
</script>

<template>
    <div
        class="flex flex-wrap items-end gap-x-5 gap-y-3 print:hidden"
        data-test="diagram-toolbar"
    >
        <div class="grid w-full gap-2 sm:w-64">
            <Label for="diagram-site">{{ $t('Site') }}</Label>
            <NativeSelect
                id="diagram-site"
                v-model="siteId"
                name="site"
                @change="emit('chooseSite')"
            >
                <option
                    v-for="option in sites"
                    :key="option.id"
                    :value="String(option.id)"
                >
                    {{ option.name }}
                </option>
            </NativeSelect>
        </div>
        <slot />
        <div class="flex flex-wrap gap-2.5">
            <Button
                v-if="canEdit"
                type="button"
                variant="outline"
                :disabled="!ready"
                data-test="edit-layout"
                @click="emit('edit')"
            >
                <Move aria-hidden="true" />
                {{ $t('Edit the layout') }}
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="!ready"
                data-test="save-diagram"
                @click="emit('save')"
            >
                <Download aria-hidden="true" />
                {{ $t('Download as SVG') }}
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="!ready"
                data-test="print-diagram"
                @click="emit('print')"
            >
                <Printer aria-hidden="true" />
                {{ $t('Print') }}
            </Button>
        </div>
    </div>
</template>
