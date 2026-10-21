<?php

// torsade/app/Console/Commands/DemoCommand.php

namespace App\Console\Commands;

use App\Enums\ActivityEvent;
use App\Services\AuditLogger;
use App\Services\Network\DemoData;
use App\Support\Actor;
use Illuminate\Console\Command;

/**
 * Fills an empty installation with a fictitious network, to try the
 * application before documenting a real one. It never runs in production and
 * never mixes its data with existing documentation; the default seeder does
 * not call it.
 */
class DemoCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'torsade:demo';

    /**
     * @var string
     */
    protected $description = 'Create a fictitious network (sites, catalogue, blocks, addressing rule, VLANs, subnets, IP addresses, places, devices, their members, their ports and their links, patch panels, wall jacks and patch cords) to try the application';

    public function handle(DemoData $demo, AuditLogger $audit): int
    {
        if (app()->isProduction()) {
            $this->logExecution($audit, 'refused');

            $this->components->error(__('Demonstration data cannot be created in production.'));

            return self::FAILURE;
        }

        if ($demo->networkDataExists()) {
            $this->logExecution($audit, 'refused');

            $this->components->error(__('Network data already exists: demonstration data is only created in an empty installation.'));

            return self::FAILURE;
        }

        // Each object is written to the activity log as it is created, with
        // the console as its author.
        $counts = $demo->create(Actor::console());

        $this->logExecution($audit, 'success');

        $this->components->info(__('Demonstration data created.'));
        $this->components->twoColumnDetail(__('Sites'), (string) $counts['sites']);
        $this->components->twoColumnDetail(__('Catalogue entries'), (string) $counts['catalog_vlans']);
        $this->components->twoColumnDetail(__('Address blocks'), (string) $counts['address_blocks']);
        $this->components->twoColumnDetail(__('Addressing rule'), (string) $counts['addressing_rules']);
        $this->components->twoColumnDetail(__('VLANs'), (string) $counts['vlans']);
        $this->components->twoColumnDetail(__('Subnets'), (string) $counts['subnets']);
        $this->components->twoColumnDetail(__('Ranges'), (string) $counts['ip_ranges']);
        $this->components->twoColumnDetail(__('IP addresses'), (string) $counts['ip_addresses']);
        $this->components->twoColumnDetail(__('Buildings'), (string) $counts['buildings']);
        $this->components->twoColumnDetail(__('Floors'), (string) $counts['floors']);
        $this->components->twoColumnDetail(__('Rooms'), (string) $counts['rooms']);
        $this->components->twoColumnDetail(__('Devices'), (string) $counts['devices']);
        $this->components->twoColumnDetail(__('Device templates'), (string) $counts['device_templates']);
        $this->components->twoColumnDetail(__('Members'), (string) $counts['device_members']);
        $this->components->twoColumnDetail(__('Ports'), (string) $counts['ports']);
        $this->components->twoColumnDetail(__('Aggregates'), (string) $counts['port_aggregates']);
        $this->components->twoColumnDetail(__('Links'), (string) $counts['links']);
        $this->components->twoColumnDetail(__('Patch panels'), (string) $counts['patch_panels']);
        $this->components->twoColumnDetail(__('Jacks'), (string) $counts['jacks']);
        $this->components->twoColumnDetail(__('Patch cords'), (string) $counts['patch_cords']);
        $this->components->twoColumnDetail(__('Jack cords'), (string) $counts['jack_cords']);
        $this->components->twoColumnDetail(__('Elements placed on a diagram'), (string) $counts['diagram_positions']);

        return self::SUCCESS;
    }

    private function logExecution(AuditLogger $audit, string $outcome): void
    {
        $audit->record(ActivityEvent::CommandExecuted, Actor::console(), properties: [
            'command' => $this->getName(),
            'outcome' => $outcome,
        ]);
    }
}
