#!/usr/bin/env python3
"""Render retained paired observations and Xdebug executed-call profiles without dependencies.

Speedup = C++ time / Rust time; time saved = 100 * (1 - Rust / C++).
Instrumented profiles explain call costs only; they never supply benchmark timings.
Callgrind support is deliberately limited to Xdebug's single-part Time_(10ns) format.
"""

import argparse
from collections import defaultdict
import hashlib
import html
import json
import math
from pathlib import Path
from os.path import relpath
import re
from urllib.parse import quote


def file_sha256(path):
    digest = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for chunk in iter(lambda: stream.read(1048576), b''):
            digest.update(chunk)
    return digest.hexdigest()


def timeout_fired(path):
    if path is None:
        return False
    try:
        with Path(path).open() as stream:
            return any(line.startswith('timeout: sending signal ') for line in stream)
    except OSError:
        return False


def exit_cause(code, timed_out=False):
    if not isinstance(code, int):
        return 'not_run'
    if code == 0:
        return 'complete'
    if code == 124 or timed_out:
        return 'timeout'
    if code in (125, 126, 127):
        return 'invocation_failure'
    # Linux signals occupy 1..64; perf may return -1 as shell status 255.
    if 128 < code <= 192:
        return 'signal'
    return 'nonzero_exit'


def profile_status(directory, native_exit, php_exit, postprocess_exit, postprocess_stage, preflight_exit, preflight_stderr=None):
    directory = Path(directory)

    def workload(name):
        try:
            result = json.loads((directory / name).read_text())
            return result if (isinstance(result, dict) and result.get('status') == 'ok'
                and isinstance(result.get('iterations'), int) and 1 <= result['iterations'] <= 10000
                and result.get('warmups') == 1 and result.get('verified_calls') == result['iterations'] + 1) else None
        except (ValueError, OSError):
            return None

    php, native = workload('php-workload.json'), workload('native-workload.json')
    callgrind, folded = directory / 'php.callgrind', directory / 'native.folded'
    samples = 0
    try:
        samples = sum(int(line.rsplit(' ', 1)[1]) for line in folded.read_text().splitlines())
    except (ValueError, OSError):
        pass
    matched = bool(php and native and all(key in php and key in native and php[key] == native[key] for key in
        ('input_sha256', 'output_sha256', 'wire_sha256', 'verified_calls', 'size', 'iterations'))
        and php.get('headers_sha256') == native.get('headers_sha256'))
    native_timed_out = timeout_fired(directory / 'perf.stderr')
    php_timed_out = timeout_fired(directory / 'php.stderr')
    preflight_timed_out = timeout_fired(preflight_stderr)
    native_state = 'invalid'
    if native_exit == 'unavailable' and exit_cause(preflight_exit, preflight_timed_out) == 'nonzero_exit':
        native_state = 'unavailable'
    elif (native_exit == 0 and postprocess_exit == 0 and postprocess_stage == 'complete'
            and matched and samples > 0 and (directory / 'native.svg').is_file()):
        native_state = 'captured'
    return {
        'php_profile': 'captured' if php_exit == 0 and php and callgrind.is_file() and callgrind.stat().st_size else 'invalid',
        'native_profile': native_state, 'native_samples': samples, 'workload_identity_matches': matched,
        'native_exit': native_exit, 'native_exit_cause': exit_cause(native_exit, native_timed_out), 'php_exit': php_exit,
        'native_timed_out': native_timed_out, 'php_timed_out': php_timed_out,
        'php_exit_cause': exit_cause(php_exit, php_timed_out),
        'native_postprocess_exit': postprocess_exit, 'native_postprocess_stage': postprocess_stage,
        'perf_preflight_exit': preflight_exit, 'perf_preflight_cause': exit_cause(preflight_exit, preflight_timed_out),
        'perf_preflight_timed_out': preflight_timed_out,
        'php_profile_sha256': file_sha256(callgrind) if callgrind.is_file() else None,
        'boundary': 'Whole-process profiles include setup and exact-output verification; diagnostic builds differ from timed builds.',
        'claim': 'Call-cost attribution only. No latency or production-capacity conclusion from instrumented runs.',
    }


def comparison(row):
    ratio = row.get('median_rust_over_cpp')
    interval = row.get('bootstrap_95_percent_interval')
    if (row.get('decision') not in ('useful_improvement', 'regression', 'inconclusive')
            or row.get('invalid_pairs') != 0
            or not isinstance(ratio, (int, float)) or not math.isfinite(ratio) or ratio <= 0
            or not isinstance(interval, list) or len(interval) != 2
            or any(not isinstance(value, (int, float)) or not math.isfinite(value) or value <= 0 for value in interval)
            or not interval[0] <= ratio <= interval[1]):
        return None
    return {'speedup': 1 / ratio, 'time_saved_percent': 100 * (1 - ratio),
            'speedup_interval': [1 / interval[1], 1 / interval[0]]}


