#!/usr/bin/env bash
# torsade/deploy/install.sh
#
# Installs Torsade on a dedicated Debian 13 machine, from the unpacked
# archive of a release:
#
#   sudo bash deploy/install.sh [options]
#
# Without options, the script asks its questions. Run again after a
# failure, it resumes: it reuses the passwords of the database and the key
# of the application it already made, and never creates a second
# administrator. Once an installation is complete, it refuses to run:
# torsade-update updates it. See documentation/administration/installation.md.
set -euo pipefail

source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=deploy/lib/torsade.sh
. "$source_dir/deploy/lib/torsade.sh"

usage() {
    cat << 'EOF'
Installation de Torsade sur Debian 13, depuis l'archive décompressée :

  sudo bash deploy/install.sh [options]

Options :
  --host NOM               nom de l'application : nom d'hôte (torsade.example.test)
                           ou, pour un essai, adresse IPv4 de la machine
  --self-signed            certificat autosigné, pour un essai
  --certificate FICHIER    certificat fourni (PEM), suivi de sa chaîne
  --key FICHIER            clé privée de ce certificat (PEM, sans phrase de passe)
  --authority FICHIER      autorité qui a signé la chaîne, quand le système
                           ne la connaît pas (sert à la vérifier)
  --hsts                   active HSTS (certificat fourni seulement)
  --security-updates       active les mises à jour de sécurité automatiques
  --admin-name NOM         nom complet du premier administrateur
  --admin-email ADRESSE    adresse de connexion du premier administrateur
  --non-interactive        aucune question : toutes les valeurs en options
  -h, --help               cette aide

Sans option, le script pose ses questions.
EOF
}

host=""
tls_mode=""
certificate=""
key=""
authority=""
hsts=no
security_updates=no
admin_name=""
admin_email=""
interactive=yes

# An option that takes a value and comes without one.
needs_value() {
    if [ "$2" -lt 2 ]; then
        printf 'Option sans valeur : %s\n\n' "$1" >&2
        usage >&2
        exit 2
    fi
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --host) needs_value "$1" "$#"; host="$2"; shift 2 ;;
        --self-signed) tls_mode=self-signed; shift ;;
        --certificate) needs_value "$1" "$#"; certificate="$2"; tls_mode=provided; shift 2 ;;
        --key) needs_value "$1" "$#"; key="$2"; shift 2 ;;
        --authority) needs_value "$1" "$#"; authority="$2"; shift 2 ;;
        --hsts) hsts=yes; shift ;;
        --security-updates) security_updates=yes; shift ;;
        --admin-name) needs_value "$1" "$#"; admin_name="$2"; shift 2 ;;
        --admin-email) needs_value "$1" "$#"; admin_email="$2"; shift 2 ;;
        --non-interactive) interactive=no; shift ;;
        -h | --help) usage; exit 0 ;;
        *) printf 'Option inconnue : %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done

require_root

if [ "$interactive" = yes ] && [ ! -t 0 ]; then
    printf 'Erreur : sans terminal, lancez le script avec --non-interactive et toutes ses options.\n' >&2
    exit 2
fi

open_log /var/log/torsade-install.log
take_lock torsade-admin

# Whatever stops the script before its end names the step and, once the
# checks before any change have passed, says how to resume.
completed=no
resumable=no
on_exit() {
    local status=$?
    if [ "$completed" = yes ] || [ "$status" -eq 0 ] || [ "$resumable" = no ]; then
        return
    fi
    printf "\nL'installation s'est arrêtée à l'étape « %s ».\n" "$torsade_step" >&2
    printf "Corrigez la cause indiquée, puis relancez la même commande : l'installation reprend où elle s'est arrêtée.\n" >&2
    show_log_tail
}
trap on_exit EXIT

# A question; the end of the input (Ctrl-D) stops the script.
ask() {
    local prompt="$1" answer
    read -r -p "$prompt " answer || return 1
    printf '%s' "$answer"
}

confirm() {
    local answer
    answer="$(ask "$1 [oui/non]")" || die "installation abandonnée."
    [ "$answer" = "oui" ]
}

version="$(tr -d '[:space:]' < "$source_dir/VERSION")"
release="$TORSADE_RELEASES/$version"

