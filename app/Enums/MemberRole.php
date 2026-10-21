<?php

// torsade/app/Enums/MemberRole.php

namespace App\Enums;

/**
 * What a member does in its device. In a stack, the primary drives the
 * whole and the secondary takes over if it fails; every member switches
 * traffic. A device has one primary and one secondary at most.
 */
enum MemberRole: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Member = 'member';

    /**
     * Whether a device can only have one member of this role.
     */
    public function isSingle(): bool
    {
        return $this !== self::Member;
    }

    /**
     * The translation key of the role's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary',
            self::Secondary => 'Secondary',
            self::Member => 'Member',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $role): array => ['value' => $role->value, 'label' => __($role->label())],
            self::cases(),
        );
    }
}
