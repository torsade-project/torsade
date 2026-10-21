<?php

// torsade/database/migrations/2026_10_13_100100_create_link_ends_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The two ends of a link, one line each. A link written as two
        // columns of ports could not keep a port out of a second link: the
        // port would have to be looked for in both columns. Here a port is
        // in one line at most, and one unique index says so.
        Schema::create('link_ends', function (Blueprint $table) {
            $table->id();
            // The ends go with their link.
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            // A port is the end of one link at most, whichever end it is.
            // It is not deleted while its link exists: nor is its member,
            // nor its device, whose deletion would take it along.
            $table->foreignId('port_id')->unique()->constrained()->restrictOnDelete();
            // Which of the two ends: 1 or 2, checked below.
            $table->unsignedTinyInteger('end');
            $table->timestamps();

            // A link has one end 1 and one end 2.
            $table->unique(['link_id', 'end']);
        });

        // With the unique index above, a link never has more than two
        // ends. The schema builder has no method for a check constraint: it
        // is written as a statement, which holds no variable.
        DB::statement('ALTER TABLE link_ends ADD CONSTRAINT link_ends_end_check CHECK (`end` IN (1, 2))');
    }

    /**
     * Reverse the migrations. Which ports each link joins is lost; the
     * ports themselves stay.
     */
    public function down(): void
    {
        Schema::dropIfExists('link_ends');
    }
};
