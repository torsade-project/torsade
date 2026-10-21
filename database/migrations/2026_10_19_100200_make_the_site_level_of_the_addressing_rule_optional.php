<?php

// torsade/database/migrations/2026_10_19_100200_make_the_site_level_of_the_addressing_rule_optional.php

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
        // A rule may have no level of sites: the subnets of VLANs are then
        // ranked by VID straight in the block of the organisation. The
        // rule in place keeps its length.
        Schema::table('addressing_rules', function (Blueprint $table) {
            $table->unsignedTinyInteger('site_prefix_length')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations. A rule without a level of sites cannot be
     * written in the former version: it is deleted, with its ranges, as
     * deleting it from the page does. Nothing else refers to it.
     */
    public function down(): void
    {
        DB::table('addressing_rules')->whereNull('site_prefix_length')->delete();

        Schema::table('addressing_rules', function (Blueprint $table) {
            $table->unsignedTinyInteger('site_prefix_length')->nullable(false)->change();
        });
    }
};
