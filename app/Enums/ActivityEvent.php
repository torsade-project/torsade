<?php

// torsade/app/Enums/ActivityEvent.php

namespace App\Enums;

/**
 * Every kind of entry the activity log can hold.
 */
enum ActivityEvent: string
{
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';

    // Decided by an administrator for another account.
    case TwoFactorRequired = 'user.two_factor_required';
    case TwoFactorNoLongerRequired = 'user.two_factor_not_required';
    case TwoFactorReset = 'user.two_factor_reset';

    case LoginSucceeded = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case LoggedOut = 'auth.logout';
    case PasswordChanged = 'auth.password_changed';
    case PasswordReset = 'auth.password_reset';

    // Done by the user on their own account.
    case TwoFactorEnabled = 'auth.two_factor_enabled';
    case TwoFactorDisabled = 'auth.two_factor_disabled';

    case CommandExecuted = 'console.command';

    // The network documentation.
    case SiteCreated = 'site.created';
    case SiteUpdated = 'site.updated';
    case SiteDeleted = 'site.deleted';

    case VlanCreated = 'vlan.created';
    case VlanUpdated = 'vlan.updated';
    case VlanDeleted = 'vlan.deleted';

    case SubnetCreated = 'subnet.created';
    case SubnetUpdated = 'subnet.updated';
    case SubnetDeleted = 'subnet.deleted';

    case CatalogVlanCreated = 'catalog_vlan.created';
    case CatalogVlanUpdated = 'catalog_vlan.updated';
    case CatalogVlanDeleted = 'catalog_vlan.deleted';

    case AddressBlockCreated = 'address_block.created';
    case AddressBlockUpdated = 'address_block.updated';
    case AddressBlockDeleted = 'address_block.deleted';

    case AddressingRuleCreated = 'addressing_rule.created';
    case AddressingRuleUpdated = 'addressing_rule.updated';
    case AddressingRuleDeleted = 'addressing_rule.deleted';

    case IpRangeCreated = 'ip_range.created';
    case IpRangeUpdated = 'ip_range.updated';
    case IpRangeDeleted = 'ip_range.deleted';

    // Written while every range was a DHCP range. Nothing writes them any
    // more: they are kept so that the entries of that time stay readable.
    case DhcpRangeCreated = 'dhcp_range.created';
    case DhcpRangeUpdated = 'dhcp_range.updated';
    case DhcpRangeDeleted = 'dhcp_range.deleted';

    case IpAddressCreated = 'ip_address.created';
    case IpAddressUpdated = 'ip_address.updated';
    case IpAddressDeleted = 'ip_address.deleted';

    // An address that becomes an interface of a device, or stops being
    // one. Before these two existed, it was written as an address updated.
    case IpAddressAttached = 'ip_address.attached';
    case IpAddressDetached = 'ip_address.detached';

    // An address plugged into a wall jack, or unplugged from it.
    case IpAddressPlugged = 'ip_address.plugged';
    case IpAddressUnplugged = 'ip_address.unplugged';

    // The places and the machines.
    case BuildingCreated = 'building.created';
    case BuildingUpdated = 'building.updated';
    case BuildingDeleted = 'building.deleted';

    case FloorCreated = 'floor.created';
    case FloorUpdated = 'floor.updated';
    case FloorDeleted = 'floor.deleted';

    case RoomCreated = 'room.created';
    case RoomUpdated = 'room.updated';
    case RoomDeleted = 'room.deleted';

    case DeviceCreated = 'device.created';
    case DeviceUpdated = 'device.updated';
    case DeviceDeleted = 'device.deleted';

    // What devices are made of.
    case DeviceTemplateCreated = 'device_template.created';
    case DeviceTemplateUpdated = 'device_template.updated';
    case DeviceTemplateDeleted = 'device_template.deleted';

    case DeviceMemberCreated = 'device_member.created';
    case DeviceMemberUpdated = 'device_member.updated';
    case DeviceMemberDeleted = 'device_member.deleted';
    // A series of ports added to a member: one entry for all of them.
    case DeviceMemberPortsAdded = 'device_member.ports_added';

