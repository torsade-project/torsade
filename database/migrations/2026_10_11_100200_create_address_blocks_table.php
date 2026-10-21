<?php

// torsade/database/migrations/2026_10_11_100200_create_address_blocks_table.php

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
        // A block of addresses set aside for the organisation ("10.0.0.0/8")
        // or for one site ("10.1.0.0/16"). No key links a block to the
        // subnets it holds: which one contains which follows from the
        // addresses.
        Schema::create('address_blocks', function (Blueprint $table) {
            $table->id();
            // Without a site, the block belongs to the organisation. A site
            // cannot be deleted while blocks belong to it.
            $table->foreignId('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('ip_version')->default(4);
            // Stored in binary, like the addresses of subnets: sorting the
            // column gives the numeric order.
            $table->binary('network', 16);
            $table->unsignedTinyInteger('prefix_length');
            $table->string('name', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['network', 'prefix_length']);
        });
    }

    /**
     * Reverse the migrations. The blocks are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('address_blocks');
    }
};
