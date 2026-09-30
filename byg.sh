#!/usr/bin/env bash
# Byg release-zip: dist/asite-cache.zip med mappen asite-cache/ i roden.
set -euo pipefail
cd "$(dirname "$0")"
rm -rf dist && mkdir -p dist
for f in asite-cache/*.php asite-cache/includes/*.php; do php -l "$f" >/dev/null; done
python3 - <<'P'
import zipfile, os
with zipfile.ZipFile('dist/asite-cache.zip', 'w', zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk('asite-cache'):
        for f in files:
            z.write(os.path.join(root, f))
print('dist/asite-cache.zip')
P
