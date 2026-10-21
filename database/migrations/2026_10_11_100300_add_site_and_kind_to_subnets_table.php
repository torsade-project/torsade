<?php

// torsade/database/migrations/2026_10_11_100300_add_site_and_kind_to_subnets_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            // Optional: a subnet without a VLAN (carrier link, tunnel) can
            // still belong to a site. With a VLAN, it is the site of the
            // VLAN, kept equal by the application.
            $table->foreignId('site_id')->nullable()->after('vlan_id')->constrained()->restrictOnDelete();
            // segment, point_to_point, loopback, tunnel or external.
            $table->string('kind', 20)->default('segment')->after('site_id');
        });

        // The subnets that already exist take the site of their VLAN.
        DB::table('vlans')->select(['id', 'site_id'])->orderBy('id')->each(function (object $vlan): void {
            DB::table('subnets')->where('vlan_id', $vlan->id)->update(['site_id' => $vlan->site_id]);
        });

        // Their kind is read from what they are: a /31 with neither gateway
        // nor range is a point-to-point link, a /32 with no VLAN, gateway or
        // range is a loopback. Everything else stays a segment.
        $withoutRange = fn ($query) => $query
            ->where('ip_version', 4)
            ->whereNull('gateway')
            ->whereNotExists(fn ($ranges) => $ranges->from('dhcp_ranges')->whereColumn('dhcp_ranges.subnet_id', 'subnets.id'));

        DB::table('subnets')
            ->where('prefix_length', 31)
            ->where($withoutRange)
            ->update(['kind' => 'point_to_point']);

        DB::table('subnets')
            ->where('prefix_length', 32)
            ->whereNull('vlan_id')
            ->where($withoutRange)
            ->update(['kind' => 'loopback']);
    }

    /**
     * Reverse the migrations. The kind of each subnet is lost, and so is the
     * site of the subnets that have no VLAN.
     */
    public function down(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropColumn(['site_id', 'kind']);
        });
    }
};
