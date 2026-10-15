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
    'native-utilities-performance.yml': [(kind, runner) for kind in ('fingerprint-ffi', 'fingerprint-worker', 'cpu-snapshot') for runner in ('ubuntu-latest', 'ubuntu-24.04-arm')],
    'archive-sample-performance.yml': [(kind, runner) for kind in ('sample-planning', 'archive-parsing') for runner in ('ubuntu-latest', 'ubuntu-24.04-arm')],
    'laravel.yml': [], 'rust-clippy.yml': [],
}
RESOURCE_HASHES = {'resource_limits_before', 'resource_limits_after', 'resource_limits_comparison'}
UTILITY_SOURCES = {'baseline', 'candidate', 'composer_lock', 'benchmark', 'rust_cargo_lock', 'rust_cargo_toml',
    'rust_accumulator', 'rust_abi', 'rust_worker', 'php_worker', 'native_library', 'native_worker',
    'coordinator_binary', 'php_binary', 'vendor_installed', 'xml_elements', 'candidate_binary',
    'cargo_lock', 'cargo_toml', 'candidate_source', 'candidate_cli', 'coordinator_source',
    'python_executable', 'crate_lib_source'} | RESOURCE_HASHES
CALLER_SOURCES = {
    'composer.json', 'composer.lock', 'app/Services/Releases/Fingerprint/Par2MetadataParser.php',
    'app/Services/Releases/Fingerprint/NativeMetadataParser.php', 'app/Services/Releases/Fingerprint/NzbFingerprintAnalyzer.php',
    'app/Services/Releases/Verification/SpotCheckPlanner.php', 'app/Services/Releases/Verification/NativeSpotCheckPlanner.php',
    'scripts/benchmark-sample-planning.php', 'scripts/benchmark-archive-parsing.php', 'scripts/benchmark-yenc.php',
    'scripts/build-rapidyenc.sh', 'scripts/benchmark-article-processing.php', 'scripts/benchmark-release-names.php',
    'tests/Fixtures/SamplePlanningBenchmarkFixtures.php', 'tests/Fixtures/ArchiveParsingBenchmarkFixtures.php',
    'tests/Fixtures/par2/rename-index.par2', 'tests/Fixtures/YencArticles.php', 'tests/Support/YencNntpHarness.php',
    'app/Services/YencService.php', 'app/Services/NNTP/NNTPService.php', 'app/Support/AdminReleaseNames.php',
    'app/Support/ReleaseNameDisplay.php', 'docker/app/nzb-yenc-ffi/Cargo.toml', 'docker/app/nzb-yenc-ffi/Cargo.lock',
    'docker/app/nzb-yenc-ffi/src/lib.rs', 'docker/app/nzb-yenc-ffi/src/release_names.rs',
    'docker/app/nzb-yenc-ffi/src/par2.rs', 'docker/app/nzb-yenc-ffi/src/formats.rs',
    'docker/app/nzb-yenc-ffi/src/seven_zip_ranges.rs', 'docker/app/nzb-yenc-ffi/src/par2_source_ranges.rs',
    'docker/app/nzb-yenc-ffi/src/spotcheck_plan.rs', 'docker/app/php/release-presentation-preload.php',
    'vendor/composer/installed.json',
} | RESOURCE_HASHES
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
    if not isinstance(sha, str) or not re.fullmatch('[0-9a-f]{40}', sha):
        return None
    eligible = [run for run in runs if isinstance(run, dict) and run.get('head_sha') == sha and run.get('event') == 'push'
                and run.get('head_branch') == 'master'
                and isinstance(run.get('head_repository'), dict)
                and run['head_repository'].get('full_name') == REPOSITORY]
    return max(eligible, key=lambda run: int(run['id']), default=None)


