<?php

// torsade/database/migrations/2026_10_11_200000_add_number_to_sites_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // The rank of the site in the addressing rule of the
            // organisation: the site 20 gets the twenty-first block.
            // Optional, 0 to 65535; existing sites start without one.
            $table->unsignedSmallInteger('number')->nullable()->unique()->after('code');
        });
    }

    /**
     * Reverse the migrations. The numbers of the sites are lost.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });
    }
};