# --- 1. Before any change ---------------------------------------------------

step "Contrôles préalables"

# shellcheck disable=SC1091
os_id="$(. /etc/os-release && printf '%s' "${ID:-}")"
# shellcheck disable=SC1091
os_version="$(. /etc/os-release && printf '%s' "${VERSION_ID:-}")"
if [ "$os_id" != debian ] || [ "$os_version" != 13 ]; then
    die "Torsade s'installe sur Debian 13 ; cette machine est « $os_id $os_version »."
fi

if ! valid_version "$version" || [ ! -f "$source_dir/artisan" ] || [ ! -f "$source_dir/vendor/autoload.php" ]; then
    die "lancez ce script depuis l'archive décompressée de Torsade : sudo bash deploy/install.sh"
fi

if [ -f "$TORSADE_INSTALLED" ]; then
    die "Torsade est déjà installé ($(installed_version)). Pour passer à une nouvelle version : sudo torsade-update <archive>."
fi

for place in / /var; do
    available="$(df --output=avail -k "$place" | tail -n 1 | tr -d ' ')"
    if [ "$available" -lt 2097152 ]; then
        die "il faut au moins 2 Gio d'espace libre sur $place."
    fi
done

if ! DEBIAN_FRONTEND=noninteractive run apt-get update --error-on=any; then
    die "les dépôts Debian configurés sur cette machine ne répondent pas (apt-get update)."
fi

# The settings of a previous attempt, when the options do not repeat them.
if [ -z "$host" ]; then
    host="$(conf_get TORSADE_HOST "$TORSADE_SETTINGS")"
fi
if [ -z "$tls_mode" ]; then
    tls_mode="$(conf_get TORSADE_TLS "$TORSADE_SETTINGS")"
fi

if [ "$interactive" = yes ]; then
    while [ -z "$host" ]; do
        host="$(ask "Nom de l'application (nom d'hôte, ou adresse IPv4 pour un essai) :")" || die "installation abandonnée."
    done
    if [ -z "$tls_mode" ]; then
        if confirm "Avez-vous un certificat pour ce nom (sinon, certificat autosigné pour un essai) ?"; then
            tls_mode=provided
        else
            tls_mode=self-signed
        fi
    fi
    if [ "$tls_mode" = provided ]; then
        if [ -z "$certificate" ]; then
            certificate="$(ask "Fichier du certificat (PEM, suivi de sa chaîne) :")" || die "installation abandonnée."
        fi
        if [ -z "$key" ]; then
            key="$(ask "Fichier de sa clé privée (PEM) :")" || die "installation abandonnée."
        fi
        if [ -z "$authority" ] && confirm "L'autorité qui l'a signé est-elle propre à votre organisation ?"; then
            authority="$(ask "Fichier du certificat de cette autorité (PEM) :")" || die "installation abandonnée."
        fi
        if [ "$hsts" = no ] && confirm "Activer HSTS ?"; then
            hsts=yes
        fi
    fi
    if [ "$security_updates" = no ] && confirm "Activer les mises à jour de sécurité automatiques de Debian ?"; then
        security_updates=yes
    fi
fi

if ! is_hostname "$host" && ! is_ipv4 "$host"; then
    die "« $host » n'est ni un nom d'hôte complet ni une adresse IPv4 (--host)."
fi

case "$tls_mode" in
    self-signed) ;;
    provided)
        [ -r "$certificate" ] || die "certificat illisible : « $certificate » (--certificate)."
        [ -r "$key" ] || die "clé privée illisible : « $key » (--key)."
        if [ -n "$authority" ] && [ ! -r "$authority" ]; then
            die "autorité illisible : « $authority » (--authority)."
        fi
        ;;
    *) die "choisissez un certificat : --self-signed, ou --certificate et --key." ;;
esac

if [ "$hsts" = yes ]; then
    if [ "$tls_mode" = self-signed ]; then
        die "HSTS est refusé avec un certificat autosigné : le navigateur interdirait de passer outre son avertissement."
    fi
    printf "\nHSTS : un navigateur qui a ouvert Torsade refusera ensuite tout accès sans un certificat valide, pendant un an.\n"
    printf "Un poste qui ne connaît pas l'autorité de votre certificat ne pourra plus ouvrir l'application.\n"
    if [ "$interactive" = yes ] && ! confirm "Confirmez-vous l'activation de HSTS ?"; then
        hsts=no
    fi
