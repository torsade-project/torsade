// torsade/resources/js/types/search.ts
import type { StatusValue } from './network';

// What the search of the documentation answers. The server says which
// parts of a value match what was typed: the browser only shows them.

// A piece of a value: whether it matches, and whether it is a technical
// value written in the monospaced font.
export type SearchSegment = {
    text: string;
    match: boolean;
    mono: boolean;
};

export type SearchResultType =
    | 'site'
    | 'building'
    | 'floor'
    | 'room'
    | 'vlan'
    | 'catalog'
    | 'block'
    | 'subnet'
    | 'address'
    | 'device'
    | 'port'
    | 'jack'
    | 'panel'
    | 'template';

export type SearchItem = {
    key: string;
    type: SearchResultType;
    label: SearchSegment[];
    // The parts of the context, shown one after the other.
    context: SearchSegment[][];
    // A value the text was found in, that the result does not show
    // otherwise, with the name of its field.
    excerpt: { field: string; segments: SearchSegment[] } | null;
    // Only for a planned or a retired object.
    status: StatusValue | null;
    status_label: string | null;
    url: string;
};

export type SearchGroupKey =
    | 'sites'
    | 'places'
    | 'vlans'
    | 'catalog'
    | 'blocks'
    | 'subnets'
    | 'addresses'
    | 'devices'
    | 'ports'
    | 'jacks'
    | 'panels'
    | 'templates';

export type SearchGroup = {
    key: SearchGroupKey;
    label: string;
    total: number;
    exact: boolean;
    items: SearchItem[];
};

// An address that no record holds, the subnet it lies in, and, for those
// who may write, where to document it.
export type UndocumentedAddress = {
    address: string;
    subnet: {
        id: number;
        cidr: string;
        name: string | null;
        url: string;
    } | null;
    document_url: string | null;
};

export type SearchResults = {
    query: string;
    // Said when what was typed was read otherwise, a prefix brought back
    // to its network address.
    notice: string | null;
    undocumented: UndocumentedAddress | null;
    groups: SearchGroup[];
    total: number;
};
