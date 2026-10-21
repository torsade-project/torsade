<?php

// torsade/app/Support/DatabaseAccountPrivileges.php

namespace App\Support;

/**
 * Whether the account the application connects with owns exactly what it
 * needs: SELECT, INSERT, UPDATE and DELETE on the database of the
 * application, and nothing else at any level, global, database, table,
 * column, proxy or role. Changing the schema is left to another account,
 * kept for the installation and the updates.
 *
 * The decision works on the lines SHOW GRANTS gives, so that a test can
 * prove it on lists of grants; only the live check reads them from the
 * server.
 */
final class DatabaseAccountPrivileges
{
    /**
     * @var list<string>
     */
    public const array EXPECTED = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    /**
     * What breaks the rule in the grants of an account: the privileges on
     * the database beyond the expected ones, those missing, those on
     * anything else, the roles granted, and whether the account may grant
     * its privileges. Nothing at all when the account owns exactly what it
     * needs.
     *
     * @param  list<string>  $grants  The lines of SHOW GRANTS for the account.
     * @return array{extra: list<string>, missing: list<string>, elsewhere: list<string>, roles: list<string>, grantOption: bool}
     */
    public static function assess(array $grants, string $database): array
    {
        $own = '`'.str_replace('`', '``', $database).'`.*';
        $onDatabase = [];
        $elsewhere = [];
        $roles = [];
        $grantOption = false;

        foreach ($grants as $grant) {
            $grant = trim($grant);

            if (preg_match('/\bWITH\s+(?:GRANT|ADMIN)\s+OPTION\b/i', $grant) === 1) {
                $grantOption = true;
            }

            // A privilege on something: GRANT <privileges> ON <what> TO …
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/is', $grant, $matches) === 1) {
                $privileges = self::privileges($matches[1]);
                $target = trim($matches[2]);

                // USAGE grants nothing: every account has it on *.*.
                $privileges = array_values(array_diff($privileges, ['USAGE']));

                if ($privileges === []) {
                    continue;
                }

                // A privilege kept for every account opens the database to
                // all of them, whatever it is on.
                if (preg_match('/\sTO\s+PUBLIC\s*$/i', $grant) === 1) {
                    $elsewhere[] = 'PUBLIC '.$target.' : '.implode(', ', $privileges);

                    continue;
                }

                if ($target === $own) {
                    $onDatabase = [...$onDatabase, ...$privileges];
                } else {
                    $elsewhere[] = $target.' : '.implode(', ', $privileges);
                }

                continue;
            }

            // A role: GRANT <role> TO …
            if (preg_match('/^GRANT\s+(.+?)\s+TO\s+/is', $grant, $matches) === 1) {
                $roles[] = trim($matches[1]);
            }
        }

        $onDatabase = array_values(array_unique($onDatabase));

        // ALL holds the four expected privileges, among the others: none of
        // them is missing, and ALL is too much.
        $all = array_intersect($onDatabase, ['ALL', 'ALL PRIVILEGES']) !== [];

        return [
            'extra' => array_values(array_diff($onDatabase, self::EXPECTED)),
            'missing' => $all ? [] : array_values(array_diff(self::EXPECTED, $onDatabase)),
            'elsewhere' => $elsewhere,
            'roles' => $roles,
            'grantOption' => $grantOption,
        ];
    }

    /**
     * Whether the account owns exactly what it needs.
     *
     * @param  array{extra: list<string>, missing: list<string>, elsewhere: list<string>, roles: list<string>, grantOption: bool}  $assessment
     */
    public static function exact(array $assessment): bool
    {
        return $assessment['extra'] === []
            && $assessment['missing'] === []
            && $assessment['elsewhere'] === []
            && $assessment['roles'] === []
            && ! $assessment['grantOption'];
    }

    /**
     * The privileges of a list such as "SELECT, UPDATE (`name`, `email`)":
     * a privilege on columns counts as the privilege itself.
     *
     * @return list<string>
     */
    private static function privileges(string $list): array
    {
        $list = (string) preg_replace('/\([^)]*\)/', '', $list);

        return array_values(array_filter(array_map(
            fn (string $privilege): string => strtoupper((string) preg_replace('/\s+/', ' ', trim($privilege))),
            explode(',', $list),
        )));
    }
}
