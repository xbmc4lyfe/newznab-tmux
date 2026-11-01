#!/usr/bin/env python3
"""Publish how far the Rust rewrite has got, for the front page of the docs site.

Usage:
    python3 progress.py --master DIR [--rust-native DIR] --output rewrite-progress.json

The PHP is deleted on rust-native before it's replaced and stays on master until the
cutover, so counting PHP lines on either branch says nothing about porting. The
headline is the share of the baseline's live PHP whose entry points are done: each
subsystem's baseline lines, multiplied by the share of its ledger entries that are
ported or dropped.

An entry only counts if it retires PHP, so ports of existing Rust (a .rs source) don't.
Ledgers come from rust-native, or from master once rust-native is gone after the cutover.
"""

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import tomllib

HERE = Path(__file__).resolve().parent


def measure(tree, config):
    """Run rewrite_metrics.py on a checkout; returns its report."""
    with tempfile.TemporaryDirectory() as temp:
        out = Path(temp) / 'metrics.json'
        run = subprocess.run(
            [sys.executable, '-B', str(HERE / 'rewrite_metrics.py'), str(tree), '--json', str(out), '--subsystems', str(config)],
            capture_output=True, text=True)
        if not out.is_file():
            raise RuntimeError(f'rewrite_metrics.py failed on {tree}: {run.stderr}')
        return json.loads(out.read_text())


def source_path(source):
    """Ledger sources are file paths or Laravel handlers; App\\Foo\\Bar@baz becomes app/Foo/Bar.php."""
    if source.startswith('App\\'):
        return 'app/' + source[4:].partition('@')[0].replace('\\', '/') + '.php'
    return source


def owner(workstream, source, subsystems):
    """Which subsystem an entry retires: its workstream's, split by source path if several share it."""
    candidates = [row for row in subsystems if workstream in row['workstreams']]
    if len(candidates) == 1:
        return candidates[0]['key']
    path = source_path(source)
    for row in candidates or subsystems:
        if any(re.search(pattern, path) for pattern in row['paths']):
            return row['key']
    return (candidates or subsystems[-1:])[0]['key']


def read_ledgers(tree, excluded, subsystems):
    """Counts per subsystem as {key: [done, total]}, skipping excluded files and ports of Rust."""
    counts = {}
    for path in sorted((tree / 'rust/porting/ledger').glob('*.toml')):
        if path.stem in excluded:
            continue
        for entry in tomllib.loads(path.read_text(encoding='utf-8')).get('entry', []):
            source = entry.get('source', '')
            if source.endswith('.rs'):
                continue
            count = counts.setdefault(owner(path.stem, source, subsystems), [0, 0])
            count[1] += 1
            count[0] += entry.get('status') in ('ported', 'dropped')
    return counts


def rust_lines(tree):
    """Non-test Rust lines per crate: src/ only, as the PHP side counts app/ and not tests/."""
    lines = {}
    for crate in sorted(p for p in (tree / 'rust/crates').glob('*') if p.is_dir()):
        lines[crate.name] = sum(
            len(path.read_bytes().splitlines()) for path in (crate / 'src').rglob('*.rs') if path.is_file())
    return lines


def compute(config, master, rust_native, ledgers, crates):
    """Build the published report from the map, per-branch metrics, ledger counts and crate lines."""
    subsystems = config['subsystem']
    follows_rest = set(config['ledger']['follows_rest'])
    catch_all = subsystems[-1]
    known_crates = {name for subsystem in subsystems for name in subsystem['crates']}
    live = {branch: {row['key']: row['live'] for row in report['subsystems']} for branch, report in
            (('master', master), ('rust_native', rust_native)) if report}

    rows, retired, weighed = [], 0.0, 0
    for subsystem in subsystems:
        key = subsystem['key']
        done, total = ledgers.get(key, (0, 0))
        share = done / total if total else 0.0
        names = list(subsystem['crates'])
        if subsystem is catch_all:
            names += sorted(set(crates) - known_crates)
        rows.append(dict(key=key, title=subsystem['title'], share=share, baseline_live=subsystem['baseline_live'],
                         master_live=live.get('master', {}).get(key, 0),
                         rust_lines=sum(crates.get(name, 0) for name in names)))
        if key not in follows_rest:
            retired += subsystem['baseline_live'] * share
            weighed += subsystem['baseline_live']
    overall = retired / weighed if weighed else 0.0
    for row in rows:
        if row['key'] in follows_rest:
            row['share'] = overall
        row['ported_pct'] = round(100 * row.pop('share'), 1)

    base = config['baseline']
    master_php = master['php']['live']
    return dict(
        headline_pct=round(100 * overall, 1),
        baseline=dict(commit=base['commit'], live_lines=base['live_lines']),
        entries=dict(done=sum(count[0] for count in ledgers.values()), total=sum(count[1] for count in ledgers.values())),
        master=dict(commit=master['commit'], live_php=master_php,
                    removed_pct=round(100 * (1 - master_php / base['live_lines']), 1)),
        rust_native=dict(commit=rust_native['commit'], live_php=rust_native['php']['live'], rust_lines=sum(crates.values())) if rust_native else None,
        subsystems=rows,
    )


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--master', required=True, type=Path)
    parser.add_argument('--rust-native', type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--subsystems', type=Path, default=HERE / 'subsystems.toml')
    args = parser.parse_args()
    config = tomllib.loads(args.subsystems.read_text(encoding='utf-8'))
    rust_native = args.rust_native if args.rust_native and (args.rust_native / 'rust/porting/ledger').is_dir() else None
    source = rust_native or args.master
    if not (source / 'rust/porting/ledger').is_dir():
        parser.exit(1, 'Neither checkout has rust/porting/ledger\n')
    report = compute(config, measure(args.master, args.subsystems), measure(rust_native, args.subsystems) if rust_native else None,
                     read_ledgers(source, set(config['ledger']['excluded']), config['subsystem']), rust_lines(source))
    args.output.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(f"Rewrite progress: {report['headline_pct']}%")


if __name__ == '__main__':
    main()
