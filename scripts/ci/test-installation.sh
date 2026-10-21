#!/usr/bin/env bash
# torsade/scripts/ci/test-installation.sh
#
# The test of the installation, run by the CI as root inside a Debian 13
# container that runs systemd, one scenario at a time:
#
#   bash test-installation.sh <scenario>
#
# The archive and its SHA256SUMS are in /root/release, the list of the PHP
# extensions the dependencies require in /root/platform/extensions.txt.
# What a scenario hands to the next one is kept in /root/ci-state. The
# commands the documentation of administration gives are run here as it
# gives them, root standing for sudo.
set -euo pipefail

state=/root/ci-state
mkdir -p "$state"

# The commands of the documentation use the name of the administrator who
# runs them; a command run through docker exec has none.
export USER="${USER:-root}"

archive="$(find /root/release -maxdepth 1 -name 'torsade-*.tar.gz' -print -quit)"
version="$(basename "$archive" .tar.gz)"
version="${version#torsade-}"
unpacked="/root/torsade-$version"

failures=0
failed=()

pass() {
    printf 'ok    %s\n' "$*"
}

fail() {
    printf 'FAIL  %s\n' "$*" >&2
    failures=$((failures + 1))
    failed+=("$*")
}

check() {
    local label="$1"
    shift
    if "$@"; then
        pass "$label"
    else
        fail "$label"
    fi
}

# Passes when the command fails.
check_not() {
    local label="$1"
    shift
    if "$@"; then
        fail "$label"
    else
        pass "$label"
    fi
}

# The checks that failed are named again at the end, where the log of the
# step is read first: their own lines may be far above.
finish() {
    if [ "$failures" -gt 0 ]; then
        printf '%s check(s) failed:\n' "$failures" >&2
        printf 'FAIL  %s\n' "${failed[@]}" >&2
        exit 1
    fi
}

host() {
    cat "$state/host"
}

# A request to the application through Nginx and TLS, trusting the
# certificate of the installation (self-signed) or the test authority.
web() {
    local trust=(--cacert /etc/torsade/tls/torsade.crt)
    if [ -f /etc/torsade/tls/authority.crt ]; then
        trust=(--cacert /etc/torsade/tls/authority.crt)
    fi
    curl --silent --show-error --max-time 30 --noproxy '*' --resolve "$(host):443:127.0.0.1" "${trust[@]}" "$@"
}

health() {
    web --output /dev/null --write-out '%{http_code}' "https://$(host)/up"
}

# The reload of the documentation, by the function the commands of Torsade
# use, from the library the installation puts on the machine: it waits for
# the workers of Nginx started before the reload to leave. Sourced in a
# subshell, so that nothing of the library mixes with this script.
reload_nginx() {
    (
        # shellcheck source=deploy/lib/torsade.sh
        . /usr/local/lib/torsade/torsade.sh
        reload_nginx
    )
}

# A command of the application, as the documentation gives it.
artisan() {
    (cd /opt/torsade/current && runuser -u torsade -- php8.4 artisan "$@")
}

sql() {
    mariadb --batch --skip-column-names torsade -e "$1"
}

current_version() {
    tr -d '[:space:]' < /opt/torsade/current/VERSION
}

# The names of the sites, in a digest: the data the tests change and expect
# back.
data_digest() {
    sql 'SELECT id, name FROM sites ORDER BY id' | sha256sum | cut -d ' ' -f 1
}

mode_of() {
    stat -c '%a %U:%G' "$1"
}

header() {
    # The value of a header in a file of headers, without its end of line.
    grep -i "^$1:" "$2" | sed -n 1p | cut -d ':' -f 2- | sed 's/^ *//' | tr -d '\r'
}

count_header() {
    grep -ic "^$1:" "$2" || true
}

# The token of the forms, from the XSRF-TOKEN cookie of the jar.
xsrf_token() {
    local value
    value="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$1" | tail -n 1)"
    printf '%b' "${value//%/\\x}"
}

# Signs in; the headers of the answer are left in $state/headers.
sign_in() {
    local email="$1" password="$2" jar="$state/cookies"
    rm -f "$jar"
    web --output /dev/null --cookie-jar "$jar" "https://$(host)/login"
    web --output /dev/null --dump-header "$state/headers" --cookie "$jar" --cookie-jar "$jar" \
        --header "X-XSRF-TOKEN: $(xsrf_token "$jar")" \
        --data-urlencode "email=$email" --data-urlencode "password=$password" \
        --data-urlencode remember=on "https://$(host)/login"
}

# An authority of the test, and a certificate it signs for a name.
make_certificate() {
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -noenc -days 30 -sha256 \
        -subj "/CN=Autorite de test" -keyout /root/authority.key -out /root/authority.crt 2> /dev/null
    openssl req -newkey ec -pkeyopt ec_paramgen_curve:P-256 -noenc -sha256 \
        -subj "/CN=$1" -keyout /root/server.key -out /root/server.csr 2> /dev/null
    printf '%s\n' 'basicConstraints = critical, CA:FALSE' 'keyUsage = critical, digitalSignature' \
        'extendedKeyUsage = serverAuth' "subjectAltName = DNS:$1" > /root/server.ext
    openssl x509 -req -in /root/server.csr -CA /root/authority.crt -CAkey /root/authority.key \
        -CAcreateserial -days 30 -sha256 -extfile /root/server.ext -out /root/server.crt 2> /dev/null
}

# --- Install ------------------------------------------------------------------

