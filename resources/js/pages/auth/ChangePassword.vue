<!-- torsade/resources/js/pages/auth/ChangePassword.vue -->
<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { logout } from '@/routes';

defineOptions({
    layout: {
        title: trans('Choose your password'),
        description: trans(
            'Your password is temporary. Choose a new one of at least 12 characters to continue.',
        ),
    },
});

defineProps<{
    passwordRules: string;
}>();
</script>

<template>
    <Head :title="$t('Choose your password')" />

    <div class="flex flex-col gap-5">
        <Form
            v-bind="SecurityController.update.form()"
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="flex flex-col gap-5"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">{{
                    $t('Temporary password')
                }}</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    required
                    autofocus
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
                    required
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
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
                    required
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                    :aria-invalid="
                        errors.password_confirmation ? 'true' : undefined
                    "
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                size="lg"
                class="w-full"
                :disabled="processing"
                data-test="change-password-button"
            >
                <Spinner v-if="processing" />
                {{ $t('Save and continue') }}
            </Button>
        </Form>

        <!-- Outside the form: signing out must not submit it. -->
        <div class="text-center text-sm text-muted-foreground">
            <Link
                :href="logout()"
                as="button"
                class="link underline"
                data-test="logout-button"
            >
                {{ $t('Log out') }}
            </Link>
        </div>
    </div>
</template>
