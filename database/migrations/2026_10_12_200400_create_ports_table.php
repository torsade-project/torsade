<?php

// torsade/database/migrations/2026_10_12_200400_create_ports_table.php

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
        Schema::create('ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_member_id');
            // The device of its member, repeated here for one reason: the
            // name of a port is unique within its device, and the database
            // can only guarantee it on columns of this table.
            $table->foreignId('device_id');
            // As the device calls it: "1/0/18", "Gi2/0/1", "port3".
            $table->string('name', 32);
            // The order of the ports within their member.
            $table->unsignedSmallInteger('position');
            $table->string('connector', 20);
            $table->string('max_speed', 20);
            $table->string('poe_type', 20)->default('none');
            $table->string('poe_label', 32)->nullable();
            // False: the port is shut down.
            $table->boolean('enabled')->default(true);
            // access, trunk, or none: not configured, or routed.
            $table->string('mode', 20)->nullable();
            // A port keeps existing when its aggregate is deleted.
            $table->foreignId('port_aggregate_id')->nullable()->constrained()->nullOnDelete();
            // What is plugged into it.
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'name']);
            $table->index(['device_member_id', 'position']);

            // The ports go with their member, and the two columns together
            // refer to it: a port cannot name another device than the one
            // of its member.
            $table->foreign(['device_member_id', 'device_id'])
                ->references(['id', 'device_id'])
                ->on('device_members')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations. The ports are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('ports');
    }
};
