<?php

// torsade/app/Support/Ipv4.php

namespace App\Support;

use InvalidArgumentException;

/**
 * Every IPv4 calculation of the application, on plain 64-bit integers and
 * with no dependency: the rules are the same whatever the database engine
 * and can be tested on their own.
 *
 * Addresses go in and out as dotted-decimal strings ("192.168.100.10").
 */
final class Ipv4
{
    public const int MAX_PREFIX = 32;

    private const int MAX_ADDRESS = 0xFFFFFFFF;

    /**
     * Whether the string is a strict dotted-decimal address: four numbers
     * from 0 to 255, without leading zero, sign or space. "01.2.3.4" is
     * refused because some tools would read its first number as octal.
     */
    public static function isValid(string $address): bool
    {
        return self::parse($address) !== null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function toInt(string $address): int
    {
        return self::parse($address)
            ?? throw new InvalidArgumentException("[{$address}] is not an IPv4 address.");
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function toAddress(int $value): string
    {
        if ($value < 0 || $value > self::MAX_ADDRESS) {
            throw new InvalidArgumentException("[{$value}] is outside the IPv4 address space.");
        }

        return implode('.', [
            ($value >> 24) & 0xFF,
            ($value >> 16) & 0xFF,
            ($value >> 8) & 0xFF,
            $value & 0xFF,
        ]);
    }

    public static function isValidPrefix(int $prefix): bool
    {
        return $prefix >= 0 && $prefix <= self::MAX_PREFIX;
    }

    /**
     * Split "192.168.100.0/24" into its address and its prefix length. The
     * address is returned as typed, host bits included: see network().
     *
     * @return array{address: string, prefix: int}|null Null when the string is not in CIDR notation.
     */
    public static function parseCidr(string $cidr): ?array
    {
        if (preg_match('/^([0-9.]+)\/(0|[1-9][0-9]?)$/D', $cidr, $matches) !== 1) {
            return null;
        }

        $prefix = (int) $matches[2];

        if (! self::isValid($matches[1]) || ! self::isValidPrefix($prefix)) {
            return null;
        }

        return ['address' => $matches[1], 'prefix' => $prefix];
    }

    /**
     * The subnet mask of a prefix length, in dotted decimal.
     */
    public static function mask(int $prefix): string
    {
        return self::toAddress(self::maskBits($prefix));
    }

    /**
     * The network address of the subnet an address belongs to: the address
     * with its host bits set to zero.
     */
    public static function network(string $address, int $prefix): string
    {
        return self::toAddress(self::toInt($address) & self::maskBits($prefix));
    }

    /**
     * Whether the address has no host bit set for this prefix length.
     */
    public static function isNetworkAddress(string $address, int $prefix): bool
    {
        return self::network($address, $prefix) === $address;
    }

    /**
     * The last address of the subnet.
     */
    public static function broadcast(string $address, int $prefix): string
    {
        return self::toAddress(self::lastOf(self::toInt($address), $prefix));
    }

    /**
     * The addresses a machine can hold. Up to /30, the network and broadcast
     * addresses are excluded; a /31 keeps both of its addresses (point-to-point
     * link, RFC 3021) and a /32 its only one.
     *
     * @return array{0: string, 1: string} First and last usable address.
     */
    public static function usableRange(string $network, int $prefix): array
    {
        [$first, $last] = self::usableBounds($network, $prefix);

        return [self::toAddress($first), self::toAddress($last)];
    }

    public static function usableCount(int $prefix): int
    {
        self::assertPrefix($prefix);

        $size = 1 << (self::MAX_PREFIX - $prefix);

        return $prefix >= 31 ? $size : $size - 2;
    }

    /**
     * Whether the address is anywhere in the subnet, network and broadcast
     * addresses included.
     */
    public static function contains(string $network, int $prefix, string $address): bool
    {
        $first = self::toInt($network) & self::maskBits($prefix);
        $value = self::toInt($address);

        return $value >= $first && $value <= self::lastOf($first, $prefix);
    }

    /**
     * Whether a machine of the subnet can hold the address.
     */
    public static function isUsable(string $network, int $prefix, string $address): bool
    {
        [$first, $last] = self::usableBounds($network, $prefix);
        $value = self::toInt($address);

        return $value >= $first && $value <= $last;
    }

    /**
     * Whether two subnets share at least one address. Two CIDR blocks are
     * either disjoint or nested, so comparing their bounds is enough.
     */
    public static function overlaps(string $networkA, int $prefixA, string $networkB, int $prefixB): bool
    {
        $firstA = self::toInt($networkA) & self::maskBits($prefixA);
        $firstB = self::toInt($networkB) & self::maskBits($prefixB);

        return $firstA <= self::lastOf($firstB, $prefixB) && $firstB <= self::lastOf($firstA, $prefixA);
    }

    /**
     * Whether the first block holds the second one. Two CIDR blocks are
     * either disjoint or nested: the first holds the second when it is at
     * least as large and the second starts inside it. A block holds itself.
     */
    public static function prefixContains(string $networkA, int $prefixA, string $networkB, int $prefixB): bool
    {
        $mask = self::maskBits($prefixA);

        return $prefixA <= $prefixB && (self::toInt($networkB) & $mask) === (self::toInt($networkA) & $mask);
    }

    /**
     * The number of addresses of a block, network and broadcast addresses
     * included: 256 for a /24.
     */
    public static function size(int $prefix): int
    {
        self::assertPrefix($prefix);

        return 1 << (self::MAX_PREFIX - $prefix);
    }

    /**
     * What is left of a block once the blocks inside it are taken away, as
     * CIDR prefixes: each one starts on its own boundary and is as large as
     * the gap allows, so that it can be given out as it is.
     *
     * The work depends on the number of blocks taken away, never on the
     * size of the block: a /8 is not walked through address by address.
     *
     * @param  iterable<array{0: string, 1: int}>  $taken  Blocks, as network address and prefix length.
     * @param  int  $limit  How many prefixes are listed; all of them are counted.
     * @return array{prefixes: list<array{network: string, prefix: int, size: int}>, count: int, addresses: int, taken: int}
     */
    public static function freePrefixes(string $network, int $prefix, iterable $taken, int $limit = PHP_INT_MAX): array
    {
        $first = self::toInt($network) & self::maskBits($prefix);
        $last = self::lastOf($first, $prefix);

        $occupied = [];

        foreach ($taken as [$takenNetwork, $takenPrefix]) {
            $start = self::toInt($takenNetwork) & self::maskBits($takenPrefix);
            $end = self::lastOf($start, $takenPrefix);

            // What lies outside the block does not count.
            if ($end >= $first && $start <= $last) {
                $occupied[] = [max($start, $first), min($end, $last)];
            }
        }

        usort($occupied, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $free = ['prefixes' => [], 'count' => 0, 'addresses' => 0, 'taken' => 0];
        $position = $first;

        foreach ($occupied as [$start, $end]) {
            if ($start > $position) {
                self::splitIntoPrefixes($position, $start - 1, $limit, $free);
            }

            // Blocks that overlap each other are only counted once.
            if ($end >= $position) {
                $free['taken'] += $end - max($start, $position) + 1;
                $position = $end + 1;
            }
        }

        if ($position <= $last) {
            self::splitIntoPrefixes($position, $last, $limit, $free);
        }

        return $free;
    }

    /**
     * Negative, zero or positive, like the spaceship operator: the numeric
     * order of two addresses.
     */
    public static function compare(string $a, string $b): int
    {
        return self::toInt($a) <=> self::toInt($b);
    }

    /**
     * Whether the address lies between the two bounds, both included.
     */
    public static function inRange(string $address, string $start, string $end): bool
    {
        $value = self::toInt($address);

        return $value >= self::toInt($start) && $value <= self::toInt($end);
    }

    /**
     * Whether two ranges, bounds included, share at least one address.
     */
    public static function rangesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        return self::toInt($startA) <= self::toInt($endB) && self::toInt($startB) <= self::toInt($endA);
    }

    /**
     * The number of addresses from start to end, both included.
     */
    public static function rangeSize(string $start, string $end): int
    {
        return max(0, self::toInt($end) - self::toInt($start) + 1);
    }

    /**
     * The ranges of addresses whose text starts with what was typed, for a
     * search: "10.10.1" finds 10.10.1.x, 10.10.10.x to 10.10.19.x and
     * 10.10.100.x to 10.10.199.x. An address in full finds itself, and
     * those it is the beginning of.
     *
     * Addresses are stored as numbers, which no text search reaches: the
     * ranges are compared with them, a few intervals whatever the number of
     * addresses.
     *
     * @return list<array{0: string, 1: string}> First and last address of each range; empty when what was typed is not the beginning of an address.
     */
    public static function startingWith(string $typed): array
    {
        if (preg_match('/^\d{1,3}(\.\d{1,3}){0,3}\.?$/D', $typed) !== 1) {
            return [];
        }

        $parts = explode('.', $typed);

        // The number being typed, of which only the first digits are known:
        // "1" may become 1, 10 to 19 or 100 to 199. After a dot, nothing of
        // the next number is known yet.
        $partial = array_pop($parts);

        if (count($parts) === 4) {
            return [];
        }

        $base = 0;

        foreach ($parts as $index => $part) {
            if ((int) $part > 255 || (strlen($part) > 1 && $part[0] === '0')) {
                return [];
            }

            $base |= (int) $part << (8 * (3 - $index));
        }

        $shift = 8 * (3 - count($parts));
        // What the numbers not typed yet can add.
        $span = (1 << $shift) - 1;

        if ($partial === '') {
            return [[self::toAddress($base), self::toAddress($base | (0xFF << $shift) | $span)]];
        }

        $ranges = [];
        $first = null;

        // The values whose text starts with the digits typed, grouped where
        // they follow each other.
        for ($value = 0; $value <= 256; $value++) {
            $matches = $value <= 255 && str_starts_with((string) $value, $partial);

            if ($matches && $first === null) {
                $first = $value;
            } elseif (! $matches && $first !== null) {
                $ranges[] = [
                    self::toAddress($base | ($first << $shift)),
                    self::toAddress($base | (($value - 1) << $shift) | $span),
                ];
                $first = null;
            }
        }

        return $ranges;
    }

    /**
     * The usable addresses that are neither taken one by one (documented
     * addresses, gateway) nor covered by a range (DHCP), as intervals.
     *
     * The work depends on the number of occupied items, never on the size of
     * the subnet: a /8 holds more than 16 million addresses, which must not be
     * listed one by one.
     *
     * @param  iterable<string>  $addresses  Single occupied addresses.
     * @param  iterable<array{0: string, 1: string}>  $ranges  Occupied ranges, bounds included.
     * @return list<array{start: string, end: string, count: int}>
     */
    public static function freeIntervals(string $network, int $prefix, iterable $addresses = [], iterable $ranges = []): array
    {
        [$first, $last] = self::usableBounds($network, $prefix);

        $occupied = [];

        foreach ($addresses as $address) {
            $value = self::toInt($address);
            $occupied[] = [$value, $value];
        }

        foreach ($ranges as [$start, $end]) {
            $occupied[] = [self::toInt($start), self::toInt($end)];
        }

        usort($occupied, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $free = [];
        $position = $first;

        foreach ($occupied as [$start, $end]) {
            if ($position > $last) {
                break;
            }

            if ($end < $position) {
                continue;
            }

            if ($start > $position) {
                $free[] = self::interval($position, min($start - 1, $last));
            }

            $position = max($position, $end + 1);
        }

        if ($position <= $last) {
            $free[] = self::interval($position, $last);
        }

        return $free;
    }

    /**
     * Cut intervals at the bounds of ranges that carry a mark, and say for
     * each piece which mark it lies under, or null outside of every range.
     * The ranges do not overlap each other.
     *
     * @param  list<array{start: string, end: string, count: int}>  $intervals  In the order of their addresses.
     * @param  iterable<array{0: string, 1: string, 2: string}>  $ranges  First address, last address, mark.
     * @return list<array{start: string, end: string, count: int, mark: string|null}>
     */
    public static function markIntervals(array $intervals, iterable $ranges): array
    {
        $marked = [];

        foreach ($ranges as [$start, $end, $mark]) {
            $marked[] = [self::toInt($start), self::toInt($end), $mark];
        }

        usort($marked, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $pieces = [];

        foreach ($intervals as $interval) {
            $position = self::toInt($interval['start']);
            $last = self::toInt($interval['end']);

            foreach ($marked as [$start, $end, $mark]) {
                if ($end < $position || $start > $last) {
                    continue;
                }

                if ($start > $position) {
                    $pieces[] = [...self::interval($position, $start - 1), 'mark' => null];
                }

                $pieces[] = [...self::interval(max($position, $start), min($last, $end)), 'mark' => $mark];

                $position = min($last, $end) + 1;
            }

            if ($position <= $last) {
                $pieces[] = [...self::interval($position, $last), 'mark' => null];
            }
        }

        return $pieces;
    }

    /**
     * The lowest free address of the subnet, or null when it is full.
     *
     * @param  iterable<string>  $addresses
     * @param  iterable<array{0: string, 1: string}>  $ranges
     */
    public static function nextFree(string $network, int $prefix, iterable $addresses = [], iterable $ranges = []): ?string
    {
        return self::freeIntervals($network, $prefix, $addresses, $ranges)[0]['start'] ?? null;
    }

    /**
     * Cut a run of addresses into the largest CIDR prefixes that fit in it,
     * from its first address on. A prefix can only start on a multiple of
     * its own size: that, and what is left of the run, bound each one.
     *
     * @param  array{prefixes: list<array{network: string, prefix: int, size: int}>, count: int, addresses: int, taken: int}  $free
     */
    private static function splitIntoPrefixes(int $from, int $to, int $limit, array &$free): void
    {
        while ($from <= $to) {
            // The lowest bit set in the address is the largest size it can
            // be the start of; zero starts anything.
            $size = $from === 0 ? self::MAX_ADDRESS + 1 : $from & -$from;

            while ($size > $to - $from + 1) {
                $size >>= 1;
            }

            $bits = 0;

            while ((1 << $bits) < $size) {
                $bits++;
            }

            $free['count']++;
            $free['addresses'] += $size;

            if (count($free['prefixes']) < $limit) {
                $free['prefixes'][] = ['network' => self::toAddress($from), 'prefix' => self::MAX_PREFIX - $bits, 'size' => $size];
            }

            $from += $size;
        }
    }

    private static function parse(string $address): ?int
    {
        $octet = '(0|[1-9][0-9]{0,2})';

        if (preg_match("/^{$octet}\\.{$octet}\\.{$octet}\\.{$octet}$/D", $address, $matches) !== 1) {
            return null;
        }

        $value = 0;

        foreach (array_slice($matches, 1) as $part) {
            if ((int) $part > 255) {
                return null;
            }

            $value = ($value << 8) | (int) $part;
        }

        return $value;
    }

    private static function assertPrefix(int $prefix): void
    {
        if (! self::isValidPrefix($prefix)) {
            throw new InvalidArgumentException("[{$prefix}] is not an IPv4 prefix length.");
        }
    }

    private static function maskBits(int $prefix): int
    {
        self::assertPrefix($prefix);

        return (self::MAX_ADDRESS << (self::MAX_PREFIX - $prefix)) & self::MAX_ADDRESS;
    }

    /**
     * The last address of the subnet that starts at the given address.
     */
    private static function lastOf(int $address, int $prefix): int
    {
        $mask = self::maskBits($prefix);

        return ($address & $mask) | (~$mask & self::MAX_ADDRESS);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function usableBounds(string $network, int $prefix): array
    {
        $first = self::toInt($network) & self::maskBits($prefix);
        $last = self::lastOf($first, $prefix);

        return $prefix >= 31 ? [$first, $last] : [$first + 1, $last - 1];
    }

    /**
     * @return array{start: string, end: string, count: int}
     */
    private static function interval(int $start, int $end): array
    {
        return ['start' => self::toAddress($start), 'end' => self::toAddress($end), 'count' => $end - $start + 1];
    }
}
