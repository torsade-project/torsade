<?php

// torsade/database/migrations/2026_10_12_100100_create_buildings_table.php

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
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            // A site cannot be deleted while buildings belong to it.
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            // A short name used in labels, "B": capitals, digits, hyphens.
            $table->string('code', 10)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            // The name and the code identify a building within its site.
            $table->unique(['site_id', 'name']);
            $table->unique(['site_id', 'code']);
        });
    }

    /**
     * Reverse the migrations. The buildings are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
