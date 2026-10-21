// torsade/resources/js/types/network.ts
import type { AddressingRuleParams } from '@/lib/addressingRule';

// What the server sends about the network documentation. Every shape is an
// explicit list built by the server, never a whole database record.

export type Option<T extends string | number = string> = {
    value: T;
    label: string;
};

export type StatusValue = 'planned' | 'active' | 'retired';

export type Site = {
    id: number;
    name: string;
    // A short code used in the names of devices, in capitals.
    code: string | null;
    // The rank of the site in the addressing rule of the organisation.
    number: number | null;
    // A site is planned, in service or closed, like the objects of its
    // network.
    status: StatusValue;
    status_label: string;
    description: string | null;
    vlans_count: number;
    // The blocks of the site in CIDR notation, where the page needs them.
    blocks: string[];
};

export type SiteOption = {
    id: number;
    name: string;
};

export type SiteFilters = {
    status: string;
};

// A site and its number, the rank the addressing rule gives it: the forms
// work out from it what the rule proposes.
export type NumberedSite = SiteOption & {
    number: number | null;
};

// Where what is documented differs from the standard of the organisation
// (its VLAN catalogue, its address blocks), with the reason in words. It is
// worked out by the server and never refuses anything.
export type Deviation = {
    kind: string;
    reason: string;
    // What lifts it, when something can: the aggregate missing in front of
    // another one, created from the ports in front on that device; the link
    // between two ports cabled to each other; the port in front of a link
    // plugged into the jack its own port is patched to.
    action?:
        | {
              type: 'create_opposite_aggregate';
              aggregate: number;
              device: number;
          }
        | {
              type: 'create_link';
              device: number;
              port: number;
              opposite_device: number;
              opposite_port: number;
          }
        | {
              type: 'plug';
              jack: number;
              port: number;
          };
};

// One line of the deviations of a page: an object and what differs
// about it.
export type DeviationItem = {
    type: 'vlan' | 'subnet';
    id: number;
    label: string;
    deviations: Deviation[];
};

// An entry of the VLAN catalogue, as a choice in a list.
export type CatalogOption = {
    id: number;
    vid: number;
    name: string;
};

// The entries that already have the number or the name just given to an
// entry: the server sends them with its refusal, so that the form can lead
// to them.
export type CatalogConflict = {
    vid: CatalogOption | null;
    name: CatalogOption | null;
};

// A VLAN attached to an entry of the catalogue, on the site it belongs to.
export type CatalogVlan = {
    id: number;
    site_id: number;
    vid: number;
    name: string;
    status: StatusValue;
    status_label: string;
    deviations: Deviation[];
};

export type CatalogEntry = {
    id: number;
    vid: number;
    name: string;
    description: string | null;
    // One per site at most.
    vlans: CatalogVlan[];
};

export type CatalogSite = SiteOption & {
    code: string | null;
};

// A VLAN that could be attached to an entry of the catalogue.
export type CatalogCandidate = {
    id: number;
    vid: number;
    name: string;
    status: StatusValue;
    status_label: string;
    site: SiteOption;
};

// A subnet with what is derived from its address and prefix length.
export type Subnet = {
    id: number;
    cidr: string;
    network: string;
    prefix_length: number;
    kind: SubnetKindValue;
    kind_label: string;
    mask: string;
    broadcast: string;
    first_usable: string;
    last_usable: string;
    gateway: string | null;
    name: string | null;
    description: string | null;
    status: StatusValue;
    status_label: string;
    usable_count: number;
    documented_count: number;
    // Left out where the subnet is shown inside its VLAN.
    vlan?: { id: number; vid: number; name: string; site: string } | null;
    // The site of its VLAN when it has one, its own otherwise.
    site?: SiteOption | null;
};

export type SubnetKindValue =
    | 'segment'
    | 'point_to_point'
    | 'loopback'
    | 'tunnel'
    | 'external';

// A subnet with the ways it is not where the address blocks say.
export type PlacedSubnet = Subnet & {
    deviations: Deviation[];
};

