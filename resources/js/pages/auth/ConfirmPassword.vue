<!-- torsade/resources/js/pages/auth/ConfirmPassword.vue -->
<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: {
        title: trans('Confirm password'),
        description: trans(
            'This is a secure area of the application. Please confirm your password before continuing.',
        ),
    },
});
</script>

<template>
    <Head :title="$t('Confirm password')" />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="flex flex-col gap-5">
            <div class="grid gap-2">
                <Label for="password">{{ $t('Password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    autofocus
                    :aria-invalid="errors.password ? 'true' : undefined"
                />

                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center">
                <Button
                    size="lg"
                    class="w-full"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Confirm password') }}
                </Button>
            </div>
        </div>
    </Form>
</template>
