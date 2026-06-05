#!/usr/bin/env bash
#
# Deploy the CURRENT ShootCal Web Calendar source to the WordPress.org SVN repo.
#
# Stages trunk + a new tag + the marketing assets, runs `svn add`/`svn rm`, shows
# the status, and prints the one commit command to run. It does NOT commit by
# default — `svn ci` needs Ryan's SVN password (account-level, generated at
# WordPress.org → profile → Account & Security; username `rsmith4321`).
#
#   ./deploy-svn.sh            # stage everything + print the commit command (safe)
#   ./deploy-svn.sh commit     # also run `svn ci` (it will prompt for the SVN pw)
#
# Refuses to deploy if the source version already exists as a published tag —
# WordPress.org tags are immutable, so bump the version (3 places + a changelog
# entry) before deploying a change.
set -euo pipefail

SLUG="shootcal-web-calendar"
SRC="/Volumes/Recent Pictures SSD/wp-plugins/$SLUG"
SVN="/Volumes/Recent Pictures SSD/wp-plugins/$SLUG-svn"
SVN_USER="rsmith4321"
SVN_URL="https://plugins.svn.wordpress.org/$SLUG"

# rsync excludes — keep dev/meta files out of the published package.
EXCLUDES=(--exclude='.git' --exclude='.gitignore' --exclude='.github'
          --exclude='.wordpress-org' --exclude='.DS_Store' --exclude='*.zip'
          --exclude='node_modules' --exclude='.svn' --exclude='*.sh')

# --- version (single source of truth: the main file's const) ------------------
VERSION=$(grep -oE "const VERSION[[:space:]]*=[[:space:]]*'[^']+'" "$SRC/$SLUG.php" \
            | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)
[ -n "${VERSION:-}" ] || { echo "ERROR: couldn't read VERSION from $SLUG.php"; exit 1; }

# Header `Version:` and readme `Stable tag:` must match the const.
HDR=$(grep -oE 'Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "$SRC/$SLUG.php" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)
STABLE=$(grep -iE 'Stable tag:' "$SRC/readme.txt" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)
if [ "$VERSION" != "$HDR" ] || [ "$VERSION" != "$STABLE" ]; then
  echo "ERROR: version mismatch — const=$VERSION header=${HDR:-?} stable=${STABLE:-?}."
  echo "Bump all three (main-file header Version, const VERSION, readme Stable tag) together."
  exit 1
fi
echo "Deploying version: $VERSION"

# --- working copy -------------------------------------------------------------
if [ -d "$SVN/.svn" ]; then
  echo "Updating working copy…"; svn update -q "$SVN"
else
  echo "Checking out $SVN_URL …"; svn checkout -q "$SVN_URL" "$SVN"
fi

# WordPress.org tags are immutable — never re-publish an existing one.
if svn ls "$SVN_URL/tags/$VERSION/" >/dev/null 2>&1; then
  echo "ERROR: tag $VERSION is already published on WordPress.org."
  echo "Bump the version (3 places + a readme changelog entry) before deploying a change."
  exit 1
fi

# --- stage trunk / tag / assets ----------------------------------------------
echo "Syncing trunk…"
rsync -a --delete "${EXCLUDES[@]}" "$SRC/" "$SVN/trunk/"

echo "Creating tags/$VERSION…"
rm -rf "$SVN/tags/$VERSION"
mkdir -p "$SVN/tags/$VERSION"
rsync -a --exclude='.svn' "$SVN/trunk/" "$SVN/tags/$VERSION/"

echo "Copying marketing assets…"
cp "$SRC/.wordpress-org/"*.png "$SVN/assets/" 2>/dev/null || true

cd "$SVN"
# Stage additions, then any files deleted from the source (svn shows them as '!').
svn add --force trunk tags assets >/dev/null 2>&1 || true
svn status | awk '/^!/{print $2}' | while read -r f; do svn rm "$f" >/dev/null 2>&1 || true; done

# --- sanity + handoff ---------------------------------------------------------
LEAK=$(find trunk -name '.git' -o -name '.wordpress-org' -o -name '*.sh' | wc -l | tr -d ' ')
echo "Leakage check (must be 0): $LEAK"
[ "$LEAK" = "0" ] || { echo "ERROR: dev files leaked into trunk — aborting."; exit 1; }
echo "---- svn status ----"
svn status | sed 's/^/  /'

MSG="Release $VERSION"
if [ "${1:-}" = "commit" ]; then
  echo "Committing (you'll be prompted for your SVN password)…"
  svn ci --username "$SVN_USER" -m "$MSG"
  echo "Done. Live at https://wordpress.org/plugins/$SLUG in a few minutes."
else
  echo
  echo "Staged and ready. To publish, run:"
  echo "  cd \"$SVN\" && svn ci --username $SVN_USER -m \"$MSG\""
fi
