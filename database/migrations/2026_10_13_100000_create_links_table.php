<?php

// torsade/database/migrations/2026_10_13_100000_create_links_table.php

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
        // A link between two ports of two devices: what is plugged into
        // what, whatever the cables and the patch panels in between. Its
        // two ends are in a table of their own.
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            // What carries it: copper, a fibre, a direct-attach cable. Not
            // always known.
            $table->string('medium', 20)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. The links are lost: their medium, their
     * status and their description.
     */
    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