def validate_summary(summary, run, kind, runner):
    expected = dict(schema_version=1, repository=REPOSITORY, head_sha=run['head_sha'],
                    run_id=run['id'], run_attempt=run['run_attempt'], kind=kind, runner=runner,
                    adoption_qualified=False)
    if not isinstance(summary, dict) or any(type(summary.get(key)) is not type(value) or summary.get(key) != value for key, value in expected.items()):
        raise ValueError('Summary identity does not match current run attempt')
    if not isinstance(summary.get('rows'), list) or len(summary['rows']) > 100:
        raise ValueError('Invalid summary rows')
    allowed = set(expected) | {'rows', 'status', 'evidence_type', 'completeness', 'source_sha256',
                               'library_sha256', 'source_and_library_identities_stable', 'instrumentation_identities_stable', 'baseline_revision', 'runtime_identity', 'protocol_sha256', 'report_sha256', 'rust_payload_mode', 'uncertainty_method'}
    if set(summary) - allowed:
        raise ValueError('Unexpected public summary fields')
    if summary.get('rust_payload_mode', 'unknown') not in ('incumbent', 'experimental', 'unknown'):
        raise ValueError('Invalid Rust payload mode')
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
                'body_ffi_median_ms', 'fixture', 'p99_sample_sufficient', 'baseline_median_ns', 'candidate_median_ns', 'median_ratio', 'paired_median_ratio', 'baseline_p99_ns', 'candidate_p99_ns', 'p99_ratio', 'median_ratio_ci95', 'p99_ratio_ci95', 'median_baseline_over_candidate_ratio', 'median_cold_baseline_over_candidate_ratio',
                'category', 'comparison', 'metric', 'baseline_over_candidate', 'speedup_interval', 'baseline_latency_ms', 'candidate_latency_ms', 'native_median_ms', 'qualification_gate_passed', 'p99_threshold_met'}
    if any(not isinstance(row, dict) or set(row) - row_keys for row in summary['rows']):
        raise ValueError('Unexpected public measurement fields')
    if summary.get('uncertainty_method', 'not_reported') not in ('not_reported', 'mixed_output_bootstrap', 'legacy_stratified_bootstrap_unqualified'):
        raise ValueError('Unknown uncertainty protocol')

    if kind in ('sample-planning', 'archive-parsing'):
        if set(summary.get('source_sha256', {})) - CALLER_SOURCES or set(summary.get('library_sha256', {})) - {'native'}:
            raise ValueError('Unexpected public caller identity names')
        validate_caller_rows(summary, kind)

    if kind in ('fingerprint-ffi', 'fingerprint-worker', 'cpu-snapshot', 'sample-planning', 'archive-parsing'):
        revision = '5cc5303b502e653d83bba1951dec2f5d2da1db35' if kind == 'cpu-snapshot' else 'a1a9b0829610c3b62e7d0bdb418669a89c36c90b'
        if kind in ('fingerprint-ffi', 'fingerprint-worker', 'cpu-snapshot') and set(summary.get('source_sha256', {})) - UTILITY_SOURCES:
            raise ValueError('Unexpected public utility identity names')
        if kind in ('fingerprint-ffi', 'fingerprint-worker', 'cpu-snapshot') and summary.get('rows') and summary.get('baseline_revision') != revision:
            raise ValueError('Invalid frozen baseline revision')
        for field in ('protocol_sha256', 'report_sha256'):
            if field in summary and (not isinstance(summary[field], str) or not re.fullmatch('[0-9a-f]{64}', summary[field])):
                raise ValueError('Invalid utility evidence digest')
        runtime = summary.get('runtime_identity', {})
        runtime_keys = {'executed_sha', 'rustc', 'cargo', 'php', 'python', 'architecture', 'cpu_model', 'runner_image_os', 'runner_image_version', 'build_profile', 'logical_cpus'}
        if not isinstance(runtime, dict) or set(runtime) - runtime_keys:
            raise ValueError('Unexpected utility runtime identity')
        for key, value in runtime.items():
            if key == 'logical_cpus':
                if type(value) is not int or value < 1:
                    raise ValueError('Invalid utility CPU count')
            elif not isinstance(value, str) or len(value) > 4096:
                raise ValueError('Invalid utility runtime value')
        if runtime and runtime.get('executed_sha') != run['head_sha']:
            raise ValueError('Utility runtime source mismatch')
        fixtures = {'common-mounted', 'rare-deep64', 'rare-unavailable', 'rare-host-unavailable', 'host-live'} if kind == 'cpu-snapshot' else {'ids16', 'ids4096', 'ids10000-unique-plus-duplicates', 'ids50000', 'xml10000', 'xml10000-fromXml', 'incremental16', 'ids16-max255', 'incremental25000-max255-spill'}
        if kind in ('fingerprint-ffi', 'fingerprint-worker', 'cpu-snapshot') and any(row.get('fixture') not in fixtures for row in summary['rows']):
            raise ValueError('Unexpected utility fixture')


        if kind in ('fingerprint-ffi', 'fingerprint-worker') and any('phase' in row for row in summary['rows']):
            seen = set()
            for row in summary['rows']:
                key = (row.get('fixture'), row.get('phase'))
                samples = row.get('samples')
                if key[1] not in ('cold', 'repeated') or key in seen or type(samples) is not int or not 0 <= samples <= 5000:
                    raise ValueError('Invalid extended fingerprint fixture')
                seen.add(key)
                if row.get('p99_sample_sufficient') is not (samples >= 1000):
                    raise ValueError('Invalid fingerprint p99 sample count')
                if samples < 1000 and any(row.get(field) is not None for field in ('baseline_p99_ns', 'candidate_p99_ns', 'p99_ratio', 'p99_ratio_ci95', 'p99_threshold_met')):
                    raise ValueError('Insufficient samples cannot claim fingerprint p99')
                if row.get('p99_threshold_met') is True:
                    interval = row.get('p99_ratio_ci95')
                    if not isinstance(interval, list) or len(interval) != 2 or not all(positive(value) for value in interval) or interval[0] < 1.5 or interval[0] > interval[1]:
                        raise ValueError('Invalid fingerprint p99 threshold claim')
            if summary.get('status') == 'measured' and len(seen) != 18:
                raise ValueError('Measured full fingerprint summary is incomplete')
            if summary.get('status') == 'measured':
                require_current_provenance(summary)
        if kind == 'cpu-snapshot':
            if any(row.get('fixture') == 'host-live' for row in summary['rows']) and summary.get('status') in ('measured', 'measured_gate_failed'):
                require_current_provenance(summary)
            for row in summary['rows']:
                if row.get('fixture') == 'host-live' and row.get('comparison') != 'exact_host_cli':
                    raise ValueError('Host evidence must use exact CLI comparison')
                if row.get('gate_passed') is True and (type(row.get('samples')) is not int or row['samples'] < 1000 or row.get('p99_sample_sufficient') is not True):
                    raise ValueError('Insufficient samples cannot qualify CPU p99')


