<?php

// torsade/tests/Feature/Network/StatusAgreementTest.php

namespace Tests\Feature\Network;

use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A status agrees with the object it describes, wherever it is written:
 * "actif, prévu, retiré" for a VLAN or a subnet, "active, prévue, retirée"
 * for an IP address. Tables, filters, forms and the activity log all say
 * the same.
 */
class StatusAgreementTest extends NetworkTestCase
{
    private const MASCULINE = [
        ['value' => 'planned', 'label' => 'Prévu'],
        ['value' => 'active', 'label' => 'Actif'],
        ['value' => 'retired', 'label' => 'Retiré'],
    ];

    private const FEMININE = [
        ['value' => 'planned', 'label' => 'Prévue'],
        ['value' => 'active', 'label' => 'Active'],
        ['value' => 'retired', 'label' => 'Retirée'],
    ];

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function statuses(): array
    {
        return [
            // Status, for a VLAN or a subnet, for an IP address.
            'planned' => ['planned', 'Prévu', 'Prévue'],
            'active' => ['active', 'Actif', 'Active'],
            'retired' => ['retired', 'Retiré', 'Retirée'],
        ];
    }

    // --- Tables --------------------------------------------------------------

    #[DataProvider('statuses')]
    public function test_the_status_of_a_vlan_is_masculine_in_its_list_and_on_its_page(string $status, string $masculine, string $feminine): void
    {
        $vlan = Vlan::factory()->create(['status' => $status]);

        $this->assertNotSame($feminine, $masculine);

        $this->actingAs($this->reader())
            ->get(route('vlans.index'))
            ->assertInertia(fn (Assert $page) => $page->where('vlans.0.status_label', $masculine));

        $this->actingAs($this->reader())
            ->get(route('vlans.show', $vlan))
            ->assertInertia(fn (Assert $page) => $page->where('vlan.status_label', $masculine));
    }