scenario_install() {
    check "the archive matches its SHA256SUMS" bash -c 'cd /root/release && sha256sum --check SHA256SUMS'
    (cd /root && tar -xzf "$archive")
    hostname -I
    hostname -I | awk '{ print $1 }' > "$state/host"
    check "the script tells its options" bash "$unpacked/deploy/install.sh" --help
    if bash "$unpacked/deploy/install.sh" --non-interactive --host > "$state/option.txt" 2>&1; then
        fail "an option without its value is refused"
    else
        pass "an option without its value is refused"
    fi
    check "and says so" grep -q "Option sans valeur : --host" "$state/option.txt"

    local options=(--non-interactive --host "$(host)"
        --admin-name "Camille Martin" --admin-email camille.martin@example.test)

    # A certificate whose name is another is refused, before the web
    # server is touched.
    make_certificate torsade.example.test
    if bash "$unpacked/deploy/install.sh" "${options[@]}" --certificate /root/server.crt \
        --key /root/server.key --authority /root/authority.crt > "$state/install-0.txt" 2>&1; then
        fail "a certificate for another name is refused"
    else
        pass "a certificate for another name is refused"
    fi
    cat "$state/install-0.txt"
    check "because it does not cover the name" grep -q "ne couvre pas le nom" "$state/install-0.txt"

    # Then an installation interrupted once the database, the key and the
    # migrations are done: another program holds the port of HTTPS.
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -noenc -days 1 \
        -subj /CN=occupant -keyout /root/occupant.key -out /root/occupant.crt 2> /dev/null
    setsid openssl s_server -quiet -accept 443 -cert /root/occupant.crt -key /root/occupant.key \
        < /dev/null > /dev/null 2>&1 &
    local occupant=$!
    sleep 1

    if bash "$unpacked/deploy/install.sh" "${options[@]}" --self-signed > "$state/install-1.txt" 2>&1; then
        fail "the installation stops when the port of HTTPS is taken"
    else
        pass "the installation stops when the port of HTTPS is taken"
    fi
    cat "$state/install-1.txt"
    check "the step that failed is named" grep -q "« PHP-FPM et Nginx »" "$state/install-1.txt"
    check "and how to resume" grep -q "relancez la même commande" "$state/install-1.txt"
    check "nothing is marked installed" test ! -e /etc/torsade/installed

    grep '^APP_KEY=' /etc/torsade/torsade.env | sha256sum > "$state/app-key"
    grep '^DB_PASSWORD=' /etc/torsade/torsade.env | sha256sum > "$state/db-password"
    sha256sum < /etc/torsade/database-migration.password > "$state/migration-password"

    kill "$occupant"
    wait "$occupant" 2> /dev/null || true

    # Run again, it resumes.
    if bash "$unpacked/deploy/install.sh" "${options[@]}" --self-signed > "$state/install-2.txt" 2>&1; then
        pass "the installation resumes and ends"
    else
        fail "the installation resumes and ends"
    fi
    cat "$state/install-2.txt"

    check "the key of the application is the one of the first attempt" \
        cmp -s "$state/app-key" <(grep '^APP_KEY=' /etc/torsade/torsade.env | sha256sum)
    check "the password of the database is the one of the first attempt" \
        cmp -s "$state/db-password" <(grep '^DB_PASSWORD=' /etc/torsade/torsade.env | sha256sum)
    check "the password of the migrations is the one of the first attempt" \
        cmp -s "$state/migration-password" <(sha256sum < /etc/torsade/database-migration.password)
    check "the installation is marked complete" test -f /etc/torsade/installed
    check "the summary gives the fingerprint of the certificate" grep -q "Empreinte SHA-256" "$state/install-2.txt"
    check "the file of the first administrator exists" test -f /root/torsade-premier-administrateur.txt

    awk '/Mot de passe temporaire|Temporary password/ { print $NF }' /root/torsade-premier-administrateur.txt \
        > "$state/admin-password"
    check "the temporary password is in its file" test -s "$state/admin-password"
    check_not "and not in what the installation printed" grep -qF -- "$(cat "$state/admin-password")" "$state/install-2.txt"
    finish
}

# --- Web ----------------------------------------------------------------------

scenario_web() {
    local headers="$state/headers"

    check "/up answers OK over HTTPS" test "$(web "https://$(host)/up")" = OK
    check "the probe of the documentation answers OK" \
        test "$(curl --fail --cacert /etc/torsade/tls/torsade.crt "https://$(host)/up")" = OK

    # From HTTP to HTTPS, with the headers of the site on the redirection.
    curl --silent --show-error --output /dev/null --dump-header "$headers" "http://$(host)/login"
    cat "$headers"
    check "HTTP redirects permanently" grep -q "^HTTP/1.1 301" "$headers"
    check "to HTTPS on the name of the application" \
        test "$(header Location "$headers")" = "https://$(host)/login"
    check "the redirection says nosniff" test "$(header X-Content-Type-Options "$headers")" = nosniff
    check "the redirection carries CORP" test "$(header Cross-Origin-Resource-Policy "$headers")" = same-origin
    check "the redirection hides the version" test "$(header Server "$headers")" = nginx

    # A page of the application.
    web --output /dev/null --dump-header "$headers" "https://$(host)/login"
    cat "$headers"
    check "the page answers 200" grep -q "^HTTP/2 200" "$headers"
    check "the policy has a nonce" grep -qi "^content-security-policy:.*'nonce-" "$headers"
    check_not "the policy has nothing unsafe" grep -qi "^content-security-policy:.*unsafe-" "$headers"
    check "no X-Powered-By" test "$(count_header X-Powered-By "$headers")" = 0
    check "Server without a version" test "$(header Server "$headers")" = nginx
    check "no HSTS with a self-signed certificate" test "$(count_header Strict-Transport-Security "$headers")" = 0
    local name
    for name in X-Content-Type-Options X-Robots-Tag Cross-Origin-Opener-Policy Cross-Origin-Resource-Policy; do
        check "$name once" test "$(count_header "$name" "$headers")" = 1
    done
    check "COOP same-origin" test "$(header Cross-Origin-Opener-Policy "$headers")" = same-origin

    # The cookies of production.
    local session xsrf
    session="$(grep -i '^set-cookie: __Host-torsade-session=' "$headers" | tr -d '\r' || true)"
    xsrf="$(grep -i '^set-cookie: XSRF-TOKEN=' "$headers" | tr -d '\r' || true)"
    check "session cookie prefixed __Host-" test -n "$session"
    check "session cookie Secure" grep -qi '; secure' <<< "$session"
    check "session cookie HttpOnly" grep -qi '; httponly' <<< "$session"
    check "session cookie SameSite=Lax" grep -qi '; samesite=lax' <<< "$session"
    check "session cookie on the path /" grep -qi '; path=/;' <<< "$session;"
    check_not "session cookie without a domain" grep -qi '; domain=' <<< "$session"
    check "XSRF-TOKEN cookie Secure" grep -qi '; secure' <<< "$xsrf"

    # A file of the build: a long cache, the headers of the site.
    local asset
    asset="$(find /opt/torsade/current/public/build/assets -name '*.css' -print -quit)"
    web --output /dev/null --dump-header "$headers" "https://$(host)/build/assets/$(basename "$asset")"
    cat "$headers"
    check "the file of the build answers 200" grep -q "^HTTP/2 200" "$headers"
    check "the file of the build is cached long" grep -qi "^cache-control: max-age=[0-9]\{8,\}" "$headers"
    check "the file of the build says nosniff" test "$(header X-Content-Type-Options "$headers")" = nosniff
    check "the file of the build carries CORP" test "$(header Cross-Origin-Resource-Policy "$headers")" = same-origin
    check "the file of the build carries COOP" test "$(header Cross-Origin-Opener-Policy "$headers")" = same-origin

    # No other PHP file runs, no hidden file is served.
    printf '<?php echo "executed";\n' > /opt/torsade/current/public/probe.php
    printf 'hidden\n' > /opt/torsade/current/public/.probe
    check "another PHP file is not found" \
        test "$(web --output "$state/probe" --write-out '%{http_code}' "https://$(host)/probe.php")" = 404
    check_not "another PHP file is not executed" grep -q executed "$state/probe"
    check "a hidden file is not served" \
        test "$(web --output /dev/null --write-out '%{http_code}' "https://$(host)/.probe")" = 404
    rm -f /opt/torsade/current/public/probe.php /opt/torsade/current/public/.probe

    finish
}

