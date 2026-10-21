<?php

// torsade/tests/Unit/SearchTextTest.php

namespace Tests\Unit;

use App\Support\SearchInput;
use App\Support\SearchText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the search compares and shows: the parts of a value that match,
 * found with the folding of the database (case and accents ignored), and
 * how what was typed is read.
 */
class SearchTextTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: list<array{text: string, match: bool}>}>
     */
    public static function segments(): array
    {
        return [
            'accent in the value' => ['Étage 1', 'etage', [['text' => 'Étage', 'match' => true], ['text' => ' 1', 'match' => false]]],
            'accent typed' => ['Rez-de-chaussee', 'CHAUSSÉE', [['text' => 'Rez-de-', 'match' => false], ['text' => 'chaussee', 'match' => true]]],
            'every match' => ['sw-10-10', '10', [['text' => 'sw-', 'match' => false], ['text' => '10', 'match' => true], ['text' => '-', 'match' => false], ['text' => '10', 'match' => true]]],
            'a letter read as two only matches itself' => ['Straße 2', 'strasse', [['text' => 'Straße 2', 'match' => false]]],
            'a letter read as two, typed as it is' => ['Straße 2', 'STRAßE', [['text' => 'Straße', 'match' => true], ['text' => ' 2', 'match' => false]]],
            'a ligature only matches itself' => ['Cœur de réseau', 'coeur', [['text' => 'Cœur de réseau', 'match' => false]]],
            'a combining accent is a character of its own' => ["Ope\u{0301}ra", 'opera', [['text' => "Ope\u{0301}ra", 'match' => false]]],
            'a combining accent typed as it is' => ["Ope\u{0301}ra", "OPE\u{0301}RA", [['text' => "Ope\u{0301}ra", 'match' => true]]],
            'the accent of the last letter belongs to it' => ["Ope\u{0301}ra", 'ope', [['text' => "Ope\u{0301}", 'match' => true], ['text' => 'ra', 'match' => false]]],
            'wildcards are characters' => ['10%_x', '%_', [['text' => '10', 'match' => false], ['text' => '%_', 'match' => true], ['text' => 'x', 'match' => false]]],
            'no match' => ['Atelier', 'siège', [['text' => 'Atelier', 'match' => false]]],
            'another alphabet is kept' => ['Склад', 'sklad', [['text' => 'Склад', 'match' => false]]],
        ];
    }

    /**
     * @param  list<array{text: string, match: bool}>  $expected
     */
    #[DataProvider('segments')]
    public function test_the_parts_that_match_are_those_the_database_finds(string $value, string $typed, array $expected): void
    {
        $this->assertSame($expected, SearchText::segments($value, $typed));
    }

    public function test_a_letter_read_as_two_or_a_combining_accent_differ_only_for_a_whole_value(): void
    {
        // LIKE compares character by character; "=" compares whole values.
        $this->assertFalse(SearchText::contains('Straße 2', 'strasse'));
        $this->assertTrue(SearchText::contains('Straße 2', 'STRAßE'));
        $this->assertTrue(SearchText::equals('Straße', 'STRASSE'));
        $this->assertTrue(SearchText::equals('Cœur', 'coeur'));
        $this->assertFalse(SearchText::equals('Straße', 'strase'));

        // A combining accent likewise: a character for LIKE, none for "=".
        $this->assertFalse(SearchText::contains("Ope\u{0301}ra", 'opera'));
        $this->assertTrue(SearchText::equals("Ope\u{0301}ra", 'opéra'));
    }

    public function test_the_wildcards_of_like_are_escaped(): void
    {
        $this->assertSame('%10\\%\\_a\\\\b%', SearchText::containing('10%_a\\b'));
        $this->assertSame('aa:bb%', SearchText::startingWith('aa:bb'));
    }

    public function test_what_was_typed_is_read_in_its_forms(): void
    {
        $address = SearchInput::read('  10.1.20.37 ');
        $this->assertSame('10.1.20.37', $address->address);
        $this->assertFalse($address->searchesText());

        $prefix = SearchInput::read('10.1.20.37/24');
        $this->assertSame('10.1.20.0/24', $prefix->prefixCidr());
        $this->assertTrue($prefix->prefixWasBroughtBack());
        $this->assertFalse(SearchInput::read('10.1.20.0/24')->prefixWasBroughtBack());

        $this->assertSame(['10.0.0.0', '10.255.255.255'], SearchInput::read('10.')->addressRange);
        $this->assertSame(['10.1.20.0', '10.1.20.255'], SearchInput::read('10.1.20.')->addressRange);
        // Without its dot, or with a number too large, it is a text.
        $this->assertNull(SearchInput::read('10.1')->addressRange);
        $this->assertNull(SearchInput::read('300.1.')->addressRange);
        $this->assertTrue(SearchInput::read('10.1')->searchesText());

        $this->assertSame(10, SearchInput::read('10')->vid);
        $this->assertSame(4094, SearchInput::read('4094')->vid);
        $this->assertNull(SearchInput::read('4095')->vid);
        $this->assertNull(SearchInput::read('0')->vid);
        $this->assertNull(SearchInput::read('010')->vid);

        $this->assertSame('aa:bb:cc:dd:ee:ff', SearchInput::read('AABB.CCDD.EEFF')->mac);
        $this->assertSame('aa:bb:cc', SearchInput::read('AA-BB-CC')->macPrefix);
        $this->assertNull(SearchInput::read('aa:bb')->macPrefix);
        $this->assertNull(SearchInput::read('aa:bb-cc')->macPrefix);

        $this->assertSame(['device' => 'Baie A switch', 'port' => 'Gi1/0/1'], SearchInput::read('Baie A switch  Gi1/0/1')->devicePort);
        $this->assertNull(SearchInput::read('sw-etage-1')->devicePort);
    }
}