CALLER_FIXTURES = {
    'sample-planning': {'small-dispatch': 'dispatch_control', 'boundary-1024': 'common',
        'large-16384': 'common', 'duplicates-inferred': 'uncommon', 'tight-budget': 'uncommon',
        'unsupported-coercion': 'fallback_control'},
    'archive-parsing': {'public-rename-index': 'common', 'synthetic-128': 'common',
        'synthetic-512': 'uncommon', 'synthetic-1024': 'uncommon',
        **dict.fromkeys(('rar4', 'rar5', 'zip', 'seven-zip', 'iso', 'seven-zip-truncated',
            'seven-zip-corrupt', 'seven-ranges-complete', 'seven-ranges-split', 'seven-ranges-missing',
            'seven-ranges-conflicting', 'par2-ranges-complete', 'par2-ranges-split',
            'par2-ranges-missing', 'par2-ranges-conflicting', 'par2-ranges-checksum'), 'diagnostic')},
}


def require_current_provenance(summary):
    if (not summary.get('runtime_identity') or not summary.get('source_sha256')
            or not RESOURCE_HASHES <= set(summary['source_sha256'])
            or not all(summary.get(field) for field in ('protocol_sha256', 'report_sha256'))):
        raise ValueError('Measured current summary lacks provenance')


def positive(value):
    try:
        return type(value) in (int, float) and math.isfinite(value) and value > 0
    except OverflowError:
        return False