# --- Files, accounts, extensions, services --------------------------------------

scenario_files() {
    local release
    release="$(readlink -f /opt/torsade/current)"

    check "the current version is $version" test "$release" = "/opt/torsade/releases/$version"
    check "the code belongs to root" \
        test -z "$(find "$release" ! -user root ! -path "$release/bootstrap/cache*" -print -quit)"
    check "nobody else writes the code" \
        test -z "$(find "$release" -perm /022 ! -type l ! -path "$release/bootstrap/cache*" -print -quit)"
    check ".env readable by root and torsade only" test "$(mode_of /etc/torsade/torsade.env)" = "640 root:torsade"
    check "the cache of the start-up belongs to torsade" test "$(mode_of "$release/bootstrap/cache")" = "750 torsade:torsade"
    check "the files of the cache are 0640" test -z "$(find "$release/bootstrap/cache" -type f ! -perm 0640 -print -quit)"
    check "the configuration is cached" test -f "$release/bootstrap/cache/config.php"
    check "the data are closed to others" test "$(mode_of /var/lib/torsade)" = "750 torsade:torsade"
    check "the logs are closed to others" test "$(mode_of /var/log/torsade)" = "750 torsade:torsade"
    check "the logs of the application go to /var/log/torsade" test "$(readlink /var/lib/torsade/logs)" = /var/log/torsade
    check "the backups are root's" test "$(mode_of /var/backups/torsade)" = "700 root:root"
    check "the key of TLS is root's" test "$(mode_of /etc/torsade/tls/torsade.key)" = "600 root:root"
    check "the password of the migrations is root's" test "$(mode_of /etc/torsade/database-migration.password)" = "600 root:root"
    check "the journal of the installation is root's" test "$(mode_of /var/log/torsade-install.log)" = "600 root:root"
    check "the first administrator's file is root's" test "$(mode_of /root/torsade-premier-administrateur.txt)" = "600 root:root"
    check "only Nginx opens the socket of PHP-FPM" test "$(mode_of /run/php/torsade-fpm.sock)" = "600 www-data:www-data"
    # PHP-FPM starts its processes on demand: a request wakes one.
    health > /dev/null
    check "PHP-FPM runs as torsade" grep -qx torsade <<< "$(ps -o user= -C php-fpm8.4)"
    check "the default pool is off" test ! -e /etc/php/8.4/fpm/pool.d/www.conf
    check "the default site is off" test ! -e /etc/nginx/sites-enabled/default
    check "the account torsade has no shell" test "$(getent passwd torsade | cut -d ':' -f 7)" = /usr/sbin/nologin
    check "the commands are installed" \
        test -x /usr/local/sbin/torsade-update -a -x /usr/local/sbin/torsade-backup -a -x /usr/local/sbin/torsade-restore

    # MariaDB on the machine only.
    ss -ltn
    check_not "MariaDB listens on the machine only" grep -vE '^((127\.0\.0\.1|\[::1\]):3306)?$' \
        <<< "$(ss -ltnH | awk '$4 ~ /:3306$/ { print $4 }')"

    # The accounts of the database.
    mariadb --batch --skip-column-names -e "SHOW GRANTS FOR 'torsade'@'localhost'" \
        | sed 's/IDENTIFIED BY PASSWORD .*//' | tee "$state/grants"
    check "the account of the application holds SELECT, INSERT, UPDATE, DELETE on torsade.*" \
        grep -qx "GRANT SELECT, INSERT, UPDATE, DELETE ON \`torsade\`.\* TO \`torsade\`@\`localhost\`" "$state/grants"
    check "and nothing else" test "$(grep -vc '^GRANT USAGE ON \*\.\* TO' "$state/grants")" = 1
    mariadb --batch --skip-column-names -e "SHOW GRANTS FOR 'torsade_migration'@'localhost'" \
        | sed 's/IDENTIFIED BY PASSWORD .*//'

    # The extensions the dependencies require, for the command line and
    # for PHP-FPM.
    local extension
    while IFS= read -r extension; do
        check "PHP has $extension" php8.4 -r "exit(extension_loaded('$extension') ? 0 : 1);"
        check "PHP-FPM has $extension" grep -qix "$extension" <<< "$(php-fpm8.4 -m)"
    done < /root/platform/extensions.txt

    # The services, the timers and the journals, as the documentation
    # reads them; not a single cron job.
    check "PHP-FPM runs" systemctl status php8.4-fpm
    check "the journals of the services read" journalctl -u nginx -u php8.4-fpm -u mariadb --since today
    check "the logs of the application list" ls -l /var/log/torsade
    systemctl list-timers 'torsade-*' | tee "$state/timers"
    check "the backup timer is listed" grep -q torsade-backup.timer "$state/timers"
    check "the purge timer is listed" grep -q torsade-purge-health-cache.timer "$state/timers"
    check "the purge runs" systemctl start torsade-purge-health-cache.service
    check_not "no cron job of Torsade" grep -rqs torsade /etc/cron.d /var/spool/cron
    check_not "no automatic update without the option" test -e /etc/apt/apt.conf.d/52torsade-security-updates

    finish
}

