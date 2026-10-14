#!/usr/bin/env bash
# Builds the app and packages the plugin as dist/membuild-community-<version>.zip, ready to upload.
set -euo pipefail
cd "$(dirname "$0")"
npm --prefix app run build >/dev/null
version="$(sed -n "s/^ \* Version: *//p" membuild-community/membuild-community.php | tr -d '[:space:]')"
mkdir -p dist
rm -f "dist/membuild-community-$version.zip"
zip -rq "dist/membuild-community-$version.zip" membuild-community \
  -x 'membuild-community/tmp-*' 'membuild-community/**/.DS_Store' 'membuild-community/assets/build/.vite/*.map'
# WordPress.org asks for human-readable code: ship the app's source next to its build.
stage="$(mktemp -d)"
mkdir -p "$stage/membuild-community/source"
cp -R app/src app/index.html app/package.json app/package-lock.json app/vite.config.js "$stage/membuild-community/source/"
(cd "$stage" && zip -rq "$OLDPWD/dist/membuild-community-$version.zip" membuild-community/source -x '*.DS_Store')
rm -rf "$stage"
echo "dist/membuild-community-$version.zip"
unzip -l "dist/membuild-community-$version.zip" | tail -1
