#!/usr/bin/env python3
"""Publish bounded benchmark summaries for the current master build, never PR artifacts.

CI reporting only; standard library, no application boot or artifact extraction.
"""
import argparse
from datetime import datetime, timezone
import html
import io
import json
import math
import os
from pathlib import Path
import re
import urllib.error
import urllib.request
import zipfile

REPOSITORY = 'xbmc4lyfe/newznab-tmux'
WORKFLOWS = {
    'yenc-performance.yml': [('cpp', 'ubuntu-latest'), ('cpp', 'ubuntu-24.04-arm'),
                             ('php', 'ubuntu-latest'), ('php', 'ubuntu-24.04-arm'),
                             ('profile', 'ubuntu-latest'), ('profile', 'ubuntu-24.04-arm')],
    'release-presentation.yml': [('presentation', 'ubuntu-latest')],
    'laravel.yml': [], 'rust-clippy.yml': [],
}
LIMIT = 1024 * 1024


def strict_json(raw):
    def invalid(value):
        raise ValueError('Nonfinite JSON number: ' + value)
    def finite(value):
        number = float(value)
        if not math.isfinite(number):
            raise ValueError('Nonfinite JSON number')
        return number
    return json.loads(raw, parse_constant=invalid, parse_float=finite)


def api(path):
    request = urllib.request.Request('https://api.github.com/repos/' + REPOSITORY + '/' + path,
                                     headers={'Authorization': 'Bearer ' + os.environ['GH_TOKEN'],
                                              'Accept': 'application/vnd.github+json',
                                              'X-GitHub-Api-Version': '2022-11-28'})
    with urllib.request.urlopen(request, timeout=30) as response:
        raw = response.read(8 * LIMIT + 1)
    if len(raw) > 8 * LIMIT:
        raise ValueError('API response exceeds limit')
    return strict_json(raw)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def download(artifact_id):
    # Strip authentication at the signed archive redirect boundary.
    request = urllib.request.Request(
        f'https://api.github.com/repos/{REPOSITORY}/actions/artifacts/{int(artifact_id)}/zip',
        headers={'Authorization': 'Bearer ' + os.environ['GH_TOKEN']})
    try:
        urllib.request.build_opener(NoRedirect).open(request, timeout=30)
    except urllib.error.HTTPError as error:
        if error.code != 302:
            raise
        location = error.headers['Location']
    else:
        raise ValueError('Expected a signed archive redirect')
    if not location.startswith('https://'):
        raise ValueError('Archive redirect must use HTTPS')
    with urllib.request.urlopen(location, timeout=30) as response:
        data = response.read(2 * LIMIT + 1)
    if len(data) > 2 * LIMIT:
        raise ValueError('Archive exceeds limit')
    return data


def read_summary(data):
    if len(data) > 2 * LIMIT:
        raise ValueError('Archive exceeds limit')
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        members = archive.infolist()
        if len(members) != 1 or members[0].filename != 'summary.json' or members[0].file_size > LIMIT:
            raise ValueError('Expected one bounded summary.json member')
        return strict_json(archive.read(members[0]))


def select_run(runs, sha):
    eligible = [run for run in runs if run.get('head_sha') == sha and run.get('event') == 'push'
                and run.get('head_branch') == 'master'
                and run.get('head_repository', {}).get('full_name') == REPOSITORY]
    return max(eligible, key=lambda run: int(run['id']), default=None)


def validate_summary(summary, run, kind, runner):
    expected = dict(schema_version=1, repository=REPOSITORY, head_sha=run['head_sha'],
                    run_id=run['id'], run_attempt=run['run_attempt'], kind=kind, runner=runner,
                    adoption_qualified=False)
    if not isinstance(summary, dict) or any(summary.get(key) != value for key, value in expected.items()):
        raise ValueError('Summary identity does not match current run attempt')
    if not isinstance(summary.get('rows'), list) or len(summary['rows']) > 100:
        raise ValueError('Invalid summary rows')
    allowed = set(expected) | {'rows', 'status', 'evidence_type', 'completeness', 'source_sha256',
                               'library_sha256', 'source_and_library_identities_stable', 'instrumentation_identities_stable'}
    if set(summary) - allowed:
        raise ValueError('Unexpected public summary fields')
    for mapping in ('source_sha256', 'library_sha256'):
        hashes = summary.get(mapping, {})
        if not isinstance(hashes, dict) or any(not isinstance(value, str) or not re.fullmatch('[0-9a-f]{64}', value) for value in hashes.values()):
            raise ValueError('Invalid public identity hashes')
        if any(not isinstance(key, str) or not re.fullmatch('[A-Za-z0-9_./-]{1,160}', key) or '..' in key or '.env' in key for key in hashes):
            raise ValueError('Invalid public identity names')
    row_keys = {'size', 'density', 'path', 'phase', 'scenario', 'rows', 'decision', 'eligible', 'status',
                'eligible_pairs', 'invalid_pairs', 'bootstrap_replicates', 'confidence_family_size',
                'median_rust_over_cpp', 'median_rust_over_php', 'bootstrap_95_percent_interval',
                'median_ms', 'min_ms', 'max_ms', 'candidate_backend', 'correctness_passed', 'performance_gate',
                'gate_passed', 'samples', 'iterations', 'php_median_ns', 'rust_median_ns', 'improvement_percent',
                'body_ffi_median_ms'}
    if any(not isinstance(row, dict) or set(row) - row_keys for row in summary['rows']):
        raise ValueError('Unexpected public measurement fields')


