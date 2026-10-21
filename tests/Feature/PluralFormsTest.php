<?php

// torsade/tests/Feature/PluralFormsTest.php

namespace Tests\Feature;

use App\Http\Responses\LockoutResponse;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A string that shows a number next to a noun agrees with that number. In
 * French, zero and one take the singular, two and more the plural: "0
 * ligne", "1 ligne", "2 lignes".
 */
class PluralFormsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private static function catalogue(): array
    {
        return json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Each string that agrees with a number, what replaces its other
     * placeholders, and what it reads for zero, one and two.
     *
     * @return array<string, array{string, array<string, string|int>, string, string, string}>
     */
    public static function strings(): array
    {
        return [
            'lines of the quick entry' => [
                ':count line of :max|:count lines of :max',
                ['max' => 100],
                '0 ligne sur 100',
                '1 ligne sur 100',
                '2 lignes sur 100',
            ],
            'entries of a paginated list' => [
                'Page :current of :last (:total entry)|Page :current of :last (:total entries)',
                ['current' => 1, 'last' => 1, 'total' => ':count'],
                'Page 1 sur 1 (0 entrée)',
                'Page 1 sur 1 (1 entrée)',
                'Page 1 sur 1 (2 entrées)',
            ],
            'addresses of an address plan' => [
                'Address plan of :cidr: :count address.|Address plan of :cidr: :count addresses.',
                ['cidr' => '10.0.0.0/31'],
                "Plan d'adressage de 10.0.0.0/31 : 0 adresse.",
                "Plan d'adressage de 10.0.0.0/31 : 1 adresse.",
                "Plan d'adressage de 10.0.0.0/31 : 2 adresses.",
            ],
            'addresses of a DHCP range' => [
                ':count address|:count addresses',
                [],
                '0 adresse',
                '1 adresse',
                '2 adresses',
            ],
            'usable addresses' => [
                'Usable address: :count|Usable addresses: :count',
                [],
                'Adresse utilisable : 0',
                'Adresse utilisable : 1',
                'Adresses utilisables : 2',
            ],
            'free addresses' => [
                'Usable address that is neither the gateway, nor in a DHCP range, nor documented: :count.|Usable addresses that are neither the gateway, nor in a DHCP range, nor documented: :count.',
                [],
                "Adresse utilisable qui n'est ni la passerelle, ni dans une plage DHCP, ni documentée : 0.",
                "Adresse utilisable qui n'est ni la passerelle, ni dans une plage DHCP, ni documentée : 1.",
                'Adresses utilisables qui ne sont ni la passerelle, ni dans une plage DHCP, ni documentées : 2.',
            ],
            'addresses created at once' => [
                ':count IP address created.|:count IP addresses created.',
                [],
                '0 adresse IP créée.',
                '1 adresse IP créée.',
                '2 adresses IP créées.',
            ],
            'addresses that keep a subnet from being deleted' => [
                'An IP address is still documented in this subnet: delete it first.|:count IP addresses are still documented in this subnet: delete them first.',
                [],
                "Une adresse IP est encore documentée dans ce sous-réseau : supprimez-la d'abord.",
                "Une adresse IP est encore documentée dans ce sous-réseau : supprimez-la d'abord.",
                "Des adresses IP sont encore documentées dans ce sous-réseau (2) : supprimez-les d'abord.",
            ],
            'VLANs that keep a site from being deleted' => [
                'A VLAN still belongs to this site: delete it first.|:count VLANs still belong to this site: delete them first.',
                [],
                "Un VLAN est encore rattaché à ce site : supprimez-le d'abord.",
                "Un VLAN est encore rattaché à ce site : supprimez-le d'abord.",
                "Des VLAN sont encore rattachés à ce site (2) : supprimez-les d'abord.",
            ],
            'what a long list leaves out' => [
                ':items and :count more',
                ['items' => 'a, b'],
                'a, b et 0 de plus',
                'a, b et 1 de plus',
                'a, b et 2 de plus',
            ],
            'seconds before a login can be tried again' => [
                'auth.throttle',
                ['seconds' => ':count'],
                'Trop de tentatives de connexion. Veuillez réessayer dans 0 seconde.',
                'Trop de tentatives de connexion. Veuillez réessayer dans 1 seconde.',
                'Trop de tentatives de connexion. Veuillez réessayer dans 2 secondes.',
            ],
        ];
    }

    /**
     * @param  array<string, string|int>  $replacements
     */
    #[DataProvider('strings')]
    public function test_a_string_agrees_with_zero_one_and_two(string $key, array $replacements, string $zero, string $one, string $two): void
    {
        foreach ([0 => $zero, 1 => $one, 2 => $two] as $number => $expected) {
            $replace = array_map(
                fn (string|int $value): string|int => $value === ':count' ? $number : $value,
                $replacements,
            );

            $this->assertSame($expected, trans_choice($key, $number, $replace), "For {$number}.");
        }

        // The plural holds beyond two.
        $this->assertSame(
            str_replace('2', '37', $two),
            trans_choice($key, 37, array_map(fn (string|int $value): string|int => $value === ':count' ? 37 : $value, $replacements)),
        );
    }

    public function test_the_limit_of_the_quick_entry_is_written_in_the_plural(): void
    {
        $this->assertSame(
            "Il n'est pas possible d'enregistrer plus de :max adresses à la fois.",
            trans_choice('No more than :max address can be saved at once.|No more than :max addresses can be saved at once.', 100),
        );
    }

    public function test_every_string_with_forms_has_as_many_forms_in_french(): void
    {
        $withForms = array_filter(self::catalogue(), fn (string $key): bool => str_contains($key, '|'), ARRAY_FILTER_USE_KEY);

        $this->assertGreaterThanOrEqual(10, count($withForms));

        foreach ($withForms as $key => $translation) {
            $this->assertSame(
                substr_count($key, '|'),
                substr_count($translation, '|'),
                "[{$key}] does not have the same number of forms in French.",
            );

            foreach (explode('|', $translation) as $form) {
                $this->assertNotSame('', trim($form), "[{$key}] has an empty form.");
            }
        }

        // No translation has forms that its source text does not have: it
        // would be shown with its separator.
        foreach (self::catalogue() as $key => $translation) {
            if (! str_contains($key, '|')) {
                $this->assertStringNotContainsString('|', $translation, "[{$key}] has forms but is read without a number.");
            }
        }
    }

    public function test_a_string_with_forms_is_always_read_with_its_number(): void
    {
        $sources = Finder::create()
            ->files()
            ->in([app_path(), base_path('routes'), resource_path('views'), resource_path('js')])
            ->exclude(['actions', 'routes', 'wayfinder'])
            ->name(['*.php', '*.vue', '*.ts']);

        $plain = [];
        $counted = [];

        foreach ($sources as $file) {
            $content = $file->getContents();

            // __(), trans() and $t() read a string as it is; trans_choice(),
            // transChoice() and $tc() pick the form that fits a number.
            preg_match_all('~(?:\b__|\btrans|\$t)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1~s', $content, $read);
            preg_match_all('~(?:\btrans_choice|\btransChoice|\$tc)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1~s', $content, $chosen);

            $plain = [...$plain, ...array_map(stripslashes(...), $read[2])];
            $counted = [...$counted, ...array_map(stripslashes(...), $chosen[2])];
        }

        $this->assertNotEmpty($plain);
        $this->assertGreaterThanOrEqual(10, count(array_unique($counted)));

        foreach (array_unique($plain) as $key) {
            $this->assertStringNotContainsString('|', $key, "[{$key}] has forms but is read without a number.");
            $this->assertNotSame('auth.throttle', $key, 'The lockout message has forms: it is read with its seconds.');
        }

        // And each string with forms of the catalogue is used somewhere.
        foreach (array_keys(self::catalogue()) as $key) {
            if (str_contains($key, '|')) {
                $this->assertContains($key, $counted, "[{$key}] is in the catalogue but is not used.");
            }
        }
    }

    // --- In the application --------------------------------------------------

    private function error(string $field): string
    {
        $errors = session('errors');

        return $errors instanceof ViewErrorBag
            ? $errors->first($field)
            : (string) data_get($errors, "default.messages.{$field}.0", '');
    }

    public function test_a_site_with_one_vlan_says_so_in_the_singular(): void
    {
        $site = Site::factory()->create();
        Vlan::factory()->create(['site_id' => $site->id]);

        $this->actingAs(User::factory()->editor()->create())->delete(route('sites.destroy', $site));

        $this->assertSame("Un VLAN est encore rattaché à ce site : supprimez-le d'abord.", $this->error('site'));

        Vlan::factory()->create(['site_id' => $site->id]);

        $this->actingAs(User::factory()->editor()->create())->delete(route('sites.destroy', $site));

        $this->assertSame("Des VLAN sont encore rattachés à ce site (2) : supprimez-les d'abord.", $this->error('site'));
    }

    public function test_a_subnet_with_one_address_says_so_in_the_singular(): void
    {
        $subnet = Subnet::factory()->cidr('192.168.100.0/24')->create();
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10']);

        $this->actingAs(User::factory()->editor()->create())->delete(route('subnets.destroy', $subnet));

        $this->assertSame(
            "Une adresse IP est encore documentée dans ce sous-réseau : supprimez-la d'abord.",
            $this->error('subnet'),
        );
    }

    public function test_the_quick_entry_announces_one_address_then_several(): void
    {
        $subnet = Subnet::factory()->cidr('192.168.100.0/24')->create();
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->post(route('ip-addresses.bulk', $subnet), ['addresses' => [['address' => '192.168.100.10']]])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '1 adresse IP créée.');

        $this->actingAs($editor)
            ->post(route('ip-addresses.bulk', $subnet), ['addresses' => [['address' => '192.168.100.11'], ['address' => '192.168.100.12']]])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '2 adresses IP créées.');

        // And for three, as the browser test types them.
        $this->actingAs($editor)
            ->post(route('ip-addresses.bulk', $subnet), ['addresses' => [
                ['address' => '192.168.100.13'], ['address' => '192.168.100.14'], ['address' => '192.168.100.15'],
            ]])
            ->assertInertiaFlash('toast.message', '3 adresses IP créées.');
    }

    public function test_a_locked_login_counts_its_seconds_in_the_plural_then_in_the_singular(): void
    {
        $this->assertInstanceOf(LockoutResponse::class, app(LockoutResponseContract::class));

        // The clock stands still, so that the seconds left are known.
        $this->freezeSecond();

        foreach (range(1, 6) as $attempt) {
            $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
        }

        $this->assertSame(
            'Trop de tentatives de connexion. Veuillez réessayer dans 60 secondes.',
            $this->error('email'),
        );

        // One second before the lock is lifted.
        $this->travel(59)->seconds();

        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-password']);

        $this->assertSame(
            'Trop de tentatives de connexion. Veuillez réessayer dans 1 seconde.',
            $this->error('email'),
        );
    }

    public function test_locked_two_factor_codes_count_their_seconds_the_same_way(): void
    {
        $user = User::factory()->withTwoFactor()->create([
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
        ]);

        $this->freezeSecond();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('two-factor.login.store'), ['code' => '000000']);
        }

        $this->post(route('two-factor.login.store'), ['code' => '000000']);

        $this->assertSame(
            'Trop de tentatives de connexion. Veuillez réessayer dans 60 secondes.',
            $this->error('code'),
        );

        $this->travel(59)->seconds();

        $this->post(route('two-factor.login.store'), ['code' => '000000']);

        $this->assertSame(
            'Trop de tentatives de connexion. Veuillez réessayer dans 1 seconde.',
            $this->error('code'),
        );
    }
}
