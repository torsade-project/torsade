// torsade/resources/js/types/admin.ts
import type { Role } from '@/types/auth';

export type RoleOption = {
    value: Role;
    label: string;
};

// Enabled, required by an administrator but not configured yet, or disabled.
export type TwoFactorState = 'enabled' | 'required' | 'disabled';

export type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: Role;
    role_label: string;
    is_active: boolean;
    must_change_password: boolean;
    two_factor: TwoFactorState;
    last_login_at: string | null;
    is_self: boolean;
};

export type EditableUser = {
    id: number;
    name: string;
    email: string;
    role: Role;
    is_self: boolean;
    two_factor: TwoFactorState;
    two_factor_required: boolean;
};

// The only place a temporary password ever reaches the browser.
export type TemporaryPasswordResponse = {
    user: { id: number; name: string; email: string };
    temporary_password: string;
};

export type ActivityEntry = {
    id: number;
    event: string;
    event_label: string;
    created_at: string | null;
    author: {
        type: 'user' | 'console' | 'anonymous';
        name?: string;
        email?: string;
    };
    // An account (name and e-mail address) or a network object (its label).
    subject: { title: string; detail: string | null } | null;
    changes: { field: string; old: string | null; new: string | null }[];
    details: string[];
    ip: string | null;
};

export type ActivityFilters = {
    user: string;
    event: string;
    from: string;
    to: string;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
