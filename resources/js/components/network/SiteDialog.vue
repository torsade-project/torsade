<!-- torsade/resources/js/components/network/SiteDialog.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    allowsSiteNumber,
    cidr,
    maxSiteNumber,
    siteBlock,
} from '@/lib/addressingRule';
import type { AddressingRuleParams } from '@/lib/addressingRule';
import { store, update } from '@/routes/sites';
import type { Option, Site, StatusValue } from '@/types';

// A short form in a window: a site is created or renamed without leaving
// the list.
const props = defineProps<{
    open: boolean;
    // The site to modify, or null for a new one.
    site: Site | null;
    // Whether the window is opened from the page of the site, which the
    // user then stays on.
    fromPage?: boolean;
    // What the addressing rule works out, or null without a rule: nothing
    // is then said about the number.
    rule: AddressingRuleParams | null;
    statuses: Option[];
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    name: string;
    code: string;
    // A number once typed: the field is a numeric input.
    number: string | number;
    status: StatusValue;
    description: string;
    create_block: boolean;
}>({
    name: '',
    code: '',
    number: '',
    status: 'active',
    description: '',
    create_block: true,
});

// --- What the addressing rule expects for the number being typed -----------

const typedNumber = computed<number | null>(() => {
    const text = String(form.number).trim();

    return /^\d{1,5}$/.test(text) ? Number(text) : null;
});

// The block the rule expects for that number, when it reaches it.
const expectedBlock = computed<string | null>(() => {
    const block =
        props.rule === null ? null : siteBlock(props.rule, typedNumber.value);

    return block === null ? null : cidr(block);
});

// A number the rule does not reach is accepted all the same: the site is
// then shown as a deviation. Without a level of sites, the rule uses no
// number: none is beyond it.
const beyondRule = computed<boolean>(
    () =>
        props.rule !== null &&
        props.rule.site_prefix_length !== null &&
        typedNumber.value !== null &&
        !allowsSiteNumber(props.rule, typedNumber.value),
);

const alreadyDeclared = computed<boolean>(
    () =>
        expectedBlock.value !== null &&
        (props.site?.blocks ?? []).includes(expectedBlock.value),
);

// The block can be declared along with the site, in one action.
const canCreateBlock = computed<boolean>(
    () => expectedBlock.value !== null && !alreadyDeclared.value,
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.name = props.site?.name ?? '';
        form.code = props.site?.code ?? '';
        form.number =
            props.site?.number === null || props.site?.number === undefined
                ? ''
                : String(props.site.number);
        form.status = props.site?.status ?? 'active';
        form.description = props.site?.description ?? '';
        form.create_block = true;
    },
);

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    // The box only counts while it is shown: the server works the block
    // out by itself, from the number it is given.
    form.transform((data) => ({
        ...data,
        number: String(data.number).trim() === '' ? null : data.number,
        create_block: canCreateBlock.value && data.create_block,
        from_page: props.fromPage === true,
    }));

    if (props.site) {
        form.put(update(props.site.id).url, options);
    } else {
        form.post(store().url, options);
    }
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-md" data-test="site-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        site ? $t('Edit the site') : $t('New site')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'A site is a place with its own network. VLAN numbers are unique within a site.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="site-name">{{ $t('Name') }}</Label>
                    <Input
                        id="site-name"
                        v-model="form.name"
                        name="name"
                        required
                        maxlength="100"
                        autocomplete="off"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2 sm:max-w-48">
                    <Label for="site-code">{{ $t('Code') }}</Label>
                    <Input
                        id="site-code"
                        v-model="form.code"
                        name="code"
                        maxlength="10"
                        autocomplete="off"
                        class="font-mono uppercase"
                        aria-describedby="site-code-help"
                        :aria-invalid="form.errors.code ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.code" />
                </div>
                <p
                    id="site-code-help"
                    class="-mt-2 text-sm text-muted-foreground"
                >
                    {{
                        $t(
                            'Optional: letters, digits and hyphens. It names the site in the summary of a port, "Trunk: 10 · ATE 60", and in the name of an exported diagram, "physical-diagram-SIE-2026-10-18.svg".',
                        )
                    }}
                </p>

                <div class="grid gap-2">
                    <Label for="site-number">{{ $t('Number') }}</Label>
                    <Input
                        id="site-number"
                        v-model="form.number"
                        type="number"
                        name="number"
                        min="0"
                        max="65535"
                        step="1"
                        class="font-mono sm:max-w-48"
                        aria-describedby="site-number-help"
                        :aria-invalid="form.errors.number ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.number" />
                    <div
                        id="site-number-help"
                        class="flex flex-col gap-2 text-sm text-muted-foreground"
                    >
                        <p>
                            {{
                                rule
                                    ? $t(
                                          'Optional. The rank of the site in the addressing rule: it gives the block expected for the site.',
                                      )
                                    : $t(
                                          'Optional. The rank of the site in the addressing rule, once there is one.',
                                      )
                            }}
                        </p>

                        <!-- Always there, so that a screen reader is told
                             when the expected block appears or changes. -->
                        <div role="status" data-test="site-expected-block">
                            <p v-if="expectedBlock" class="text-foreground">
                                {{ $t('Block expected by the rule:') }}
                                <span class="font-mono">{{
                                    expectedBlock
                                }}</span>
                                <template v-if="alreadyDeclared">
                                    —
                                    {{ $t('already declared for this site.') }}
                                </template>
                            </p>
                            <p
                                v-else-if="beyondRule && rule"
                                class="flex items-start gap-1.5 text-pair-orange"
                            >
                                <TriangleAlert
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>{{
                                    $t(
                                        'The rule allows the numbers :min to :max: no block is expected for this one, and the site will be shown as a deviation.',
                                        {
                                            min: 0,
                                            max: maxSiteNumber(rule) ?? 0,
                                        },
                                    )
                                }}</span>
                            </p>
                        </div>
                    </div>

                    <div v-if="canCreateBlock" class="grid gap-2">
                        <label class="flex items-center gap-2.5">
                            <input
                                v-model="form.create_block"
                                type="checkbox"
                                name="create_block"
                                class="size-4.5 shrink-0 rounded-xs accent-primary"
                                :aria-invalid="
                                    form.errors.create_block
                                        ? 'true'
                                        : undefined
                                "
                            />
                            <span>{{ $t('Create this block') }}</span>
                        </label>
                        <InputError :message="form.errors.create_block" />
                    </div>
                </div>

                <div class="grid gap-2 sm:max-w-48">
                    <Label for="site-status">{{ $t('Status') }}</Label>
                    <NativeSelect
                        id="site-status"
                        v-model="form.status"
                        name="status"
                        aria-describedby="site-status-help"
                        :aria-invalid="form.errors.status ? 'true' : undefined"
                    >
                        <option
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.status" />
                </div>
                <p
                    id="site-status-help"
                    class="-mt-2 text-sm text-muted-foreground"
                >
                    {{
                        $t(
                            'Planned for a site that does not exist yet, retired for a site that closed. A block created with the site takes its status.',
                        )
                    }}
                </p>

                <div class="grid gap-2">
                    <Label for="site-description">{{
                        $t('Description')
                    }}</Label>
                    <Textarea
                        id="site-description"
                        v-model="form.description"
                        name="description"
                        maxlength="1000"
                        rows="3"
                        :aria-invalid="
                            form.errors.description ? 'true' : undefined
                        "
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('close')"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="save-site"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
