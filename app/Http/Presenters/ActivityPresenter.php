<?php

// torsade/app/Http/Presenters/ActivityPresenter.php

namespace App\Http\Presenters;

use App\Enums\ActivityEvent;
use App\Enums\AggregateProtocol;
use App\Enums\DeviceType;
use App\Enums\DiagramView;
use App\Enums\GatewayPosition;
use App\Enums\IpAssignment;
use App\Enums\LinkMedium;
use App\Enums\MemberRole;
use App\Enums\PanelConnector;
use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortMode;
use App\Enums\PortSpeed;
use App\Enums\RangeKind;
use App\Enums\Role;
use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Models\Activity;
use App\Models\User;
use App\Support\Actor;

/**
 * An entry of the activity log as the pages show it, with readable labels:
 * the page of the log and the home page present it the same way. The
 * entry must be loaded with its causer and its subject.
 */
final class ActivityPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function entry(Activity $activity): array
    {
        $event = ActivityEvent::tryFrom((string) $activity->event);

        return [
            'id' => $activity->id,
            'event' => $activity->event,
            'event_label' => $event === null ? (string) $activity->event : __($event->label()),
            'created_at' => $activity->created_at?->toIso8601String(),
            'author' => self::author($activity),
            'subject' => self::subject($activity),
            'changes' => self::changes($activity, $event?->concernsFeminineObject() ?? false),
            'details' => self::details($activity, $event),
            'ip' => $activity->getProperty('ip'),
        ];
    }

    /**
     * @return array{type: 'user'|'console'|'anonymous', name?: string, email?: string}
     */
    private static function author(Activity $activity): array
    {
        if ($activity->causer instanceof User) {
            return ['type' => 'user', 'name' => $activity->causer->name, 'email' => $activity->causer->email];
        }

        return ['type' => $activity->getProperty('source') === Actor::SOURCE_CONSOLE ? 'console' : 'anonymous'];
    }

    /**
     * What the entry is about: an account, or a network object. The label of
     * a network object is stored with the entry, so that it can still be
     * named once deleted.
     *
     * @return array{title: string, detail: string|null}|null
     */
    private static function subject(Activity $activity): ?array
    {
        if ($activity->subject instanceof User) {
            return ['title' => $activity->subject->name, 'detail' => $activity->subject->email];
        }

        $label = $activity->getProperty('label');

        return is_string($label) ? ['title' => $label, 'detail' => null] : null;
    }

    /**
     * The values before and after, with readable labels. A status agrees
     * with the object the entry is about.
     *
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    private static function changes(Activity $activity, bool $feminine): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];

        /** @var array<string, mixed> $old */
        $old = $changes['old'] ?? [];
        /** @var array<string, mixed> $new */
        $new = $changes['attributes'] ?? [];

        $lines = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $before = array_key_exists($field, $old);
            $after = array_key_exists($field, $new);

            // A creation or a deletion lists what the object holds: a field
            // left empty says nothing. In an update, emptying a field does.
            if (! ($before && $after) && ($old[$field] ?? $new[$field] ?? null) === null) {
                continue;
            }

            $lines[] = [
                'field' => self::fieldLabel($field),
                'old' => $before ? self::value($field, $old[$field], $feminine) : null,
                'new' => $after ? self::value($field, $new[$field], $feminine) : null,
            ];
        }

        return $lines;
    }

    private static function fieldLabel(string $field): string
    {
        return match ($field) {
            'name' => __('Name'),
            'email' => __('Email address'),
            'role' => __('Role'),
            'is_active' => __('Status'),
            'two_factor_required' => __('Two-factor authentication required'),
            'description' => __('Description'),
            'status' => __('Status'),
            'site' => __('Site'),
            'vid' => __('VID'),
            'vlan' => __('VLAN'),
            'subnet' => __('Subnet'),
            'network' => __('Network address'),
            'prefix_length' => __('Prefix length'),
            'gateway' => __('Gateway'),
            'code' => __('Code'),
            'number' => __('Number'),
            'aggregate_block' => __('Block of the organisation'),
            'site_prefix_length' => __('Level of sites'),
            'vlan_prefix_length' => __('Length of the subnets of VLANs'),
            'gateway_position' => __('Position of the gateway'),
            'ranges' => __('Standard breakdown'),
            'catalog' => __('Catalogue'),
            'kind' => __('Type'),
            'start_address' => __('Start'),
            'end_address' => __('End'),
            'address' => __('Address'),
            'hostname' => __('Host name'),
            'mac_address' => __('MAC address'),
            'device_type' => __('Type'),
            'assignment' => __('Assignment'),
            'device' => __('Device'),
            'building' => __('Building'),
            'floor' => __('Floor'),
            'room' => __('Room'),
            'level' => __('Level'),
            'is_technical' => __('Technical room'),
            'type' => __('Type'),
            'manufacturer' => __('Manufacturer'),
            'model' => __('Model'),
            'serial_number' => __('Serial number'),
            'poe_budget_watts' => __('PoE budget (W)'),
            'port_groups' => __('Groups of ports'),
            'position' => __('Number'),
            'template' => __('Template'),
            'member' => __('Member'),
            'order' => __('Order'),
            'connector' => __('Connector'),
            'max_speed' => __('Speed'),
            'poe_type' => __('PoE'),
            'poe_label' => __('PoE label'),
            'enabled' => __('State'),
            'aggregate' => __('Aggregate'),
            'mode' => __('Mode'),
            'vlans' => __('VLANs'),
            'protocol' => __('Protocol'),
            'ports' => __('Ports'),
            'ports_count' => __('Number of ports'),
            'members' => __('Members'),
            'aggregates' => __('Aggregates'),
            'end_1' => __('End 1'),
            'end_2' => __('End 2'),
            'medium' => __('Medium'),
            'port_count' => __('Number of ports'),
            'category' => __('Category'),
            'label' => __('Label'),
            'location' => __('Location'),
            'panel_port' => __('Port of a panel'),
            'jack' => __('Jack'),
            'port' => __('Port'),
            'color' => __('Colour'),
            'jacks_count' => __('Number of jacks'),
            'jacks' => __('Jacks'),
            'diagram' => __('Diagram'),
            'devices_count' => __('Number of devices'),
            'nodes_count' => __('Number of elements'),
            'panels_count' => __('Number of panels'),
            default => $field,
        };
    }

    private static function value(string $field, mixed $value, bool $feminine): string
    {
        return match (true) {
            // The role of an account, or the role of a member in its
            // device: no value is shared.
            $field === 'role' && is_string($value) => __(Role::tryFrom($value)?->label() ?? MemberRole::tryFrom($value)?->label() ?? $value),
            $field === 'is_active' => $value ? __('Active') : __('Inactive'),
            $field === 'two_factor_required', $field === 'is_technical' => $value ? __('Yes') : __('No'),
            $field === 'enabled' => $value ? __('Enabled (masculine)') : __('Disabled (masculine)'),
            // A field that was emptied, or was empty before the change.
            $value === null => '—',
            $field === 'status' && is_string($value) => __(Status::tryFrom($value)?->label($feminine) ?? $value),
            // The type of an address, or the type of a device.
            in_array($field, ['device_type', 'type'], true) && is_string($value) => __(DeviceType::tryFrom($value)?->label() ?? $value),
            $field === 'assignment' && is_string($value) => __(IpAssignment::tryFrom($value)?->label() ?? $value),
            // The connector of a port, or the connector of a patch panel.
            $field === 'connector' && is_string($value) => __(PortConnector::tryFrom($value)?->label() ?? PanelConnector::tryFrom($value)?->label() ?? $value),
            $field === 'max_speed' && is_string($value) => __(PortSpeed::tryFrom($value)?->label() ?? $value),
            $field === 'poe_type' && is_string($value) => __(PoeType::tryFrom($value)?->label() ?? $value),
            $field === 'mode' && is_string($value) => __(PortMode::tryFrom($value)?->label() ?? $value),
            $field === 'protocol' && is_string($value) => __(AggregateProtocol::tryFrom($value)?->label() ?? $value),
            $field === 'medium' && is_string($value) => __(LinkMedium::tryFrom($value)?->label() ?? $value),
            $field === 'gateway_position' && is_string($value) => __(GatewayPosition::tryFrom($value)?->label() ?? $value),
            $field === 'diagram' && is_string($value) => __(DiagramView::tryFrom($value)?->label() ?? $value),
            // The lengths of the rule are prefix lengths: written as such.
            in_array($field, ['site_prefix_length', 'vlan_prefix_length'], true) && is_int($value) => '/'.$value,
            // The kind of a subnet or the kind of a range: no value is shared.
            $field === 'kind' && is_string($value) => __(SubnetKind::tryFrom($value)?->label() ?? RangeKind::tryFrom($value)?->label() ?? $value),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    /**
     * Extra context shown under the event, already translated.
     *
     * @return list<string>
     */
    private static function details(Activity $activity, ?ActivityEvent $event): array
    {
        $details = [];

        if ($event === ActivityEvent::LoginFailed) {
            $details[] = match ($activity->getProperty('reason')) {
                'inactive' => __('Deactivated account'),
                'locked' => __('Too many attempts'),
                'two_factor' => __('Invalid two-factor code'),
                default => $activity->subject === null ? __('Unknown account') : __('Wrong password'),
            };
        }

        if ($event === ActivityEvent::CommandExecuted) {
            $details[] = (string) $activity->getProperty('command');
            $details[] = match ($activity->getProperty('outcome')) {
                'success' => __('Succeeded'),
                'refused' => __('Refused'),
                default => __('Failed'),
            };
        }

        return $details;
    }
}
