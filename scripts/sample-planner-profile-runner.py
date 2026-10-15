#!/usr/bin/env python3
"""SOURCE ONLY: bounded private attribution orchestration; never adoption evidence."""
import argparse
import ctypes
import errno
import hashlib
import json
import os
from pathlib import Path
import platform
import resource
import selectors
import signal
import subprocess
import sys
import time

ARCHIVE = '8011646596debf96645b1d18ede0afb0e66e098471ea3ec34fd77215a7005e8a'
LIBRARY = '02eeb2be7d182cbc046cd5358792795825acf9daac549a020afc0fb2ea847213'
PACKET_MANIFEST = 'f20e41e1bd48e47e8888fa9341bde3bb8ccc89802ebc3264ed8400724c223d16'
PROTOCOL = 'f40aa2daf2ae6aad4f0db73627511b4b6a11cc1ead36812e3d2770a971bd70f2'
SOURCE = 'aa43ff2b697dbdd91fef8d8de104579181828772a72a5db1bf0468e5950d8f42'
TOLERANCE_NS = 10_000
WORKER_SECONDS = 30
BATCH_SECONDS = 600
CLEANUP_RESERVE_SECONDS = 5
MAX_CAPTURE = 8 * 1024 * 1024  # Per stream; overflow invalidates, retained prefix is labeled.
MAX_RESULT = 8 * 1024 * 1024
STRATA = ['small-dispatch', 'boundary-1024', 'large-16384', 'duplicates-inferred', 'tight-budget', 'unsupported-coercion']
PHP_FLAGS = ['-d', 'ffi.enable=1', '-d', 'opcache.enable_cli=0', '-d', 'opcache.jit=1235', '-d', 'memory_limit=-1']
RUN_DEADLINE = None
CANCELLED = 0
EXPECTED_RUNTIME = {'php': '8.5.11', 'os': 'Linux', 'architecture': 'x86_64', 'sapi': 'cli', 'ffi': '1',
                    'opcache_cli': '0', 'jit': '1235', 'memory_limit': '-1'}


def digest(path):
    h = hashlib.sha256()
    with Path(path).open('rb') as f:
        for block in iter(lambda: f.read(1024 * 1024), b''):
            if RUN_DEADLINE is not None and time.monotonic() >= RUN_DEADLINE:
                raise TimeoutError('Batch deadline during input hashing')
            h.update(block)
    return h.hexdigest()


def load_json(path, limit=MAX_RESULT):
    path = Path(path)
    if path.stat().st_size > limit:
        raise ValueError('JSON size limit: ' + str(path))
    def no_duplicates(pairs):
        out = {}
        for k, v in pairs:
            if k in out:
                raise ValueError('Duplicate JSON key: ' + k)
            out[k] = v
        return out
    return json.loads(path.read_text(), object_pairs_hook=no_duplicates)


def write_json(path, value):
    # All paths are owned, new output paths; append-only worker evidence is never replaced.
    with Path(path).open('x') as f:
        json.dump(value, f, indent=2)
        f.write('\n')


def demand(condition, message):
    if not condition:
        raise ValueError(message)


def proc_table(deadline):
    result = {}
    entries = list(Path('/proc').iterdir())
    demand(len(entries) <= 65536, 'Unbounded /proc inventory')
    for entry in entries:
        if not entry.name.isdigit():
            continue
        demand(time.monotonic() < deadline, 'Process inspection deadline')
        try:
            raw = (entry / 'stat').read_text()
        except FileNotFoundError:
            continue
        # Permission/parse errors fail closed. Process names can contain spaces and ')'.
        fields = raw[raw.rfind(')') + 2:].split()
        demand(len(fields) >= 20, 'Malformed /proc stat')
        result[int(entry.name)] = {'state': fields[0], 'ppid': int(fields[1]), 'pgrp': int(fields[2]),
                                   'session': int(fields[3]), 'start': int(fields[19])}
    return result


def descendants(table):
    found = set()
    frontier = {os.getpid()}
    while frontier:
        more = {pid for pid, row in table.items() if row['ppid'] in frontier and pid not in found}
        found.update(more)
        frontier = more
    return found


