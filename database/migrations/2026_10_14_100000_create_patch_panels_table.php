<?php

// torsade/database/migrations/2026_10_14_100000_create_patch_panels_table.php

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
        // A patch panel, kept in a room: the horizontal cables of the jacks
        // end on its ports, and the patch cords leave from them.
        Schema::create('patch_panels', function (Blueprint $table) {
            $table->id();
            // A room cannot be deleted while it holds a panel.
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->string('name', 64);
            // Its ports are numbered from 1 to this number, and made with
            // it: 96 at most.
            $table->unsignedTinyInteger('port_count');
            $table->string('connector', 20);
            // "6A", when it is known.
            $table->string('category', 16)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            // The name identifies a panel within its room.
            $table->unique(['room_id', 'name']);
        });
    }

    /**
     * Reverse the migrations. The patch panels are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('patch_panels');
    }
};
