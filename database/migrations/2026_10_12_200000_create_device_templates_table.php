<?php

// torsade/database/migrations/2026_10_12_200000_create_device_templates_table.php

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
        // What a kind of machine is made of: a switch with 24 copper ports
        // and 4 fibre ports. The ports of a device are copied from its
        // template once, when one of its members is added.
        Schema::create('device_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            // The kind of machine the template is for: a switch, a
            // firewall, a server.
            $table->string('device_type', 20);
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            // What the machine can give to everything it powers, in watts.
            $table->unsignedInteger('poe_budget_watts')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. The device templates are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_templates');
    }
};
