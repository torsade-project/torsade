<?php

// torsade/database/migrations/2026_10_14_100400_add_jack_to_ip_addresses_table.php

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
        Schema::table('ip_addresses', function (Blueprint $table) {
            // The jack the machine is plugged into. The addresses already
            // there have none. A jack cannot be deleted while an address is
            // plugged into it.
            $table->foreignId('jack_id')->nullable()->after('device_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations. Which jack each address is plugged into is
     * lost; the addresses stay.
     */
    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jack_id');
        });
    }
};
