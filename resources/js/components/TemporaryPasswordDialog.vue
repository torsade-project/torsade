<!-- torsade/resources/js/components/TemporaryPasswordDialog.vue -->
<script setup lang="ts">
import { TriangleAlert, Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { TemporaryPasswordResponse } from '@/types';

// The password lives only in this component's props, for as long as the
// window is open: it is never stored by the browser or by the server.
const props = defineProps<{
    result: TemporaryPasswordResponse | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const { copy, copied } = useClipboard({ legacy: true });

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="props.result !== null" @update:open="onOpenChange">
        <DialogContent
            v-if="props.result"
            class="sm:max-w-md"
            :show-close-button="false"
            data-test="temporary-password-dialog"
            @interact-outside.prevent
        >
            <DialogHeader>
                <DialogTitle>{{ $t('Temporary password') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Give this password to :name (:email). They will have to choose their own at their next login.',
                            {
                                name: props.result.user.name,
                                email: props.result.user.email,
                            },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex items-stretch overflow-hidden rounded-md border border-input"
            >
                <input
                    type="text"
                    readonly
                    :value="props.result.temporary_password"
                    :aria-label="$t('Temporary password')"
                    class="h-full w-full bg-sunken p-3 font-mono text-sm text-foreground"
                    data-test="temporary-password"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <button
                    type="button"
                    class="flex items-center gap-2 border-l border-input px-3 text-sm hover:bg-accent"
                    data-test="copy-temporary-password"
                    @click="copy(props.result.temporary_password)"
                >
                    <Check v-if="copied" class="w-4 text-pair-green" />
                    <Copy v-else class="w-4" />
                    {{ copied ? $t('Copied') : $t('Copy') }}
                </button>
            </div>

            <Alert variant="destructive">
                <TriangleAlert class="size-4" />
                <AlertTitle>{{
                    $t('This password will not be shown again.')
                }}</AlertTitle>
                <AlertDescription>
                    {{
                        $t(
                            'Copy it now. If it is lost, reset the password again.',
                        )
                    }}
                </AlertDescription>
            </Alert>

            <DialogFooter>
                <Button
                    type="button"
                    data-test="close-temporary-password"
                    @click="emit('close')"
                >
                    {{ $t('I have copied the password') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
