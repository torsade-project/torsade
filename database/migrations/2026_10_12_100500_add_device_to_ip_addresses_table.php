<?php

// torsade/database/migrations/2026_10_12_100500_add_device_to_ip_addresses_table.php

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
            // An address attached to a device is one of its interfaces. The
            // addresses already there belong to none. A device cannot be
            // deleted while addresses are attached to it.
            $table->foreignId('device_id')->nullable()->after('assignment')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations. Which device each address belongs to is lost;
     * the addresses stay, with their host name and their type.
     */
    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });
    }
};
