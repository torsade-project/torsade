<?php

// torsade/tests/Feature/Network/GlobalSearchTest.php

namespace Tests\Feature\Network;

use App\Enums\Status;
use App\Models\Activity;
use App\Models\AddressBlock;
use App\Models\Building;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\Port;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Network\GlobalSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The search of the whole documentation, as the search window asks for
 * it: what was typed is read as an address, a prefix, the beginning of an
 * address, a VLAN number, a MAC address or a device followed by a port,
 * and otherwise looked for as text, without regard to case or accents.
 */
class GlobalSearchTest extends NetworkTestCase
{
    /**
     * @param  array<string, int|string>  $parameters
     * @return TestResponse<Response>
     */
    private function search(string $typed, ?User $user = null, array $parameters = []): TestResponse
    {
        return $this->actingAs($user ?? $this->reader())
            ->getJson(route('search.results', ['q' => $typed, ...$parameters]));
    }

    /**
     * The keys of the groups, in their order.
     *
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function groupKeys(TestResponse $response): array
    {
        return array_column($response->assertOk()->json('groups'), 'key');
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>|null
     */
    private function group(TestResponse $response, string $key): ?array
    {
        return collect($response->assertOk()->json('groups'))->firstWhere('key', $key);
    }

    /**
     * The label of each result of a group, as it reads.
     *
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function labels(TestResponse $response, string $key): array
    {
        return array_map(
            fn (array $item): string => implode('', array_column($item['label'], 'text')),
            $this->group($response, $key)['items'] ?? [],
        );
    }

    /**
     * The parts of the label of the first result of a group that match.
     *
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function matchedInLabel(TestResponse $response, string $key, int $position = 0): array
    {
        $label = $this->group($response, $key)['items'][$position]['label'] ?? [];

        return array_values(array_column(array_filter($label, fn (array $segment): bool => $segment['match']), 'text'));
    }

    // ----- Addresses --------------------------------------------------------

    public function test_a_documented_address_comes_with_its_subnet_and_the_blocks_that_hold_it(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        AddressBlock::factory()->cidr('10.0.0.0/8')->create();
        AddressBlock::factory()->cidr('10.1.0.0/16')->forSite($site)->create();
        AddressBlock::factory()->cidr('10.2.0.0/16')->create();
        $subnet = $this->subnet('10.1.20.0/24', ['site_id' => $site->id, 'name' => 'Bureaux']);
        $this->subnet('10.1.21.0/24');
        $address = IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.1.20.37', 'hostname' => 'pc-accueil']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.1.20.38']);
        // An address is not a text: names that hold it are not looked into.
        Device::factory()->create(['site_id' => $site->id, 'name' => 'srv-10.1.20.37']);

        $response = $this->search('10.1.20.37');

        // The address itself first; then the blocks and the subnet that hold
        // it, in the order of the groups.
        $this->assertSame(['addresses', 'blocks', 'subnets'], $this->groupKeys($response));
        $this->assertSame(['10.1.20.37'], $this->labels($response, 'addresses'));
        $this->assertSame(['10.1.20.37'], $this->matchedInLabel($response, 'addresses'));
        $this->assertSame(['10.1.20.0/24'], $this->labels($response, 'subnets'));
        $this->assertSame(['10.0.0.0/8', '10.1.0.0/16'], $this->labels($response, 'blocks'));
        // The subnet and the blocks only hold the address: nothing of them
        // is marked as matching.
        $this->assertSame([], $this->matchedInLabel($response, 'subnets'));
        $this->assertNull($response->json('undocumented'));

        // An address has no page of its own: the page of its subnet, at its line.
        $this->assertSame(route('subnets.show', $subnet).'#address-'.$address->id, $this->group($response, 'addresses')['items'][0]['url']);
        $this->assertTrue($this->group($response, 'addresses')['exact']);
    }

    public function test_an_address_of_a_subnet_that_is_not_documented_says_so(): void
    {
        $subnet = $this->subnet('10.1.20.0/24', ['name' => 'Bureaux']);
        AddressBlock::factory()->cidr('10.0.0.0/8')->create();

        $forEditor = $this->search('10.1.20.50', $this->editor())->assertOk();

        $this->assertSame('10.1.20.50', $forEditor->json('undocumented.address'));
        $this->assertSame('10.1.20.0/24', $forEditor->json('undocumented.subnet.cidr'));
        $this->assertSame(route('subnets.show', $subnet), $forEditor->json('undocumented.subnet.url'));
        // The window of a new address of that subnet, filled in with it.
        $this->assertSame(route('subnets.show', ['subnet' => $subnet, 'address' => '10.1.20.50']), $forEditor->json('undocumented.document_url'));
        // The subnet and the block that hold it are listed as for any address.
        $this->assertSame(['blocks', 'subnets'], $this->groupKeys($forEditor));

        $this->assertSame(
            route('subnets.show', ['subnet' => $subnet, 'address' => '10.1.20.50']),
            $this->search('10.1.20.50', $this->admin())->json('undocumented.document_url'),
        );

        // A reader is told, and offered nothing.
        $forReader = $this->search('10.1.20.50')->assertOk();
        $this->assertSame('10.1.20.0/24', $forReader->json('undocumented.subnet.cidr'));
        $this->assertNull($forReader->json('undocumented.document_url'));

        // No machine of the subnet holds its network or broadcast address.
        $this->assertNull($this->search('10.1.20.0', $this->editor())->json('undocumented.document_url'));
        $this->assertNull($this->search('10.1.20.255', $this->editor())->json('undocumented.document_url'));
        $this->assertSame('10.1.20.0/24', $this->search('10.1.20.255', $this->editor())->json('undocumented.subnet.cidr'));
    }

    public function test_an_address_outside_of_every_subnet_is_told_without_an_action(): void
    {
        $this->subnet('10.1.20.0/24');
        AddressBlock::factory()->cidr('10.0.0.0/8')->create();

        $response = $this->search('192.0.2.10', $this->admin());

        $this->assertSame('192.0.2.10', $response->json('undocumented.address'));
        $this->assertNull($response->json('undocumented.subnet'));
        $this->assertNull($response->json('undocumented.document_url'));
        $this->assertSame([], $this->groupKeys($response));
        $this->assertSame(0, $response->json('total'));

        // Inside a block, outside of any subnet: the block is found.
        $inBlock = $this->search('10.9.9.9', $this->admin());
        $this->assertSame(['blocks'], $this->groupKeys($inBlock));
        $this->assertNull($inBlock->json('undocumented.subnet'));
    }

    public function test_the_blocks_that_hold_an_address_are_all_found_from_the_widest(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        AddressBlock::factory()->cidr('10.0.0.0/8')->create(['name' => 'Organisation entière']);
        AddressBlock::factory()->cidr('10.0.0.0/12')->create(['name' => 'Sites']);
        AddressBlock::factory()->cidr('10.1.0.0/16')->forSite($site)->create();
        AddressBlock::factory()->cidr('10.16.0.0/12')->create();
        AddressBlock::factory()->cidr('172.16.0.0/12')->create();

        $response = $this->search('10.1.200.7');

        $this->assertSame(['10.0.0.0/8', '10.0.0.0/12', '10.1.0.0/16'], $this->labels($response, 'blocks'));
        $this->assertSame(3, $this->group($response, 'blocks')['total']);
        // A block of a site says its site; the others belong to the organisation.
        $this->assertSame('Organisation', $this->group($response, 'blocks')['items'][0]['context'][0][0]['text']);
        $this->assertSame('Siège', $this->group($response, 'blocks')['items'][2]['context'][0][0]['text']);
    }

    // ----- Prefixes ----------------------------------------------------------

    public function test_a_prefix_equal_to_a_subnet_or_to_a_block_finds_it_alone(): void
    {
        AddressBlock::factory()->cidr('10.0.0.0/8')->create();
        AddressBlock::factory()->cidr('10.1.0.0/16')->create();
        $this->subnet('10.1.20.0/24');
        $this->subnet('10.1.21.0/24');

        $subnet = $this->search('10.1.20.0/24');
        $this->assertSame(['subnets'], $this->groupKeys($subnet));
        $this->assertSame(['10.1.20.0/24'], $this->labels($subnet, 'subnets'));
        $this->assertSame(['10.1.20.0/24'], $this->matchedInLabel($subnet, 'subnets'));
        $this->assertTrue($this->group($subnet, 'subnets')['exact']);
        $this->assertNull($subnet->json('notice'));

        $block = $this->search('10.1.0.0/16');
        $this->assertSame(['blocks'], $this->groupKeys($block));
        $this->assertSame(['10.1.0.0/16'], $this->labels($block, 'blocks'));
        $this->assertSame(['10.1.0.0/16'], $this->matchedInLabel($block, 'blocks'));

        // A small site with one network: the block and the subnet are equal.
        AddressBlock::factory()->cidr('10.1.21.0/24')->create();
        $both = $this->search('10.1.21.0/24');
        $this->assertSame(['blocks', 'subnets'], $this->groupKeys($both));
    }

    public function test_a_prefix_that_nothing_equals_finds_what_holds_it_and_what_it_holds(): void
    {
        AddressBlock::factory()->cidr('10.0.0.0/8')->create();
        AddressBlock::factory()->cidr('10.1.0.0/16')->create();
        AddressBlock::factory()->cidr('10.1.64.0/18')->create();
        $this->subnet('10.1.20.0/24');
        $this->subnet('10.1.21.0/24');
        $this->subnet('10.1.40.0/24');
        $this->subnet('10.2.0.0/24');

        $holding = $this->search('10.1.0.0/19');

        // Held by two blocks, holding two subnets; the block of 10.1.64.0
        // and the subnets beyond 10.1.31.255 are not around it.
        $this->assertSame(['10.0.0.0/8', '10.1.0.0/16'], $this->labels($holding, 'blocks'));
        $this->assertSame(['10.1.20.0/24', '10.1.21.0/24'], $this->labels($holding, 'subnets'));
        $this->assertFalse($this->group($holding, 'subnets')['exact']);
        $this->assertSame([], $this->matchedInLabel($holding, 'subnets'));

        // A prefix inside a subnet: the subnet holds it.
        $held = $this->search('10.1.20.128/25');
        $this->assertSame(['10.1.20.0/24'], $this->labels($held, 'subnets'));
        $this->assertSame(['10.0.0.0/8', '10.1.0.0/16'], $this->labels($held, 'blocks'));
    }

    public function test_a_prefix_typed_with_host_bits_is_looked_for_at_its_network_address(): void
    {
        $this->subnet('10.1.20.0/24');

        $response = $this->search('10.1.20.37/24');

        $this->assertSame('Recherché à son adresse réseau : 10.1.20.0/24', $response->json('notice'));
        $this->assertSame(['10.1.20.0/24'], $this->labels($response, 'subnets'));
        $this->assertSame(['10.1.20.0/24'], $this->matchedInLabel($response, 'subnets'));
    }

    public function test_around_a_prefix_fifty_blocks_and_subnets_at_most_are_found_the_blocks_first(): void
    {
        for ($number = 0; $number < 60; $number++) {
            $this->subnet('10.5.'.$number.'.0/24');
        }

        $alone = $this->search('10.5.0.0/16', parameters: ['group' => 'subnets']);
        $this->assertSame(GlobalSearch::AROUND_PREFIX, $alone->json('group.total'));
        $this->assertCount(GlobalSearch::PAGE_LIMIT, $alone->json('group.items'));
        $this->assertCount(GlobalSearch::AROUND_PREFIX - GlobalSearch::PAGE_LIMIT, $this->search('10.5.0.0/16', parameters: ['group' => 'subnets', 'page' => 2])->json('group.items'));
        $this->assertSame([], $this->search('10.5.0.0/16', parameters: ['group' => 'subnets', 'page' => 3])->json('group.items'));

        AddressBlock::factory()->cidr('10.0.0.0/8')->create();
        AddressBlock::factory()->cidr('10.5.0.0/20')->create();

        $withBlocks = $this->search('10.5.0.0/16');
        $this->assertSame(2, $this->group($withBlocks, 'blocks')['total']);
        $this->assertSame(GlobalSearch::AROUND_PREFIX - 2, $this->group($withBlocks, 'subnets')['total']);
    }

    // ----- The beginning of an address --------------------------------------

    public function test_the_beginning_of_an_address_finds_the_addresses_and_subnets_that_start_so(): void
    {
        $offices = $this->subnet('10.1.20.0/24');
        $voice = $this->subnet('10.1.30.0/24');
        $other = $this->subnet('10.2.0.0/24');
        $lab = $this->subnet('192.168.10.0/24');
        IpAddress::factory()->create(['subnet_id' => $offices->id, 'address' => '10.1.20.37']);
        IpAddress::factory()->create(['subnet_id' => $voice->id, 'address' => '10.1.30.5']);
        IpAddress::factory()->create(['subnet_id' => $other->id, 'address' => '10.2.0.9']);
        IpAddress::factory()->create(['subnet_id' => $lab->id, 'address' => '192.168.10.10', 'hostname' => 'labo-10.1.20.']);

        // One number.
        $one = $this->search('10.');
        $this->assertSame(['10.1.20.37', '10.1.30.5', '10.2.0.9'], $this->labels($one, 'addresses'));
        $this->assertSame(['10.1.20.0/24', '10.1.30.0/24', '10.2.0.0/24'], $this->labels($one, 'subnets'));

        // Two numbers.
        $two = $this->search('10.1.');
        $this->assertSame(['10.1.20.37', '10.1.30.5'], $this->labels($two, 'addresses'));
        $this->assertSame(['10.1.20.0/24', '10.1.30.0/24'], $this->labels($two, 'subnets'));

        // Three numbers: a host name that holds them is not looked into.
        $three = $this->search('10.1.20.');
        $this->assertSame(['subnets', 'addresses'], $this->groupKeys($three));
        $this->assertSame(['10.1.20.37'], $this->labels($three, 'addresses'));
        $this->assertSame(['10.1.20.0/24'], $this->labels($three, 'subnets'));
        $this->assertFalse($this->group($three, 'addresses')['exact']);
        $this->assertSame([], $this->matchedInLabel($three, 'addresses'));
    }

    // ----- VLAN numbers ------------------------------------------------------

    public function test_a_vlan_number_finds_the_vlans_of_every_site_and_the_entry_of_the_catalogue(): void
    {
        $head = Site::factory()->create(['name' => 'Siège']);
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'BUREAUTIQUE']);
        Vlan::factory()->create(['site_id' => $head->id, 'vid' => 10, 'name' => 'Bureautique', 'catalog_vlan_id' => $entry->id]);
        Vlan::factory()->create(['site_id' => $workshop->id, 'vid' => 10, 'name' => 'Bureaux']);
        Vlan::factory()->create(['site_id' => $head->id, 'vid' => 30, 'name' => 'Voix 10']);
        Vlan::factory()->create(['site_id' => $head->id, 'vid' => 40, 'name' => 'Invités']);
        // The number is also looked for as text.
        Device::factory()->create(['site_id' => $head->id, 'name' => 'sw-10']);

        $response = $this->search('10');

        $this->assertSame(['vlans', 'catalog', 'devices'], $this->groupKeys($response));
        // The VLANs of that number first, then those whose name holds it.
        $this->assertSame(['10 — Bureautique', '10 — Bureaux', '30 — Voix 10'], $this->labels($response, 'vlans'));
        $this->assertSame(['10'], $this->matchedInLabel($response, 'vlans'));
        $this->assertSame(['10'], $this->matchedInLabel($response, 'vlans', 2));
        $this->assertSame('Atelier', $this->group($response, 'vlans')['items'][1]['context'][0][0]['text']);
        $this->assertSame(['10 — BUREAUTIQUE'], $this->labels($response, 'catalog'));
        $this->assertSame(['sw-10'], $this->labels($response, 'devices'));
    }

    public function test_a_number_outside_of_1_to_4094_is_looked_for_as_text(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        Vlan::factory()->create(['site_id' => $site->id, 'vid' => 409, 'name' => 'Lot 4095']);
        Device::factory()->create(['site_id' => $site->id, 'name' => 'baie-4095']);

        $response = $this->search('4095');

        $this->assertSame(['vlans', 'devices'], $this->groupKeys($response));
        $this->assertSame(['409 — Lot 4095'], $this->labels($response, 'vlans'));
        // Found by its name, not by its number.
        $this->assertSame(['4095'], $this->matchedInLabel($response, 'vlans'));

        // Nor is a zero: the name holds one, the number is not looked into.
        $this->assertSame(['vlans', 'devices'], $this->groupKeys($this->search('0')));
    }

    // ----- MAC addresses -----------------------------------------------------

    public function test_a_mac_address_is_found_in_each_of_the_four_notations(): void
    {
        $subnet = $this->subnet('10.1.20.0/24');
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.1.20.37', 'mac_address' => 'aa:bb:cc:dd:ee:ff']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.1.20.38', 'mac_address' => 'aa:bb:cc:dd:ee:00']);
        Device::factory()->create(['name' => 'console', 'description' => 'aa:bb:cc:dd:ee:ff']);

        foreach (['aa:bb:cc:dd:ee:ff', 'AA-BB-CC-DD-EE-FF', 'aabb.ccdd.eeff', 'AABBCCDDEEFF'] as $typed) {
            $response = $this->search($typed);

            // A MAC address is not a text either.
            $this->assertSame(['addresses'], $this->groupKeys($response), $typed);
            $this->assertSame(['10.1.20.37'], $this->labels($response, 'addresses'), $typed);

            // The MAC address is shown, and matches as a whole.
            $mac = $this->group($response, 'addresses')['items'][0]['context'][0][0];
            $this->assertSame(['text' => 'aa:bb:cc:dd:ee:ff', 'match' => true, 'mono' => true], $mac, $typed);
        }
    }

    public function test_the_beginning_of_a_mac_address_finds_the_addresses_whose_mac_starts_so(): void
    {
        $subnet = $this->subnet('10.1.20.0/24');
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.1.20.37', 'mac_address' => 'aa:bb:cc:dd:ee:ff']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.1.20.38', 'mac_address' => 'aa:bb:cc:00:11:22']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.1.20.39', 'mac_address' => '00:aa:bb:cc:11:22']);
        // Looked for as text as well.
        Device::factory()->create(['name' => 'console', 'description' => 'Adaptateur aa:bb:cc']);

        foreach (['aa:bb:cc', 'AA-BB-CC', 'aa:bb:cc:', 'aa:bb:cc:dd'] as $typed) {
            $response = $this->search($typed);
            $expected = $typed === 'aa:bb:cc:dd' ? ['10.1.20.37'] : ['10.1.20.37', '10.1.20.38'];

            $this->assertSame($expected, $this->labels($response, 'addresses'), $typed);
            $this->assertFalse($this->group($response, 'addresses')['exact'], $typed);
        }

        $this->assertSame(['addresses', 'devices'], $this->groupKeys($this->search('aa:bb:cc')));
    }

    // ----- A device and one of its ports -------------------------------------

    public function test_a_device_followed_by_a_port_finds_that_port_on_each_device_of_that_name(): void
    {
        $head = Site::factory()->create(['name' => 'Siège']);
        $workshop = Site::factory()->create(['name' => 'Atelier']);

        $portOf = function (Site $site, string $device, string $port, ?string $description = null): Port {
            $member = DeviceMember::factory()->create(['device_id' => Device::factory()->create(['site_id' => $site->id, 'name' => $device])->id]);

            return Port::factory()->create(['device_member_id' => $member->id, 'name' => $port, 'description' => $description]);
        };

        $head24 = $portOf($head, 'sw-etage-1', '1/0/24');
        $workshop24 = $portOf($workshop, 'sw-etage-1', '1/0/24');
        $portOf($head, 'sw-etage-2', '1/0/24', 'Vers sw-etage-1 1/0/24');
        $rack = $portOf($head, 'Baie A switch', 'Gi1/0/1');

        $response = $this->search('sw-etage-1 1/0/24');

        $this->assertSame(['ports'], $this->groupKeys($response));
        // On each site, then the port whose description holds the text.
        $this->assertSame(['sw-etage-1 1/0/24', 'sw-etage-1 1/0/24', 'sw-etage-2 1/0/24'], $this->labels($response, 'ports'));
        $this->assertSame(['sw-etage-1', '1/0/24'], $this->matchedInLabel($response, 'ports'));
        $this->assertSame([], $this->matchedInLabel($response, 'ports', 2));
        $this->assertSame(
            [route('devices.show', $head24->device_id).'#port-'.$head24->id, route('devices.show', $workshop24->device_id).'#port-'.$workshop24->id],
            array_slice(array_column($this->group($response, 'ports')['items'], 'url'), 0, 2),
        );
        $this->assertSame('Description', $this->group($response, 'ports')['items'][2]['excerpt']['field']);
        $this->assertTrue($this->group($response, 'ports')['exact']);

        // A device whose name holds spaces, and whatever the case.
        $spaced = $this->search('baie a SWITCH gi1/0/1');
        $this->assertSame(['Baie A switch Gi1/0/1'], $this->labels($spaced, 'ports'));
        $this->assertSame(route('devices.show', $rack->device_id).'#port-'.$rack->id, $this->group($spaced, 'ports')['items'][0]['url']);
    }

    public function test_an_unknown_device_followed_by_a_word_is_only_looked_for_as_text(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        $member = DeviceMember::factory()->create(['device_id' => Device::factory()->create(['site_id' => $site->id, 'name' => 'sw-etage-1'])->id]);
        Port::factory()->create(['device_member_id' => $member->id, 'name' => '1/0/24']);
        Jack::factory()->create(['label' => 'B.15', 'location' => 'Bureau sw-inconnu 1/0/24']);

        $response = $this->search('sw-inconnu 1/0/24');

        $this->assertSame(['jacks'], $this->groupKeys($response));
        $this->assertSame(['sw-inconnu 1/0/24'], array_column(array_filter(
            $this->group($response, 'jacks')['items'][0]['context'][2],
            fn (array $segment): bool => $segment['match'],
        ), 'text'));
    }

    // ----- Text --------------------------------------------------------------

    public function test_text_is_found_without_regard_to_case_or_accents_and_marked_as_written(): void
    {
        $site = Site::factory()->create(['name' => 'Siège social', 'code' => 'SIE']);
        $building = Building::factory()->create(['site_id' => $site->id, 'name' => 'Bâtiment principal']);
        Floor::factory()->create(['building_id' => $building->id, 'name' => 'Étage 1', 'level' => 1]);
        Floor::factory()->create(['building_id' => $building->id, 'name' => 'Rez-de-chaussée', 'level' => 0]);

        $floor = $this->search('ETAGE');
        $this->assertSame(['places'], $this->groupKeys($floor));
        $this->assertSame(['Étage 1'], $this->labels($floor, 'places'));
        // The part that matches, as it is written.
        $this->assertSame([['text' => 'Étage', 'match' => true, 'mono' => false], ['text' => ' 1', 'match' => false, 'mono' => false]], $this->group($floor, 'places')['items'][0]['label']);
        $this->assertSame('Siège social', $this->group($floor, 'places')['items'][0]['context'][0][0]['text']);

        $siege = $this->search('siege');
        $this->assertSame(['Siège social'], $this->labels($siege, 'sites'));
        $this->assertSame(['Siège'], $this->matchedInLabel($siege, 'sites'));

        $this->assertSame(['Rez-de-chaussée'], $this->labels($this->search('CHAUSSEE'), 'places'));
        $this->assertSame(['Bâtiment principal'], $this->labels($this->search('batiment'), 'places'));
    }

    public function test_a_letter_the_collation_reads_as_two_is_found_as_it_is_written(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        Room::factory()->inSite($site)->create(['name' => 'Kleine Straße']);
        Room::factory()->inSite($site)->create(['name' => 'Cœur de réseau']);

        // The database looks for a text one character at a time.
        $this->assertNotContains('places', $this->groupKeys($this->search('strasse')));
        $this->assertNotContains('places', $this->groupKeys($this->search('coeur')));

        $found = $this->search('STRAßE');
        $this->assertSame(['Kleine Straße'], $this->labels($found, 'places'));
        $this->assertSame(['Straße'], $this->matchedInLabel($found, 'places'));
        $this->assertSame(['Cœur'], $this->matchedInLabel($this->search('cŒur'), 'places'));
    }

    public function test_a_combining_accent_is_looked_for_as_a_character_of_its_own(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        Room::factory()->inSite($site)->create(['name' => "Salle Ope\u{0301}ra"]);
        Room::factory()->inSite($site)->create(['name' => 'Salle Opéra']);

        // The accent written apart is a character the database looks for.
        $this->assertSame(['Salle Opéra'], $this->labels($this->search('opera'), 'places'));
        $this->assertSame(["Salle Ope\u{0301}ra"], $this->labels($this->search("ope\u{0301}ra"), 'places'));

        // A match that ends before it takes it with its letter.
        $start = $this->search('salle ope', parameters: ['group' => 'places']);
        $this->assertSame(
            ["Salle Ope\u{0301}", 'Salle Opé'],
            array_map(fn (array $item): string => implode('', array_column(array_filter($item['label'], fn (array $segment): bool => $segment['match']), 'text')), $start->json('group.items')),
        );
    }

    public function test_the_wildcards_of_the_database_are_looked_for_as_they_are(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);

        foreach (['promo-10%', 'promo-100', 'sw_a', 'swxa', 'c\\d', 'cd', 'c\\\\d'] as $name) {
            Device::factory()->create(['site_id' => $site->id, 'name' => $name]);
        }

        $this->assertSame(['promo-10%'], $this->labels($this->search('10%'), 'devices'));
        $this->assertSame(['sw_a'], $this->labels($this->search('w_a'), 'devices'));
        // Both start so; a backslash comes before a letter.
        $this->assertSame(['c\\\\d', 'c\\d'], $this->labels($this->search('c\\'), 'devices'));
        $this->assertSame(['c\\d'], $this->labels($this->search('c\\d'), 'devices'));
        $this->assertSame([], $this->groupKeys($this->search('%%')));
    }

    public function test_each_kind_of_object_is_found_by_the_fields_of_the_model(): void
    {
        $site = Site::factory()->create(['name' => 'Siège', 'code' => 'SIE']);
        $room = Room::factory()->inSite($site)->technical()->create(['name' => 'Local Krypton']);
        Building::factory()->create(['site_id' => $site->id, 'name' => 'Annexe', 'code' => 'KRYPT']);
        Vlan::factory()->create(['site_id' => $site->id, 'vid' => 50, 'name' => 'Labo', 'description' => 'Réseau krypton']);
        CatalogVlan::factory()->create(['vid' => 60, 'name' => 'KRYPTON']);
        AddressBlock::factory()->cidr('10.9.0.0/16')->create(['name' => 'Krypton']);
        $subnet = $this->subnet('10.9.1.0/24', ['description' => 'Salle krypton']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.9.1.10', 'hostname' => 'krypton-01']);
        $device = Device::factory()->create(['site_id' => $site->id, 'name' => 'sw-labo', 'room_id' => $room->id]);
        $member = DeviceMember::factory()->create(['device_id' => $device->id, 'serial_number' => 'KRYPT-0042']);
        Port::factory()->create(['device_member_id' => $member->id, 'name' => '1/0/1', 'description' => 'Imprimante krypton']);
        Jack::factory()->create(['floor_id' => $room->floor_id, 'label' => 'K.01', 'location' => 'Salle krypton']);
        PatchPanel::factory()->create(['room_id' => $room->id, 'name' => 'PB-K', 'description' => 'Panneau krypton']);
        DeviceTemplate::factory()->create(['name' => 'Modèle labo', 'manufacturer' => 'Krypton Networks']);

        $response = $this->search('krypt');

        $this->assertSame(
            ['places', 'vlans', 'catalog', 'blocks', 'subnets', 'addresses', 'devices', 'ports', 'jacks', 'panels', 'templates'],
            $this->groupKeys($response),
        );
        $this->assertSame(['Annexe', 'Local Krypton'], $this->labels($response, 'places'));
        // A value that is not shown is quoted around the match, with its field.
        $this->assertSame('Description', $this->group($response, 'vlans')['items'][0]['excerpt']['field']);
        $this->assertSame('Numéro de série', $this->group($response, 'devices')['items'][0]['excerpt']['field']);
        $this->assertSame(['sw-labo'], $this->labels($response, 'devices'));
        $this->assertSame(['sw-labo 1/0/1'], $this->labels($response, 'ports'));
        $this->assertSame('Nom', $this->group($response, 'blocks')['items'][0]['excerpt']['field']);
        $this->assertSame(['Modèle labo'], $this->labels($response, 'templates'));

        // The code of a site is looked into.
        $this->assertSame(['Siège'], $this->labels($this->search('sie'), 'sites'));
    }

    public function test_in_a_group_an_exact_match_comes_first_then_a_start_then_a_match_anywhere_the_retired_last(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);

        foreach ([['Ancien pare-feu', Status::Active], ['Pare-feu-02', Status::Active], ['Pare-feu-01', Status::Retired], ['Pare-feu', Status::Active], ['Pare-feu-10', Status::Planned], ['Pare-feu-3', Status::Active]] as [$name, $status]) {
            Device::factory()->create(['site_id' => $site->id, 'name' => $name, 'status' => $status]);
        }

        $response = $this->search('pare-feu', parameters: ['group' => 'devices']);
        $items = $response->assertOk()->json('group.items');

        // A number is read as a number: 3 before 10.
        $this->assertSame(
            ['Pare-feu', 'Pare-feu-02', 'Pare-feu-3', 'Pare-feu-10', 'Pare-feu-01', 'Ancien pare-feu'],
            array_map(fn (array $item): string => implode('', array_column($item['label'], 'text')), $items),
        );
        // Only a planned or retired result says its status.
        $this->assertSame([null, null, null, 'planned', 'retired', null], array_column($items, 'status'));
        $this->assertSame('Retiré', $items[4]['status_label']);
        $this->assertSame('Prévu', $items[3]['status_label']);
    }

    public function test_a_status_agrees_with_the_object(): void
    {
        $subnet = $this->subnet('10.1.20.0/24');
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.1.20.37', 'hostname' => 'imprimante', 'status' => Status::Retired]);
        Jack::factory()->create(['label' => 'imprimante-1', 'status' => Status::Planned]);

        $response = $this->search('imprimante');

        $this->assertSame('Retirée', $this->group($response, 'addresses')['items'][0]['status_label']);
        $this->assertSame('Prévue', $this->group($response, 'jacks')['items'][0]['status_label']);
    }

    public function test_a_group_with_an_exact_match_comes_before_the_others(): void
    {
        $site = Site::factory()->create(['name' => 'Bureaux du siège']);
        Room::factory()->inSite($site)->create(['name' => 'Bureau']);
        Device::factory()->create(['site_id' => $site->id, 'name' => 'imprimante-bureau']);

        // The room is called exactly so: its group comes first.
        $this->assertSame(['places', 'sites', 'devices'], $this->groupKeys($this->search('bureau')));

        // Without an exact match, the groups keep their order.
        $this->assertSame(['sites', 'places', 'devices'], $this->groupKeys($this->search('burea')));
    }

    public function test_the_window_shows_five_results_per_group_with_its_total_and_the_page_twenty_five(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);

        for ($number = 1; $number <= 30; $number++) {
            Device::factory()->create(['site_id' => $site->id, 'name' => sprintf('poste-%02d', $number)]);
        }

        $window = $this->search('poste');
        $this->assertSame(30, $this->group($window, 'devices')['total']);
        $this->assertSame(['poste-01', 'poste-02', 'poste-03', 'poste-04', 'poste-05'], $this->labels($window, 'devices'));
        $this->assertSame(30, $window->json('total'));

        $first = $this->search('poste', parameters: ['group' => 'devices']);
        $this->assertCount(GlobalSearch::PAGE_LIMIT, $first->json('group.items'));
        $this->assertSame(30, $first->json('group.total'));

        $second = $this->search('poste', parameters: ['group' => 'devices', 'page' => 2]);
        $this->assertSame(
            ['poste-26', 'poste-27', 'poste-28', 'poste-29', 'poste-30'],
            array_map(fn (array $item): string => implode('', array_column($item['label'], 'text')), $second->json('group.items')),
        );
    }

    public function test_the_next_results_of_the_places_are_merged_across_buildings_floors_and_rooms(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        $building = Building::factory()->create(['site_id' => $site->id, 'name' => 'Zone 00']);

        // Floors and rooms alternate in the order of their names.
        for ($number = 1; $number <= 20; $number++) {
            Floor::factory()->create(['building_id' => $building->id, 'name' => sprintf('Zone %02d', 2 * $number - 1), 'level' => $number]);
        }

        $floor = Floor::query()->firstOrFail();

        for ($number = 1; $number <= 20; $number++) {
            Room::factory()->create(['floor_id' => $floor->id, 'name' => sprintf('Zone %02d', 2 * $number)]);
        }

        $first = $this->search('zone', parameters: ['group' => 'places']);
        $second = $this->search('zone', parameters: ['group' => 'places', 'page' => 2]);
        $read = fn (TestResponse $response): array => array_map(fn (array $item): string => implode('', array_column($item['label'], 'text')), $response->json('group.items'));
        $names = array_map(fn (int $number): string => sprintf('Zone %02d', $number), range(0, 40));

        $this->assertSame(41, $first->json('group.total'));
        $this->assertSame(array_slice($names, 0, GlobalSearch::PAGE_LIMIT), $read($first));
        $this->assertSame(array_slice($names, GlobalSearch::PAGE_LIMIT), $read($second));
    }

    public function test_the_places_are_ordered_by_rank_then_by_name_whatever_their_kind(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        $building = Building::factory()->create(['site_id' => $site->id, 'name' => 'Bureaux']);
        $floor = Floor::factory()->create(['building_id' => $building->id, 'name' => 'Bureau', 'level' => 1]);
        Floor::factory()->create(['building_id' => $building->id, 'name' => 'Grand bureau', 'level' => 2]);
        Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Bureau 1']);
        Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Bureau']);

        $items = $this->group($this->search('bureau'), 'places')['items'];

        // The two exact matches, the floor before the room; then the room
        // whose name comes before the building's; the match anywhere last.
        $this->assertSame(
            [['floor', 'Bureau'], ['room', 'Bureau'], ['room', 'Bureau 1'], ['building', 'Bureaux'], ['floor', 'Grand bureau']],
            array_map(fn (array $item): array => [$item['type'], implode('', array_column($item['label'], 'text'))], $items),
        );
    }

    public function test_the_names_of_places_of_different_kinds_follow_the_natural_order_of_the_database(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        $building = Building::factory()->create(['site_id' => $site->id, 'name' => 'Salle 1']);
        $floor = Floor::factory()->create(['building_id' => $building->id, 'name' => 'Salle_2', 'level' => 1]);
        Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Salle 05']);
        Room::factory()->create(['floor_id' => $floor->id, 'name' => 'SalleA']);

        // Every name of a place, sorted by the database alone.
        $sorted = DB::query()
            ->fromSub(DB::table('buildings')->select('name')->unionAll(DB::table('floors')->select('name'))->unionAll(DB::table('rooms')->select('name')), 'places')
            ->orderByRaw('NATURAL_SORT_KEY(name)')
            ->pluck('name')
            ->all();

        $found = $this->labels($this->search('salle'), 'places');

        $this->assertSame($sorted, $found);
        // A number is read by its value, its zeros left aside.
        $this->assertLessThan(array_search('Salle 05', $found, true), array_search('Salle 1', $found, true));
    }

    // ----- What is typed -----------------------------------------------------

    public function test_a_search_holds_1_to_100_characters_once_its_spaces_are_trimmed(): void
    {
        $this->search('')->assertUnprocessable()->assertJsonValidationErrors(['q' => 'Tapez ce que vous cherchez.']);
        $this->search('    ')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->search(str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q' => 'Une recherche contient 100 caractères au plus.']);

        $this->search(str_repeat('a', 100))->assertOk();
        $this->search('  '.str_repeat('a', 100).'  ')->assertOk()->assertJsonPath('query', str_repeat('a', 100));
        $this->search('a')->assertOk();

        $this->search('poste', parameters: ['group' => 'accounts'])->assertUnprocessable()->assertJsonValidationErrors('group');
        $this->search('poste', parameters: ['group' => 'devices', 'page' => 0])->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_the_three_roles_may_search_a_guest_may_not(): void
    {
        Device::factory()->create(['name' => 'sw-coeur']);

        $this->getJson(route('search.results', ['q' => 'coeur']))->assertUnauthorized();

        foreach ([$this->reader(), $this->editor(), $this->admin()] as $user) {
            $this->assertSame(['sw-coeur'], $this->labels($this->search('coeur', $user), 'devices'));
        }

        $inactive = User::factory()->reader()->inactive()->create();
        $this->actingAs($inactive)->get(route('search.results', ['q' => 'coeur']))->assertRedirect(route('login'));
    }

    public function test_accounts_and_the_activity_log_are_never_searched(): void
    {
        User::factory()->admin()->create(['name' => 'Camille Martin', 'email' => 'camille@example.test']);

        $this->assertSame([], $this->groupKeys($this->search('camille', $this->admin())));
        $this->assertSame([], $this->groupKeys($this->search('example.test', $this->admin())));
    }

    public function test_a_user_has_one_hundred_and_twenty_searches_a_minute(): void
    {
        $user = $this->reader();
        $other = $this->reader();

        for ($search = 1; $search <= 120; $search++) {
            $this->search('192.0.2.1', $user)->assertOk();
        }

        $this->search('192.0.2.1', $user)->assertTooManyRequests();

        // The limit is counted for each user.
        $this->search('192.0.2.1', $other)->assertOk();
    }

    public function test_searching_writes_nothing_in_the_activity_log(): void
    {
        Device::factory()->create(['name' => 'sw-coeur']);
        $before = Activity::query()->count();

        $this->search('coeur', $this->admin())->assertOk();

        $this->assertSame($before, Activity::query()->count());
    }

    /**
     * The objects of the network that a subnet leaves alone are not
     * returned by mistake.
     */
    public function test_an_address_typed_finds_no_port_nor_other_text(): void
    {
        $member = DeviceMember::factory()->create(['device_id' => Device::factory()->create(['name' => 'sw-1'])->id]);
        Port::factory()->create(['device_member_id' => $member->id, 'name' => '1/0/1', 'description' => '10.1.20.37']);
        Subnet::factory()->cidr('10.1.20.0/24')->create();

        $this->assertSame(['subnets'], $this->groupKeys($this->search('10.1.20.37')));
    }
}
