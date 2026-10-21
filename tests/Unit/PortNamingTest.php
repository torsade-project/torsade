<?php

// torsade/tests/Unit/PortNamingTest.php

namespace Tests\Unit;

use App\Support\PortNaming;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How ports are named from a pattern, on a table of cases that the
 * functions of the browser are checked against as well: the two sides
 * cannot drift apart.
 */
class PortNamingTest extends TestCase
{
    /**
     * The table shared with the tests of the browser.
     *
     * @return array{patterns: list<array{0: string, 1: string|null}>, single_patterns: list<array{0: string, 1: string|null}>, names: list<array{0: string, 1: int, 2: int, 3: int, 4: list<string>}>, clashes: list<array<string, mixed>>}
     */
    private static function table(): array
    {
        $json = (string) file_get_contents(dirname(__DIR__).'/fixtures/port-naming-cases.json');

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function patterns(): array
    {
        $cases = [];

        foreach (self::table()['patterns'] as [$pattern, $problem]) {
            $cases[json_encode($pattern, JSON_THROW_ON_ERROR)] = [$pattern, $problem];
        }

        return $cases;
    }

    /**
     * The patterns that name one port alone.
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function singlePatterns(): array
    {
        $cases = [];

        foreach (self::table()['single_patterns'] as [$pattern, $problem]) {
            $cases[json_encode($pattern, JSON_THROW_ON_ERROR)] = [$pattern, $problem];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: int, 4: list<string>}>
     */
    public static function names(): array
    {
        $cases = [];

        foreach (self::table()['names'] as $case) {
            $cases[$case[0].' on the member '.$case[1].', '.$case[3].' from '.$case[2]] = $case;
        }

        return $cases;
    }

    public function test_the_table_has_cases_of_every_sort(): void
    {
        $table = self::table();

        // Every way a pattern can be wrong, and patterns that are right.
        $problems = array_values(array_unique(array_column($table['patterns'], 1)));

        sort($problems);

        $this->assertSame(
            [null, PortNaming::CHARACTERS, PortNaming::NO_NUMBER, PortNaming::SEVERAL_MEMBERS, PortNaming::SEVERAL_NUMBERS],
            $problems,
        );
        $this->assertGreaterThanOrEqual(8, count($table['names']));
        $this->assertGreaterThanOrEqual(6, count($table['clashes']));

        // For one port alone: every way to be wrong but the missing number,
        // and patterns that do without it.
        $single = array_values(array_unique(array_column($table['single_patterns'], 1)));

        sort($single);

        $this->assertSame([null, PortNaming::CHARACTERS, PortNaming::SEVERAL_MEMBERS, PortNaming::SEVERAL_NUMBERS], $single);
        $this->assertGreaterThanOrEqual(
            4,
            count(array_filter($table['single_patterns'], fn (array $case): bool => $case[1] === null && ! str_contains($case[0], PortNaming::NUMBER))),
        );
    }

    #[DataProvider('singlePatterns')]
    public function test_a_pattern_for_one_port_alone_can_do_without_the_number_of_the_port(string $pattern, ?string $problem): void
    {
        $this->assertSame($problem, PortNaming::problem($pattern, single: true));

        // For several ports, the same pattern needs the number of the
        // port: without it, that is what is wrong first.
        $this->assertSame(
            str_contains($pattern, PortNaming::NUMBER) ? $problem : PortNaming::NO_NUMBER,
            PortNaming::problem($pattern),
        );
    }

    #[DataProvider('patterns')]
    public function test_a_pattern_holds_the_number_of_the_port_once_and_the_number_of_the_member_once_at_most(string $pattern, ?string $problem): void
    {
        $this->assertSame($problem, PortNaming::problem($pattern));
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('names')]
    public function test_the_names_a_pattern_gives(string $pattern, int $member, int $first, int $quantity, array $expected): void
    {
        $this->assertSame($expected, PortNaming::names($pattern, $member, $first, $quantity));

        if ($quantity > 0) {
            $this->assertSame($expected[0], PortNaming::name($pattern, $member, $first));
        }
    }

    public function test_the_groups_that_repeat_a_name_another_group_gives(): void
    {
        foreach (self::table()['clashes'] as $index => $case) {
            $groups = array_map(
                fn (array $group): array => ['name_pattern' => $group[0], 'first_number' => $group[1], 'quantity' => $group[2]],
                $case['groups'],
            );

            $expected = [];

            foreach ($case['clashes'] as $place => [$name, $with]) {
                $expected[(int) $place] = ['name' => $name, 'with' => $with];
            }

            $this->assertSame($expected, PortNaming::clashes($groups, $case['member']), 'case '.$index);
        }
    }

    public function test_the_limits_are_those_of_the_columns(): void
    {
        $this->assertSame(32, PortNaming::MAX_NAME_LENGTH);
        $this->assertSame(128, PortNaming::MAX_QUANTITY);
        $this->assertSame(999, PortNaming::MAX_FIRST_NUMBER);
    }
}
