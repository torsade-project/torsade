<?php

// torsade/app/Enums/DiagramNode.php

namespace App\Enums;

/**
 * What a position places on a diagram: a device, on either diagram; a
 * subnet, drawn as a bar, or the node of the internet, on the logical
 * diagram; a patch panel, on the physical diagram.
 */
enum DiagramNode: string
{
    case Device = 'device';
    case Subnet = 'subnet';
    case Internet = 'internet';
    case PatchPanel = 'patch_panel';
}
