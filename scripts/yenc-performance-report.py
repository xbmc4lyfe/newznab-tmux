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
    file_name = function = target_file = target_function = None
    pending_calls = None
    events = None
    positions = 1

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

    for line in Path(path).read_text().splitlines():
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
    profile_records = []
    for index, path in enumerate(sorted((evidence / 'profiles').rglob('*.callgrind'))):
        label = str(path.relative_to(evidence))
        try:
            receipt = json.loads((path.parent / 'status.json').read_text())
            workload = json.loads((path.parent / 'php-workload.json').read_text())
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
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
            profile_records.append({'path': label, 'status': 'ok', 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()})
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
    (output / 'status.json').write_text(json.dumps({'benchmark_status': status, 'profiles': profile_records,
        'observations_sha256': hashlib.sha256(observations.read_bytes()).hexdigest() if observations.exists() else None}, indent=2) + '\n')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--evidence-dir', required=True)
    parser.add_argument('--output-dir', required=True)
    args = parser.parse_args()
    render(args.evidence_dir, args.output_dir)
