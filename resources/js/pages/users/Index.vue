<!-- torsade/resources/js/pages/users/Index.vue -->
<script setup lang="ts">
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import TemporaryPasswordDialog from '@/components/TemporaryPasswordDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/dates';
import { trans } from '@/lib/i18n';
import {
    activate,
    create,
    deactivate,
    edit,
    index,
    resetPassword,
} from '@/routes/users';
import type {
    ManagedUser,
    Paginated,
    TemporaryPasswordResponse,
    TwoFactorState,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Users'),
                href: index(),
            },
        ],
    },
});

defineProps<{
    users: Paginated<ManagedUser>;
}>();

const http = useHttp();

const twoFactorLabels: Record<TwoFactorState, string> = {
    enabled: trans('Enabled'),
    required: trans('Required, to be set up'),
    disabled: trans('Disabled'),
};

const userToReset = ref<ManagedUser | null>(null);
const userToDeactivate = ref<ManagedUser | null>(null);
const passwordResult = ref<TemporaryPasswordResponse | null>(null);

// Rules refused by the server (last administrator, own account) come back
// as validation errors: show the first one.
const showFirstError = (errors: Record<string, string>): void => {
    toast.error(Object.values(errors)[0] ?? trans('Something went wrong.'));
};

const confirmReset = async (): Promise<void> => {
    const user = userToReset.value;

    userToReset.value = null;

    if (!user) {
        return;
    }

    try {
        passwordResult.value = (await http.submit(
            resetPassword(user.id),
        )) as TemporaryPasswordResponse;
    } catch {
        toast.error(trans('The password could not be reset.'));
    }
};

const confirmDeactivation = (): void => {
    const user = userToDeactivate.value;

    userToDeactivate.value = null;

    if (!user) {
        return;
    }

    router.post(
        deactivate(user.id).url,
        {},
        { preserveScroll: true, onError: showFirstError },
    );
};

const reactivate = (user: ManagedUser): void => {
    router.post(
        activate(user.id).url,
        {},
        { preserveScroll: true, onError: showFirstError },
    );
};

const closePasswordDialog = (): void => {
    passwordResult.value = null;

    router.reload({ only: ['users'] });
};
</script>

<template>
    <Head :title="$t('Users')" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <Heading
                :title="$t('Users')"
                :description="
                    $t(
                        'Accounts are created by an administrator and are never deleted, only deactivated.',
                    )
                "
            />
            <Button as-child data-test="create-user-button">
                <Link :href="create()">{{ $t('New user') }}</Link>
            </Button>
        </div>

        <div class="table-frame">
            <table class="table-dense" :aria-label="$t('Users')">
                <thead>
                    <tr>
                        <th scope="col">{{ $t('Name') }}</th>
                        <th scope="col">{{ $t('Email address') }}</th>
                        <th scope="col">{{ $t('Role') }}</th>
                        <th scope="col">{{ $t('Status') }}</th>
                        <th scope="col">
                            {{ $t('Two-factor authentication') }}
                        </th>
                        <th scope="col">{{ $t('Last login') }}</th>
                        <th scope="col" class="text-right">
                            {{ $t('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users.data"
                        :key="user.id"
                        :data-test="`user-row-${user.id}`"
                    >
                        <td class="font-medium whitespace-nowrap">
                            {{ user.name }}
                            <span
                                v-if="user.is_self"
                                class="font-normal text-muted-foreground"
                                >{{ $t('(you)') }}</span
                            >
                        </td>
                        <td>{{ user.email }}</td>
                        <td>{{ user.role_label }}</td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    :variant="
                                        user.is_active ? 'success' : 'outline'
                                    "
                                >
                                    {{
                                        user.is_active
                                            ? $t('Active')
                                            : $t('Inactive')
                                    }}
                                </Badge>
                                <Badge
                                    v-if="user.must_change_password"
                                    variant="outline"
                                >
                                    {{ $t('Temporary password') }}
                                </Badge>
                            </div>
                        </td>
                        <td :data-test="`two-factor-${user.id}`">
                            {{ twoFactorLabels[user.two_factor] }}
                        </td>
                        <td class="whitespace-nowrap">
                            {{
                                user.last_login_at
                                    ? formatDateTime(user.last_login_at)
                                    : $t('Never')
                            }}
                        </td>
                        <td class="py-1">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <Button variant="outline" size="xs" as-child>
                                    <Link :href="edit(user.id)">{{
                                        $t('Edit')
                                    }}</Link>
                                </Button>
                                <!-- One's own password is changed in one's
                                     settings: the server refuses it here. -->
                                <Button
                                    v-if="!user.is_self"
                                    variant="outline"
                                    size="xs"
                                    :data-test="`reset-password-${user.id}`"
                                    @click="userToReset = user"
                                >
                                    {{ $t('Reset password') }}
                                </Button>
                                <Button
                                    v-if="!user.is_active"
                                    variant="outline"
                                    size="xs"
                                    :data-test="`activate-${user.id}`"
                                    @click="reactivate(user)"
                                >
                                    {{ $t('Reactivate') }}
                                </Button>
                                <Button
                                    v-else-if="!user.is_self"
                                    variant="outline"
                                    size="xs"
                                    :data-test="`deactivate-${user.id}`"
                                    @click="userToDeactivate = user"
                                >
                                    {{ $t('Deactivate') }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination
            :current-page="users.current_page"
            :last-page="users.last_page"
            :total="users.total"
            :previous-url="users.prev_page_url"
            :next-url="users.next_page_url"
        />
    </div>

    <ConfirmDialog
        :open="userToReset !== null"
        :title="$t('Reset the password?')"
        :description="
            $t(
                'A temporary password will replace the password of :name and close all their sessions.',
                { name: userToReset?.name ?? '' },
            )
        "
        :confirm-label="$t('Reset password')"
        @confirm="confirmReset"
        @cancel="userToReset = null"
    />

    <ConfirmDialog
        :open="userToDeactivate !== null"
        :title="$t('Deactivate this account?')"
        :description="
            $t(
                ':name will be signed out at once and will not be able to log in until the account is reactivated.',
                { name: userToDeactivate?.name ?? '' },
            )
        "
        :confirm-label="$t('Deactivate')"
        destructive
        @confirm="confirmDeactivation"
        @cancel="userToDeactivate = null"
    />

    <TemporaryPasswordDialog
        :result="passwordResult"
        @close="closePasswordDialog"
    />
</template>
