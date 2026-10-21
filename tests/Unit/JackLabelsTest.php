<?php

// torsade/tests/Unit/JackLabelsTest.php

namespace Tests\Unit;

use App\Support\JackLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The labels of a series of jacks, checked against the table of cases the
 * browser is checked against too.
 */
class JackLabelsTest extends TestCase
{
    /**
     * @return array{labels: list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: list<string>}>}
     */
    private static function table(): array
    {
        /** @var array{labels: list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: list<string>}>} $table */
        $table = json_decode((string) file_get_contents(__DIR__.'/../fixtures/jack-label-cases.json'), true, flags: JSON_THROW_ON_ERROR);

        return $table;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: int, 5: list<string>}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (self::table()['labels'] as $case) {
            $cases[json_encode(array_slice($case, 0, 5), JSON_THROW_ON_ERROR)] = $case;
        }

        return $cases;
    }

    public function test_the_table_has_cases_of_every_sort(): void
    {
        $labels = self::table()['labels'];

        $this->assertGreaterThanOrEqual(8, count($labels));
        // A number that needs more digits than asked for, an empty series.
        $this->assertContains('P100', array_merge(...array_column($labels, 5)));
        $this->assertContains([], array_column($labels, 5));
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('cases')]
    public function test_a_series_gives_its_labels_in_order(string $prefix, string $suffix, int $first, int $last, int $digits, array $expected): void
    {
        $this->assertSame($expected, JackLabels::labels($prefix, $suffix, $first, $last, $digits));
    }
}
