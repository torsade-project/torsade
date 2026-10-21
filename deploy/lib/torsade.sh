# torsade/deploy/lib/torsade.sh
# shellcheck shell=bash
# The constants serve the scripts that source this file.
# shellcheck disable=SC2034
#
# What the scripts of the installation, the update, the backup and the
# restoration share: the places of an installation, the messages, and the
# operations on a version of the application. Sourced, never run. The
# installation copies it to /usr/local/lib/torsade/, where the commands of
# /usr/local/sbin find it.

# --- Places -----------------------------------------------------------------

TORSADE_ROOT=/opt/torsade
TORSADE_RELEASES=$TORSADE_ROOT/releases
TORSADE_CURRENT=$TORSADE_ROOT/current

TORSADE_CONF=/etc/torsade
TORSADE_ENV=$TORSADE_CONF/torsade.env
TORSADE_SETTINGS=$TORSADE_CONF/install.conf
TORSADE_MIGRATION_PASSWORD=$TORSADE_CONF/database-migration.password
TORSADE_BACKUP_CONF=$TORSADE_CONF/backup.conf
TORSADE_NGINX_LOCAL=$TORSADE_CONF/nginx-local.conf
TORSADE_TLS_DIR=$TORSADE_CONF/tls
TORSADE_TLS_CERT=$TORSADE_TLS_DIR/torsade.crt
TORSADE_TLS_KEY=$TORSADE_TLS_DIR/torsade.key
TORSADE_TLS_CA=$TORSADE_TLS_DIR/authority.crt
TORSADE_INSTALLED=$TORSADE_CONF/installed
# Left by an update that failed during or after its migrations, until a
# restoration: the database may be migrated in part.
TORSADE_UPDATE_FAILED=$TORSADE_CONF/update-failed

TORSADE_DATA=/var/lib/torsade
TORSADE_LOGS=/var/log/torsade
TORSADE_BACKUPS=/var/backups/torsade

TORSADE_SBIN=/usr/local/sbin
TORSADE_LIB=/usr/local/lib/torsade

TORSADE_USER=torsade
TORSADE_DB=torsade
TORSADE_DB_USER=torsade
TORSADE_DB_MIGRATION_USER=torsade_migration
TORSADE_DB_SOCKET=/run/mysqld/mysqld.sock

TORSADE_PHP=php8.4
TORSADE_FPM_SERVICE=php8.4-fpm
TORSADE_FPM_POOL=/etc/php/8.4/fpm/pool.d/torsade.conf
TORSADE_FPM_DEFAULT_POOL=/etc/php/8.4/fpm/pool.d/www.conf
TORSADE_FPM_INI=/etc/php/8.4/fpm/conf.d/90-torsade.ini
TORSADE_FPM_SOCKET=/run/php/torsade-fpm.sock

TORSADE_NGINX_SITE=/etc/nginx/sites-available/torsade
TORSADE_NGINX_ENABLED=/etc/nginx/sites-enabled/torsade
TORSADE_NGINX_DEFAULT=/etc/nginx/sites-enabled/default

TORSADE_MARIADB_CNF=/etc/mysql/mariadb.conf.d/90-torsade.cnf
TORSADE_SYSTEMD=/etc/systemd/system

# The units the installation writes, from deploy/systemd/.
TORSADE_UNITS=(
    torsade-backup.service
    torsade-backup.timer
    torsade-purge-health-cache.service
    torsade-purge-health-cache.timer
)

# The commands the installation puts in /usr/local/sbin, from deploy/bin/.
TORSADE_COMMANDS=(torsade-update torsade-backup torsade-restore)

# Versions kept in /opt/torsade/releases after an update.
TORSADE_KEEP_RELEASES=3

