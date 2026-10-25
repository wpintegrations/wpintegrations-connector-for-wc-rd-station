#!/usr/bin/env bash
#
# Build the distributable plugin zip.
#
# Compiles the admin app, then stages everything .distignore does not exclude
# and zips it. The working tree is never modified.
#
# Usage: bin/build.sh
# Output: dist/<slug>-<version>.zip
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SLUG="$(basename "$ROOT")"
DIST="$ROOT/dist"
STAGE="$DIST/.stage"

# The entry file is whichever root PHP file carries the plugin header.
MAIN="$(grep -l -m1 'Plugin Name:' ./*.php | head -1 | xargs -n1 basename)"
if [ -z "${MAIN:-}" ]; then
	echo "error: no root PHP file with a 'Plugin Name:' header" >&2
	exit 1
fi

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][0-9A-Za-z.-]*\).*/\1/p' "$MAIN" | head -1)"
if [ -z "${VERSION:-}" ]; then
	echo "error: no Version header in $MAIN" >&2
	exit 1
fi

# A readme advertising a different version than the code is the usual cause of
# a release that never reaches anyone.
if [ -f readme.txt ]; then
	STABLE="$(sed -n 's/^Stable tag:[[:space:]]*\([0-9][0-9A-Za-z.-]*\).*/\1/p' readme.txt | head -1)"
	if [ -n "${STABLE:-}" ] && [ "$STABLE" != "$VERSION" ]; then
		echo "error: readme.txt Stable tag ($STABLE) does not match $MAIN Version ($VERSION)" >&2
		exit 1
	fi
fi

echo "==> $SLUG $VERSION"

echo "==> Compiling the admin app"
pnpm install --frozen-lockfile --silent
pnpm run build

if [ ! -f build/.vite/manifest.json ]; then
	echo "error: build/.vite/manifest.json missing — the admin page would render empty" >&2
	exit 1
fi

echo "==> Staging"
rm -rf "$STAGE"
mkdir -p "$STAGE/$SLUG"
rsync -a \
	--exclude-from=.distignore \
	--exclude='/dist' \
	./ "$STAGE/$SLUG/"

# Refuse to ship anything that would break or bloat the install.
FAIL=0
for bad in hot node_modules vendor .git .distignore .claude workdrive dist pnpm-lock.yaml .wordpress-org brand lib/plugin-update-checker; do
	if [ -e "$STAGE/$SLUG/$bad" ]; then
		echo "error: $bad leaked into the package" >&2
		FAIL=1
	fi
done

# Last line of defence: .distignore has to be maintained by hand, so scan the
# staged tree for credential shapes instead of trusting it. The Freemius public
# key (pk_) is meant to ship, a secret key is not; the bundled SDK is skipped,
# being third-party code full of these words in its own docs.
SECRETS="$(grep -rlIE 'sk_[A-Za-z0-9!<>=_.:%{}^;$-]{8,}|Secret Key|Bearer Authorization Token|BEGIN [A-Z ]*PRIVATE KEY' \
	"$STAGE/$SLUG" 2>/dev/null | grep -v '/freemius/' || true)"
if [ -n "$SECRETS" ]; then
	echo "error: possible credential in the package:" >&2
	echo "$SECRETS" | sed "s|^$STAGE/$SLUG/||" | sed 's/^/  /' >&2
	FAIL=1
fi
for need in "$MAIN" readme.txt build/.vite/manifest.json; do
	if [ ! -e "$STAGE/$SLUG/$need" ]; then
		echo "error: $need missing from the package" >&2
		FAIL=1
	fi
done
[ "$FAIL" -eq 0 ] || exit 1

echo "==> Zipping"
ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr "$ZIP" "$SLUG" -x '*.DS_Store' )
rm -rf "$STAGE"

echo
echo "$ZIP"
echo "  size:  $(du -h "$ZIP" | cut -f1)"
echo "  files: $(unzip -l "$ZIP" | tail -1 | awk '{print $2}')"
