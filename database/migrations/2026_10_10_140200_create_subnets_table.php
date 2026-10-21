<?php

// torsade/database/migrations/2026_10_10_140200_create_subnets_table.php

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
        Schema::create('subnets', function (Blueprint $table) {
            $table->id();
            // Optional: a carrier link or a VPN has a subnet and no VLAN.
            // Unique: a VLAN carries one subnet at most.
            $table->foreignId('vlan_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('ip_version')->default(4);
            // Addresses are stored in binary, 4 bytes for IPv4 and 16 for
            // IPv6: sorting the column gives the numeric order.
            $table->binary('network', 16);
            $table->unsignedTinyInteger('prefix_length');
            $table->binary('gateway', 16)->nullable();
            $table->string('name', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['network', 'prefix_length']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subnets');
    }
};
