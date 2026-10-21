<?php

// torsade/database/migrations/2026_10_12_200300_create_port_aggregates_table.php

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
        // Several ports of a device used as one link. The mode and the
        // VLANs are set on the aggregate, not on its ports.
        Schema::create('port_aggregates', function (Blueprint $table) {
            $table->id();
            // The aggregates go with their device.
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            // As the device calls it: "Po1", "lag 1", "Trk1".
            $table->string('name', 32);
            // lacp or static.
            $table->string('protocol', 20)->default('lacp');
            // access, trunk, or none.
            $table->string('mode', 20)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            // The name identifies an aggregate within its device.
            $table->unique(['device_id', 'name']);
        });
    }

    /**
     * Reverse the migrations. The aggregates are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('port_aggregates');
    }
};
