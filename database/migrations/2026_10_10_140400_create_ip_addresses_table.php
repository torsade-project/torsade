<?php

// torsade/database/migrations/2026_10_10_140400_create_ip_addresses_table.php

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
        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            // A subnet cannot be deleted while addresses are documented in it.
            $table->foreignId('subnet_id')->constrained()->restrictOnDelete();
            // Unique across the whole installation, whatever the status: there
            // is a single addressing domain.
            $table->binary('address', 16)->unique();
            $table->string('hostname', 253)->nullable()->index();
            // Stored as aa:bb:cc:dd:ee:ff. Not unique: a machine with two
            // addresses is two records with the same MAC.
            $table->string('mac_address', 17)->nullable()->index();
            $table->string('device_type', 20)->nullable();
            $table->string('assignment', 20)->default('static');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
    }
};