def subreaper_setup():
    demand(sys.platform == 'linux' and platform.machine() == 'x86_64', 'Actual x86_64 Linux required')
    demand(hasattr(os, 'pidfd_open') and hasattr(signal, 'pidfd_send_signal'), 'pidfd APIs unavailable')
    libc = ctypes.CDLL(None, use_errno=True)
    libc.prctl.restype = ctypes.c_int
    demand(libc.prctl(36, 1, 0, 0, 0) == 0, 'Cannot set child subreaper')
    enabled = ctypes.c_int(0)
    demand(libc.prctl(37, ctypes.byref(enabled), 0, 0, 0) == 0 and enabled.value == 1,
           'Cannot confirm child subreaper')
    # Kernel ECHILD establishes no unrelated child ownership at initial boundary.
    try:
        os.waitpid(-1, os.WNOHANG)
    except ChildProcessError:
        return
    raise ValueError('Runner already owns children')


def cancellation(signum, frame):
    # Mark only: handlers never interrupt/reenter ownership cleanup or file writes.
    global CANCELLED
    CANCELLED = signum


def closure(process, deadline, handles):
    """Always signal retained pidfds; closure requires both /proc and kernel ECHILD."""
    evidence = {'signals': [], 'reaped': [], 'closed': False, 'inspection_errors': [],
                'leader_pid': process.pid if process else None}

    def kill_known():
        for pid, info in handles.items():
            try:
                signal.pidfd_send_signal(info['fd'], signal.SIGKILL)
                evidence['signals'].append({'pid': pid, 'start': info['start'], 'signal': 'SIGKILL'})
            except ProcessLookupError:
                pass
            except OSError as exc:
                evidence['inspection_errors'].append('pidfd signal: '+repr(exc))

    def reap():
        if process:
            process.poll()  # Let Popen reap its leader before generic waitpid.
            if process.returncode is None:
                return False
        while True:
            try:
                pid, status = os.waitpid(-1, os.WNOHANG)
            except ChildProcessError:
                return True
            if pid == 0:
                return False
            evidence['reaped'].append({'pid': pid, 'wait_status': status})

    try:
        # Initial leader pidfd was acquired immediately after Popen, before inventory.
        # Even a first /proc failure cannot bypass the retained-handle kill in finally.
        while time.monotonic() < deadline:
            table = proc_table(deadline)
            owned = descendants(table)
            for pid in sorted(owned):
                if pid in handles:
                    continue
                row = table[pid]
                try:
                    fd = os.pidfd_open(pid, 0)
                except ProcessLookupError:
                    continue
                try:
                    current = proc_table(deadline).get(pid)
                    if current is None:
                        os.close(fd)
                        continue
                    demand(current['start'] == row['start'], 'PID identity changed during cleanup')
                    handles[pid] = {'fd': fd, 'start': row['start']}
                except BaseException:
                    os.close(fd)
                    raise
            kill_known()
            kernel_empty = reap()
            after = proc_table(deadline)
            owned_after = descendants(after)
            group_after = [pid for pid, row in after.items() if process and
                           (row['pgrp'] == process.pid or row['session'] == process.pid)]
            if kernel_empty and not owned_after and not group_after and not evidence['inspection_errors']:
                evidence.update(closed=True, kernel_echild=True, owned_descendants=[], worker_group_members=[])
                return evidence
            time.sleep(0.01)
    except BaseException as exc:
        evidence['inspection_errors'].append(repr(exc))
    finally:
        # Inspection/deadline failure still terminates every retained, stable owned handle.
        # This cannot prove closure of undiscovered children; never promote it to closed.
        if not evidence['closed']:
            kill_known()
            try:
                evidence['fallback_kernel_echild'] = reap()
            except BaseException as exc:
                evidence['inspection_errors'].append('fallback reap: '+repr(exc))
        for info in handles.values():
            os.close(info['fd'])
    evidence['failure'] = 'Closure UNPROVEN; abort batch and require fresh GitHub VM/job outer containment'
    return evidence


def resource_snapshot():
    data = {'monotonic_ns': time.monotonic_ns(), 'children_usage': list(resource.getrusage(resource.RUSAGE_CHILDREN))}
    for name in ['cpu.stat', 'memory.current', 'memory.events', 'cpu.max', 'memory.max']:
        try:
            data[name] = (Path('/sys/fs/cgroup') / name).read_text()
        except OSError as exc:
            data[name] = {'unavailable': repr(exc)}
    return data


