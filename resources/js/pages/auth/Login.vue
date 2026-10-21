<!-- torsade/resources/js/pages/auth/Login.vue -->
<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { store } from '@/routes/login';

defineOptions({
    layout: {
        title: trans('Sign in'),
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="$t('Log in')" />

    <div v-if="status" class="text-sm font-medium text-success" role="status">
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="email">{{ $t('Email address') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email"
                    :placeholder="$t('email@example.com')"
                    :aria-invalid="errors.email ? 'true' : undefined"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ $t('Password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    :placeholder="$t('Password')"
                    :aria-invalid="errors.password ? 'true' : undefined"
                />
                <InputError :message="errors.password" />
            </div>

            <Label
                for="remember"
                class="flex items-center gap-2.5 text-base leading-snug font-normal"
            >
                <Checkbox id="remember" name="remember" :tabindex="3" />
                <span>{{ $t('Stay signed in on this device') }}</span>
            </Label>

            <Button
                type="submit"
                size="lg"
                class="w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                {{ $t('Log in') }}
            </Button>

            <p class="text-sm text-muted-foreground">
                {{
                    $t(
                        'Lost password: an administrator can reset it from the Users page.',
                    )
                }}
            </p>
        </div>
    </Form>
</template>
