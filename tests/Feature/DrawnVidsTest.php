<?php

// torsade/tests/Feature/DrawnVidsTest.php

namespace Tests\Feature;

use App\Models\CatalogVlan;
use App\Models\Site;
use App\Models\Vlan;
use Database\Factories\DrawnVids;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * The VLAN numbers the factories draw never meet a number a test writes by
 * hand: they come from ranges of their own, and a test that writes a number
 * of those ranges is stopped at once, instead of failing once in a while.
 */
class DrawnVidsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_factories_draw_their_numbers_in_two_ranges_of_their_own(): void
    {
        foreach (range(1, 400) as $draw) {
            $vid = Vlan::factory()->raw(['site_id' => 1])['vid'];

            $this->assertGreaterThanOrEqual(2100, $vid, "draw {$draw}");
            $this->assertLessThanOrEqual(2999, $vid, "draw {$draw}");
            $this->assertTrue(DrawnVids::wasDrawn($vid));
        }

        foreach (range(1, 400) as $draw) {
            $vid = CatalogVlan::factory()->raw()['vid'];

            $this->assertGreaterThanOrEqual(3100, $vid, "draw {$draw}");
            $this->assertLessThanOrEqual(3999, $vid, "draw {$draw}");
            $this->assertTrue(DrawnVids::wasDrawn($vid));
        }

        // Valid numbers of VLANs, in ranges that do not meet.
        $this->assertGreaterThanOrEqual(Vlan::MIN_VID, DrawnVids::FIRST_OF_VLANS);
        $this->assertLessThan(DrawnVids::FIRST_OF_CATALOGUE, DrawnVids::LAST_OF_VLANS);
        $this->assertLessThanOrEqual(Vlan::MAX_VID, DrawnVids::LAST_OF_CATALOGUE);

        // The name follows the number, as before.
        $drawn = Vlan::factory()->raw(['site_id' => 1]);

        $this->assertSame('VLAN-'.$drawn['vid'], $drawn['name']);
    }

    public function test_what_was_drawn_is_forgotten_from_one_test_to_the_next(): void
    {
        // Nothing was drawn yet in this test.
        foreach ([2100, 2500, 2999, 3100, 3500, 3999] as $vid) {
            $this->assertTrue(DrawnVids::isKept($vid));
            $this->assertFalse(DrawnVids::wasDrawn($vid));
        }

        foreach ([1, 10, 999, 2099, 3000, 3099, 4000, 4094] as $vid) {
            $this->assertFalse(DrawnVids::isKept($vid));
        }
    }

    public function test_a_factory_given_a_number_draws_none(): void
    {
        $site = Site::factory()->create();

        Vlan::factory()->create(['site_id' => $site->id, 'vid' => 10]);
        CatalogVlan::factory()->create(['vid' => 20]);
        Vlan::factory()->raw(['site_id' => $site->id, 'vid' => 30]);

        // Nothing drawn, in either range: a number written by hand can
        // never be taken for a drawn one.
        foreach ([...range(DrawnVids::FIRST_OF_VLANS, DrawnVids::LAST_OF_VLANS), ...range(DrawnVids::FIRST_OF_CATALOGUE, DrawnVids::LAST_OF_CATALOGUE)] as $vid) {
            $this->assertFalse(DrawnVids::wasDrawn($vid), (string) $vid);
        }

        // The name follows the number given.
        $this->assertSame('VLAN-10', Vlan::query()->where('vid', 10)->sole()->name);
        $this->assertSame('CATALOGUE-20', CatalogVlan::query()->where('vid', 20)->sole()->name);
    }

    public function test_a_vlan_given_by_hand_a_number_kept_for_the_factories_stops_the_test(): void
    {
        $site = Site::factory()->create();

        foreach ([2100, 2500, 2999, 3100, 3999] as $vid) {
            try {
                Vlan::factory()->create(['site_id' => $site->id, 'vid' => $vid]);

                $this->fail("The VLAN number {$vid} was accepted.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString((string) $vid, $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('vlans', 0);
    }

    public function test_an_entry_of_the_catalogue_given_by_hand_such_a_number_stops_the_test_too(): void
    {
        foreach ([2500, 3100, 3500, 3999] as $vid) {
            try {
                CatalogVlan::factory()->create(['vid' => $vid]);

                $this->fail("The VLAN number {$vid} was accepted.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString((string) $vid, $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('catalog_vlans', 0);
    }

    public function test_numbers_written_outside_those_ranges_and_numbers_drawn_are_stored(): void
    {
        $site = Site::factory()->create();

        // Around the ranges, and at the two ends of what a VLAN can be.
        foreach ([1, 10, 2099, 3000, 3099, 4000, 4094] as $vid) {
            Vlan::factory()->create(['site_id' => $site->id, 'vid' => $vid, 'name' => "VLAN {$vid}"]);
            CatalogVlan::factory()->create(['vid' => $vid, 'name' => "Entrée {$vid}"]);
        }

        // A number that was drawn is stored, and may be given again: to
        // another VLAN of another site, or to a VLAN that follows a drawn
        // entry.
        $drawn = Vlan::factory()->create(['site_id' => $site->id]);
        $entry = CatalogVlan::factory()->create();

        Vlan::factory()->create(['vid' => $drawn->vid]);
        Vlan::factory()->create(['site_id' => $site->id, 'vid' => $entry->vid, 'catalog_vlan_id' => $entry->id]);

        $drawn->update(['name' => 'Renommé']);

        $this->assertDatabaseCount('vlans', 10);
        $this->assertDatabaseCount('catalog_vlans', 8);
        $this->assertTrue(DrawnVids::isKept($drawn->vid));
        $this->assertTrue(DrawnVids::isKept($entry->vid));
    }
}