def run_worker(command, folder, worker_deadline, batch_deadline):
    folder.mkdir()
    record = {'argv': command, 'before': resource_snapshot(), 'timeout_seconds': WORKER_SECONDS,
              'stdout_truncated': False, 'stderr_truncated': False, 'output_completeness_unknown': True}
    write_json(folder / 'command.json', record)
    started = time.monotonic()
    # No shell and no inherited application/provider environment. PHP uses reviewed system ini.
    env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'}
    selector = selectors.DefaultSelector()
    sizes = {'stdout': 0, 'stderr': 0}
    files = {}
    process = None
    handles = {}
    eof = set()
    try:
        files = {name: (folder / (name + '.raw')).open('xb') for name in sizes}
        process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   start_new_session=True, close_fds=True, cwd=folder, env=env)
        record['pid'] = process.pid
        handles[process.pid] = {'fd': os.pidfd_open(process.pid, 0), 'start': None}
        for name, pipe in [('stdout', process.stdout), ('stderr', process.stderr)]:
            os.set_blocking(pipe.fileno(), False)
            selector.register(pipe, selectors.EVENT_READ, name)
        while selector.get_map():
            if CANCELLED:
                record['failure'] = 'signal_cancelled'
                break
            if time.monotonic() >= worker_deadline:
                record['failure'] = 'worker_timeout_or_batch_deadline'
                break
            for key, _ in selector.select(timeout=min(0.05, max(0, worker_deadline-time.monotonic()))):
                block = os.read(key.fileobj.fileno(), 65536)
                if not block:
                    eof.add(key.data)
                    selector.unregister(key.fileobj)
                    continue
                name = key.data
                remaining = max(0, MAX_CAPTURE-sizes[name])
                files[name].write(block[:remaining])
                sizes[name] += len(block)
                if sizes[name] > MAX_CAPTURE:
                    record[name + '_truncated'] = True
                    record['failure'] = 'capture_limit'
            if record.get('failure'):
                break
        if not record.get('failure'):
            while process.poll() is None:
                if CANCELLED or time.monotonic() >= worker_deadline:
                    record['failure'] = 'signal_cancelled' if CANCELLED else 'worker_timeout_or_batch_deadline'
                    break
                time.sleep(0.01)
    except BaseException as exc:
        record['failure'] = 'observer_exception: ' + repr(exc)
    finally:
        # Cleanup always runs, even leader success: orphaned helpers cannot leak into next pair.
        record['closure'] = closure(process, min(batch_deadline, time.monotonic()+CLEANUP_RESERVE_SECONDS), handles)
        record['output_completeness_unknown'] = eof != {'stdout', 'stderr'} or any(record[name+'_truncated'] for name in ['stdout', 'stderr'])
        record['pipe_eof_observed'] = sorted(eof)
        record['cancelled_signal'] = CANCELLED
        if record['closure'].get('signals') and not record.get('failure'):
            record['failure'] = 'unexpected_descendants_after_worker_exit'
        if process:
            for stream in [process.stdout, process.stderr]:
                if stream:
                    stream.close()
        selector.close()
        for stream in files.values():
            stream.close()
        record.update(returncode=process.returncode if process else None, elapsed_seconds=time.monotonic()-started,
                      captured_bytes_seen=sizes, after=resource_snapshot())
        write_json(folder / 'process.json', record)
    return record


