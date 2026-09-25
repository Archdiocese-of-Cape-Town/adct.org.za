#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ZIP_PATH=${1:-"$ROOT_DIR/dist/adct-parish-intake.zip"}
case "$ZIP_PATH" in
    /*) ;;
    *) ZIP_PATH="$ROOT_DIR/$ZIP_PATH" ;;
esac

if [ ! -f "$ZIP_PATH" ]; then
    printf 'Release zip not found: %s\n' "$ZIP_PATH" >&2
    exit 1
fi

temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT HUP INT TERM

unzip -tq "$ZIP_PATH" >/dev/null
unzip -Z1 "$ZIP_PATH" > "$temp_dir/entries.txt"
if [ ! -s "$temp_dir/entries.txt" ]; then
    printf 'Release zip is empty.\n' >&2
    exit 1
fi

if grep -Ev '^adct-parish-intake(/|$)' "$temp_dir/entries.txt" > "$temp_dir/unexpected.txt"; then
    printf 'Release zip must contain only the adct-parish-intake/ top-level folder:\n' >&2
    cat "$temp_dir/unexpected.txt" >&2
    exit 1
fi
if ! grep -Eq '^adct-parish-intake/?$' "$temp_dir/entries.txt"; then
    printf 'Release zip does not contain its stable top-level folder.\n' >&2
    exit 1
fi

unzip -q "$ZIP_PATH" -d "$temp_dir/unpacked"
package_dir="$temp_dir/unpacked/adct-parish-intake"

for required_file in \
    adct-parish-intake.php \
    uninstall.php \
    src/WordPress/Autoloader.php \
    assets/events-block.js \
    assets/events.css \
    vendor-prefixed/autoload.php
do
    if [ ! -f "$package_dir/$required_file" ]; then
        printf 'Required release file is missing: %s\n' "$required_file" >&2
        exit 1
    fi
done

for excluded_path in docs tests .github data vendor composer.json composer.lock phpunit.xml.dist scripts; do
    if [ -e "$package_dir/$excluded_path" ]; then
        printf 'Non-runtime path found in release package: %s\n' "$excluded_path" >&2
        exit 1
    fi
done

if grep -E '(^|/)(tests?|docs?|fixtures)(/|$)|(^|/)\.github(/|$)|(^|/)data/seed(/|$)|^adct-parish-intake/vendor(/|$)' "$temp_dir/entries.txt" > "$temp_dir/forbidden.txt"; then
    printf 'Non-runtime path found in release archive:\n' >&2
    cat "$temp_dir/forbidden.txt" >&2
    exit 1
fi

find "$package_dir" -type f -name '*.php' -print > "$temp_dir/php-files.txt"
if [ ! -s "$temp_dir/php-files.txt" ]; then
    printf 'Release package contains no PHP files.\n' >&2
    exit 1
fi
while IFS= read -r php_file; do
    php -l "$php_file" >/dev/null
done < "$temp_dir/php-files.txt"

php "$ROOT_DIR/scripts/check-release-bootstrap.php" "$package_dir"
printf 'Release zip is valid: %s\n' "$ZIP_PATH"
