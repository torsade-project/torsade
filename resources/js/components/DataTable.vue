<!-- torsade/resources/js/components/DataTable.vue -->
<script setup lang="ts" generic="TRow extends { id: number }">
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue';
import {
    columnFilteringFeature,
    createFilteredRowModel,
    createPaginatedRowModel,
    createSortedRowModel,
    globalFilteringFeature,
    rowPaginationFeature,
    rowSortingFeature,
    tableFeatures,
    useTable,
} from '@tanstack/vue-table';
import { computed, onMounted, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useLocationTarget } from '@/composables/useLocationTarget';
import { currentLocale } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { cn } from '@/lib/utils';
import type { DataColumn } from '@/types';

// A dense table with sortable columns, an optional search and pages, all
// computed in the browser on the rows the server sent. The server sends the
// rows already in their default order.
const props = withDefaults(
    defineProps<{
        rows: TRow[];
        columns: DataColumn<TRow>[];
        // Text typed in the search box of the page.
        search?: string;
        // The column the rows are sorted on when the page opens.
        sortBy?: string;
        pageSize?: number;
        emptyText: string;
        // Shown instead when the search leaves no row.
        noMatchText?: string;
        // Prefix of the data-test attribute of each row.
        rowTest?: string;
        // What the table lists, for assistive technologies.
        label?: string;
        // The name of the line of a row, that a link can lead to
        // ("#address-12").
        rowAnchor?: (row: TRow) => string;
    }>(),
    {
        search: '',
        sortBy: undefined,
        pageSize: 100,
        noMatchText: undefined,
        rowTest: 'row',
        label: undefined,
        rowAnchor: undefined,
    },
);

const features = tableFeatures({
    rowSortingFeature,
    columnFilteringFeature,
    globalFilteringFeature,
    rowPaginationFeature,
    sortedRowModel: createSortedRowModel(),
    filteredRowModel: createFilteredRowModel(),
    paginatedRowModel: createPaginatedRowModel(),
});

const columnById = computed(
    () => new Map(props.columns.map((column) => [column.id, column])),
);

// Empty values last, numbers by value, text the way the language sorts it
// ("VLAN 9" before "VLAN 10").
const compareValues = (
    a: string | number | null,
    b: string | number | null,
): number => {
    if (a === null || b === null) {
        return a === b ? 0 : a === null ? 1 : -1;
    }

    if (typeof a === 'number' && typeof b === 'number') {
        return a - b;
    }

    return String(a).localeCompare(String(b), currentLocale(), {
        numeric: true,
        sensitivity: 'base',
    });
};

const table = useTable({
    features,
    data: computed(() => props.rows),
    columns: props.columns.map((column) => ({
        id: column.id,
        accessorFn: (row: TRow) => column.value(row),
        enableSorting: column.sortable !== false,
        enableGlobalFilter: column.searchable === true,
        sortFn: (a: { original: TRow }, b: { original: TRow }): number =>
            column.compare
                ? column.compare(a.original, b.original)
                : compareValues(
                      column.value(a.original),
                      column.value(b.original),
                  ),
    })),
    initialState: {
        sorting: props.sortBy ? [{ id: props.sortBy, desc: false }] : [],
        pagination: { pageIndex: 0, pageSize: props.pageSize },
    },
    // A click on a header goes from ascending to descending and back.
    enableSortingRemoval: false,
    getColumnCanGlobalFilter: (column: { id: string }) =>
        columnById.value.get(column.id)?.searchable === true,
    globalFilterFn: (
        row: { original: TRow },
        columnId: string,
        query: string,
    ): boolean => {
        const column = columnById.value.get(columnId);

        if (!column) {
            return false;
        }

        if (column.matches) {
            return column.matches(row.original, query);
        }

        return String(column.value(row.original) ?? '')
            .toLowerCase()
            .includes(query);
    },
});

watch(
    () => props.search,
    (search) => {
        const query = search.trim().toLowerCase();

        table.setGlobalFilter(query === '' ? undefined : query);
    },
    { immediate: true },
);

const visibleRows = computed(() => table.getRowModel().rows);

