<?php

// torsade/app/Http/Controllers/Network/DeviceTemplateController.php

namespace App\Http\Controllers\Network;

use App\Enums\DeviceType;
use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortSpeed;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\DeviceTemplateRequest;
use App\Models\DeviceTemplate;
use App\Services\Network\DeviceTemplates;
use App\Support\Actor;
use App\Support\PortNaming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Device templates. Each route of this controller is behind the policy of
 * templates (see routes/network.php): reading for every active account,
 * writing for editors and administrators.
 */
class DeviceTemplateController extends Controller
{
    /**
     * The templates, by name, each with its ports and how many members of
     * devices were made from it.
     *
     * Three queries, whatever the number of templates.
     */
    public function index(DeviceTemplates $templates): Response
    {
        $existing = DeviceTemplate::query()
            ->with('portGroups')
            ->withCount('members')
            ->orderBy('name')
            ->get();

        $names = $existing->map(fn (DeviceTemplate $template): string => mb_strtolower($template->name))->all();

        return Inertia::render('device-templates/Index', [
            'templates' => $existing->map(fn (DeviceTemplate $template): array => NetworkPresenter::deviceTemplate($template)),
            // How many generic templates are not there yet: the action that
            // adds them says so, and is not offered for nothing.
            'missingGenerics' => count(array_filter(
                $templates->generics(),
                fn (array $generic): bool => ! in_array(mb_strtolower($generic['name']), $names, true),
            )),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('device-templates/Form', [
            'template' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(DeviceTemplateRequest $request, DeviceTemplates $templates): RedirectResponse
    {
        $templates->create($request->templateData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template created.')]);

        return to_route('device-templates.index');
    }

    /**
     * Create the generic templates that are missing, and only those.
     */
    public function storeGenerics(Request $request, DeviceTemplates $templates): RedirectResponse
    {
        $created = $templates->addGenerics(Actor::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $created === 0
                ? __('The generic templates are all there already.')
                : trans_choice(':count generic template added.|:count generic templates added.', $created),
        ]);

        return to_route('device-templates.index');
    }

    public function edit(DeviceTemplate $deviceTemplate): Response
    {
        $deviceTemplate->load('portGroups')->loadCount('members');

        return Inertia::render('device-templates/Form', [
            'template' => NetworkPresenter::deviceTemplate($deviceTemplate),
            ...$this->formOptions(),
        ]);
    }

    public function update(DeviceTemplateRequest $request, DeviceTemplate $deviceTemplate, DeviceTemplates $templates): RedirectResponse
    {
        $templates->update($deviceTemplate, $request->templateData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template updated.')]);

        return to_route('device-templates.index');
    }

    public function destroy(Request $request, DeviceTemplate $deviceTemplate, DeviceTemplates $templates): RedirectResponse
    {
        $templates->delete($deviceTemplate, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template deleted.')]);

        return to_route('device-templates.index');
    }

    /**
     * What the form of a template chooses among, and the limits it shows.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => DeviceType::options(),
            'connectors' => PortConnector::options(),
            'speeds' => PortSpeed::options(),
            'poeTypes' => PoeType::options(),
            'limits' => [
                'groups' => DeviceTemplate::MAX_GROUPS,
                'quantity' => PortNaming::MAX_QUANTITY,
                'first_number' => PortNaming::MAX_FIRST_NUMBER,
                'pattern' => PortNaming::MAX_NAME_LENGTH,
            ],
        ];
    }
}
