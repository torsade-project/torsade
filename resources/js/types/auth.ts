// torsade/resources/js/types/auth.ts
export type Role = 'reader' | 'editor' | 'admin';

export type User = {
    id: number;
    name: string;
    email: string;
    role: Role;
};

export type Auth = {
    user: User;
    // What the navigation may show. The server checks the same gates again.
    can: {
        editNetwork: boolean;
        manageUsers: boolean;
        viewActivityLog: boolean;
    };
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
