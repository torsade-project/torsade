<?php

// torsade/app/Support/SearchInput.php

namespace App\Support;

/**
 * What was typed in the search field, read before anything is looked for:
 * an IPv4 address, a CIDR prefix, the beginning of an address, a VLAN
 * number, a MAC address or its beginning, a device followed by one of its
 * ports. Each form that is recognised gives results of its own; what is
 * left is looked for as text, except after the forms that can only be
 * what they are (an address, a prefix, a MAC address).
 */
final readonly class SearchInput
{
    /**
     * @param  string  $text  What was typed, without its surrounding spaces.
     * @param  array{network: string, prefix: int, typed: string}|null  $prefix  The network address, with the address as it was typed.
     * @param  array{0: string, 1: string}|null  $addressRange  First and last address that start as typed.
     * @param  array{device: string, port: string}|null  $devicePort
     */
    private function __construct(
        public string $text,
        public ?string $address,
        public ?array $prefix,
        public ?array $addressRange,
        public ?int $vid,
        public ?string $mac,
        public ?string $macPrefix,
        public ?array $devicePort,
    ) {}

    public static function read(string $typed): self
    {
        $text = trim($typed);

        return new self(
            text: $text,
            address: Ipv4::isValid($text) ? $text : null,
            prefix: self::prefix($text),
            addressRange: self::addressRange($text),
            vid: self::vid($text),
            mac: MacAddress::normalise($text),
            macPrefix: self::macPrefix($text),
            devicePort: self::devicePort($text),
        );
    }

    /**
     * Whether the text is also looked for as it is: not after an address,
     * a prefix, the beginning of an address or a MAC address, which no
     * name holds.
     */
    public function searchesText(): bool
    {
        return $this->address === null
            && $this->prefix === null
            && $this->addressRange === null
            && $this->mac === null;
    }

    /**
     * The prefix as it is looked for, at its network address:
     * "10.1.20.0/24" for "10.1.20.37/24".
     */
    public function prefixCidr(): ?string
    {
        return $this->prefix === null ? null : $this->prefix['network'].'/'.$this->prefix['prefix'];
    }

    /**
     * Whether the prefix was typed with host bits, and brought back to its
     * network address.
     */
    public function prefixWasBroughtBack(): bool
    {
        return $this->prefix !== null && $this->prefix['typed'] !== $this->prefix['network'];
    }

    /**
     * @return array{network: string, prefix: int, typed: string}|null
     */
    private static function prefix(string $text): ?array
    {
        $cidr = Ipv4::parseCidr($text);

        if ($cidr === null) {
            return null;
        }

        return ['network' => Ipv4::network($cidr['address'], $cidr['prefix']), 'prefix' => $cidr['prefix'], 'typed' => $cidr['address']];
    }

    /**
     * One to three whole numbers of an address, each followed by a dot:
     * "10." or "10.1.20.". What is typed after the last dot is not the
     * beginning of an address but a text: "10.1" may be in a host name.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function addressRange(string $text): ?array
    {
        if (preg_match('/^(?:(?:0|[1-9][0-9]{0,2})\.){1,3}$/D', $text) !== 1) {
            return null;
        }

        return Ipv4::startingWith($text)[0] ?? null;
    }

    /**
     * A whole number from 1 to 4094, written without a leading zero.
     */
    private static function vid(string $text): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $text) !== 1) {
            return null;
        }

        $vid = (int) $text;

        return $vid <= 4094 ? $vid : null;
    }

    /**
     * Three to five numbers of a MAC address, separated by colons or by
     * hyphens, the same all along: "aa:bb:cc" or "AA-BB-CC-DD", as it is
     * stored ("aa:bb:cc"). A separator typed after the last number is
     * allowed.
     */
    private static function macPrefix(string $text): ?string
    {
        if (preg_match('/^[0-9a-f]{2}([:-])[0-9a-f]{2}(?:\1[0-9a-f]{2}){1,3}\1?$/iD', $text) !== 1) {
            return null;
        }

        return rtrim(strtolower(str_replace('-', ':', $text)), ':');
    }

    /**
     * The name of a device, which may hold spaces, then the name of one of
     * its ports, after the last space: "sw-etage-1 1/0/24".
     *
     * @return array{device: string, port: string}|null
     */
    private static function devicePort(string $text): ?array
    {
        if (preg_match('/^(.*\S)\s+(\S+)$/uD', $text, $matches) !== 1) {
            return null;
        }

        return ['device' => $matches[1], 'port' => $matches[2]];
    }
}
