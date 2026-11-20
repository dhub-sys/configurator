#!/usr/bin/env bash
# Δημιουργεί το πακέτο εγκατάστασης Joomla: dist/com_koinoxrista-<έκδοση>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -oP '(?<=<version>)[^<]+' koinoxrista.xml)
mkdir -p dist
OUT="dist/com_koinoxrista-$VERSION.zip"
rm -f "$OUT"
zip -qr "$OUT" koinoxrista.xml script.php administrator components media -x '*.DS_Store'
echo "$OUT"
