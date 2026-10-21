// torsade/resources/js/types/table.ts

// A column of a sortable table. Cells are drawn by the page through a slot
// named "cell-<id>"; the value below is what sorting and searching use.
export type DataColumn<TRow> = {
    id: string;
    header: string;
    value: (row: TRow) => string | number | null;
    // Columns sort on their value unless told otherwise.
    sortable?: boolean;
    // A custom order, ascending: IP addresses do not sort like text.
    compare?: (a: TRow, b: TRow) => number;
    // Whether the search box looks into this column.
    searchable?: boolean;
    // A custom match, when the typed text may be written differently from
    // the stored value. The query comes trimmed and in lower case.
    matches?: (row: TRow, query: string) => boolean;
    align?: 'left' | 'right';
    // Classes for the cells of the column.
    class?: string;
};
