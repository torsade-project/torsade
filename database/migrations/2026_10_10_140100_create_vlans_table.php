<?php

// torsade/database/migrations/2026_10_10_140100_create_vlans_table.php

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
        Schema::create('vlans', function (Blueprint $table) {
            $table->id();
            // A site cannot be deleted while VLANs belong to it.
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            // 1 to 4094, checked by the application.
            $table->unsignedSmallInteger('vid');
            $table->string('name', 64);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            // The number and the name identify a VLAN within its site only.
            $table->unique(['site_id', 'vid']);
            $table->unique(['site_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vlans');
    }
};