# --- Sign in --------------------------------------------------------------------

scenario_login() {
    local jar="$state/cookies" headers="$state/headers" password new_password
    password="$(cat "$state/admin-password")"
    new_password="Essai-$(od -An -N 12 -tx1 /dev/urandom | tr -d ' \n')"

    sign_in camille.martin@example.test "$password"
    check "signing in redirects" grep -q "^HTTP/2 302" "$headers"
    check "the remember-me cookie is Secure" grep -qi '^set-cookie: remember_web_[^=]*=.*; secure' "$headers"

    web --output /dev/null --dump-header "$headers" --cookie "$jar" --cookie-jar "$jar" "https://$(host)/dashboard"
    check "the temporary password leads to its change" \
        test "$(header Location "$headers")" = "https://$(host)/change-password"

    web --output /dev/null --dump-header "$headers" --cookie "$jar" --cookie-jar "$jar" \
        --request PUT --header "X-XSRF-TOKEN: $(xsrf_token "$jar")" \
        --data-urlencode "current_password=$password" --data-urlencode "password=$new_password" \
        --data-urlencode "password_confirmation=$new_password" "https://$(host)/settings/password"
    check "the new password is accepted" grep -q "^HTTP/2 30[23]" "$headers"

    check "the home page opens" \
        test "$(web --output /dev/null --write-out '%{http_code}' --cookie "$jar" "https://$(host)/dashboard")" = 200
    # Its headers exceed the 4 KB Nginx gives them by default.
    check "the list of the sites opens" \
        test "$(web --output /dev/null --write-out '%{http_code}' --cookie "$jar" "https://$(host)/sites")" = 200
    printf '%s' "$new_password" > "$state/admin-password"

    # Neither the temporary password nor those of the database appear in
    # what the installation printed, in its journal, in the logs of the
    # application or in the journal of the system.
    local secret secrets=("$password" "$new_password")
    secrets+=("$(sed -n 's/^DB_PASSWORD=//p' /etc/torsade/torsade.env)")
    secrets+=("$(cat /etc/torsade/database-migration.password)")
    journalctl --no-pager > "$state/journal.txt"
    for secret in "${secrets[@]}"; do
        check_not "a password stays out of the outputs and the journals" grep -rqF -- "$secret" \
            "$state/install-0.txt" "$state/install-1.txt" "$state/install-2.txt" \
            /var/log/torsade-install.log /var/log/torsade "$state/journal.txt"
    done
    rm -f "$state/journal.txt"

    finish
}

# --- torsade:check, configuration, maintenance ------------------------------------

scenario_check() {
    cd /opt/torsade/current
    check "torsade:check passes" runuser -u torsade -- php8.4 artisan torsade:check

    # A change of the configuration, as the documentation gives it.
    sed -i 's/^APP_DEBUG=.*/APP_DEBUG=true/' /etc/torsade/torsade.env
    runuser -u torsade -- bash -c 'umask 0027 && php8.4 artisan optimize'
    systemctl reload php8.4-fpm
    if runuser -u torsade -- php8.4 artisan torsade:check > "$state/check.txt" 2>&1; then
        fail "torsade:check fails with APP_DEBUG true"
    else
        pass "torsade:check fails with APP_DEBUG true"
    fi
    cat "$state/check.txt"
    check "and says what to correct" grep -q "Mettez APP_DEBUG à false" "$state/check.txt"

    sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' /etc/torsade/torsade.env
    check ".env keeps its owner and mode" test "$(mode_of /etc/torsade/torsade.env)" = "640 root:torsade"
    runuser -u torsade -- bash -c 'umask 0027 && php8.4 artisan optimize'
    systemctl reload php8.4-fpm
    check "torsade:check passes again" runuser -u torsade -- php8.4 artisan torsade:check
    check "the files of the cache stay 0640" \
        test -z "$(find /opt/torsade/current/bootstrap/cache -type f ! -perm 0640 -print -quit)"
    check "/up answers OK" test "$(health)" = 200

    # Maintenance.
    runuser -u torsade -- php8.4 artisan down
    check "in maintenance, /up answers 503" test "$(health)" = 503
    runuser -u torsade -- php8.4 artisan up
    check "out of it, /up answers 200" test "$(health)" = 200

    finish
}

# --- Resume and refusal -----------------------------------------------------------

scenario_resume() {
    local options=(--non-interactive --host "$(host)" --self-signed
        --admin-name "Autre Administrateur" --admin-email autre@example.test)

    # As if the installation had stopped after the first administrator.
    rm /etc/torsade/installed
    if bash "$unpacked/deploy/install.sh" "${options[@]}" > "$state/install-3.txt" 2>&1; then
        pass "the installation resumes after the first administrator"
    else
        fail "the installation resumes after the first administrator"
    fi
    cat "$state/install-3.txt"
    check "no second administrator" test "$(sql "SELECT COUNT(*) FROM users WHERE role = 'admin'")" = 1
    check "the key of the application stays" \
        cmp -s "$state/app-key" <(grep '^APP_KEY=' /etc/torsade/torsade.env | sha256sum)

    if bash "$unpacked/deploy/install.sh" "${options[@]}" > "$state/install-4.txt" 2>&1; then
        fail "a complete installation refuses to run again"
    else
        pass "a complete installation refuses to run again"
    fi
    cat "$state/install-4.txt"
    check "and points to the update" grep -q "torsade-update" "$state/install-4.txt"
    check_not "without asking to run it again" grep -q "relancez la même commande" "$state/install-4.txt"
    check "/up answers OK" test "$(health)" = 200

    finish
}

# --- Backup and restoration on the same machine -------------------------------------

