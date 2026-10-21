<?php

// torsade/database/migrations/2026_10_11_100100_create_catalog_vlans_table.php

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
        // The VLANs the organisation has standardised: a number and a name
        // that each site is expected to reuse. It is a standard, not an
        // object of the network: it has no status.
        Schema::create('catalog_vlans', function (Blueprint $table) {
            $table->id();
            // 1 to 4094, checked by the application.
            $table->unsignedSmallInteger('vid')->unique();
            $table->string('name', 64)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::table('vlans', function (Blueprint $table) {
            // Optional: an existing VLAN is attached to the catalogue by
            // hand. An entry cannot be deleted while VLANs are attached.
            $table->foreignId('catalog_vlan_id')->nullable()->after('site_id')
                ->constrained('catalog_vlans')->restrictOnDelete();

            // An entry is used once per site at most.
            $table->unique(['site_id', 'catalog_vlan_id']);
        });
    }

    /**
     * Reverse the migrations. The catalogue and what was attached to it are
     * lost; the VLANs themselves are kept.
     */
    public function down(): void
    {
        Schema::table('vlans', function (Blueprint $table) {
            $table->dropForeign(['catalog_vlan_id']);
            $table->dropUnique(['site_id', 'catalog_vlan_id']);
            $table->dropColumn('catalog_vlan_id');
        });

        Schema::dropIfExists('catalog_vlans');
    }
};
