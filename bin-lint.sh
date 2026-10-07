#!/usr/bin/env bash
# Syntax-check every PHP/JS file. Requires `php` (and optionally `node`) on PATH.
set -euo pipefail
cd "$(dirname "$0")"
fail=0
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null || { echo "PHP syntax error: $f"; fail=1; }
done < <(find . -name '*.php' -not -path './vendor/*' -print0)
if command -v node >/dev/null 2>&1; then
  for f in assets/js/*.js; do node --check "$f" || fail=1; done
fi
exit $fail