def read_callgrind(path):
    """Sum exclusive costs and keep inclusive edges separate, including recursion."""
    maps = {'file': {}, 'function': {}}
    functions = defaultdict(int)
    edges = defaultdict(lambda: {'calls': 0, 'inclusive_cost': 0})
    def name(value, kind):
        match = re.fullmatch(r'\((\d+)\)(?: (.*))?', value)
        if not match:
            return value
        identity, label = match.groups()
        if label is not None:
            maps[kind][identity] = label
        if identity not in maps[kind]:
            raise ValueError('Unknown compressed Callgrind name: ' + value)
        return maps[kind][identity]

    with Path(path).open() as stream:
        return parse_callgrind_lines(stream, functions, edges, name)


def parse_callgrind_lines(lines, functions, edges, name):
    file_name = function = target_file = target_function = None
    pending_calls, events, positions = None, None, 1
    for line in lines:
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        if line.startswith('events:'):
            events = line.split(':', 1)[1].split()
            if not events or events[0] != 'Time_(10ns)':
                raise ValueError('Expected Xdebug Time_(10ns) event')
        elif line.startswith('positions:'):
            positions = len(line.split(':', 1)[1].split())
        elif line.startswith(('fl=', 'cfl=', 'cfi=')):
            key, value = line.split('=', 1)
            value = name(value, 'file')
            if key == 'fl':
                file_name = value
                target_file = value
            else:
                target_file = value
        elif line.startswith(('fn=', 'cfn=')):
            key, value = line.split('=', 1)
            value = name(value, 'function')
            if key == 'fn':
                if pending_calls is not None:
                    raise ValueError('Call association missing cost')
                function = value
                target_file = file_name
                target_function = None
            else:
                target_function = value
        elif line.startswith('calls='):
            pending_calls = int(line.split('=', 1)[1].split()[0])
        elif re.match(r'^[0-9+*\-]', line):
            if events is None or file_name is None or function is None:
                raise ValueError('Cost without events, file or function')
            fields = line.split()
            if len(fields) <= positions:
                raise ValueError('Missing time cost')
            cost = int(fields[positions])
            if cost < 0:
                raise ValueError('Negative time cost')
            source = (file_name, function)
            if pending_calls is None:
                functions[source] += cost
            else:
                if target_function is None or pending_calls < 0:
                    raise ValueError('Invalid call association')
                edge = edges[(source, (target_file or file_name, target_function))]
                edge['calls'] += pending_calls
                edge['inclusive_cost'] += cost
                pending_calls = None
    if events is None or pending_calls is not None or not functions:
        raise ValueError('Incomplete Xdebug profile')
    return {'functions': dict(functions), 'edges': dict(edges),
            'total_self_cost': sum(functions.values()), 'event': events[0]}


def bar_svg(title, rows, unit, reference=None):
    width, left, row_height = 1180, 540, 34
    height = 90 + max(1, len(rows)) * row_height
    maximum = max([value for _, value in rows] + [reference or 0, 0.001])
    scale = (width - left - 110) / maximum
    content = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" role="img">',
               f'<title>{html.escape(title)}</title>',
               '<rect width="100%" height="100%" fill="#101827"/>',
               '<g fill="#e5e7eb" font-family="sans-serif" font-size="14">',
               f'<text x="20" y="30">{html.escape(title)}</text>']
    if reference is not None:
        x = left + reference * scale
        content.append(f'<path d="M{x:.2f},48 V{height - 18}" stroke="#94a3b8" stroke-dasharray="4"/>')
    for index, (label, value) in enumerate(rows):
        y = 60 + index * row_height
        color = '#38bdf8' if reference is None or value >= reference else '#fb923c'
        content += [f'<text x="20" y="{y + 16}">{html.escape(label[:75])}</text>',
                    f'<rect x="{left}" y="{y}" width="{value * scale:.2f}" height="22" fill="{color}"/>',
                    f'<text x="{left + value * scale + 8:.2f}" y="{y + 16}">{value:.3f} {html.escape(unit)}</text>']
    return '\n'.join(content + ['</g></svg>'])


