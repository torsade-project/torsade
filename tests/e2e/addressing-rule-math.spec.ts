// torsade/tests/e2e/addressing-rule-math.spec.ts
import fs from 'node:fs';
import path from 'node:path';
// No page is opened here: the plain test, without the fixtures that watch a
// browser.
import { expect, test } from '@playwright/test';
import {
    allowsSiteNumber,
    breakdownFits,
    breakdownOf,
    cidr,
    coversVid,
    gatewayOf,
    hasSiteLevel,
    isCoherent,
    maxSiteNumber,
    maxVid,
    siteBlock,
    vlanSubnet,
} from '@/lib/addressingRule';
import type { AddressingRuleParams, Prefix } from '@/lib/addressingRule';
import { projectRoot } from './support/environment';

// The forms work out in the browser what the addressing rule proposes. The
// functions that do it are checked against the table of cases the server is
// checked against: the two sides cannot drift apart.

type Case = {
    rule: string;
    // Null for a rule without a level of sites, which uses no number.
    max_site_number: number | null;
    max_vid: number;
    site_blocks: [number | null, string | null][];
    vlan_subnets: [number | null, number, string | null][];
    gateways: [string, number, string | null][];
    breakdowns: {
        subnet: string;
        fits: boolean;
        ranges: [string, string, string, string | null, boolean][];
    }[];
};

const table = JSON.parse(
    fs.readFileSync(
        path.join(
            projectRoot,
            'tests',
            'fixtures',
            'addressing-rule-cases.json',
        ),
        'utf8',
    ),
) as { rules: Record<string, AddressingRuleParams>; cases: Case[] };

const written = (prefix: Prefix | null): string | null =>
    prefix === null ? null : cidr(prefix);

test('the table covers every rule it describes', () => {
    expect(table.cases.map((one) => one.rule)).toEqual(
        Object.keys(table.rules),
    );
    expect(table.cases.length).toBeGreaterThanOrEqual(6);
});

for (const one of table.cases) {
    const rule = table.rules[one.rule];

    test.describe(`rule "${one.rule}"`, () => {
        test('the numbers of sites and the VIDs the rule reaches', () => {
            expect(isCoherent(rule)).toBe(true);
            expect(maxSiteNumber(rule)).toBe(one.max_site_number);
            expect(maxVid(rule)).toBe(one.max_vid);

            // The bounds, and one step beyond each; no number at all without
            // a level of sites.
            if (one.max_site_number === null) {
                expect(hasSiteLevel(rule)).toBe(false);
                expect(allowsSiteNumber(rule, 0)).toBe(false);
            } else {
                expect(hasSiteLevel(rule)).toBe(true);
                expect(allowsSiteNumber(rule, 0)).toBe(true);
                expect(allowsSiteNumber(rule, one.max_site_number)).toBe(true);
                expect(allowsSiteNumber(rule, one.max_site_number + 1)).toBe(
                    false,
                );
            }

            expect(allowsSiteNumber(rule, -1)).toBe(false);
            expect(allowsSiteNumber(rule, null)).toBe(false);

            expect(coversVid(rule, 0)).toBe(false);
            expect(coversVid(rule, 1)).toBe(true);
            expect(coversVid(rule, one.max_vid)).toBe(true);
            expect(coversVid(rule, one.max_vid + 1)).toBe(false);
        });

        test('the block expected for a site', () => {
            for (const [number, expected] of one.site_blocks) {
                expect(
                    written(siteBlock(rule, number)),
                    `site ${String(number)}`,
                ).toBe(expected);
            }
        });

        test('the subnet expected for a VLAN', () => {
            for (const [site, vid, expected] of one.vlan_subnets) {
                expect(
                    written(vlanSubnet(rule, site, vid)),
                    `site ${String(site)}, VLAN ${vid}`,
                ).toBe(expected);
            }
        });

        test('the gateway proposed for a subnet', () => {
            for (const [network, prefix, expected] of one.gateways) {
                expect(
                    gatewayOf(rule, network, prefix),
                    `${network}/${prefix}`,
                ).toBe(expected);
            }
        });

        test('the ranges laid out in a subnet', () => {
            for (const breakdown of one.breakdowns) {
                const [network, prefix] = breakdown.subnet.split('/');

                expect(
                    breakdownOf(rule, network, Number(prefix)).map((range) => [
                        range.kind,
                        range.start_address,
                        range.end_address,
                        range.name,
                        range.fits,
                    ]),
                    breakdown.subnet,
                ).toEqual(breakdown.ranges);
                expect(
                    breakdownFits(rule, network, Number(prefix)),
                    breakdown.subnet,
                ).toBe(breakdown.fits);
            }
        });
    });
}

test('a form being typed is not taken for a rule', () => {
    const usual = table.rules.usual;

    // Lengths out of order, a subnet without usable addresses, an address
    // that is not the one of a block.
    for (const changed of [
        { site_prefix_length: 8 },
        { site_prefix_length: 24 },
        { vlan_prefix_length: 31 },
        { vlan_prefix_length: 16 },
        { prefix_length: 0 },
        { network: '10.0.0.1' },
        { network: '10.0' },
        { site_prefix_length: Number.NaN },
    ]) {
        expect(
            isCoherent({ ...usual, ...changed }),
            JSON.stringify(changed),
        ).toBe(false);
    }

    expect(isCoherent(usual)).toBe(true);
    expect(isCoherent({ ...usual, vlan_prefix_length: 30 })).toBe(true);

    // Without a level of sites, the subnets of VLANs still have to be
    // smaller than the block of the organisation.
    const flat = table.rules['no level of sites'];

    expect(isCoherent(flat)).toBe(true);
    expect(isCoherent({ ...flat, vlan_prefix_length: 16 })).toBe(false);
    expect(isCoherent({ ...flat, vlan_prefix_length: 17 })).toBe(true);
    expect(isCoherent({ ...flat, vlan_prefix_length: 31 })).toBe(false);
});