def validate_caller_rows(summary, kind):
    fixtures = CALLER_FIXTURES[kind]
    keys = {'fixture', 'phase', 'candidate_backend', 'samples', 'category', 'p99_sample_sufficient',
            'comparison', 'native_median_ms', 'metric', 'baseline_latency_ms', 'candidate_latency_ms',
            'speedup_interval', 'baseline_over_candidate', 'qualification_gate_passed', 'eligible'}
    seen = set()
    for row in summary['rows']:
        fixture, phase = row.get('fixture'), row.get('phase')
        if not isinstance(fixture, str) or fixture not in fixtures or phase not in ('cold', 'warm') or (fixture, phase) in seen:
            raise ValueError('Unexpected or duplicate caller fixture')
        seen.add((fixture, phase))
        category = fixtures[fixture]
        samples = row.get('samples')
        if (set(row) - keys or row.get('category') != category or type(samples) is not int
                or not 0 <= samples <= 10000 or type(row.get('eligible')) is not bool
                or row.get('p99_sample_sufficient') is not (samples >= 1000)):
            raise ValueError('Invalid caller row contract')
        if row.get('comparison') == 'invalid' and row['eligible'] is False:
            if set(row) - {'fixture', 'phase', 'candidate_backend', 'samples', 'category', 'p99_sample_sufficient', 'comparison', 'eligible'} or row.get('candidate_backend') != 'rust':
                raise ValueError('Invalid caller row contains measurement claims')
            continue
        comparison = ('native_only_diagnostic' if category == 'diagnostic' else 'same_php_control'
                      if category.endswith('control') else 'shared_par2_projection'
                      if kind == 'archive-parsing' else 'exact_public_caller')
        if row.get('comparison') != comparison or row.get('candidate_backend') != ('php' if category.endswith('control') else 'rust'):
            raise ValueError('Unexpected caller comparison')
        if category == 'diagnostic':
            if set(row) & {'baseline_over_candidate', 'speedup_interval', 'baseline_latency_ms',
                           'candidate_latency_ms', 'metric', 'qualification_gate_passed'}:
                raise ValueError('Native diagnostics cannot claim a baseline comparison')
            if row['eligible'] and not positive(row.get('native_median_ms')):
                raise ValueError('Invalid diagnostic latency')
        elif category.endswith('control'):
            if set(row) & {'baseline_over_candidate', 'speedup_interval', 'qualification_gate_passed', 'native_median_ms'} or row.get('metric') != 'not_applicable':
                raise ValueError('PHP controls cannot claim a Rust speedup')
        else:
            metric = 'p99_unavailable' if category == 'uncommon' and samples < 1000 else 'p99_latency' if category == 'uncommon' else 'median_latency'
            if row.get('metric') != metric or 'native_median_ms' in row:
                raise ValueError('Invalid caller latency metric')
            interval = row.get('speedup_interval')
            if interval is not None and (not isinstance(interval, list) or len(interval) != 2
                    or any(value is not None and not positive(value) for value in interval)
                    or all(value is not None for value in interval) and interval[0] > interval[1]):
                raise ValueError('Invalid caller uncertainty interval')
            if metric == 'p99_unavailable':
                if (set(row) & {'baseline_over_candidate', 'baseline_latency_ms', 'candidate_latency_ms'}
                        or interval not in (None, [None, None]) or row.get('qualification_gate_passed') is True):
                    raise ValueError('Insufficient samples cannot claim p99 performance')
            elif row['eligible']:
                if not all(positive(row.get(key)) for key in ('baseline_over_candidate', 'baseline_latency_ms', 'candidate_latency_ms')):
                    raise ValueError('Invalid comparative caller latency')
                if not math.isclose(row['baseline_over_candidate'], row['baseline_latency_ms'] / row['candidate_latency_ms'], rel_tol=1e-12):
                    raise ValueError('Caller speedup disagrees with latency')
            if 'qualification_gate_passed' in row and type(row['qualification_gate_passed']) is not bool:
                raise ValueError('Invalid caller gate')
            if row.get('qualification_gate_passed') is True and (samples < 1000 or not row['eligible'] or not interval or interval[0] is None or interval[0] < 1.5):
                raise ValueError('Unqualified caller gate')
        for key in ('native_median_ms', 'baseline_latency_ms', 'candidate_latency_ms', 'baseline_over_candidate'):
            if key in row and not positive(row[key]):
                raise ValueError('Invalid caller numeric value')
    if summary.get('status') == 'measured':
        expected = {(name, phase) for name in fixtures for phase in ('cold', 'warm')}
        if seen != expected or not all(row['eligible'] for row in summary['rows']):
            raise ValueError('Measured caller summary is incomplete or invalid')
        require_current_provenance(summary)
        if not summary.get('library_sha256', {}).get('native'):
            raise ValueError('Measured caller summary lacks native library identity')


