<?php

// torsade/tests/Unit/EnumLabelsTest.php

namespace Tests\Unit;

use App\Enums\ActivityEvent;
use App\Enums\AggregateProtocol;
use App\Enums\DeviceType;
use App\Enums\GatewayPosition;
use App\Enums\IpAssignment;
use App\Enums\LinkMedium;
use App\Enums\MemberRole;
use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortMode;
use App\Enums\PortSpeed;
use App\Enums\Role;
use App\Enums\Status;
use Tests\TestCase;

/**
 * Enum labels are translation keys resolved at runtime: the scan of string
 * literals in TranslationTest cannot see them, so they are checked here.
 */
class EnumLabelsTest extends TestCase
{
    public function test_the_roles_are_reader_editor_and_admin(): void
    {
        $this->assertSame(['reader', 'editor', 'admin'], Role::values());
    }

    public function test_every_role_has_a_french_label(): void
    {
        $this->assertSame('Lecteur', __(Role::Reader->label()));
        $this->assertSame('Éditeur', __(Role::Editor->label()));
        $this->assertSame('Administrateur', __(Role::Admin->label()));
    }

    public function test_every_activity_event_has_a_french_label(): void
    {
        $catalogue = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach (ActivityEvent::cases() as $event) {
            $this->assertArrayHasKey($event->label(), $catalogue, "[{$event->value}] has no French label.");
        }
    }

    public function test_every_position_of_the_gateway_has_a_french_label(): void
    {
        $this->assertSame(['first', 'last', 'none'], array_column(GatewayPosition::cases(), 'value'));

        $this->assertSame(
            [
                ['value' => 'first', 'label' => 'Première adresse utilisable'],
                ['value' => 'last', 'label' => 'Dernière adresse utilisable'],
                ['value' => 'none', 'label' => 'Aucune passerelle'],
            ],
            GatewayPosition::options(),
        );
    }

    public function test_the_statuses_are_planned_active_and_retired(): void
    {
        $this->assertSame(['planned', 'active', 'retired'], array_column(Status::cases(), 'value'));

        $this->assertSame('Prévu', __(Status::Planned->label()));
        $this->assertSame('Actif', __(Status::Active->label()));
        $this->assertSame('Retiré', __(Status::Retired->label()));
    }

