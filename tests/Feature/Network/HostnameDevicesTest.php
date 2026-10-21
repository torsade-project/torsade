<?php

// torsade/tests/Feature/Network/HostnameDevicesTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\Status;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Network\HostnameDevices;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * The devices of a site created from the host names of its addresses: each
 * distinct name becomes a device, or joins the device that already bears
 * it, and its addresses become interfaces. All of it, or nothing.
 */
class HostnameDevicesTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Subnet $servers;

    private Subnet $offices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);

        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 20, 'name' => 'Serveurs']);

        // Two subnets of the site: one carried by a VLAN, one without.
        $this->servers = Subnet::factory()->cidr('10.10.20.0/24')->create(['vlan_id' => $vlan->id]);
        $this->offices = $this->subnet('10.10.10.0/24', ['site_id' => $this->office->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function address(Subnet $subnet, string $address, ?string $hostname, array $attributes = []): IpAddress
    {
        return IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => $address, 'hostname' => $hostname, ...$attributes]);
    }

    /**
     * The host names as they are stored, by address.
     *
     * @return array<string, string|null>
     */
    private function hostnames(): array
    {
        return IpAddress::query()->orderBy('address')->get()
            ->mapWithKeys(fn (IpAddress $address): array => [$address->address => $address->hostname])
            ->all();
    }

    /**
     * @param  list<array<string, string>>  $lines
     * @return TestResponse<Response>
     */
    private function apply(array $lines, ?Site $site = null): TestResponse
    {
        // A line that says nothing of the status asks for a device in
        // service, as the page proposes most of the time.
        $lines = array_map(
            fn (array $line): array => isset($line['hostname'], $line['type']) ? ['status' => 'active', ...$line] : $line,
            $lines,
        );

        return $this->actingAs($this->editor)->post(route('sites.hostname-devices.store', $site ?? $this->office), ['lines' => $lines]);
    }

    // --- The page -------------------------------------------------------------

    public function test_the_page_lists_the_distinct_host_names_whatever_their_case(): void
    {
        // One machine, three addresses, written three ways.
        $this->address($this->servers, '10.10.20.10', 'SRV-Fichiers-01', ['device_type' => 'server']);
        $this->address($this->servers, '10.10.20.11', 'srv-fichiers-01', ['device_type' => 'server']);
        $this->address($this->offices, '10.10.10.10', 'SRV-FICHIERS-01', ['device_type' => 'nas']);
        // More addresses than a line shows.
        foreach ([1, 2, 3, 4, 5] as $host) {
            $this->address($this->offices, '10.10.10.'.(100 + $host), 'fw-siege');
        }
        $this->address($this->offices, '10.10.10.50', 'imp-accueil', ['device_type' => 'printer']);

        // What is left out: no host name, another site, no site, and an
        // address that already belongs to a device.
        $this->address($this->offices, '10.10.10.60', null);
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $this->address($this->subnet('10.20.10.0/24', ['site_id' => $workshop->id]), '10.20.10.10', 'presse-01');
        $this->address($this->subnet('172.16.0.0/30'), '172.16.0.1', 'rtr-lien');
        $this->address($this->offices, '10.10.10.70', 'sw-coeur', ['device_id' => Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur'])->id]);

        $this->actingAs($this->editor)
            ->get(route('sites.hostname-devices.create', $this->office))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/HostnameDevices')
                ->where('site', ['id' => $this->office->id, 'name' => 'Siège'])
                // By name.
                ->where('candidates', [
                    [
                        'hostname' => 'fw-siege', 'addresses_count' => 5,
                        // Three addresses at most, the lowest first.
                        'addresses' => ['10.10.10.101', '10.10.10.102', '10.10.10.103'],
                        // No address says what it is.
                        'type' => 'other', 'status' => 'active', 'device' => null, 'too_long' => false,
                    ],
                    ['hostname' => 'imp-accueil', 'addresses_count' => 1, 'addresses' => ['10.10.10.50'], 'type' => 'printer', 'status' => 'active', 'device' => null, 'too_long' => false],
                    [
                        // As its lowest address writes it.
                        'hostname' => 'SRV-FICHIERS-01', 'addresses_count' => 3,
                        'addresses' => ['10.10.10.10', '10.10.20.10', '10.10.20.11'],
                        // The type most of its addresses have.
                        'type' => 'server', 'status' => 'active', 'device' => null, 'too_long' => false,
                    ],
                ])
                ->has('types', count(DeviceType::cases()))
                ->where('statuses', [
                    ['value' => 'planned', 'label' => 'Prévu'],
                    ['value' => 'active', 'label' => 'Actif'],
                    ['value' => 'retired', 'label' => 'Retiré'],
                ])
                ->where('maxLines', HostnameDevices::MAX_LINES)
                ->where('maxNameLength', 100));
    }

    public function test_the_type_proposed_is_the_most_frequent_one_the_first_met_when_equal_and_other_when_none(): void
    {
        $this->address($this->offices, '10.10.10.1', 'a-two-to-one', ['device_type' => 'switch']);
        $this->address($this->offices, '10.10.10.2', 'a-two-to-one', ['device_type' => 'router']);
        $this->address($this->offices, '10.10.10.3', 'a-two-to-one', ['device_type' => 'router']);

        $this->address($this->offices, '10.10.10.11', 'b-equal', ['device_type' => 'nas']);
        $this->address($this->offices, '10.10.10.12', 'b-equal', ['device_type' => 'server']);

        $this->address($this->offices, '10.10.10.21', 'c-untyped');
        $this->address($this->offices, '10.10.10.22', 'c-untyped');

        $this->address($this->offices, '10.10.10.31', 'd-partly-typed');
        $this->address($this->offices, '10.10.10.32', 'd-partly-typed', ['device_type' => 'camera']);

        $this->actingAs($this->editor)
            ->get(route('sites.hostname-devices.create', $this->office))
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.0.type', 'router')
                ->where('candidates.1.type', 'nas')
                ->where('candidates.2.type', 'other')
                ->where('candidates.3.type', 'camera'));
    }

    public function test_the_status_proposed_follows_the_site_and_the_addresses_of_the_name(): void
    {
        $this->address($this->offices, '10.10.10.1', 'a-all-planned', ['status' => 'planned']);
        $this->address($this->offices, '10.10.10.2', 'a-all-planned', ['status' => 'planned']);

        $this->address($this->offices, '10.10.10.11', 'b-all-retired', ['status' => 'retired']);

        $this->address($this->offices, '10.10.10.21', 'c-planned-and-active', ['status' => 'planned']);
        $this->address($this->offices, '10.10.10.22', 'c-planned-and-active');

        $this->address($this->offices, '10.10.10.31', 'd-retired-and-planned', ['status' => 'retired']);
        $this->address($this->offices, '10.10.10.32', 'd-retired-and-planned', ['status' => 'planned']);

        $this->address($this->offices, '10.10.10.41', 'e-active');

        $proposed = fn (): array => array_column(
            (array) $this->actingAs($this->editor)->get(route('sites.hostname-devices.create', $this->office))->assertOk()->inertiaProps('candidates'),
            'status',
            'hostname',
        );

        // Planned when all the addresses are, retired when all are, in
        // service otherwise.
        $this->assertSame([
            'a-all-planned' => 'planned',
            'b-all-retired' => 'retired',
            'c-planned-and-active' => 'active',
            'd-retired-and-planned' => 'active',
            'e-active' => 'active',
        ], $proposed());

        // On a site that is only planned, everything is.
        $this->office->update(['status' => Status::Planned]);

        $this->assertSame(['planned'], array_values(array_unique($proposed())));

        // On a retired site, the addresses decide as on a site in service.
        $this->office->update(['status' => Status::Retired]);

        $this->assertSame(['planned', 'retired', 'active', 'active', 'active'], array_values($proposed()));
    }

    public function test_a_device_is_created_with_the_status_of_its_line_and_without_a_room(): void
    {
        $existing = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur', 'status' => Status::Active]);

        $this->address($this->offices, '10.10.10.1', 'nas-02', ['status' => 'planned']);
        $this->address($this->offices, '10.10.10.2', 'srv-ancien', ['status' => 'retired']);
        $this->address($this->offices, '10.10.10.3', 'srv-01');
        $this->address($this->offices, '10.10.10.4', 'sw-coeur');

        $this->apply([
            // As proposed, and changed by hand.
            ['hostname' => 'nas-02', 'type' => 'nas', 'status' => 'planned'],
            ['hostname' => 'srv-ancien', 'type' => 'server', 'status' => 'retired'],
            ['hostname' => 'srv-01', 'type' => 'server', 'status' => 'planned'],
            // The device is already there: the status sent changes nothing.
            ['hostname' => 'sw-coeur', 'type' => 'switch', 'status' => 'retired'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['nas-02' => 'planned', 'srv-01' => 'planned', 'srv-ancien' => 'retired', 'sw-coeur' => 'active'],
            Device::query()->orderBy('name')->get()->mapWithKeys(fn (Device $device): array => [$device->name => $device->status->value])->all(),
        );
        $this->assertSame(0, Device::query()->whereNotNull('room_id')->count());
        $this->assertSame(Status::Active, $existing->refresh()->status);

        // The addresses keep their own status.
        $this->assertSame(
            ['planned', 'retired', 'active', 'active'],
            IpAddress::query()->orderBy('address')->get()->map(fn (IpAddress $address): string => $address->status->value)->all(),
        );
    }

    public function test_a_name_the_site_already_has_a_device_for_is_proposed_for_that_device(): void
    {
        $existing = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'FW-Siege', 'type' => DeviceType::Firewall]);
        // A device of that name on another site is not this one.
        Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'srv-01']);

        $this->address($this->offices, '10.10.10.1', 'fw-siege', ['device_type' => 'router']);
        $this->address($this->offices, '10.10.10.10', 'srv-01', ['device_type' => 'server']);

        $this->actingAs($this->editor)
            ->get(route('sites.hostname-devices.create', $this->office))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates', 2)
                ->where('candidates.0.hostname', 'fw-siege')
                ->where('candidates.0.device', ['id' => $existing->id, 'name' => 'FW-Siege', 'type_label' => 'Pare-feu', 'status_label' => 'Actif'])
                ->where('candidates.1.hostname', 'srv-01')
                ->where('candidates.1.device', null));
    }

    public function test_the_page_says_so_when_nothing_is_left_to_do(): void
    {
        $this->address($this->offices, '10.10.10.60', null);

        $this->actingAs($this->editor)
            ->get(route('sites.hostname-devices.create', $this->office))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('candidates', []));
    }

    // --- Doing it -------------------------------------------------------------

    public function test_each_ticked_name_becomes_a_device_and_its_addresses_its_interfaces(): void
    {
        $this->address($this->servers, '10.10.20.10', 'SRV-Fichiers-01', ['device_type' => 'server']);
        $this->address($this->servers, '10.10.20.11', 'srv-fichiers-01', ['device_type' => 'server']);
        $this->address($this->offices, '10.10.10.10', 'srv-fichiers-01');
        $this->address($this->offices, '10.10.10.1', 'fw-siege', ['device_type' => 'firewall']);
        $this->address($this->offices, '10.10.10.50', 'imp-accueil', ['device_type' => 'printer']);

        $before = $this->hostnames();

        $this->apply([
            // The name may come back in any case; the type can be changed.
            ['hostname' => 'SRV-FICHIERS-01', 'type' => 'nas'],
            ['hostname' => 'fw-siege', 'type' => 'firewall'],
        ])
            ->assertRedirect(route('sites.show', $this->office))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '2 équipements créés, 4 adresses rattachées.');

        $devices = Device::query()->orderBy('name')->get();

        $this->assertCount(2, $devices);

        // Named as the lowest address of the group writes it, on the site,
        // without a room, in service.
        $this->assertSame(
            [['fw-siege', DeviceType::Firewall], ['srv-fichiers-01', DeviceType::Nas]],
            $devices->map(fn (Device $device): array => [$device->name, $device->type])->all(),
        );

        foreach ($devices as $device) {
            $this->assertSame([$this->office->id, null, Status::Active], [$device->site_id, $device->room_id, $device->status]);
        }

        $this->assertSame(
            ['10.10.10.10', '10.10.20.10', '10.10.20.11'],
            $devices[1]->ipAddresses()->orderBy('address')->get()->map(fn (IpAddress $address): string => $address->address)->all(),
        );
        $this->assertSame(1, $devices[0]->ipAddresses()->count());

        // What was not ticked is left as it was.
        $this->assertNull(IpAddress::query()->where('hostname', 'imp-accueil')->sole()->device_id);

        // No host name was touched, nor any type of address.
        $this->assertSame($before, $this->hostnames());
        $this->assertSame(['server', 'server', null, 'firewall', 'printer'], [
            IpAddress::query()->where('address', inet_pton('10.10.20.10'))->sole()->device_type?->value,
            IpAddress::query()->where('address', inet_pton('10.10.20.11'))->sole()->device_type?->value,
            IpAddress::query()->where('address', inet_pton('10.10.10.10'))->sole()->device_type?->value,
            IpAddress::query()->where('address', inet_pton('10.10.10.1'))->sole()->device_type?->value,
            IpAddress::query()->where('address', inet_pton('10.10.10.50'))->sole()->device_type?->value,
        ]);

        // Each device created and each address attached is in the log.
        $this->assertSame(2, $this->countEntries(ActivityEvent::DeviceCreated));
        $this->assertSame(4, $this->countEntries(ActivityEvent::IpAddressAttached));
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressUpdated));

        $attached = $this->lastEntry(ActivityEvent::IpAddressAttached)->attribute_changes?->toArray() ?? [];

        $this->assertSame([], $attached['old']);
        $this->assertSame(['device' => 'fw-siege (Siège)'], $attached['attributes']);

        // What is left is still proposed; what was done is not.
        $this->actingAs($this->editor)
            ->get(route('sites.hostname-devices.create', $this->office))
            ->assertInertia(fn (Assert $page) => $page->has('candidates', 1)->where('candidates.0.hostname', 'imp-accueil'));
    }

    public function test_two_names_that_differ_only_by_an_accent_are_the_same_name(): void
    {
        // A host name has no accent; the name of a device may have one. The
        // device "imp-étage-2" is the machine "imp-etage-2", as the names of
        // two devices would be the same.
        $this->address($this->offices, '10.10.10.20', 'imp-etage-1', ['device_type' => 'printer']);
        $this->address($this->servers, '10.10.20.20', 'IMP-ETAGE-1');
        $this->address($this->offices, '10.10.10.21', 'imp-etage-2');
        $existing = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'imp-étage-2', 'type' => DeviceType::Printer]);

        $this->actingAs($this->editor)
            ->get(route('sites.hostname-devices.create', $this->office))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates', 2)
                // One name, as its lowest address writes it.
                ->where('candidates.0.hostname', 'imp-etage-1')
                ->where('candidates.0.addresses', ['10.10.10.20', '10.10.20.20'])
                ->where('candidates.0.device', null)
                // The device of the site whose name differs by an accent.
                ->where('candidates.1.hostname', 'imp-etage-2')
                ->where('candidates.1.device.id', $existing->id));

        // The line may come back written another way.
        $this->apply([['hostname' => 'IMP-ETAGE-1', 'type' => 'printer'], ['hostname' => 'imp-etage-2', 'type' => 'printer']])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '1 équipement créé, 1 rattachement, 3 adresses rattachées.');

        $created = Device::query()->whereKeyNot($existing->id)->sole();

        $this->assertSame('imp-etage-1', $created->name);
        $this->assertSame(2, $created->ipAddresses()->count());
        // No second device "imp-etage-2": the addresses joined the one there.
        $this->assertSame(1, $existing->ipAddresses()->count());
        $this->assertSame(2, Device::query()->count());
    }

    public function test_the_addresses_join_the_device_that_already_bears_their_name(): void
    {
        $existing = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'FW-Siege', 'type' => DeviceType::Firewall]);

        $this->address($this->offices, '10.10.10.1', 'fw-siege', ['device_type' => 'router']);
        $this->address($this->servers, '10.10.20.1', 'FW-SIEGE');
        $this->address($this->offices, '10.10.10.10', 'srv-01', ['device_type' => 'server']);

        $before = $this->hostnames();

        // The type sent for a device that is already there changes nothing.
        $this->apply([['hostname' => 'fw-siege', 'type' => 'router'], ['hostname' => 'srv-01', 'type' => 'server']])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '1 équipement créé, 1 rattachement, 3 adresses rattachées.');

        $this->assertSame(2, Device::query()->count());
        $this->assertSame(2, $existing->ipAddresses()->count());
        $this->assertSame(['FW-Siege', DeviceType::Firewall], [$existing->refresh()->name, $existing->type]);
        $this->assertSame($before, $this->hostnames());

        // One device created, none for the name already there.
        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceCreated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceUpdated));
        $this->assertSame(3, $this->countEntries(ActivityEvent::IpAddressAttached));
    }

    public function test_the_summary_counts_what_was_done(): void
    {
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'fw-siege']);
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur']);

        $this->address($this->offices, '10.10.10.1', 'fw-siege');
        $this->address($this->offices, '10.10.10.2', 'sw-coeur');
        $this->address($this->offices, '10.10.10.3', 'sw-coeur');

        // Only attachments: no device is said to be created.
        $this->apply([['hostname' => 'fw-siege', 'type' => 'firewall']])
            ->assertInertiaFlash('toast.message', '1 rattachement, 1 adresse rattachée.');

        $this->apply([['hostname' => 'sw-coeur', 'type' => 'switch']])
            ->assertInertiaFlash('toast.message', '1 rattachement, 2 adresses rattachées.');

        $this->address($this->offices, '10.10.10.10', 'srv-01');

        $this->apply([['hostname' => 'srv-01', 'type' => 'server']])
            ->assertInertiaFlash('toast.message', '1 équipement créé, 1 adresse rattachée.');
    }

    // --- All or nothing -------------------------------------------------------

    public function test_a_refused_line_leaves_nothing_done_and_is_told_on_its_line(): void
    {
        $this->address($this->offices, '10.10.10.1', 'fw-siege', ['device_type' => 'firewall']);
        $this->address($this->offices, '10.10.10.10', 'srv-01', ['device_type' => 'server']);
        $this->address($this->offices, '10.10.10.50', 'imp-accueil');
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'Imp-Accueïl']);

        $before = $this->hostnames();

        $response = $this->apply([
            ['hostname' => 'fw-siege', 'type' => 'firewall'],
            ['hostname' => 'gone-01', 'type' => 'server'],
            ['hostname' => 'imp-accueil', 'type' => 'printer'],
            ['hostname' => 'srv-01', 'type' => 'server'],
            ['hostname' => 'SRV-01', 'type' => 'nas'],
        ]);

        // Every refusal at once, each on its line.
        $response->assertRedirect()->assertSessionHasErrors(['lines.1.hostname', 'lines.4.hostname']);

        $this->assertSame(['lines.1.hostname', 'lines.4.hostname'], $this->errorFields());
        $this->assertSame("Plus aucune adresse sans équipement de ce site ne porte ce nom d'hôte.", $this->error('lines.1.hostname'));
        $this->assertSame("Ce nom d'hôte apparaît deux fois dans cette saisie.", $this->error('lines.4.hostname'));

        // The lines that were accepted were not kept either.
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(3, IpAddress::query()->whereNull('device_id')->count());
        $this->assertSame($before, $this->hostnames());
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceCreated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressAttached));
    }

    public function test_a_name_done_in_the_meantime_is_refused(): void
    {
        $this->address($this->offices, '10.10.10.1', 'fw-siege');
        $this->address($this->offices, '10.10.10.10', 'srv-01');

        $this->apply([['hostname' => 'fw-siege', 'type' => 'firewall']])->assertSessionHasNoErrors();

        // The page was opened before: it still sends both names.
        $response = $this->apply([['hostname' => 'fw-siege', 'type' => 'firewall'], ['hostname' => 'srv-01', 'type' => 'server']]);

        $this->assertRefused($response, 'lines.0.hostname', "Plus aucune adresse sans équipement de ce site ne porte ce nom d'hôte.");
        $this->assertSame(1, Device::query()->count());
        $this->assertNull(IpAddress::query()->where('hostname', 'srv-01')->sole()->device_id);
    }

    public function test_a_host_name_longer_than_the_name_of_a_device_is_refused_on_its_line(): void
    {
        // A host name holds 253 characters, the name of a device 100.
        $long = str_repeat('a', 50).'.'.str_repeat('b', 50).'.example';

        $this->address($this->offices, '10.10.10.1', $long);
        $this->address($this->offices, '10.10.10.2', 'srv-01');

        $this->actingAs($this->editor)
            ->get(route('sites.hostname-devices.create', $this->office))
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.0.hostname', $long)
                ->where('candidates.0.too_long', true)
                ->where('candidates.1.too_long', false));

        $this->assertRefused(
            $this->apply([['hostname' => $long, 'type' => 'server'], ['hostname' => 'srv-01', 'type' => 'server']]),
            'lines.0.hostname',
            "Ce nom d'hôte dépasse les 100 caractères du nom d'un équipement : créez l'équipement à la main.",
        );

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_the_shape_of_what_is_sent_is_checked(): void
    {
        $this->address($this->offices, '10.10.10.1', 'fw-siege');

        $this->assertRefused($this->apply([]), 'lines', "Cochez au moins un nom d'hôte.");
        $this->assertRefused($this->apply([['hostname' => 'fw-siege', 'type' => 'toaster']]), 'lines.0.type');
        $this->assertRefused($this->apply([['hostname' => 'fw-siege']]), 'lines.0.type');
        $this->assertRefused($this->apply([['type' => 'server']]), 'lines.0.hostname');
        $this->assertRefused($this->apply([['hostname' => 'fw-siege', 'type' => 'firewall', 'status' => 'broken']]), 'lines.0.status');
        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('sites.hostname-devices.store', $this->office), ['lines' => [['hostname' => 'fw-siege', 'type' => 'firewall']]]),
            'lines.0.status',
            'Le champ statut est obligatoire.',
        );

        $tooMany = array_map(
            fn (int $index): array => ['hostname' => 'host-'.$index, 'type' => 'server'],
            range(1, HostnameDevices::MAX_LINES + 1),
        );

        $this->assertRefused($this->apply($tooMany), 'lines', "Cochez 500 noms d'hôte au plus à la fois.");

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_only_the_addresses_of_the_site_are_done(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $there = $this->subnet('10.20.10.0/24', ['site_id' => $workshop->id]);

        // The same name on both sites, and on a subnet of no site.
        $this->address($this->offices, '10.10.10.1', 'ap-01', ['device_type' => 'access_point']);
        $this->address($there, '10.20.10.1', 'ap-01', ['device_type' => 'access_point']);
        $this->address($this->subnet('172.16.0.0/30'), '172.16.0.1', 'ap-01');

        $this->apply([['hostname' => 'ap-01', 'type' => 'access_point']])->assertSessionHasNoErrors();

        $device = Device::query()->sole();

        $this->assertSame($this->office->id, $device->site_id);
        $this->assertSame(['10.10.10.1'], $device->ipAddresses()->get()->map(fn (IpAddress $address): string => $address->address)->all());
        $this->assertSame(2, IpAddress::query()->whereNull('device_id')->count());

        // The other site has its own device of that name.
        $this->apply([['hostname' => 'ap-01', 'type' => 'access_point']], $workshop)->assertSessionHasNoErrors();

        $this->assertSame(2, Device::query()->where('name', 'ap-01')->count());
        $this->assertSame(1, IpAddress::query()->whereNull('device_id')->count());
    }

    // --- Rights ---------------------------------------------------------------

    public function test_only_editors_and_administrators_reach_the_page_and_the_action(): void
    {
        $this->address($this->offices, '10.10.10.1', 'fw-siege');

        $lines = ['lines' => [['hostname' => 'fw-siege', 'type' => 'firewall', 'status' => 'active']]];

        $this->get(route('sites.hostname-devices.create', $this->office))->assertRedirect(route('login'));
        $this->post(route('sites.hostname-devices.store', $this->office), $lines)->assertRedirect(route('login'));

        $reader = $this->reader();

        $this->actingAs($reader)->get(route('sites.hostname-devices.create', $this->office))->assertForbidden();
        $this->actingAs($reader)->post(route('sites.hostname-devices.store', $this->office), $lines)->assertForbidden();

        $this->assertDatabaseCount('devices', 0);

        $this->actingAs($this->admin())->get(route('sites.hostname-devices.create', $this->office))->assertOk();
        $this->actingAs($this->admin())->post(route('sites.hostname-devices.store', $this->office), $lines)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('devices', 1);

        $this->actingAs($this->editor)->get(route('sites.hostname-devices.create', 999999))->assertNotFound();
    }
}