// A block of addresses of the organisation, or of a site when it has one.
export type AddressBlock = {
    id: number;
    cidr: string;
    network: string;
    prefix_length: number;
    mask: string;
    last_address: string;
    // Number of addresses, from the first to the last.
    size: number;
    name: string | null;
    description: string | null;
    status: StatusValue;
    status_label: string;
    site: SiteOption | null;
};

// A block in the list, where blocks are nested by their addresses.
export type AddressBlockNode = AddressBlock & {
    // How many blocks of the organisation are around it.
    depth: number;
    // Addresses that belong to a subnet.
    occupied: number;
};

export type EditableAddressBlock = {
    id: number;
    cidr: string;
    network: string;
    prefix_length: number;
    site_id: number | null;
    name: string | null;
    description: string | null;
    status: StatusValue;
};

// A prefix that belongs to a site: one of its segments, or one of its
// blocks. The form of a block counts with them what the block being typed
// would hold.
export type SitePrefix = {
    site_id: number;
    network: string;
    prefix_length: number;
};

export type SiteBlockPrefix = SitePrefix & {
    id: number;
};

// The addressing rule in force, as its page gets it: what it works out
// from, and the block it starts from.
export type AddressingRulePage = AddressingRuleParams & {
    aggregate_block_id: number;
    // The block of the organisation, in CIDR notation.
    block: string;
    gateway_label: string;
};

// A block of the organisation, which an addressing rule can start from.
export type OrganisationBlockOption = {
    id: number;
    cidr: string;
    network: string;
    prefix_length: number;
    name: string | null;
};

// How the addressing rule applies to a site: the block it expects from the
// number of the site, and whether the site has declared it.
export type RuleApplicationRow = {
    id: number;
    name: string;
    number: number | null;
    expected_block: string | null;
    state: 'declared' | 'missing' | 'outside' | 'unnumbered';
    // The block of the site that is the expected one, when it is declared.
    block_id: number | null;
    deviation: Deviation | null;
};

// How much of a block its subnets take, and what is left of it.
export type BlockSpace = {
    size: number;
    occupied: number;
    free: number;
    // Every free prefix is counted; only the first ones are listed.
    free_prefix_count: number;
    free_prefixes: { cidr: string; size: number }[];
};

export type Vlan = {
    id: number;
    vid: number;
    name: string;
    description: string | null;
    status: StatusValue;
    status_label: string;
    site: SiteOption;
    // The entry of the catalogue the VLAN follows, and how it differs from it.
    catalog: CatalogOption | null;
    deviations: Deviation[];
    subnet: Subnet | null;
};

export type VlanFilters = {
    site: string;
    status: string;
    // The identifier of an entry, or "none" for the VLANs that follow none.
    catalog: string;
};

export type EditableVlan = {
    id: number;
    site_id: number;
    catalog_vlan_id: number | null;
    vid: number;
    name: string;
    description: string | null;
    status: StatusValue;
};

// A VLAN a subnet can be attached to, with the site it gives the subnet.
export type VlanOption = {
    id: number;
    label: string;
    // What the addressing rule numbers a subnet by: the VID, and the
    // number of the site of the VLAN.
    vid: number;
    site: string;
    site_id: number;
    site_number: number | null;
};

// A range of the addressing rule as it would be created in a subnet, shown
// before the standard breakdown is applied.
export type BreakdownRange = {
    kind: RangeKindValue;
    kind_label: string;
    start_address: string;
    end_address: string;
    name: string | null;
    size: number;
    // Whether both ends are usable addresses of the subnet.
    fits: boolean;
};

// A kind of subnet, with what it accepts: the form hides what it excludes.
export type SubnetKindOption = Option<SubnetKindValue> & {
    allows_vlan: boolean;
    allows_gateway: boolean;
    allows_ranges: boolean;
    prefix_lengths: number[];
};

export type SubnetFilters = {
    // The identifier of a site, or "none" for the subnets that have none.
    site: string;
    kind: string;
};

export type EditableSubnet = {
    id: number;
    cidr: string;
    network: string;
    prefix_length: number;
    kind: SubnetKindValue;
    vlan_id: number | null;
    site_id: number | null;
    gateway: string | null;
    name: string | null;
    description: string | null;
    status: StatusValue;
};

