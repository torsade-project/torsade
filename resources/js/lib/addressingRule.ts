// torsade/resources/js/lib/addressingRule.ts
import { ipv4ToNumber, numberToIpv4, prefixRange } from './ipv4';

// What the addressing rule of the organisation works out: the block a site
// is expected to have, the subnet a VLAN is expected to carry, the gateway
// and the ranges proposed for a subnet.
//
// The forms use these functions to propose values while they are filled in.
// The server works the same values out by itself and never takes them from
// the browser; both are checked against the same table of cases.
//
// A rule may have no level of sites: the subnets of VLANs are then ranked by
// VID straight in the block of the organisation, the same for every site,
// and no block is expected of a site, nor any number.

export type GatewayPositionValue = 'first' | 'last' | 'none';

export type RangeKindValue = 'dhcp' | 'static' | 'reserved';

// A range of the rule, as offsets from the network address of a subnet.
export type RuleRange = {
    kind: RangeKindValue;
    start_offset: number;
    end_offset: number;
    name: string | null;
};

// The rule as the server hands it over: its block and its lengths, the
// length of the blocks of sites null without a level of sites.
export type AddressingRuleParams = {
    network: string;
    prefix_length: number;
    site_prefix_length: number | null;
    vlan_prefix_length: number;
    gateway_position: GatewayPositionValue;
    ranges: RuleRange[];
};

export type Prefix = {
    network: string;
    prefix_length: number;
};

// A range of the rule laid out in a subnet.
export type PlacedRange = {
    kind: RangeKindValue;
    start_address: string;
    end_address: string;
    name: string | null;
    // Whether both ends are usable addresses of the subnet.
    fits: boolean;
};

const HIGHEST_ADDRESS = 4294967295;

// The number of addresses of a prefix. Powers, not bit shifts: JavaScript
// shifts work on signed 32-bit integers.
const sizeOf = (prefixLength: number): number => 2 ** (32 - prefixLength);

const isLength = (value: number): boolean =>
    Number.isInteger(value) && value >= 1 && value <= 32;

/**
 * Whether the parameters make a rule: a block, cut into blocks of sites if
 * there is a level of sites, cut into subnets of VLANs that keep usable
 * addresses. A form that is being typed may hold anything.
 */
export function isCoherent(rule: AddressingRuleParams): boolean {
    const site = rule.site_prefix_length;

    return (
        isLength(rule.prefix_length) &&
        isLength(rule.vlan_prefix_length) &&
        prefixRange(rule.network, rule.prefix_length) !== null &&
        (site === null
            ? rule.prefix_length < rule.vlan_prefix_length
            : isLength(site) &&
              rule.prefix_length < site &&
              site < rule.vlan_prefix_length) &&
        rule.vlan_prefix_length <= 30
    );
}

/**
 * Whether the rule cuts the block of the organisation into blocks of
 * sites, ranked by the numbers of the sites.
 */
export function hasSiteLevel(rule: AddressingRuleParams): boolean {
    return rule.site_prefix_length !== null;
}

/**
 * The highest number a site can have for the rule to give it a block;
 * null without a level of sites, the rule giving no block to any site.
 */
export function maxSiteNumber(rule: AddressingRuleParams): number | null {
    return rule.site_prefix_length === null
        ? null
        : 2 ** (rule.site_prefix_length - rule.prefix_length) - 1;
}

export function allowsSiteNumber(
    rule: AddressingRuleParams,
    number: number | null,
): number is number {
    const max = maxSiteNumber(rule);

    return (
        max !== null &&
        number !== null &&
        Number.isInteger(number) &&
        number >= 0 &&
        number <= max
    );
}

/**
 * The block a site is expected to have, or null when the site has no
 * number or one the rule does not reach.
 */