    #[DataProvider('statuses')]
    public function test_the_status_of_a_subnet_is_masculine_in_its_list_and_on_its_page(string $status, string $masculine, string $feminine): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['status' => $status]);

        $this->assertNotSame($feminine, $masculine);

        $this->actingAs($this->reader())
            ->get(route('subnets.index'))
            ->assertInertia(fn (Assert $page) => $page->where('subnets.0.status_label', $masculine));

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $subnet))
            ->assertInertia(fn (Assert $page) => $page->where('subnet.status_label', $masculine));
    }

    #[DataProvider('statuses')]
    public function test_the_status_of_an_ip_address_is_feminine_in_the_table_of_its_subnet(string $status, string $masculine, string $feminine): void
    {
        $subnet = $this->subnet('192.168.100.0/24');

        $this->assertNotSame($masculine, $feminine);

        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10', 'status' => $status]);

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $subnet))
            ->assertInertia(fn (Assert $page) => $page->where('addresses.0.status_label', $feminine));
    }

    // --- Filters and forms ---------------------------------------------------

    public function test_the_filter_and_the_forms_of_a_vlan_offer_masculine_statuses(): void
    {
        $vlan = Vlan::factory()->create();

        $this->actingAs($this->editor())
            ->get(route('vlans.index'))
            ->assertInertia(fn (Assert $page) => $page->where('statuses', self::MASCULINE));

        $this->actingAs($this->editor())
            ->get(route('vlans.create'))
            ->assertInertia(fn (Assert $page) => $page->where('statuses', self::MASCULINE));

        $this->actingAs($this->editor())
            ->get(route('vlans.edit', $vlan))
            ->assertInertia(fn (Assert $page) => $page->where('statuses', self::MASCULINE));
    }

    public function test_the_forms_of_a_subnet_offer_masculine_statuses(): void
    {
        Site::factory()->create();
        $subnet = $this->subnet('192.168.100.0/24');

        $this->actingAs($this->editor())
            ->get(route('subnets.create'))
            ->assertInertia(fn (Assert $page) => $page->where('statuses', self::MASCULINE));

        $this->actingAs($this->editor())
            ->get(route('subnets.edit', $subnet))
            ->assertInertia(fn (Assert $page) => $page->where('statuses', self::MASCULINE));
    }

    public function test_the_forms_of_an_ip_address_offer_feminine_statuses(): void
    {
        // The window of one address and the quick entry of several, both
        // on the page of the subnet.
        $this->actingAs($this->editor())
            ->get(route('subnets.show', $this->subnet('192.168.100.0/24')))
            ->assertInertia(fn (Assert $page) => $page->where('statuses', self::FEMININE));
    }

    // --- Activity log --------------------------------------------------------

    /**
     * The "status" line of each entry of the log, newest first.
     *
     * @return list<array{event: string, old: string|null, new: string|null}>
     */
    private function loggedStatuses(): array
    {
        /** @var list<array{event: string, changes: list<array{field: string, old: string|null, new: string|null}>}> $entries */
        $entries = $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertOk()
            ->inertiaProps('entries.data');

        $lines = [];

        foreach ($entries as $entry) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] === 'Statut') {
                    $lines[] = ['event' => $entry['event'], 'old' => $change['old'], 'new' => $change['new']];
                }
            }
        }

        return $lines;
    }

    public function test_the_log_writes_the_status_of_a_vlan_and_of_a_subnet_in_the_masculine(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create();

        $this->actingAs($editor)->post(route('vlans.store'), [
            'site_id' => $site->id,
            'vid' => 30,
            'name' => 'Bureautique',
            'status' => 'planned',
            'with_subnet' => true,
            'subnet' => ['network' => '192.168.100.0', 'prefix_length' => 24],
        ])->assertSessionHasNoErrors();

        $vlan = Vlan::query()->sole();

        $this->actingAs($editor)->put(route('vlans.update', $vlan), [
            'site_id' => $site->id,
            'vid' => 30,
            'name' => 'Bureautique',
            'status' => 'retired',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            [
                ['event' => 'vlan.updated', 'old' => 'Prévu', 'new' => 'Retiré'],
                // A subnet created with its VLAN takes its status.
                ['event' => 'subnet.created', 'old' => null, 'new' => 'Prévu'],
                ['event' => 'vlan.created', 'old' => null, 'new' => 'Prévu'],
            ],
            $this->loggedStatuses(),
        );
    }

    public function test_the_log_writes_the_status_of_an_ip_address_in_the_feminine(): void
    {
        $editor = $this->editor();
        $subnet = $this->subnet('192.168.100.0/24');

        $this->actingAs($editor)->post(route('ip-addresses.store', $subnet), [
            'address' => '192.168.100.10',
            'assignment' => 'static',
            'status' => 'planned',
        ])->assertSessionHasNoErrors();

        $address = IpAddress::query()->sole();

        foreach (['active', 'retired'] as $status) {
            $this->actingAs($editor)->put(route('ip-addresses.update', $address), [
                'address' => '192.168.100.10',
                'assignment' => 'static',
                'status' => $status,
            ])->assertSessionHasNoErrors();
        }

        $this->actingAs($editor)->delete(route('ip-addresses.destroy', $address))->assertSessionHasNoErrors();

        $this->assertSame(
            [
                ['event' => 'ip_address.deleted', 'old' => 'Retirée', 'new' => null],
                ['event' => 'ip_address.updated', 'old' => 'Active', 'new' => 'Retirée'],
                ['event' => 'ip_address.updated', 'old' => 'Prévue', 'new' => 'Active'],
                ['event' => 'ip_address.created', 'old' => null, 'new' => 'Prévue'],
            ],
            $this->loggedStatuses(),
        );
    }

    public function test_the_status_of_an_account_keeps_its_own_wording(): void
    {
        // An account is "actif" or "désactivé": another notion, untouched.
        $catalogue = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($catalogue);
        $this->assertSame('Actif', $catalogue['Active']);
        $this->assertSame('Désactivé', $catalogue['Inactive']);
    }
}
