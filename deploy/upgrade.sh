#!/usr/bin/env bash
# torsade/deploy/upgrade.sh
#
# The update of an installation to this version, led by the version that
# arrives. torsade-update hands over to this script once it has checked
# the archive, with the temporary directory it unpacked it in:
#
#   bash deploy/upgrade.sh <directory of the unpacked archive>
#
# Maintenance, then a backup it waits for; then this version is put in
# place, migrated and cached, becomes the current one at once, and the
# files Torsade manages are rewritten from it. The database is never put
# back automatically: a failure says which command does it.
set -euo pipefail

source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=deploy/lib/torsade.sh
. "$source_dir/deploy/lib/torsade.sh"

incoming="${1:-}"

require_root
open_log /var/log/torsade-update.log

version="$(tr -d '[:space:]' < "$source_dir/VERSION")"
release="$TORSADE_RELEASES/$version"
previous_release="$(readlink -f "$TORSADE_CURRENT")"
previous_version="$(installed_version)"
backup=""
# An administrator may have put the application in maintenance before the
# update: a failure then leaves it so.
was_down=no
if [ -f "$TORSADE_DATA/framework/down" ]; then
    was_down=yes
fi

# Where the update stands, for what a failure leaves and says.
phase=checks
completed=no

on_exit() {
    local status=$?

    if [ "$completed" != yes ] && [ "$status" -ne 0 ]; then
        printf "\nLa mise à jour vers %s a échoué à l'étape « %s ».\n" "$version" "$torsade_step" >&2
        case "$phase" in
            checks)
                printf "Rien n'a changé : la version %s reste en service.\n" "$previous_version" >&2
                ;;
            maintenance)
                # Before the migrations: the version in place is served again.
                if [ "$(readlink -f "$TORSADE_CURRENT")" != "$release" ]; then
                    rm -rf "$release" "$release.new"
                fi
                if [ "$was_down" = yes ]; then
                    printf "La base n'a pas été modifiée ; la version %s reste en maintenance, comme avant la mise à jour.\n" "$previous_version" >&2
                elif artisan "$previous_release" up >> "$torsade_log" 2>&1; then
                    printf "La base n'a pas été modifiée : la version %s est de nouveau en service.\n" "$previous_version" >&2
                else
                    printf "La base n'a pas été modifiée, mais la version %s n'a pas pu quitter la maintenance :\n" "$previous_version" >&2
                    printf "  cd %s && sudo runuser -u torsade -- php8.4 artisan up\n" "$TORSADE_CURRENT" >&2
                fi
                ;;
            migration)
                printf '%s\n' "$backup" > "$TORSADE_UPDATE_FAILED"
                printf "L'application reste en maintenance : la base a pu être modifiée en partie.\n" >&2
                printf "Les sauvegardes et les mises à jour attendent la restauration.\n" >&2
                printf "Pour rendre la version %s avec les données d'avant la mise à jour :\n" "$previous_version" >&2
                printf "  sudo torsade-restore %s\n" "$backup" >&2
                ;;
            switched)
                printf '%s\n' "$backup" > "$TORSADE_UPDATE_FAILED"
                artisan "$TORSADE_CURRENT" down >> "$torsade_log" 2>&1 || true
                printf "L'application reste en maintenance ; les sauvegardes et les mises à jour attendent la restauration.\n" >&2
                printf "Pour revenir à la version %s avec les données d'avant la mise à jour :\n" "$previous_version" >&2
                printf "  sudo torsade-restore --release %s %s\n" "$previous_version" "$backup" >&2
                ;;
            finished)
                # The new version is in service: only the cleaning failed.
                printf "Torsade %s est en service, mais les anciennes versions n'ont pas pu être retirées de %s.\n" "$version" "$TORSADE_RELEASES" >&2
                ;;
        esac
        show_log_tail
    fi

    if [[ "$incoming" == "$TORSADE_ROOT"/incoming.* ]]; then
        rm -rf "$incoming"
    fi
}
trap on_exit EXIT

step "Contrôles préalables"

take_lock torsade-admin
[ -f "$TORSADE_INSTALLED" ] || die "Torsade n'est pas installé sur cette machine."
if [ -f "$TORSADE_UPDATE_FAILED" ]; then
    die "$(update_failed_message)."
fi
valid_version "$version" || die "version illisible dans l'archive."
version_gt "$version" "$previous_version" || die "la version $version n'est pas plus récente que la version installée $previous_version."
for place in "$TORSADE_ROOT" /var; do
    available="$(df --output=avail -k "$place" | tail -n 1 | tr -d ' ')"
    if [ "$available" -lt 1048576 ]; then
        die "il faut au moins 1 Gio d'espace libre sur $place."
    fi
done
say "Torsade $previous_version vers $version"

step "Maintenance"

phase=maintenance
run artisan "$previous_release" down

step "Sauvegarde"

# Taken in maintenance: nothing is written between the backup and the
# update.
backup="$("$TORSADE_SBIN/torsade-backup" 2>> "$torsade_log")"
[ -d "$backup" ] || die "la sauvegarde n'a pas abouti."
say "Sauvegarde : $backup"

# No other backup until the end, which would export a schema migrated in
# part under the number of the previous version.
hold_backup_lock || die "une autre sauvegarde est en cours depuis plus de 15 minutes."

step "Version $version"

place_release "$source_dir" "$release"
prepare_data "$source_dir/storage"

step "Migrations et caches"

phase=migration
migrate "$release"
build_caches "$release"

step "Bascule"

phase=switched
switch_current "$release"
write_managed_files "$source_dir/deploy"
reload_php
reload_nginx

step "Remise en service"

run artisan "$release" up
check_health || die "https://$(conf_get TORSADE_HOST "$TORSADE_SETTINGS")/up ne répond pas OK : voir $torsade_log."
check_configuration "$release"

step "Versions conservées"

phase=finished
prune_releases

completed=yes
say "Torsade $version est en service (version précédente : $previous_version)."
say "Sauvegarde d'avant la mise à jour : $backup"
