<?php

// torsade/database/migrations/2026_10_14_100300_create_patch_cords_table.php

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
        // A patch cord, from a port of a panel to a port of a device.
        Schema::create('patch_cords', function (Blueprint $table) {
            $table->id();
            // A port of a panel has one cord at most. It is not deleted
            // while it has one, nor is its panel.
            $table->foreignId('patch_panel_port_id')->unique()->constrained()->restrictOnDelete();
            // A port of a device has one cord at most. It is not deleted
            // while it has one, nor is its member nor its device, whose
            // deletion would take it along.
            $table->foreignId('port_id')->unique()->constrained()->restrictOnDelete();
            $table->string('color', 32)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. The patch cords are lost: which port of a
     * panel goes to which port of a device. The ports stay.
     */
    public function down(): void
    {
        Schema::dropIfExists('patch_cords');
    }
};
