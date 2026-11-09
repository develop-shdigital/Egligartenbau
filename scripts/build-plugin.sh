#!/usr/bin/env bash
# Build the SHD Instagram Feed plugin: minify the slider script, compile
# translations (if WP-CLI is available) and create an installable zip.
#
# Usage: scripts/build-plugin.sh [output-dir]
# Needs: node/npx, zip. Optional: wp (WP-CLI) for translations.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
plugins="$root/wp-content/plugins"
slug="shd-instagram-feed"
plugin="$plugins/$slug"
out="${1:-$root/dist}"

echo "Minifying slider.js"
npx -y terser@5.36.0 "$plugin/assets/js/slider.js" --compress --mangle --comments false --output "$plugin/assets/js/slider.min.js"

if command -v wp >/dev/null 2>&1; then
	echo "Compiling translations"
	wp i18n make-pot "$plugin" "$plugin/languages/$slug.pot" --domain="$slug" --exclude=languages
	wp i18n update-po "$plugin/languages/$slug.pot" "$plugin/languages"
	wp i18n make-mo "$plugin/languages"
	wp i18n make-php "$plugin/languages"
else
	echo "WP-CLI not found: translations not recompiled"
fi

version="$(sed -n 's/^ \* Version: *//p' "$plugin/$slug.php" | head -n 1)"
mkdir -p "$out"
rm -f "$out/$slug-$version.zip"
(cd "$plugins" && zip -qr "$out/$slug-$version.zip" "$slug" -x "*.DS_Store")
echo "Created $out/$slug-$version.zip"