def measured_payload_mode(summary):
    mode = summary.get('rust_payload_mode')
    if summary.get('status') not in ('ok', 'useful_improvement', 'inconclusive', 'regression'):
        return 'unknown'
    return mode if mode in ('incumbent', 'experimental') else 'unknown'


def coverage(root):
    payload_sources = {'payload_decoder', 'payload_decoder_x86', 'payload_decoder_arm'}
    diagnostic = {'nntp_body', 'nntp_headers'}
    rows = []
    for path in sorted((root / 'docker/app/nzb-yenc-ffi/src').rglob('*.rs')):
        name = path.stem
        label, detail = 'No automated benchmark', 'Correctness tests or utility execution do not measure speed.'
        modes, comparisons = [], []
        if name in payload_sources:
            modes = ['incumbent']
            comparisons = ['php'] if path.with_stem(name + '_experimental').is_file() else ['php', 'cpp']
            label = 'Comparative caller benchmark capability'
            detail = 'Default incumbent kernels: ' + '/'.join(comparisons) + ' baseline comparison wiring. Capability is separate from measured mode evidence in each result manifest.'
        elif name.removesuffix('_experimental') in payload_sources and name.endswith('_experimental'):
            modes, comparisons = ['experimental'], ['cpp']
            label = 'Comparative caller benchmark capability'
            detail = 'Opt-in experimental kernels: C++ baseline comparison wiring. This coverage does not establish default-kernel performance or adoption.'
        elif name == 'yenc_framing':
            label, detail = 'Comparative caller benchmark capability', 'Framing is shared by yEnc comparison callers; the result manifest identifies the measured payload mode.'
            comparisons = ['php', 'cpp']
        elif name == 'release_names':
            label, detail = 'Comparative caller benchmark', 'Release rendering, x86; 5% median screen for batches of 16+ rows.'
        elif name in diagnostic:
            label, detail = 'Diagnostic caller profile', 'Fixed article-analysis inputs; no isolated baseline comparison or complete archive-format coverage.'
        elif name == 'par2':
            label, detail = 'Shared-semantics caller comparison', 'Four actual PHP fallback/native caller strata; backend extras differ and remain retained. No overall adoption qualification.'
        elif name in ('formats', 'seven_zip_ranges', 'par2_source_ranges'):
            label, detail = 'Native caller diagnostics', 'Correctness-gated bounded format/range fixtures with FFI and JSON costs; no equivalent PHP baseline or exhaustive archive coverage.'
        elif name == 'spotcheck_plan':
            label, detail = 'Comparative public caller benchmark', 'Exact serialized PHP/native output, cold/warm calls, common median and uncommon p99 qualification mode; small/unsupported inputs remain PHP controls.'
        elif name in ('article_identity', 'article_xref', 'article_dates'):
            detail = 'Correctness/parity checks exist; no independent comparative timing protocol or module-specific caller benchmark.'
        elif name == 'nntmux-evidence-utility':
            label, detail = 'Auxiliary correctness only', 'CI evidence and rendering utility; running it does not measure its own performance.'
        elif name == 'lib':
            label, detail = 'Mixed coverage', 'FFI exports: decoding/rendering comparisons; metadata profiles; some APIs unbenchmarked.'
        elif name in ('article_fingerprint', 'nntmux-article-fingerprint', 'benchmark-article-fingerprint'):
            label, detail = 'Comparative public operation benchmark', 'Nine PHP/FFI/worker caller strata, cold/repeated lifecycle costs; tail estimates require 1000 pairs via dispatch. PHP process startup excluded; Rust worker startup included.'
        elif name in ('cpu_snapshot', 'nntmux-cpu-snapshot', 'benchmark-cpu-snapshot'):
            label, detail = 'Comparative CLI benchmark', 'Python baseline vs Rust: synthetic proc/cgroup fixtures and 1000 exact Linux host CLI pairs with changing-counter witnesses; startup included, production load unverified.'
        rows.append({'target': str(path.relative_to(root)), 'coverage': label, 'detail': detail, 'payload_modes': modes, 'comparisons': comparisons})
    return rows