// A link that leads to a line of the table opens the page of the table
// where that line is; the line is marked.
const target = useLocationTarget();

onMounted(() => {
    const anchor = props.rowAnchor;

    if (anchor === undefined || target.value === null) {
        return;
    }

    const position = table
        .getPrePaginatedRowModel()
        .rows.findIndex((row) => anchor(row.original) === target.value);

    if (position >= 0) {
        table.setPageIndex(Math.floor(position / props.pageSize));
    }
});

const anchorOf = (row: TRow): string | undefined => props.rowAnchor?.(row);
const matchingCount = computed(
    () => table.getPrePaginatedRowModel().rows.length,
);
const pageCount = computed(() => table.getPageCount());
const pageNumber = computed(() => table.atoms.pagination.get().pageIndex + 1);

const sortState = (id: string): 'ascending' | 'descending' | 'none' => {
    const direction = table.getColumn(id)?.getIsSorted();

    return direction === 'asc'
        ? 'ascending'
        : direction === 'desc'
          ? 'descending'
          : 'none';
};

const toggleSort = (id: string): void => {
    table.getColumn(id)?.toggleSorting();
};
</script>

<template>
    <div class="flex min-w-0 flex-col gap-3">
        <!-- A table wider than its frame scrolls inside it, not the page. -->
        <div class="table-frame">
            <table class="table-dense" :aria-label="label">
                <thead>
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.id"
                            scope="col"
                            :class="
                                column.align === 'right' ? 'text-right' : ''
                            "
                            :aria-sort="
                                column.sortable === false
                                    ? undefined
                                    : sortState(column.id)
                            "
                        >
                            <span v-if="column.sortable === false">{{
                                column.header
                            }}</span>
                            <button
                                v-else
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-sm hover:text-link"
                                :data-test="`sort-${column.id}`"
                                @click="toggleSort(column.id)"
                            >
                                {{ column.header }}
                                <ArrowUp
                                    v-if="sortState(column.id) === 'ascending'"
                                    class="size-3.5 text-link"
                                    aria-hidden="true"
                                />
                                <ArrowDown
                                    v-else-if="
                                        sortState(column.id) === 'descending'
                                    "
                                    class="size-3.5 text-link"
                                    aria-hidden="true"
                                />
                                <ArrowUpDown
                                    v-else
                                    class="size-3.5 text-subtle-foreground"
                                    aria-hidden="true"
                                />
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="visibleRows.length === 0">
                        <td
                            :colspan="columns.length"
                            class="py-8 text-center text-muted-foreground"
                            data-test="empty-table"
                        >
                            {{
                                rows.length > 0 && noMatchText
                                    ? noMatchText
                                    : emptyText
                            }}
                        </td>
                    </tr>
                    <tr
                        v-for="row in visibleRows"
                        :key="row.original.id"
                        :id="anchorOf(row.original)"
                        class="align-middle"
                        :class="{
                            'bg-accent':
                                target !== null &&
                                anchorOf(row.original) === target,
                        }"
                        :data-test="`${rowTest}-${row.original.id}`"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.id"
                            :class="
                                cn(
                                    column.align === 'right'
                                        ? 'text-right'
                                        : '',
                                    column.class,
                                )
                            "
                        >
                            <slot
                                :name="`cell-${column.id}`"
                                :row="row.original"
                            >
                                {{ column.value(row.original) }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="pageCount > 1"
            class="flex flex-wrap items-center justify-between gap-4 text-sm text-muted-foreground"
            :aria-label="$t('Pagination')"
        >
            <p>
                {{
                    $tc(
                        'Page :current of :last (:total entry)|Page :current of :last (:total entries)',
                        matchingCount,
                        {
                            current: pageNumber,
                            last: pageCount,
                            total: formatCount(matchingCount),
                        },
                    )
                }}
            </p>
            <div class="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="!table.getCanPreviousPage()"
                    data-test="previous-page"
                    @click="table.previousPage()"
                >
                    {{ $t('Previous') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="!table.getCanNextPage()"
                    data-test="next-page"
                    @click="table.nextPage()"
                >
                    {{ $t('Next') }}
                </Button>
            </div>
        </nav>
    </div>
</template>