export type RangeKindValue = 'dhcp' | 'static' | 'reserved';

// A range of addresses of a subnet, set aside for one use.
export type IpRange = {
    id: number;
    kind: RangeKindValue;
    kind_label: string;
    name: string | null;
    start_address: string;
    end_address: string;
    size: number;
    description: string | null;
};

export type IpAddress = {
    id: number;
    address: string;
    hostname: string | null;
    mac_address: string | null;
    device_type: string | null;
    device_type_label: string | null;
    assignment: string;
    assignment_label: string;
    status: StatusValue;
    status_label: string;
    description: string | null;
    is_gateway: boolean;
    // The device this address is an interface of, if any.
    device: { id: number; name: string; site: string } | null;
    // The wall jack its machine is plugged into, if any.
    jack: AddressJack | null;
    // Whether that device is on another site than the subnet.
    deviations: Deviation[];
};

// The wall jack an address is plugged into, with its path.
export type AddressJack = {
    id: number;
    label: string;
    // The building and the floor of the jack.
    place: string;
    path: JackPathData;
};

// The jacks the field "Jack" of the window of an address offers for what
// was typed. The server lists the first ones only.
export type JackChoices = {
    items: { id: number; label: string; place: string; site: string }[];
    // Whether there are more than those listed.
    more: boolean;
};

// A room as a place is always written: with its path, "Main building ›
// Ground floor › Technical room".
export type Place = {
    id: number;
    name: string;
    path: string;
    is_technical: boolean;
};

// A room as a choice in the form of a device, which shows those of the
// site chosen.
export type RoomOption = Place & {
    site_id: number;
};

// The places of a site, as a tree: buildings, their floors from the lowest
// up, and the rooms of each floor.
export type PlaceRoom = {
    id: number;
    name: string;
    is_technical: boolean;
    description: string | null;
    devices_count: number;
    // The patch panels kept in the room, by name.
    panels: PatchPanelSummary[];
};

// A patch panel in a few words, as the room it is kept in lists it.
export type PatchPanelSummary = {
    id: number;
    name: string;
    port_count: number;
    // Its ports that have a jack or a cord.
    occupied: number;
    connector: string;
    connector_label: string;
    // "6A", when it is known.
    category: string | null;
    status: StatusValue;
    status_label: string;
    description: string | null;
};

// A patch panel with the room it is kept in and its site.
export type PatchPanel = PatchPanelSummary & {
    room: Place;
    site: SiteOption;
};

// A port of a patch panel: the jack whose horizontal cable ends there, and
// the cord that leaves from it towards a port of a device.
export type PanelPort = {
    id: number;
    number: number;
    jack: {
        id: number;
        label: string;
        // The building and the floor of the jack.
        place: string;
        location: string | null;
        status: StatusValue;
        status_label: string;
    } | null;
    cord: {
        id: number;
        color: string | null;
        description: string | null;
        device: { id: number; name: string };
        port: { id: number; name: string; enabled: boolean };
        // What the port of the device carries; null without a mode.
        configuration: string | null;
    } | null;
    deviations: Deviation[];
};

export type PlaceFloor = {
    id: number;
    level: number;
    name: string;
    description: string | null;
    jacks_count: number;
    rooms: PlaceRoom[];
};

// A patch panel of a site with its ports that have no jack: what a jack of
// the site can be cabled to.
export type PanelChoice = {
    id: number;
    name: string;
    // The room it is kept in, with its building and its floor.
    place: string;
    port_count: number;
    free: { id: number; number: number }[];
};

// A wall jack as its window modifies it.
export type EditableJack = {
    id: number;
    label: string;
    location: string | null;
    status: StatusValue;
    description: string | null;
    // The port of a panel its horizontal cable ends on.
    panel_port: {
        id: number;
        number: number;
        panel: { id: number; name: string };
    } | null;
};

// A jack of the site of a panel that is not cabled yet.
export type FreeJack = {
    id: number;
    label: string;
    location: string | null;
};

// The jacks of a floor without a horizontal cable, under the path of the
// floor: "Bâtiment principal › 1er étage".
export type FreeJackGroup = {
    floor: number;
    place: string;
    jacks: FreeJack[];
};