def complete_cpp_comparison(summary):
    expected = {(size, density, path) for size in (4096, 524288, 1048576, 8388608)
                for density in ('ordinary', 'heavy') for path in ('tolerant', 'strict', 'body')}
    completeness = summary.get('completeness')
    if summary.get('status') not in ('useful_improvement', 'inconclusive', 'regression') or not isinstance(completeness, dict) or completeness.get('complete') is not True:
        return False
    rows = summary['rows']
    if len(rows) != len(expected):
        return False
    keys = set()
    for row in rows:
        key = (row.get('size'), row.get('density'), row.get('path'))
        if key not in expected or key in keys or type(row.get('eligible_pairs')) is not int or row['eligible_pairs'] < 3 or row.get('invalid_pairs') != 0:
            return False
        if row.get('decision') not in ('useful_improvement', 'inconclusive', 'regression'):
            return False
        ratio, interval = row.get('median_rust_over_cpp'), row.get('bootstrap_95_percent_interval')
        if type(ratio) not in (int, float) or ratio <= 0 or not math.isfinite(ratio):
            return False
        if not isinstance(interval, list) or len(interval) != 2 or any(type(value) not in (int, float) or value <= 0 or not math.isfinite(value) for value in interval) or interval[0] > interval[1]:
            return False
        keys.add(key)
    return keys == expected


def last_valid_comparison(fetch=api, archive_download=download):
    """Find a complete historical C++ matrix without replacing current-build state.

    Search is bounded to the newest 20 trusted master push runs. In-progress,
    failed, cancelled or partial attempts are never replaced by an older attempt
    of that same run. Both architecture summaries must belong to its latest attempt.
    """
    result = dict(status='No complete comparison in newest 20 trusted master push runs', results=[], unavailable_runs=0, expired_artifacts=0, searched_runs=0)
    try:
        runs = fetch('actions/workflows/yenc-performance.yml/runs?branch=master&event=push&per_page=20')['workflow_runs']
        trusted = [run for run in runs if isinstance(run, dict) and select_run([run], run.get('head_sha')) is not None]
        for run in sorted(trusted, key=lambda row: int(row['id']), reverse=True)[:20]:
            if run.get('status') != 'completed':
                continue
            result['searched_runs'] += 1
            try:
                artifacts = fetch(f"actions/runs/{run['id']}/artifacts?per_page=100")['artifacts']
                summaries = []
                for runner in ('ubuntu-latest', 'ubuntu-24.04-arm'):
                    name = f"public-benchmark-cpp-{runner}-{run['id']}-{run['run_attempt']}"
                    matches = [a for a in artifacts if a.get('name') == name]
                    if len(matches) == 1 and matches[0].get('expired'):
                        result['expired_artifacts'] += 1
                    if len(matches) != 1 or matches[0]['expired'] or matches[0]['size_in_bytes'] > 2 * LIMIT:
                        break
                    artifact = matches[0]
                    summary = read_summary(archive_download(artifact['id']))
                    validate_summary(summary, run, 'cpp', runner)
                    if not complete_cpp_comparison(summary):
                        break
                    summaries.append(dict(summary, artifact_id=artifact['id'], workflow_status=run.get('conclusion')))
                if len(summaries) == 2:
                    return dict(result, status='Available historical comparison', results=summaries,
                                head_sha=run['head_sha'], run_id=run['id'], run_attempt=run['run_attempt'])
            except (OSError, ValueError, KeyError, TypeError, OverflowError, RuntimeError, NotImplementedError, zipfile.BadZipFile):
                result['unavailable_runs'] += 1
                continue
    except (OSError, ValueError, KeyError, TypeError, OverflowError):
        result['status'] = 'Historical comparison collection unavailable'
    if result['unavailable_runs']:
        result['status'] = 'Historical comparison collection incomplete; some attempts unavailable'
    return result


