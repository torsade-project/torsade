<?php

// torsade/app/Enums/DeviationKind.php

namespace App\Enums;

/**
 * The ways what is documented can differ from the standard of the
 * organisation, its VLAN catalogue, its address blocks and its addressing
 * rule, or from what is expected of it: an interface of a device on the
 * network of another site, the two ends of a link that do not agree. A
 * deviation is worked out when a page is shown; it is never stored and
 * never refuses anything, because the network is documented as it is.
 */
enum DeviationKind: string
{
    /** A VLAN whose number is not the one of its entry of the catalogue. */
    case VlanVid = 'vlan_vid';

    /** A VLAN whose name is not the one of its entry, whatever the case. */
    case VlanName = 'vlan_name';

    /** A subnet of a site, inside a block of another site. */
    case SubnetInBlockOfAnotherSite = 'subnet_in_block_of_another_site';

    /** A subnet of a site that has blocks, outside all of them. */
    case SubnetOutsideSiteBlocks = 'subnet_outside_site_blocks';

    /** A subnet without a site, inside the block of a site. */
    case SubnetWithoutSiteInSiteBlock = 'subnet_without_site_in_site_block';

    /** A site whose number is beyond what the addressing rule reaches. */
    case SiteNumberOutsideRule = 'site_number_outside_rule';

    /** A numbered site that has not declared the block the rule expects. */
    case SiteBlockMissing = 'site_block_missing';

    /** A segment carried by a VLAN that is not the one the rule expects. */
    case SubnetDiffersFromRule = 'subnet_differs_from_rule';

    /** An address attached to a device of another site than its subnet. */
    case AddressOnDeviceOfAnotherSite = 'address_on_device_of_another_site';

    /** A switch whose ports carry two different VLANs under one number. */
    case SwitchMergesVlans = 'switch_merges_vlans';

    /** A link between an access port and a trunk. */
    case LinkModes = 'link_modes';

    /** A link between two access ports on different VLANs. */
    case LinkAccessVlans = 'link_access_vlans';

    /** A link between two trunks whose native VLAN differs. */
    case LinkNativeVlans = 'link_native_vlans';

    /** A link between two trunks that do not tag the same VLANs. */
    case LinkTaggedVlans = 'link_tagged_vlans';

    /** A link whose two ends carry two different VLANs under one number. */
    case LinkMergesVlans = 'link_merges_vlans';

    /** An aggregate whose links lead to several devices. */
    case AggregateSeveralDevices = 'aggregate_several_devices';

    /** An aggregate facing ports that are not one same aggregate. */
    case AggregateOneSided = 'aggregate_one_sided';

    /** An active link on a port that is shut down. */
    case LinkOnDisabledPort = 'link_on_disabled_port';

    /** A cord between a panel and a device kept in two different rooms. */
    case CordAcrossRooms = 'cord_across_rooms';

    /** Two ports cabled to each other through a jack, neither of them linked. */
    case CablingWithoutLink = 'cabling_without_link';

    /** A link one of whose ends is cabled to another port than its own. */
    case LinkContradictedByCabling = 'link_contradicted_by_cabling';

    /** A retired link whose two ports are still cabled to each other. */
    case CablingOfRetiredLink = 'cabling_of_retired_link';

    /** A linked port patched to a jack where nothing is plugged. */
    case LinkedWithoutPlug = 'linked_without_plug';

    /** An active address plugged into a jack that is not patched. */
    case AddressOnUnpatchedJack = 'address_on_unpatched_jack';

    /** An address whose VLAN the port at the end of its jack does not carry. */
    case AddressVlanNotCarried = 'address_vlan_not_carried';
}