def trace_validate(observations, stratum):
    previous = []
    expected_roles = [] if stratum == 'small-dispatch' else ([1] if stratum == 'unsupported-coercion' else [1, 2, 2, 2])
    final_events = None
    spans = []
    for phase_id, phase in enumerate(['cold', 'warmup', 'warm']):
        row = observations[phase]
        observer = row['observer']
        demand(observer['capacity'] == 128 and observer['invocation_capacity'] == 8 and observer['overflow'] is False,
               'Trace capacity/overflow invalid')
        events = observer['events']
        demand(isinstance(events, list) and len(events) <= 128 and events[:len(previous)] == previous,
               'Trace overwritten or unbounded')
        new = events[len(previous):]
        for event in new:
            demand(isinstance(event, list) and len(event) == 8 and all(type(v) is int for v in event), 'Nonscalar event')
            ph, public, invocation, role, stage, start, end, closed = event
            demand(ph == public == phase_id and 1 <= stage <= 12 and closed == 1 and start <= end,
                   'Phase/ordinal/stage/unclosed interval invalid')
            demand(row['started_ns'] <= start <= end <= row['ended_ns'], 'Trace escaped exact caller boundary')
            demand((role == 0 and invocation == 0) or (role in [1, 2] and 1 <= invocation <= 8), 'Role/ordinal invalid')
            demand((stage in [7, 8, 9, 10]) == (role in [1, 2]), 'Wrong role for stage')
        # Frozen outer dispatch calls the original Native class, outside this observer.
        # Only the profiled select's inner support/admission remains in the trace.
        common = [3, 2, 1, 6, 11, 10, 7, 8, 9, 4, 5, 10, 7, 8, 9, 12] if phase_id == 0 else [3, 2, 1, 6, 4, 5, 10, 7, 8, 9, 12]
        expected_stages = [] if stratum == 'small-dispatch' else ((common[:10] if phase_id == 0 else [3, 2, 1, 6, 4]) if stratum == 'unsupported-coercion' else common)
        demand([event[4] for event in new] == expected_stages, 'Unexpected exact phase stage sequence')
        # Events are appended at entry: infer parent from strict contained intervals, preserving order.
        stack = []
        direct_children = {}
        for index, event in enumerate(new):
            while stack and event[5] >= new[stack[-1]][6]:
                stack.pop()
            parent = stack[-1] if stack else None
            if parent is not None:
                p = new[parent]
                demand(p[5] <= event[5] <= event[6] <= p[6], 'Child span not exactly contained')
                direct_children.setdefault(parent, []).append(index)
                allowed = {2: {1}, 3: {2, 4, 5, 6, 10, 12}, 6: {10, 11}, 10: {7, 8, 9}}
                demand(event[4] in allowed.get(p[4], set()), 'Unexpected parent stage')
                if p[4] == 10:
                    demand(event[2:4] == p[2:4], 'Invocation child mismatch')
                if p[4] == 6 and event[4] == 10:
                    demand(event[3] == 1, 'Library invocation is not probe')
                if p[4] == 3 and event[4] == 10:
                    demand(event[3] == 2, 'Select invocation is not workload')
            else:
                demand(event[4] == 3, 'Unexpected root span')
            stack.append(index)
        for parent_index, children in direct_children.items():
            p = new[parent_index]
            demand(all(new[a][6] <= new[b][5] for a, b in zip(children, children[1:])), 'Sibling overlap')
            child_ns = sum(new[i][6]-new[i][5] for i in children)
            parent_ns = p[6]-p[5]
            demand(child_ns <= parent_ns+TOLERANCE_NS, 'Child sum exceeds parent+tolerance')
            # Retain negative residuals; never clip or claim adoption.
            spans.append({'phase': phase, 'stage': p[4], 'invocation': p[2], 'inclusive_ns': parent_ns,
                          'immediate_child_ns': child_ns, 'exclusive_ns': parent_ns-child_ns})
        roots = [event for event in new if not any(other[5] <= event[5] and event[6] <= other[6]
                 and other is not event and (other[5] < event[5] or event[6] < other[6]) for other in new)]
        root_sum = sum(event[6]-event[5] for event in roots)
        demand(root_sum <= row['elapsed_ns']+TOLERANCE_NS, 'Root sum exceeds caller')
        spans.append({'phase': phase, 'stage': 'complete_caller_residual', 'exclusive_ns': row['elapsed_ns']-root_sum})
        calls = [event for event in events if event[4] == 10]
        demand(observer['invocations'] == len(calls), 'Invocation count mismatch')
        previous = events
        final_events = events
    calls = [event for event in final_events if event[4] == 10]
    demand([event[2] for event in calls] == list(range(1, len(calls)+1)), 'Missing/extra invocation ordinal')
    demand([event[3] for event in calls] == expected_roles, 'Missing/extra probe/workload')
    expected_phases = [] if not expected_roles else ([0] if len(expected_roles) == 1 else [0, 0, 1, 2])
    demand([event[0] for event in calls] == expected_phases, 'Invocation phase mismatch')
    for call in calls:
        children = [event for event in final_events if event[2] == call[2] and event[4] != 10]
        demand([event[4] for event in children] == [7, 8, 9], 'Missing/extra aggregate/string/decode stage')
    expected_counts = [0, 0, 0] if stratum == 'small-dispatch' else ([10, 5, 5] if stratum == 'unsupported-coercion' else [16, 11, 11])
    demand([sum(event[0] == ph for event in final_events) for ph in range(3)] == expected_counts, 'Missing/extra phase spans')
    return spans


