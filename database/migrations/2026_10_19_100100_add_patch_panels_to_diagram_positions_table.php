<?php

// torsade/database/migrations/2026_10_19_100100_add_patch_panels_to_diagram_positions_table.php

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
        // A patch panel drawn on the physical diagram is placed by hand as a
        // device is. A panel deleted takes its places along.
        Schema::table('diagram_positions', function (Blueprint $table) {
            $table->foreignId('patch_panel_id')->nullable()->after('subnet_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('diagram_positions', function (Blueprint $table) {
            // A panel has one place on a diagram.
            $table->unique(['site_id', 'view', 'patch_panel_id']);
        });

        // As before, a device has its device, a subnet its subnet, the
        // internet none; a panel has its panel, on the physical diagram
        // only.
        DB::statement('ALTER TABLE diagram_positions DROP CONSTRAINT diagram_positions_node_check');
        DB::statement(<<<'SQL'
            ALTER TABLE diagram_positions ADD CONSTRAINT diagram_positions_node_check CHECK (
                (node = 'device' AND device_id IS NOT NULL AND subnet_id IS NULL AND patch_panel_id IS NULL)
                OR (node = 'subnet' AND device_id IS NULL AND subnet_id IS NOT NULL AND patch_panel_id IS NULL)
                OR (node = 'internet' AND device_id IS NULL AND subnet_id IS NULL AND patch_panel_id IS NULL)
                OR (node = 'patch_panel' AND view = 'physical' AND device_id IS NULL AND subnet_id IS NULL AND patch_panel_id IS NOT NULL)
            )
            SQL);
    }

    /**
     * Reverse the migrations. The positions of the panels are lost: the
     * former version does not draw them. The others are kept.
     */
    public function down(): void
    {
        DB::table('diagram_positions')->where('node', 'patch_panel')->delete();

        DB::statement('ALTER TABLE diagram_positions DROP CONSTRAINT diagram_positions_node_check');

        Schema::table('diagram_positions', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'view', 'patch_panel_id']);
            $table->dropForeign(['patch_panel_id']);
        });

        Schema::table('diagram_positions', function (Blueprint $table) {
            $table->dropColumn('patch_panel_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE diagram_positions ADD CONSTRAINT diagram_positions_node_check CHECK (
                (node = 'device' AND device_id IS NOT NULL AND subnet_id IS NULL)
                OR (node = 'subnet' AND device_id IS NULL AND subnet_id IS NOT NULL)
                OR (node = 'internet' AND device_id IS NULL AND subnet_id IS NULL)
            )
            SQL);
    }
};
