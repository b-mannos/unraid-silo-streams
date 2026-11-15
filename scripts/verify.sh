#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -x /opt/homebrew/bin/python3.12 ]]; then
  PYTHON=/opt/homebrew/bin/python3.12
else
  PYTHON=python3
fi
if command -v php >/dev/null 2>&1; then
  while IFS= read -r -d '' file; do php -l "$file"; done < <(find src -type f \( -name '*.php' -o -name '*.page' \) -print0)
  for file in tests/*_test.php tests/*.test.php; do
    [[ ! -f "$file" ]] || php "$file"
  done
else
  echo 'SKIP: PHP and .page syntax and PHP tests (PHP CLI unavailable).'
fi
if command -v node >/dev/null 2>&1; then
  while IFS= read -r -d '' file; do node --check "$file"; done < <(find src preview -type f -name '*.js' -print0 2>/dev/null)
  for file in tests/*_test.js tests/*.test.js tests/*.test.cjs; do
    [[ ! -f "$file" ]] || node "$file"
  done
else
  echo 'SKIP: JavaScript syntax and tests (Node.js unavailable).'
fi
"$PYTHON" scripts/build.py
"$PYTHON" tests/package_test.py
"$PYTHON" tests/ca_metadata_test.py
