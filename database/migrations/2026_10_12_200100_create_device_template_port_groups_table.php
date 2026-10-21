<?php

// torsade/database/migrations/2026_10_12_200100_create_device_template_port_groups_table.php

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
        // The ports of a template, by groups of ports that are alike:
        // "24 ports named {m}/0/{n}, RJ45, 1 Gbit/s, 802.3at".
        Schema::create('device_template_port_groups', function (Blueprint $table) {
            $table->id();
            // The groups go with their template.
            $table->foreignId('device_template_id')->constrained()->cascadeOnDelete();
            // The order of the groups in the template.
            $table->unsignedSmallInteger('position');
            // 1 to 128, checked by the application.
            $table->unsignedSmallInteger('quantity');
            // How each port is named: {m} is the number of the member of
            // the device, {n} the number of the port.
            $table->string('name_pattern', 32);
            // The number of the first port: 0 or 1 most of the time.
            $table->unsignedSmallInteger('first_number')->default(1);
            $table->string('connector', 20);
            $table->string('max_speed', 20);
            // The IEEE type of what the port can power, "none" included.
            $table->string('poe_type', 20)->default('none');
            // What the manufacturer calls it: "PoE+++".
            $table->string('poe_label', 32)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. The groups of ports of the templates are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_template_port_groups');
    }
};