fi

if [ "$interactive" = no ] && { [ -z "$admin_name" ] || [ -z "$admin_email" ]; }; then
    die "en mode non interactif, --admin-name et --admin-email sont nécessaires."
fi

ensure_dir 0750 root root "$TORSADE_CONF"
(umask 0077 && touch "$TORSADE_SETTINGS")
conf_set TORSADE_HOST "$host" "$TORSADE_SETTINGS"
conf_set TORSADE_TLS "$tls_mode" "$TORSADE_SETTINGS"
conf_set TORSADE_HSTS "$hsts" "$TORSADE_SETTINGS"

say "Torsade $version, pour https://$host"
resumable=yes

# --- 2. Packages ------------------------------------------------------------

step "Paquets de Debian"

# From the repositories of Debian configured on the machine only. PHP-FPM
# only recommends OPcache: it is asked for. The extensions are those the
# dependencies of Torsade require.
packages=(
    nginx
    mariadb-server
    mariadb-client
    php8.4-fpm
    php8.4-cli
    php8.4-opcache
    php8.4-mysql
    php8.4-mbstring
    php8.4-xml
    curl
    openssl
    ca-certificates
)
if [ "$security_updates" = yes ]; then
    packages+=(unattended-upgrades)
fi
DEBIAN_FRONTEND=noninteractive run apt-get install --yes --no-install-recommends "${packages[@]}"

# --- 3. Account -------------------------------------------------------------

step "Compte système torsade"

if ! id -u "$TORSADE_USER" > /dev/null 2>&1; then
    run useradd --system --user-group --home-dir "$TORSADE_DATA" --no-create-home \
        --shell /usr/sbin/nologin "$TORSADE_USER"
fi

# --- 4. Places --------------------------------------------------------------

step "Emplacements"

ensure_dir 0755 root root "$TORSADE_ROOT" "$TORSADE_RELEASES"
ensure_dir 0750 root "$TORSADE_USER" "$TORSADE_CONF"
ensure_dir 0700 root root "$TORSADE_BACKUPS"
prepare_data "$source_dir/storage"

# --- 5. Certificate ---------------------------------------------------------

step "Certificat"

ensure_dir 0755 root root "$TORSADE_TLS_DIR"

# The name the certificate must cover: a name of host, or an address.
name_check=(-checkhost "$host")
san="DNS:$host"
if is_ipv4 "$host"; then
    name_check=(-checkip "$host")
    san="IP:$host"
fi

covers_name() {
    openssl x509 -in "$1" -noout "${name_check[@]}" 2> /dev/null | grep -q "does match"
}

if [ "$tls_mode" = self-signed ]; then
    # Kept when a previous attempt made it for this name and it lasts.
    if [ -f "$TORSADE_TLS_CERT" ] && [ -f "$TORSADE_TLS_KEY" ] && covers_name "$TORSADE_TLS_CERT" \
        && openssl x509 -in "$TORSADE_TLS_CERT" -noout -checkend 2592000 > /dev/null 2>&1; then
        say "Certificat autosigné déjà créé pour $host : conservé."
    else
        work="$(mktemp -d)"
        cat > "$work/request.cnf" << EOF
[req]
distinguished_name = dn
x509_extensions = ext
prompt = no

[dn]
CN = $host

[ext]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = serverAuth
subjectAltName = $san
subjectKeyIdentifier = hash
EOF
        # ECDSA on P-256 signed with SHA-256, for 825 days at most, for the
        # name of the application only (TLS recommendations of the ANSSI).
        (umask 0077 && run openssl req -x509 -config "$work/request.cnf" \
            -newkey ec -pkeyopt ec_paramgen_curve:P-256 -sha256 -days 825 -noenc \
            -keyout "$TORSADE_TLS_KEY.new" -out "$TORSADE_TLS_CERT.new")
        rm -rf "$work"
        chmod 0600 "$TORSADE_TLS_KEY.new"
        chmod 0644 "$TORSADE_TLS_CERT.new"
        mv -T "$TORSADE_TLS_KEY.new" "$TORSADE_TLS_KEY"
        mv -T "$TORSADE_TLS_CERT.new" "$TORSADE_TLS_CERT"
    fi
    rm -f "$TORSADE_TLS_CA"