// A device of the site of a panel, which a port of the panel is patched to.
export type PatchDevice = {
    id: number;
    name: string;
    // The room it is kept in, with where it is; null without a room.
    place: string | null;
};

// A port of a device as the window "Patch" offers it, in the order of the
// device.
export type DevicePortChoice = {
    id: number;
    name: string;
    // The position of its member.
    member: number;
    // What it is already patched to: "PB-A 3 (B.03)".
    cord: string | null;
    // What it is linked to: "sw-coeur-01 › 1/0/49".
    link: string | null;
    // The jack it is plugged into, "B.07": a port has one connector, it
    // is then patched nowhere.
    plugged: string | null;
    // What it carries; null without a mode.
    configuration: string | null;
};

// The path of a jack towards a device, which stops where nothing is
// declared: no port of a panel, or no cord on that port.
export type JackPathData = {
    jack: {
        id: number;
        label: string;
        // The building and the floor of the jack.
        place: string;
    };
    // The device plugged into the jack, on the side of the desk, told
    // before the path: "Plugged: ap-salle-reunion 1".
    plugged: {
        cord_id: number;
        device: { id: number; name: string };
        port: { id: number; name: string };
    } | null;
    // "PB-A 15".
    panel_port: { id: number; name: string; panel_id: number } | null;
    device: { id: number; name: string } | null;
    port: { id: number; name: string } | null;
    // What the port of the device carries; null without a mode.
    configuration: string | null;
};

// A jack as the list of jacks shows it.
export type JackLine = EditableJack & {
    status_label: string;
    site: SiteOption;
    path: JackPathData;
    // The cord of the device plugged into it, if any.
    cord: JackCordValues | null;
    // The addresses plugged into it, in their order.
    addresses: {
        id: number;
        address: string;
        hostname: string | null;
        subnet_id: number;
        status: StatusValue;
    }[];
    deviations: Deviation[];
};

// The filters of the list of jacks, as its address carries them.
export type JackFilters = {
    site: string;
    building: string;
    floor: string;
    status: string;
    // "1" when only the jacks with a deviation are listed.
    deviations: string;
    search: string;
};

// What a jack cord is, besides its two ends.
export type JackCordValues = {
    id: number;
    color: string | null;
    description: string | null;
};

// A jack of a site a port can be plugged into, with its path towards the
// panel and the switch.
export type PluggableJack = {
    id: number;
    label: string;
    location: string | null;
    path: JackPathData;
};

// The jacks of a floor a port can be plugged into, under the path of the
// floor: "Bâtiment principal › 1er étage".
export type PluggableJackGroup = {
    floor: number;
    place: string;
    jacks: PluggableJack[];
};

// A device of the site of a jack, which can be plugged into it.
export type PlugDevice = {
    id: number;
    name: string;
    // The room it is kept in, with where it is; null without a room.
    place: string | null;
    // How many of its ports have no connector yet.
    free_ports: number;
};

// The ports of the device chosen that have no connector, in its order.
export type PlugPorts = {
    device: { id: number; name: string };
    ports: {
        id: number;
        name: string;
        member: number;
        configuration: string | null;
    }[];
};

// A site with its buildings and the floors of each, for the filters of the
// list of jacks.
export type JackPlaceOption = {
    id: number;
    name: string;
    buildings: {
        id: number;
        name: string;
        floors: { id: number; name: string }[];
    }[];
};

export type PlaceBuilding = {
    id: number;
    name: string;
    code: string | null;
    description: string | null;
    floors: PlaceFloor[];
};

// A machine of a site, with the room it is kept in and how many addresses
// it has.
export type Device = {
    id: number;
    name: string;
    type: string;
    type_label: string;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    description: string | null;
    status: StatusValue;
    status_label: string;
    site: SiteOption;
    room: Place | null;
    addresses_count: number;
};

// One of the boxes a device is made of: a switch alone has one member, a
// stack several. The ports belong to the members.
export type DeviceMember = {
    id: number;
    // Its number in the device.
    position: number;
    role: 'primary' | 'secondary' | 'member';
    role_label: string;
    // The template its ports were copied from, if any.
    template: { id: number; name: string } | null;
    serial_number: string | null;
    // Null: the member is where its device is.
    room: Place | null;
    description: string | null;
    ports_count: number;
};