def coverage(root):
    comparative = {'payload_decoder', 'payload_decoder_x86', 'payload_decoder_arm', 'yenc_framing'}
    diagnostic = {'nntp_body', 'nntp_headers', 'article_identity', 'article_xref', 'article_dates', 'formats', 'par2'}
    rows = []
    for path in sorted((root / 'docker/app/nzb-yenc-ffi/src').rglob('*.rs')):
        name = path.stem
        label, detail = 'No automated benchmark', 'Correctness tests or utility execution do not measure speed.'
        if name in comparative:
            label, detail = 'Comparative caller benchmark', 'yEnc PHP/Rust and C++/Rust matrix; framing included in caller costs.'
        elif name == 'release_names':
            label, detail = 'Comparative caller benchmark', 'Release rendering, x86; 5% median screen for batches of 16+ rows.'
        elif name in diagnostic:
            label, detail = 'Diagnostic caller profile', 'Costs included in article analysis profiles; no isolated baseline comparison.'
        elif name == 'lib':
            label, detail = 'Mixed coverage', 'FFI exports: decoding/rendering comparisons; metadata profiles; some APIs unbenchmarked.'
        elif name.startswith('benchmark-'):
            detail = 'Local comparison harness exists; not invoked automatically in CI.'
        elif name == 'article_fingerprint' or name == 'nntmux-article-fingerprint':
            detail = 'NZB article-ID digest harness exists; separate from release metadata fingerprint profiles.'
        elif name in ('cpu_snapshot', 'nntmux-cpu-snapshot'):
            detail = 'Used for CPU evidence collection; local comparison harness is not recurring CI.'
        rows.append({'target': str(path.relative_to(root)), 'coverage': label, 'detail': detail})
    return rows


def collect(sha, root, fetch=api, archive_download=download):
    report = dict(schema_version=1, repository=REPOSITORY, head_sha=sha,
                  generated_at=datetime.now(timezone.utc).isoformat(), workflows=[], results=[], coverage=coverage(root))
    for workflow, expected in WORKFLOWS.items():
        try:
            runs = fetch(f'actions/workflows/{workflow}/runs?branch=master&event=push&head_sha={sha}&per_page=100')['workflow_runs']
            run = select_run(runs, sha)
            state = (run.get('conclusion') or run['status']) if run else 'Not run for this commit'
        except (OSError, ValueError, KeyError, TypeError):
            run, state = None, 'Collection unavailable'
        info = {'workflow': workflow, 'status': state}
        if run:
            info.update(run_id=run['id'], run_attempt=run['run_attempt'])
        report['workflows'].append(info)
        artifacts = []
        if run and expected:
            try:
                artifacts = fetch(f"actions/runs/{run['id']}/artifacts?per_page=100")['artifacts']
            except (OSError, ValueError, KeyError, TypeError):
                state = 'Collection unavailable'
        for kind, runner in expected:
            result = dict(kind=kind, runner=runner, status=state, rows=[], adoption_qualified=False)
            if run:
                result.update(run_id=run['id'], run_attempt=run['run_attempt'])
                name = f"public-benchmark-{kind}-{runner}-{run['id']}-{run['run_attempt']}"
                matched = [a for a in artifacts if a.get('name') == name]
                if state != 'Collection unavailable':
                    result['status'] = 'Summary unavailable' if run['status'] == 'completed' else 'Pending'
                if len(matched) == 1:
                    try:
                        artifact = matched[0]
                        if artifact['expired'] or artifact['size_in_bytes'] > 2 * LIMIT:
                            raise ValueError('Expired or oversized summary')
                        summary = read_summary(archive_download(artifact['id']))
                        validate_summary(summary, run, kind, runner)
                        result.update(summary)
                        result['artifact_id'] = artifact['id']
                        result['workflow_status'] = info['status']
                    except (OSError, ValueError, KeyError, TypeError, RuntimeError, NotImplementedError, zipfile.BadZipFile):
                        result['status'] = 'Summary invalid or unavailable'
            report['results'].append(result)
    return report


