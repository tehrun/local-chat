#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

ASSET_PATHS=(
  "manifest.json"
  "sw.js"
)

copy_assets() {
  for asset in "${ASSET_PATHS[@]}"; do
    cp "${ROOT_DIR}/public/${asset}" "${ROOT_DIR}/${asset}"
  done
}

check_assets() {
  local mismatched=0

  for asset in "${ASSET_PATHS[@]}"; do
    if ! cmp -s "${ROOT_DIR}/public/${asset}" "${ROOT_DIR}/${asset}"; then
      echo "Asset out of sync: ${asset} (expected root copy to match public/${asset})" >&2
      mismatched=1
    fi
  done

  return "${mismatched}"
}

mode="${1:-sync}"
case "${mode}" in
  sync)
    copy_assets
    ;;
  check)
    check_assets
    ;;
  *)
    echo "Usage: $(basename "$0") [sync|check]" >&2
    exit 2
    ;;
esac
