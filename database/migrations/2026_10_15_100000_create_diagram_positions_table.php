<?php

// torsade/database/migrations/2026_10_15_100000_create_diagram_positions_table.php

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
        // Where a device was placed by hand on a diagram of a site. A
        // diagram without any row is laid out on its own.
        Schema::create('diagram_positions', function (Blueprint $table) {
            $table->id();
            // The positions go with the site, and with the device: a device
            // deleted, or a site, takes its places on the diagrams along.
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('view', 16);
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            // In the units of the drawing, on its grid.
            $table->integer('x');
            $table->integer('y');
            $table->timestamps();

            // A device has one place on a diagram.
            $table->unique(['site_id', 'view', 'device_id']);
        });
    }

    /**
     * Reverse the migrations. The positions placed by hand are lost: every
     * diagram goes back to the layout it works out on its own.
     */
    public function down(): void
    {
        Schema::dropIfExists('diagram_positions');
    }
};