def render(evidence, output):
    evidence, output = Path(evidence), Path(output)
    output.mkdir(parents=True, exist_ok=True)
    for previous in output.glob('hotpaths-*.svg'):
        previous.unlink()
    observations = evidence / 'paired' / 'observations.jsonl'
    records = [json.loads(line) for line in observations.read_text().splitlines()] if observations.exists() else []
    summaries = [row for row in records if row.get('type') == 'stratum_summary']
    final = next((row for row in reversed(records) if row.get('type') in ('run_summary', 'run_failure')), {})
    status = final.get('status', 'setup incomplete; no performance conclusion')
    sections = [f'<h1>Native yEnc performance evidence</h1><p>Run result: <strong>{html.escape(status)}</strong>.</p>',
                '<p>C++ is the pinned RapidYenc baseline; Rust is the built candidate. Identical synthetic article fixtures execute real YencService and memory-backed NNTP BODY calls. This paired comparison does not measure network, database, archive metadata or production throughput.</p>',
                '<p>Speedup = C++ time / Rust time. 1× means equal time. Time saved = 100 × (1 − Rust time / C++ time). Decisions and simultaneous confidence intervals come from the uninstrumented paired benchmark; inconclusive estimates do not establish a gain.</p>',
                '<table><tr><th>Stratum</th><th>Decision</th><th>Speedup</th><th>95% speedup interval</th><th>Time saved</th><th>Eligible / invalid</th></tr>']
    markdown = ['## Native decoder speedup estimates', '', f'Run result: `{status}`.', '',
                'Fixture comparison only. Inconclusive estimates do not establish a gain. Profiles do not supply timings.', '',
                '| Stratum | Decision | C++ / Rust | 95% interval | Time saved | Eligible / invalid |',
                '|---|---|---:|---|---:|---|']
    chart = []
    for row in summaries:
        label = f"{row['size']} B / {row['density']} / {row['path']}"
        metrics = comparison(row)
        values = ['unavailable'] * 3 if metrics is None else [f"{metrics['speedup']:.3f}×",
                   f"{metrics['speedup_interval'][0]:.3f}–{metrics['speedup_interval'][1]:.3f}×",
                   f"{metrics['time_saved_percent']:.2f}%"]
        cells = [label, row['decision'], *values, f"{row['eligible_pairs']} / {row['invalid_pairs']}"]
        sections.append('<tr>' + ''.join('<td>' + html.escape(str(cell)) + '</td>' for cell in cells) + '</tr>')
        markdown.append('| ' + ' | '.join(str(cell).replace('|', '\\|') for cell in cells) + ' |')
        if metrics:
            chart.append((label + ' [' + row['decision'] + ']', metrics['speedup']))
    sections.append('</table>')
    (output / 'speedup.svg').write_text(bar_svg('Uninstrumented C++ / Rust median paired speedup (decision shown)', chart, '×', 1))
    sections.append('<img src="speedup.svg" alt="Paired speedup estimates by fixture and call path">')
    sections.append('<h2>Executed PHP hot paths</h2><p>The separate Rust-only metadata workload executes NzbFingerprintAnalyzer and native RAR5/NNTP metadata parsing over a fixed verified archive-header fixture. It has no C++ counterpart and supplies no migration speedup estimate.</p><p>Xdebug profiles include process bootstrap, fixture setup, warmup, decoding and correctness checks. Exclusive function time is shown below; inclusive call-edge cost is listed separately and must not be added across edges. FFI calls are opaque here; native internals appear in the separate CPU sampling flamegraphs. Instrumentation changes cost and these numbers are not speedup measurements.</p>')
    setup_path = evidence / 'profiles' / 'status.json'
    profiling_setup = json.loads(setup_path.read_text()) if setup_path.is_file() else {'status': 'unavailable', 'phase': 'no setup receipt'}
    sections.append('<p>Profiling setup/run receipt: ' + html.escape(json.dumps(profiling_setup, sort_keys=True)) + '</p>')
    profile_records = []
    profile_paths = set((evidence / 'profiles').rglob('*.callgrind'))
    profile_paths.update(receipt.parent / 'php.callgrind' for receipt in (evidence / 'profiles').glob('*/status.json'))
    for index, path in enumerate(sorted(profile_paths)):
        label = str(path.relative_to(evidence))
        try:
            receipt = json.loads((path.parent / 'status.json').read_text())
            workload = json.loads((path.parent / 'php-workload.json').read_text())
            digest = file_sha256(path)
            if (receipt.get('php_profile') != 'captured' or receipt.get('php_profile_sha256') != digest
                    or workload.get('status') != 'ok' or workload.get('warmups') != 1
                    or not isinstance(workload.get('iterations'), int) or workload['iterations'] < 1
                    or workload.get('verified_calls') != workload['iterations'] + 1):
                raise ValueError('Missing, failed or mismatched workload/profile verification receipt')
            profile = read_callgrind(path)
            total = profile['total_self_cost']
            rows = sorted(profile['functions'].items(), key=lambda item: item[1], reverse=True)[:20]
            image = f'hotpaths-{index}.svg'
            (output / image).write_text(bar_svg(label + ' — exclusive function cost',
                                              [(key[1], cost / 100000) for key, cost in rows], 'ms'))
            sections += [f'<details open><summary>{html.escape(label)}</summary>',
                         f'<p>Total exclusive profiled cost: {total / 100000:.3f} ms.</p><img src="{image}" alt="Top executed PHP functions">',
                         '<table><tr><th>Caller → callee</th><th>Calls</th><th>Inclusive cost (ms)</th></tr>']
            for (source, target), edge in sorted(profile['edges'].items(), key=lambda item: item[1]['inclusive_cost'], reverse=True)[:30]:
                sections.append(f"<tr><td>{html.escape(source[1])} → {html.escape(target[1])}</td><td>{edge['calls']}</td><td>{edge['inclusive_cost'] / 100000:.3f}</td></tr>")
            sections.append('</table></details>')
            profile_records.append({'path': label, 'status': 'ok', 'sha256': digest})
        except (ValueError, OSError) as error:
            sections.append(f'<p>Invalid profile {html.escape(label)}: {html.escape(str(error))}</p>')
            profile_records.append({'path': label, 'status': 'invalid', 'error': str(error)})
    if not profile_records:
        sections.append('<p>No executed PHP profile available. Check retained profiling logs; no hot-path conclusion.</p>')
    sections.append('<h2>Native CPU sampling</h2><p>Searchable, zoomable sampled native stacks use separate optimized builds with debug information. Width represents CPU samples, not wall time. Whole-process samples include setup and correctness verification. Unavailable or invalid captures cannot identify native hot paths.</p>')
    sections.append('<table><tr><th>Workload</th><th>Capture status</th><th>CPU samples</th><th>Flamegraph</th></tr>')
    for receipt_path in sorted((evidence / 'profiles').glob('*/status.json')):
        receipt = json.loads(receipt_path.read_text())
        graph = receipt_path.parent / 'native.svg'
        state = receipt.get('native_profile', 'unavailable')
        link = 'unavailable'
        if state == 'captured' and receipt.get('workload_identity_matches') and graph.is_file():
            link = '<a href="' + html.escape(quote(relpath(graph, output))) + '">Open flamegraph</a>'
        sections.append('<tr><td>' + html.escape(receipt_path.parent.name) + '</td><td>'
                        + html.escape(str(state)) + '</td><td>' + html.escape(str(receipt.get('native_samples', 0)))
                        + '</td><td>' + link + '</td></tr>')
    sections.append('</table>')
    document = '<!doctype html><html lang="en"><meta charset="utf-8"><title>yEnc performance evidence</title><style>body{font:16px system-ui;background:#101827;color:#e5e7eb;margin:32px;max-width:1400px}table{border-collapse:collapse}td,th{padding:8px;text-align:left;border-bottom:1px solid #334155}img{width:100%;margin:20px 0}summary{cursor:pointer}</style><body>' + '\n'.join(sections) + '</body></html>'
    (output / 'report.html').write_text(document)
    (output / 'summary.md').write_text('\n'.join(markdown) + '\n')
    (output / 'status.json').write_text(json.dumps({'benchmark_status': status, 'profiling_setup': profiling_setup, 'profiles': profile_records,
        'observations_sha256': file_sha256(observations) if observations.exists() else None}, indent=2) + '\n')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--evidence-dir')
    parser.add_argument('--output-dir')
    parser.add_argument('--record-profile-status')
    parser.add_argument('--native-exit')
    parser.add_argument('--php-exit', type=int)
    parser.add_argument('--postprocess-exit', type=int)
    parser.add_argument('--postprocess-stage')
    parser.add_argument('--preflight-exit', type=int)
    parser.add_argument('--preflight-stderr')
    args = parser.parse_args()
    if args.record_profile_status:
        if any(value is None for value in (args.native_exit, args.php_exit, args.postprocess_exit,
                                          args.postprocess_stage, args.preflight_exit)):
            parser.error('Profile status requires all process exit receipts')
        native_exit = args.native_exit if args.native_exit == 'unavailable' else int(args.native_exit)
        status = profile_status(args.record_profile_status, native_exit, args.php_exit, args.postprocess_exit,
                                args.postprocess_stage, args.preflight_exit, args.preflight_stderr)
        (Path(args.record_profile_status) / 'status.json').write_text(json.dumps(status, indent=2) + '\n')
        raise SystemExit(1 if status['php_profile'] == 'invalid' or status['native_profile'] == 'invalid' else 0)
    if not args.evidence_dir or not args.output_dir:
        parser.error('Rendering requires --evidence-dir and --output-dir')
    render(args.evidence_dir, args.output_dir)