// The lowest and the highest number a member can have: the server
// enforces them.
export type MemberLimits = {
    min: number;
    max: number;
};

// A template as a choice in the window of a member, with what names the
// ports it gives.
export type TemplateChoice = {
    id: number;
    name: string;
    ports_count: number;
    port_groups: {
        quantity: number;
        name_pattern: string;
        first_number: number;
    }[];
};

// How a port, or an aggregate of ports, carries VLANs.
export type PortModeValue = 'access' | 'trunk';

// A VLAN, as a choice among those a port carries.
export type VlanChoice = {
    id: number;
    vid: number;
    name: string;
};

// The VLANs of one site a port can carry, by number. A device offers the
// VLANs of its own site first, then those of the other sites, by name.
export type VlanGroup = {
    site: { id: number; name: string };
    vlans: VlanChoice[];
};

// The ports and the aggregates of one device that carry a VLAN, as the
// page of the VLAN lists them.
export type VlanCarrierGroup = {
    device: { id: number; name: string };
    carriers: {
        kind: 'port' | 'aggregate';
        name: string;
        // The number of the member of a port; null for an aggregate.
        member: number | null;
        role: 'untagged' | 'voice' | 'native' | 'tagged';
        role_label: string;
    }[];
};

// A port of a member of a device, as the table of the device shows it.
export type Port = {
    id: number;
    member_id: number;
    // As the device writes it: "1/0/18", "Gi2/0/1".
    name: string;
    // Its place in the order of its member.
    position: number;
    connector: string;
    connector_label: string;
    max_speed: string;
    max_speed_label: string;
    poe_type: string;
    poe_type_label: string;
    // The word of the manufacturer for what the port powers, if any.
    poe_label: string | null;
    // False: the port is shut down.
    enabled: boolean;
    mode: PortModeValue | null;
    // The mode and the VLANs in a few words; null without a mode.
    summary: string | null;
    untagged_vlan_id: number | null;
    tagged_vlan_ids: number[];
    // The aggregate the port is part of, if any.
    aggregate: { id: number; name: string } | null;
    description: string | null;
    // What the port is linked to, if it is: the device and the port at the
    // other end of its link.
    link: {
        id: number;
        device: { id: number; name: string };
        port: string;
    } | null;
    // What the port is patched to, if it is: the port of a panel at the
    // other end of its cord, and the jack of that port.
    cord: {
        id: number;
        panel_port: { id: number; name: string; panel_id: number };
        jack: { id: number; label: string } | null;
    } | null;
    // The jack it is plugged into, if it is: "plugged into B.07", the
    // whole place of the jack in the tooltip.
    jack_cord:
        | (JackCordValues & {
              jack: {
                  id: number;
                  label: string;
                  // "Bâtiment principal › 1er étage › B.07".
                  path: string;
                  floor_id: number;
              };
          })
        | null;
    // How its cabling differs from what is declared around it.
    deviations: Deviation[];
};

// The window "Link…" of a port opened from the deviation of a cabling
// without a link: the port in front, cabled to it, is chosen.
export type LinkDraft = {
    port_id: number;
    opposite: {
        device: { id: number; name: string; site: string };
        port_id: number;
    };
};

// Several ports of a device used as one link. Its mode and its VLANs are
// set on the aggregate: its ports have none of their own.
// The window of a new aggregate in front of another one: the ports in
// front, the protocol, the mode and the VLANs of the aggregate in front, and
// a name in the series of the device.
export type AggregateDraft = {
    // The aggregate in front, with its device: "sw-serveurs › Po1".
    origin: string;
    name: string;
    protocol: 'lacp' | 'static';
    mode: PortModeValue | null;
    untagged_vlan_id: number | null;
    tagged_vlan_ids: number[];
    port_ids: number[];
};

