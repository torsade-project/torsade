// torsade/resources/js/composables/useSubnetProposal.ts
import { computed, ref, watch } from 'vue';
import type { ComputedRef } from 'vue';
import {
    allowsSiteNumber,
    gatewayOf,
    hasSiteLevel,
    vlanSubnet,
} from '@/lib/addressingRule';
import type { AddressingRuleParams } from '@/lib/addressingRule';
import { trans } from '@/lib/i18n';

// The fields of a form the addressing rule proposes values for: the address
// of a subnet, its prefix length and its gateway.
export type ProposedFields = {
    network: string;
    prefix_length: number | null;
    gateway: string;
};

type Options = {
    // What the addressing rule works out, or null without a rule.
    rule: () => AddressingRuleParams | null;
    // The site the subnet would be on, or null while none is chosen.
    site: () => { name: string; number: number | null } | null;
    // The number of the VLAN that would carry the subnet, or null while
    // none is typed.
    vid: () => number | null;
    // The fields as the form holds them.
    read: () => ProposedFields;
    write: (values: Partial<ProposedFields>) => void;
    // The prefix length the form starts with, given back to a field the
    // rule no longer proposes anything for.
    defaultPrefix: number;
};

/**
 * Fills in the subnet a VLAN is expected to carry, as the addressing rule
 * works it out from the number of the site and from the VID, and follows
 * them as long as the fields hold what was proposed. A value typed by hand
 * is never written over: the rule proposes, it does not impose.
 *
 * Nothing here is a rule of the addressing plan: the server checks what is
 * sent, whatever was proposed.
 */
export function useSubnetProposal(options: Options): {
    // Whether the address and the prefix length are the ones proposed.
    addressIsProposed: ComputedRef<boolean>;
    gatewayIsProposed: ComputedRef<boolean>;
    // Why nothing can be proposed, when there is a rule and it says nothing
    // for this site or this VID.
    reason: ComputedRef<string | null>;
} {
    const proposal = computed<ProposedFields | null>(() => {
        const rule = options.rule();
        const site = options.site();
        const vid = options.vid();

        if (rule === null || site === null || vid === null) {
            return null;
        }

        const subnet = vlanSubnet(rule, site.number, vid);

        return subnet === null
            ? null
            : {
                  network: subnet.network,
                  prefix_length: subnet.prefix_length,
                  gateway:
                      gatewayOf(rule, subnet.network, subnet.prefix_length) ??
                      '',
              };
    });

    const reason = computed<string | null>(() => {
        const rule = options.rule();
        const site = options.site();
        const vid = options.vid();

        if (
            rule === null ||
            site === null ||
            vid === null ||
            proposal.value !== null
        ) {
            return null;
        }

        // Without a level of sites, the number of the site counts for
        // nothing: only the VID is out of reach.
        if (hasSiteLevel(rule)) {
            if (site.number === null) {
                return trans(
                    'The site :site has no number: the rule proposes nothing',
                    { site: site.name },
                );
            }

            if (!allowsSiteNumber(rule, site.number)) {
                return trans(
                    'The rule does not cover the site number :number',
                    { number: site.number },
                );
            }
        }

        return trans('The rule does not cover the VID :vid', { vid });
    });

    // What the rule last wrote in the fields. A field that no longer holds
    // it was changed by hand, and is left alone from then on.
    const writtenAddress = ref<{
        network: string;
        prefix_length: number | null;
    } | null>(null);
    const writtenGateway = ref<string | null>(null);

    const holdsAddress = (): boolean => {
        const fields = options.read();

        return writtenAddress.value === null
            ? fields.network.trim() === ''
            : fields.network === writtenAddress.value.network &&
                  fields.prefix_length === writtenAddress.value.prefix_length;
    };

    const holdsGateway = (): boolean => {
        const fields = options.read();

        return writtenGateway.value === null
            ? fields.gateway.trim() === ''
            : fields.gateway === writtenGateway.value;
    };

    watch(
        proposal,
        (next) => {
            const values: Partial<ProposedFields> = {};

            if (holdsAddress()) {
                // Nothing to propose any more: what was proposed goes.
                if (next !== null || writtenAddress.value !== null) {
                    values.network = next?.network ?? '';
                    values.prefix_length =
                        next?.prefix_length ?? options.defaultPrefix;
                }

                writtenAddress.value =
                    next === null
                        ? null
                        : {
                              network: next.network,
                              prefix_length: next.prefix_length,
                          };
            } else {
                writtenAddress.value = null;
            }

            if (holdsGateway()) {
                if (next !== null || writtenGateway.value !== null) {
                    values.gateway = next?.gateway ?? '';
                }

                writtenGateway.value = next === null ? null : next.gateway;
            } else {
                writtenGateway.value = null;
            }

            options.write(values);
        },
        { immediate: true },
    );

    return {
        addressIsProposed: computed<boolean>(
            () => writtenAddress.value !== null && holdsAddress(),
        ),
        gatewayIsProposed: computed<boolean>(
            () =>
                writtenGateway.value !== null &&
                writtenGateway.value !== '' &&
                holdsGateway(),
        ),
        reason,
    };
}
