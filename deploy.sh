#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# deploy.sh — bump micro version, commit, build, deploy
#
# Version scheme: major.minor.mini.micro
#   major / minor / mini  — set manually (mini = branch number)
#   micro                 — auto-incremented on every deploy
# ---------------------------------------------------------------------------

PHP_FILE="src/php/avpvh-gallery.php"
PKG_FILE="package.json"

# -- 1. Read current version from PHP header --------------------------------
current=$(grep -m1 '^Version:' "$PHP_FILE" | sed 's/Version:[[:space:]]*//')

IFS='.' read -r major minor mini micro <<< "$current"
micro=$(( ${micro:-0} + 1 ))
new_version="${major}.${minor}.${mini}.${micro}"

echo "Bumping version: ${current} → ${new_version}"

# -- 2. Update PHP plugin header --------------------------------------------
sed -i "s/^Version:.*$/Version:           ${new_version}/" "$PHP_FILE"
sed -i "s/^Stable tag:.*$/Stable tag: ${new_version}/" "src/txt/readme.txt"

# -- 3. Update package.json (version field) ---------------------------------
# Use node so we don't mangle the JSON formatting
node -e "
const fs = require('fs');
const pkg = JSON.parse(fs.readFileSync('${PKG_FILE}', 'utf8'));
pkg.version = '${new_version}';
fs.writeFileSync('${PKG_FILE}', JSON.stringify(pkg, null, 2) + '\n');
"

# -- 4. Build ---------------------------------------------------------------
echo "Building..."
npm run build

# -- 5. Commit version bump -------------------------------------------------
git add "$PHP_FILE" "$PKG_FILE" "src/txt/readme.txt"
git commit -m "deploy: bump version to ${new_version}"

# -- 6. Deploy to server ----------------------------------------------------
echo "Deploying..."
# Exit code 23 = some files/attrs not transferred (directory permission errors
# on the server are expected and harmless — files themselves do transfer).
rsync -av --omit-dir-times --exclude='vendor/' dist/ \
    grmt@avpvh.nl:/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-gallery/ || \
    { code=$?; [ $code -eq 23 ] && echo "(rsync: ignoring dir-permission errors)" || exit $code; }

echo "Done — ${new_version} deployed."