# The settings of the application a backup keeps, and a restoration may
# bring back: the key, without which the secrets of the second factor
# cannot be read, and the choices of the administrator. Never the password
# of the database, the address of the application nor its HSTS, which
# belong to the machine.
TORSADE_KEPT_SETTINGS=(
    APP_KEY
    APP_NAME
    APP_LOCALE
    APP_FALLBACK_LOCALE
    APP_DISPLAY_TIMEZONE
    SESSION_LIFETIME
    LOG_LEVEL
    LOG_DAILY_DAYS
    TRUSTED_HOSTS
    TRUSTED_PROXIES
)

# --- Messages ---------------------------------------------------------------

# The journal of the script that runs, root only. Every command run through
# run() writes there; no password ever goes through a command line, so none
# reaches it.
torsade_log=/dev/null
torsade_step=""

open_log() {
    torsade_log="$1"
    (umask 0077 && touch "$torsade_log")
    chmod 0600 "$torsade_log"
    printf '\n=== %s %s\n' "$(date -Is)" "$(basename "$0")" >> "$torsade_log"
}

say() {
    printf '%s\n' "$*"
    printf '%s %s\n' "$(date -Is)" "$*" >> "$torsade_log"
}

warn() {
    printf 'Attention : %s\n' "$*" >&2
    printf '%s attention : %s\n' "$(date -Is)" "$*" >> "$torsade_log"
}

die() {
    if [ -n "$torsade_step" ]; then
        printf "Erreur à l'étape « %s » : %s\n" "$torsade_step" "$*" >&2
    else
        printf 'Erreur : %s\n' "$*" >&2
    fi
    printf '%s erreur : %s\n' "$(date -Is)" "$*" >> "$torsade_log"
    exit 1
}

step() {
    torsade_step="$1"
    printf '\n==> %s\n' "$1"
    printf '%s ==> %s\n' "$(date -Is)" "$1" >> "$torsade_log"
}

# Runs a command, its output going to the journal only. The command runs
# in a subshell: when a function fails there, bash would otherwise leave
# the script with its output still sent to the journal, and the messages
# of the failure would never reach the screen.
run() {
    printf '+ %s\n' "$*" >> "$torsade_log"
    ("$@") >> "$torsade_log" 2>&1
}

# What a failed update leaves to do, for the commands that wait for it.
update_failed_message() {
    printf "une mise à jour a échoué et la base peut être migrée en partie : restaurez d'abord la sauvegarde faite avant elle, par sudo torsade-restore %s" "$(cat "$TORSADE_UPDATE_FAILED")"
}

# The last lines of the journal, to explain a failure.
show_log_tail() {
    if [ -f "$torsade_log" ]; then
        printf '\nDernières lignes du journal %s :\n' "$torsade_log" >&2
        tail -n 20 "$torsade_log" >&2
    fi
}

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        printf 'Erreur : %s se lance en root (sudo).\n' "$(basename "$0")" >&2
        exit 1
    fi
}

# The file of a lock, in a folder of root only: in /run/lock, which every
# account may write, any of them could hold the lock and stop the commands.
lock_file() {
    install -d -m 0700 -o root -g root /run/torsade
    printf '/run/torsade/%s.lock' "$1"
}

# Takes a lock, or stops when another administration command holds it.
take_lock() {
    local file
    file="$(lock_file "$1")"
    exec {torsade_lock_fd}> "$file"
    if ! flock --nonblock "$torsade_lock_fd"; then
        die "une autre commande d'administration de Torsade est en cours ($1)."
    fi
}

# Waits for the backup in progress, if any, then keeps any other from
# starting until the script ends.
hold_backup_lock() {
    local file
    file="$(lock_file backup)"
    exec {torsade_backup_lock_fd}> "$file"
    flock --wait 900 "$torsade_backup_lock_fd"
}

# --- Values -----------------------------------------------------------------

# The value of a key of a file of KEY=VALUE lines, without surrounding quotes.
conf_get() {
    local key="$1" file="$2" value
    [ -f "$file" ] || return 0
    value="$(sed -n "s/^${key}=//p" "$file" | tail -n 1)"
    value="${value%\"}"
    value="${value#\"}"
    printf '%s' "$value"
}

