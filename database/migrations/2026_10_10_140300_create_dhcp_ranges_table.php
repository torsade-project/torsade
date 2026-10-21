<?php

// torsade/database/migrations/2026_10_10_140300_create_dhcp_ranges_table.php

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
        Schema::create('dhcp_ranges', function (Blueprint $table) {
            $table->id();
            // A range has no meaning without its subnet: it goes with it.
            $table->foreignId('subnet_id')->constrained()->cascadeOnDelete();
            $table->binary('start_address', 16);
            $table->binary('end_address', 16);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dhcp_ranges');
    }
};
