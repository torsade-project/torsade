<?php

// torsade/database/migrations/2026_10_11_200100_create_addressing_rules_table.php

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
        // How the organisation numbers its networks: a block of its own,
        // cut into one block per site, itself cut into one subnet per
        // VLAN. It only serves to propose addresses and to point out what
        // differs: no other object refers to it.
        Schema::create('addressing_rules', function (Blueprint $table) {
            $table->id();
            // An installation has one rule at most. The column always
            // holds the same value and is unique: a second line is refused
            // by the database itself, whatever writes it.
            $table->unsignedTinyInteger('singleton')->default(1)->unique();
            // A block of the organisation. It cannot be deleted while the
            // rule starts from it.
            $table->foreignId('aggregate_block_id')->constrained('address_blocks')->restrictOnDelete();
            // The lengths are checked together by the application: block
            // of the organisation < blocks of sites < subnets of VLANs.
            $table->unsignedTinyInteger('site_prefix_length');
            $table->unsignedTinyInteger('vlan_prefix_length');
            // Where the gateway of a subnet is proposed: first, last, none.
            $table->string('gateway_position', 10)->default('first');
            $table->timestamps();
        });

        // The unique column must keep its one value, or a second line
        // would pass with another one. The schema builder has no method
        // for a check constraint: it is written as a statement, which
        // holds no variable.
        DB::statement('ALTER TABLE addressing_rules ADD CONSTRAINT addressing_rules_singleton_check CHECK (singleton = 1)');

        // The way a subnet of a VLAN is cut into ranges, as offsets from
        // its network address: "2 to 20, reserved" is .2 to .20 in a /24.
        Schema::create('addressing_rule_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addressing_rule_id')->constrained('addressing_rules')->cascadeOnDelete();
            // dhcp, static or reserved, as for the ranges of a subnet.
            $table->string('kind', 20);
            $table->unsignedInteger('start_offset');
            $table->unsignedInteger('end_offset');
            $table->string('name', 64)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. The addressing rule and its ranges are lost;
     * nothing else refers to them.
     */
    public function down(): void
    {
        Schema::dropIfExists('addressing_rule_ranges');
        Schema::dropIfExists('addressing_rules');
    }
};