def collect(sha, root, fetch=api, archive_download=download):
    report = dict(schema_version=1, repository=REPOSITORY, head_sha=sha,
                  generated_at=datetime.now(timezone.utc).isoformat(), workflows=[], results=[], coverage=coverage(root),
                  frozen_reports_available=(root / 'github-pages/benchmarks/index.html').is_file())
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
            result = dict(kind=kind, runner=runner, status=state, rows=[], adoption_qualified=False, rust_payload_mode='unknown')
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
                        result['rust_payload_mode'] = measured_payload_mode(summary)
                        result['artifact_id'] = artifact['id']
                        result['workflow_status'] = info['status']
                    except (OSError, ValueError, KeyError, TypeError, RuntimeError, NotImplementedError, zipfile.BadZipFile):
                        result['status'] = 'Summary invalid or unavailable'
            report['results'].append(result)
    report['last_valid_comparison'] = last_valid_comparison(fetch, archive_download)
    return report


def escape(value):
    return html.escape(str(value), quote=True)


def run_link(row):
    if 'run_id' not in row:
        return '—'
    number, attempt = int(row['run_id']), int(row['run_attempt'])
    return f'<a href="https://github.com/{REPOSITORY}/actions/runs/{number}/attempts/{attempt}">Run {number}, attempt {attempt}</a>'


def measurement_table(rows):
    table = ['<table><tr><th>Workload</th><th>Latency / ratio / uncertainty</th><th>Decision / eligibility</th></tr>']
    for row in rows:
        label = ' · '.join(str(row[key]) for key in ('size', 'density', 'path', 'phase', 'scenario', 'rows', 'fixture') if key in row)
        metrics = {key: value for key, value in row.items() if key.startswith('median_') or key in ('php_median_ns', 'rust_median_ns', 'improvement_percent', 'body_ffi_median_ms', 'baseline_median_ns', 'candidate_median_ns', 'paired_median_ratio', 'baseline_p99_ns', 'candidate_p99_ns', 'p99_ratio', 'p99_ratio_ci95', 'baseline_over_candidate', 'speedup_interval', 'baseline_latency_ms', 'candidate_latency_ms', 'native_median_ms')}
        status = {key: row[key] for key in ('decision', 'eligible', 'eligible_pairs', 'invalid_pairs', 'gate_passed', 'performance_gate', 'samples', 'p99_sample_sufficient', 'qualification_gate_passed', 'p99_threshold_met', 'comparison', 'category', 'metric') if key in row}
        table.append(f'<tr><td>{escape(label)}</td><td>{escape(json.dumps(metrics))}</td><td>{escape(json.dumps(status))}</td></tr>')
    table.append('</table>')
    return '\n'.join(table)


