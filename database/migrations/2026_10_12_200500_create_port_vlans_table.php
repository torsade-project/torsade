<?php

// torsade/database/migrations/2026_10_12_200500_create_port_vlans_table.php

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
        // A VLAN carried by a port, or by an aggregate of ports. Whether it
        // is tagged belongs here, not to the VLAN: the same VLAN is
        // untagged on an access port and tagged on a trunk.
        Schema::create('port_vlans', function (Blueprint $table) {
            $table->id();
            // One of the two, never both: checked below. The line goes with
            // its port or its aggregate.
            $table->foreignId('port_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('port_aggregate_id')->nullable()->constrained()->cascadeOnDelete();
            // A VLAN cannot be deleted while a port carries it.
            $table->foreignId('vlan_id')->constrained()->restrictOnDelete();
            $table->boolean('tagged');
            $table->timestamps();

            // A port or an aggregate carries a VLAN once.
            $table->unique(['port_id', 'vlan_id']);
            $table->unique(['port_aggregate_id', 'vlan_id']);
        });

        // A line belongs to a port or to an aggregate: exactly one of the
        // two columns is filled in. The schema builder has no method for a
        // check constraint: it is written as a statement, which holds no
        // variable.
        DB::statement('ALTER TABLE port_vlans ADD CONSTRAINT port_vlans_one_holder_check CHECK ((port_id IS NULL) <> (port_aggregate_id IS NULL))');
    }

    /**
     * Reverse the migrations. Which VLANs each port and each aggregate
     * carries is lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('port_vlans');
    }
};
