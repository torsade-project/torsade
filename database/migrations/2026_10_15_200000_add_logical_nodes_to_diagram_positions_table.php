<?php

// torsade/database/migrations/2026_10_15_200000_add_logical_nodes_to_diagram_positions_table.php

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
        // What a position places: a device, a subnet or the node of the
        // internet. Every position saved so far is the one of a device.
        Schema::table('diagram_positions', function (Blueprint $table) {
            $table->string('node', 16)->default('device')->after('view');
        });

        Schema::table('diagram_positions', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable()->change();
            // A subnet deleted takes its places on the diagrams along.
            $table->foreignId('subnet_id')->nullable()->after('device_id')->constrained()->cascadeOnDelete();
        });

        // 1 for the node of the internet, NULL for any other: unique with
        // the site and the view, it keeps one such node per diagram, while
        // the NULLs of the other nodes never collide.
        Schema::table('diagram_positions', function (Blueprint $table) {
            $table->unsignedTinyInteger('internet_node')->nullable()->after('subnet_id')
                ->storedAs("case when node = 'internet' then 1 end");
        });

        Schema::table('diagram_positions', function (Blueprint $table) {
            // A subnet has one place on a diagram, and so has the internet.
            $table->unique(['site_id', 'view', 'subnet_id']);
            $table->unique(['site_id', 'view', 'internet_node']);
        });

        // A device has its device, a subnet its subnet, the internet
        // neither.
        DB::statement(<<<'SQL'
            ALTER TABLE diagram_positions ADD CONSTRAINT diagram_positions_node_check CHECK (
                (node = 'device' AND device_id IS NOT NULL AND subnet_id IS NULL)
                OR (node = 'subnet' AND device_id IS NULL AND subnet_id IS NOT NULL)
                OR (node = 'internet' AND device_id IS NULL AND subnet_id IS NULL)
            )
            SQL);
    }

    /**
     * Reverse the migrations. The positions of the subnets and of the
     * internet are lost: the logical diagrams lay them out on their own
     * again. Those of the devices are kept.
     */
    public function down(): void
    {
        DB::table('diagram_positions')->where('node', '!=', 'device')->delete();

        DB::statement('ALTER TABLE diagram_positions DROP CONSTRAINT diagram_positions_node_check');

        Schema::table('diagram_positions', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'view', 'internet_node']);
            $table->dropUnique(['site_id', 'view', 'subnet_id']);
            $table->dropForeign(['subnet_id']);
        });

        Schema::table('diagram_positions', function (Blueprint $table) {
            $table->dropColumn(['internet_node', 'subnet_id']);
        });

        Schema::table('diagram_positions', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable(false)->change();
            $table->dropColumn('node');
        });
    }
};