scenario_backup() {
    # A few fictitious sites.
    sql "INSERT INTO sites (name, created_at, updated_at) VALUES ('Site Essai Nord', NOW(), NOW()), ('Site Essai Sud', NOW(), NOW()), ('Site Essai Est', NOW(), NOW())"
    data_digest > "$state/data"

    # The service the timer starts each night.
    check "the service of the daily backup runs" systemctl start torsade-backup.service
    journalctl -t torsade-backup | tee "$state/journal.txt"
    check "the journal of the system tells the backup" grep -q 'Sauvegarde réussie' "$state/journal.txt"

    local backup
    backup="$(torsade-backup)"
    echo "$backup" > "$state/backup-path"
    ls -l "$backup"
    check "the backup is a directory of root" test "$(mode_of "$backup")" = "700 root:root"
    check "its files are root's" test -z "$(find "$backup" -type f ! -perm 0600 -print -quit)"
    check "it holds the key of the application" grep -q '^APP_KEY=' "$backup/configuration.env"
    check_not "it holds no password of the database" grep -qE \
        "DB_PASSWORD|$(sed -n 's/^DB_PASSWORD=//p' /etc/torsade/torsade.env)|$(cat /etc/torsade/database-migration.password)" \
        "$backup/configuration.env" "$backup/backup.info"

    sql "UPDATE sites SET name = CONCAT(name, ' modifié')"
    check "the data changed" test "$(data_digest)" != "$(cat "$state/data")"

    if torsade-restore --yes "$backup"; then
        pass "the backup is restored"
    else
        fail "the backup is restored"
    fi
    check "the data are back" test "$(data_digest)" = "$(cat "$state/data")"
    check "/up answers OK" test "$(health)" = 200
    journalctl -t torsade-restore | tee "$state/journal.txt"
    check "the journal of the system tells the restoration" grep -q 'réussie' "$state/journal.txt"

    # Gathered in one file as the documentation does, for a rebuild on a
    # new machine.
    tar -C /var/backups/torsade -cf ~/torsade-sauvegarde.tar "$(basename "$backup")"
    chown "$USER": ~/torsade-sauvegarde.tar
    chmod 600 ~/torsade-sauvegarde.tar
    check "the gathered backup is its owner's only" test "$(mode_of ~/torsade-sauvegarde.tar)" = "600 root:root"
    cp ~/torsade-sauvegarde.tar "$state/torsade-sauvegarde.tar"

    finish
}

# --- Updates ----------------------------------------------------------------------

# An archive of a higher number, made from the one of the release, changed
# by the function given, with its SHA256SUMS in a directory of its own.
make_archive() {
    local new_version="$1" change="${2:-}" work directory
    work="$(mktemp -d)"
    directory="/root/updates/$new_version"
    rm -rf "$directory"
    mkdir -p "$directory"
    tar -xzf "$archive" -C "$work"
    mv "$work/torsade-$version" "$work/torsade-$new_version"
    printf '%s\n' "$new_version" > "$work/torsade-$new_version/VERSION"
    if [ -n "$change" ]; then
        "$change" "$work/torsade-$new_version"
    fi
    tar --owner=0 --group=0 --numeric-owner -czf "$directory/torsade-$new_version.tar.gz" -C "$work" "torsade-$new_version"
    (cd "$directory" && sha256sum "torsade-$new_version.tar.gz" > SHA256SUMS)
    rm -rf "$work"
    printf '%s' "$directory/torsade-$new_version.tar.gz"
}

backups_count() {
    find /var/backups/torsade -mindepth 1 -maxdepth 1 -type d | wc -l
}

next_version() {
    printf '%s.%s' "${1%.*}" "$((${1##*.} + $2))"
}

# A migration the updates bring: a table of their own.
add_probe_migration() {
    cat > "$1/database/migrations/2099_01_01_000001_create_update_probes_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_probes', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_probes');
    }
};
EOF
}

scenario_update() {
    local step target update before
    for step in 1 2 3; do
        target="$(next_version "$version" "$step")"
        update="$(make_archive "$target" add_probe_migration)"
        before="$(backups_count)"
        if (cd "$(dirname "$update")" && torsade-update "torsade-$target.tar.gz"); then
            pass "update to $target"
        else
            fail "update to $target"
        fi
        check "the version served is $target" test "$(current_version)" = "$target"
        check "a backup was made first" test "$(backups_count)" -gt "$before"
        check "the data stay" test "$(data_digest)" = "$(cat "$state/data")"
        check "/up answers OK" test "$(health)" = 200
        check "torsade:check passes" artisan torsade:check
    done
    ls /opt/torsade/releases
    check "three versions at most" test "$(find /opt/torsade/releases -mindepth 1 -maxdepth 1 -type d | wc -l)" -le 3
    check "the migration of the updates ran" test "$(sql "SHOW TABLES LIKE 'update_probes'")" = update_probes
    current_version > "$state/version"

    # A backup of the first version, restored on the last one: followed by
    # the migrations, with the account that may change the schema.
    if torsade-restore --yes "$(cat "$state/backup-path")"; then
        pass "a backup of an older version is restored"
    else
        fail "a backup of an older version is restored"
    fi
    check "its migrations ran" test "$(sql "SHOW TABLES LIKE 'update_probes'")" = update_probes
    check "the version in service stays" test "$(current_version)" = "$(cat "$state/version")"
    check "the data stay" test "$(data_digest)" = "$(cat "$state/data")"
    check "/up answers OK" test "$(health)" = 200

    finish
}

# A migration that fails.
add_failing_migration() {
    cat > "$1/database/migrations/2099_01_01_000000_fail_on_purpose.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('This migration fails on purpose.');
    }
};
EOF
}

# A version without the skeleton of its storage.
remove_storage() {
    rm -rf "$1/storage"
}

# A version whose every page fails.
break_entry_point() {
    printf '<?php\nhttp_response_code(500);\n' > "$1/public/index.php"
}