    case PortCreated = 'port.created';
    case PortUpdated = 'port.updated';
    case PortDeleted = 'port.deleted';

    case PortAggregateCreated = 'port_aggregate.created';
    case PortAggregateUpdated = 'port_aggregate.updated';
    case PortAggregateDeleted = 'port_aggregate.deleted';

    case LinkCreated = 'link.created';
    case LinkUpdated = 'link.updated';
    case LinkDeleted = 'link.deleted';

    case PatchPanelCreated = 'patch_panel.created';
    case PatchPanelUpdated = 'patch_panel.updated';
    case PatchPanelDeleted = 'patch_panel.deleted';

    case JackCreated = 'jack.created';
    case JackUpdated = 'jack.updated';
    case JackDeleted = 'jack.deleted';
    // A series of jacks created on a floor: one entry for all of them.
    case FloorJacksAdded = 'floor.jacks_added';

    // A cord laid between a port of a panel and a port of a device, and
    // taken away. A series of cords gives one entry per cord.
    case PatchCordCreated = 'patch_cord.created';
    case PatchCordUpdated = 'patch_cord.updated';
    case PatchCordDeleted = 'patch_cord.deleted';

    // A port of a device plugged into a wall jack by a jack cord, the cord
    // changed, and taken away: one entry per cord, with its two ends.
    case JackCordCreated = 'jack_cord.created';
    case JackCordUpdated = 'jack_cord.updated';
    case JackCordDeleted = 'jack_cord.deleted';

    // The layout of a diagram of a site, placed by hand then saved, or
    // given back to the automatic layout: one entry on the site, with the
    // number of devices.
    case SiteDiagramSaved = 'site.diagram_saved';
    case SiteDiagramReset = 'site.diagram_reset';

    /**
     * Whether the event is about an object whose name is feminine in
     * French, an IP address, a range, a link or a jack: its status is then
     * written "active" and not "actif".
     */
    public function concernsFeminineObject(): bool
    {
        return str_starts_with($this->value, 'ip_address.')
            || str_starts_with($this->value, 'link.')
            || str_starts_with($this->value, 'jack.')
            || str_starts_with($this->value, 'ip_range.')
            || str_starts_with($this->value, 'dhcp_range.');
    }

    /**
     * The event that used to be written for the same thing, when there is
     * one: looking for the ranges created also finds those created while
     * they were called DHCP ranges.
     */
    public function formerEvent(): ?self
    {
        return match ($this) {
            self::IpRangeCreated => self::DhcpRangeCreated,
            self::IpRangeUpdated => self::DhcpRangeUpdated,
            self::IpRangeDeleted => self::DhcpRangeDeleted,
            default => null,
        };
    }

    /**
     * Whether the event is still written. One that is not is only found in
     * old entries: it is not offered as a filter of its own.
     */
    public function isWritten(): bool
    {
        return ! in_array($this, [self::DhcpRangeCreated, self::DhcpRangeUpdated, self::DhcpRangeDeleted], true);
    }

