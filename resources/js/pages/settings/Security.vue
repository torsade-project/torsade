<!-- torsade/resources/js/pages/settings/Security.vue -->
<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { trans } from '@/lib/i18n';
import { edit } from '@/routes/security';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} &
    ManageTwoFactorProps;

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Security settings'),
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="$t('Security settings')" />

    <section class="flex flex-col gap-5">
        <Heading
            variant="small"
            :title="$t('Update password')"
            :description="
                $t(
                    'Use a long, random password of at least 12 characters, different from the current one',
                )
            "
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="panel flex flex-col gap-5 p-5 sm:p-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">{{
                    $t('Current password')
                }}</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    autocomplete="current-password"
                    :aria-invalid="errors.current_password ? 'true' : undefined"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ $t('New password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    :passwordrules="props.passwordRules"
                    :aria-invalid="errors.password ? 'true' : undefined"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">{{
                    $t('Confirm password')
                }}</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    :passwordrules="props.passwordRules"
                    :aria-invalid="
                        errors.password_confirmation ? 'true' : undefined
                    "
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-2.5">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    {{ $t('Save') }}
                </Button>
            </div>
        </Form>
    </section>

    <ManageTwoFactor
        :canManageTwoFactor="canManageTwoFactor"
        :requiresConfirmation="requiresConfirmation"
        :twoFactorEnabled="twoFactorEnabled"
        :twoFactorRequired="twoFactorRequired"
    />
</template>