export type PortAggregate = {
    id: number;
    // As the device writes it: "Po1", "lag 1".
    name: string;
    protocol: 'lacp' | 'static';
    protocol_label: string;
    mode: PortModeValue | null;
    // The mode and the VLANs in a few words; null without a mode.
    summary: string | null;
    untagged_vlan_id: number | null;
    tagged_vlan_ids: number[];
    description: string | null;
    // Where its links do not lead to one same aggregate of one device.
    deviations: Deviation[];
};

// One end of a link between two devices: a port, with its device and the
// aggregate it is part of, if any.
export type LinkEnd = {
    device: { id: number; name: string; site: SiteOption };
    port: { id: number; name: string; enabled: boolean };
    aggregate: { id: number; name: string } | null;
};

// A link between two ports of two devices. On the page of a device, the
// near end is the port of that device; elsewhere, its end 1.
export type DeviceLink = {
    id: number;
    // What carries it, when it is known.
    medium: string | null;
    medium_label: string | null;
    status: StatusValue;
    status_label: string;
    description: string | null;
    near: LinkEnd;
    far: LinkEnd;
    // Its cabled path, when the cabling confirms it: "through PB-B 13 ›
    // B.13".
    path: CabledPath | null;
    // Where its two ends do not agree.
    deviations: Deviation[];
};

// The physical path of a link: the port patched to a panel, the port of the
// panel and the jack where the port in front is plugged.
export type CabledPath = {
    port_id: number;
    panel_port: {
        id: number;
        name: string;
        panel_id: number;
        // The room of the panel, with where it is.
        place: string;
    };
    jack: {
        id: number;
        label: string;
        floor_id: number;
        // The building and the floor of the jack.
        place: string;
    };
};

// A line of a table of links: one link, or the links between the ports of
// two aggregates, shown together as one aggregated link.
export type LinkLine = {
    key: string;
    aggregated: boolean;
    links: DeviceLink[];
    // What differs about the line, each deviation told once.
    deviations: Deviation[];
};

// The filters of the list of every link, as the address of the page
// keeps them.
export type LinkFilters = {
    site: string;
    status: string;
    // "1" when only the links with a deviation are listed.
    deviations: string;
};

// What differs about a link or an aggregate of a device, for the list of
// the deviations of its page.
export type LinkDeviationItem = {
    key: string;
    label: string;
    // The section of the page that shows the object.
    section: 'links' | 'aggregates' | 'ports';
    deviations: Deviation[];
};

// The devices a port can be linked to, as the window "Link…" lists them
// for what was typed. The server lists the first ones only.
export type LinkableDevices = {
    items: {
        id: number;
        name: string;
        site: string;
        type_label: string;
        // How many of its ports no link uses yet.
        free_ports: number;
    }[];
    more: boolean;
};

// The ports of the opposite device that no link uses yet, in the order of
// the device, each with the summary of its configuration.
export type OppositePorts = {
    device: { id: number; name: string; site: string };
    ports: {
        id: number;
        name: string;
        // The number of its member.
        member: number;
        enabled: boolean;
        // The name of its aggregate, if any: the summary is then the one
        // of the aggregate.
        aggregate: string | null;
        summary: string | null;
    }[];
};

// What the windows of ports choose among, and the limits they show: the
// server enforces them.
export type PortOptions = {
    connectors: Option[];
    speeds: Option[];
    poeTypes: Option[];
    limits: {
        name: number;
        position: number;
        first_number: number;
        quantity: number;
    };
};

export type DeviceFilters = {
    site: string;
    type: string;
    status: string;
    search: string;
};

export type EditableDevice = {
    id: number;
    site_id: number;
    room_id: number | null;
    name: string;
    type: string;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    status: StatusValue;
    description: string | null;
    // Each member then has its own serial number: the form asks for none.
    has_members: boolean;
};

// A device as a choice in the window of an address.
export type DeviceOption = {
    id: number;
    name: string;
    site_id: number;
    site: string;
};

// An address as an interface of its device: where it is.
export type DeviceAddress = {
    id: number;
    address: string;
    hostname: string | null;
    status: StatusValue;
    status_label: string;
    subnet: { id: number; cidr: string; site: string | null };
    vlan: { id: number; vid: number; name: string } | null;
    // The wall jack its machine is plugged into, if any.
    jack: AddressJack | null;
    deviations: Deviation[];
};

