<?php

// torsade/database/migrations/2026_10_12_100400_create_devices_table.php

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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            // A site cannot be deleted while devices belong to it.
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            // Optional: a small site often has no plan of its rooms. A room
            // cannot be deleted while devices are kept in it. That the room
            // belongs to the site of the device is checked by the
            // application.
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            // Free text: "Reception printer" as well as "HV-MAI-01".
            $table->string('name', 100);
            $table->string('type', 20);
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            // The name identifies a device within its site. The collation of
            // the column does not tell capitals from small letters: "SW-01"
            // and "sw-01" are the same name.
            $table->unique(['site_id', 'name']);
        });
    }

    /**
     * Reverse the migrations. The devices are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
