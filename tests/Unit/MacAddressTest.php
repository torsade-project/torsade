<?php

// torsade/tests/Unit/MacAddressTest.php

namespace Tests\Unit;

use App\Support\MacAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MacAddressTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedNotations(): array
    {
        return [
            'colons' => ['aa:bb:cc:dd:ee:ff'],
            'colons, upper case' => ['AA:BB:CC:DD:EE:FF'],
            'hyphens' => ['aa-bb-cc-dd-ee-ff'],
            'hyphens, upper case' => ['AA-BB-CC-DD-EE-FF'],
            'dots' => ['aabb.ccdd.eeff'],
            'dots, mixed case' => ['AaBb.CcDd.EeFf'],
            'no separator' => ['aabbccddeeff'],
            'no separator, upper case' => ['AABBCCDDEEFF'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedValues(): array
    {
        return [
            'empty' => [''],
            'too short' => ['aa:bb:cc:dd:ee'],
            'too long' => ['aa:bb:cc:dd:ee:ff:00'],
            'not hexadecimal' => ['aa:bb:cc:dd:ee:gg'],
            'mixed separators' => ['aa:bb-cc:dd-ee:ff'],
            'single digits' => ['a:b:c:d:e:f'],
            'dots every two digits' => ['aa.bb.cc.dd.ee.ff'],
            'colons every four digits' => ['aabb:ccdd:eeff'],
            'spaces as separators' => ['aa bb cc dd ee ff'],
            'trailing space' => ['aa:bb:cc:dd:ee:ff '],
            'trailing newline' => ["aa:bb:cc:dd:ee:ff\n"],
            'eleven digits' => ['aabbccddeef'],
            'thirteen digits' => ['aabbccddeeff0'],
            'an IP address' => ['192.168.100.10'],
        ];
    }

    #[DataProvider('acceptedNotations')]
    public function test_the_four_notations_give_the_same_stored_form(string $typed): void
    {
        $this->assertTrue(MacAddress::isValid($typed));
        $this->assertSame('aa:bb:cc:dd:ee:ff', MacAddress::normalise($typed));
    }

    #[DataProvider('refusedValues')]
    public function test_anything_else_is_refused(string $typed): void
    {
        $this->assertFalse(MacAddress::isValid($typed));
        $this->assertNull(MacAddress::normalise($typed));
    }

    public function test_digits_are_kept_in_order(): void
    {
        $this->assertSame('00:1a:2b:3c:4d:5e', MacAddress::normalise('001A.2B3C.4D5E'));
        $this->assertSame('00:1a:2b:3c:4d:5e', MacAddress::normalise('00-1A-2B-3C-4D-5E'));
        $this->assertSame('01:23:45:67:89:ab', MacAddress::normalise('0123456789AB'));
    }
}
