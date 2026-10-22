#!/usr/bin/env bash
# torsade/scripts/check-archive.sh
#
# Checks the archive of a release against the list of what it may hold:
#
#   scripts/check-archive.sh <directory>/torsade-X.Y.Z.tar.gz
#
# Fails on any path outside the list, on an entry that root does not own or
# that others than its owner may write, on a script of the installation
# that cannot run or holds a carriage return, and when a file the
# installation needs is missing. Checks the SHA256SUMS next to the archive
# when there is one, and prints the size of the archive.
set -euo pipefail

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <directory>/torsade-X.Y.Z.tar.gz" >&2
    exit 2
fi

archive="$1"
file="$(basename "$archive")"

if ! [[ "$file" =~ ^torsade-([0-9]+\.[0-9]+\.[0-9]+)\.tar\.gz$ ]]; then
    echo "The name of the archive is not torsade-X.Y.Z.tar.gz: $file" >&2
    exit 1
fi

version="${BASH_REMATCH[1]}"
top="torsade-$version"
failures=0

fail() {
    echo "FAIL: $*" >&2
    failures=$((failures + 1))
}

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# One name per line, then the same entries with their mode and owner.
tar -tzf "$archive" > "$work/names"
tar -tvzf "$archive" --numeric-owner > "$work/details"

# The paths the archive may hold, below its top directory: the trees and
# files of the application, and the empty skeleton of the places it writes
# to, directories only.
trees='^(app|config|database/migrations|lang|public|resources/views|routes|vendor|deploy|documentation)/'
files='^(artisan|bootstrap/app\.php|bootstrap/providers\.php|composer\.json|composer\.lock|VERSION|README\.md|CHANGELOG\.md|LICENSE|SECURITY\.md)$'
dirs='^(bootstrap|bootstrap/cache|database|resources|storage|storage/app|storage/app/private|storage/app/public|storage/framework|storage/framework/cache|storage/framework/cache/data|storage/framework/sessions|storage/framework/views|storage/logs)/$'

# Never, even inside a tree of the list.
never='(^|/)\.|(^|/)node_modules(/|$)|^public/(hot|storage)(/|$)'

while IFS= read -r entry; do
    if [ "$entry" = "$top/" ]; then
        continue
    fi

    if [[ "$entry" != "$top/"* ]]; then
        fail "outside the top directory $top: $entry"
        continue
    fi

    path="${entry#"$top/"}"

    if [[ "$path" =~ $never ]]; then
        fail "never shipped: $path"
    elif ! [[ "$path" =~ $trees || "$path" =~ $files || "$path" =~ $dirs ]]; then
        fail "not in the list of the archive: $path"
    fi
done < "$work/names"

# Root owns every entry, and only the owner may write.
while read -r _ owner _; do
    if [ "$owner" != "0/0" ]; then
        fail "an entry is owned by $owner, not by root"
        break
    fi
done < "$work/details"

awk 'substr($1, 6, 1) == "w" || substr($1, 9, 1) == "w"' "$work/details" > "$work/writable"

if [ -s "$work/writable" ]; then
    fail "$(wc -l < "$work/writable") entries others may write, the first ones:"
    head -n 20 "$work/writable" >&2
fi

# What the installation cannot do without, and what must travel with every
# copy: the licence, and how to report a vulnerability.
required=(
    artisan
    composer.json
    VERSION
    LICENSE
    SECURITY.md
    vendor/autoload.php
    public/index.php
    public/build/manifest.json
    public/build/assets/barlow-LICENSE.txt
    public/build/assets/ibm-plex-mono-LICENSE.txt
    public/build/assets/elkjs-LICENSE.txt
    deploy/install.sh
    deploy/upgrade.sh
    deploy/lib/torsade.sh
    deploy/bin/torsade-update
    deploy/bin/torsade-backup
    deploy/bin/torsade-restore
    documentation/administration/installation.md
)

for path in "${required[@]}"; do
    if ! grep -qxF "$top/$path" "$work/names"; then
        fail "missing: $path"
    fi
done

if grep -qxF "$top/VERSION" "$work/names"; then
    shipped="$(tar -xzOf "$archive" "$top/VERSION" | tr -d '[:space:]')"

    if [ "$shipped" != "$version" ]; then
        fail "VERSION holds $shipped, the name of the archive says $version"
    fi
fi

# The files of the installation are read on Debian: LF only. Its scripts
# run: deploy/*.sh and the commands of deploy/bin.
while read -r mode _ _ _ _ name; do
    if [[ "$mode" == -* && "$name" == "$top/deploy/"* ]]; then
        if [ "$(tar -xzOf "$archive" "$name" | tr -dc '\r' | wc -c)" -gt 0 ]; then
            fail "carriage return in: ${name#"$top/"}"
        fi

        if [[ "$name" =~ ^$top/deploy/([^/]+\.sh|bin/[^/]+)$ && "${mode:3:1}" != "x" ]]; then
            fail "not executable: ${name#"$top/"}"
        fi
    fi
done < "$work/details"

directory="$(dirname "$archive")"

if [ -f "$directory/SHA256SUMS" ]; then
    if ! (cd "$directory" && sha256sum --check --strict SHA256SUMS); then
        fail "the digest of SHA256SUMS does not match"
    fi
fi

size="$(wc -c < "$archive")"
echo "$file: $(wc -l < "$work/names") entries, $(numfmt --to=iec-i --suffix=B "$size") ($size bytes)"

if [ "$failures" -gt 0 ]; then
    echo "$failures check(s) failed." >&2
    exit 1
fi

echo "The archive holds only the paths of its list."