def validate_result(path, arm, stratum, fixture, reference, library, packet, source, frozen, frozen_hashes, packet_hashes):
    data = load_json(path)
    demand(data['status'] == 'observed_requires_external_validation' and data['arm'] == arm, 'Result status/arm mismatch')
    demand(data['fixture_sha256'] == digest(fixture) and data['library_sha256'] == LIBRARY == digest(library), 'Input identity mismatch')
    demand(data['runtime'] == EXPECTED_RUNTIME, 'Runtime differs from frozen PHP receipt')
    wrapper = packet/'NativeSpotCheckPlanner.instrumented.php' if arm == 'instrumented' else source/'app/Services/Releases/Verification/NativeSpotCheckPlanner.php'
    demand(Path(data['loaded_wrapper']).resolve() == wrapper.resolve() and data['wrapper_sha256'] == digest(wrapper), 'Wrapper path/hash mismatch')
    for name, expected in data['included_files'].items():
        included = Path(name).resolve()
        demand(included.is_relative_to(source) or included in {wrapper.resolve(), (packet/'driver.php').resolve()}, 'Included file escaped private frozen runtime')
        authoritative = (frozen_hashes.get(str(included.relative_to(frozen))) if included.is_relative_to(source)
                         else packet_hashes.get(included.name))
        demand(authoritative is not None and expected == authoritative == digest(included), 'Included file differs from frozen preflight identity')
    observations = data['observations']
    demand(set(observations) == {'cold', 'warmup', 'warm'}, 'Missing/extra public phase')
    expected_attempts = 0 if stratum == 'small-dispatch' else 1
    expected_success = 0 if stratum in ['small-dispatch', 'unsupported-coercion'] else 1
    exact_reference = reference.read_bytes()
    for row in observations.values():
        demand(row['correct'] is True, 'Fixture property check failed')
        demand(row['serialized_result'].encode('utf-8') == exact_reference, 'Exact serialized reference mismatch')
        demand(row['native_attempts'] == expected_attempts and row['native_successes'] == expected_success, 'Native execution/fallback mismatch')
        demand(type(row['elapsed_ns']) is int and row['elapsed_ns'] >= 0 and row['ended_ns']-row['started_ns'] == row['elapsed_ns'], 'Caller clock mismatch')
        demand(type(row['observer_readout_ns']) is int and row['observer_readout_ns'] >= 0, 'Readout clock missing')
        if arm == 'control':
            demand(row['observer'] is None, 'Control observer is not empty')
    spans = trace_validate(observations, stratum) if arm == 'instrumented' else []
    return {'valid': True, 'spans': spans, 'latency_ns': {k: v['elapsed_ns'] for k, v in observations.items()}}