else
    work="$(mktemp -d)"
    # The certificate first, then the chain that follows it in the file.
    awk -v dir="$work" '
        /-----BEGIN CERTIFICATE-----/ { n++ }
        n == 1 { print > (dir "/leaf.pem") }
        n > 1 { print > (dir "/chain.pem") }
    ' "$certificate"
    [ -s "$work/leaf.pem" ] || die "aucun certificat PEM dans « $certificate »."
    touch "$work/chain.pem"

    certificate_key="$(openssl x509 -in "$work/leaf.pem" -noout -pubkey | openssl pkey -pubin -outform DER | sha256sum)"
    private_key="$(openssl pkey -in "$key" -passin pass: -pubout -outform DER 2> /dev/null | sha256sum)" \
        || die "clé privée illisible, ou protégée par une phrase de passe : « $key »."
    [ "$certificate_key" = "$private_key" ] || die "la clé privée ne correspond pas au certificat."

    covers_name "$work/leaf.pem" || die "le certificat ne couvre pas le nom « $host »."
    openssl x509 -in "$work/leaf.pem" -noout -checkend 0 > /dev/null || die "le certificat a expiré."

    # Up to an authority the system knows, or the one given, through the
    # intermediate authorities of the file, when there are any.
    verify=(openssl verify)
    if grep -q -- "-----BEGIN CERTIFICATE-----" "$work/chain.pem"; then
        verify+=(-untrusted "$work/chain.pem")
    fi
    if [ -n "$authority" ]; then
        verify+=(-CAfile "$authority")
    fi
    if ! run "${verify[@]}" "$work/leaf.pem"; then
        die "la chaîne du certificat est incomplète ou invalide (dates, autorité) : voir $torsade_log."
    fi

    cat "$work/leaf.pem" "$work/chain.pem" > "$TORSADE_TLS_CERT.new"
    (umask 0077 && cat "$key" > "$TORSADE_TLS_KEY.new")
    chmod 0644 "$TORSADE_TLS_CERT.new"
    chmod 0600 "$TORSADE_TLS_KEY.new"
    mv -T "$TORSADE_TLS_CERT.new" "$TORSADE_TLS_CERT"
    mv -T "$TORSADE_TLS_KEY.new" "$TORSADE_TLS_KEY"
    if [ -n "$authority" ]; then
        install -m 0644 "$authority" "$TORSADE_TLS_CA"
    else
        rm -f "$TORSADE_TLS_CA"
    fi
    rm -rf "$work"
fi
chown root:root "$TORSADE_TLS_KEY" "$TORSADE_TLS_CERT"

# --- 6. Configuration of the application ------------------------------------

step "Configuration de l'application"

# Written once, then only the values the installation owns are set again:
# the key of the application and the password of the database stay.
if [ ! -f "$TORSADE_ENV" ]; then
    app_key="base64:$(head -c 32 /dev/urandom | base64)"
    (umask 0027 && cat > "$TORSADE_ENV.new" << EOF
# /etc/torsade/torsade.env: configuration of Torsade, written by its
# installation. After a change, rebuild the caches and reload PHP-FPM:
# see documentation/administration/exploitation.md.

APP_NAME=Torsade
APP_ENV=production
APP_KEY=$app_key
APP_DEBUG=false
APP_URL=https://$host

APP_LOCALE=fr
APP_FALLBACK_LOCALE=en
APP_DISPLAY_TIMEZONE=Europe/Paris
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=daily
LOG_LEVEL=info
LOG_DAILY_DAYS=14
LOG_DEPRECATIONS_CHANNEL=null

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_SOCKET=$TORSADE_DB_SOCKET
DB_DATABASE=$TORSADE_DB
DB_USERNAME=$TORSADE_DB_USER
DB_PASSWORD=$(random_hex 24)

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_COOKIE=__Host-torsade-session

HSTS_ENABLED=false
TRUSTED_HOSTS=
TRUSTED_PROXIES=

CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log
MAIL_MAILER=log
EOF
    )
    chown root:"$TORSADE_USER" "$TORSADE_ENV.new"
    mv -T "$TORSADE_ENV.new" "$TORSADE_ENV"
