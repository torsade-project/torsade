#!/usr/bin/env bash
# torsade/scripts/build-archive.sh
#
# Builds the archive of a release, torsade-X.Y.Z.tar.gz, and its
# SHA256SUMS, from the commit checked out:
#
#   scripts/build-archive.sh <output directory>
#
# The archive is made from an export of the commit in a temporary
# directory, never from the working tree, whose dependencies, development
# tools included, stay as they are. What it holds is a fixed list of paths;
# scripts/check-archive.sh checks it against that list. Needs git, PHP,
# Composer, Node and npm, GNU tar, gzip and sha256sum.
set -euo pipefail

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <output directory>" >&2
    exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mkdir -p "$1"
output="$(cd "$1" && pwd)"

# A change that is not committed would be missing from the archive without
# a word: the tree must be clean. Files Git does not track are not exported
# and do not matter.
if [ -n "$(git -C "$root" status --porcelain --untracked-files=no)" ]; then
    echo "The working tree holds changes that are not committed: commit them or set them aside first." >&2
    exit 1
fi

commit="$(git -C "$root" rev-parse --verify HEAD)"
version="$(git -C "$root" show "$commit:VERSION" | tr -d '[:space:]')"

if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "VERSION does not hold a version number X.Y.Z: $version" >&2
    exit 1
fi

name="torsade-$version"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

export_dir="$work/export"
stage="$work/$name"
mkdir -p "$export_dir" "$stage"

echo "Exporting $commit (version $version)"
git -C "$root" archive --format=tar "$commit" | tar -x -C "$export_dir"

(
    cd "$export_dir"

    # The front end is built with the development dependencies: its routes
    # are generated from those of the application by a development package.
    composer install --no-interaction --no-progress --prefer-dist
    npm ci --no-audit --no-fund
    npm run build

    # Then the dependencies of production only, with an optimised class
    # map. What is not shipped goes first, so that the map lists none of it.
    rm -rf tests database/factories database/seeders
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
)

# The list of what the archive holds. Everything else stays out: tests,
# sources of the front end, tools and settings of development.
paths=(
    app
    bootstrap/app.php
    bootstrap/providers.php
    config
    database/migrations
    lang
    public
    resources/views
    routes
    vendor
    artisan
    composer.json
    composer.lock
    VERSION
    README.md
    CHANGELOG.md
    LICENSE
    SECURITY.md
)

# The scripts of the installation and the documentation of the
# administrator, once they exist.
optional_paths=(deploy documentation)

copy() {
    mkdir -p "$stage/$(dirname "$1")"
    cp -R "$export_dir/$1" "$stage/$1"
}

for path in "${paths[@]}"; do
    copy "$path"
done

for path in "${optional_paths[@]}"; do
    if [ -e "$export_dir/$path" ]; then
        copy "$path"
    fi
done

# Never what only development produces, nor any hidden file: those of the
# packages (settings of their own tools) and those of the web server of
# development.
rm -rf "$stage/public/hot" "$stage/public/storage"
find "$stage" -mindepth 1 -name '.*' -prune -exec rm -rf {} +

# The empty places the application writes to: its storage, and the cache
# of its start-up. The installation gives them to the account that runs it.
for dir in \
    bootstrap/cache \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs; do
    mkdir -p "$stage/$dir"
done

# Nobody but the owner may write; the scripts of the installation run, the
# library they source does not.
chmod -R u+rwX,go+rX,go-w "$stage"

if [ -d "$stage/deploy" ]; then
    find "$stage/deploy" -maxdepth 1 -type f -name '*.sh' -exec chmod 755 {} +
    find "$stage/deploy/bin" -type f -exec chmod 755 {} +
fi

# Root owns every file, whoever builds the archive; the date of every file
# is that of the commit, and the order of the entries is fixed.
archive="$output/$name.tar.gz"
tar --create \
    --sort=name \
    --owner=0 --group=0 --numeric-owner \
    --mode=go-w \
    --mtime="$(git -C "$root" show -s --format=%cI "$commit")" \
    -C "$work" "$name" | gzip -n -9 > "$archive"

(cd "$output" && sha256sum "$name.tar.gz" > SHA256SUMS)

echo "Built $archive"
cat "$output/SHA256SUMS"
