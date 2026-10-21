// torsade/resources/js/lib/dates.ts
import { usePage } from '@inertiajs/vue3';
import { currentLocale } from '@/lib/i18n';

/**
 * Format a UTC date sent by the server in the display timezone configured
 * for the application (APP_DISPLAY_TIMEZONE), in the language of the page.
 */
export function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    return new Intl.DateTimeFormat(currentLocale(), {
        dateStyle: 'short',
        timeStyle: 'medium',
        timeZone: usePage().props.timezone,
    }).format(new Date(iso));
}
