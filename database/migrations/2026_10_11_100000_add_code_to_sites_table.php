<?php

// torsade/database/migrations/2026_10_11_100000_add_code_to_sites_table.php

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
            // A short code used in the names of devices, such as "HV" in
            // "HV-MAI-01". Optional; stored in upper case by the application.
            $table->string('code', 10)->nullable()->unique()->after('name');
        });
    }

    /**
     * Reverse the migrations. The codes are lost.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
