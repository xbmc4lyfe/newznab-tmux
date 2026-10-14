#!/usr/bin/env bash
# Disposable Linux profiling only. No production environment or services are used.
# Requires locked Composer dependencies, built RapidYenc archive, Rust toolchain,
# Xdebug, perf and a pinned FlameGraph checkout supplied by the workflow.
set -euo pipefail
if [[ $# -ne 3 || $(uname -s) != Linux ]]; then
    echo 'Usage: profile-yenc.sh EVIDENCE_DIR FLAMEGRAPH_DIR PERF_BINARY (Linux only)' >&2
    exit 2
fi
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"
evidence=$(realpath "$1")
flamegraph=$(realpath "$2")
perf_binary=$(realpath "$3")
profile_build="$evidence/profile-build"
mkdir -p "$profile_build/cpp-source" "$evidence/profiles"
archive="$root/storage/app/rapidyenc/rapidyenc-480bd7b5896f8b3edecc721d23f1384d767ffe2f.tar.gz"
printf '%s  %s\n' bd5eff1e978672af4ebaacde837270a679808bbc12dc15bd6acf7c83d94baa16 "$archive" | sha256sum --check --status
tar -xzf "$archive" --strip-components=1 -C "$profile_build/cpp-source"
# Debug information is added to optimized builds; these are diagnostic libraries,
# never the libraries used by the uninstrumented paired speedup gate.
cmake -S "$profile_build/cpp-source" -B "$profile_build/cpp" \
    -DCMAKE_BUILD_TYPE=Release -DBUILD_NATIVE=OFF -DDISABLE_ENCODE=ON -DDISABLE_TOOL=ON \
    -DCMAKE_C_FLAGS_RELEASE='-O3 -DNDEBUG -g -fno-omit-frame-pointer' \
    -DCMAKE_CXX_FLAGS_RELEASE='-O3 -DNDEBUG -g -fno-omit-frame-pointer'
cmake --build "$profile_build/cpp" --target rapidyenc_shared --parallel 2
CARGO_TARGET_DIR="$profile_build/rust" CARGO_PROFILE_RELEASE_DEBUG=2 \
    CARGO_PROFILE_RELEASE_STRIP=none RUSTFLAGS='-C force-frame-pointers=yes' \
    cargo +"${RUST_TOOLCHAIN:?}" build --release --locked --manifest-path docker/app/nzb-yenc-ffi/Cargo.toml
git -C "$flamegraph" rev-parse HEAD > "$profile_build/flamegraph-revision.txt"
"$perf_binary" --version > "$profile_build/perf-version.txt"
XDEBUG_MODE=off php --ri xdebug > "$profile_build/xdebug-version.txt"
sha256sum "$profile_build/cpp/librapidyenc.so" "$profile_build/rust/release/libnzbyenc.so" \
    scripts/profile-yenc.php scripts/profile-yenc.sh > "$profile_build/profile-identities.sha256"
# A harmless separate preflight distinguishes unavailable kernel sampling from
# a crash/timeout of an admitted workload. Preserve both stdout and exit status.
perf_available=0
if timeout --kill-after=5s 15s sudo -n "$perf_binary" record -e cpu-clock -F 99 \
    -o "$profile_build/perf-preflight.data" -- /bin/sleep 0.1 \
    > "$profile_build/perf-preflight.stdout" 2> "$profile_build/perf-preflight.stderr"; then
    perf_available=1
fi
if [[ -f "$profile_build/perf-preflight.data" ]]; then
    sudo -n chmod a+r "$profile_build/perf-preflight.data"
fi
invalid=0
for backend in cpp rust; do
    if [[ "$backend" == cpp ]]; then
        library="$profile_build/cpp/librapidyenc.so"
    else
        library="$profile_build/rust/release/libnzbyenc.so"
    fi
    for density in ordinary heavy; do
        for path in strict tolerant body; do
            directory="$evidence/profiles/$backend-$density-$path"
            mkdir -p "$directory"
            args=(scripts/profile-yenc.php "--native=$library" "--path=$path" \
                --size=524288 "--density=$density" --iterations=400)
            php_exit=0
            timeout --kill-after=5s 90s env XDEBUG_MODE=profile php -d ffi.enable=true \
                -d memory_limit=1G -d xdebug.start_with_request=yes -d xdebug.use_compression=0 \
                "-d xdebug.output_dir=$directory" -d xdebug.profiler_output_name=php.callgrind \
                "${args[@]}" > "$directory/php-workload.json" 2> "$directory/php.stderr" || php_exit=$?
            # perf needs kernel access on hosted runners. sudo is confined to this
            # disposable job; unavailable counters are retained as such, never a pass.
            native_exit=unavailable
            if [[ "$perf_available" == 1 ]]; then
              native_exit=0
              timeout --kill-after=5s 90s sudo -n env XDEBUG_MODE=off "$perf_binary" record \
                -e cpu-clock -F 99 --call-graph dwarf,16384 -o "$directory/perf.data" -- \
                "$(command -v php)" -d ffi.enable=true -d memory_limit=1G "${args[@]}" \
                > "$directory/native-workload.json" 2> "$directory/perf.stderr" || native_exit=$?
              if [[ -f "$directory/perf.data" ]]; then
                  sudo -n chmod a+r "$directory/perf.data"
              fi
              if [[ "$native_exit" == 0 ]]; then
                # Caller owns the redirected stack output; sudo only reads perf.data.
                # shellcheck disable=SC2024
                sudo -n "$perf_binary" script -i "$directory/perf.data" > "$directory/perf.stacks" 2> "$directory/perf-script.stderr"
                perl "$flamegraph/stackcollapse-perf.pl" "$directory/perf.stacks" > "$directory/native.folded" 2> "$directory/collapse.stderr"
                if [[ -s "$directory/native.folded" ]]; then
                    perl "$flamegraph/flamegraph.pl" --title "$backend / $density / $path — whole-process CPU samples" \
                        "$directory/native.folded" > "$directory/native.svg"
                fi
              fi
            fi
            # Keep workload correctness distinct from sampler availability.
            if ! python3 - "$directory" "$native_exit" "$php_exit" <<'PY'
import hashlib, json, pathlib, sys
directory = pathlib.Path(sys.argv[1])
native_exit = sys.argv[2]
php_exit = sys.argv[3]
def workload(name):
    try:
        result = json.loads((directory / name).read_text())
        return result if result.get('status') == 'ok' else None
    except (ValueError, OSError):
        return None
php = workload('php-workload.json')
native = workload('native-workload.json')
callgrind = directory / 'php.callgrind'
folded = directory / 'native.folded'
samples = sum(int(line.rsplit(' ', 1)[1]) for line in folded.read_text().splitlines()) if folded.exists() else 0
matched = bool(php and native and all(php[key] == native[key] for key in
    ('input_sha256', 'output_sha256', 'wire_sha256', 'verified_calls', 'size', 'iterations')))
status = {
    'php_profile': 'captured' if php_exit == '0' and php and callgrind.is_file() and callgrind.stat().st_size else 'invalid',
    'native_profile': 'unavailable' if native_exit == 'unavailable' else (
        'captured' if native_exit == '0' and matched and samples and (directory / 'native.svg').is_file() else 'invalid'),
    'native_samples': samples, 'workload_identity_matches': matched,
    'native_exit': native_exit, 'php_exit': php_exit,
    'php_profile_sha256': hashlib.sha256(callgrind.read_bytes()).hexdigest() if callgrind.is_file() else None,
    'boundary': 'Whole-process profiles include setup and exact-output verification; diagnostic builds differ from timed builds.',
    'claim': 'Call-cost attribution only. No latency or production-capacity conclusion from instrumented runs.',
}
(directory / 'status.json').write_text(json.dumps(status, indent=2) + '\n')
# perf failure can be a platform restriction; a decoded correctness failure is fatal.
if status['php_profile'] == 'invalid' or (native_exit != 'unavailable' and (native_exit != '0' or not matched)):
    raise SystemExit(1)
PY
            then
                invalid=1
            fi
        done
    done
done
# Debug build intermediates dwarf evidence size; keep libraries and source identities.
cp "$profile_build/cpp/librapidyenc.so" "$profile_build/cpp.so"
cp "$profile_build/rust/release/libnzbyenc.so" "$profile_build/rust.so"
rm -rf -- "$profile_build/cpp" "$profile_build/cpp-source" "$profile_build/rust"
exit "$invalid"
