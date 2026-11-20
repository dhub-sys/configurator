#!/usr/bin/env bash
# Αντιγράφει τα αρχεία σε τοπική δοκιμαστική εγκατάσταση Joomla (χωρίς επανεγκατάσταση).
set -euo pipefail
cd "$(dirname "$0")/.."
J=${1:-/tmp/joomla6}
rsync -a --delete components/com_koinoxrista/ "$J/components/com_koinoxrista/"
rsync -a --delete administrator/components/com_koinoxrista/ "$J/administrator/components/com_koinoxrista/"
cp koinoxrista.xml script.php "$J/administrator/components/com_koinoxrista/"
rsync -a --delete media/ "$J/media/com_koinoxrista/"
