<?php

// torsade/app/Services/Network/SiteStandard.php

namespace App\Services\Network;

use App\Models\CatalogVlan;
use App\Models\Site;
use App\Models\Vlan;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The standard of the organisation applied to a site in one action: the
 * entries of the catalogue the site does not follow yet become VLANs of
 * the site, each with its subnet and its ranges when asked.
 *
 * Nothing is decided here that the services of VLANs, subnets and ranges
 * do not decide: each line goes through them like a VLAN created by hand,
 * and each object is logged by them. All the lines are created, or none.
 *
 * @phpstan-type StandardLine array{catalog_vlan_id: int, vid: int, name: string, subnet: array{network: string, prefix_length: int, gateway: string|null}|null, apply_breakdown: bool}
 */
final class SiteStandard
{
    /**
     * How many entries can be applied at once: far above what a catalogue
     * holds.
     */
    public const int MAX_LINES = 200;

    public function __construct(private readonly Vlans $vlans) {}

    /**
     * The entries of the catalogue that no VLAN of the site follows yet,
     * by number: what there is to apply.
     *
     * @return Collection<int, CatalogVlan>
     */
    public function entriesFor(Site $site): Collection
    {
        return CatalogVlan::query()
            ->whereDoesntHave('vlans', fn (Builder $query) => $query->where('site_id', $site->id))
            ->orderBy('vid')
            ->get();
    }

    /**
     * Create a VLAN of the site for each line, attached to its entry, with
     * its subnet and its ranges when the line asks for them.
     *
     * @param  array<int, StandardLine>  $lines  The entries chosen, by line.
     * @return array{vlans: int, subnets: int} What was created.
     *
     * @throws ValidationException Messages are keyed "lines.<line>.<field>".
     */
    public function apply(Site $site, string $status, array $lines, Actor $actor): array
    {
        return DB::transaction(function () use ($site, $status, $lines, $actor): array {
            // The site is locked: two people applying the standard to it
            // at the same time do not both pass.
            Site::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();

            $errors = [];
            $created = ['vlans' => 0, 'subnets' => 0];

            foreach ($lines as $line => $data) {
                try {
                    $this->createLine($site, $status, $data, $actor);

                    $created['vlans']++;
                    $created['subnets'] += $data['subnet'] === null ? 0 : 1;
                } catch (ValidationException $exception) {
                    // The line is undone and the others are still tried:
                    // every refusal is told at once, each on its line.
                    foreach ($exception->errors() as $field => $messages) {
                        $errors['lines.'.$line.'.'.preg_replace('/^subnet\./', '', $field)] = $messages[0];
                    }
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return $created;
        });
    }

    /**
     * @param  StandardLine  $data
     *
     * @throws ValidationException Keyed as the form of a VLAN keys them.
     */
    private function createLine(Site $site, string $status, array $data, Actor $actor): void
    {
        $entry = CatalogVlan::query()->find($data['catalog_vlan_id']);

        if ($entry === null) {
            throw ValidationException::withMessages(['catalog_vlan_id' => __('This entry of the catalogue no longer exists.')]);
        }

        // A VLAN of the site that already bears the number and follows no
        // entry is most often the very VLAN the entry describes: creating
        // a second one is not what is wanted.
        $existing = Vlan::query()
            ->where('site_id', $site->id)
            ->where('vid', $data['vid'])
            ->whereNull('catalog_vlan_id')
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'vid' => __('The VLAN :vid ":name" already exists on this site, outside the catalogue: attach it from its page.', [
                    'vid' => $existing->vid,
                    'name' => $existing->name,
                ]),
            ]);
        }

        $this->vlans->create(
            [
                'site_id' => $site->id,
                'catalog_vlan_id' => $entry->id,
                'vid' => $data['vid'],
                'name' => $data['name'],
                'status' => $status,
            ],
            $data['subnet'],
            $actor,
            $data['subnet'] !== null && $data['apply_breakdown'],
        );
    }
}