fi
conf_set APP_URL "https://$host" "$TORSADE_ENV"
if [ "$hsts" = yes ]; then
    conf_set HSTS_ENABLED true "$TORSADE_ENV"
else
    conf_set HSTS_ENABLED false "$TORSADE_ENV"
fi
chown root:"$TORSADE_USER" "$TORSADE_ENV"
chmod 0640 "$TORSADE_ENV"

if [ ! -f "$TORSADE_MIGRATION_PASSWORD" ]; then
    (umask 0077 && random_hex 24 > "$TORSADE_MIGRATION_PASSWORD")
fi
chown root:root "$TORSADE_MIGRATION_PASSWORD"
chmod 0600 "$TORSADE_MIGRATION_PASSWORD"

# --- 7. Version -------------------------------------------------------------

step "Version $version"

place_release "$source_dir" "$release"

# --- 8. Database ------------------------------------------------------------

step "Base de données"

run systemctl enable --now mariadb

# MariaDB listens on the machine only; Torsade reaches it by its socket.
listens_locally() {
    local skip bind address
    read -r skip bind < <(mariadb --batch --skip-column-names -e 'SELECT @@skip_networking, @@bind_address')
    [ "$skip" = 1 ] && return 0
    [ -n "$bind" ] || return 1
    for address in ${bind//,/ }; do
        case "$address" in
            127.0.0.1 | ::1 | localhost) ;;
            *) return 1 ;;
        esac
    done
}

if ! listens_locally; then
    say "MariaDB écoutait au-delà de la machine : il n'écoute plus que sur 127.0.0.1."
    install -m 0644 "$source_dir/deploy/mariadb/90-torsade.cnf" "$TORSADE_MARIADB_CNF"
    run systemctl restart mariadb
    listens_locally || die "MariaDB écoute encore au-delà de la machine : vérifiez /etc/mysql/mariadb.conf.d/."
fi

