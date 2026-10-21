<!-- torsade/resources/js/pages/users/Create.vue -->
<script setup lang="ts">
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import TemporaryPasswordDialog from '@/components/TemporaryPasswordDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { create, index, store } from '@/routes/users';
import type { Role, RoleOption, TemporaryPasswordResponse } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Users'),
                href: index(),
            },
            {
                title: trans('New user'),
                href: create(),
            },
        ],
    },
});

defineProps<{
    roles: RoleOption[];
}>();

// A plain HTTP request, not an Inertia visit: the temporary password comes
// back in the response body and never goes through the session.
const form = useHttp<
    { name: string; email: string; role: Role },
    TemporaryPasswordResponse
>({
    name: '',
    email: '',
    role: 'reader',
});

const result = ref<TemporaryPasswordResponse | null>(null);

const submit = async (): Promise<void> => {
    try {
        result.value = await form.submit(store());
    } catch {
        // Validation messages are shown under the fields.
        if (!form.hasErrors) {
            toast.error(trans('Something went wrong.'));
        }
    }
};

const closePasswordDialog = (): void => {
    result.value = null;

    router.visit(index());
};
</script>

<template>
    <Head :title="$t('New user')" />

    <div class="flex max-w-2xl flex-col gap-7">
        <Heading
            :title="$t('New user')"
            :description="
                $t(
                    'A temporary password is generated and shown once. The user will have to change it at their first login.',
                )
            "
        />

        <form
            class="panel flex flex-col gap-5 p-5 sm:p-6"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="name">{{ $t('Name') }}</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    name="name"
                    required
                    v-focus
                    autocomplete="off"
                    :placeholder="$t('Full name')"
                    :aria-invalid="form.errors.name ? 'true' : undefined"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ $t('Email address') }}</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    name="email"
                    required
                    autocomplete="off"
                    :placeholder="$t('email@example.com')"
                    :aria-invalid="form.errors.email ? 'true' : undefined"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="role">{{ $t('Role') }}</Label>
                <NativeSelect
                    id="role"
                    v-model="form.role"
                    name="role"
                    :aria-invalid="form.errors.role ? 'true' : undefined"
                >
                    <option
                        v-for="role in roles"
                        :key="role.value"
                        :value="role.value"
                    >
                        {{ role.label }}
                    </option>
                </NativeSelect>
                <InputError :message="form.errors.role" />
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="store-user-button"
                >
                    <Spinner v-if="form.processing" />
                    {{ $t('Create the user') }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="index()">{{ $t('Cancel') }}</Link>
                </Button>
            </div>
        </form>
    </div>

    <TemporaryPasswordDialog :result="result" @close="closePasswordDialog" />
</template>