def escape(value):
    return html.escape(str(value), quote=True)


def run_link(row):
    if 'run_id' not in row:
        return '—'
    number, attempt = int(row['run_id']), int(row['run_attempt'])
    return f'<a href="https://github.com/{REPOSITORY}/actions/runs/{number}/attempts/{attempt}">Run {number}, attempt {attempt}</a>'


def measurement_table(rows):
    table = ['<table><tr><th>Workload</th><th>Median latency / ratio</th><th>Decision / eligibility</th></tr>']
    for row in rows:
        label = ' · '.join(str(row[key]) for key in ('size', 'density', 'path', 'phase', 'scenario', 'rows') if key in row)
        metrics = {key: value for key, value in row.items() if key.startswith('median_') or key in ('php_median_ns', 'rust_median_ns', 'improvement_percent', 'body_ffi_median_ms')}
        status = {key: row[key] for key in ('decision', 'eligible', 'eligible_pairs', 'invalid_pairs', 'gate_passed', 'performance_gate') if key in row}
        table.append(f'<tr><td>{escape(label)}</td><td>{escape(json.dumps(metrics))}</td><td>{escape(json.dumps(status))}</td></tr>')
    table.append('</table>')
    return '\n'.join(table)


def render(report):
    body = ['<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">',
            '<title>NNTmux Rust benchmarks</title><style>body{font:16px system-ui;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#182332}table{border-collapse:collapse;width:100%;margin:1rem 0}th,td{text-align:left;padding:.55rem;border-bottom:1px solid #ccd5dd}code{overflow-wrap:anywhere}pre{white-space:pre-wrap;overflow-wrap:anywhere}a{color:#175eaa}details{margin:1rem 0}</style>',
            '<h1>NNTmux Rust benchmarks</h1>',
            f'<p>Master build <code>{escape(report["head_sha"])}</code>. Updated {escape(report["generated_at"])}. <a href="results.json">Machine-readable results</a>.</p>',
            '<p>Coverage is incomplete. Comparative median screens and diagnostic profiles are separate evidence. These builds do not establish the required 1.5× gain for every common path and representative uncommon p99 workload, or production throughput.</p>',
            '<p>Each master push runs fresh yEnc comparisons on x86/ARM, BODY/fingerprint profiles on x86/ARM and release rendering on x86. Missing, failed, cancelled and inconclusive results remain visible. Local/manual experiments are excluded. Raw evidence is retained in Actions for 14 days; published summaries last until the next build.</p>',
            '<h2>Build checks</h2><table><tr><th>Workflow</th><th>Latest state</th><th>Evidence</th></tr>']
    for row in report['workflows']:
        body.append(f'<tr><td>{escape(row["workflow"])}</td><td>{escape(row["status"])}</td><td>{run_link(row)}</td></tr>')
    body.append('</table><h2>Current measurements</h2>')
    for row in report['results']:
        body.append(f'<details><summary>{escape(row["kind"])} · {escape(row["runner"])} · {escape(row["status"])}</summary><p>{run_link(row)}. Evidence: {escape(row.get("evidence_type", "unavailable"))}; adoption qualification: not established.</p>')
        if 'workflow_status' in row:
            body.append(f'<p>Overall workflow: {escape(row["workflow_status"])}</p>')
        body.append(measurement_table(row['rows']) if row['rows'] else '<p>No current measurement rows available.</p>')
        body.append('<details><summary>Summary data and identities</summary><pre>' + escape(json.dumps(row, indent=2, ensure_ascii=False, allow_nan=False)) + '</pre></details></details>')
    body.append('<h2>Rust target coverage and priorities</h2><p>Next priorities: compare repeated native body/format scans through the full caller; automate existing NZB digest and CPU collector harnesses; then cover archive range parsers and sample planning. Newly added Rust source files appear automatically as gaps until their benchmark mapping is reviewed.</p><table><tr><th>Source target</th><th>Automated evidence</th><th>Scope / gap</th></tr>')
    for row in report['coverage']:
        body.append(f'<tr><td><code>{escape(row["target"])}</code></td><td>{escape(row["coverage"])}</td><td>{escape(row["detail"])}</td></tr>')
    body.append('</table></html>')
    return '\n'.join(body)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sha', required=True)
    parser.add_argument('--output', required=True, type=Path)
    options = parser.parse_args()
    if not re.fullmatch('[0-9a-f]{40}', options.sha):
        parser.error('Expected full commit SHA')
    report = collect(options.sha, Path.cwd())
    options.output.mkdir(parents=True, exist_ok=True)
    (options.output / 'results.json').write_text(json.dumps(report, indent=2, allow_nan=False) + '\n')
    (options.output / 'index.html').write_text(render(report))


if __name__ == '__main__':
    main()
