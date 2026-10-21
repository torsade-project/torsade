<?php

// torsade/app/Http/Requests/Network/PhysicalDiagramRequest.php

namespace App\Http\Requests\Network;

use App\Models\Device;

class PhysicalDiagramRequest extends SiteDiagramRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Device::class);
    }
}
