<?php

// torsade/app/Services/Network/SearchContext.php

namespace App\Services\Network;

use App\Casts\AsIpAddress;
use App\Models\AddressBlock;
use App\Models\Subnet;
use App\Support\SearchInput;
use App\Support\SearchText;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One search under way: what was typed, read, and how many results of a
 * group are asked for from which one on. It writes the conditions of the
 * text search and the parts of a value that match.
 */
final class SearchContext
{
    /**
     * Characters shown before and after a match, in an excerpt of a long
     * value.
     */
    private const int BEFORE = 40;

    private const int AFTER = 80;

    private ?bool $prefixIsDeclared = null;

    public function __construct(
        public readonly SearchInput $input,
        public readonly int $limit,
        public readonly int $offset,
    ) {}

    public function searchesText(): bool
    {
        return $this->input->searchesText();
    }

    /**
     * The text looked for in columns, when it is: anywhere in one of them,
     * and the conditions of an exact match and of a match at the start,
     * that rank the results.
     *
     * @param  list<literal-string>  $columns  Written in this application, never typed.
     * @return array{where: Closure(Builder<covariant Model>): void, exact: list<array{0: literal-string, 1: list<int|string>}>, start: list<array{0: literal-string, 1: list<int|string>}>}|null
     */
    public function text(array $columns): ?array
    {
        if (! $this->searchesText()) {
            return null;
        }

        $text = $this->input->text;
        $containing = SearchText::containing($text);
        $starting = SearchText::startingWith($text);

        return [
            'where' => function (Builder $query) use ($columns, $containing): void {
                $query->where(function (Builder $query) use ($columns, $containing): void {
                    foreach ($columns as $column) {
                        $query->orWhere($column, 'like', $containing);
                    }
                });
            },
            'exact' => array_map(fn (string $column): array => ["{$column} = ?", [$text]], $columns),
            'start' => array_map(fn (string $column): array => ["{$column} LIKE ?", [$starting]], $columns),
        ];
    }

    /**
     * A value of a text field, in parts that say where the text matches;
     * a single part matching nothing when the text is not looked for.
     *
     * @return list<array{text: string, match: bool, mono: bool}>
     */
    public function highlight(string $value, bool $mono = false): array
    {
        $segments = $this->searchesText()
            ? SearchText::segments($value, $this->input->text)
            : [['text' => $value, 'match' => false]];

        return array_map(fn (array $segment): array => [...$segment, 'mono' => $mono], $segments);
    }

    /**
     * A long value cut around the first match, with an ellipsis where
     * it is cut; a short value as a whole.
     *
     * @return list<array{text: string, match: bool, mono: bool}>
     */
    public function excerpt(string $value): array
    {
        $segments = $this->highlight($value);

        if (mb_strlen($value) <= self::BEFORE + self::AFTER) {
            return $segments;
        }

        $start = 0;

        foreach ($segments as $segment) {
            if ($segment['match']) {
                break;
            }

            $start += mb_strlen($segment['text']);
        }

        $from = max(0, $start - self::BEFORE);
        $window = mb_substr($value, $from, self::BEFORE + self::AFTER);
        $ellipsis = ['text' => '…', 'match' => false, 'mono' => false];

        return [
            ...($from > 0 ? [$ellipsis] : []),
            ...$this->highlight($window),
            ...($from + mb_strlen($window) < mb_strlen($value) ? [$ellipsis] : []),
        ];
    }

    /**
     * Whether a subnet or a block is equal to the prefix typed: then only
     * those are looked for, not the prefixes around it.
     */
    public function prefixIsDeclared(): bool
    {
        if ($this->prefixIsDeclared === null) {
            $prefix = $this->input->prefix;

            $this->prefixIsDeclared = $prefix !== null && (
                Subnet::query()->where('network', AsIpAddress::toBinary($prefix['network']))->where('prefix_length', $prefix['prefix'])->exists()
                || AddressBlock::query()->where('network', AsIpAddress::toBinary($prefix['network']))->where('prefix_length', $prefix['prefix'])->exists()
            );
        }

        return $this->prefixIsDeclared;
    }
}
