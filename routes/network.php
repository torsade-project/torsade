<?php

// torsade/routes/network.php

use App\Http\Controllers\Network\AddressBlockController;
use App\Http\Controllers\Network\AddressingRuleController;
use App\Http\Controllers\Network\BuildingController;
use App\Http\Controllers\Network\CatalogVlanController;
use App\Http\Controllers\Network\DeviceAddressController;
use App\Http\Controllers\Network\DeviceController;
use App\Http\Controllers\Network\DeviceMemberController;
use App\Http\Controllers\Network\DeviceTemplateController;
use App\Http\Controllers\Network\DiagramLayoutController;
use App\Http\Controllers\Network\FloorController;
use App\Http\Controllers\Network\HostnameDeviceController;
use App\Http\Controllers\Network\IpAddressController;
use App\Http\Controllers\Network\IpRangeController;
use App\Http\Controllers\Network\JackController;
use App\Http\Controllers\Network\JackCordController;
use App\Http\Controllers\Network\LinkController;
use App\Http\Controllers\Network\LogicalDiagramController;
use App\Http\Controllers\Network\PatchCordController;
use App\Http\Controllers\Network\PatchPanelController;
use App\Http\Controllers\Network\PhysicalDiagramController;
use App\Http\Controllers\Network\PortAggregateController;
use App\Http\Controllers\Network\PortController;
use App\Http\Controllers\Network\RoomController;
use App\Http\Controllers\Network\SiteController;
use App\Http\Controllers\Network\SiteStandardController;
use App\Http\Controllers\Network\SubnetController;
use App\Http\Controllers\Network\VlanController;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Building;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\DiagramPosition;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Jack;
use App\Models\JackCord;
use App\Models\Link;
use App\Models\PatchCord;
use App\Models\PatchPanel;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Support\Facades\Route;

