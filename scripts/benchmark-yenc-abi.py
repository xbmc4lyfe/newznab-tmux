#!/usr/bin/env python3
"""Optional native ABI diagnostic; never replaces the PHP caller performance gate.

Use retained benchmark-yenc.php .data/.article fixtures and CPP/Rust libraries.
Each fresh child initializes its library and preallocates all buffers before timing.
Exact bytes, CRC continuation and destination-capacity canaries are checked untimed.
"""

import argparse
import ctypes
import ctypes.util
import hashlib
import json
from pathlib import Path
import platform
import random
import resource
import statistics
import subprocess
import sys
import time
import zlib

GUARD_BYTES = 64
CANARY = 0xC7
OPERATIONS = ("decode-lined", "decode-flat", "crc-whole", "crc-continuation")


def sha256(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def payload_from_article(article):
    """Fixture-only slice matching ArticleFrame payload offsets, including final CRLF."""
    first_end = article.find(b"\n")
    trailer = article.rfind(b"\n=yend ")
    if not article.startswith(b"=ybegin ") or first_end < 0 or trailer <= first_end:
        raise ValueError("Unsupported retained fixture framing")
    return article[first_end + 1 : trailer + 1]


def fixture_bytes(data_path, article_path):
    data = Path(data_path).read_bytes()
    article = Path(article_path).read_bytes()
    payload = payload_from_article(article)
    return data, article, payload


def runtime_identity():
    cpu_path = Path("/proc/cpuinfo")
    return {
        "platform": platform.platform(),
        "machine": platform.machine(),
        "python": sys.version,
        "python_binary": sys.executable,
        "python_binary_sha256": sha256(sys.executable),
        "cpuinfo_sha256": sha256(cpu_path) if cpu_path.is_file() else None,
        "zlib_compile_version": zlib.ZLIB_VERSION,
        "zlib_runtime_version": zlib.ZLIB_RUNTIME_VERSION,
    }


def loaded_library_identity(name):
    path = Path(name)
    if not path.is_file() and Path("/proc/self/maps").is_file():
        for line in Path("/proc/self/maps").read_text().splitlines():
            candidate = line.split()[-1]
            if candidate.startswith("/") and Path(candidate).name == path.name:
                path = Path(candidate)
                break
    return {"requested_path": name, "resolved_path": str(path),
            "sha256": sha256(path) if path.is_file() else None}


def load_functions(backend, library_path):
    if backend == "zlib":
        name = ctypes.util.find_library("z")
        if not name:
            raise RuntimeError("No existing zlib C library found; no installation attempted")
        library = ctypes.CDLL(name)
        crc = getattr(library, "crc32_z", None)
        if crc is not None:
            crc.argtypes = (ctypes.c_ulong, ctypes.c_void_p, ctypes.c_size_t)
        else:
            crc = library.crc32
            crc.argtypes = (ctypes.c_ulong, ctypes.c_void_p, ctypes.c_uint)
        crc.restype = ctypes.c_ulong
        library.zlibVersion.argtypes = ()
        library.zlibVersion.restype = ctypes.c_char_p
        identity = loaded_library_identity(name)
        identity["zlib_version"] = library.zlibVersion().decode("ascii")
        return library, None, lambda address, length, seed: int(crc(seed, address, length)) & 0xFFFFFFFF, identity
    name = str(Path(library_path).resolve())
    library = ctypes.CDLL(name)
    library.rapidyenc_decode_init.argtypes = ()
    library.rapidyenc_decode_init.restype = None
    library.rapidyenc_decode_init()
    decode = library.rapidyenc_decode_ex
    decode.argtypes = (ctypes.c_int, ctypes.c_void_p, ctypes.c_void_p, ctypes.c_size_t, ctypes.c_void_p)
    decode.restype = ctypes.c_size_t
    library.rapidyenc_crc_init.argtypes = ()
    library.rapidyenc_crc_init.restype = None
    library.rapidyenc_crc_init()
    crc = library.rapidyenc_crc
    crc.argtypes = (ctypes.c_void_p, ctypes.c_size_t, ctypes.c_uint32)
    crc.restype = ctypes.c_uint32
    return library, decode, lambda address, length, seed: int(crc(address, length, seed)) & 0xFFFFFFFF, loaded_library_identity(name)


def output_probe(destination, capacity, written, expected):
    all_bytes = bytes(destination)
    output = all_bytes[GUARD_BYTES : GUARD_BYTES + capacity]
    prefix = all_bytes[:GUARD_BYTES]
    suffix = all_bytes[GUARD_BYTES + capacity :]
    length_valid = written == len(expected) and written <= capacity
    unused = output[written:] if written <= capacity else b""
    changed = len(unused) - unused.count(CANARY)
    first_changed = len(unused) - len(unused.lstrip(bytes([CANARY]))) if changed else None
    return {
        "written": written,
        "destination_capacity": capacity,
        "exact_output": length_valid and output[:written] == expected,
        "output_sha256": hashlib.sha256(output[:written]).hexdigest() if written <= capacity else None,
        "prefix_capacity_canary_intact": prefix == bytes([CANARY]) * GUARD_BYTES,
        "suffix_capacity_canary_intact": suffix == bytes([CANARY]) * GUARD_BYTES,
        "unused_capacity_changed_bytes": changed,
        "first_changed_offset_after_returned_length": first_changed,
        "unused_capacity_preview_hex": unused[:64].hex(),
        "unused_capacity_note": "writes inside supplied capacity beyond returned length are observed separately; they are not writes outside capacity",
    }


def valid_output(probe, backend):
    return (probe["exact_output"] and probe["prefix_capacity_canary_intact"]
            and probe["suffix_capacity_canary_intact"]
            and (backend != "rust" or probe["unused_capacity_changed_bytes"] == 0))


def worker(options):
    data, article, lined = fixture_bytes(options.data, options.article)
    payload = lined if options.operation == "decode-lined" else lined.translate(None, b"\r\n")
    library, decode, crc, identity = load_functions(options.backend, options.library)
    expected_crc = zlib.crc32(data)
    source_bytes = payload if options.operation.startswith("decode") else data
    source = ctypes.create_string_buffer(source_bytes, len(source_bytes))
    source_address = ctypes.addressof(source)
    destination = ctypes.create_string_buffer(len(source_bytes) + GUARD_BYTES * 2)
    destination_address = ctypes.addressof(destination) + GUARD_BYTES
    ctypes.memset(ctypes.addressof(destination), CANARY, len(destination))
    split = len(data) // 2
    seed = zlib.crc32(data[:split])
    whole_crc = int(crc(source_address, len(source_bytes), 0)) if not options.operation.startswith("decode") else None
    continuation_crc = int(crc(source_address + split, len(data) - split, seed)) if not options.operation.startswith("decode") else None
    zero_crc = int(crc(source_address, 0, seed))
    if zero_crc != seed or (whole_crc is not None and (whole_crc != expected_crc or continuation_crc != expected_crc)):
        raise ValueError("CRC known-answer/continuation/zero-length check failed")
    if options.operation.startswith("decode"):
        invoke = lambda: int(decode(0, source_address, destination_address, len(payload), None))
    elif options.operation == "crc-continuation":
        invoke = lambda: int(crc(source_address + split, len(data) - split, seed))
    else:
        invoke = lambda: int(crc(source_address, len(data), 0))
    warm_result = invoke()
    warm_probe = output_probe(destination, len(payload), warm_result, data) if decode and options.operation.startswith("decode") else None
    if warm_probe is not None and not valid_output(warm_probe, options.backend):
        return {"status": "incorrect", "library": identity, "warm_output_probe": warm_probe}
    if warm_probe is None and warm_result != expected_crc:
        raise ValueError("Warm CRC result disagrees with independent reference")
    ctypes.memset(ctypes.addressof(destination), CANARY, len(destination))
    results = [None] * options.iterations
    usage_before = resource.getrusage(resource.RUSAGE_SELF)
    started = time.perf_counter_ns()
    for iteration in range(options.iterations):
        results[iteration] = invoke()
    elapsed_ns = time.perf_counter_ns() - started
    usage_after = resource.getrusage(resource.RUSAGE_SELF)
    last_result = results[-1]
    probe = output_probe(destination, len(payload), last_result, data) if warm_probe is not None else None
    correct = valid_output(probe, options.backend) if probe is not None else last_result == expected_crc
    correct = correct and all(result == (len(data) if probe is not None else expected_crc) for result in results)
    peak_rss = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    return {
        "status": "ok" if correct else "incorrect",
        "operation": options.operation, "backend": options.backend,
        "iterations": options.iterations, "batch_elapsed_ns": elapsed_ns,
        "batch_user_cpu_seconds": usage_after.ru_utime - usage_before.ru_utime,
        "batch_system_cpu_seconds": usage_after.ru_stime - usage_before.ru_stime,
        "per_call_ns": elapsed_ns / options.iterations,
        "processed_bytes_per_call": len(payload) if probe is not None else len(data) - (split if options.operation == "crc-continuation" else 0),
        "last_result": last_result, "timed_call_results": results, "expected_crc32": f"{expected_crc:08x}",
        "continuation_seed_crc32": f"{seed:08x}", "zero_length_crc32": f"{zero_crc:08x}",
        "library": identity, "warm_output_probe": warm_probe, "output_probe": probe,
        "data_sha256": hashlib.sha256(data).hexdigest(),
        "article_sha256": hashlib.sha256(article).hexdigest(),
        "script_sha256": sha256(__file__),
        "peak_rss_bytes": peak_rss if sys.platform == "darwin" else peak_rss * 1024,
        "validation_boundary": "warm and final exact bytes/CRC/canaries checked outside timing; timed batch includes Python ctypes invocation/loop overhead",
    }


def run_child(command, prefix, timeout):
    stdout_path = Path(str(prefix) + ".stdout")
    stderr_path = Path(str(prefix) + ".stderr")
    started = time.perf_counter_ns()
    with stdout_path.open("wb") as stdout, stderr_path.open("wb") as stderr:
        try:
            process = subprocess.run(command, stdout=stdout, stderr=stderr, timeout=timeout, check=False)
            exit_code = process.returncode
            status = "ok" if exit_code == 0 else "child_failed"
        except subprocess.TimeoutExpired:
            exit_code = None
            status = "timeout"
        except OSError as error:
            exit_code = None
            status = "spawn_failed"
            stderr.write(str(error).encode())
    result = None
    parse_error = None
    try:
        result = json.loads(stdout_path.read_text())
    except (ValueError, UnicodeError) as error:
        parse_error = str(error)
    if status == "ok" and (not isinstance(result, dict) or result.get("status") != "ok"):
        status = "invalid_child_result"
    if status == "ok" and stderr_path.stat().st_size:
        status = "child_stderr"
    return {"status": status, "exit_code": exit_code, "result": result, "command": command,
            "parse_error": parse_error, "stdout_path": str(stdout_path), "stderr_path": str(stderr_path),
            "process_elapsed_ns": time.perf_counter_ns() - started}


def diagnostic(options):
    fixture_dir = Path(options.fixture_dir).resolve()
    output = Path(options.output_dir).resolve()
    output.mkdir(mode=0o700)
    log = (output / "observations.jsonl").open("x")

    def emit(record):
        line = json.dumps(record, sort_keys=True) + "\n"
        log.write(line)
        log.flush()
        print(line, end="", flush=True)

    try:
        return diagnostic_observations(options, fixture_dir, output, emit)
    except Exception as error:
        emit({"type": "run_failure", "status": "invalid_diagnostic", "error": str(error), "error_class": type(error).__name__})
        return 1
    finally:
        log.close()


def diagnostic_observations(options, fixture_dir, output, emit):
    script = Path(__file__).resolve()
    libraries = {"cpp": Path(options.cpp_library or fixture_dir / "cpp.so").resolve(),
                 "rust": Path(options.rust_library or fixture_dir / "rust.so").resolve()}
    _, _, _, zlib_identity = load_functions("zlib", None)
    fixture_paths = sorted(fixture_dir.glob("*.data"))
    protocol = {"type": "protocol", "version": "yenc-abi-diagnostic-v1",
                "claim": "explanatory ABI screen only; no caller merge-gate decision or production-capacity claim",
                "script_sha256": sha256(script), "runtime": runtime_identity(),
                "libraries": {name: {"path": str(path), "sha256": sha256(path)} for name, path in libraries.items()},
                "zlib_reference_library": zlib_identity,
                "order_seed": options.seed, "samples": options.samples, "iterations": options.iterations,
                "warmup_calls": 1,
                "timeout_seconds": options.timeout, "operations": OPERATIONS,
                "crc_reference_backend": "existing zlib C ABI; all three measured through ctypes over the same preallocated bytes",
                "timed_boundary": "native function calls with preallocated resident buffers; initialization, fixture extraction, exact verification, guard reset, CRC seeds and hashing untimed"}
    if options.runtime_manifest:
        path = Path(options.runtime_manifest)
        protocol["build_runtime_manifest"] = {"sha256": sha256(path), "contents": json.loads(path.read_text())}
    protocol["protocol_sha256"] = hashlib.sha256(json.dumps(protocol, sort_keys=True).encode()).hexdigest()
    emit(protocol)
    randomizer = random.Random(options.seed)
    summaries = []
    invalid_count = 0
    if not fixture_paths:
        raise ValueError("No retained .data fixtures found")
    for data_path in fixture_paths:
        article_path = data_path.with_suffix(".article")
        data, article, payload = fixture_bytes(data_path, article_path)
        fixture = {"name": data_path.stem, "decoded_bytes": len(data), "article_bytes": len(article),
                   "lined_payload_bytes": len(payload), "data_sha256": sha256(data_path),
                   "article_sha256": sha256(article_path), "lined_payload_sha256": hashlib.sha256(payload).hexdigest(),
                   "flat_payload_sha256": hashlib.sha256(payload.translate(None, b"\r\n")).hexdigest(),
                   "data_path": str(data_path), "article_path": str(article_path)}
        emit({"type": "fixture", **fixture})
        medians = {}
        for operation in OPERATIONS:
            backends = ["cpp", "rust"] + (["zlib"] if operation.startswith("crc") else [])
            observations = {name: [] for name in backends}
            failures = {name: 0 for name in backends}
            for sample in range(options.samples):
                order = backends.copy()
                randomizer.shuffle(order)
                for position, backend in enumerate(order):
                    command = [sys.executable, str(script), "--backend", backend, "--operation", operation,
                               "--data", str(data_path), "--article", str(article_path), "--iterations", str(options.iterations)]
                    if backend != "zlib":
                        command += ["--library", str(libraries[backend])]
                    prefix = output / f"{data_path.stem}-{operation}-{sample}-{backend}"
                    record = run_child(command, prefix, options.timeout)
                    result = record.get("result") or {}
                    eligible = record["status"] == "ok" and result.get("status") == "ok"
                    if eligible:
                        identity_matches = (result.get("data_sha256") == fixture["data_sha256"]
                                            and result.get("article_sha256") == fixture["article_sha256"]
                                            and result.get("script_sha256") == protocol["script_sha256"])
                        if backend != "zlib":
                            identity_matches = identity_matches and result.get("library", {}).get("sha256") == protocol["libraries"][backend]["sha256"]
                        else:
                            identity_matches = identity_matches and result.get("library") == protocol["zlib_reference_library"]
                        if not identity_matches:
                            record["status"] = "identity_changed"
                            eligible = False
                    if eligible:
                        observations[backend].append(result["per_call_ns"])
                    else:
                        failures[backend] += 1
                        invalid_count += 1
                    emit({"type": "observation", "fixture": fixture["name"], "operation": operation,
                          "backend": backend, "sample": sample, "position": position, "order": order,
                          "eligible": eligible, **record})
            for backend in backends:
                values = observations[backend]
                median = statistics.median(values) if values and failures[backend] == 0 else None
                medians[(operation, backend)] = median
                summary = {"type": "operation_summary", "fixture": fixture["name"], "operation": operation,
                           "backend": backend, "eligible_observations": len(values), "invalid_observations": failures[backend],
                           "median_per_call_ns": median,
                           "min_per_call_ns": min(values) if values else None, "max_per_call_ns": max(values) if values else None}
                summaries.append(summary)
                emit(summary)
        estimates = {}
        for backend in ("cpp", "rust"):
            decode = medians[("decode-lined", backend)]
            crc = medians[("crc-whole", backend)]
            estimates[backend] = {"decode_lined_ns": decode, "whole_crc_ns": crc,
                                  "estimated_native_decode_plus_crc_ns": decode + crc if decode is not None and crc is not None else None,
                                  "crc_fraction_of_estimated_native_work": crc / (decode + crc) if decode is not None and crc is not None else None}
        emit({"type": "cost_estimate", "fixture": fixture["name"], "backends": estimates,
              "basis": "sum of independent native-call medians; excludes PHP framing, FFI allocation/copies, NNTP parsing and caller CRC scheduling; not a measured end-to-end total"})
    emit({"type": "run_summary", "status": "complete" if invalid_count == 0 else "partial_diagnostic",
          "invalid_observations": invalid_count, "operation_summaries": len(summaries),
          "claim": protocol["claim"]})
    return 0 if invalid_count == 0 else 1


def arguments():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--fixture-dir")
    parser.add_argument("--output-dir")
    parser.add_argument("--cpp-library")
    parser.add_argument("--rust-library")
    parser.add_argument("--runtime-manifest")
    parser.add_argument("--samples", type=int, default=11)
    parser.add_argument("--iterations", type=int, default=8)
    parser.add_argument("--seed", type=int, default=20261013)
    parser.add_argument("--timeout", type=float, default=30)
    parser.add_argument("--backend", choices=("cpp", "rust", "zlib"))
    parser.add_argument("--operation", choices=OPERATIONS)
    parser.add_argument("--library")
    parser.add_argument("--data")
    parser.add_argument("--article")
    options = parser.parse_args()
    if options.samples < 1 or options.samples > 101 or options.iterations < 1 or options.iterations > 1000 or not 0 < options.timeout <= 300:
        parser.error("Samples 1..101, iterations 1..1000 and timeout (0,300] are required")
    if options.backend:
        if not options.operation or not options.data or not options.article or (options.backend != "zlib" and not options.library):
            parser.error("Worker requires operation, data, article and native library")
        if options.backend == "zlib" and options.operation.startswith("decode"):
            parser.error("zlib reference supports CRC only")
    elif not options.fixture_dir or not options.output_dir:
        parser.error("Diagnostic requires fixture-dir and a new output-dir")
    return options


if __name__ == "__main__":
    settings = arguments()
    if settings.backend:
        try:
            result = worker(settings)
        except Exception as error:
            result = {"status": "error", "error": str(error), "error_class": type(error).__name__}
        print(json.dumps(result, sort_keys=True))
        sys.exit(0 if result["status"] == "ok" else 1)
    sys.exit(diagnostic(settings))