# The character set and the collation the application declares, read by
# PHP from its booted configuration.
# shellcheck disable=SC2016
read -r charset collation < <(as_torsade "$release" "$TORSADE_PHP" -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connection = config("database.connections.mariadb");
    echo $connection["charset"], " ", $connection["collation"], "\n";
')
[[ "$charset" =~ ^[a-z0-9_]+$ && "$collation" =~ ^[a-z0-9_]+$ ]] || die "jeu de caractères illisible dans config/database.php."

db_password="$(conf_get DB_PASSWORD "$TORSADE_ENV")"
migration_password="$(cat "$TORSADE_MIGRATION_PASSWORD")"
[[ "$db_password" =~ ^[0-9a-f]{48}$ && "$migration_password" =~ ^[0-9a-f]{48}$ ]] \
    || die "mot de passe de la base inattendu dans $TORSADE_ENV ou $TORSADE_MIGRATION_PASSWORD."

# Two accounts: the application reads and writes the data, nothing else;
# the migrations, run by the installation and the updates only, change the
# schema. The passwords go through the input of the client, never a
# command line.
mariadb --batch << EOF
CREATE DATABASE IF NOT EXISTS \`$TORSADE_DB\` CHARACTER SET $charset COLLATE $collation;
CREATE USER IF NOT EXISTS '$TORSADE_DB_USER'@'localhost' IDENTIFIED BY '$db_password';
ALTER USER '$TORSADE_DB_USER'@'localhost' IDENTIFIED BY '$db_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$TORSADE_DB\`.* TO '$TORSADE_DB_USER'@'localhost';
CREATE USER IF NOT EXISTS '$TORSADE_DB_MIGRATION_USER'@'localhost' IDENTIFIED BY '$migration_password';
ALTER USER '$TORSADE_DB_MIGRATION_USER'@'localhost' IDENTIFIED BY '$migration_password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON \`$TORSADE_DB\`.* TO '$TORSADE_DB_MIGRATION_USER'@'localhost';
EOF
unset db_password migration_password

# --- 9. Application ---------------------------------------------------------

step "Migrations et caches"

migrate "$release"
build_caches "$release"
switch_current "$release"

# --- 10. Web server ---------------------------------------------------------

step "PHP-FPM et Nginx"

write_managed_files "$source_dir/deploy"
run systemctl enable "$TORSADE_FPM_SERVICE" nginx
run systemctl restart "$TORSADE_FPM_SERVICE"
run nginx -t
run systemctl restart nginx

# --- 11. Timers -------------------------------------------------------------

step "Tâches planifiées"

run systemctl enable --now torsade-backup.timer torsade-purge-health-cache.timer

if [ "$security_updates" = yes ]; then
    step "Mises à jour de sécurité automatiques"
    # In a file of its own, read after those of Debian, which it leaves as
    # they are: the lists of packages and the security updates each day,
    # and nothing else, where Debian would also install the updates of its
    # point releases.
    cat > /etc/apt/apt.conf.d/52torsade-security-updates << 'EOF'
// Written by the installation of Torsade: unattended-upgrades installs the
// security updates of Debian each day, and nothing else.
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
#clear Unattended-Upgrade::Origins-Pattern;
Unattended-Upgrade::Origins-Pattern {
    "origin=Debian,codename=${distro_codename}-security,label=Debian-Security";
};
EOF
    chmod 0644 /etc/apt/apt.conf.d/52torsade-security-updates
    run systemctl enable --now apt-daily.timer apt-daily-upgrade.timer
fi

# --- 12. First administrator ------------------------------------------------

step "Premier administrateur"

admin_file=/root/torsade-premier-administrateur.txt
admins="$(mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM \`$TORSADE_DB\`.users WHERE role = 'admin' AND is_active = 1")"
if [ "$admins" != 0 ]; then
    say "Un administrateur existe déjà : aucun autre n'est créé."
elif [ "$interactive" = yes ]; then
    while [ -z "$admin_name" ]; do
        admin_name="$(ask "Nom complet du premier administrateur :")" || die "installation abandonnée."
    done
    while [ -z "$admin_email" ]; do
        admin_email="$(ask "Son adresse de connexion :")" || die "installation abandonnée."
    done
    # Shown once on this screen, never copied to the journal.
    artisan "$release" torsade:create-admin --name="$admin_name" --email="$admin_email"
else
    # Written for root only, never to the journal.
    if ! (umask 0077 && artisan "$release" torsade:create-admin --name="$admin_name" --email="$admin_email" > "$admin_file"); then
        cat "$admin_file" >&2
        rm -f "$admin_file"
        die "le premier administrateur n'a pas été créé."
    fi
fi

# --- 13. Checks -------------------------------------------------------------

step "Vérifications"

check_configuration "$release"
check_health || die "https://$host/up ne répond pas OK : voir $torsade_log et /var/log/nginx/error.log."

printf 'Torsade %s, installé le %s\n' "$version" "$(date -Is)" > "$TORSADE_INSTALLED"
chmod 0644 "$TORSADE_INSTALLED"
completed=yes

step "Installation terminée"

cat << EOF
Adresse de l'application : https://$host
EOF
if [ "$tls_mode" = self-signed ]; then
    fingerprint="$(openssl x509 -in "$TORSADE_TLS_CERT" -noout -fingerprint -sha256 | sed 's/^[^=]*=//')"
    cat << EOF

Certificat autosigné. Empreinte SHA-256, à comparer avec celle que montre
le navigateur avant d'accepter son avertissement :
  $fingerprint
EOF
fi
cat << EOF

Emplacements :
  code          $TORSADE_CURRENT -> $release
  configuration $TORSADE_ENV
  données       $TORSADE_DATA
  journaux      $TORSADE_LOGS
  sauvegardes   $TORSADE_BACKUPS (chaque jour)
  installation  $torsade_log
EOF
# Still there when an earlier attempt created the administrator.
if [ -f "$admin_file" ]; then
    cat << EOF

Le mot de passe temporaire du premier administrateur est dans
$admin_file, lisible par root seulement : supprimez ce fichier
après la première connexion.
EOF
fi
cat << EOF

Prochaines étapes : connectez-vous, changez le mot de passe temporaire,
activez la double authentification. Voir
documentation/administration/installation.md.
EOF
