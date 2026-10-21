<?php

// torsade/tests/Unit/AddressingSchemeTest.php

namespace Tests\Unit;

use App\Enums\GatewayPosition;
use App\Support\AddressingScheme;
use App\Support\Ipv4;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the addressing rule works out, on a table of cases that the
 * functions of the browser are checked against as well: the two sides
 * cannot drift apart.
 */
class AddressingSchemeTest extends TestCase
{
    /**
     * The table shared with the tests of the browser.
     *
     * @return array{rules: array<string, array<string, mixed>>, cases: list<array<string, mixed>>}
     */
    private static function table(): array
    {
        $json = (string) file_get_contents(dirname(__DIR__).'/fixtures/addressing-rule-cases.json');

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function scheme(array $rule): AddressingScheme
    {
        return new AddressingScheme(
            $rule['network'],
            $rule['prefix_length'],
            $rule['site_prefix_length'],
            $rule['vlan_prefix_length'],
            GatewayPosition::from($rule['gateway_position']),
            $rule['ranges'],
        );
    }

    /**
     * @return array<string, array{0: AddressingScheme, 1: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $table = self::table();
        $cases = [];

        foreach ($table['cases'] as $case) {
            $cases[$case['rule']] = [self::scheme($table['rules'][$case['rule']]), $case];
        }

        return $cases;
    }

    /**
     * @param  array{network: string, prefix_length: int}|null  $prefix
     */
    private static function cidr(?array $prefix): ?string
    {
        return $prefix === null ? null : AddressingScheme::cidr($prefix);
    }

    public function test_the_table_covers_every_rule_it_describes(): void
    {
        $table = self::table();

        $this->assertSame(array_keys($table['rules']), array_column($table['cases'], 'rule'));
        $this->assertGreaterThanOrEqual(6, count($table['cases']));
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('cases')]
    public function test_the_numbers_of_sites_and_the_vids_the_rule_reaches(AddressingScheme $scheme, array $case): void
    {
        $this->assertSame($case['max_site_number'], $scheme->maxSiteNumber());
        $this->assertSame($case['max_vid'], $scheme->maxVid());

        // The bounds, and one step beyond each; no number at all without a
        // level of sites.
        if ($case['max_site_number'] === null) {
            $this->assertFalse($scheme->hasSiteLevel());
            $this->assertFalse($scheme->allowsSiteNumber(0));
        } else {
            $this->assertTrue($scheme->hasSiteLevel());
            $this->assertTrue($scheme->allowsSiteNumber(0));
            $this->assertTrue($scheme->allowsSiteNumber($case['max_site_number']));
            $this->assertFalse($scheme->allowsSiteNumber($case['max_site_number'] + 1));
        }

        $this->assertFalse($scheme->allowsSiteNumber(-1));
        $this->assertFalse($scheme->allowsSiteNumber(null));

        $this->assertFalse($scheme->coversVid(0));
        $this->assertTrue($scheme->coversVid(1));
        $this->assertTrue($scheme->coversVid($case['max_vid']));
        $this->assertFalse($scheme->coversVid($case['max_vid'] + 1));
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('cases')]
    public function test_the_block_expected_for_a_site(AddressingScheme $scheme, array $case): void
    {
        foreach ($case['site_blocks'] as [$number, $expected]) {
            $this->assertSame($expected, self::cidr($scheme->siteBlock($number)), 'Site '.json_encode($number));
        }
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('cases')]
    public function test_the_subnet_expected_for_a_vlan(AddressingScheme $scheme, array $case): void
    {
        foreach ($case['vlan_subnets'] as [$site, $vid, $expected]) {
            $subnet = $scheme->vlanSubnet($site, $vid);

            $this->assertSame($expected, self::cidr($subnet), 'Site '.json_encode($site).", VLAN {$vid}");

            // A subnet that is expected lies in the block of its site, or in
            // the block of the organisation without a level of sites.
            if ($subnet !== null) {
                $block = $scheme->hasSiteLevel()
                    ? $scheme->siteBlock($site)
                    : ['network' => $scheme->network, 'prefix_length' => $scheme->prefix];

                $this->assertNotNull($block);
                $this->assertTrue(Ipv4::prefixContains($block['network'], $block['prefix_length'], $subnet['network'], $subnet['prefix_length']));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('cases')]
    public function test_the_gateway_proposed_for_a_subnet(AddressingScheme $scheme, array $case): void
    {
        foreach ($case['gateways'] as [$network, $prefix, $expected]) {
            $this->assertSame($expected, $scheme->gatewayOf($network, $prefix), "{$network}/{$prefix}");
        }
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('cases')]
    public function test_the_ranges_laid_out_in_a_subnet(AddressingScheme $scheme, array $case): void
    {
        $this->assertSame($scheme->ranges !== [], $scheme->hasBreakdown());

        foreach ($case['breakdowns'] as $breakdown) {
            [$network, $prefix] = explode('/', $breakdown['subnet']);

            $placed = array_map(
                fn (array $range): array => [$range['kind'], $range['start_address'], $range['end_address'], $range['name'], $range['fits']],
                $scheme->breakdownOf($network, (int) $prefix),
            );

            $this->assertSame($breakdown['ranges'], $placed, $breakdown['subnet']);
            $this->assertSame($breakdown['fits'], $scheme->breakdownFits($network, (int) $prefix), $breakdown['subnet']);
        }
    }

    public function test_the_offset_of_the_gateway_in_a_subnet_of_a_vlan(): void
    {
        $at = fn (GatewayPosition $position, int $vlanPrefix): ?int => (new AddressingScheme('10.0.0.0', 8, 16, $vlanPrefix, $position))->gatewayOffset();

        $this->assertSame(1, $at(GatewayPosition::First, 24));
        $this->assertSame(254, $at(GatewayPosition::Last, 24));
        $this->assertSame(62, $at(GatewayPosition::Last, 26));
        $this->assertSame(2, $at(GatewayPosition::Last, 30));
        $this->assertNull($at(GatewayPosition::None, 24));
    }

    public function test_the_example_of_the_documentation(): void
    {
        $scheme = new AddressingScheme('10.0.0.0', 8, 16, 24, GatewayPosition::First);

        $this->assertSame('10.20.0.0/16', self::cidr($scheme->siteBlock(20)));
        $this->assertSame('10.20.10.0/24', self::cidr($scheme->vlanSubnet(20, 10)));
        $this->assertSame('10.20.10.1', $scheme->gatewayOf('10.20.10.0', 24));
        $this->assertFalse($scheme->hasBreakdown());

        $this->assertSame(
            ['network' => '10.0.0.0', 'prefix_length' => 8, 'site_prefix_length' => 16, 'vlan_prefix_length' => 24, 'gateway_position' => 'first', 'ranges' => []],
            $scheme->toArray(),
        );
    }

    public function test_the_example_without_a_level_of_sites(): void
    {
        $scheme = new AddressingScheme('192.168.0.0', 16, null, 24, GatewayPosition::First);

        $this->assertFalse($scheme->hasSiteLevel());
        $this->assertNull($scheme->maxSiteNumber());
        $this->assertSame(255, $scheme->maxVid());

        // Whatever the site, numbered or not.
        foreach ([null, 0, 20] as $site) {
            $this->assertNull($scheme->siteBlock($site));
            $this->assertSame('192.168.1.0/24', self::cidr($scheme->vlanSubnet($site, 1)));
            $this->assertSame('192.168.20.0/24', self::cidr($scheme->vlanSubnet($site, 20)));
            $this->assertSame('192.168.255.0/24', self::cidr($scheme->vlanSubnet($site, 255)));
            $this->assertNull($scheme->vlanSubnet($site, 256));
        }

        $this->assertSame('192.168.20.1', $scheme->gatewayOf('192.168.20.0', 24));
        $this->assertNull($scheme->toArray()['site_prefix_length']);
    }
}
