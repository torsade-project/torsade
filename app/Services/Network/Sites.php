<?php

// torsade/app/Services/Network/Sites.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Models\Site;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a site goes through this class, whether it comes from the
 * interface or from a command: the rules are enforced here and each change
 * is written to the activity log.
 *
 * @phpstan-type SiteData array{name: string, code?: string|null, number?: int|null, status?: string|null, description?: string|null}
 */
final class Sites
{
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly ChangeLog $log,
        private readonly AddressingRules $rules,
    ) {}

    /**
     * Create a site and, when asked, the block the addressing rule expects
     * for its number, with the status of the site. Both are saved together
     * or not at all: a block that cannot be created leaves no site behind.
     *
     * @param  SiteData  $data
     *
     * @throws ValidationException A refused block is told on "create_block".
     */
    public function create(array $data, Actor $actor, bool $withExpectedBlock = false): Site
    {
        return DB::transaction(function () use ($data, $actor, $withExpectedBlock): Site {
            $site = new Site($this->attributes($data));

            $this->save($site);

            $this->log->created(ActivityEvent::SiteCreated, $site, $actor);

            if ($withExpectedBlock) {
                $this->rules->createExpectedBlock($site, $actor, 'create_block');
            }

            return $site;
        });
    }

    /**
     * Change a site and, when asked, declare the block the addressing rule
     * expects for the number it now has: all of it, or nothing.
     *
     * @param  SiteData  $data
     *
     * @throws ValidationException A refused block is told on "create_block".
     */
    public function update(Site $site, array $data, Actor $actor, bool $withExpectedBlock = false): void
    {
        DB::transaction(function () use ($site, $data, $actor, $withExpectedBlock): void {
            $before = $site->auditValues();

            $site->fill($this->attributes($data));

            $this->save($site);

            $this->log->updated(ActivityEvent::SiteUpdated, $site, $before, $actor);

            if ($withExpectedBlock) {
                $this->rules->createExpectedBlock($site, $actor, 'create_block');
            }
        });
    }

    /**
     * A site is only deleted once empty: nothing disappears with it.
     *
     * @throws ValidationException
     */
    public function delete(Site $site, Actor $actor): void
    {
        DB::transaction(function () use ($site, $actor): void {
            $vlans = $site->vlans()->lockForUpdate()->count();

            if ($vlans > 0) {
                throw ValidationException::withMessages([
                    'site' => trans_choice(
                        'A VLAN still belongs to this site: delete it first.|:count VLANs still belong to this site: delete them first.',
                        $vlans,
                    ),
                ]);
            }

            $subnets = $site->subnets()->lockForUpdate()->count();

            if ($subnets > 0) {
                throw ValidationException::withMessages([
                    'site' => trans_choice(
                        'A subnet still belongs to this site: delete it or change its site first.|:count subnets still belong to this site: delete them or change their site first.',
                        $subnets,
                    ),
                ]);
            }

            $blocks = $site->addressBlocks()->lockForUpdate()->count();

            if ($blocks > 0) {
                throw ValidationException::withMessages([
                    'site' => trans_choice(
                        'An address block still belongs to this site: delete it first.|:count address blocks still belong to this site: delete them first.',
                        $blocks,
                    ),
                ]);
            }

            $buildings = $site->buildings()->lockForUpdate()->count();

            if ($buildings > 0) {
                throw ValidationException::withMessages([
                    'site' => trans_choice(
                        'A building still belongs to this site: delete it first.|:count buildings still belong to this site: delete them first.',
                        $buildings,
                    ),
                ]);
            }

            $devices = $site->devices()->lockForUpdate()->count();

            if ($devices > 0) {
                throw ValidationException::withMessages([
                    'site' => trans_choice(
                        'A device still belongs to this site: delete it or change its site first.|:count devices still belong to this site: delete them or change their site first.',
                        $devices,
                    ),
                ]);
            }

            $this->log->deleted(ActivityEvent::SiteDeleted, $site, $actor);

            $site->delete();
        });
    }

    /**
     * @param  SiteData  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $code = trim((string) ($data['code'] ?? ''));

        return [
            'name' => $data['name'],
            // A code is written in capitals wherever it is used.
            'code' => $code === '' ? null : strtoupper($code),
            'number' => $data['number'] ?? null,
            'description' => $data['description'] ?? null,
            // Left out, the status stays what it is: in service for a new
            // site.
            ...(isset($data['status']) ? ['status' => $data['status']] : []),
        ];
    }

    /**
     * @throws ValidationException
     */
    private function save(Site $site): void
    {
        // The number says which block the site is expected to have: the
        // refusal names the site that already has it.
        if ($site->number !== null) {
            $other = Site::query()->where('number', $site->number)->whereKeyNot($site->getKey())->first();

            if ($other !== null) {
                throw ValidationException::withMessages([
                    'number' => __('The site :site already has the number :number.', ['site' => $other->name, 'number' => $site->number]),
                ]);
            }
        }

        $this->guardingUniqueness(fn (): bool => $site->save(), [
            'sites_name_unique' => ['name', __('Another site already has this name.')],
            'sites_code_unique' => ['code', __('Another site already has this code.')],
            'sites_number_unique' => ['number', __('Another site already has this number.')],
        ]);
    }
}