export function siteBlock(
    rule: AddressingRuleParams,
    number: number | null,
): Prefix | null {
    const base = ipv4ToNumber(rule.network);
    const length = rule.site_prefix_length;

    if (base === null || length === null || !allowsSiteNumber(rule, number)) {
        return null;
    }

    return {
        network: numberToIpv4(base + number * sizeOf(length)),
        prefix_length: length,
    };
}

/**
 * The highest VID the rule covers. No VLAN is numbered 0: the first subnet
 * of the block of a site, or of the block of the organisation without a
 * level of sites, belongs to none.
 */
export function maxVid(rule: AddressingRuleParams): number {
    return (
        2 **
            (rule.vlan_prefix_length -
                (rule.site_prefix_length ?? rule.prefix_length)) -
        1
    );
}

export function coversVid(rule: AddressingRuleParams, vid: number): boolean {
    return Number.isInteger(vid) && vid >= 1 && vid <= maxVid(rule);
}

/**
 * The subnet a VLAN is expected to carry, or null when its site has no
 * expected block or when the rule does not cover its VID. Without a level
 * of sites, it is ranked in the block of the organisation, whatever the
 * site.
 */
export function vlanSubnet(
    rule: AddressingRuleParams,
    siteNumber: number | null,
    vid: number,
): Prefix | null {
    const block = hasSiteLevel(rule)
        ? siteBlock(rule, siteNumber)
        : { network: rule.network, prefix_length: rule.prefix_length };
    const base = block === null ? null : ipv4ToNumber(block.network);

    if (base === null || !coversVid(rule, vid)) {
        return null;
    }

    return {
        network: numberToIpv4(base + vid * sizeOf(rule.vlan_prefix_length)),
        prefix_length: rule.vlan_prefix_length,
    };
}

/**
 * The gateway proposed for a subnet: its first or its last usable address,
 * or none.
 */
export function gatewayOf(
    rule: AddressingRuleParams,
    network: string,
    prefixLength: number,
): string | null {
    const range = prefixRange(network, prefixLength);

    if (range === null || rule.gateway_position === 'none') {
        return null;
    }

    // A /31 and a /32 have neither network nor broadcast address.
    const edge = prefixLength >= 31 ? 0 : 1;

    return numberToIpv4(
        rule.gateway_position === 'first'
            ? range.start + edge
            : range.end - edge,
    );
}

/**
 * The ranges of the rule laid out in a subnet, each one shifted from its
 * network address. One that does not fit is still returned, so that it can
 * be named.
 */
export function breakdownOf(
    rule: AddressingRuleParams,
    network: string,
    prefixLength: number,
): PlacedRange[] {
    const range = prefixRange(network, prefixLength);

    if (range === null) {
        return [];
    }

    const size = sizeOf(prefixLength);
    const [lowest, last] = prefixLength >= 31 ? [0, size - 1] : [1, size - 2];

    return rule.ranges.map((ruleRange) => ({
        kind: ruleRange.kind,
        start_address: numberToIpv4(
            Math.min(range.start + ruleRange.start_offset, HIGHEST_ADDRESS),
        ),
        end_address: numberToIpv4(
            Math.min(range.start + ruleRange.end_offset, HIGHEST_ADDRESS),
        ),
        name: ruleRange.name,
        fits:
            ruleRange.start_offset >= lowest &&
            ruleRange.end_offset <= last &&
            ruleRange.start_offset <= ruleRange.end_offset,
    }));
}

/**
 * Whether every range of the rule fits in a subnet. A rule without ranges
 * has nothing to lay out: it does not fit anywhere.
 */
export function breakdownFits(
    rule: AddressingRuleParams,
    network: string,
    prefixLength: number,
): boolean {
    const placed = breakdownOf(rule, network, prefixLength);

    return placed.length > 0 && placed.every((range) => range.fits);
}

/**
 * A prefix in CIDR notation: "10.20.0.0/16".
 */
export function cidr(prefix: Prefix): string {
    return `${prefix.network}/${prefix.prefix_length}`;
}
