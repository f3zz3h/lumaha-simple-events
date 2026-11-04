#!/usr/bin/env bash
# Build the installable plugin zip: dist/lumaha-simple-event-tickets-<version>.zip containing a
# lumaha-simple-event-tickets/ folder, without the development files listed in .distignore.
#
# Usage: build-zip.sh [expected-version]
# With an expected version (e.g. from a v0.1.0 tag), fail unless the plugin header, LSET_VERSION
# and Lumaha_Simple_Event_Tickets::VERSION all say that version.
set -euo pipefail

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
if [ -f "$ROOT/readme.txt" ]; then
	stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$ROOT/readme.txt" | head -1)"
	if [ -n "$stable_tag" ] && [ "$stable_tag" != "$header_version" ]; then
		echo "readme.txt says Stable tag: $stable_tag, but the plugin says $header_version." >&2
		exit 1
	fi
fi

DIST="$ROOT/dist"
STAGE="$DIST/stage"
ZIP="$DIST/$SLUG-$header_version.zip"
rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE/$SLUG"
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/$SLUG/"

if command -v wp > /dev/null; then
	mkdir -p "$STAGE/$SLUG/languages"
	wp i18n make-pot "$STAGE/$SLUG" "$STAGE/$SLUG/languages/$SLUG.pot" --slug="$SLUG" --domain="$SLUG" \
		--headers='{"Report-Msgid-Bugs-To":"https://github.com/f3zz3h/lumaha-simple-event-tickets/issues"}' --quiet --allow-root
fi

if command -v zip > /dev/null; then
	(cd "$STAGE" && zip -qr -X "$ZIP" "$SLUG")
else
	python3 -c 'import shutil, sys; shutil.make_archive(sys.argv[1][:-4], "zip", sys.argv[2], sys.argv[3])' "$ZIP" "$STAGE" "$SLUG"
fi
rm -rf "$STAGE"

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1)), version $header_version"
if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{ echo "zip=$ZIP"; echo "version=$header_version"; echo "name=$(basename "$ZIP")"; } >> "$GITHUB_OUTPUT"
fi
