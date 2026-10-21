<?php

// torsade/tests/Unit/Ipv4Test.php

namespace Tests\Unit;

use App\Support\Ipv4;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The IPv4 rules of the addressing plan, on every prefix length that behaves
 * differently and on both ends of the address space.
 */
class Ipv4Test extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function validAddresses(): array
    {
        return [
            'lowest' => ['0.0.0.0'],
            'highest' => ['255.255.255.255'],
            'private class A' => ['10.0.0.1'],
            'private class B' => ['172.16.254.3'],
            'private class C' => ['192.168.100.10'],
            'zero in the middle' => ['10.0.0.0'],
            'three digits everywhere' => ['192.168.100.200'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAddresses(): array
    {
        return [
            'number above 255' => ['256.1.1.1'],
            'last number above 255' => ['1.1.1.256'],
            'four digits' => ['1.1.1.1000'],
            'three numbers' => ['1.2.3'],
            'five numbers' => ['1.2.3.4.5'],
            'leading zero' => ['01.2.3.4'],
            'leading zero at the end' => ['1.2.3.04'],
            'double zero' => ['00.0.0.0'],
            'leading space' => [' 1.2.3.4'],
            'trailing space' => ['1.2.3.4 '],
            'space inside' => ['1.2. 3.4'],
            'trailing newline' => ["1.2.3.4\n"],
            'tab' => ["1.2.3.4\t"],
            'empty' => [''],
            'empty number' => ['1..3.4'],
            'trailing dot' => ['1.2.3.4.'],
            'negative number' => ['-1.2.3.4'],
            'plus sign' => ['+1.2.3.4'],
            'letters' => ['a.b.c.d'],
            'hexadecimal' => ['0x1.2.3.4'],
            'a single integer' => ['3232261130'],
            'with a prefix' => ['192.168.1.0/24'],
            'comma' => ['1,2,3,4'],
            'IPv6' => ['::1'],
            'IPv6 mapped' => ['::ffff:192.168.1.1'],
            'non-ASCII digits' => ['١.٢.٣.٤'],
        ];
    }

    #[DataProvider('validAddresses')]
    public function test_it_accepts_dotted_decimal_addresses(string $address): void
    {
        $this->assertTrue(Ipv4::isValid($address));
        $this->assertSame($address, Ipv4::toAddress(Ipv4::toInt($address)));
    }

    #[DataProvider('invalidAddresses')]
    public function test_it_refuses_anything_else(string $address): void
    {
        $this->assertFalse(Ipv4::isValid($address));
    }

    #[DataProvider('invalidAddresses')]
    public function test_converting_an_invalid_address_throws(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);

        Ipv4::toInt($address);
    }

    public function test_addresses_convert_to_integers_and_back(): void
    {
        $this->assertSame(0, Ipv4::toInt('0.0.0.0'));
        $this->assertSame(1, Ipv4::toInt('0.0.0.1'));
        $this->assertSame(256, Ipv4::toInt('0.0.1.0'));
        $this->assertSame(3232261130, Ipv4::toInt('192.168.100.10'));
        $this->assertSame(4294967295, Ipv4::toInt('255.255.255.255'));

        $this->assertSame('0.0.0.0', Ipv4::toAddress(0));
        $this->assertSame('192.168.100.10', Ipv4::toAddress(3232261130));
        $this->assertSame('255.255.255.255', Ipv4::toAddress(4294967295));
    }

    public function test_an_integer_outside_the_address_space_is_refused(): void
    {
        foreach ([-1, 4294967296] as $value) {
            try {
                Ipv4::toAddress($value);
                $this->fail("[{$value}] was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_numeric_order_differs_from_text_order(): void
    {
        $this->assertLessThan(0, Ipv4::compare('192.168.1.9', '192.168.1.10'));
        $this->assertGreaterThan(0, Ipv4::compare('10.0.0.0', '9.255.255.255'));
        $this->assertSame(0, Ipv4::compare('172.16.0.1', '172.16.0.1'));
        $this->assertLessThan(0, Ipv4::compare('0.0.0.0', '255.255.255.255'));
    }

    public function test_prefix_lengths_go_from_0_to_32(): void
    {
        $this->assertTrue(Ipv4::isValidPrefix(0));
        $this->assertTrue(Ipv4::isValidPrefix(1));
        $this->assertTrue(Ipv4::isValidPrefix(32));
        $this->assertFalse(Ipv4::isValidPrefix(-1));
        $this->assertFalse(Ipv4::isValidPrefix(33));
    }

    public function test_a_prefix_length_out_of_bounds_throws(): void
    {
        foreach ([-1, 33, 64] as $prefix) {
            try {
                Ipv4::mask($prefix);
                $this->fail("[/{$prefix}] was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function masks(): array
    {
        return [
            '/0' => [0, '0.0.0.0'],
            '/1' => [1, '128.0.0.0'],
            '/8' => [8, '255.0.0.0'],
            '/12' => [12, '255.240.0.0'],
            '/16' => [16, '255.255.0.0'],
            '/23' => [23, '255.255.254.0'],
            '/24' => [24, '255.255.255.0'],
            '/25' => [25, '255.255.255.128'],
            '/30' => [30, '255.255.255.252'],
            '/31' => [31, '255.255.255.254'],
            '/32' => [32, '255.255.255.255'],
        ];
    }

    #[DataProvider('masks')]
    public function test_the_mask_is_derived_from_the_prefix(int $prefix, string $mask): void
    {
        $this->assertSame($mask, Ipv4::mask($prefix));
    }

    /**
     * Each line: address, prefix, network, broadcast, first usable, last
     * usable, number of usable addresses.
     *
     * @return array<string, array{0: string, 1: int, 2: string, 3: string, 4: string, 5: string, 6: int}>
     */
    public static function subnets(): array
    {
        return [
            '/1 lower half' => ['0.0.0.0', 1, '0.0.0.0', '127.255.255.255', '0.0.0.1', '127.255.255.254', 2147483646],
            '/1 upper half' => ['200.1.2.3', 1, '128.0.0.0', '255.255.255.255', '128.0.0.1', '255.255.255.254', 2147483646],
            '/8' => ['10.20.30.40', 8, '10.0.0.0', '10.255.255.255', '10.0.0.1', '10.255.255.254', 16777214],
            '/16' => ['172.16.5.9', 16, '172.16.0.0', '172.16.255.255', '172.16.0.1', '172.16.255.254', 65534],
            '/23' => ['192.168.101.7', 23, '192.168.100.0', '192.168.101.255', '192.168.100.1', '192.168.101.254', 510],
            '/24' => ['192.168.100.10', 24, '192.168.100.0', '192.168.100.255', '192.168.100.1', '192.168.100.254', 254],
            '/24 at the top' => ['255.255.255.255', 24, '255.255.255.0', '255.255.255.255', '255.255.255.1', '255.255.255.254', 254],
            '/24 at the bottom' => ['0.0.0.0', 24, '0.0.0.0', '0.0.0.255', '0.0.0.1', '0.0.0.254', 254],
            '/30' => ['192.168.100.6', 30, '192.168.100.4', '192.168.100.7', '192.168.100.5', '192.168.100.6', 2],
            '/30 at the top' => ['255.255.255.255', 30, '255.255.255.252', '255.255.255.255', '255.255.255.253', '255.255.255.254', 2],
            '/31' => ['192.168.100.5', 31, '192.168.100.4', '192.168.100.5', '192.168.100.4', '192.168.100.5', 2],
            '/31 at the bottom' => ['0.0.0.0', 31, '0.0.0.0', '0.0.0.1', '0.0.0.0', '0.0.0.1', 2],
            '/31 at the top' => ['255.255.255.255', 31, '255.255.255.254', '255.255.255.255', '255.255.255.254', '255.255.255.255', 2],
            '/32' => ['192.168.100.5', 32, '192.168.100.5', '192.168.100.5', '192.168.100.5', '192.168.100.5', 1],
            '/32 lowest' => ['0.0.0.0', 32, '0.0.0.0', '0.0.0.0', '0.0.0.0', '0.0.0.0', 1],
            '/32 highest' => ['255.255.255.255', 32, '255.255.255.255', '255.255.255.255', '255.255.255.255', '255.255.255.255', 1],
        ];
    }

    #[DataProvider('subnets')]
    public function test_network_broadcast_and_usable_range(
        string $address,
        int $prefix,
        string $network,
        string $broadcast,
        string $first,
        string $last,
        int $count,
    ): void {
        $this->assertSame($network, Ipv4::network($address, $prefix));
        $this->assertSame($broadcast, Ipv4::broadcast($address, $prefix));
        $this->assertSame([$first, $last], Ipv4::usableRange($network, $prefix));
        $this->assertSame($count, Ipv4::usableCount($prefix));

        // The usable range is the same whichever address of the subnet is given.
        $this->assertSame([$first, $last], Ipv4::usableRange($address, $prefix));
    }

    public function test_the_whole_address_space_is_a_slash_zero(): void
    {
        $this->assertSame('0.0.0.0', Ipv4::network('192.168.1.1', 0));
        $this->assertSame('255.255.255.255', Ipv4::broadcast('192.168.1.1', 0));
        $this->assertSame(4294967294, Ipv4::usableCount(0));
    }

    public function test_host_bits_are_detected(): void
    {
        $this->assertTrue(Ipv4::isNetworkAddress('192.168.100.0', 24));
        $this->assertFalse(Ipv4::isNetworkAddress('192.168.100.10', 24));
        $this->assertTrue(Ipv4::isNetworkAddress('192.168.100.8', 30));
        $this->assertFalse(Ipv4::isNetworkAddress('192.168.100.9', 30));
        $this->assertTrue(Ipv4::isNetworkAddress('192.168.100.9', 32));
        $this->assertTrue(Ipv4::isNetworkAddress('0.0.0.0', 1));
        $this->assertFalse(Ipv4::isNetworkAddress('64.0.0.0', 1));
        $this->assertTrue(Ipv4::isNetworkAddress('128.0.0.0', 1));
        $this->assertFalse(Ipv4::isNetworkAddress('255.255.255.255', 31));
        $this->assertTrue(Ipv4::isNetworkAddress('255.255.255.254', 31));
    }

    public function test_cidr_notation_is_split_into_address_and_prefix(): void
    {
        $this->assertSame(['address' => '192.168.100.0', 'prefix' => 24], Ipv4::parseCidr('192.168.100.0/24'));
        $this->assertSame(['address' => '192.168.100.10', 'prefix' => 24], Ipv4::parseCidr('192.168.100.10/24'));
        $this->assertSame(['address' => '0.0.0.0', 'prefix' => 0], Ipv4::parseCidr('0.0.0.0/0'));
        $this->assertSame(['address' => '255.255.255.255', 'prefix' => 32], Ipv4::parseCidr('255.255.255.255/32'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidCidrs(): array
    {
        return [
            'no prefix' => ['192.168.100.0'],
            'empty prefix' => ['192.168.100.0/'],
            'prefix above 32' => ['192.168.100.0/33'],
            'three-digit prefix' => ['192.168.100.0/024'],
            'leading zero in the prefix' => ['192.168.100.0/08'],
            'negative prefix' => ['192.168.100.0/-1'],
            'mask instead of prefix' => ['192.168.100.0/255.255.255.0'],
            'invalid address' => ['256.168.100.0/24'],
            'leading zero in the address' => ['192.168.100.01/24'],
            'space before the slash' => ['192.168.100.0 /24'],
            'space after the slash' => ['192.168.100.0/ 24'],
            'trailing newline' => ["192.168.100.0/24\n"],
            'two slashes' => ['192.168.100.0/24/24'],
            'IPv6' => ['fd00::/64'],
        ];
    }

    #[DataProvider('invalidCidrs')]
    public function test_an_invalid_cidr_is_not_parsed(string $cidr): void
    {
        $this->assertNull(Ipv4::parseCidr($cidr));
    }

    public function test_membership_includes_network_and_broadcast_addresses(): void
    {
        $this->assertTrue(Ipv4::contains('192.168.100.0', 24, '192.168.100.0'));
        $this->assertTrue(Ipv4::contains('192.168.100.0', 24, '192.168.100.10'));
        $this->assertTrue(Ipv4::contains('192.168.100.0', 24, '192.168.100.255'));
        $this->assertFalse(Ipv4::contains('192.168.100.0', 24, '192.168.99.255'));
        $this->assertFalse(Ipv4::contains('192.168.100.0', 24, '192.168.101.0'));

        $this->assertTrue(Ipv4::contains('0.0.0.0', 1, '127.255.255.255'));
        $this->assertFalse(Ipv4::contains('0.0.0.0', 1, '128.0.0.0'));
        $this->assertTrue(Ipv4::contains('128.0.0.0', 1, '255.255.255.255'));

        $this->assertTrue(Ipv4::contains('10.0.0.0', 8, '10.255.255.255'));
        $this->assertFalse(Ipv4::contains('10.0.0.0', 8, '11.0.0.0'));

        $this->assertTrue(Ipv4::contains('192.168.100.5', 32, '192.168.100.5'));
        $this->assertFalse(Ipv4::contains('192.168.100.5', 32, '192.168.100.4'));
    }

    public function test_usable_addresses_exclude_network_and_broadcast_up_to_slash_30(): void
    {
        $this->assertFalse(Ipv4::isUsable('192.168.100.0', 24, '192.168.100.0'));
        $this->assertTrue(Ipv4::isUsable('192.168.100.0', 24, '192.168.100.1'));
        $this->assertTrue(Ipv4::isUsable('192.168.100.0', 24, '192.168.100.254'));
        $this->assertFalse(Ipv4::isUsable('192.168.100.0', 24, '192.168.100.255'));
        $this->assertFalse(Ipv4::isUsable('192.168.100.0', 24, '192.168.101.1'));

        $this->assertFalse(Ipv4::isUsable('192.168.100.4', 30, '192.168.100.4'));
        $this->assertTrue(Ipv4::isUsable('192.168.100.4', 30, '192.168.100.5'));
        $this->assertTrue(Ipv4::isUsable('192.168.100.4', 30, '192.168.100.6'));
        $this->assertFalse(Ipv4::isUsable('192.168.100.4', 30, '192.168.100.7'));

        $this->assertFalse(Ipv4::isUsable('0.0.0.0', 1, '0.0.0.0'));
        $this->assertTrue(Ipv4::isUsable('0.0.0.0', 1, '0.0.0.1'));
        $this->assertFalse(Ipv4::isUsable('128.0.0.0', 1, '255.255.255.255'));
        $this->assertTrue(Ipv4::isUsable('128.0.0.0', 1, '255.255.255.254'));
    }

    public function test_both_addresses_of_a_slash_31_are_usable(): void
    {
        $this->assertTrue(Ipv4::isUsable('192.168.100.4', 31, '192.168.100.4'));
        $this->assertTrue(Ipv4::isUsable('192.168.100.4', 31, '192.168.100.5'));
        $this->assertFalse(Ipv4::isUsable('192.168.100.4', 31, '192.168.100.3'));
        $this->assertFalse(Ipv4::isUsable('192.168.100.4', 31, '192.168.100.6'));

        $this->assertTrue(Ipv4::isUsable('255.255.255.254', 31, '255.255.255.255'));
        $this->assertTrue(Ipv4::isUsable('0.0.0.0', 31, '0.0.0.0'));
    }

    public function test_the_only_address_of_a_slash_32_is_usable(): void
    {
        $this->assertTrue(Ipv4::isUsable('192.168.100.5', 32, '192.168.100.5'));
        $this->assertFalse(Ipv4::isUsable('192.168.100.5', 32, '192.168.100.6'));
        $this->assertTrue(Ipv4::isUsable('0.0.0.0', 32, '0.0.0.0'));
        $this->assertTrue(Ipv4::isUsable('255.255.255.255', 32, '255.255.255.255'));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: int, 4: bool}>
     */
    public static function overlaps(): array
    {
        return [
            'identical' => ['192.168.100.0', 24, '192.168.100.0', 24, true],
            'second inside the first' => ['192.168.100.0', 24, '192.168.100.128', 25, true],
            'first inside the second' => ['192.168.100.128', 25, '192.168.100.0', 24, true],
            'a /32 inside a /24' => ['192.168.100.0', 24, '192.168.100.77', 32, true],
            'a /31 at the end of a /24' => ['192.168.100.0', 24, '192.168.100.254', 31, true],
            'neighbours' => ['192.168.100.0', 24, '192.168.101.0', 24, false],
            'two halves of a /24' => ['192.168.100.0', 25, '192.168.100.128', 25, false],
            'two neighbouring /30' => ['192.168.100.0', 30, '192.168.100.4', 30, false],
            'two neighbouring /32' => ['192.168.100.5', 32, '192.168.100.6', 32, false],
            'a /8 around a /24' => ['10.0.0.0', 8, '10.200.3.0', 24, true],
            'a /8 beside a /24' => ['10.0.0.0', 8, '11.0.0.0', 24, false],
            'the two /1' => ['0.0.0.0', 1, '128.0.0.0', 1, false],
            'a /1 around the top /32' => ['128.0.0.0', 1, '255.255.255.255', 32, true],
            'a /1 beside the bottom /32' => ['128.0.0.0', 1, '0.0.0.0', 32, false],
            'the whole space' => ['0.0.0.0', 0, '192.168.100.0', 24, true],
            'different private blocks' => ['172.16.0.0', 12, '192.168.0.0', 16, false],
        ];
    }

    #[DataProvider('overlaps')]
    public function test_subnet_overlap(string $networkA, int $prefixA, string $networkB, int $prefixB, bool $expected): void
    {
        $this->assertSame($expected, Ipv4::overlaps($networkA, $prefixA, $networkB, $prefixB));
        $this->assertSame($expected, Ipv4::overlaps($networkB, $prefixB, $networkA, $prefixA));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: int, 4: bool}>
     */
    public static function nestings(): array
    {
        return [
            'a block holds itself' => ['10.1.0.0', 16, '10.1.0.0', 16, true],
            'a /8 holds a /16 at its start' => ['10.0.0.0', 8, '10.0.0.0', 16, true],
            'a /8 holds a /24 in its middle' => ['10.0.0.0', 8, '10.200.3.0', 24, true],
            'a /16 holds its last /32' => ['10.1.0.0', 16, '10.1.255.255', 32, true],
            'a /31 holds each of its addresses' => ['10.1.0.2', 31, '10.1.0.3', 32, true],
            'the whole space holds everything' => ['0.0.0.0', 0, '203.0.113.0', 24, true],
            'a /1 holds the top /32' => ['128.0.0.0', 1, '255.255.255.255', 32, true],
            'the smaller never holds the larger' => ['10.0.0.0', 16, '10.0.0.0', 8, false],
            'a /24 does not hold the /16 around it' => ['10.1.5.0', 24, '10.1.0.0', 16, false],
            'neighbours of the same size' => ['10.1.0.0', 16, '10.2.0.0', 16, false],
            'a /16 beside a /24' => ['10.1.0.0', 16, '10.2.5.0', 24, false],
            'a /1 does not hold the bottom /32' => ['128.0.0.0', 1, '0.0.0.0', 32, false],
            'two halves of a /24' => ['192.168.100.0', 25, '192.168.100.128', 25, false],
        ];
    }

    #[DataProvider('nestings')]
    public function test_a_block_holds_another_when_it_is_larger_and_starts_before_it(
        string $networkA,
        int $prefixA,
        string $networkB,
        int $prefixB,
        bool $expected,
    ): void {
        $this->assertSame($expected, Ipv4::prefixContains($networkA, $prefixA, $networkB, $prefixB));

        // Two blocks that are not nested either way are disjoint.
        if (! $expected && ! Ipv4::prefixContains($networkB, $prefixB, $networkA, $prefixA)) {
            $this->assertFalse(Ipv4::overlaps($networkA, $prefixA, $networkB, $prefixB));
        }
    }

    public function test_ranges(): void
    {
        $this->assertTrue(Ipv4::inRange('192.168.100.100', '192.168.100.100', '192.168.100.200'));
        $this->assertTrue(Ipv4::inRange('192.168.100.200', '192.168.100.100', '192.168.100.200'));
        $this->assertFalse(Ipv4::inRange('192.168.100.99', '192.168.100.100', '192.168.100.200'));
        $this->assertFalse(Ipv4::inRange('192.168.100.201', '192.168.100.100', '192.168.100.200'));
        $this->assertTrue(Ipv4::inRange('192.168.100.9', '192.168.100.9', '192.168.100.9'));

        $this->assertSame(101, Ipv4::rangeSize('192.168.100.100', '192.168.100.200'));
        $this->assertSame(1, Ipv4::rangeSize('192.168.100.9', '192.168.100.9'));
        $this->assertSame(0, Ipv4::rangeSize('192.168.100.10', '192.168.100.9'));
        $this->assertSame(4294967296, Ipv4::rangeSize('0.0.0.0', '255.255.255.255'));

        $this->assertTrue(Ipv4::rangesOverlap('10.0.0.10', '10.0.0.20', '10.0.0.20', '10.0.0.30'));
        $this->assertTrue(Ipv4::rangesOverlap('10.0.0.10', '10.0.0.30', '10.0.0.15', '10.0.0.20'));
        $this->assertTrue(Ipv4::rangesOverlap('10.0.0.15', '10.0.0.20', '10.0.0.10', '10.0.0.30'));
        $this->assertFalse(Ipv4::rangesOverlap('10.0.0.10', '10.0.0.20', '10.0.0.21', '10.0.0.30'));
        $this->assertFalse(Ipv4::rangesOverlap('10.0.0.21', '10.0.0.30', '10.0.0.10', '10.0.0.20'));
    }

    public function test_an_empty_subnet_is_one_free_interval(): void
    {
        $this->assertSame(
            [['start' => '192.168.100.1', 'end' => '192.168.100.254', 'count' => 254]],
            Ipv4::freeIntervals('192.168.100.0', 24),
        );

        $this->assertSame('192.168.100.1', Ipv4::nextFree('192.168.100.0', 24));
    }

    public function test_free_intervals_leave_out_addresses_and_ranges(): void
    {
        $free = Ipv4::freeIntervals(
            '192.168.100.0',
            24,
            // Given out of order, with a duplicate and the gateway at the end.
            ['192.168.100.20', '192.168.100.10', '192.168.100.11', '192.168.100.10', '192.168.100.254'],
            [['192.168.100.100', '192.168.100.199']],
        );

        $this->assertSame([
            ['start' => '192.168.100.1', 'end' => '192.168.100.9', 'count' => 9],
            ['start' => '192.168.100.12', 'end' => '192.168.100.19', 'count' => 8],
            ['start' => '192.168.100.21', 'end' => '192.168.100.99', 'count' => 79],
            ['start' => '192.168.100.200', 'end' => '192.168.100.253', 'count' => 54],
        ], $free);

        // 254 usable, minus 4 distinct addresses and 100 addresses of the range.
        $this->assertSame(150, array_sum(array_column($free, 'count')));
    }

    public function test_the_next_free_address_skips_what_is_taken_at_the_start(): void
    {
        $this->assertSame('192.168.100.4', Ipv4::nextFree(
            '192.168.100.0',
            24,
            ['192.168.100.1', '192.168.100.2', '192.168.100.3', '192.168.100.50'],
        ));

        // A range that starts on the first usable address.
        $this->assertSame('192.168.100.51', Ipv4::nextFree(
            '192.168.100.0',
            24,
            [],
            [['192.168.100.1', '192.168.100.50']],
        ));
    }

    public function test_overlapping_and_adjacent_occupied_items_are_merged(): void
    {
        $free = Ipv4::freeIntervals(
            '10.0.0.0',
            24,
            ['10.0.0.60', '10.0.0.61', '10.0.0.150'],
            [['10.0.0.50', '10.0.0.100'], ['10.0.0.90', '10.0.0.120'], ['10.0.0.121', '10.0.0.130']],
        );

        $this->assertSame([
            ['start' => '10.0.0.1', 'end' => '10.0.0.49', 'count' => 49],
            ['start' => '10.0.0.131', 'end' => '10.0.0.149', 'count' => 19],
            ['start' => '10.0.0.151', 'end' => '10.0.0.254', 'count' => 104],
        ], $free);
    }

    public function test_items_outside_the_usable_range_are_ignored(): void
    {
        $free = Ipv4::freeIntervals(
            '192.168.100.0',
            24,
            ['192.168.100.0', '192.168.100.255', '192.168.99.7', '192.168.101.7'],
            [['192.168.99.0', '192.168.99.255'], ['192.168.101.0', '192.168.101.255']],
        );

        $this->assertSame([['start' => '192.168.100.1', 'end' => '192.168.100.254', 'count' => 254]], $free);
    }

    public function test_a_full_subnet_has_no_free_address(): void
    {
        $this->assertSame([], Ipv4::freeIntervals('192.168.100.0', 24, [], [['192.168.100.1', '192.168.100.254']]));
        $this->assertNull(Ipv4::nextFree('192.168.100.0', 24, [], [['192.168.100.0', '192.168.100.255']]));

        $this->assertSame([], Ipv4::freeIntervals('192.168.100.4', 30, ['192.168.100.5', '192.168.100.6']));
        $this->assertSame([], Ipv4::freeIntervals('192.168.100.4', 31, ['192.168.100.4', '192.168.100.5']));
        $this->assertSame([], Ipv4::freeIntervals('192.168.100.5', 32, ['192.168.100.5']));
    }

    public function test_free_addresses_of_a_slash_30_31_and_32(): void
    {
        $this->assertSame(
            [['start' => '192.168.100.5', 'end' => '192.168.100.6', 'count' => 2]],
            Ipv4::freeIntervals('192.168.100.4', 30),
        );

        $this->assertSame(
            [['start' => '192.168.100.6', 'end' => '192.168.100.6', 'count' => 1]],
            Ipv4::freeIntervals('192.168.100.4', 30, ['192.168.100.5']),
        );

        $this->assertSame(
            [['start' => '192.168.100.4', 'end' => '192.168.100.5', 'count' => 2]],
            Ipv4::freeIntervals('192.168.100.4', 31),
        );

        $this->assertSame(
            [['start' => '192.168.100.5', 'end' => '192.168.100.5', 'count' => 1]],
            Ipv4::freeIntervals('192.168.100.4', 31, ['192.168.100.4']),
        );

        $this->assertSame(
            [['start' => '192.168.100.5', 'end' => '192.168.100.5', 'count' => 1]],
            Ipv4::freeIntervals('192.168.100.5', 32),
        );
    }

    public function test_free_addresses_at_both_ends_of_the_address_space(): void
    {
        $this->assertSame(
            [['start' => '0.0.0.1', 'end' => '0.0.0.2', 'count' => 2]],
            Ipv4::freeIntervals('0.0.0.0', 30),
        );

        $this->assertSame(
            [['start' => '255.255.255.253', 'end' => '255.255.255.254', 'count' => 2]],
            Ipv4::freeIntervals('255.255.255.252', 30),
        );

        $this->assertSame(
            [['start' => '255.255.255.254', 'end' => '255.255.255.254', 'count' => 1]],
            Ipv4::freeIntervals('255.255.255.254', 31, ['255.255.255.255']),
        );

        $this->assertSame([], Ipv4::freeIntervals('255.255.255.255', 32, ['255.255.255.255']));
    }

    public function test_free_addresses_of_a_slash_8_are_not_enumerated(): void
    {
        $started = hrtime(true);

        $free = Ipv4::freeIntervals(
            '10.0.0.0',
            8,
            ['10.0.0.1', '10.0.0.10', '10.128.0.1', '10.255.255.254'],
            [['10.0.1.0', '10.0.1.255']],
        );

        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        $this->assertSame([
            ['start' => '10.0.0.2', 'end' => '10.0.0.9', 'count' => 8],
            ['start' => '10.0.0.11', 'end' => '10.0.0.255', 'count' => 245],
            ['start' => '10.0.2.0', 'end' => '10.128.0.0', 'count' => 8388097],
            ['start' => '10.128.0.2', 'end' => '10.255.255.253', 'count' => 8388604],
        ], $free);

        // 16 777 214 usable addresses, minus 4 documented and 256 in the range.
        $this->assertSame(16777214 - 4 - 256, array_sum(array_column($free, 'count')));

        // Walking through 16 million addresses would take seconds, not this.
        $this->assertLessThan(50, $elapsedMilliseconds);
    }

    public function test_free_addresses_of_a_slash_1(): void
    {
        $free = Ipv4::freeIntervals('128.0.0.0', 1, ['192.168.1.1']);

        $this->assertSame([
            ['start' => '128.0.0.1', 'end' => '192.168.1.0', 'count' => 1084752128],
            ['start' => '192.168.1.2', 'end' => '255.255.255.254', 'count' => 1062731517],
        ], $free);

        $this->assertSame(2147483646 - 1, array_sum(array_column($free, 'count')));
    }

    // --- Intervals cut at the bounds of ranges ----------------------------------

    public function test_intervals_are_cut_at_the_bounds_of_ranges_and_say_which_one_they_lie_in(): void
    {
        $interval = fn (string $start, string $end, ?string $mark = null): array => [
            'start' => $start, 'end' => $end, 'count' => Ipv4::rangeSize($start, $end), 'mark' => $mark,
        ];
        $free = fn (string $start, string $end): array => ['start' => $start, 'end' => $end, 'count' => Ipv4::rangeSize($start, $end)];

        // No range: every interval is left whole, outside of any range.
        $this->assertSame(
            [$interval('10.0.0.1', '10.0.0.254')],
            Ipv4::markIntervals([$free('10.0.0.1', '10.0.0.254')], []),
        );

        // A range in the middle of an interval cuts it in three.
        $this->assertSame(
            [
                $interval('10.0.0.1', '10.0.0.9'),
                $interval('10.0.0.10', '10.0.0.19', 'a'),
                $interval('10.0.0.20', '10.0.0.254'),
            ],
            Ipv4::markIntervals([$free('10.0.0.1', '10.0.0.254')], [['10.0.0.10', '10.0.0.19', 'a']]),
        );

        // Ranges given in any order, at both ends, side by side, and one
        // that spans two intervals.
        $this->assertSame(
            [
                $interval('10.0.0.1', '10.0.0.4', 'start'),
                $interval('10.0.0.5', '10.0.0.9'),
                $interval('10.0.0.20', '10.0.0.24'),
                $interval('10.0.0.25', '10.0.0.29', 'left'),
                $interval('10.0.0.30', '10.0.0.30', 'right'),
                $interval('10.0.0.50', '10.0.0.59', 'right'),
                $interval('10.0.0.60', '10.0.0.249'),
                $interval('10.0.0.250', '10.0.0.254', 'end'),
            ],
            Ipv4::markIntervals(
                [$free('10.0.0.1', '10.0.0.9'), $free('10.0.0.20', '10.0.0.30'), $free('10.0.0.50', '10.0.0.254')],
                [
                    ['10.0.0.250', '10.0.0.254', 'end'],
                    ['10.0.0.30', '10.0.0.59', 'right'],
                    ['10.0.0.1', '10.0.0.4', 'start'],
                    ['10.0.0.25', '10.0.0.29', 'left'],
                ],
            ),
        );

        // A range that covers an interval, or lies outside of all of them.
        $this->assertSame(
            [$interval('10.0.0.5', '10.0.0.9', 'all')],
            Ipv4::markIntervals([$free('10.0.0.5', '10.0.0.9')], [['10.0.0.1', '10.0.0.100', 'all'], ['10.0.1.0', '10.0.1.9', 'elsewhere']]),
        );

        // Nothing is lost or counted twice.
        $pieces = Ipv4::markIntervals(
            [$free('10.0.0.1', '10.0.255.254')],
            [['10.0.0.1', '10.0.0.49', 'a'], ['10.0.1.0', '10.0.1.255', 'b'], ['10.0.200.0', '10.0.255.254', 'c']],
        );

        $this->assertSame(65534, array_sum(array_column($pieces, 'count')));
        $this->assertSame(['a', null, 'b', null, 'c'], array_column($pieces, 'mark'));
    }

    // --- The space of a block -------------------------------------------------

    /**
     * The free prefixes of a block, written in CIDR notation.
     *
     * @param  list<string>  $taken  Blocks in CIDR notation.
     * @return array{prefixes: list<string>, count: int, addresses: int, taken: int}
     */
    private function free(string $block, array $taken = [], int $limit = PHP_INT_MAX): array
    {
        $split = fn (string $cidr): array => [explode('/', $cidr)[0], (int) explode('/', $cidr)[1]];

        [$network, $prefix] = $split($block);

        $free = Ipv4::freePrefixes($network, $prefix, array_map($split, $taken), $limit);

        // Each prefix is aligned: its address is a network address.
        foreach ($free['prefixes'] as $found) {
            $this->assertTrue(Ipv4::isNetworkAddress($found['network'], $found['prefix']), $found['network'].'/'.$found['prefix']);
            $this->assertSame(Ipv4::size($found['prefix']), $found['size']);
        }

        return [
            'prefixes' => array_map(fn (array $found): string => $found['network'].'/'.$found['prefix'], $free['prefixes']),
            'count' => $free['count'],
            'addresses' => $free['addresses'],
            'taken' => $free['taken'],
        ];
    }

    public function test_the_size_of_a_block_counts_every_address(): void
    {
        $this->assertSame(1, Ipv4::size(32));
        $this->assertSame(2, Ipv4::size(31));
        $this->assertSame(4, Ipv4::size(30));
        $this->assertSame(256, Ipv4::size(24));
        $this->assertSame(65536, Ipv4::size(16));
        $this->assertSame(16777216, Ipv4::size(8));
        $this->assertSame(4294967296, Ipv4::size(0));
    }

    public function test_an_empty_block_is_free_as_a_whole(): void
    {
        foreach (['192.168.100.0/24', '10.0.0.0/8', '10.1.2.3/32', '10.1.2.2/31', '0.0.0.0/0', '128.0.0.0/1'] as $block) {
            $this->assertSame(
                ['prefixes' => [$block], 'count' => 1, 'addresses' => Ipv4::size((int) explode('/', $block)[1]), 'taken' => 0],
                $this->free($block),
            );
        }
    }

    public function test_the_free_space_is_given_as_the_largest_aligned_prefixes(): void
    {
        // A /26 at the start of a /24: the second quarter, then the second half.
        $this->assertSame(
            ['prefixes' => ['192.168.100.64/26', '192.168.100.128/25'], 'count' => 2, 'addresses' => 192, 'taken' => 64],
            $this->free('192.168.100.0/24', ['192.168.100.0/26']),
        );

        // A /30 in the middle: what is before it and after it is cut on
        // the boundaries of each size.
        $this->assertSame(
            [
                'prefixes' => [
                    '192.168.100.0/26',
                    '192.168.100.64/27',
                    '192.168.100.96/30',
                    '192.168.100.104/29',
                    '192.168.100.112/28',
                    '192.168.100.128/25',
                ],
                'count' => 6,
                'addresses' => 252,
                'taken' => 4,
            ],
            $this->free('192.168.100.0/24', ['192.168.100.100/30']),
        );

        // Several blocks, given in any order.
        $this->assertSame(
            ['prefixes' => ['10.1.1.0/24', '10.1.3.0/24', '10.1.4.0/22', '10.1.8.0/21', '10.1.16.0/20', '10.1.32.0/19', '10.1.64.0/18', '10.1.128.0/17'], 'count' => 8, 'addresses' => 65536 - 512, 'taken' => 512],
            $this->free('10.1.0.0/16', ['10.1.2.0/24', '10.1.0.0/24']),
        );
    }

    public function test_a_block_that_is_full_has_no_free_space(): void
    {
        $full = ['prefixes' => [], 'count' => 0, 'addresses' => 0, 'taken' => 256];

        $this->assertSame($full, $this->free('192.168.100.0/24', ['192.168.100.0/24']));
        $this->assertSame($full, $this->free('192.168.100.0/24', ['192.168.100.0/25', '192.168.100.128/25']));
        // Something larger than the block only counts for what is inside it.
        $this->assertSame($full, $this->free('192.168.100.0/24', ['192.168.0.0/16']));
    }

    public function test_what_lies_outside_the_block_does_not_count(): void
    {
        $this->assertSame(
            ['prefixes' => ['192.168.100.0/24'], 'count' => 1, 'addresses' => 256, 'taken' => 0],
            $this->free('192.168.100.0/24', ['192.168.99.0/24', '192.168.101.0/24', '10.0.0.0/8']),
        );
    }

    public function test_blocks_that_overlap_each_other_are_counted_once(): void
    {
        $this->assertSame(
            ['prefixes' => ['192.168.100.128/25'], 'count' => 1, 'addresses' => 128, 'taken' => 128],
            $this->free('192.168.100.0/24', ['192.168.100.0/25', '192.168.100.0/26', '192.168.100.64/27']),
        );
    }

    public function test_the_free_space_at_both_ends_of_the_address_space(): void
    {
        $this->assertSame(
            ['prefixes' => ['0.0.0.1/32', '0.0.0.2/31', '0.0.0.4/30', '0.0.0.8/29', '0.0.0.16/28', '0.0.0.32/27', '0.0.0.64/26', '0.0.0.128/25'], 'count' => 8, 'addresses' => 255, 'taken' => 1],
            $this->free('0.0.0.0/24', ['0.0.0.0/32']),
        );

        $this->assertSame(
            ['prefixes' => ['255.255.255.0/25', '255.255.255.128/26', '255.255.255.192/27', '255.255.255.224/28', '255.255.255.240/29', '255.255.255.248/30', '255.255.255.252/31', '255.255.255.254/32'], 'count' => 8, 'addresses' => 255, 'taken' => 1],
            $this->free('255.255.255.0/24', ['255.255.255.255/32']),
        );

        // The whole space, less one address in its middle.
        $free = $this->free('0.0.0.0/0', ['128.0.0.0/32']);

        // The lower half, then one prefix of each size from /32 to /2.
        $this->assertSame(32, $free['count']);
        $this->assertSame(4294967295, $free['addresses']);
        $this->assertSame('0.0.0.0/1', $free['prefixes'][0]);
        $this->assertSame('128.0.0.1/32', $free['prefixes'][1]);
        $this->assertSame('192.0.0.0/2', $free['prefixes'][31]);
    }

    public function test_the_list_of_free_prefixes_can_be_cut_short_and_is_still_counted_in_full(): void
    {
        $free = $this->free('10.0.0.0/8', ['10.200.3.77/32'], limit: 5);

        // One prefix of each size from /9 to /32, all of them counted.
        $this->assertSame(24, $free['count']);
        $this->assertSame(16777215, $free['addresses']);
        $this->assertSame(1, $free['taken']);
        $this->assertSame(['10.0.0.0/9', '10.128.0.0/10', '10.192.0.0/13', '10.200.0.0/23', '10.200.2.0/24'], $free['prefixes']);

        $this->assertSame([], $this->free('10.0.0.0/8', ['10.200.3.77/32'], limit: 0)['prefixes']);
    }

    public function test_the_free_space_of_a_slash_8_is_not_enumerated(): void
    {
        // A thousand /24 scattered in a /8, every other one.
        $taken = [];

        for ($index = 0; $index < 1000; $index++) {
            $taken[] = '10.'.intdiv($index * 2, 256).'.'.(($index * 2) % 256).'.0/24';
        }

        $started = hrtime(true);

        $free = $this->free('10.0.0.0/8', $taken, limit: 50);

        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        $this->assertSame(1000 * 256, $free['taken']);
        $this->assertSame(16777216 - 1000 * 256, $free['addresses']);
        $this->assertCount(50, $free['prefixes']);
        // The 999 gaps between them, then what is left of the /8.
        $this->assertGreaterThan(999, $free['count']);
        $this->assertSame('10.0.1.0/24', $free['prefixes'][0]);

        // Walking through 16 million addresses would take seconds, not this.
        $this->assertLessThan(100, $elapsedMilliseconds);
    }

    /**
     * @return array<string, array{0: string, 1: list<array{0: string, 1: string}>}>
     */
    public static function beginningsOfAddresses(): array
    {
        return [
            'one digit' => ['1', [['1.0.0.0', '1.255.255.255'], ['10.0.0.0', '19.255.255.255'], ['100.0.0.0', '199.255.255.255']]],
            'two digits' => ['10', [['10.0.0.0', '10.255.255.255'], ['100.0.0.0', '109.255.255.255']]],
            'three digits' => ['192', [['192.0.0.0', '192.255.255.255']]],
            'a number and a dot' => ['10.', [['10.0.0.0', '10.255.255.255']]],
            'the second number being typed' => ['10.2', [['10.2.0.0', '10.2.255.255'], ['10.20.0.0', '10.29.255.255'], ['10.200.0.0', '10.255.255.255']]],
            'three numbers and a dot' => ['10.10.10.', [['10.10.10.0', '10.10.10.255']]],
            'the last number being typed' => ['10.10.10.1', [['10.10.10.1', '10.10.10.1'], ['10.10.10.10', '10.10.10.19'], ['10.10.10.100', '10.10.10.199']]],
            'an address in full' => ['192.168.100.254', [['192.168.100.254', '192.168.100.254']]],
            'a zero' => ['10.0', [['10.0.0.0', '10.0.255.255']]],
            'the top of the address space' => ['255.255.255.25', [['255.255.255.25', '255.255.255.25'], ['255.255.255.250', '255.255.255.255']]],
            'the highest first digits' => ['25', [['25.0.0.0', '25.255.255.255'], ['250.0.0.0', '255.255.255.255']]],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $expected
     */
    #[DataProvider('beginningsOfAddresses')]
    public function test_the_addresses_that_start_with_what_was_typed(string $typed, array $expected): void
    {
        $this->assertSame($expected, Ipv4::startingWith($typed));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function whatIsNotTheBeginningOfAnAddress(): array
    {
        return [
            'nothing' => [''],
            'a name' => ['srv-fichiers-01'],
            'a name that starts with digits' => ['10-srv'],
            'a number above 255' => ['256'],
            'a number above 255 in the middle' => ['10.300.1'],
            'a number above 255 being typed' => ['10.10.10.26'.'0'],
            'a leading zero' => ['10.01'],
            'a leading zero in the middle' => ['10.01.2'],
            'five numbers' => ['10.10.10.10.1'],
            'a dot after four numbers' => ['10.10.10.10.'],
            'two dots' => ['10..1'],
            'a dot first' => ['.10'],
            'spaces' => ['10. 10'],
            'a prefix length' => ['10.10.10.0/24'],
        ];
    }

    #[DataProvider('whatIsNotTheBeginningOfAnAddress')]
    public function test_what_is_not_the_beginning_of_an_address_finds_nothing(string $typed): void
    {
        $this->assertSame([], Ipv4::startingWith($typed));
    }
}
