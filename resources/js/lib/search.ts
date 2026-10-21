// torsade/resources/js/lib/search.ts
import { ref } from 'vue';
import { trans } from '@/lib/i18n';
import { results } from '@/routes/search';
import type { SearchGroup, SearchResults } from '@/types';

// The search window is one for the whole application: the field of the
// header, its shortcuts and the field of the home page all open it.
export const searchOpen = ref<boolean>(false);

// What the window starts with when it is opened from a field where
// something was already typed.
export const searchStart = ref<string | null>(null);

export function openSearch(text: string | null = null): void {
    searchStart.value = text;
    searchOpen.value = true;
}

/**
 * Whether what was typed is worth asking for: two characters, or a single
 * digit, which may already be a VLAN number.
 */
export function isWorthSearching(text: string): boolean {
    const trimmed = text.trim();

    return trimmed.length >= 2 || /^\d$/.test(trimmed);
}

/**
 * Whether the key pressed asks for the search window: "/" or Ctrl+K, except
 * while something is typed in a field, where "/" is a character.
 */
export function asksForSearch(event: KeyboardEvent): boolean {
    const slash =
        event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey;
    const controlK =
        event.key.toLowerCase() === 'k' &&
        (event.ctrlKey || event.metaKey) &&
        !event.altKey;

    if (!slash && !controlK) {
        return false;
    }

    const target = event.target;

    return !(
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            target.closest(
                'input, textarea, select, [contenteditable="true"]',
            ) !== null)
    );
}

// Why a search did not give results, in words for the window.
export class SearchError extends Error {}

/**
 * The message for an answer that is not a list of results: a refused
 * search says why, a server that did not answer says so.
 */
async function failure(response: Response): Promise<SearchError> {
    if (response.status === 422) {
        const body = (await response.json().catch(() => ({}))) as {
            errors?: Record<string, string[]>;
        };

        return new SearchError(
            body.errors?.q?.[0] ??
                body.errors?.group?.[0] ??
                trans('This search cannot be done.'),
        );
    }

    if (response.status === 429) {
        return new SearchError(
            trans(
                'Too many searches in a short time: wait a moment, then type again.',
            ),
        );
    }

    return new SearchError(
        trans('The search did not succeed. Try again in a moment.'),
    );
}

async function ask(
    query: Record<string, string | number>,
    signal?: AbortSignal,
): Promise<unknown> {
    let response: Response;

    try {
        response = await fetch(results({ query }).url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal,
        });
    } catch (error) {
        // A search given up for a newer one is not a failure.
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        throw new SearchError(
            trans('The search did not succeed. Try again in a moment.'),
        );
    }

    if (!response.ok) {
        throw await failure(response);
    }

    return response.json();
}

/**
 * The results the window shows for what was typed, a few per group.
 */
export async function searchFor(
    text: string,
    signal?: AbortSignal,
): Promise<SearchResults> {
    return (await ask({ q: text.trim() }, signal)) as SearchResults;
}

/**
 * The next page of one group of the page of results.
 */
export async function nextPageOf(
    text: string,
    group: string,
    page: number,
): Promise<SearchGroup | null> {
    const answer = (await ask({ q: text.trim(), group, page })) as {
        group: SearchGroup | null;
    };

    return answer.group;
}