def main():
    global RUN_DEADLINE
    parser = argparse.ArgumentParser(description=__doc__)
    for option in ['packet', 'frozen-root', 'library', 'output', 'protocol']:
        parser.add_argument('--'+option, type=Path, required=True)
    args = parser.parse_args()
    packet, frozen, library, output = [p.resolve() for p in [args.packet, args.frozen_root, args.library, args.output]]
    # --frozen-root is the extraction root containing sample-planning/, not its source/ child.
    protocol = args.protocol.resolve()
    source = frozen/'sample-planning/source'
    demand(not output.exists(), 'Output must be new; no evidence overwrite or favorable rerun')
    demand(not output.is_relative_to(packet) and not output.is_relative_to(frozen), 'Output must be separate from frozen inputs')
    output.mkdir(parents=False)
    batch_start = time.monotonic()
    deadline = batch_start+BATCH_SECONDS
    RUN_DEADLINE = deadline
    summary = {'status': 'invalid_until_complete', 'attribution_only': True, 'adoption_qualified': False,
               'tolerance_ns': TOLERANCE_NS, 'worker_seconds': WORKER_SECONDS, 'batch_seconds': BATCH_SECONDS,
               'kernel_monotonic_resolution_ns': round(time.clock_getres(time.CLOCK_MONOTONIC)*1e9),
               'python_clock_info': vars(time.get_clock_info('monotonic')), 'pairs': [], 'failures': []}
    for signum in [signal.SIGTERM, signal.SIGINT]:
        signal.signal(signum, cancellation)
    try:
        subreaper_setup()
        demand(digest(packet/'manifest.json') == PACKET_MANIFEST, 'Reviewed packet manifest pin mismatch')
        demand(digest(protocol) == PROTOCOL, 'Reviewed protocol pin mismatch')
        summary['protocol_binding'] = {'path': str(protocol), 'sha256': PROTOCOL, 'content': load_json(protocol)}
        manifest = load_json(packet/'manifest.json')
        for name, expected in manifest['files'].items():
            demand(Path(name).name == name and digest(packet/name) == expected, 'Packet identity mismatch: '+name)
        demand(manifest['archive_sha256'] == ARCHIVE and manifest['source_sha256'] == SOURCE, 'Frozen packet origins mismatch')
        demand(digest(source/'app/Services/Releases/Verification/NativeSpotCheckPlanner.php') == SOURCE and digest(library) == LIBRARY, 'Original source/library mismatch')
        demand(library == (frozen/'sample-planning/native.so').resolve(), 'Library escaped verified artifact')
        receipt = load_json(frozen/'attribution-preflight.json')
        summary['preflight'] = receipt  # Retain rejected preflight evidence as well.
        demand(receipt.get('archive_sha256') == ARCHIVE and receipt.get('library_sha256') == LIBRARY,
               'External preflight origin mismatch')
        for key in ['authorized', 'vendor_source_identity_verified', 'original_build_runtime_api_receipts_verified',
                    'actual_x86_64_verified', 'elf_notes_dependencies_cpu_flags_captured', 'fresh_github_hosted_vm_job_containment', 'php_8_5_11_ini_verified']:
            demand(receipt.get(key) is True, 'Missing external fail-closed preflight: '+key)
        demand(receipt.get('protocol_sha256') == PROTOCOL, 'Preflight protocol binding mismatch')
        demand(receipt.get('approving_coordinator_thread') == '01a12d8e-7f36-7fa3-81b8-f6f988ce9a02', 'ISA policy approving coordinator mismatch')
        demand(isinstance(receipt.get('host_cpu_flags'), list) and bool(receipt['host_cpu_flags'])
               and all(isinstance(flag, str) for flag in receipt['host_cpu_flags']), 'Actual host CPU flags missing')
        elf_evidence = receipt.get('elf_evidence', {})
        demand(isinstance(elf_evidence, dict) and all(key in elf_evidence for key in ['notes', 'dependencies', 'version_requirements']), 'Captured ELF evidence missing')
        for key, item in elf_evidence.items():
            path = (frozen/item['path']).resolve()
            demand(path.is_relative_to(frozen) and digest(path) == item['sha256'], 'ELF evidence identity mismatch: '+key)
        isa = receipt.get('historical_isa')
        demand(isinstance(isa, dict) and isa.get('state') in ['known', 'unknown'], 'Historical ISA status must be explicit')
        demand(isa.get('policy') == 'private_attribution_only_reuse_no_rebuild_no_portability_claim', 'Historical ISA policy mismatch')
        if isa['state'] == 'known':
            demand(isa.get('required_features_supported') is True, 'Known required ISA unsupported')
        else:
            demand(isa.get('coordinator_unknown_isa_allowed') is True, 'Unknown ISA lacks private-attribution allowance')
        demand(receipt.get('abi_preflight_status') in ['verified', 'unknown_private_attribution_failures_invalid'], 'ABI preflight policy missing')
        frozen_hashes = receipt.get('frozen_file_sha256', {})
        demand(isinstance(frozen_hashes, dict) and bool(frozen_hashes), 'Frozen source/vendor/fixture/reference hash map missing')
        for name, expected in frozen_hashes.items():
            path = (frozen/name).resolve()
            demand(path.is_relative_to(frozen) and digest(path) == expected, 'Frozen preflight file identity mismatch: '+name)
        demand(receipt.get('packet_manifest_sha256') == digest(packet/'manifest.json'), 'Packet manifest differs from frozen preflight')
        summary['preflight'] = receipt
        summary['packet_manifest_sha256'] = digest(packet/'manifest.json')
        order = load_json(packet/'order.json')
        demand(order['seed'] == 20261016 and len(order['schedule']) == 186, 'Order shape mismatch')
        seen = set()
        for item in order['schedule']:
            key = (item['stratum'], item['pair'])
            demand(item['stratum'] in STRATA and type(item['pair']) is int and 0 <= item['pair'] < 31
                   and item['variant'] == 0 and sorted(item['arms']) == ['control', 'instrumented'] and key not in seen,
                   'Order entry invalid')
            seen.add(key)
        summary['input_hashes'] = {}
        for name in STRATA:
            for suffix in ['.fixture.serialized', '-0-php.result.serialized']:
                path = frozen/'sample-planning'/(name+suffix)
                observed = digest(path)
                demand(frozen_hashes.get(str(path.relative_to(frozen))) == observed, 'Fixture/reference lacks frozen preflight provenance')
                summary['input_hashes'][str(path)] = observed
        write_json(output/'frozen-run.json', summary)
        for item in order['schedule']:
            stratum = item['stratum']
            pair = {'stratum': stratum, 'pair': item['pair'], 'variant': 0, 'arms': {}, 'valid': False}
            fixture = frozen/'sample-planning'/(stratum+'.fixture.serialized')
            reference = frozen/'sample-planning'/(stratum+'-0-php.result.serialized')
            for arm in item['arms']:
                if CANCELLED:
                    pair['arms'][arm] = {'valid': False, 'failure': 'signal_cancelled_not_started'}
                    continue
                if time.monotonic() >= deadline-CLEANUP_RESERVE_SECONDS:
                    pair['arms'][arm] = {'valid': False, 'failure': 'batch_deadline_not_started'}
                    continue
                folder = output/(stratum+'-'+str(item['pair'])+'-'+arm)
                result = folder/'result.json'
                command = ['php', *PHP_FLAGS, str(packet/'driver.php'), arm, str(source), str(fixture), str(library), str(result)]
                process = None
                try:
                    process = run_worker(command, folder, min(time.monotonic()+WORKER_SECONDS, deadline-CLEANUP_RESERVE_SECONDS), deadline)
                    demand(process['closure']['closed'], 'Process closure unproven; stop batch')
                    if process.get('failure') or process['returncode'] != 0:
                        validation = {'valid': False, 'failure': process.get('failure', 'nonzero_exit')}
                    else:
                        validation = validate_result(result, arm, stratum, fixture, reference, library, packet, source, frozen, frozen_hashes, manifest['files'])
                    pair['arms'][arm] = validation
                    write_json(folder/'validation.json', validation)
                except Exception as exc:
                    pair['arms'][arm] = {'valid': False, 'failure': repr(exc)}
                    if process is None or not process.get('closure', {}).get('closed', False):
                        raise RuntimeError('Cannot continue after unproven process closure') from exc
            pair['valid'] = len(pair['arms']) == 2 and all(v.get('valid') is True for v in pair['arms'].values())
            summary['pairs'].append(pair)
            write_json(output/(stratum+'-'+str(item['pair'])+'-pair.json'), pair)
            demand(not CANCELLED, 'Batch cancelled by signal '+str(CANCELLED))
        demand(digest(packet/'manifest.json') == PACKET_MANIFEST and digest(protocol) == PROTOCOL, 'Reviewed bindings changed during batch')
        # Recheck input identities after all workers; mutations invalidate the complete batch.
        for name, expected in manifest['files'].items():
            demand(digest(packet/name) == expected, 'Packet changed during batch')
        for path, expected in summary['input_hashes'].items():
            demand(digest(path) == expected, 'Fixture/reference changed during batch')
        demand(digest(library) == LIBRARY, 'Library changed during batch')
        for name, expected in frozen_hashes.items():
            demand(digest(frozen/name) == expected, 'Frozen source/vendor input changed during batch')
        summary['status'] = 'complete_diagnostic_screen' if all(p['valid'] for p in summary['pairs']) else 'invalid_pairs_retained'
    except BaseException as exc:
        summary['failures'].append(repr(exc))
        summary['status'] = 'aborted_invalid'
    finally:
        summary['cancelled_signal'] = CANCELLED
        summary['elapsed_seconds'] = time.monotonic()-batch_start
        summary['valid_pairs'] = sum(p['valid'] for p in summary['pairs'])
        summary['invalid_pairs'] = sum(not p['valid'] for p in summary['pairs'])
        summary['not_recorded_pairs'] = 186-len(summary['pairs'])
        write_json(output/'summary.json', summary)
    return 0 if summary['status'] == 'complete_diagnostic_screen' else 2


if __name__ == '__main__':
    raise SystemExit(main())
