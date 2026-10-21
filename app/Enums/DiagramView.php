<?php

// torsade/app/Enums/DiagramView.php

namespace App\Enums;

/**
 * A diagram of a site: each one keeps the places of its nodes apart.
 */
enum DiagramView: string
{
    case Physical = 'physical';
    case Logical = 'logical';

    /**
     * The translation key of the diagram's name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Physical => 'Physical diagram',
            self::Logical => 'Logical diagram',
        };
    }
}
