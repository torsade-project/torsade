<?php

// torsade/database/migrations/2026_10_14_100100_create_patch_panel_ports_table.php

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
        // The ports of a patch panel, one line each: a jack and a patch
        // cord are each attached to one of them.
        Schema::create('patch_panel_ports', function (Blueprint $table) {
            $table->id();
            // The ports go with their panel.
            $table->foreignId('patch_panel_id')->constrained()->cascadeOnDelete();
            // From 1 to the number of ports of the panel.
            $table->unsignedTinyInteger('number');
            $table->timestamps();

            $table->unique(['patch_panel_id', 'number']);
        });
    }

    /**
     * Reverse the migrations. The ports of the panels are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('patch_panel_ports');
    }
};
