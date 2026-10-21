<?php

// torsade/app/Support/SearchText.php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A text looked for in the documentation, compared the way the database
 * compares it: through the collation of its columns, without regard to
 * case or accents ("etage" finds "Étage"). The database finds the objects;
 * the parts of their values that match are worked out here again, with the
 * same folding, so that they can be shown.
 *
 * LIKE compares one character with one character, so a letter that the
 * collation reads as two ("ß" as "ss") only matches itself there, and a
 * combining accent is a character of its own; "=" compares whole values,
 * reads such a letter as two and leaves a combining accent aside. Each
 * comparison here follows the operator it stands for.
 */
final class SearchText
{
    /**
     * A LIKE pattern that finds the text anywhere in a column.
     */
    public static function containing(string $text): string
    {
        return '%'.self::escape($text).'%';
    }

    /**
     * A LIKE pattern that finds the text at the start of a column.
     */
    public static function startingWith(string $text): string
    {
        return self::escape($text).'%';
    }

    /**
     * "%", "_" and the escape character itself, read as the characters
     * they are: "10_%" finds what was typed, not every value.
     */
    public static function escape(string $text): string
    {
        return addcslashes($text, '\\%_');
    }

    /**
     * Whether the value holds the text, compared as LIKE compares.
     */
    public static function contains(?string $value, string $text): bool
    {
        if ($value === null || $text === '') {
            return false;
        }

        return mb_strpos(implode('', self::fold($value)[0]), implode('', self::fold($text)[0])) !== false;
    }

    /**
     * Whether two values are the same, compared as "=" compares.
     */
    public static function equals(string $value, string $text): bool
    {
        return self::fold($value, whole: true)[0] === self::fold($text, whole: true)[0];
    }

    /**
     * The value cut into the parts that match the text and those that do
     * not, in order, as LIKE finds them: every match is marked, each one
     * the length of what it covers in the value ("Étage" for "etage").
     *
     * @return list<array{text: string, match: bool}>
     */
    public static function segments(string $value, string $text): array
    {
        $characters = mb_str_split($value);
        [$folded, $origins] = self::fold($value);
        $needle = implode('', self::fold($text)[0]);

        if ($needle === '' || $characters === []) {
            return $value === '' ? [] : [['text' => $value, 'match' => false]];
        }

        $haystack = implode('', $folded);
        $length = mb_strlen($needle);
        $segments = [];
        // The first character of the value not yet cut out.
        $position = 0;
        $offset = 0;

        while (($found = mb_strpos($haystack, $needle, $offset)) !== false) {
            $first = $origins[$found];
            $last = $origins[$found + $length - 1];

            // A combining accent written after the last letter belongs to it.
            while ($last + 1 < count($characters) && self::isCombining($characters[$last + 1])) {
                $last++;
            }

            if ($first < $position) {
                $offset = $found + 1;

                continue;
            }

            if ($first > $position) {
                $segments[] = ['text' => implode('', array_slice($characters, $position, $first - $position)), 'match' => false];
            }

            $segments[] = ['text' => implode('', array_slice($characters, $first, $last - $first + 1)), 'match' => true];
            $position = $last + 1;
            $offset = $found + $length;
        }

        if ($position < count($characters)) {
            $segments[] = ['text' => implode('', array_slice($characters, $position)), 'match' => false];
        }

        return $segments;
    }

    /**
     * The value as it is compared: one folded character after another, and
     * for each of them the position of the character of the value it comes
     * from. Compared as a whole value, a letter may give two ("ß" gives
     * "ss") and a combining accent none.
     *
     * @return array{0: list<string>, 1: list<int>}
     */
    private static function fold(string $value, bool $whole = false): array
    {
        $folded = [];
        $origins = [];

        foreach (mb_str_split($value) as $index => $character) {
            foreach (mb_str_split(self::foldCharacter($character, $whole)) as $piece) {
                $folded[] = $piece;
                $origins[] = $index;
            }
        }

        return [$folded, $origins];
    }

    /**
     * A letter without its case and its accents ("É" gives "e"), as the
     * collation of the database compares it; any other character without
     * its case only. The transliteration only applies to the letters of the
     * Latin alphabet: a sign or a letter of another alphabet is kept as it
     * is. A letter that becomes two ("œ" gives "oe") only does so in a
     * comparison of whole values, which alone leaves a combining accent
     * aside.
     */
    private static function foldCharacter(string $character, bool $whole = false): string
    {
        if (self::isCombining($character)) {
            return $whole ? '' : $character;
        }

        if (preg_match('/^\p{Latin}$/u', $character) === 1) {
            $latin = strtolower(Str::ascii($character));

            if (preg_match($whole ? '/^[a-z]+$/D' : '/^[a-z]$/D', $latin) === 1) {
                return $latin;
            }
        }

        return mb_strtolower($character);
    }

    /**
     * Whether the character is a combining accent, written after the letter
     * it marks ("e" then U+0301 for "é").
     */
    private static function isCombining(string $character): bool
    {
        return preg_match('/^\p{M}$/u', $character) === 1;
    }
}