    /**
     * The translation key of the event's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::UserCreated => 'User created',
            self::UserUpdated => 'User updated',
            self::UserRoleChanged => 'Role changed',
            self::UserDeactivated => 'User deactivated',
            self::UserReactivated => 'User reactivated',
            self::TwoFactorRequired => 'Two-factor authentication required',
            self::TwoFactorNoLongerRequired => 'Two-factor authentication no longer required',
            self::TwoFactorReset => 'Two-factor authentication reset',
            self::LoginSucceeded => 'Login',
            self::LoginFailed => 'Failed login',
            self::LoggedOut => 'Logout',
            self::PasswordChanged => 'Password changed',
            self::PasswordReset => 'Password reset',
            self::TwoFactorEnabled => 'Two-factor authentication enabled',
            self::TwoFactorDisabled => 'Two-factor authentication disabled',
            self::CommandExecuted => 'Console command',
            self::SiteCreated => 'Site created',
            self::SiteUpdated => 'Site updated',
            self::SiteDeleted => 'Site deleted',
            self::VlanCreated => 'VLAN created',
            self::VlanUpdated => 'VLAN updated',
            self::VlanDeleted => 'VLAN deleted',
            self::SubnetCreated => 'Subnet created',
            self::SubnetUpdated => 'Subnet updated',
            self::SubnetDeleted => 'Subnet deleted',
            self::CatalogVlanCreated => 'Catalogue entry created',
            self::CatalogVlanUpdated => 'Catalogue entry updated',
            self::CatalogVlanDeleted => 'Catalogue entry deleted',
            self::AddressBlockCreated => 'Address block created',
            self::AddressBlockUpdated => 'Address block updated',
            self::AddressBlockDeleted => 'Address block deleted',
            self::AddressingRuleCreated => 'Addressing rule created',
            self::AddressingRuleUpdated => 'Addressing rule updated',
            self::AddressingRuleDeleted => 'Addressing rule deleted',
            self::IpRangeCreated => 'Range created',
            self::IpRangeUpdated => 'Range updated',
            self::IpRangeDeleted => 'Range deleted',
            self::DhcpRangeCreated => 'DHCP range created',
            self::DhcpRangeUpdated => 'DHCP range updated',
            self::DhcpRangeDeleted => 'DHCP range deleted',
            self::IpAddressCreated => 'IP address created',
            self::IpAddressUpdated => 'IP address updated',
            self::IpAddressDeleted => 'IP address deleted',
            self::IpAddressAttached => 'Address attached',
            self::IpAddressDetached => 'Address detached',
            self::IpAddressPlugged => 'Address plugged into a jack',
            self::IpAddressUnplugged => 'Address unplugged from a jack',
            self::BuildingCreated => 'Building created',
            self::BuildingUpdated => 'Building updated',
            self::BuildingDeleted => 'Building deleted',
            self::FloorCreated => 'Floor created',
            self::FloorUpdated => 'Floor updated',
            self::FloorDeleted => 'Floor deleted',
            self::RoomCreated => 'Room created',
            self::RoomUpdated => 'Room updated',
            self::RoomDeleted => 'Room deleted',
            self::DeviceCreated => 'Device created',
            self::DeviceUpdated => 'Device updated',
            self::DeviceDeleted => 'Device deleted',
            self::DeviceTemplateCreated => 'Device template created',
            self::DeviceTemplateUpdated => 'Device template updated',
            self::DeviceTemplateDeleted => 'Device template deleted',
            self::DeviceMemberCreated => 'Member created',
            self::DeviceMemberUpdated => 'Member updated',
            self::DeviceMemberDeleted => 'Member deleted',
            self::DeviceMemberPortsAdded => 'Ports added',
            self::PortCreated => 'Port created',
            self::PortUpdated => 'Port updated',
            self::PortDeleted => 'Port deleted',
            self::PortAggregateCreated => 'Aggregate created',
            self::PortAggregateUpdated => 'Aggregate updated',
            self::PortAggregateDeleted => 'Aggregate deleted',
            self::LinkCreated => 'Link created',
            self::LinkUpdated => 'Link updated',
            self::LinkDeleted => 'Link deleted',
            self::PatchPanelCreated => 'Patch panel created',
            self::PatchPanelUpdated => 'Patch panel updated',
            self::PatchPanelDeleted => 'Patch panel deleted',
            self::JackCreated => 'Jack created',
            self::JackUpdated => 'Jack updated',
            self::JackDeleted => 'Jack deleted',
            self::FloorJacksAdded => 'Jacks added',
            self::PatchCordCreated => 'Port patched',
            self::PatchCordUpdated => 'Cord updated',
            self::PatchCordDeleted => 'Port unpatched',
            self::JackCordCreated => 'Port plugged into a jack',
            self::JackCordUpdated => 'Jack cord updated',
            self::JackCordDeleted => 'Port unplugged from a jack',
            self::SiteDiagramSaved => 'Diagram layout saved',
            self::SiteDiagramReset => 'Diagram laid out automatically',
        };
    }
}
