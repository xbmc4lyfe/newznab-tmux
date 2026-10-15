#!/usr/bin/env bash
# Stage frozen public reports for another session's Pages build; never deploy.
set -euo pipefail

if [[ "$#" != 1 || -z "$1" ]]; then
  echo 'Usage: bash github-pages/deployment/prepare-benchmarks.sh OUTPUT_SITE_DIRECTORY' >&2
  exit 2
fi
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
source_dir="$repo/github-pages/benchmarks"
if [[ -n "$(find "$source_dir" ! -type f ! -type d -print -quit)" ]]; then
  echo 'Report source must contain only regular files and directories.' >&2
  exit 1
fi
(
  cd "$source_dir"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum --check SHA256SUMS.txt
  else
    shasum -a 256 --check SHA256SUMS.txt
  fi
)
mkdir -p "$1"
output=$(cd "$1" && pwd -P)
case "$output/" in
  "$repo/github-pages/"*)
    echo 'Output must be outside the tracked github-pages source tree.' >&2
    exit 2
    ;;
esac
# Refuse to replace a caller's existing report tree or follow its symlink.
mkdir "$output/benchmarks"
cp -R "$source_dir/." "$output/benchmarks/"
printf 'Staged frozen reports at %s/benchmarks/index.html\n' "$output"
