#!/usr/bin/env bash
#
# Builds the WordPress.org release zip: a production bundle plus only the files listed as
# shippable by .distignore. Output: dist/qa-runner/ (for SVN) and dist/qa-runner-<version>.zip.
#
# Usage: npm run release

set -euo pipefail

cd "$(dirname "$0")/.."

slug="qa-runner"
version="$(sed -n 's/^ \* Version:[[:space:]]*//p' qa-runner.php)"
stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt)"

if [[ "$version" != "$stable" ]]; then
  echo "Version mismatch: qa-runner.php says $version, readme.txt Stable tag says $stable." >&2
  exit 1
fi

# The dev watcher writes an unminified bundle with a source map; never ship that.
npm run build

rm -rf dist
mkdir -p "dist/$slug"
rsync -a --exclude-from=.distignore ./ "dist/$slug/"

# Drop directories left empty by the exclusions (e.g. languages/ holding only .gitkeep).
find "dist/$slug" -type d -empty -delete

(cd dist && zip -qr "$slug-$version.zip" "$slug")

echo "Built dist/$slug-$version.zip"
