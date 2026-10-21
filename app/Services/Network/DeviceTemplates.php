<?php

// torsade/app/Services/Network/DeviceTemplates.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortSpeed;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Rules\PortNamePattern;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use App\Support\PortNaming;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a device template goes through this class, whether it
 * comes from the interface or from a command: the rules are enforced here
 * and each change is written to the activity log.
 *
 * A template and its groups of ports are saved together and checked
 * together. Changing a template changes no device: the ports of a member
 * were copied from it when the member was added.
 *
 * @phpstan-type PortGroupData array{quantity: int, name_pattern: string, first_number: int, connector: string, max_speed: string, poe_type: string, poe_label?: string|null}
 * @phpstan-type TemplateData array{name: string, device_type: string, manufacturer?: string|null, model?: string|null, poe_budget_watts?: int|null, description?: string|null, port_groups: array<int, PortGroupData>}
 */
final class DeviceTemplates
{
    use TranslatesUniqueViolations;

    public function __construct(private readonly ChangeLog $log) {}

    /**
     * @param  TemplateData  $data
     *
     * @throws ValidationException Messages are keyed by field, "port_groups.<line>.<field>" for a group.
     */
    public function create(array $data, Actor $actor): DeviceTemplate
    {
        return DB::transaction(function () use ($data, $actor): DeviceTemplate {
            $template = new DeviceTemplate;

            $this->write($template, $data);

            $this->log->created(ActivityEvent::DeviceTemplateCreated, $template, $actor);

            return $template;
        });
    }

