#!/usr/bin/env bash
# Build the installable plugin zip: dist/lumaha-simple-event-tickets-<version>.zip containing a
# lumaha-simple-event-tickets/ folder, without the development files listed in .distignore.
#
# Usage: build-zip.sh [--wporg] [expected-version]
# With an expected version (e.g. from a v0.1.0 tag), fail unless the plugin header, LSET_VERSION
# and LSET_Plugin::VERSION all say that version.
#
# --wporg builds the WordPress.org version instead: dist/lumaha-simple-event-tickets-<version>-wporg.zip,
# same folder name, without the GitHub update checker, the header's Update URI and README.md
# (WordPress.org plugins may only update from WordPress.org). The EventPrime migrator isn't built.
# Its folder is kept in dist/wporg/lumaha-simple-event-tickets for an SVN deploy.
set -euo pipefail

WPORG=""
if [ "${1:-}" = "--wporg" ]; then
	WPORG=1
	shift
fi

SLUG="lumaha-simple-event-tickets"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MAIN="$ROOT/$SLUG.php"

header_version="$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$MAIN" | head -1)"
constant_version="$(sed -n "s/^[[:space:]]*const VERSION[[:space:]]*= '\([^']*\)';.*/\1/p" "$MAIN" | head -1)"
define_version="$(sed -n "s/^define( 'LSET_VERSION', '\([^']*\)' );/\1/p" "$MAIN" | head -1)"

if [ -z "$header_version" ] || [ "$header_version" != "$constant_version" ] || [ "$header_version" != "$define_version" ]; then
	echo "Version mismatch: header '$header_version', VERSION '$constant_version', LSET_VERSION '$define_version'." >&2
	exit 1
fi
if [ -n "${1:-}" ] && [ "$1" != "$header_version" ]; then
	echo "The tag is for version $1, but the plugin says $header_version. Bump the version first." >&2
	exit 1
fi
# WordPress.org installs whatever readme.txt's Stable tag names, so a release must update it too.
stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$ROOT/readme.txt" | head -1)"
if [ "$stable_tag" != "$header_version" ]; then
	echo "readme.txt says Stable tag: '$stable_tag', but the plugin says $header_version. Update the Stable tag first." >&2
	exit 1
fi

DIST="$ROOT/dist"
STAGE="$DIST/stage"
ZIP="$DIST/$SLUG-$header_version.zip"
if [ -n "$WPORG" ]; then
	STAGE="$DIST/wporg"
	ZIP="$DIST/$SLUG-$header_version-wporg.zip"
fi
rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE/$SLUG"
rsync -a --exclude-from="$ROOT/.distignore" --exclude dist "$ROOT/" "$STAGE/$SLUG/"

if [ -n "$WPORG" ]; then
	rm "$STAGE/$SLUG/includes/class-lset-github-updater.php"
	sed -i '/^ \* Update URI:/d' "$STAGE/$SLUG/$SLUG.php"
	sed -i '/lset-github-updater:start/,/lset-github-updater:end/d' "$STAGE/$SLUG/$SLUG.php"
	# README.md is the GitHub page (it documents the GitHub updater and the migrator); WordPress.org uses readme.txt.
	rm "$STAGE/$SLUG/README.md"
	if grep -q '^ \* Update URI:' "$STAGE/$SLUG/$SLUG.php" || grep -rqi 'github-updater\|GitHub_Updater\|api\.github\.com\|from GitHub releases' "$STAGE/$SLUG"; then
		echo "The WordPress.org build still refers to the GitHub updater." >&2
		exit 1
	fi
fi

if command -v wp > /dev/null; then
	mkdir -p "$STAGE/$SLUG/languages"
	wp i18n make-pot "$STAGE/$SLUG" "$STAGE/$SLUG/languages/$SLUG.pot" --slug="$SLUG" --domain="$SLUG" \
		--headers='{"Report-Msgid-Bugs-To":"https://github.com/f3zz3h/lumaha-simple-events/issues"}' --quiet --allow-root
fi

if command -v zip > /dev/null; then
	(cd "$STAGE" && zip -qr -X "$ZIP" "$SLUG")
else
	python3 -c 'import shutil, sys; shutil.make_archive(sys.argv[1][:-4], "zip", sys.argv[2], sys.argv[3])' "$ZIP" "$STAGE" "$SLUG"
fi
[ -n "$WPORG" ] || rm -rf "$STAGE"

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1)), version $header_version"

if [ -n "$WPORG" ]; then
	if [ -n "${GITHUB_OUTPUT:-}" ]; then
		{ echo "zip=$ZIP"; echo "version=$header_version"; echo "name=$(basename "$ZIP")"; } >> "$GITHUB_OUTPUT"
	fi
	exit 0
fi

# The one-off EventPrime migrator is its own small plugin, zipped separately.
ADDON="lumaha-set-eventprime-migrator"
addon_version="$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$ROOT/addons/$ADDON/$ADDON.php" | head -1)"
ADDON_ZIP="$DIST/$ADDON-$addon_version.zip"
rm -f "$ADDON_ZIP"
if command -v zip > /dev/null; then
	(cd "$ROOT/addons" && zip -qr -X "$ADDON_ZIP" "$ADDON")
else
	python3 -c 'import shutil, sys; shutil.make_archive(sys.argv[1][:-4], "zip", sys.argv[2], sys.argv[3])' "$ADDON_ZIP" "$ROOT/addons" "$ADDON"
fi
echo "Built $ADDON_ZIP ($(du -h "$ADDON_ZIP" | cut -f1)), version $addon_version"

if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{ echo "zip=$ZIP"; echo "version=$header_version"; echo "name=$(basename "$ZIP")"; echo "addon_zip=$ADDON_ZIP"; echo "addon_name=$(basename "$ADDON_ZIP")"; } >> "$GITHUB_OUTPUT"
fi
