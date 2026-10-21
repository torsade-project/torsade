<?php

// torsade/database/migrations/2026_10_14_100200_create_jacks_table.php

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
        // A wall jack, on the floor where it is.
        Schema::create('jacks', function (Blueprint $table) {
            $table->id();
            // A floor cannot be deleted while it has jacks.
            $table->foreignId('floor_id')->constrained()->restrictOnDelete();
            // "B.15": free text, sorted as a person reads it.
            $table->string('label', 32);
            // "Office 12, by the window".
            $table->string('location', 100)->nullable();
            // The horizontal cable, laid once: a port of a panel has one
            // jack at most, and is not deleted while it has one, nor is its
            // panel, whose deletion would take it along.
            $table->foreignId('patch_panel_port_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            // The label identifies a jack within its floor.
            $table->unique(['floor_id', 'label']);
        });
    }

    /**
     * Reverse the migrations. The jacks are lost, with the port of a panel
     * each one is cabled to.
     */
    public function down(): void
    {
        Schema::dropIfExists('jacks');
    }
};
