<?php

// torsade/tests/Unit/DatabaseAccountPrivilegesTest.php

namespace Tests\Unit;

use App\Support\DatabaseAccountPrivileges;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The account of the application owns SELECT, INSERT, UPDATE and DELETE on
 * its database, and nothing else at any level: decided on the lines SHOW
 * GRANTS gives, as MariaDB writes them.
 */
class DatabaseAccountPrivilegesTest extends TestCase
{
    private const string ACCOUNT = '`torsade_app`@`localhost`';

    /**
     * @param  list<string>  $grants
     * @return array{extra: list<string>, missing: list<string>, elsewhere: list<string>, roles: list<string>, grantOption: bool}
     */
    private function assess(array $grants, string $database = 'torsade'): array
    {
        return DatabaseAccountPrivileges::assess($grants, $database);
    }

    public function test_the_four_privileges_on_the_database_are_exactly_what_is_needed(): void
    {
        $assessment = $this->assess([
            'GRANT USAGE ON *.* TO '.self::ACCOUNT.' IDENTIFIED BY PASSWORD \'*0000000000000000000000000000000000000000\'',
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `torsade`.* TO '.self::ACCOUNT,
        ]);

        $this->assertTrue(DatabaseAccountPrivileges::exact($assessment));
    }

    public function test_the_four_privileges_given_in_two_lines_and_in_any_order_are_enough(): void
    {
        $this->assertTrue(DatabaseAccountPrivileges::exact($this->assess([
            'GRANT DELETE, SELECT ON `torsade`.* TO '.self::ACCOUNT,
            'GRANT UPDATE, INSERT ON `torsade`.* TO '.self::ACCOUNT,
        ])));
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: list<string>|bool}>
     */
    public static function wrongGrants(): array
    {
        $account = self::ACCOUNT;
        $four = "GRANT SELECT, INSERT, UPDATE, DELETE ON `torsade`.* TO {$account}";

        return [
            'every privilege on the database' => [["GRANT ALL PRIVILEGES ON `torsade`.* TO {$account}"], 'extra', ['ALL PRIVILEGES']],
            'every privilege on the database misses none' => [["GRANT ALL PRIVILEGES ON `torsade`.* TO {$account}"], 'missing', []],
            'a privilege on the schema' => [[$four, "GRANT CREATE, DROP, ALTER ON `torsade`.* TO {$account}"], 'extra', ['CREATE', 'DROP', 'ALTER']],
            'a missing privilege' => [["GRANT SELECT, INSERT, UPDATE ON `torsade`.* TO {$account}"], 'missing', ['DELETE']],
            'no privilege at all' => [["GRANT USAGE ON *.* TO {$account}"], 'missing', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']],
            'a global privilege' => [[$four, "GRANT SELECT ON *.* TO {$account}"], 'elsewhere', ['*.* : SELECT']],
            'a privilege on another database' => [[$four, "GRANT SELECT ON `torsade_test`.* TO {$account}"], 'elsewhere', ['`torsade_test`.* : SELECT']],
            'a privilege on a table' => [[$four, "GRANT SELECT ON `torsade`.`users` TO {$account}"], 'elsewhere', ['`torsade`.`users` : SELECT']],
            'a privilege on columns' => [[$four, "GRANT SELECT (`email`, `name`), UPDATE (`name`) ON `torsade`.`users` TO {$account}"], 'elsewhere', ['`torsade`.`users` : SELECT, UPDATE']],
            'a proxy' => [[$four, "GRANT PROXY ON ''@'%' TO {$account}"], 'elsewhere', ["''@'%' : PROXY"]],
            'a role' => [[$four, "GRANT `maintenance` TO {$account}"], 'roles', ['`maintenance`']],
            'a role that may be granted on' => [[$four, "GRANT `maintenance` TO {$account} WITH ADMIN OPTION"], 'grantOption', true],
            'the right to grant' => [["GRANT SELECT, INSERT, UPDATE, DELETE ON `torsade`.* TO {$account} WITH GRANT OPTION"], 'grantOption', true],
            'a privilege kept for every account' => [[$four, 'GRANT SELECT ON `torsade`.* TO PUBLIC'], 'elsewhere', ['PUBLIC `torsade`.* : SELECT']],
        ];
    }

    /**
     * @param  list<string>  $grants
     * @param  list<string>|bool  $expected
     */
    #[DataProvider('wrongGrants')]
    public function test_anything_else_at_any_level_breaks_the_rule(array $grants, string $what, array|bool $expected): void
    {
        $assessment = $this->assess($grants);

        $this->assertFalse(DatabaseAccountPrivileges::exact($assessment));

        if ($expected !== []) {
            $this->assertSame($expected, $assessment[$what]);
        }
    }

    public function test_the_database_is_matched_by_its_whole_name(): void
    {
        // A database whose name starts like the one of the application is
        // another one.
        $this->assertFalse(DatabaseAccountPrivileges::exact($this->assess([
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `torsade_old`.* TO '.self::ACCOUNT,
        ])));

        // A name with a backtick is written doubled by the server.
        $this->assertTrue(DatabaseAccountPrivileges::exact($this->assess([
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `tor``sade`.* TO '.self::ACCOUNT,
        ], 'tor`sade')));
    }
}