scenario_update_failures() {
    local served target update output before
    served="$(cat "$state/version")"
    target="$(next_version "$served" 1)"

    # A digest that does not match: refused, nothing touched.
    update="$(make_archive "$target")"
    printf '%064d  %s\n' 0 "$(basename "$update")" > "$(dirname "$update")/SHA256SUMS"
    before="$(backups_count)"
    if torsade-update "$update" > "$state/update.txt" 2>&1; then
        fail "an archive with a wrong digest is refused"
    else
        pass "an archive with a wrong digest is refused"
    fi
    cat "$state/update.txt"
    check "nothing changed" test "$(current_version)" = "$served" -a ! -e "/opt/torsade/releases/$target"
    check "no backup was made" test "$(backups_count)" = "$before"
    check "not in maintenance" test "$(health)" = 200

    # Before the migrations: the backup cannot be made.
    update="$(make_archive "$target")"
    chmod 0644 /usr/bin/mariadb-dump
    if torsade-update "$update" > "$state/update.txt" 2>&1; then
        fail "an update whose backup fails stops"
    else
        pass "an update whose backup fails stops"
    fi
    chmod 0755 /usr/bin/mariadb-dump
    cat "$state/update.txt"
    check "the version in place is served again" test "$(current_version)" = "$served" -a "$(health)" = 200
    check "the new version was never placed" test ! -e "/opt/torsade/releases/$target"

    # Before the migrations, once the new version is placed: its storage is
    # missing from the archive.
    update="$(make_archive "$target" remove_storage)"
    if torsade-update "$update" > "$state/update.txt" 2>&1; then
        fail "an update whose version cannot be put in place stops"
    else
        pass "an update whose version cannot be put in place stops"
    fi
    cat "$state/update.txt"
    check "the version in place is served again" test "$(current_version)" = "$served" -a "$(health)" = 200
    check "out of maintenance" test ! -e /var/lib/torsade/framework/down
    check "the new version is removed" test ! -e "/opt/torsade/releases/$target"
    check "no marker of a failed update" test ! -e /etc/torsade/update-failed

    # During the migrations: maintenance, and the command that restores.
    update="$(make_archive "$target" add_failing_migration)"
    if torsade-update "$update" > "$state/update.txt" 2>&1; then
        fail "an update whose migration fails stops"
    else
        pass "an update whose migration fails stops"
    fi
    cat "$state/update.txt"
    check "the application stays in maintenance" test "$(health)" = 503
    check "the failure is marked" test -s /etc/torsade/update-failed
    if torsade-backup > "$state/refused.txt" 2>&1; then
        fail "no backup while the failure is marked"
    else
        pass "no backup while the failure is marked"
    fi
    cat "$state/refused.txt"
    check "the backup says it is refused, and what to restore" \
        grep -q "Sauvegarde refusée : .*sudo torsade-restore /var/backups/torsade/" "$state/refused.txt"
    check_not "and not that it failed" grep -q "la sauvegarde a échoué" "$state/refused.txt"
    if torsade-update "$update" > "$state/refused.txt" 2>&1; then
        fail "no update while the failure is marked"
    else
        pass "no update while the failure is marked"
    fi
    cat "$state/refused.txt"
    check "the update says what to restore" grep -q "sudo torsade-restore /var/backups/torsade/" "$state/refused.txt"
    output="$(sed -n 's#.*\(torsade-restore /var/backups/torsade/[^ ]*\).*#\1#p' "$state/update.txt" | sed -n 1p)"
    check "the message gives the command that restores" test -n "$output"
    if torsade-restore --yes "${output#torsade-restore }"; then
        pass "the restoration gives the application back"
    else
        fail "the restoration gives the application back"
    fi
    check "the version in place is served" test "$(current_version)" = "$served" -a "$(health)" = 200
    check "the data stay" test "$(data_digest)" = "$(cat "$state/data")"
    check "the restoration removes the mark of the failure" test ! -e /etc/torsade/update-failed

    # After the switch: maintenance, and the command that goes back.
    target="$(next_version "$served" 2)"
    update="$(make_archive "$target" break_entry_point)"
    if torsade-update "$update" > "$state/update.txt" 2>&1; then
        fail "an update whose version fails once switched stops"
    else
        pass "an update whose version fails once switched stops"
    fi
    cat "$state/update.txt"
    check "the new version stays in place" test "$(current_version)" = "$target"
    check "in maintenance" test -e /var/lib/torsade/framework/down
    check "the failure is marked" test -s /etc/torsade/update-failed
    output="$(sed -n "s#.*\(torsade-restore --release $served /var/backups/torsade/[^ ]*\).*#\1#p" "$state/update.txt" | sed -n 1p)"
    check "the message gives the command that goes back" test -n "$output"
    # The command as the message gives it, words apart.
    # shellcheck disable=SC2086
    if torsade-restore --yes ${output#torsade-restore }; then
        pass "going back gives the application back"
    else
        fail "going back gives the application back"
    fi
    check "the previous version is served" test "$(current_version)" = "$served" -a "$(health)" = 200
    check "going back removes the mark of the failure" test ! -e /etc/torsade/update-failed
    check "the data stay" test "$(data_digest)" = "$(cat "$state/data")"
    check "torsade:check passes" artisan torsade:check

    finish
}

# --- Accounts ---------------------------------------------------------------------

scenario_accounts() {
    cd /opt/torsade/current
    check_not "an unknown account is refused" runuser -u torsade -- php8.4 artisan torsade:reset-password autre-compte@example.test
    if runuser -u torsade -- php8.4 artisan torsade:reset-password camille.martin@example.test > "$state/accounts.txt" 2>&1; then
        pass "a temporary password is given"
    else
        fail "a temporary password is given"
    fi
    check "and shown" grep -q "Mot de passe temporaire" "$state/accounts.txt"
    check "the second factor is removed" runuser -u torsade -- php8.4 artisan torsade:reset-two-factor camille.martin@example.test
    finish
}

# --- Reverse proxy ------------------------------------------------------------------

# A Nginx in front of Torsade, on another port of the machine, as an
# organisation would place its own: HTTPS with the certificate of the
# installation, the original Host passed on, and, inside the location, the
# lines of the file given.
# shellcheck disable=SC2016
front_proxy() {
    {
        printf '%s\n' 'server {' '    listen 127.0.0.1:8443 ssl;' "    server_name $(host);" \
            '    ssl_certificate /etc/torsade/tls/torsade.crt;' '    ssl_certificate_key /etc/torsade/tls/torsade.key;' \
            '    location / {' '        proxy_pass https://127.0.0.1;' '        proxy_set_header Host $host;' \
            '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;' '        proxy_set_header X-Forwarded-Proto https;'
        sed 's/^/        /' "$1"
        printf '%s\n' '    }' '}'
    } > /etc/nginx/conf.d/torsade-front-proxy.conf
}

# A request to the application through the Nginx in front of it.
front() {
    curl --silent --show-error --max-time 30 --noproxy '*' --resolve "$(host):8443:127.0.0.1" \
        --cacert /etc/torsade/tls/torsade.crt "$@"
}

scenario_proxy() {
    cp /etc/torsade/nginx-local.conf "$state/nginx-local.conf"
    cp -p /etc/torsade/torsade.env "$state/torsade.env"

    # A proxy on the machine itself, declared to the application and to
    # Nginx as the documentation says.
    sed -i 's/^TRUSTED_PROXIES=.*/TRUSTED_PROXIES=127.0.0.1/' /etc/torsade/torsade.env
    printf '%s\n' 'set_real_ip_from 127.0.0.1;' 'real_ip_header X-Forwarded-For;' >> /etc/torsade/nginx-local.conf
    (cd /opt/torsade/current && runuser -u torsade -- bash -c 'umask 0027 && php8.4 artisan optimize')
    systemctl reload php8.4-fpm
    check "Nginx accepts the configuration" nginx -t
    check "Nginx reads it again" reload_nginx

    check "/up answers OK behind the proxy" \
        test "$(web --output /dev/null --write-out '%{http_code}' --header 'X-Forwarded-For: 192.0.2.50' "https://$(host)/up")" = 200
    tail -n 1 /var/log/nginx/access.log
    check "Nginx logs the real address" grep -q '^192\.0\.2\.50 ' /var/log/nginx/access.log

    # A Nginx placed in front of Torsade, with the room for the headers the
    # documentation gives it: the pages of a user signed in, whose headers
    # exceed 4 KB, go through it.
    awk '/^ *```nginx$/ { inside = 1; block = ""; next }
        inside && /^ *```$/ { inside = 0; if (block ~ /proxy_buffer_size/) { printf "%s", block; exit } next }
        inside { sub(/^ +/, ""); block = block $0 "\n" }' \
        "$unpacked/documentation/administration/securite.md" > "$state/proxy-buffers.conf"
    cat "$state/proxy-buffers.conf"
    check "the documentation gives the room for headers of a proxy" grep -q '^proxy_buffer_size ' "$state/proxy-buffers.conf"
    front_proxy "$state/proxy-buffers.conf"
    check "Nginx accepts the proxy in front" nginx -t
    check "and reads it" reload_nginx

    # The account whose password the command gave, signed in through the
    # proxy; its temporary password is changed at once.
    local jar="$state/front-cookies" headers="$state/front-headers" password new_password
    password="$(awk '/Mot de passe temporaire/ { print $NF }' "$state/accounts.txt")"
    new_password="Essai-$(od -An -N 12 -tx1 /dev/urandom | tr -d ' \n')"
    rm -f "$jar"
    front --output /dev/null --cookie-jar "$jar" "https://$(host):8443/login"
    front --output /dev/null --dump-header "$headers" --cookie "$jar" --cookie-jar "$jar" \
        --header "X-XSRF-TOKEN: $(xsrf_token "$jar")" \
        --data-urlencode "email=camille.martin@example.test" --data-urlencode "password=$password" \
        "https://$(host):8443/login"
    check "signing in through the proxy redirects" grep -q "^HTTP/1.1 302" "$headers"
    front --output /dev/null --dump-header "$headers" --cookie "$jar" --cookie-jar "$jar" \
        --request PUT --header "X-XSRF-TOKEN: $(xsrf_token "$jar")" \
        --data-urlencode "current_password=$password" --data-urlencode "password=$new_password" \
        --data-urlencode "password_confirmation=$new_password" "https://$(host):8443/settings/password"
    check "the password is changed through the proxy" grep -q "^HTTP/1.1 30[23]" "$headers"
    check "the list of the sites opens through the proxy" \
        test "$(front --output /dev/null --write-out '%{http_code}' --cookie "$jar" "https://$(host):8443/sites")" = 200

    # Without that room, the proxy cannot take the headers of the page.
    front_proxy /dev/null
    check "Nginx reads the proxy without the room for headers" reload_nginx
    check "and the proxy then answers 502" \
        test "$(front --output /dev/null --write-out '%{http_code}' --cookie "$jar" "https://$(host):8443/sites")" = 502
    rm -f /etc/nginx/conf.d/torsade-front-proxy.conf "$jar" "$headers"

    cp "$state/nginx-local.conf" /etc/torsade/nginx-local.conf
    cp -p "$state/torsade.env" /etc/torsade/torsade.env
    (cd /opt/torsade/current && runuser -u torsade -- bash -c 'umask 0027 && php8.4 artisan optimize')
    systemctl reload php8.4-fpm
    nginx -t
    check "Nginx reads its configuration again" reload_nginx
    check "torsade:check passes" artisan torsade:check
    finish
}

# --- nftables -------------------------------------------------------------------

scenario_nftables() {
    # The example of the documentation, between its fences, put in place
    # and turned on as the documentation says.
    awk '/^```nftables$/ { inside = 1; next } inside && /^```$/ { exit } inside' \
        "$unpacked/documentation/administration/securite.md" > /etc/nftables.conf
    cat /etc/nftables.conf
    check "the documentation holds an example of nftables" test -s /etc/nftables.conf
    check "the example of nftables is valid" nft --check --file /etc/nftables.conf
    check "the firewall turns on" systemctl enable --now nftables
    nft list ruleset | tee "$state/ruleset"
    check "it drops what it does not allow" grep -q 'policy drop' "$state/ruleset"
    check "it keeps SSH" grep -q 'tcp dport 22 accept' "$state/ruleset"
    check "/up answers OK behind the firewall" test "$(health)" = 200
    finish
}

# --- A second machine: a certificate of an authority and HSTS, then a ----------
# --- rebuild --------------------------------------------------------------------

scenario_authority() {
    check "the archive matches its SHA256SUMS" bash -c 'cd /root/release && sha256sum --check SHA256SUMS'
    (cd /root && tar -xzf "$archive")
    echo torsade.example.test > "$state/host"
    make_certificate torsade.example.test

    # The command of the documentation, as it is written.
    if (cd "$unpacked" && bash deploy/install.sh --non-interactive --host torsade.example.test \
        --certificate /root/server.crt --key /root/server.key --authority /root/authority.crt --hsts --security-updates \
        --admin-name "Camille Martin" --admin-email camille.martin@example.test) > "$state/install-1.txt" 2>&1; then
        pass "the installation with a certificate of an authority and HSTS ends"
    else
        fail "the installation with a certificate of an authority and HSTS ends"
    fi
    cat "$state/install-1.txt"
    check "the reminder of HSTS is shown" grep -q "ne pourra plus ouvrir l'application" "$state/install-1.txt"

    # The security updates of Debian, and nothing else.
    apt-config dump | grep -E 'Unattended-Upgrade::Origins-Pattern|APT::Periodic::(Update-Package-Lists|Unattended-Upgrade) ' | tee "$state/apt"
    check "unattended-upgrades is installed" dpkg -s unattended-upgrades
    check "the security updates are installed each day" grep -q 'APT::Periodic::Unattended-Upgrade "1";' "$state/apt"
    check "from the security archive only" \
        test "$(grep -c '^Unattended-Upgrade::Origins-Pattern:: ' "$state/apt")" = 1
    # unattended-upgrades, not apt, puts the name of the release in place.
    # shellcheck disable=SC2016
    check "which is the security archive" grep -qF 'codename=${distro_codename}-security,label=Debian-Security' "$state/apt"

    local headers="$state/headers"
    web --output /dev/null --dump-header "$headers" "https://torsade.example.test/login"
    cat "$headers"
    check "HSTS for a year, without subdomains nor preload" \
        test "$(header Strict-Transport-Security "$headers")" = "max-age=31536000"
    check "/up answers OK" test "$(health)" = 200
    check "torsade:check passes" artisan torsade:check

    # The probe of the documentation, as it is written, the name known to
    # the machine.
    echo "127.0.0.1 torsade.example.test" >> /etc/hosts
    check "the probe of the documentation answers OK" \
        test "$(cd /etc/torsade/tls && curl --fail --cacert authority.crt https://torsade.example.test/up)" = OK
    finish
}

# --- A third machine: at a terminal, as in the trial in a virtual machine ---------

scenario_terminal() {
    check "the archive matches its SHA256SUMS" bash -c 'cd /root/release && sha256sum --check SHA256SUMS'
    (cd /root && tar -xzf "$archive")
    hostname -I | awk '{ print $1 }' > "$state/host"

    # The installation as an administrator runs it, answering its
    # questions at a terminal: the address of the machine as name, a
    # self-signed certificate.
    cat > /root/install.exp << 'EOF'
set timeout 1800
proc answer {pattern reply} {
    expect -gl $pattern { send -- "$reply\r" } timeout { puts "\nNo question: $pattern"; exit 2 } eof { puts "\nEnded before: $pattern"; exit 3 }
}
spawn bash deploy/install.sh
answer "Nom de l'application" $env(HOST)
answer "Avez-vous un certificat" "non"
answer "Activer les mises" "non"
answer "Nom complet" "Camille Martin"
answer "Son adresse" "camille.martin@example.test"
expect eof
catch wait result
exit [lindex $result 3]
EOF
    if (cd "$unpacked" && HOST="$(host)" expect /root/install.exp) > "$state/install-1.txt" 2>&1; then
        pass "the installation at a terminal ends"
    else
        fail "the installation at a terminal ends"
    fi
    # Shown here without the password it showed once.
    grep -v "Mot de passe temporaire" "$state/install-1.txt"
    check "the temporary password was shown once" test "$(grep -c "Mot de passe temporaire" "$state/install-1.txt")" = 1
    check_not "no file of the first administrator" test -e /root/torsade-premier-administrateur.txt
    check_not "the password stays out of the journal" grep -qF -- \
        "$(awk '/Mot de passe temporaire/ { print $NF }' "$state/install-1.txt" | tr -d '\r')" /var/log/torsade-install.log
    check "the summary gives the fingerprint of the certificate" grep -q "Empreinte SHA-256" "$state/install-1.txt"
    check "/up answers OK" test "$(health)" = 200
    check "torsade:check passes" artisan torsade:check
    finish
}

scenario_rebuild() {
    local backup app_url
    cd /root/machine-1
    tar -C /var/backups/torsade -xf torsade-sauvegarde.tar
    backup="/var/backups/torsade/$(tar -tf torsade-sauvegarde.tar | awk -F / 'NR == 1 { print $1 }')"
    app_url="$(grep '^APP_URL=' /etc/torsade/torsade.env)"

    if torsade-restore --configuration --yes "$backup"; then
        pass "the backup of the first machine is restored on a new one"
    else
        fail "the backup of the first machine is restored on a new one"
    fi
    check "the data are those of the first machine" test "$(data_digest)" = "$(cat /root/machine-1/data)"
    check "the key of the application is that of the first machine" \
        cmp -s /root/machine-1/app-key <(grep '^APP_KEY=' /etc/torsade/torsade.env | sha256sum)
    check "the address stays that of this machine" test "$(grep '^APP_URL=' /etc/torsade/torsade.env)" = "$app_url"
    check "HSTS stays on" grep -qx 'HSTS_ENABLED=true' /etc/torsade/torsade.env
    check "/up answers OK" test "$(health)" = 200
    check "torsade:check passes" artisan torsade:check

    # The administrator of the first machine signs in with their password.
    sign_in camille.martin@example.test "$(cat /root/machine-1/admin-password)"
    check "the administrator of the first machine signs in" \
        test "$(header Location "$state/headers")" = "https://$(host)/dashboard"
    finish
}

case "${1:-}" in
    install) scenario_install ;;
    web) scenario_web ;;
    files) scenario_files ;;
    login) scenario_login ;;
    check) scenario_check ;;
    resume) scenario_resume ;;
    backup) scenario_backup ;;
    update) scenario_update ;;
    update-failures) scenario_update_failures ;;
    accounts) scenario_accounts ;;
    proxy) scenario_proxy ;;
    nftables) scenario_nftables ;;
    authority) scenario_authority ;;
    rebuild) scenario_rebuild ;;
    terminal) scenario_terminal ;;
    *)
        echo "Usage: $0 install|web|files|login|check|resume|backup|update|update-failures|accounts|proxy|nftables|authority|rebuild|terminal" >&2
        exit 2
        ;;
esac
