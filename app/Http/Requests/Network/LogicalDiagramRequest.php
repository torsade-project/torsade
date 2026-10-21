<?php

// torsade/app/Http/Requests/Network/LogicalDiagramRequest.php

namespace App\Http\Requests\Network;

use App\Models\Subnet;

class LogicalDiagramRequest extends SiteDiagramRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Subnet::class);
    }
}
