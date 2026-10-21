<?php

// torsade/tests/Unit/IpAddressCastTest.php

namespace Tests\Unit;

use App\Casts\AsIpAddress;
use App\Models\Subnet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Addresses are binary in the database and text in the application.
 */
class IpAddressCastTest extends TestCase
{
    public function test_an_ipv4_address_is_four_bytes(): void
    {
        $this->assertSame("\xC0\xA8\x64\x0A", AsIpAddress::toBinary('192.168.100.10'));
        $this->assertSame("\x00\x00\x00\x00", AsIpAddress::toBinary('0.0.0.0'));
        $this->assertSame("\xFF\xFF\xFF\xFF", AsIpAddress::toBinary('255.255.255.255'));

        $this->assertSame('192.168.100.10', AsIpAddress::toText("\xC0\xA8\x64\x0A"));
        $this->assertSame('0.0.0.0', AsIpAddress::toText("\x00\x00\x00\x00"));
        $this->assertSame('255.255.255.255', AsIpAddress::toText("\xFF\xFF\xFF\xFF"));
    }

    public function test_the_schema_is_ready_for_ipv6_with_sixteen_bytes(): void
    {
        $binary = AsIpAddress::toBinary('fd00:10::1');

        $this->assertSame(16, strlen($binary));
        $this->assertSame('fd00:10::1', AsIpAddress::toText($binary));
    }

    public function test_binary_order_is_numeric_order(): void
    {
        $addresses = ['192.168.1.10', '10.0.0.1', '192.168.1.9', '9.255.255.255', '192.168.0.255'];

        $binary = array_map(AsIpAddress::toBinary(...), $addresses);
        sort($binary, SORT_STRING);

        $this->assertSame(
            ['9.255.255.255', '10.0.0.1', '192.168.0.255', '192.168.1.9', '192.168.1.10'],
            array_map(AsIpAddress::toText(...), $binary),
        );
    }

    public function test_a_model_reads_and_writes_text(): void
    {
        $subnet = new Subnet(['network' => '192.168.100.0', 'gateway' => '192.168.100.254']);

        $this->assertSame('192.168.100.0', $subnet->network);
        $this->assertSame('192.168.100.254', $subnet->gateway);
        $this->assertSame("\xC0\xA8\x64\x00", $subnet->getAttributes()['network']);
        $this->assertSame("\xC0\xA8\x64\xFE", $subnet->getAttributes()['gateway']);

        $subnet->gateway = null;

        $this->assertNull($subnet->gateway);
        $this->assertNull($subnet->getAttributes()['gateway']);
    }

    public function test_what_is_not_an_address_cannot_be_stored(): void
    {
        foreach (['', 'server', '192.168.100', '192.168.100.256', '192.168.100.10/24'] as $value) {
            try {
                AsIpAddress::toBinary($value);
                $this->fail("[{$value}] was stored.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_stored_value_of_another_length_is_reported(): void
    {
        $this->expectException(UnexpectedValueException::class);

        AsIpAddress::toText("\xC0\xA8\x64");
    }
}
