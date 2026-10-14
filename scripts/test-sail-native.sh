#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "$0")/.." && pwd)"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/project/docker/app/nzb-yenc-ffi" "$fixture/bin" "$fixture/install"
touch "$fixture/project/docker/app/nzb-yenc-ffi/Cargo.toml"
touch "$fixture/project/docker/app/nzb-yenc-ffi/Cargo.lock"
export SAIL_NATIVE_TEST_LOG="$fixture/cargo.log"

cat > "$fixture/bin/cargo" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$@" > "$SAIL_NATIVE_TEST_LOG"
target=''
while [ $# -gt 0 ]; do
    if [ "$1" = --target-dir ]; then target="$2"; break; fi
    shift
done
[ -n "$target" ]
mkdir -p "$target/release"
printf 'linux-native-library' > "$target/release/libnzbyenc.so"
printf '#!/usr/bin/env bash\nprintf "native-worker"\n' > "$target/release/nntmux-article-fingerprint"
STUB
chmod +x "$fixture/bin/cargo"
export PATH="$fixture/bin:$PATH"

bash "$project_root/docker/8.5/build-native.sh" "$fixture/project" "$fixture/install"
expected="$fixture/expected.log"
printf '%s\n' build --release --locked --manifest-path \
    "$fixture/project/docker/app/nzb-yenc-ffi/Cargo.toml" \
    --target-dir "$fixture/install/lib/nntmux/build" > "$expected"
cmp "$expected" "$SAIL_NATIVE_TEST_LOG"
[ "$(cat "$fixture/install/lib/nntmux/libnzbyenc.so")" = linux-native-library ]
[ -x "$fixture/install/bin/nntmux-article-fingerprint" ]
[ "$("$fixture/install/bin/nntmux-article-fingerprint")" = native-worker ]
[ ! -d "$fixture/project/docker/app/nzb-yenc-ffi/target" ]

rm "$SAIL_NATIVE_TEST_LOG"
if bash "$project_root/docker/8.5/build-native.sh" "$fixture/missing-project" "$fixture/install" 2> "$fixture/missing.log"; then
    echo 'Missing mounted source unexpectedly succeeded.' >&2
    exit 1
fi
rg -q 'mounted.*crate|crate.*mounted' "$fixture/missing.log"
[ ! -e "$SAIL_NATIVE_TEST_LOG" ]

cat > "$fixture/bin/cargo" <<'STUB'
#!/usr/bin/env bash
exit 42
STUB
chmod +x "$fixture/bin/cargo"
if bash "$project_root/docker/8.5/build-native.sh" "$fixture/project" "$fixture/install" 2> "$fixture/failed.log"; then
    echo 'Failed native build unexpectedly succeeded.' >&2
    exit 1
fi
[ "$(cat "$fixture/install/lib/nntmux/libnzbyenc.so")" = linux-native-library ]
[ "$("$fixture/install/bin/nntmux-article-fingerprint")" = native-worker ]

rm -rf "$fixture/install/lib/nntmux/build/release"
printf '#!/usr/bin/env bash\nexit 0\n' > "$fixture/bin/cargo"
if bash "$project_root/docker/8.5/build-native.sh" "$fixture/project" "$fixture/install" 2> "$fixture/artifacts.log"; then
    echo 'Missing native artifacts unexpectedly succeeded.' >&2
    exit 1
fi
rg -q 'did not produce' "$fixture/artifacts.log"
[ "$(cat "$fixture/install/lib/nntmux/libnzbyenc.so")" = linux-native-library ]
[ "$("$fixture/install/bin/nntmux-article-fingerprint")" = native-worker ]

echo 'Sail native helper tests passed.'
