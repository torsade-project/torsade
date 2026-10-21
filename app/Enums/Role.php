<?php

// torsade/app/Enums/Role.php

namespace App\Enums;

/**
 * The three fixed roles of Torsade. There is no generic permission system.
 */
enum Role: string
{
    /** Reads the documentation. */
    case Reader = 'reader';

    /** Reads and writes the network data. */
    case Editor = 'editor';

    /** Everything, plus the users and the activity log. */
    case Admin = 'admin';

    /**
     * The translation key of the role's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Reader => 'Reader',
            self::Editor => 'Editor',
            self::Admin => 'Administrator',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
