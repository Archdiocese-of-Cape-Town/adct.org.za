#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
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

temp_dir=$(mktemp -d "$ROOT_DIR/.release-build.XXXXXX")
trap 'rm -rf "$temp_dir"' EXIT HUP INT TERM

temp_dir_relative=${temp_dir#"$ROOT_DIR"/}
export COMPOSER_VENDOR_DIR="$temp_dir_relative/vendor"
composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

strauss_phar="$temp_dir/strauss.phar"
curl --fail --location --silent --show-error \
    https://github.com/BrianHenryIE/strauss/releases/download/0.30.0/strauss.phar \
    --output "$strauss_phar"
rm -rf vendor-prefixed
php "$strauss_phar"

if [ ! -f vendor-prefixed/autoload.php ]; then
    printf 'Strauss did not generate vendor-prefixed/autoload.php.\n' >&2
    exit 1
fi

package_dir="$temp_dir/adct-parish-intake"
mkdir -p "$package_dir"
cp -R adct-parish-intake.php src vendor-prefixed "$package_dir/"

rm -f "$ZIP_PATH"
(
    cd "$temp_dir"
    zip -q -X -r "$ZIP_PATH" adct-parish-intake
)

sh "$ROOT_DIR/scripts/validate-release-zip.sh" "$ZIP_PATH"
printf 'Release package created: %s\n' "$ZIP_PATH"