# Sets KEY=VALUE in a file of such lines, keeping its mode and owner; adds
# the line when the key is missing. The value is passed through the
# environment, never interpreted. The new file is written aside, then
# takes the place of the old one: a full disk never leaves it empty.
conf_set() {
    local key="$1" value="$2" file="$3"
    (umask 0077 && KEY="$key" VALUE="$value" awk '
        BEGIN { done = 0 }
        index($0, ENVIRON["KEY"] "=") == 1 { print ENVIRON["KEY"] "=" ENVIRON["VALUE"]; done = 1; next }
        { print }
        END { if (!done) print ENVIRON["KEY"] "=" ENVIRON["VALUE"] }
    ' "$file" > "$file.new")
    chown --reference="$file" "$file.new"
    chmod --reference="$file" "$file.new"
    mv -T "$file.new" "$file"
}

# Directories with their mode and owner, whether they exist or not.
ensure_dir() {
    local mode="$1" owner="$2" group="$3"
    shift 3
    mkdir -p "$@"
    chown "$owner:$group" "$@"
    chmod "$mode" "$@"
}

# Random hexadecimal characters, from the generator of the kernel.
random_hex() {
    od -An -N "$1" -tx1 /dev/urandom | tr -d ' \n'
}

# Whether version $1 is strictly higher than version $2.
version_gt() {
    [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n 1)" = "$1" ]
}

valid_version() {
    [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
}

is_ipv4() {
    local IFS=. part
    [[ "$1" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1
    for part in $1; do
        [ "$part" -le 255 ] || return 1
    done
}

is_hostname() {
    [[ "$1" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$ ]]
}

installed_version() {
    if [ -f "$TORSADE_CURRENT/VERSION" ]; then
        tr -d '[:space:]' < "$TORSADE_CURRENT/VERSION"
    fi
}

# --- The application --------------------------------------------------------

# Runs a command of the application as its own account, never as root: a
# file root left behind would be one PHP-FPM cannot write. The files it
# creates are closed to other users.
as_torsade() {
    local release="$1"
    shift
    (cd "$release" && umask 0027 && runuser -u "$TORSADE_USER" -- "$@")
}

artisan() {
    local release="$1"
    shift
    as_torsade "$release" "$TORSADE_PHP" artisan --no-interaction "$@"
}

# Puts the version unpacked in $1 in place as $2 under the releases: owned
# by root, written by nobody else, linked to the configuration and the data
# of the machine. Only its cache of the start-up belongs to the account of
# the application.
place_release() {
    local source="$1" target="$2"

    rm -rf "$target.new"
    mkdir -p "$TORSADE_RELEASES"
    cp -R "$source" "$target.new"
    chown -R root:root "$target.new"
    chmod -R u+rwX,go+rX,go-w "$target.new"

    # Never the scripts of a previous attempt nor what an archive might
    # carry in these places.
    rm -rf "$target.new/storage" "$target.new/.env"
    ln -s "$TORSADE_DATA" "$target.new/storage"
    ln -s "$TORSADE_ENV" "$target.new/.env"

    rm -rf "$target.new/bootstrap/cache"
    install -d -m 0750 -o "$TORSADE_USER" -g "$TORSADE_USER" "$target.new/bootstrap/cache"

    rm -rf "$target"
    mv -T "$target.new" "$target"
}

# The structure of the storage, created where it is missing, from the empty
# skeleton the archive holds. The logs go to /var/log/torsade, where an
# administrator looks for them. Under /var/lib/torsade, everything is done
# by the account of the application: root never changes the owner of what
# that account could have replaced with a link to anywhere else.
prepare_data() {
    local skeleton="$1" dir

    ensure_dir 0750 "$TORSADE_USER" "$TORSADE_USER" "$TORSADE_DATA" "$TORSADE_LOGS"
    (cd "$skeleton" && find . -mindepth 1 -type d ! -path './logs' ! -path './logs/*') | while IFS= read -r dir; do
        runuser -u "$TORSADE_USER" -- mkdir -p -m 0750 "$TORSADE_DATA/$dir"
    done

    if [ "$(readlink "$TORSADE_DATA/logs" || true)" != "$TORSADE_LOGS" ]; then
        runuser -u "$TORSADE_USER" -- rm -rf "$TORSADE_DATA/logs"
        runuser -u "$TORSADE_USER" -- ln -s "$TORSADE_LOGS" "$TORSADE_DATA/logs"
    fi
}

# The migrations, with the account that may change the schema: its
# password goes to the process through its environment, which the
# framework prefers to the .env file, never through a command line. A
# configuration in cache would ignore the environment: it goes first, and
# is built again after the migrations.
migrate() {
    local release="$1" password
    run artisan "$release" config:clear
    password="$(cat "$TORSADE_MIGRATION_PASSWORD")"
    DB_USERNAME="$TORSADE_DB_MIGRATION_USER" DB_PASSWORD="$password" \
        run artisan "$release" migrate --force
}

# The configuration, the routes, the events and the views in cache, read
# by the account of the application only; then PHP-FPM reloads, as OPcache
# would otherwise keep the previous configuration.
build_caches() {
    local release="$1"
    run artisan "$release" optimize
    run as_torsade "$release" find bootstrap/cache -type f -exec chmod 0640 {} +
}

# Makes $1 the current version at once: a new link, then a rename.
switch_current() {
    ln -sfn "$1" "$TORSADE_CURRENT.new"
    mv -T "$TORSADE_CURRENT.new" "$TORSADE_CURRENT"
}

reload_php() {
    run systemctl reload "$TORSADE_FPM_SERVICE"
}

# How long the workers of Nginx started before a reload may take to leave,
# in tenths of a second.
TORSADE_NGINX_RELOAD_WAIT=200

# Nginx reads its configuration again. systemctl only signals Nginx and
# returns before Nginx has done so: until the workers started before the
# reload are gone, one of them may still answer a request, the health check
# that follows included, with the previous configuration. Waits for them,
# 20 seconds at most. A client that keeps a connection open longer keeps an
# old worker alive: the reload goes on, says so in the journal, and the
# health check takes place all the same; an update must not fail on it.
# A configuration refused, or a reload that fails, is a failure of the
# function, whether or not the caller stops on errors.
reload_nginx() {
    local main pid waited=0 workers=()
    run nginx -t || return 1
    main="$(systemctl show --property MainPID --value nginx)"
    if command -v pgrep > /dev/null && [ "${main:-0}" -gt 0 ]; then
        mapfile -t workers < <(pgrep --parent "$main" || true)
    fi
    run systemctl reload nginx || return 1
    for pid in "${workers[@]}"; do
        while kill -0 "$pid" 2> /dev/null; do
            if [ "$waited" -ge "$TORSADE_NGINX_RELOAD_WAIT" ]; then
                warn "des processus de Nginx antérieurs au rechargement servent encore après 20 secondes : la suite a lieu quand même."
                return 0
            fi
            sleep 0.1
            waited=$((waited + 1))
        done
    done
}

# The health check of the application, through Nginx and TLS, as a browser
# of the machine would reach it.
check_health() {
    local host answer trust=()
    host="$(conf_get TORSADE_HOST "$TORSADE_SETTINGS")"

    # A self-signed certificate is its own authority; a certificate
    # provided is checked against the authority given at the installation,
    # or else against those of the system.
    if [ "$(conf_get TORSADE_TLS "$TORSADE_SETTINGS")" = "self-signed" ]; then
        trust=(--cacert "$TORSADE_TLS_CERT")
    elif [ -f "$TORSADE_TLS_CA" ]; then
        trust=(--cacert "$TORSADE_TLS_CA")
    fi

    # Straight to this machine: a proxy that the environment names for apt
    # would otherwise receive the request.
    answer="$(curl --silent --show-error --max-time 20 --noproxy '*' \
        --resolve "$host:443:127.0.0.1" "${trust[@]}" \
        "https://$host/up" 2>> "$torsade_log")" || return 1
    [ "$answer" = "OK" ]
}

# The configuration of production, checked by the application itself.
check_configuration() {
    artisan "$1" torsade:check
}

# --- Files managed by Torsade -----------------------------------------------

# Writes the files Torsade manages, from the deploy/ directory of a
# version: they are rewritten at each update. Those of the administrator
# (the .env file, nginx-local.conf, backup.conf) are only created when they
# are missing.
write_managed_files() {
    local deploy="$1" host ipv6 command unit

    host="$(conf_get TORSADE_HOST "$TORSADE_SETTINGS")"
    ipv6=no
    if [ -f /proc/net/if_inet6 ] && [ "$(cat /proc/sys/net/ipv6/conf/all/disable_ipv6 2> /dev/null || echo 1)" = "0" ]; then
        ipv6=yes
    fi

    # The commands of administration and what they share.
    install -d -m 0755 "$TORSADE_LIB"
    install -m 0644 "$deploy/lib/torsade.sh" "$TORSADE_LIB/torsade.sh"
    for command in "${TORSADE_COMMANDS[@]}"; do
        install -m 0755 "$deploy/bin/$command" "$TORSADE_SBIN/$command"
    done

    # PHP-FPM: the pool of the application alone, and the settings of
    # production.
    install -m 0644 "$deploy/php/torsade-pool.conf" "$TORSADE_FPM_POOL"
    install -m 0644 "$deploy/php/90-torsade.ini" "$TORSADE_FPM_INI"
    if [ -f "$TORSADE_FPM_DEFAULT_POOL" ]; then
        mv "$TORSADE_FPM_DEFAULT_POOL" "$TORSADE_FPM_DEFAULT_POOL.disabled"
    fi

    # Nginx: the site of the application alone.
    HOST="$host" IPV6="$ipv6" awk '
        /@IPV6@/ { if (ENVIRON["IPV6"] != "yes") next; sub(/@IPV6@/, "") }
        { gsub(/@HOST@/, ENVIRON["HOST"]); print }
    ' "$deploy/nginx/torsade.conf" > "$TORSADE_NGINX_SITE.new"
    chmod 0644 "$TORSADE_NGINX_SITE.new"
    mv -T "$TORSADE_NGINX_SITE.new" "$TORSADE_NGINX_SITE"
    ln -sfn "$TORSADE_NGINX_SITE" "$TORSADE_NGINX_ENABLED"
    rm -f "$TORSADE_NGINX_DEFAULT"
    if [ ! -f "$TORSADE_NGINX_LOCAL" ]; then
        install -m 0644 "$deploy/nginx/nginx-local.conf" "$TORSADE_NGINX_LOCAL"
    fi

    # The daily backup and the daily purge of the cache of the health check.
    for unit in "${TORSADE_UNITS[@]}"; do
        install -m 0644 "$deploy/systemd/$unit" "$TORSADE_SYSTEMD/$unit"
    done
    if [ ! -f "$TORSADE_BACKUP_CONF" ]; then
        install -m 0644 "$deploy/backup.conf" "$TORSADE_BACKUP_CONF"
    fi
    run systemctl daemon-reload
}

# Keeps the last versions, the current one always among them.
prune_releases() {
    local current release count=0
    current="$(readlink -f "$TORSADE_CURRENT")"
    while IFS= read -r release; do
        count=$((count + 1))
        if [ "$count" -gt "$TORSADE_KEEP_RELEASES" ] && [ "$TORSADE_RELEASES/$release" != "$current" ]; then
            run rm -rf "${TORSADE_RELEASES:?}/$release"
        fi
    done < <(find "$TORSADE_RELEASES" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -rV)
}
