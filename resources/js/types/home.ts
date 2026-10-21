// torsade/resources/js/types/home.ts
import type { ActivityEntry } from './admin';
import type { StatusValue } from './network';

// The modules of the home page, as the server prepares them: each one with
// its key, its settings and its own data. The browser draws one component
// per key, and leaves aside a key it does not know.

export type GettingStartedStep = {
    key: string;
    title: string;
    done: boolean;
    optional: boolean;
    links: { label: string; url: string }[];
};

export type SummaryCounter = {
    key: string;
    count: number;
    label: string;
    planned: number;
    planned_label: string | null;
    url: string | null;
};

export type HomeSite = {
    id: number;
    name: string;
    code: string | null;
    status: StatusValue;
    status_label: string;
    vlans_count: number;
    subnets_count: number;
    devices_count: number;
    url: string;
    physical_diagram_url: string;
    logical_diagram_url: string;
};

export type FullSubnet = {
    id: number;
    cidr: string;
    name: string | null;
    vlan: { vid: number; name: string } | null;
    site: string | null;
    documented_count: number;
    usable_count: number;
    url: string;
};

export type HomeModuleData =
    | {
          key: 'search';
          settings: Record<string, never>;
          data: Record<string, never>;
      }
    | {
          key: 'getting-started';
          settings: Record<string, never>;
          // No steps for a reader: only the sentence that says who
          // creates the first site.
          data: { steps: GettingStartedStep[] | null };
      }
    | {
          key: 'summary';
          settings: Record<string, never>;
          data: { counters: SummaryCounter[] };
      }
    | {
          key: 'sites';
          settings: { limit: number };
          data: { sites: HomeSite[]; total: number; url: string };
      }
    | {
          key: 'fullest-subnets';
          settings: { limit: number };
          data: { subnets: FullSubnet[] };
      }
    | {
          key: 'recent-activity';
          settings: { limit: number };
          data: { entries: ActivityEntry[]; url: string };
      };
