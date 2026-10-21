<?php

// torsade/app/Enums/DeviceType.php

namespace App\Enums;

/**
 * What kind of machine a device is, and what kind of machine holds an IP
 * address: an address keeps its own type, which describes the interface,
 * whether it is attached to a device or not.
 */
enum DeviceType: string
{
    case Server = 'server';
    case Workstation = 'workstation';
    case Laptop = 'laptop';
    case Printer = 'printer';
    case IpPhone = 'ip_phone';
    case AccessPoint = 'access_point';
    case Switch = 'switch';
    case Router = 'router';
    case Firewall = 'firewall';
    case Camera = 'camera';
    case Nas = 'nas';
    case Other = 'other';

    /**
     * The translation key of the type's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Server => 'Server',
            self::Workstation => 'Workstation',
            self::Laptop => 'Laptop',
            self::Printer => 'Printer',
            self::IpPhone => 'IP phone',
            self::AccessPoint => 'Access point',
            self::Switch => 'Switch',
            self::Router => 'Router',
            self::Firewall => 'Firewall',
            self::Camera => 'Camera',
            self::Nas => 'NAS',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => __($type->label())],
            self::cases(),
        );
    }
}
