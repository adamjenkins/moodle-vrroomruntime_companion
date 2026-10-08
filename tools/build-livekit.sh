#!/usr/bin/env bash
# This file is part of Moodle - http://moodle.org/
#
# Moodle is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Moodle is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
#
# Builds amd/src/livekit.js: the LiveKit JavaScript client (livekit-client) and the packages it
# bundles, as one ES module, which Moodle's grunt then turns into an AMD module like any other file
# in amd/src. Only Room, RoomEvent, Track, ConnectionState and DisconnectReason are exported.
#
# The bundle must not contain a dynamic import(): grunt rewrites it into a RequireJS call that fails
# (the script stops with an error if one is found).
#
# Usage: tools/build-livekit.sh
#   The npm work happens in a temporary directory (mktemp -d under ${TMPDIR:-/tmp}) that is
#   removed on exit. Nothing is written into the repository except amd/src/livekit.js.
#   After running it, update thirdpartylibs.xml if the version changed, then rebuild amd/build.
#
# @copyright  2026 Adam Jenkins <adam@wisecat.net>
# @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

set -euo pipefail

LIVEKIT_VERSION="2.22.3"
ESBUILD_VERSION="0.28.2"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$(cd "${SCRIPT_DIR}/.." && pwd)/amd/src/livekit.js"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/mavr-livekit-build.XXXXXX")"
cleanup() {
    rm -rf "${WORK}"
}
trap cleanup EXIT

cd "${WORK}"
npm init -y >/dev/null
npm install --prefer-offline --no-audit --no-fund --save-exact \
    "livekit-client@${LIVEKIT_VERSION}" "esbuild@${ESBUILD_VERSION}" >/dev/null

cat > "${WORK}/entry.js" <<'EOF'
export {Room, RoomEvent, Track, ConnectionState, DisconnectReason} from 'livekit-client';
EOF

npx --no-install esbuild "${WORK}/entry.js" --bundle --format=esm --target=es2020 \
    --legal-comments=inline --metafile="${WORK}/meta.json" --outfile="${WORK}/bundle.js" --log-level=warning

# The packages in the bundle, with their versions and licences (from package.json). livekit-client's
# own dist file already inlines its dependencies, so esbuild's metafile names only livekit-client:
# the production dependency tree of every package in the metafile is listed as well.
cat > "${WORK}/licences.cjs" <<'EOF'
const fs = require('fs');
const path = require('path');
const work = process.argv[2];
const meta = JSON.parse(fs.readFileSync(path.join(work, 'meta.json'), 'utf8'));
const readPackage = (dir) => JSON.parse(fs.readFileSync(path.join(dir, 'package.json'), 'utf8'));
// Find an installed dependency the way Node does: the nearest node_modules upwards.
const resolveDependency = (fromdir, name) => {
    let dir = fromdir;
    for (;;) {
        const candidate = path.join(dir, 'node_modules', name);
        if (fs.existsSync(path.join(candidate, 'package.json'))) {
            return candidate;
        }
        if (path.resolve(dir) === path.resolve(work)) {
            return null;
        }
        const parent = path.dirname(dir);
        if (parent === dir) {
            return null;
        }
        dir = parent;
    }
};
const seen = new Set();
const visit = (dir) => {
    const resolved = path.resolve(dir);
    if (seen.has(resolved)) {
        return;
    }
    seen.add(resolved);
    const pkg = readPackage(resolved);
    Object.keys(pkg.dependencies || {}).forEach((name) => {
        const depdir = resolveDependency(resolved, name);
        if (depdir) {
            visit(depdir);
        }
    });
};
Object.keys(meta.inputs).forEach((input) => {
    const match = /^(.*node_modules\/(?:@[^/]+\/)?[^/]+)\//.exec(input);
    if (match) {
        visit(path.join(work, match[1]));
    }
});
const lines = [];
seen.forEach((dir) => {
    const pkg = readPackage(dir);
    let licence = pkg.license;
    if (licence && typeof licence === 'object') {
        licence = licence.type;
    }
    if (!licence && Array.isArray(pkg.licenses)) {
        licence = pkg.licenses.map((entry) => entry.type).join(' OR ');
    }
    licence = String(licence || 'UNKNOWN').replace(/\*\//g, '');
    lines.push(pkg.name + '@' + pkg.version + ' ' + licence);
});
lines.sort();
process.stdout.write(Array.from(new Set(lines)).join('\n') + '\n');
EOF
node "${WORK}/licences.cjs" "${WORK}" > "${WORK}/licences.txt"

if grep -q 'UNKNOWN' "${WORK}/licences.txt"; then
    echo "A bundled package has no licence in its package.json:" >&2
    cat "${WORK}/licences.txt" >&2
    exit 1
fi

{
    echo "/* eslint-disable */"
    echo "/**"
    echo " * livekit-client ${LIVEKIT_VERSION} and the packages it bundles, as one ES module."
    echo " *"
    echo " * GENERATED FILE - do not edit. Rebuild with tools/build-livekit.sh"
    echo " * (esbuild ${ESBUILD_VERSION}, --format=esm --target=es2020)."
    echo " *"
    echo " * Bundled packages and licences (livekit-client and its production dependency tree;"
    echo " * livekit-client's dist file inlines its dependencies):"
    while IFS= read -r line; do
        echo " *  - ${line}"
    done < "${WORK}/licences.txt"
    echo " */"
    cat "${WORK}/bundle.js"
} > "${WORK}/livekit.js"

if grep -qF 'import(' "${WORK}/livekit.js"; then
    echo "The bundle contains a dynamic import(), which grunt cannot turn into AMD." >&2
    exit 1
fi

mv "${WORK}/livekit.js" "${OUT}"
echo "Wrote ${OUT} ($(wc -c < "${OUT}") bytes)"
echo "Bundled packages and licences:"
cat "${WORK}/licences.txt"
