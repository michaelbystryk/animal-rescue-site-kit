#!/usr/bin/env bash
# Local packaging only. Never deploys, uploads, installs or fetches dependencies.
set -euo pipefail
whitelabel_root="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$whitelabel_root/dist"
archive="$whitelabel_root/dist/homeward-rescue.zip"
# Recreate, so removed files cannot survive from a prior build.
rm -f "$archive"
package_dir="$(mktemp -d "$whitelabel_root/dist/.package.XXXXXX")"
trap 'rm -rf "$package_dir"' EXIT
cp -R "$whitelabel_root/plugin/homeward-rescue" "$package_dir/homeward-rescue"
rm -rf "$package_dir/homeward-rescue/demo-content"
mkdir -p "$package_dir/homeward-rescue/demo-content"
cp -R "$whitelabel_root/demo-content/." "$package_dir/homeward-rescue/demo-content/"
cp "$whitelabel_root/LICENSE" "$package_dir/homeward-rescue/LICENSE"
cp "$whitelabel_root/config.example.json" "$package_dir/homeward-rescue/config.example.json"
cp -R "$whitelabel_root/theme/homeward" "$package_dir/homeward"
cp "$whitelabel_root/LICENSE" "$package_dir/homeward/LICENSE"
cd "$package_dir"
zip -qr "$archive" homeward-rescue \
    -x '*/tests/*' '*/node_modules/*' '*/vendor/*' '*/.git/*' '*/.DS_Store' \
       '*/.env' '*/.env.*' '*.log' '*.zip'
printf 'Built dist/homeward-rescue.zip\n'
theme_archive="$whitelabel_root/dist/homeward.zip"
rm -f "$theme_archive"
cd "$package_dir"
zip -qr "$theme_archive" homeward -x '*/.DS_Store'
printf 'Built dist/homeward.zip\n'
