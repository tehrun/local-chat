#!/usr/bin/env bash
set -euo pipefail

status=0

while IFS= read -r -d '' file; do
  if ! node --check "$file" >/dev/null; then
    status=1
  fi
done < <(find public/assets/js -type f -name '*.js' -print0)

if [ "$status" -ne 0 ]; then
  echo "JavaScript syntax check failed."
  exit "$status"
fi

echo "JavaScript syntax check passed for public/assets/js/**/*.js"
