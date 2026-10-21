<?php

// torsade/database/migrations/2026_10_12_200200_create_device_members_table.php

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
        // The boxes a device is made of. A switch alone has one member; a
        // stack has several, managed as one switch.
        Schema::create('device_members', function (Blueprint $table) {
            $table->id();
            // The members go with their device.
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            // Its number in the device: 1 to 16, checked by the application.
            $table->unsignedTinyInteger('position');
            // primary, secondary or member. That a device has one primary
            // and one secondary at most is checked by the application.
            $table->string('role', 20)->default('member');
            // A template cannot be deleted while a member uses it.
            $table->foreignId('device_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('serial_number', 100)->nullable();
            // Empty: the member is where its device is. A room cannot be
            // deleted while a member is kept in it.
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();

            // The number identifies a member within its device.
            $table->unique(['device_id', 'position']);
            // What the ports refer to, so that a port can only belong to
            // the device of its member.
            $table->unique(['id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations. The members of the devices are lost; the
     * devices stay, with their own serial number.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_members');
    }
};
