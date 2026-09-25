#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
STRAUSS_VERSION=0.30.0
STRAUSS_SHA256=08c1a8e553594745c22294e158129005fd11ed09ed452d7d4f48566f38c66c96
cd "$ROOT_DIR"

ZIP_PATH=${1:-"$ROOT_DIR/dist/adct-parish-intake.zip"}
case "$ZIP_PATH" in
    /*) ;;
    *) ZIP_PATH="$ROOT_DIR/$ZIP_PATH" ;;
esac
mkdir -p "$(dirname "$ZIP_PATH")"

release_tag=${RELEASE_TAG:-}
if [ "${GITHUB_REF_TYPE:-}" = "tag" ]; then
    release_tag=${GITHUB_REF_NAME:-}
    if [ -z "$release_tag" ]; then
        printf 'GitHub tag reference has no name.\n' >&2
        exit 1
    fi
fi

if [ -n "$release_tag" ]; then
    case "$release_tag" in
        v*) expected_version=${release_tag#v} ;;
        *)
            printf 'Release tags must start with v (got %s).\n' "$release_tag" >&2
            exit 1
            ;;
    esac

    plugin_version=$(sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' adct-parish-intake.php | sed -n '1p' | tr -d '\r')
    if [ -z "$plugin_version" ]; then
        printf 'Could not read the plugin Version header.\n' >&2
        exit 1
    fi
    if [ "$plugin_version" != "$expected_version" ]; then
        printf 'Tag %s does not match the plugin Version header (%s).\n' "$release_tag" "$plugin_version" >&2
        exit 1
    fi
fi

if [ ! -f src/WordPress/Autoloader.php ]; then
    printf 'The plugin source autoloader is missing.\n' >&2
    exit 1
fi

temp_dir=$(mktemp -d "$ROOT_DIR/.release-build.XXXXXX")
trap 'rm -rf "$temp_dir"' EXIT HUP INT TERM

temp_dir_relative=${temp_dir#"$ROOT_DIR"/}
export COMPOSER_VENDOR_DIR="$temp_dir_relative/vendor"
composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

strauss_phar="$temp_dir/strauss.phar"
curl --fail --location --silent --show-error \
    "https://github.com/BrianHenryIE/strauss/releases/download/$STRAUSS_VERSION/strauss.phar" \
    --output "$strauss_phar"
if ! printf '%s  %s\n' "$STRAUSS_SHA256" "$strauss_phar" | sha256sum -c -; then
    printf 'Strauss %s failed SHA-256 verification; refusing to execute it.\n' "$STRAUSS_VERSION" >&2
    exit 1
fi
rm -rf vendor-prefixed
cp -R "$temp_dir/vendor" vendor-prefixed
# The normal copy filter is bypassed when Strauss prefixes the target in place.
find vendor-prefixed -type d \( -name .github -o -name docs \) -prune -exec rm -rf {} +
# Use the shipped path as Composer's vendor-dir so generated maps contain no temp paths.
export COMPOSER_VENDOR_DIR=vendor-prefixed
php "$strauss_phar"

if [ ! -f vendor-prefixed/autoload.php ]; then
    printf 'Strauss did not generate vendor-prefixed/autoload.php.\n' >&2
    exit 1
fi

package_dir="$temp_dir/adct-parish-intake"
mkdir -p "$package_dir"
cp -R adct-parish-intake.php uninstall.php src vendor-prefixed "$package_dir/"

rm -f "$ZIP_PATH"
(
    cd "$temp_dir"
    zip -q -X -r "$ZIP_PATH" adct-parish-intake
)

sh "$ROOT_DIR/scripts/validate-release-zip.sh" "$ZIP_PATH"
printf 'Release package created: %s\n' "$ZIP_PATH"
