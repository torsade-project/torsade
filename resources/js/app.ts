// torsade/resources/js/app.ts
import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { cspNonce } from '@/lib/cspNonce';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { trans, transChoice } from '@/lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Torsade';

void createInertiaApp({
    // The style of the progress bar, and of the error dialog in
    // development, inserted with the nonce of the policy.
    nonce: cspNonce(),
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        // Every interface string goes through $t() in templates, or
        // through $tc() when it has to agree with a number.
        app.config.globalProperties.$t = trans;
        app.config.globalProperties.$tc = transChoice;

        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        // A value of the theme: the colour of links and of the focus.
        color: 'var(--link)',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
