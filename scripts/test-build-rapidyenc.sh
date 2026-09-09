#!/usr/bin/env bash
set -euo pipefail

# Offline regression checks for fixed paths and preservation of an existing library.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
sandbox=$(mktemp -d)
trap 'rm -rf -- "$sandbox"' EXIT
mkdir -p "$sandbox/project/scripts" "$sandbox/project/storage/app/rapidyenc" "$sandbox/bin"
cp -- "$script_dir/build-rapidyenc.sh" "$sandbox/project/scripts/build-rapidyenc.sh"
builder="$sandbox/project/scripts/build-rapidyenc.sh"
output_dir="$sandbox/project/storage/app/rapidyenc"
revision=$(sed -n 's/^revision=//p' "$builder")
archive="$output_dir/rapidyenc-$revision.tar.gz"
printf 'existing library\n' > "$output_dir/librapidyenc.so"
printf 'corrupt archive\n' > "$archive"
# CMake must never run when verification fails; no compiler is needed for these checks.
printf '#!/usr/bin/env bash\nexit 99\n' > "$sandbox/bin/cmake"
chmod +x "$sandbox/bin/cmake"
export PATH="$sandbox/bin:$PATH"

fail() {
    echo "Test failure: $1" >&2
    exit 1
}

# Invoke outside the checkout, with zero arguments and an already installed library.
status=0
result=$(cd / && bash "$builder" 2>&1) || status=$?
[[ "$status" == 1 ]] || fail "Expected checksum failure, got $status: $result"
[[ "$result" == *"Source checksum mismatch: $archive"* ]] || fail "Unexpected archive location: $result"
[[ $(cat "$output_dir/librapidyenc.so") == 'existing library' ]] || fail 'Failed build changed the existing library'
[[ $(cat "$archive") == 'corrupt archive' ]] || fail 'Corrupt cache was silently replaced'
if compgen -G "$output_dir/.build.*" >/dev/null; then
    fail 'Failed build left temporary files behind'
fi

status=0
result=$(bash "$builder" unexpected-argument 2>&1) || status=$?
[[ "$status" == 2 && "$result" == *'no arguments'* ]] || fail 'Unexpected arguments were accepted'
echo 'RapidYenc build script regression checks passed'
