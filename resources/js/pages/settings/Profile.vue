<!-- torsade/resources/js/pages/settings/Profile.vue -->
<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { trans } from '@/lib/i18n';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Profile settings'),
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head :title="$t('Profile settings')" />

    <section class="flex flex-col gap-5">
        <Heading
            variant="small"
            :title="$t('Profile')"
            :description="$t('Update your name')"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="panel flex flex-col gap-5 p-5 sm:p-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ $t('Name') }}</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    :placeholder="$t('Full name')"
                    :aria-invalid="errors.name ? 'true' : undefined"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ $t('Email address') }}</Label>
                <!-- Display only: no name attribute, so it is never submitted. -->
                <Input
                    id="email"
                    type="email"
                    :default-value="user.email"
                    disabled
                    aria-describedby="email-help"
                />
                <p id="email-help" class="text-sm text-muted-foreground">
                    {{
                        $t(
                            'Only an administrator can change your email address.',
                        )
                    }}
                </p>
            </div>

            <div class="flex items-center gap-2.5">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                    >{{ $t('Save') }}</Button
                >
            </div>
        </Form>
    </section>
</template>
