<?php

// torsade/app/Http/Requests/Network/Concerns/SplitsCidr.php

namespace App\Http\Requests\Network\Concerns;

/**
 * The network field of a subnet accepts "192.168.100.0/24" as well as a
 * bare address with the prefix length chosen beside it.
 */
trait SplitsCidr
{
    /**
     * When the address is typed with a prefix length, that one wins over the
     * separate field: it is what the user has just written.
     *
     * @return array{network: mixed, prefix_length: mixed}
     */
    private function splitCidr(mixed $network, mixed $prefixLength): array
    {
        if (is_string($network) && preg_match('/^([^\/]*)\/(\d{1,3})$/D', $network, $matches) === 1) {
            return ['network' => $matches[1], 'prefix_length' => (int) $matches[2]];
        }

        return ['network' => $network, 'prefix_length' => $prefixLength];
    }
}
