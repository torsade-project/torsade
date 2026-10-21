<!-- torsade/resources/js/pages/auth/TwoFactorSetup.vue -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import { Button } from '@/components/ui/button';
import { trans } from '@/lib/i18n';
import { dashboard, logout } from '@/routes';

defineOptions({
    layout: {
        title: trans('Set up two-factor authentication'),
        description: trans(
            'An administrator requires two-factor authentication on your account. Set it up to continue.',
        ),
    },
});

defineProps<{
    twoFactorEnabled: boolean;
    requiresConfirmation: boolean;
}>();
</script>

<template>
    <Head :title="$t('Set up two-factor authentication')" />

    <div class="flex flex-col gap-6">
        <!-- The same component as in the security settings: one setup flow. -->
        <ManageTwoFactor
            can-manage-two-factor
            two-factor-required
            :requires-confirmation="requiresConfirmation"
            :two-factor-enabled="twoFactorEnabled"
        />

        <!-- Once it is active, the recovery codes above must be saved first. -->
        <Button
            v-if="twoFactorEnabled"
            as-child
            class="w-full"
            data-test="two-factor-setup-continue"
        >
            <Link :href="dashboard()">{{
                $t('I have saved my recovery codes')
            }}</Link>
        </Button>

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
