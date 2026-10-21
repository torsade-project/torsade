<!-- torsade/resources/js/pages/users/Edit.vue -->
<script setup lang="ts">
import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { edit, index, update } from '@/routes/users';
import { enforce, release, reset } from '@/routes/users/two-factor';
import type { EditableUser, Role, RoleOption } from '@/types';

const props = defineProps<{
    user: EditableUser;
    roles: RoleOption[];
}>();

setLayoutProps({
    breadcrumbs: [
        {
            title: trans('Users'),
            href: index(),
        },
        {
            title: props.user.name,
            href: edit(props.user.id),
        },
    ],
});

const form = useForm<{ name: string; email: string; role: Role }>({
    name: props.user.name,
    email: props.user.email,
    role: props.user.role,
});

const submit = (): void => {
    form.put(update(props.user.id).url);
};

// Two-factor authentication of the account. An administrator can require it
// or reset it, never enable it: whoever enables it holds the secret.
type TwoFactorAction = 'enforce' | 'release' | 'reset';

const pendingAction = ref<TwoFactorAction | null>(null);

const twoFactorStatus = computed<string>(
    () =>
        ({
            enabled: trans('Enabled'),
            required: trans('Required, to be set up'),
            disabled: trans('Disabled'),
        })[props.user.two_factor],
);

const dialogs: Record<
    TwoFactorAction,
    { title: string; description: string; confirm: string }
> = {
    enforce: {
        title: trans('Require two-factor authentication?'),
        description: trans(
            ':name will have to set it up at their next request and will no longer be able to disable it.',
            { name: props.user.name },
        ),
        confirm: trans('Require two-factor authentication'),
    },
    release: {
        title: trans('Stop requiring two-factor authentication?'),
        description: trans(
            ':name will be free to keep it or to disable it. Nothing changes if it is already set up.',
            { name: props.user.name },
        ),
        confirm: trans('Stop requiring it'),
    },
    reset: {
        title: trans('Reset two-factor authentication?'),
        description: trans(
            'The authenticator configuration and the recovery codes of :name will be deleted and all their sessions closed. Use it when their device is lost.',
            { name: props.user.name },
        ),
        confirm: trans('Reset two-factor authentication'),
    },
};

const confirmAction = (): void => {
    const action = pendingAction.value;

    pendingAction.value = null;

    if (!action) {
        return;
    }

    const route = { enforce, release, reset }[action](props.user.id);

    router.post(
        route.url,
        {},
        {
            preserveScroll: true,
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Something went wrong.'),
                ),
        },
    );
};
</script>

<template>
    <Head :title="$t('Edit the user')" />

    <div class="flex max-w-2xl flex-col gap-10">
        <div class="flex flex-col gap-7">
            <Heading
                :title="$t('Edit the user')"
                :description="
                    $t('Changing the role closes all the sessions of the user.')
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
                        :disabled="user.is_self"
                        aria-describedby="role-help"
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
                    <p
                        v-if="user.is_self"
                        id="role-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('You cannot change your own role.') }}
                    </p>
                    <InputError :message="form.errors.role" />
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="update-user-button"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="index()">{{ $t('Cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>

        <section class="flex flex-col gap-4" data-test="two-factor-section">
            <Heading
                variant="small"
                :title="$t('Two-factor authentication')"
                :description="
                    $t(
                        'Only the user can enable it, from their own device. You can require it, or reset it if the device is lost.',
                    )
                "
            />

            <p>
                <span class="text-muted-foreground"
                    >{{ $t('Status') }}{{ $t(':') }}</span
                >
                <span data-test="two-factor-status">{{ twoFactorStatus }}</span>
            </p>

            <p
                v-if="user.is_self"
                class="text-sm text-muted-foreground"
                data-test="two-factor-own-account"
            >
                {{
                    $t(
                        'This is your own account: manage its two-factor authentication from your settings.',
                    )
                }}
            </p>

            <div v-else class="flex flex-wrap gap-2.5">
                <Button
                    v-if="!user.two_factor_required"
                    type="button"
                    variant="outline"
                    data-test="two-factor-enforce"
                    @click="pendingAction = 'enforce'"
                >
                    {{ $t('Require two-factor authentication') }}
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    data-test="two-factor-release"
                    @click="pendingAction = 'release'"
                >
                    {{ $t('Stop requiring it') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    data-test="two-factor-reset"
                    @click="pendingAction = 'reset'"
                >
                    {{ $t('Reset two-factor authentication') }}
                </Button>
            </div>
        </section>
    </div>

    <ConfirmDialog
        :open="pendingAction !== null"
        :title="pendingAction ? dialogs[pendingAction].title : ''"
        :description="pendingAction ? dialogs[pendingAction].description : ''"
        :confirm-label="pendingAction ? dialogs[pendingAction].confirm : ''"
        :destructive="pendingAction === 'reset'"
        @confirm="confirmAction"
        @cancel="pendingAction = null"
    />
</template>
