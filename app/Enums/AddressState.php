<?php

// torsade/app/Enums/AddressState.php

namespace App\Enums;

/**
 * What one address of a subnet is, on the address plan of that subnet.
 *
 * An address has one state only. When several could apply, the first of
 * this list wins: the gateway stays the gateway even when it is documented
 * as an address, and a planned reservation is shown as planned. A free
 * address of a static range is simply free.
 */
enum AddressState: string
{
    /** The network address and the broadcast address: never given to a machine. */
    case Unusable = 'unusable';

    case Gateway = 'gateway';

    /** Documented, with the status "planned". */
    case Planned = 'planned';

    /** Documented, with the status "retired". */
    case Retired = 'retired';

    /** Documented and in service, reserved on the DHCP server. */
    case Reservation = 'reservation';

    /** Documented and in service, set on the machine. */
    case Documented = 'documented';

    /** Not documented, inside a DHCP range. */
    case DhcpRange = 'dhcp_range';

    /** Not documented, inside a range kept aside. */
    case ReservedRange = 'reserved_range';

    case Free = 'free';
}
