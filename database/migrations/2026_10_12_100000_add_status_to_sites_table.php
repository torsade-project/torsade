<?php

// torsade/database/migrations/2026_10_12_100000_add_status_to_sites_table.php

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
            // A site can be designed before it exists, and documented after
            // it closed. The sites already there are in service: they take
            // the default.
            $table->string('status', 20)->default('active')->after('number');
        });
    }

    /**
     * Reverse the migrations. The statuses of the sites are lost.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
