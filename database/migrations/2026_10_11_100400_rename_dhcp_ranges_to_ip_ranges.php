<?php

// torsade/database/migrations/2026_10_11_100400_rename_dhcp_ranges_to_ip_ranges.php

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
        // A range is no longer a DHCP range only: it has a kind.
        Schema::rename('dhcp_ranges', 'ip_ranges');

        Schema::table('ip_ranges', function (Blueprint $table) {
            // dhcp, static or reserved. The ranges that already exist were
            // all DHCP ranges: the default gives them their kind.
            $table->string('kind', 20)->default('dhcp')->after('subnet_id');
            $table->string('name', 64)->nullable()->after('end_address');
        });
    }

    /**
     * Reverse the migrations. Only DHCP ranges existed before: the static
     * and reserved ranges are deleted, and the names of the others are lost.
     */
    public function down(): void
    {
        DB::table('ip_ranges')->where('kind', '!=', 'dhcp')->delete();

        Schema::table('ip_ranges', function (Blueprint $table) {
            $table->dropColumn(['kind', 'name']);
        });

        Schema::rename('ip_ranges', 'dhcp_ranges');
    }
};