// A subnet as a choice in the window of a new address of a device.
export type SubnetChoice = {
    id: number;
    cidr: string;
    // What tells it from another: its VLAN, or its own name.
    label: string | null;
    first_usable: string;
    last_usable: string;
    site_id: number | null;
    site: string | null;
};

// The addresses that belong to no device and match what was typed in the
// window "Attach an address". The server lists the first ones only.
export type AttachableAddresses = {
    items: {
        id: number;
        address: string;
        hostname: string | null;
        subnet: string;
        site: string | null;
    }[];
    more: boolean;
};

// Ports of a device template that are alike: so many of them, named after
// one pattern, with the same connector, speed and power.
export type PortGroup = {
    quantity: number;
    // "{m}" is the number of the member, "{n}" the number of the port.
    name_pattern: string;
    first_number: number;
    connector: string;
    max_speed: string;
    poe_type: string;
    poe_label: string | null;
};

// What a kind of machine is made of, and how many members of devices were
// made from it.
export type DeviceTemplate = {
    id: number;
    name: string;
    device_type: string;
    device_type_label: string;
    manufacturer: string | null;
    model: string | null;
    poe_budget_watts: number | null;
    description: string | null;
    ports_count: number;
    members_count: number;
    port_groups: PortGroup[];
};

// The limits the form of a template shows: the server enforces them.
export type TemplateLimits = {
    groups: number;
    quantity: number;
    first_number: number;
    pattern: number;
};

// A host name of the addresses of a site, ready to become a device, or to
// join the device of the site that already bears it.
export type HostnameCandidate = {
    hostname: string;
    addresses_count: number;
    // The first addresses that bear the name.
    addresses: string[];
    // The type and the status proposed for the device.
    type: string;
    status: StatusValue;
    device: {
        id: number;
        name: string;
        type_label: string;
        status_label: string;
    } | null;
    // Whether the name is longer than the name of a device can be.
    too_long: boolean;
};

// Free addresses are always handled as intervals, never one by one: a /8
// holds more than 16 million of them.
export type FreeInterval = {
    start: string;
    end: string;
    count: number;
    // The kind of the range the interval lies in, with the name of that
    // range, or null outside of every range. A DHCP range holds no free
    // address.
    kind: 'static' | 'reserved' | null;
    range: string | null;
};

// The address to document next, and where it was taken from: a static
// range, or outside of every range.
export type NextFreeAddress = {
    address: string;
    kind: 'static' | null;
    range: string | null;
};

// What one address is on the address plan of its subnet. An address has
// one state only: the server picks it.
export type AddressState =
    | 'unusable'
    | 'gateway'
    | 'planned'
    | 'retired'
    | 'reservation'
    | 'documented'
    | 'dhcp_range'
    | 'reserved_range'
    | 'free';

export type AddressPlan = {
    // Number of addresses of the subnet, network and broadcast included.
    size: number;
    // The largest subnet that gets a plan.
    limit: number;
    // One state per address, from the network address on; null when the
    // subnet is larger than the limit.
    cells: AddressState[] | null;
};

// The physical diagram of a site, as the server works it out: its devices,
// where they are kept, the ports that are linked or cabled, the links
// between them and the patch panels they go through, in the order of their
// names. The page lays it out and draws it.
export type DiagramDevice = {
    // Unique in the diagram: "device-12".
    key: string;
    id: number;
    name: string;
    type: string;
    type_label: string;
    // A workstation, a laptop, a printer, a telephone or a camera: what
    // the page can hide with its connections.
    end_device: boolean;
    planned: boolean;
    // The site of a device of another site, drawn at the edge; null for a
    // device of the site.
    site: { id: number; name: string } | null;
    // How many members it has: a stack has two or more.
    members: number;
    // The key of its room, "unplaced" without one; null at the edge.
    place: string | null;
    // Its ports that are linked or cabled, by name: a port, or an
    // aggregate for an aggregated link.
    ports: { key: string; name: string }[];
    deviations: Deviation[];
};