    public function test_a_status_agrees_with_an_object_whose_name_is_feminine(): void
    {
        $this->assertSame('Prévue', __(Status::Planned->label(feminine: true)));
        $this->assertSame('Active', __(Status::Active->label(feminine: true)));
        $this->assertSame('Retirée', __(Status::Retired->label(feminine: true)));

        $this->assertSame(
            [
                ['value' => 'planned', 'label' => 'Prévu'],
                ['value' => 'active', 'label' => 'Actif'],
                ['value' => 'retired', 'label' => 'Retiré'],
            ],
            Status::options(),
        );

        $this->assertSame(
            [
                ['value' => 'planned', 'label' => 'Prévue'],
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'retired', 'label' => 'Retirée'],
            ],
            Status::options(feminine: true),
        );
    }

    public function test_only_the_events_about_addresses_ranges_links_and_jacks_concern_a_feminine_object(): void
    {
        $feminine = array_values(array_map(
            fn (ActivityEvent $event): string => $event->value,
            array_filter(ActivityEvent::cases(), fn (ActivityEvent $event): bool => $event->concernsFeminineObject()),
        ));

        $this->assertSame(
            [
                'ip_range.created', 'ip_range.updated', 'ip_range.deleted',
                // What a range was called before it had a kind.
                'dhcp_range.created', 'dhcp_range.updated', 'dhcp_range.deleted',
                'ip_address.created', 'ip_address.updated', 'ip_address.deleted',
                'ip_address.attached', 'ip_address.detached',
                'ip_address.plugged', 'ip_address.unplugged',
                'link.created', 'link.updated', 'link.deleted',
                'jack.created', 'jack.updated', 'jack.deleted',
            ],
            $feminine,
        );
    }

    public function test_every_medium_of_a_link_has_a_french_label(): void
    {
        $this->assertSame(
            [
                ['value' => 'copper', 'label' => 'Cuivre'],
                ['value' => 'fiber_multimode', 'label' => 'Fibre multimode'],
                ['value' => 'fiber_singlemode', 'label' => 'Fibre monomode'],
                ['value' => 'direct_attach', 'label' => 'Câble direct'],
                ['value' => 'other', 'label' => 'Autre'],
            ],
            LinkMedium::options(),
        );
    }

    public function test_every_connector_speed_and_power_standard_of_a_port_has_a_french_label(): void
    {
        $this->assertSame(
            [
                ['value' => 'rj45', 'label' => 'RJ45'],
                ['value' => 'sfp', 'label' => 'SFP'],
                ['value' => 'sfp_plus', 'label' => 'SFP+'],
                ['value' => 'sfp28', 'label' => 'SFP28'],
                ['value' => 'qsfp_plus', 'label' => 'QSFP+'],
                ['value' => 'qsfp28', 'label' => 'QSFP28'],
                ['value' => 'other', 'label' => 'Autre'],
            ],
            PortConnector::options(),
        );

        $this->assertSame(
            [
                ['value' => '100m', 'label' => '100 Mbit/s'],
                ['value' => '1g', 'label' => '1 Gbit/s'],
                // A decimal comma, as French writes it.
                ['value' => '2_5g', 'label' => '2,5 Gbit/s'],
                ['value' => '5g', 'label' => '5 Gbit/s'],
                ['value' => '10g', 'label' => '10 Gbit/s'],
                ['value' => '25g', 'label' => '25 Gbit/s'],
                ['value' => '40g', 'label' => '40 Gbit/s'],
                ['value' => '100g', 'label' => '100 Gbit/s'],
            ],
            PortSpeed::options(),
        );

        $this->assertSame(
            [
                ['value' => 'none', 'label' => 'Aucun'],
                ['value' => '802.3af', 'label' => '802.3af'],
                ['value' => '802.3at', 'label' => '802.3at'],
                ['value' => '802.3bt_type3', 'label' => '802.3bt Type 3'],
                ['value' => '802.3bt_type4', 'label' => '802.3bt Type 4'],
            ],
            PoeType::options(),
        );
    }

    public function test_the_roles_of_a_member_the_protocols_of_an_aggregate_and_the_modes_of_a_port_have_a_french_label(): void
    {
        $this->assertSame(
            [
                ['value' => 'primary', 'label' => 'Principal'],
                ['value' => 'secondary', 'label' => 'Secondaire'],
                ['value' => 'member', 'label' => 'Membre'],
            ],
            MemberRole::options(),
        );

        // A device has one primary and one secondary at most, and as many
        // plain members as it has boxes.
        $this->assertSame([true, true, false], array_map(fn (MemberRole $role): bool => $role->isSingle(), MemberRole::cases()));

        $this->assertSame(
            [['value' => 'lacp', 'label' => 'LACP'], ['value' => 'static', 'label' => 'Statique']],
            AggregateProtocol::options(),
        );

        $this->assertSame(
            [['value' => 'access', 'label' => 'Accès'], ['value' => 'trunk', 'label' => 'Trunk']],
            PortMode::options(),
        );
    }

    public function test_an_ip_address_is_static_or_a_dhcp_reservation(): void
    {
        $this->assertSame(['static', 'dhcp_reservation'], array_column(IpAssignment::cases(), 'value'));

        $this->assertSame('Fixe', __(IpAssignment::Static->label()));
        $this->assertSame('Réservation DHCP', __(IpAssignment::DhcpReservation->label()));
    }

    public function test_the_device_types_are_the_twelve_of_the_data_model(): void
    {
        $this->assertSame(
            [
                'server', 'workstation', 'laptop', 'printer', 'ip_phone', 'access_point',
                'switch', 'router', 'firewall', 'camera', 'nas', 'other',
            ],
            array_column(DeviceType::cases(), 'value'),
        );
    }

    public function test_every_device_type_has_a_french_label(): void
    {
        $catalogue = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach (DeviceType::cases() as $type) {
            $this->assertArrayHasKey($type->label(), $catalogue, "[{$type->value}] has no French label.");
        }

        $this->assertSame('Serveur', __(DeviceType::Server->label()));
        $this->assertSame('Téléphone IP', __(DeviceType::IpPhone->label()));
        $this->assertSame('Borne Wi-Fi', __(DeviceType::AccessPoint->label()));
        $this->assertSame('Pare-feu', __(DeviceType::Firewall->label()));
    }

    public function test_the_options_of_an_enum_carry_translated_labels(): void
    {
        $this->assertSame(
            [
                ['value' => 'planned', 'label' => 'Prévu'],
                ['value' => 'active', 'label' => 'Actif'],
                ['value' => 'retired', 'label' => 'Retiré'],
            ],
            Status::options(),
        );

        $this->assertSame(['value' => 'static', 'label' => 'Fixe'], IpAssignment::options()[0]);
        $this->assertCount(12, DeviceType::options());
        $this->assertSame(['value' => 'nas', 'label' => 'NAS'], DeviceType::options()[10]);
    }
}