    /**
     * Change a template and its groups of ports. The members of devices
     * made from it keep the ports they have.
     *
     * @param  TemplateData  $data
     *
     * @throws ValidationException
     */
    public function update(DeviceTemplate $template, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($template, $data, $actor): void {
            $template->load('portGroups');

            $before = $template->auditValues();

            $this->write($template, $data);

            $this->log->updated(ActivityEvent::DeviceTemplateUpdated, $template, $before, $actor);
        });
    }

    /**
     * A template is only deleted once no member of a device names it: a
     * member never loses its template without anyone deciding so.
     *
     * @throws ValidationException
     */
    public function delete(DeviceTemplate $template, Actor $actor): void
    {
        DB::transaction(function () use ($template, $actor): void {
            $members = $template->members()->lockForUpdate()->count();

            if ($members > 0) {
                throw ValidationException::withMessages([
                    'device_template' => trans_choice(
                        'A member of a device still uses this template: take the template away from it first.|:count members of devices still use this template: take the template away from them first.',
                        $members,
                    ),
                ]);
            }

            $template->load('portGroups');

            $this->log->deleted(ActivityEvent::DeviceTemplateDeleted, $template, $actor);

            // Its groups go with it, by the key that ties them.
            $template->delete();
        });
    }

    /**
     * Create the generic templates that are not there yet, found by their
     * name. Those already there are left as they are, whatever was changed
     * in them.
     *
     * @return int How many templates were created.
     */
    public function addGenerics(Actor $actor): int
    {
        return DB::transaction(function () use ($actor): int {
            $created = 0;

            foreach ($this->generics() as $generic) {
                if (DeviceTemplate::query()->where('name', $generic['name'])->exists()) {
                    continue;
                }

                $this->create($generic, $actor);
                $created++;
            }

            return $created;
        });
    }

    /**
     * The generic templates, without a brand: the usual shapes of a small
     * network, to start from.
     *
     * @return list<TemplateData>
     */
    public function generics(): array
    {
        $copper = fn (int $quantity, string $pattern, PoeType $poe = PoeType::None, int $first = 1): array => [
            'quantity' => $quantity,
            'name_pattern' => $pattern,
            'first_number' => $first,
            'connector' => PortConnector::Rj45->value,
            'max_speed' => PortSpeed::Gbit1->value,
            'poe_type' => $poe->value,
            'poe_label' => null,
        ];

        $uplinks = [
            'quantity' => 4,
            'name_pattern' => '{m}/1/{n}',
            'first_number' => 1,
            'connector' => PortConnector::SfpPlus->value,
            'max_speed' => PortSpeed::Gbit10->value,
            'poe_type' => PoeType::None->value,
            'poe_label' => null,
        ];

        $description = __('Generic template, without a brand.');

        return [
            [
                'name' => __('Switch, 24 PoE+ ports and 4 SFP+'),
                'device_type' => DeviceType::Switch->value,
                'description' => $description,
                'port_groups' => [$copper(24, '{m}/0/{n}', PoeType::At), $uplinks],
            ],
            [
                'name' => __('Switch, 48 PoE+ ports and 4 SFP+'),
                'device_type' => DeviceType::Switch->value,
                'description' => $description,
                'port_groups' => [$copper(48, '{m}/0/{n}', PoeType::At), $uplinks],
            ],
            [
                'name' => __('Switch, 8 ports'),
                'device_type' => DeviceType::Switch->value,
                'description' => $description,
                'port_groups' => [$copper(8, '{n}')],
            ],
            [
                'name' => __('Firewall, 8 ports'),
                'device_type' => DeviceType::Firewall->value,
                'description' => $description,
                'port_groups' => [$copper(8, 'port{n}')],
            ],
            [
                'name' => __('Server, 2 ports'),
                'device_type' => DeviceType::Server->value,
                'description' => $description,
                // Numbered from 0, as servers number their interfaces.
                'port_groups' => [$copper(2, 'eth{n}', first: 0)],
            ],
        ];
    }

    /**
     * Check the groups together, then save the template and replace its
     * groups.
     *
     * @param  TemplateData  $data
     *
     * @throws ValidationException
     */
    private function write(DeviceTemplate $template, array $data): void
    {
        $groups = array_values($data['port_groups']);

        $errors = $this->problemsOf($groups);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $template->fill([
            'name' => $data['name'],
            'device_type' => $data['device_type'],
            'manufacturer' => $this->blankToNull($data['manufacturer'] ?? null),
            'model' => $this->blankToNull($data['model'] ?? null),
            'poe_budget_watts' => $data['poe_budget_watts'] ?? null,
            'description' => $this->blankToNull($data['description'] ?? null),
        ]);

        $this->guardingUniqueness(fn (): bool => $template->save(), [
            'device_templates_name_unique' => ['name', __('Another template already has this name.')],
        ]);

        // The groups have no life of their own: they are written again, in
        // the order they were given.
        $template->portGroups()->delete();

        foreach ($groups as $place => $group) {
            $template->portGroups()->create([
                'position' => $place + 1,
                'quantity' => $group['quantity'],
                'name_pattern' => $group['name_pattern'],
                'first_number' => $group['first_number'],
                'connector' => $group['connector'],
                'max_speed' => $group['max_speed'],
                'poe_type' => $group['poe_type'],
                'poe_label' => $this->blankToNull($group['poe_label'] ?? null),
            ]);
        }

        $template->load('portGroups');
    }

    /**
     * What is wrong with the groups, each message on its line: a pattern
     * that cannot name ports, names too long for a port, and two groups
     * that would give the same name on the member 1.
     *
     * @param  list<PortGroupData>  $groups
     * @return array<string, string>
     */
    private function problemsOf(array $groups): array
    {
        $errors = [];

        foreach ($groups as $place => $group) {
            $message = PortNamePattern::message(PortNaming::problem($group['name_pattern']));

            // The longest name the pattern can give: the highest number of
            // a member, the last port of the group.
            $longest = PortNaming::name($group['name_pattern'], DeviceMember::MAX_POSITION, $group['first_number'] + $group['quantity'] - 1);

            if ($message === null && strlen($longest) > PortNaming::MAX_NAME_LENGTH) {
                $message = trans_choice(
                    'The names this pattern gives are longer than :count character.|The names this pattern gives are longer than :count characters.',
                    PortNaming::MAX_NAME_LENGTH,
                );
            }

            if ($message !== null) {
                $errors['port_groups.'.$place.'.name_pattern'] = $message;
            }
        }

        // Names are only compared between groups that can all name ports.
        if ($errors === []) {
            foreach (PortNaming::clashes($groups) as $place => $clash) {
                $errors['port_groups.'.$place.'.name_pattern'] = __('This group gives the name :name, which the group :number already gives.', [
                    'name' => $clash['name'],
                    'number' => $clash['with'] + 1,
                ]);
            }
        }

        return $errors;
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
