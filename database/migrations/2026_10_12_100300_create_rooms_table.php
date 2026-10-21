<?php

// torsade/database/migrations/2026_10_12_100300_create_rooms_table.php

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
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            // A floor cannot be deleted while rooms belong to it.
            $table->foreignId('floor_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            // A room that holds a rack, switches or patch panels.
            $table->boolean('is_technical')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();

            // The name identifies a room within its floor.
            $table->unique(['floor_id', 'name']);
        });
    }

    /**
     * Reverse the migrations. The rooms are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
