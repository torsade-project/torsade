<?php

// torsade/database/migrations/2026_10_19_100000_create_jack_cords_table.php

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
        // A jack cord, on the side of the desk: a port of a device plugged
        // into a wall jack.
        Schema::create('jack_cords', function (Blueprint $table) {
            $table->id();
            // A jack has one device plugged into it at most. It is not
            // deleted while it has one.
            $table->foreignId('jack_id')->unique()->constrained()->restrictOnDelete();
            // A port of a device is plugged into one jack at most. It is not
            // deleted while it is, nor is its member nor its device, whose
            // deletion would take it along.
            $table->foreignId('port_id')->unique()->constrained()->restrictOnDelete();
            $table->string('color', 32)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. The jack cords are lost: which device is
     * plugged into which jack. The jacks and the ports stay.
     */
    public function down(): void
    {
        Schema::dropIfExists('jack_cords');
    }
};