// The network documentation. Every route names the policy ability it needs:
// reading is open to any active account, writing to editors and
// administrators.
Route::middleware(['auth'])->group(function () {
    Route::get('sites', [SiteController::class, 'index'])->can('viewAny', Site::class)->name('sites.index');
    Route::get('sites/{site}', [SiteController::class, 'show'])->can('view', 'site')->name('sites.show');
    Route::post('sites', [SiteController::class, 'store'])->can('create', Site::class)->name('sites.store');
    Route::put('sites/{site}', [SiteController::class, 'update'])->can('update', 'site')->name('sites.update');
    Route::delete('sites/{site}', [SiteController::class, 'destroy'])->can('delete', 'site')->name('sites.destroy');
    // Declaring the block the addressing rule expects for a site creates a
    // block: it asks for the right to create one.
    Route::post('sites/{site}/expected-block', [SiteController::class, 'storeExpectedBlock'])->can('create', AddressBlock::class)->name('sites.expected-block.store');

    // Applying the standard of the organisation to a site creates VLANs
    // there, with their subnets: both routes ask for the right to create a
    // VLAN, the page that leads to the action included.
    Route::get('sites/{site}/standard', [SiteStandardController::class, 'create'])->can('create', Vlan::class)->name('sites.standard.create');
    Route::post('sites/{site}/standard', [SiteStandardController::class, 'store'])->can('create', Vlan::class)->name('sites.standard.store');

    // The addressing rule of the organisation. There is one at most: it
    // has one address, and saving it creates it or changes it.
    Route::get('addressing-rule', [AddressingRuleController::class, 'show'])->can('viewAny', AddressingRule::class)->name('addressing-rule.show');
    Route::put('addressing-rule', [AddressingRuleController::class, 'update'])->can('create', AddressingRule::class)->name('addressing-rule.update');
    Route::delete('addressing-rule', [AddressingRuleController::class, 'destroy'])->can('delete', AddressingRule::class)->name('addressing-rule.destroy');

    // The VLAN catalogue of the organisation. Attaching VLANs to an entry
    // changes those VLANs on behalf of the entry: it asks for the right to
    // update it.
    Route::get('catalog-vlans', [CatalogVlanController::class, 'index'])->can('viewAny', CatalogVlan::class)->name('catalog-vlans.index');
    Route::post('catalog-vlans', [CatalogVlanController::class, 'store'])->can('create', CatalogVlan::class)->name('catalog-vlans.store');
    Route::get('catalog-vlans/{catalogVlan}', [CatalogVlanController::class, 'show'])->can('view', 'catalogVlan')->name('catalog-vlans.show');
    Route::put('catalog-vlans/{catalogVlan}', [CatalogVlanController::class, 'update'])->can('update', 'catalogVlan')->name('catalog-vlans.update');
    Route::delete('catalog-vlans/{catalogVlan}', [CatalogVlanController::class, 'destroy'])->can('delete', 'catalogVlan')->name('catalog-vlans.destroy');
    Route::post('catalog-vlans/{catalogVlan}/vlans', [CatalogVlanController::class, 'attach'])->can('update', 'catalogVlan')->name('catalog-vlans.attach');

    Route::get('vlans', [VlanController::class, 'index'])->can('viewAny', Vlan::class)->name('vlans.index');
    Route::get('vlans/create', [VlanController::class, 'create'])->can('create', Vlan::class)->name('vlans.create');
    Route::post('vlans', [VlanController::class, 'store'])->can('create', Vlan::class)->name('vlans.store');
    Route::get('vlans/{vlan}', [VlanController::class, 'show'])->can('view', 'vlan')->name('vlans.show');
    Route::get('vlans/{vlan}/edit', [VlanController::class, 'edit'])->can('update', 'vlan')->name('vlans.edit');
    Route::put('vlans/{vlan}', [VlanController::class, 'update'])->can('update', 'vlan')->name('vlans.update');
    Route::delete('vlans/{vlan}', [VlanController::class, 'destroy'])->can('delete', 'vlan')->name('vlans.destroy');

    Route::get('address-blocks', [AddressBlockController::class, 'index'])->can('viewAny', AddressBlock::class)->name('address-blocks.index');
    Route::get('address-blocks/create', [AddressBlockController::class, 'create'])->can('create', AddressBlock::class)->name('address-blocks.create');
    Route::post('address-blocks', [AddressBlockController::class, 'store'])->can('create', AddressBlock::class)->name('address-blocks.store');
    Route::get('address-blocks/{addressBlock}', [AddressBlockController::class, 'show'])->can('view', 'addressBlock')->name('address-blocks.show');
    Route::get('address-blocks/{addressBlock}/edit', [AddressBlockController::class, 'edit'])->can('update', 'addressBlock')->name('address-blocks.edit');
    Route::put('address-blocks/{addressBlock}', [AddressBlockController::class, 'update'])->can('update', 'addressBlock')->name('address-blocks.update');
    Route::delete('address-blocks/{addressBlock}', [AddressBlockController::class, 'destroy'])->can('delete', 'addressBlock')->name('address-blocks.destroy');

    Route::get('subnets', [SubnetController::class, 'index'])->can('viewAny', Subnet::class)->name('subnets.index');
    Route::get('subnets/create', [SubnetController::class, 'create'])->can('create', Subnet::class)->name('subnets.create');
    Route::post('subnets', [SubnetController::class, 'store'])->can('create', Subnet::class)->name('subnets.store');
    Route::get('subnets/{subnet}', [SubnetController::class, 'show'])->can('view', 'subnet')->name('subnets.show');
    Route::get('subnets/{subnet}/edit', [SubnetController::class, 'edit'])->can('update', 'subnet')->name('subnets.edit');
    Route::put('subnets/{subnet}', [SubnetController::class, 'update'])->can('update', 'subnet')->name('subnets.update');
    Route::delete('subnets/{subnet}', [SubnetController::class, 'destroy'])->can('delete', 'subnet')->name('subnets.destroy');

    // DHCP ranges and IP addresses are read on the page of their subnet:
    // they only have routes to write them.
    Route::post('subnets/{subnet}/ip-ranges', [IpRangeController::class, 'store'])
        ->can('create', IpRange::class)
        ->name('ip-ranges.store');
    // The ranges of the addressing rule, created in a subnet at once.
    Route::post('subnets/{subnet}/breakdown', [IpRangeController::class, 'storeBreakdown'])
        ->can('create', IpRange::class)
        ->name('subnets.breakdown.store');
    Route::put('ip-ranges/{ipRange}', [IpRangeController::class, 'update'])
        ->can('update', 'ipRange')
        ->name('ip-ranges.update');
    Route::delete('ip-ranges/{ipRange}', [IpRangeController::class, 'destroy'])
        ->can('delete', 'ipRange')
        ->name('ip-ranges.destroy');

    Route::post('subnets/{subnet}/ip-addresses', [IpAddressController::class, 'store'])
        ->can('create', IpAddress::class)
        ->name('ip-addresses.store');
    Route::post('subnets/{subnet}/ip-addresses/bulk', [IpAddressController::class, 'bulk'])
        ->can('create', IpAddress::class)
        ->name('ip-addresses.bulk');
    Route::put('ip-addresses/{ipAddress}', [IpAddressController::class, 'update'])
        ->can('update', 'ipAddress')
        ->name('ip-addresses.update');
    Route::delete('ip-addresses/{ipAddress}', [IpAddressController::class, 'destroy'])
        ->can('delete', 'ipAddress')
        ->name('ip-addresses.destroy');

    // Buildings, floors and rooms are read on the page of their site: they
    // only have routes to write them.
    Route::post('sites/{site}/buildings', [BuildingController::class, 'store'])->can('create', Building::class)->name('buildings.store');
    Route::put('buildings/{building}', [BuildingController::class, 'update'])->can('update', 'building')->name('buildings.update');
    Route::delete('buildings/{building}', [BuildingController::class, 'destroy'])->can('delete', 'building')->name('buildings.destroy');

    Route::post('buildings/{building}/floors', [FloorController::class, 'store'])->can('create', Floor::class)->name('floors.store');
    Route::put('floors/{floor}', [FloorController::class, 'update'])->can('update', 'floor')->name('floors.update');
    Route::delete('floors/{floor}', [FloorController::class, 'destroy'])->can('delete', 'floor')->name('floors.destroy');

    Route::post('floors/{floor}/rooms', [RoomController::class, 'store'])->can('create', Room::class)->name('rooms.store');
    Route::put('rooms/{room}', [RoomController::class, 'update'])->can('update', 'room')->name('rooms.update');
    Route::delete('rooms/{room}', [RoomController::class, 'destroy'])->can('delete', 'room')->name('rooms.destroy');

    // The patch panels, created in the room they are kept in, from the page
    // of its site, and read on a page of their own.
    Route::post('rooms/{room}/patch-panels', [PatchPanelController::class, 'store'])->can('create', PatchPanel::class)->name('patch-panels.store');
    Route::get('patch-panels/{patchPanel}', [PatchPanelController::class, 'show'])->can('view', 'patchPanel')->name('patch-panels.show');
    Route::put('patch-panels/{patchPanel}', [PatchPanelController::class, 'update'])->can('update', 'patchPanel')->name('patch-panels.update');
    Route::delete('patch-panels/{patchPanel}', [PatchPanelController::class, 'destroy'])->can('delete', 'patchPanel')->name('patch-panels.destroy');

    // The wall jacks, listed on a page of their own and created on their
    // floor from the page of their site, one by one or as a series. A jack
    // is cabled to a port of a panel in its window, or from the page of the
    // panel: cabling and detaching it there change the jack.
    Route::get('jacks', [JackController::class, 'index'])->can('viewAny', Jack::class)->name('jacks.index');
    Route::post('floors/{floor}/jacks', [JackController::class, 'store'])->can('create', Jack::class)->name('jacks.store');
    Route::post('floors/{floor}/jacks/series', [JackController::class, 'storeSeries'])->can('create', Jack::class)->name('jacks.series.store');
    Route::put('jacks/{jack}', [JackController::class, 'update'])->can('update', 'jack')->name('jacks.update');
    Route::delete('jacks/{jack}', [JackController::class, 'destroy'])->can('delete', 'jack')->name('jacks.destroy');
    Route::put('patch-panel-ports/{patchPanelPort}/jack', [JackController::class, 'attach'])->can('update', Jack::class)->name('patch-panel-ports.jack.update');
    Route::delete('patch-panel-ports/{patchPanelPort}/jack', [JackController::class, 'detach'])->can('update', Jack::class)->name('patch-panel-ports.jack.destroy');

    // The patch cords, laid from the page of a panel, one or several
    // consecutive ports at a time, changed, and taken away, one or several
    // ports at a time.
    Route::post('patch-panels/{patchPanel}/cords', [PatchCordController::class, 'store'])->can('create', PatchCord::class)->name('patch-cords.store');
    Route::delete('patch-panels/{patchPanel}/cords', [PatchCordController::class, 'destroyMany'])->can('delete', PatchCord::class)->name('patch-cords.destroy-many');
    // A cord changed in its window is taken by its identifier: one
    // unpatched in the meantime is told in words rather than as a page not
    // found.
    Route::put('patch-cords/{patchCord}', [PatchCordController::class, 'update'])->whereNumber('patchCord')->can('update', PatchCord::class)->name('patch-cords.update');
    Route::delete('patch-cords/{patchCord}', [PatchCordController::class, 'destroy'])->can('delete', 'patchCord')->name('patch-cords.destroy');
    // Every port of a device unpatched at once, from the page of the device.
    Route::delete('devices/{device}/patch-cords', [PatchCordController::class, 'destroyAllOf'])->can('delete', PatchCord::class)->name('devices.patch-cords.destroy-all');

    // The jack cords: a port of a device plugged into a wall jack, from the
    // line of the port, from the jack or from a deviation; changed, and
    // taken away. A cord is taken by its identifier: one unplugged in the
    // meantime is told in words rather than as a page not found.
    Route::post('jack-cords', [JackCordController::class, 'store'])->can('create', JackCord::class)->name('jack-cords.store');
    Route::put('jack-cords/{jackCord}', [JackCordController::class, 'update'])->whereNumber('jackCord')->can('update', JackCord::class)->name('jack-cords.update');
    Route::delete('jack-cords/{jackCord}', [JackCordController::class, 'destroy'])->whereNumber('jackCord')->can('delete', JackCord::class)->name('jack-cords.destroy');
    // Every port of a device unplugged at once, from the page of the device.
    Route::delete('devices/{device}/jack-cords', [JackCordController::class, 'destroyAllOf'])->can('delete', JackCord::class)->name('devices.jack-cords.destroy-all');

    // Creating the devices of a site from the host names of its addresses
    // creates devices: both routes ask for the right to create one, the
    // page that leads to the action included.
    Route::get('sites/{site}/hostname-devices', [HostnameDeviceController::class, 'create'])->can('create', Device::class)->name('sites.hostname-devices.create');
    Route::post('sites/{site}/hostname-devices', [HostnameDeviceController::class, 'store'])->can('create', Device::class)->name('sites.hostname-devices.store');

    // What devices are made of. Adding the generic templates creates
    // templates: it asks for the right to create one.
    Route::get('device-templates', [DeviceTemplateController::class, 'index'])->can('viewAny', DeviceTemplate::class)->name('device-templates.index');
    Route::get('device-templates/create', [DeviceTemplateController::class, 'create'])->can('create', DeviceTemplate::class)->name('device-templates.create');
    Route::post('device-templates', [DeviceTemplateController::class, 'store'])->can('create', DeviceTemplate::class)->name('device-templates.store');
    Route::post('device-templates/generics', [DeviceTemplateController::class, 'storeGenerics'])->can('create', DeviceTemplate::class)->name('device-templates.generics.store');
    Route::get('device-templates/{deviceTemplate}/edit', [DeviceTemplateController::class, 'edit'])->can('update', 'deviceTemplate')->name('device-templates.edit');
    Route::put('device-templates/{deviceTemplate}', [DeviceTemplateController::class, 'update'])->can('update', 'deviceTemplate')->name('device-templates.update');
    Route::delete('device-templates/{deviceTemplate}', [DeviceTemplateController::class, 'destroy'])->can('delete', 'deviceTemplate')->name('device-templates.destroy');

    Route::get('devices', [DeviceController::class, 'index'])->can('viewAny', Device::class)->name('devices.index');
    Route::get('devices/create', [DeviceController::class, 'create'])->can('create', Device::class)->name('devices.create');
    Route::post('devices', [DeviceController::class, 'store'])->can('create', Device::class)->name('devices.store');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->can('view', 'device')->name('devices.show');
    Route::get('devices/{device}/edit', [DeviceController::class, 'edit'])->can('update', 'device')->name('devices.edit');
    Route::put('devices/{device}', [DeviceController::class, 'update'])->can('update', 'device')->name('devices.update');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->can('delete', 'device')->name('devices.destroy');

    // The members of a device are read on its page: they only have
    // routes to write them. Choosing a template for a device that has no
    // member creates its first one.
    Route::post('devices/{device}/template', [DeviceMemberController::class, 'storeFirst'])->can('create', DeviceMember::class)->name('devices.template.store');
    Route::post('devices/{device}/members', [DeviceMemberController::class, 'store'])->can('create', DeviceMember::class)->name('device-members.store');
    Route::put('device-members/{deviceMember}', [DeviceMemberController::class, 'update'])->can('update', 'deviceMember')->name('device-members.update');
    Route::delete('device-members/{deviceMember}', [DeviceMemberController::class, 'destroy'])->can('delete', 'deviceMember')->name('device-members.destroy');

    // The ports of a device are read on its page too. A series of ports
    // is added to one of its members; the ports ticked in its table are
    // changed or deleted together.
    Route::post('devices/{device}/ports', [PortController::class, 'store'])->can('create', Port::class)->name('ports.store');
    Route::patch('devices/{device}/ports', [PortController::class, 'updateMany'])->can('update', Port::class)->name('ports.update-many');
    Route::delete('devices/{device}/ports', [PortController::class, 'destroyMany'])->can('delete', Port::class)->name('ports.destroy-many');
    Route::put('ports/{port}', [PortController::class, 'update'])->can('update', 'port')->name('ports.update');
    Route::delete('ports/{port}', [PortController::class, 'destroy'])->can('delete', 'port')->name('ports.destroy');

    // The aggregates of ports of a device are read on its page as well.
    Route::post('devices/{device}/aggregates', [PortAggregateController::class, 'store'])->can('create', PortAggregate::class)->name('port-aggregates.store');
    Route::put('port-aggregates/{portAggregate}', [PortAggregateController::class, 'update'])->can('update', 'portAggregate')->name('port-aggregates.update');
    Route::delete('port-aggregates/{portAggregate}', [PortAggregateController::class, 'destroy'])->can('delete', 'portAggregate')->name('port-aggregates.destroy');

    // The links between the ports of two devices. A link, or a series of
    // links, is declared from the page of a device, on its ports.
    Route::get('links', [LinkController::class, 'index'])->can('viewAny', Link::class)->name('links.index');
    // The physical diagram of a site, worked out from its devices and their
    // links: a page that reads, like the lists.
    Route::get('diagrams/physical', PhysicalDiagramController::class)->can('viewAny', Device::class)->name('diagrams.physical');
    // Its layout, placed by hand: saved all at once, or given back to the
    // automatic layout.
    Route::put('sites/{site}/diagrams/physical/layout', [DiagramLayoutController::class, 'update'])->can('update', DiagramPosition::class)->name('sites.diagrams.physical.layout.update');
    Route::delete('sites/{site}/diagrams/physical/layout', [DiagramLayoutController::class, 'destroy'])->can('delete', DiagramPosition::class)->name('sites.diagrams.physical.layout.destroy');
    // The logical diagram of a site, worked out from its subnets, their
    // VLANs and the addresses of its devices; its layout, as above.
    Route::get('diagrams/logical', LogicalDiagramController::class)->can('viewAny', Subnet::class)->name('diagrams.logical');
    Route::put('sites/{site}/diagrams/logical/layout', [DiagramLayoutController::class, 'updateLogical'])->can('update', DiagramPosition::class)->name('sites.diagrams.logical.layout.update');
    Route::delete('sites/{site}/diagrams/logical/layout', [DiagramLayoutController::class, 'destroyLogical'])->can('delete', DiagramPosition::class)->name('sites.diagrams.logical.layout.destroy');
    Route::post('devices/{device}/links', [LinkController::class, 'store'])->can('create', Link::class)->name('links.store');
    Route::put('links/{link}', [LinkController::class, 'update'])->can('update', 'link')->name('links.update');
    Route::delete('links/{link}', [LinkController::class, 'destroy'])->can('delete', 'link')->name('links.destroy');

    // Attaching an address to a device, or detaching it, changes the
    // interfaces of the device: it asks for the right to update it.
    Route::post('devices/{device}/ip-addresses', [DeviceAddressController::class, 'store'])
        ->can('update', 'device')
        ->name('devices.ip-addresses.store');
    Route::delete('devices/{device}/ip-addresses', [DeviceAddressController::class, 'destroyAll'])
        ->can('update', 'device')
        ->name('devices.ip-addresses.destroy-all');
    Route::delete('devices/{device}/ip-addresses/{ipAddress}', [DeviceAddressController::class, 'destroy'])
        ->can('update', 'device')
        ->name('devices.ip-addresses.destroy');
});