// A patch panel a connection drawn goes through, in the frame of its room,
// with the ports used only, named by their numbers.
export type DiagramPanel = {
    // Unique in the diagram: "patch_panel-3".
    key: string;
    id: number;
    name: string;
    planned: boolean;
    place: string;
    ports: { key: string; name: string }[];
};

// A building, a floor, a room, or the frame of the devices kept in no room.
export type DiagramPlace = {
    key: string;
    kind: 'building' | 'floor' | 'room' | 'unplaced';
    name: string;
    technical: boolean;
    children: DiagramPlace[];
};

// A line drawn, read from its source down to its target: a link from one
// port to the other, the links between two aggregates being one; or a
// segment of a connection that has a cabled path, from the port of a device
// to the port of a panel ("rack"), then from there to the device plugged in
// ("office"), which the jack names.
export type DiagramLink = {
    key: string;
    // The connection the line is part of: its own key for a direct line.
    connection: string;
    segment: 'direct' | 'rack' | 'office';
    source: string;
    // Null at a device cabled by its address: the line ends on its edge.
    source_port: string | null;
    target: string;
    target_port: string | null;
    // The two ends of the connection in words: "sw-coeur-01 › 1/0/1".
    ends: [string, string];
    // "Po1 ↔ Po2 · 2 links" for an aggregated link; null otherwise.
    label: string | null;
    // The jack an office segment goes through: "B.15".
    jack: string | null;
    // The cabled path of a connection: "PB-B 15 › B.15".
    through: string | null;
    // The cabled path of each link of an aggregated line.
    paths: string[];
    // How many links the line stands for: none for a cabling without one.
    links_count: number;
    medium: string | null;
    medium_label: string | null;
    fibre: boolean;
    planned: boolean;
    deviations: Deviation[];
};

export type PhysicalDiagramData = {
    site: { id: number; name: string; code: string | null };
    devices: DiagramDevice[];
    panels: DiagramPanel[];
    places: DiagramPlace[];
    links: DiagramLink[];
    summary: {
        // The devices drawn, end devices left out, then the end devices.
        devices: number;
        end_devices: number;
        // The devices of the site not drawn: no link nor cabling to a
        // device drawn, and not of a type that makes the network.
        undrawn: number;
        links: number;
        deviations: number;
    };
};

// The logical diagram of a site, as the server works it out: its subnets,
// the internet, and the devices plugged into the subnets, the subnets in
// the order of their addresses, the devices in the order of their names.
// Each node has the rank of its row, from the top of the diagram down.
export type LogicalSubnet = {
    // Unique in the diagram: "subnet-4".
    key: string;
    id: number;
    cidr: string;
    name: string | null;
    kind: string;
    kind_label: string;
    rank: number;
    planned: boolean;
    vlan: { id: number; vid: number; name: string } | null;
    gateway: string | null;
    // The addresses documented there that belong to no device drawn, and
    // their number in words: "37 other addresses"; null without any.
    others: number;
    others_label: string | null;
    deviations: Deviation[];
};

export type LogicalDevice = {
    // Unique in the diagram: "device-12".
    key: string;
    id: number;
    name: string;
    type: string;
    type_label: string;
    rank: number;
    planned: boolean;
    // The site of a device of another site, drawn at the edge; null for a
    // device of the site.
    site: { id: number; name: string } | null;
    members: number;
    deviations: Deviation[];
};

// A device plugged into a subnet, or the internet linked to an external
// subnet, read from its source down to its target.
export type LogicalConnection = {
    key: string;
    kind: 'internet' | 'device';
    source: string;
    target: string;
    // The subnet, and the device, by their keys.
    subnet: string;
    device: string | null;
    // The addresses of the device in the subnet, the gateway marked.
    addresses: { address: string; gateway: boolean }[];
    // How the device carries the VLAN of the subnet, in words; null when
    // it does not.
    carried: string | null;
};

export type LogicalDiagramData = {
    site: { id: number; name: string; code: string | null };
    // Drawn with an external subnet only.
    internet: { key: string; rank: number } | null;
    subnets: LogicalSubnet[];
    devices: LogicalDevice[];
    connections: LogicalConnection[];
    summary: {
        subnets: number;
        devices: number;
        connections: number;
        deviations: number;
    };
};
