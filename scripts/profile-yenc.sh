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
preflight_exit=0
timeout --kill-after=2s 10s sudo -n "$perf_binary" record -e cpu-clock -F 99 \
    -o "$profile_build/perf-preflight.data" -- /bin/sleep 0.1 \
    > "$profile_build/perf-preflight.stdout" 2> "$profile_build/perf-preflight.stderr" || preflight_exit=$?
printf '%s\n' "$preflight_exit" > "$profile_build/perf-preflight-exit.txt"
if [[ "$preflight_exit" == 0 ]]; then
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
    workloads=(ordinary-strict ordinary-tolerant ordinary-body heavy-strict heavy-tolerant heavy-body)
    if [[ "$backend" == rust ]]; then
        workloads+=(rar5-metadata)
    fi
    for workload in "${workloads[@]}"; do
            density=${workload%-*}
            path=${workload##*-}
            directory="$evidence/profiles/$backend-$density-$path"
            mkdir -p "$directory"
            if [[ "$path" == metadata ]]; then
                args=(scripts/profile-yenc.php "--native=$library" --path=metadata --iterations=2000)
            else
                args=(scripts/profile-yenc.php "--native=$library" "--path=$path" \
                    --size=524288 "--density=$density" --iterations=400)
            fi
            php_exit=0
            timeout --kill-after=2s 20s env XDEBUG_MODE=profile php -d ffi.enable=true \
                -d memory_limit=1G -d xdebug.start_with_request=yes -d xdebug.use_compression=0 \
                "-d xdebug.output_dir=$directory" -d xdebug.profiler_output_name=php.callgrind \
                "${args[@]}" > "$directory/php-workload.json" 2> "$directory/php.stderr" || php_exit=$?
            # perf needs kernel access on hosted runners. sudo is confined to this
            # disposable job; unavailable counters are retained as such, never a pass.
            native_exit=unavailable
            postprocess_exit=0
            postprocess_stage=not_run
            if [[ "$perf_available" == 1 ]]; then
              native_exit=0
              timeout --kill-after=2s 20s sudo -n env XDEBUG_MODE=off "$perf_binary" record \
                -e cpu-clock -F 99 --call-graph dwarf,16384 -o "$directory/perf.data" -- \
                "$(command -v php)" -d ffi.enable=true -d memory_limit=1G "${args[@]}" \
                > "$directory/native-workload.json" 2> "$directory/perf.stderr" || native_exit=$?
              if [[ -f "$directory/perf.data" ]]; then
                  sudo -n chmod a+r "$directory/perf.data"
              fi
              if [[ "$native_exit" == 0 ]]; then
                # Caller owns the redirected stack output; sudo only reads perf.data.
                postprocess_stage=perf-script
                # shellcheck disable=SC2024
                sudo -n "$perf_binary" script -i "$directory/perf.data" > "$directory/perf.stacks" 2> "$directory/perf-script.stderr" || postprocess_exit=$?
                if [[ "$postprocess_exit" == 0 ]]; then
                    postprocess_stage=stack-collapse
                    perl "$flamegraph/stackcollapse-perf.pl" "$directory/perf.stacks" > "$directory/native.folded" 2> "$directory/collapse.stderr" || postprocess_exit=$?
                fi
                if [[ "$postprocess_exit" == 0 && -s "$directory/native.folded" ]]; then
                    postprocess_stage=flamegraph
                    perl "$flamegraph/flamegraph.pl" --title "$backend / $density / $path — whole-process CPU samples" \
                        "$directory/native.folded" > "$directory/native.svg" 2> "$directory/flamegraph.stderr" || postprocess_exit=$?
                    if [[ "$postprocess_exit" == 0 ]]; then
                        postprocess_stage=complete
                    fi
                fi
              fi
            fi
            # Keep workload correctness distinct from sampler availability.
            if ! python3 scripts/yenc-performance-report.py --record-profile-status="$directory" \
                --native-exit="$native_exit" --php-exit="$php_exit" \
                --postprocess-exit="$postprocess_exit" --postprocess-stage="$postprocess_stage" \
                --preflight-exit="$preflight_exit";
            then
                invalid=1
            fi
    done
done
# Debug build intermediates dwarf evidence size; keep libraries and source identities.
cp "$profile_build/cpp/librapidyenc.so" "$profile_build/cpp.so"
cp "$profile_build/rust/release/libnzbyenc.so" "$profile_build/rust.so"
(cd "$profile_build" && sha256sum cpp.so rust.so > retained-libraries.sha256)
rm -rf -- "$profile_build/cpp" "$profile_build/cpp-source" "$profile_build/rust"
exit "$invalid"
