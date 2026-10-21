<?php

// torsade/database/migrations/2026_10_12_100200_create_floors_table.php

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
        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            // A building cannot be deleted while floors belong to it.
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            // The order of the floors: -1 for a basement, 0 for the ground
            // floor. -9 to 99, checked by the application.
            $table->smallInteger('level');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();

            // A building has one floor per level, and no two of one name.
            $table->unique(['building_id', 'level']);
            $table->unique(['building_id', 'name']);
        });
    }

    /**
     * Reverse the migrations. The floors are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('floors');
    }
};