def render(report):
    body = ['<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">',
            '<title>NNTmux Rust benchmarks</title><style>body{font:16px system-ui;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#182332}table{border-collapse:collapse;width:100%;margin:1rem 0}th,td{text-align:left;padding:.55rem;border-bottom:1px solid #ccd5dd}code{overflow-wrap:anywhere}pre{white-space:pre-wrap;overflow-wrap:anywhere}a{color:#175eaa}details{margin:1rem 0}</style>',
            '<h1>NNTmux Rust benchmarks</h1>',
            f'<p>Master build <code>{escape(report["head_sha"])}</code>. Updated {escape(report["generated_at"])}. <a href="results.json">Machine-readable results</a>.</p>',
            '<p>Coverage is incomplete. Comparative median screens and diagnostic profiles are separate evidence. These builds do not establish the required 1.5× gain for every common path and representative uncommon p99 workload, or production throughput.</p>',
            '<p>Each master push runs fresh yEnc comparisons, BODY/fingerprint profiles, NZB digest/CPU comparisons, archive diagnostics and sample-planning comparisons on x86/ARM, plus release rendering on x86. Missing, failed, cancelled and inconclusive results remain visible. Local/manual experiments are excluded. Raw evidence is retained in Actions for 14 days; published summaries last until the next build.</p>',
            '<h2>Build checks</h2><table><tr><th>Workflow</th><th>Latest state</th><th>Evidence</th></tr>']
    for row in report['workflows']:
        body.append(f'<tr><td>{escape(row["workflow"])}</td><td>{escape(row.get("status", "unknown"))}</td><td>{run_link(row)}</td></tr>')
    body.append('</table><h2>Current measurements</h2>')
    if report.get('frozen_reports_available'):
        body.append('<p><a href="benchmarks/">Historical speedup charts, PHP call paths and native flamegraphs</a>. These frozen reports retain their own executed-commit and run provenance; they are separate from this build.</p>')
    for row in report['results']:
        body.append(f'<details><summary>{escape(row["kind"])} · {escape(row["runner"])} · {escape(row.get("status", "unknown"))}</summary><p>{run_link(row)}. Evidence: {escape(row.get("evidence_type", "unavailable"))}; adoption qualification: not established.</p>')
        if row.get('kind') == 'cpu-snapshot':
            if row.get('uncertainty_method') == 'mixed_output_bootstrap':
                body.append('<p>CPU intervals use corrected mixed-output resampling. Synthetic and exact host CLI evidence remain separate; neither establishes production throughput or adoption. <a href="performance.html#utility-results">Historical protocol limits</a>.</p>')
            else:
                body.append('<p>CPU interval limitation: the legacy harness uses dependent bootstrap samples, or this result has no verified resampling identity. Its interval fields do not establish qualified uncertainty. Point estimates remain recorded measurements. <a href="performance.html#utility-results">Read the protocol limits</a>.</p>')
        if row.get('kind') in ('cpp', 'php', 'profile'):
            body.append('<p>Rust payload mode: ' + escape(measured_payload_mode(row)) + '.</p>')
        if 'workflow_status' in row:
            body.append(f'<p>Overall workflow: {escape(row["workflow_status"])}</p>')
        body.append(measurement_table(row['rows']) if row['rows'] else '<p>No current measurement rows available.</p>')
        body.append('<details><summary>Summary data and identities</summary><pre>' + escape(json.dumps(row, indent=2, ensure_ascii=False, allow_nan=False)) + '</pre></details></details>')
    historical = report.get('last_valid_comparison', {})
    if historical:
        body.append('<h2>Last valid complete C++ comparison</h2><p>' + escape(historical.get('status')) + '. Historical evidence remains separate from the current build; a newer PHP-only/profile-only run cannot replace this comparison.</p>')
        if historical.get('unavailable_runs') or historical.get('expired_artifacts'):
            body.append('<p>Unavailable attempts: ' + escape(historical.get('unavailable_runs', 0)) + '; expired summary artifacts: ' + escape(historical.get('expired_artifacts', 0)) + '. Newer unavailable attempts may contain comparisons that cannot be verified.</p>')
        if historical.get('results'):
            body.append('<p>Executed source <code>' + escape(historical['head_sha']) + '</code>. ' + run_link(historical) + '.</p>')
            for row in historical['results']:
                body.append('<h3>' + escape(row['runner']) + '</h3><p>Rust payload mode: ' + escape(measured_payload_mode(row)) + '.</p>' + measurement_table(row['rows']))
    body.append('<h2>Rust target coverage and priorities</h2><p>Next priorities: inspect cold fingerprint costs and worker startup, sample-planner projection/JSON costs, and repeated native body/format scans. Archive diagnostics have no equivalent PHP baseline; qualification runs must bound uncommon tails before proposing replacements. Newly added Rust sources appear as gaps until their benchmark mapping is reviewed.</p><table><tr><th>Source target</th><th>Automated evidence</th><th>Scope / gap</th></tr>')
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
