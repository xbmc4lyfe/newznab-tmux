#!/usr/bin/env python3
"""Publish bounded benchmark summaries for the current main build, never PR artifacts.

CI reporting only; standard library, no application boot or artifact extraction.
"""
import argparse
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation, ROUND_CEILING, ROUND_FLOOR
import html
import io
import importlib.util
import itertools
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
    'archive-sample-performance.yml': [(kind, runner) for kind in ('sample-planning', 'archive-parsing', 'vendor-archive', 'metadata-modules', 'archive-modules') for runner in ('ubuntu-latest', 'ubuntu-24.04-arm')],
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
    'docker/app/nzb-yenc-ffi/src/sample_plan.rs', 'docker/app/php/release-presentation-preload.php',
    'vendor/composer/installed.json',
} | RESOURCE_HASHES
VENDOR_INPUTS = {
    'rar4-stored': ('rar/store_method.rar', 'c32c1bb9c65159df7a8cedd939e7e42d3fd424e334898e586c1558093bc89d37'),
    'rar5-mixed': ('rar/rar50_quickopen.rar', '645f6a8adfae57ff04b3e2ff0fbf018101e623646b4396d3cd605437d3b5f882'),
    'zip-stored': ('zip/little_file.zip', 'ababf71680cceb96c44ee04d69ab5dd99436c3b05c1d60242ffb8994e171ff09'),
    'seven-stored': ('szip/store_method.7z', 'ec369e350984c9af3f5211f6cc20008abf29f7f331f8aa656604be05fafede5a'),
    'rar4-solid': ('rar/solid.rar', '05d39c43a4ba897ea358543aa5b700208eba307ee6e6379d8d7cbd978aa374e4'),
    'rar5-encrypted-headers': ('rar/rar50_encrypted_headers.rar', 'f5db452c3d7dc351b1ff9971edfb0611893838affa369e31e209570e270cc2af'),
    'zip-aes': ('zip/encrypted_file_aes.zip', '7555457edd346b22c718e43d3409e1b9ae1dca1b118a26b1cdb960e58781238d'),
    'seven-encrypted-headers': ('szip/encrypted_headers.7z', '4b7fdbb7b875847278e65bed4ce23fc29a149a7a7e93334304dbf34fb8d52f0d'),
}
VENDOR_INPUTS = {name: ('vendor/dariusiii/rarinfo/tests/fixtures/' + path, digest) for name, (path, digest) in VENDOR_INPUTS.items()}
VENDOR_SOURCE_PATHS = {'composer.lock', 'vendor/composer/installed.json', 'vendor/dariusiii/rarinfo/composer.json',
    'vendor/dariusiii/rarinfo/ArchiveReader.php', 'vendor/dariusiii/rarinfo/RarInfo.php',
    'vendor/dariusiii/rarinfo/ZipInfo.php', 'vendor/dariusiii/rarinfo/SzipInfo.php',
    'scripts/benchmark-vendor-archive.php', 'tests/Fixtures/VendorArchiveBenchmarkFixtures.php'} | {path for path, _ in VENDOR_INPUTS.values()}

REMAINING_MODULES = {
    "metadata-modules": {
        "article_dates": ("dates-ordinary", "dates-folded", "dates-uncommon-duplicates", "dates-uncommon-malformed"),
        "article_identity": ("identity-ordinary", "identity-overview", "identity-uncommon-conflicts", "identity-uncommon-boundary"),
        "article_xref": ("xref-ordinary", "xref-overview", "xref-uncommon-duplicates", "xref-uncommon-limits")},
    "archive-modules": {
        "iso_primary_copies": ("iso-copies-equal", "iso-copies-different", "iso-copies-uncommon-limit", "iso-copies-uncommon-partial"),
        "rar5_copy_checksums": ("rar-copy-crc", "rar-copy-blake", "rar-copy-uncommon-ambiguous", "rar-copy-uncommon-truncated"),
        "rar5_quick_open": ("rar-quick-open-match", "rar-quick-open-conflict", "rar-quick-open-uncommon-retention", "rar-quick-open-uncommon-corrupt")},
}
REMAINING_STRATA = {kind: {name: dict(module=module, category="common" if index < 2 else "uncommon",
    comparison_scope="bounded-reference-shared-projection-v1" if kind == "metadata-modules" else
    "bounded independent PHP reference versus shared metadata projection; no full-parser equivalence or production PHP speedup")
    for module, names in modules.items() for index, name in enumerate(names)} for kind, modules in REMAINING_MODULES.items()}
REMAINING_INSTRUMENTATION = {"scripts/benchmark-remaining-modules.php", "scripts/benchmark-sample-planning.php",
    "scripts/benchmark-article-processing.php", "scripts/benchmark-yenc.php", "tests/Fixtures/SamplePlanningBenchmarkFixtures.php",
    "tests/Fixtures/RemainingMetadataBenchmarkFixtures.php", "tests/Fixtures/RemainingArchiveBenchmarkFixtures.php"}
REMAINING_WRAPPER = "app/Services/Releases/Fingerprint/NativeMetadataParser.php"
REMAINING_SOURCES = {REMAINING_WRAPPER, "composer.json", "composer.lock", "docker/app/nzb-yenc-ffi/Cargo.toml",
    "docker/app/nzb-yenc-ffi/Cargo.lock", "docker/app/nzb-yenc-ffi/src/lib.rs"} | {
    "docker/app/nzb-yenc-ffi/src/" + module + ".rs" for modules in REMAINING_MODULES.values() for module in modules}

CALLER_SOURCES |= VENDOR_SOURCE_PATHS | REMAINING_SOURCES | REMAINING_INSTRUMENTATION
CALLER_SOURCES |= {'app/Services/Yenc/' + name + '.php' for name in (
    'ArticleFrame', 'DecoderFactory', 'NativePayloadDecoder', 'PayloadDecoder',
    'PhpPayloadDecoder', 'RawPayloadDecoder', 'YencPart')}
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
                and run.get('head_branch') == 'main'
                and isinstance(run.get('head_repository'), dict)
                and run['head_repository'].get('full_name') == REPOSITORY]
    return max(eligible, key=lambda run: int(run['id']), default=None)


def validate_native_rows(summary, kind, run, historical=False):
    """A current success requires a complete, internally consistent public matrix."""
    status, rows = summary.get('status'), summary['rows']
    success = {'cpp': {'useful_improvement', 'inconclusive', 'regression'},
               'php': {'useful_improvement', 'inconclusive', 'regression'},
               'profile': {'ok'}, 'presentation': {'passed', 'gate_failed'}}[kind]
    measured = status in success or status == 'experimental_measurement'
    if status not in success | {None, 'missing', 'partial', 'invalid', 'incomplete_provenance',
                               'unsupported_hardware', 'experimental_measurement'}:
        raise ValueError('Unknown native summary status')
    provenance = summary.get('provenance_status')
    if (any(not isinstance(key, str) or key not in CALLER_SOURCES and not re.fullmatch(r'docker/app/nzb-yenc-ffi/src/(?:bin/)?[a-z0-9_-]+\.rs', key)
            for key in summary.get('source_sha256', {}))
            or set(summary.get('library_sha256', {})) - {'native', 'rust', 'cpp'}):
        raise ValueError('Unexpected native source or library identity')
    if provenance not in (None, 'verified_current', 'verified_experiment', 'incomplete', 'invalid'):
        raise ValueError('Invalid native provenance status')
    runtime = summary.get('runtime_identity', {})
    runtime_keys = {'executed_sha', 'rustc', 'cargo', 'php', 'python', 'architecture', 'cpu_model',
                    'runner_image_os', 'runner_image_version', 'build_profile', 'logical_cpus'}
    if (not isinstance(runtime, dict) or set(runtime) - runtime_keys
            or any(type(v) is not int or v < 1 if k == 'logical_cpus' else not isinstance(v, str) or len(v) > 4096
                   for k, v in runtime.items())
            or runtime and runtime.get('executed_sha') != run['head_sha']):
        raise ValueError('Native runtime source mismatch')
    for field in ('runtime_manifest_sha256', 'report_sha256', 'protocol_sha256', 'kernel_qualification_sha256'):
        if field in summary and (not isinstance(summary[field], str) or not re.fullmatch('[0-9a-f]{64}', summary[field])):
            raise ValueError('Invalid native evidence digest')
    names = {0: 'scalar', 1: 'ssse3', 2: 'avx2', 3: 'avx512-vbmi2', 4: 'neon'}
    for field in ('rust_payload_kernel', 'required_payload_kernel'):
        kernel = summary.get(field)
        if field in summary and (not isinstance(kernel, dict) or set(kernel) != {'id', 'name'}
                or type(kernel['id']) is not int or names.get(kernel['id']) != kernel['name']):
            raise ValueError('Invalid measured kernel identity')
    hardware = summary.get('hardware_qualification', 'unavailable')
    if hardware not in ('designated_kernel', 'unsupported_hardware', 'observed_only', 'unavailable'):
        raise ValueError('Invalid hardware qualification')
    candidate = summary.get('candidate_head_sha')
    if candidate is not None and (not isinstance(candidate, str) or not re.fullmatch('[0-9a-f]{40}', candidate)
                                  or candidate == run['head_sha']):
        raise ValueError('Invalid experiment source identity')
    if status == 'experimental_measurement' and (provenance != 'verified_experiment' or candidate is None):
        raise ValueError('Experiment cannot masquerade as current-source measurement')
    if status == 'unsupported_hardware':
        required = 4 if summary['runner'] == 'ubuntu-24.04-arm' else 3
        if (kind != 'cpp' or rows or hardware != 'unsupported_hardware' or provenance != 'verified_current'
                or summary.get('required_payload_kernel', {}).get('id') != required
                or summary.get('rust_payload_kernel', {}).get('id') in (None, required)
                or 'kernel_qualification_sha256' not in summary or not runtime
                or 'runtime_manifest_sha256' not in summary or not summary.get('source_sha256')
                or set(summary.get('library_sha256', {})) != {'cpp', 'rust'}):
            raise ValueError('Unsupported hardware lacks its frozen receipt')
    if measured and not historical:
        implementation_sources = {p.relative_to(Path(__file__).resolve().parents[1]).as_posix()
                                  for p in (Path(__file__).resolve().parents[1] / 'docker/app/nzb-yenc-ffi/src').rglob('*.rs')}
        if (provenance != ('verified_experiment' if status == 'experimental_measurement' else 'verified_current')
                or not runtime or not summary.get('source_sha256') or not summary.get('library_sha256')
                or provenance == 'verified_current' and not implementation_sources <= set(summary['source_sha256'])
                or any(field not in summary for field in ('runtime_manifest_sha256', 'report_sha256', 'protocol_sha256'))):
            raise ValueError('Current native metrics lack executed-source provenance')
        if kind == 'cpp':
            required = {'id': 4, 'name': 'neon'} if summary['runner'] == 'ubuntu-24.04-arm' else {'id': 3, 'name': 'avx512-vbmi2'}
            if (hardware != 'designated_kernel' or summary.get('required_payload_kernel') != required
                    or summary.get('rust_payload_kernel') != required or 'kernel_qualification_sha256' not in summary):
                raise ValueError('Designated kernel comparison lacks matching hardware proof')
    if kind in ('cpp', 'php'):
        expected = set(itertools.product((4096, 524288, 1048576, 8388608), ('ordinary', 'heavy'), ('tolerant', 'strict', 'body')))
        keys = [(r.get('size'), r.get('density'), r.get('path')) for r in rows]
        if measured:
            for row in rows:
                ratio, interval = row.get('median_rust_over_' + kind), row.get('bootstrap_95_percent_interval')
                if (type(row.get('eligible_pairs')) is not int or row['eligible_pairs'] < 3
                        or type(row.get('invalid_pairs')) is not int or row['invalid_pairs'] != 0
                        or row.get('decision') not in success or not positive(ratio)
                        or not isinstance(interval, list) or len(interval) != 2
                        or not all(positive(v) for v in interval) or interval[0] > interval[1]):
                    raise ValueError('Invalid decoder measurement')
            decisions = {r['decision'] for r in rows}
            aggregate = ('regression' if 'regression' in decisions else 'useful_improvement'
                         if decisions == {'useful_improvement'} else 'inconclusive')
            if status != 'experimental_measurement' and status != aggregate:
                raise ValueError('Decoder aggregate contradicts its strata')
    elif kind == 'profile':
        expected = set(itertools.product((4096, 524288, 1048576), ('ordinary', 'heavy'),
            ('full-analyzer', 'framing', 'headers', 'body-metadata', 'relationship', 'raw-body', 'whole-body')))
        keys = [(r.get('size'), r.get('density'), r.get('phase')) for r in rows]
        if measured and any(r.get('eligible') is not True or r.get('status') != 'ok'
                or type(r.get('samples')) is not int or r['samples'] < 1
                or any(not positive(r.get(k)) for k in ('median_ms', 'max_ms'))
                or type(r.get('min_ms')) not in (int, float) or not math.isfinite(r['min_ms'])
                or not 0 <= r['min_ms'] <= r['median_ms'] <= r['max_ms'] for r in rows):
            raise ValueError('Invalid native diagnostic measurement')
    else:
        expected = set(itertools.product(('clean', 'wrapped', 'escaped'), (1, 2, 5, 10, 16, 50, 100)))
        keys = [(r.get('scenario'), r.get('rows')) for r in rows]
        if measured:
            for row in rows:
                if type(row.get('rows')) is not int:
                    raise ValueError('Invalid rendering batch')
                if row['rows'] < 16:
                    if (row.get('candidate_backend') != 'php-small-batch' or row.get('correctness_passed') is not True
                            or row.get('performance_gate') != 'not_applicable_same_php_path'
                            or any(k in row for k in ('gate_passed', 'improvement_percent', 'rust_median_ns'))):
                        raise ValueError('PHP rendering control cannot claim Rust gains')
                else:
                    baseline, native = row.get('php_median_ns'), row.get('rust_median_ns')
                    if (row.get('candidate_backend') != 'rust' or type(row.get('samples')) is not int or row['samples'] < 11
                            or type(row.get('iterations')) is not int or row['iterations'] < 1
                            or not positive(baseline) or not positive(native)
                            or type(row.get('improvement_percent')) not in (int, float)
                            or not math.isclose(row['improvement_percent'], 100 * (baseline - native) / baseline, rel_tol=1e-9, abs_tol=1e-9)
                            or row.get('gate_passed') is not (row['improvement_percent'] >= 5)):
                        raise ValueError('Invalid rendering measurement')
            aggregate = 'passed' if all(r.get('gate_passed', True) for r in rows) else 'gate_failed'
            if status != aggregate:
                raise ValueError('Rendering aggregate contradicts its strata')
    try:
        unique = set(keys)
        if len(unique) != len(keys) or not unique <= expected:
            raise ValueError('Unexpected or duplicate native measurement stratum')
    except TypeError as error:
        raise ValueError('Invalid native measurement key') from error
    completeness = summary.get('completeness')
    if completeness is not None and (not isinstance(completeness, dict)
            or set(completeness) != {'expected_rows', 'observed_rows', 'complete'}
            or type(completeness['expected_rows']) is not int or completeness['expected_rows'] != len(expected)
            or type(completeness['observed_rows']) is not int or completeness['observed_rows'] != len(rows)
            or type(completeness['complete']) is not bool
            or completeness['complete'] is True and unique != expected):
        raise ValueError('Native completeness receipt contradicts its rows')
    if measured and (unique != expected or not completeness or completeness.get('complete') is not True):
        raise ValueError('Current native measurement matrix is incomplete')


def validate_summary(summary, run, kind, runner, historical=False):
    expected = dict(schema_version=1, repository=REPOSITORY, head_sha=run['head_sha'],
                    run_id=run['id'], run_attempt=run['run_attempt'], kind=kind, runner=runner,
                    adoption_qualified=False)
    if not isinstance(summary, dict) or any(type(summary.get(key)) is not type(value) or summary.get(key) != value for key, value in expected.items()):
        raise ValueError('Summary identity does not match current run attempt')
    if not isinstance(summary.get('rows'), list) or len(summary['rows']) > 100:
        raise ValueError('Invalid summary rows')
    allowed = set(expected) | {'rows', 'status', 'evidence_type', 'completeness', 'source_sha256',
                               'library_sha256', 'source_and_library_identities_stable', 'instrumentation_identities_stable', 'baseline_revision', 'runtime_identity', 'protocol_sha256', 'report_sha256', 'rust_payload_mode', 'candidate_status', 'uncertainty_method', 'measurement_protocol_version', 'confidence_status', 'confidence_family_size', 'host_confidence_status', 'host_confidence_family_size', 'baseline_kind', 'benchmark_mode', 'provenance_status', 'runtime_manifest_sha256', 'kernel_qualification_sha256', 'rust_payload_kernel', 'required_payload_kernel', 'hardware_qualification', 'candidate_head_sha'}
    if set(summary) - allowed:
        raise ValueError('Unexpected public summary fields')
    if kind not in ('cpp', 'php', 'profile', 'presentation') and set(summary) & {
            'provenance_status', 'runtime_manifest_sha256', 'kernel_qualification_sha256',
            'rust_payload_kernel', 'required_payload_kernel', 'hardware_qualification', 'candidate_head_sha'}:
        raise ValueError('Native provenance fields on a different comparison')
    if kind not in REMAINING_STRATA and (set(summary) & {'baseline_kind', 'benchmark_mode'}
            or any(set(row) & {'module', 'correctness_observations', 'native_proof_observations'} for row in summary['rows'])):
        raise ValueError('Remaining module fields on a different comparison')
    if summary.get('rust_payload_mode', 'unknown') not in ('incumbent', 'experimental', 'unknown'):
        raise ValueError('Invalid Rust payload mode')
    if 'candidate_status' in summary and (kind not in ('fingerprint-ffi', 'fingerprint-worker')
            or summary['candidate_status'] != 'experimental_withdrawn_from_production'):
        raise ValueError('Invalid fingerprint candidate status')
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
                'module', 'correctness_observations', 'native_proof_observations', 'category', 'comparison', 'metric', 'baseline_over_candidate', 'speedup_interval', 'baseline_latency_ms', 'candidate_latency_ms', 'native_median_ms', 'qualification_gate_passed', 'p99_threshold_met',
                'baseline_latency_interval_ms', 'candidate_latency_interval_ms', 'marginal_alpha', 'gate_statistic', 'gate_speedup_simultaneous_ci95', 'host_median_speedup_simultaneous_ci95', 'live_median_speedup_simultaneous_ci95', 'live_p99_speedup_simultaneous_ci95'}
    if any(not isinstance(row, dict) or set(row) - row_keys for row in summary['rows']):
        raise ValueError('Unexpected public measurement fields')
    if kind in ('cpp', 'php', 'profile', 'presentation'):
        validate_native_rows(summary, kind, run, historical)
    if summary.get('uncertainty_method', 'not_reported') not in ('not_reported', 'mixed_output_bootstrap', 'legacy_stratified_bootstrap_unqualified', 'paired_bootstrap_bonferroni'):
        raise ValueError('Unknown uncertainty protocol')

    cpu_fields = {'measurement_protocol_version', 'confidence_status', 'confidence_family_size', 'host_confidence_status', 'host_confidence_family_size'}
    cpu_row_fields = {'gate_statistic', 'gate_speedup_simultaneous_ci95', 'host_median_speedup_simultaneous_ci95', 'live_median_speedup_simultaneous_ci95', 'live_p99_speedup_simultaneous_ci95'}
    if kind != 'cpu-snapshot' and (set(summary) & cpu_fields
                                   or any(set(row) & cpu_row_fields for row in summary['rows'])):
        raise ValueError('CPU confidence fields on a different comparison')

    if kind == 'cpu-snapshot':
        if summary.get('confidence_status', 'unqualified') not in ('historical_unqualified', 'simultaneous_gate_intervals', 'unqualified'):
            raise ValueError('Invalid CPU confidence status')
        version = summary.get('measurement_protocol_version', 0)
        if type(version) is not int or version not in (0, 2, 3):
            raise ValueError('Invalid CPU protocol version')
        family = summary.get('confidence_family_size')
        if family is not None and (type(family) is not int or family not in (3, 5)):
            raise ValueError('Invalid CPU confidence family')
        if summary.get('confidence_status') == 'simultaneous_gate_intervals' and (version != 3 or family not in (3, 5)):
            raise ValueError('CPU confidence provenance mismatch')
        host_status = summary.get('host_confidence_status', 'unqualified')
        if host_status not in ('historical_unqualified', 'simultaneous_gate_intervals', 'unqualified'):
            raise ValueError('Invalid exact live CPU confidence status')
        host_family = summary.get('host_confidence_family_size')
        if host_family is not None and (type(host_family) is not int or host_family != 2):
            raise ValueError('Invalid exact live CPU confidence family')
        if host_status == 'simultaneous_gate_intervals' and (version != 3 or host_family != 2):
            raise ValueError('Exact live CPU confidence provenance mismatch')
        for row in summary['rows']:
            if 'gate_statistic' in row and row['gate_statistic'] not in ('median_ratio', 'p99_ratio'):
                raise ValueError('Invalid CPU gate statistic')
            for field in ('gate_speedup_simultaneous_ci95', 'host_median_speedup_simultaneous_ci95', 'live_median_speedup_simultaneous_ci95', 'live_p99_speedup_simultaneous_ci95'):
                interval = row.get(field)
                if interval is not None and (not isinstance(interval, list) or len(interval) != 2
                                             or any(type(v) not in (int, float) or not math.isfinite(v) or v <= 0 for v in interval)
                                             or interval[0] > interval[1]):
                    raise ValueError('Invalid CPU gate interval')
            if 'host_median_speedup_simultaneous_ci95' in row and row.get('fixture') != 'rare-host-unavailable':
                raise ValueError('Exact default median interval on a different fixture')
            if any(field in row for field in ('live_median_speedup_simultaneous_ci95', 'live_p99_speedup_simultaneous_ci95')) and row.get('fixture') != 'host-live':
                raise ValueError('Exact live CPU bounds on a different fixture')

    if kind in ('sample-planning', 'archive-parsing', 'vendor-archive', 'metadata-modules', 'archive-modules'):
        if set(summary.get('source_sha256', {})) - CALLER_SOURCES or set(summary.get('library_sha256', {})) - {'native'}:
            raise ValueError('Unexpected public caller identity names')
        validate_caller_rows(summary, kind)

    if kind in ('fingerprint-ffi', 'fingerprint-worker', 'cpu-snapshot', 'sample-planning', 'archive-parsing', 'vendor-archive', 'metadata-modules', 'archive-modules'):
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
    'vendor-archive': {name: 'common' for name in ('rar4-stored', 'rar5-mixed', 'zip-stored', 'seven-stored')}
        | {name: 'uncommon' for name in ('rar4-solid', 'rar5-encrypted-headers', 'zip-aes', 'seven-encrypted-headers')},
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

CALLER_FIXTURES.update({kind: {name: row['category'] for name, row in strata.items()} for kind, strata in REMAINING_STRATA.items()})

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
    remaining = kind in REMAINING_STRATA
    if remaining and (summary.get('baseline_kind') != 'independent_php_reference_projection'
            or summary.get('evidence_type') != 'shared_independent_php_reference_projection'
            or summary.get('benchmark_mode') not in (('screen', 'qualification') if summary.get('status') == 'measured' else ('screen', 'qualification', 'unknown'))
            or summary.get('adoption_qualified') is not False or 'baseline_revision' in summary):
        raise ValueError('Invalid independent PHP reference scope')
    keys = {'fixture', 'phase', 'candidate_backend', 'samples', 'category', 'p99_sample_sufficient',
            'comparison', 'native_median_ms', 'metric', 'baseline_latency_ms', 'candidate_latency_ms',
            'speedup_interval', 'baseline_over_candidate', 'qualification_gate_passed', 'eligible',
            'confidence_family_size', 'marginal_alpha', 'baseline_latency_interval_ms', 'candidate_latency_interval_ms'}
    if remaining:
        keys |= {'module', 'correctness_observations', 'native_proof_observations'}
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
        if remaining:
            if (row.get('module') != REMAINING_STRATA[kind][fixture]['module']
                    or row.get('confidence_family_size') != 48 or type(row.get('confidence_family_size')) is not int
                    or not positive(row.get('marginal_alpha')) or not math.isclose(row['marginal_alpha'], .05/48, rel_tol=1e-12)
                    or summary.get('status') != 'measured' and (row['eligible'] or row.get('qualification_gate_passed') is True)):
                raise ValueError('Invalid remaining module proof or global gate')
        if row.get('comparison') == 'invalid' and row['eligible'] is False:
            if set(row) - ({'fixture', 'phase', 'candidate_backend', 'samples', 'category', 'p99_sample_sufficient', 'comparison', 'eligible'} | ({'module', 'confidence_family_size', 'marginal_alpha', 'qualification_gate_passed'} if remaining else set())) or row.get('candidate_backend') != 'rust':
                raise ValueError('Invalid caller row contains measurement claims')
            continue
        comparison = ('shared_independent_php_reference_projection' if remaining else 'native_only_diagnostic' if category == 'diagnostic' else 'same_php_control'
                      if category.endswith('control') else 'shared_par2_projection'
                      if kind == 'archive-parsing' else 'shared_vendor_header_projection'
                      if kind == 'vendor-archive' else 'exact_public_caller')
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
        if kind == 'vendor-archive' or remaining:
            family = 48 if remaining else 32
            if (type(row.get('confidence_family_size')) is not int or row['confidence_family_size'] != family or not positive(row.get('marginal_alpha'))
                    or not math.isclose(row['marginal_alpha'], 0.05 / family, rel_tol=1e-12)):
                raise ValueError('Invalid vendor archive confidence family')
            for key in ('baseline_latency_interval_ms', 'candidate_latency_interval_ms'):
                interval = row.get(key)
                if row['category'] == 'uncommon' and samples < 1000:
                    if interval is not None:
                        raise ValueError('Insufficient vendor archive tail samples')
                elif row['eligible']:
                    if (not isinstance(interval, list) or len(interval) != 2
                            or any(bound is not None and not positive(bound) for bound in interval)
                            or all(bound is not None for bound in interval) and interval[0] > interval[1]):
                        raise ValueError('Invalid vendor archive marginal interval')
            if row['eligible'] and row['metric'] != 'p99_unavailable':
                baseline, candidate = row['baseline_latency_interval_ms'], row['candidate_latency_interval_ms']
                for interval, estimate in ((baseline, row['baseline_latency_ms']), (candidate, row['candidate_latency_ms'])):
                    if interval[0] is not None and interval[0] > estimate or interval[1] is not None and interval[1] < estimate:
                        raise ValueError('Vendor archive marginal interval excludes estimate')
                expected_interval = [baseline[0] / candidate[1] if baseline[0] is not None and candidate[1] is not None else None,
                                     baseline[1] / candidate[0] if baseline[1] is not None and candidate[0] is not None else None]
                if (not isinstance(row.get('speedup_interval'), list)
                        or any(actual is not None if expected is None else not positive(actual) or not math.isclose(actual, expected, rel_tol=1e-12)
                               for actual, expected in zip(row['speedup_interval'], expected_interval))):
                    raise ValueError('Vendor archive speedup interval disagrees with marginal bounds')
        if remaining:
            if (type(row.get('qualification_gate_passed')) is not bool
                    or type(row.get('correctness_observations')) is not int or row['correctness_observations'] != 2*samples
                    or type(row.get('native_proof_observations')) is not int or row['native_proof_observations'] != samples):
                raise ValueError('Invalid remaining module observation proof counts')
            interval = row.get('speedup_interval')
            required_gate = (summary['benchmark_mode'] == 'qualification' and row['eligible'] and samples >= 1000
                             and isinstance(interval, list) and interval[0] is not None and interval[0] >= 1.5)
            if row['qualification_gate_passed'] is not required_gate:
                raise ValueError('Remaining projection gate disagrees with evidence')
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
        if remaining:
            if (not (REMAINING_SOURCES | REMAINING_INSTRUMENTATION) <= set(summary['source_sha256'])
                    or summary.get('completeness') != dict(expected_rows=24, observed_rows=24, complete=True)
                    or len({row['samples'] for row in summary['rows']}) != 1
                    or summary['benchmark_mode'] == 'qualification' and any(row['samples'] < 1000 for row in summary['rows'])):
                raise ValueError('Invalid remaining module source provenance or completeness')
        if kind == 'vendor-archive':
            sources = summary['source_sha256']
            if (not VENDOR_SOURCE_PATHS <= set(sources)
                    or any(sources.get(path) != expected_hash for path, expected_hash in VENDOR_INPUTS.values())
                    or summary.get('evidence_type') != 'shared_vendor_archive_header_projection'):
                raise ValueError('Invalid locked vendor archive source provenance')


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
        elif name == 'formats':
            label, detail = 'Shared header caller comparison and diagnostics', 'Eight locked PHP-reader/native initial-header projections for RAR4/RAR5/ZIP/7z with caller FFI/JSON costs. Full archive semantics and ISO have no equivalent baseline or adoption qualification.'
        elif name in ('seven_zip_ranges', 'par2_source_ranges'):
            label, detail = 'Native caller diagnostics', 'Correctness-gated bounded range fixtures with FFI and JSON costs; no equivalent PHP range baseline or exhaustive archive coverage.'
        elif name in ('sample_plan', 'spotcheck_plan'):
            label, detail = 'Comparative public caller benchmark', 'Exact serialized PHP/native output, cold/warm calls, common median and uncommon p99 qualification mode; small/unsupported inputs remain PHP controls.'
        elif name in ('header_filename_identity', 'header_filename_unicode'):
            label, detail = 'Comparative public caller benchmark', 'Guarded candidate for HeaderFilenameIdentity, not wired into production. Per-call and 16-call release-batch PHP versus Rust+FFI on a synthetic corpus runs in the archive-sample-performance filename-identity job (x86 and ARM, step summary); not collected on this page and no adoption qualification.'
        elif name == 'per_article_obfuscation':
            label, detail = 'Comparative public caller benchmark', 'Adopted for HeaderParser::markPerArticleObfuscated with the PHP rule as fallback. Paired PHP versus Rust+FFI over seeded common and uncommon header ranges, with native parity, runs in the archive-sample-performance filename-identity job (x86 and ARM, step summary); common median and uncommon p99 qualification, not collected on this page.'
        elif name in ('article_identity', 'article_xref', 'article_dates', 'iso_primary_copies', 'rar5_copy_checksums', 'rar5_quick_open'):
            label, detail = 'Shared independent PHP reference comparison', 'New bounded PHP references compare declared shared projections only; actual native wrapper includes FFI, allocation, JSON decoding and the full richer native result before projection. Cold/warm common median and uncommon p99 gates do not qualify adoption or historical PHP speedups.'
        elif name in ('iso_path_tables', 'zstd_frame', 'xz', 'xz_frame', 'xz_first_block'):
            label, detail = 'No automated benchmark', 'Explicit coverage gap: no independent PHP reference comparison or qualified caller benchmark.'
        elif name in ('abi_crc', 'abi_decoder', 'abi_decoder_arm', 'abi_decoder_x86'):
            modes = ['experimental']
            label, detail = 'No automated benchmark', 'Explicit coverage gap: opt-in experimental RapidYenc ABI implementation with no production PHP caller or live comparative benchmark.'
        elif name in ('abi_receipts', 'benchmark', 'evidence', 'installation', 'tooling', 'install-yenc', 'qualify-yenc', 'verify-yenc-evidence'):
            label, detail = 'Auxiliary correctness only', 'Offline yEnc ABI qualification, receipt verification and installation guard tooling; live benchmark execution is disabled and running it does not measure its own performance.'
        elif name == 'nntmux-evidence-utility':
            label, detail = 'Auxiliary correctness only', 'CI evidence and rendering utility; running it does not measure its own performance.'
        elif name == 'lib':
            label, detail = 'Mixed coverage', 'FFI exports: decoding/rendering comparisons; metadata profiles; some APIs unbenchmarked.'
        elif name in ('bulk_verification', 'nntmux-verification-framer'):
            label, detail = 'No automated benchmark', 'Explicit coverage gap: local paired BODY/STAT caller screens include Rust startup and IPC, but have no p99/TCP/TLS adoption qualification or automated evidence collection. Rust remains disabled by default; the PHP pipeline is retained.'
        elif name in ('article_fingerprint', 'nntmux-article-fingerprint', 'benchmark-article-fingerprint'):
            label, detail = 'Comparative archived-candidate benchmark', 'Application fingerprinting uses PHP. Nine archived Rust candidate PHP/FFI/worker strata, cold/repeated lifecycle costs; tail estimates require 1000 pairs via dispatch. PHP process startup excluded; Rust worker startup included.'
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

    Search is bounded to the newest 20 trusted main push runs. In-progress,
    failed, cancelled or partial attempts are never replaced by an older attempt
    of that same run. Both architecture summaries must belong to its latest attempt.
    """
    result = dict(status='No complete comparison in newest 20 trusted main push runs', results=[], unavailable_runs=0, expired_artifacts=0, searched_runs=0)
    try:
        runs = fetch('actions/workflows/yenc-performance.yml/runs?branch=main&event=push&per_page=20')['workflow_runs']
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
                    validate_summary(summary, run, 'cpp', runner, historical=True)
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


def rust_interfaces(root):
    spec = importlib.util.spec_from_file_location('rust_pages_inventory', Path(__file__).with_name('benchmark_rust_inventory.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.inventory(root)


def manual_job_name(kind, runner):
    if kind in ('cpp', 'php', 'profile'):
        return {'cpp': 'paired-decoding', 'php': 'Rust versus PHP',
                'profile': 'Article processing comparison'}[kind] + f' ({runner})'
    if kind == 'presentation':
        return 'native-presentation'
    if kind in ('fingerprint-ffi', 'fingerprint-worker'):
        return f'Experimental comparison {kind} ({runner})'
    if kind == 'cpu-snapshot':
        return f'CPU snapshot comparison {kind} ({runner})'
    return f'{kind} ({runner})'


def collect_dispatches(sha, fetch, archive_download):
    """Keep bounded trusted manual studies separate from the automatic push matrix.

    Show the three newest runs per producer, including latest-attempt failures.
    Selective dispatches are identified from exact job/artifact names. A failed
    selection lookup remains visible and never silently substitutes older data.
    """
    results, runs_seen = [], []
    for workflow, expected in WORKFLOWS.items():
        if not expected:
            continue
        try:
            candidates = fetch(f'actions/workflows/{workflow}/runs?branch=main&event=workflow_dispatch&head_sha={sha}&per_page=20')['workflow_runs']
            trusted = [r for r in candidates if isinstance(r, dict) and r.get('event') == 'workflow_dispatch'
                and type(r.get('id')) is int and r['id'] > 0 and type(r.get('run_attempt')) is int and r['run_attempt'] > 0
                and select_run([dict(r, event='push')], sha) is not None]
        except (OSError, ValueError, KeyError, TypeError):
            runs_seen.append(dict(workflow=workflow, status='Manual collection unavailable'))
            continue
        for run in sorted(trusted, key=lambda r: r['id'], reverse=True)[:3]:
            info = dict(workflow=workflow, run_id=run['id'], run_attempt=run['run_attempt'],
                        status=run.get('conclusion') or run.get('status', 'unknown'))
            runs_seen.append(info)
            try:
                artifacts = fetch(f"actions/runs/{run['id']}/artifacts?per_page=100")['artifacts']
                jobs = fetch(f"actions/runs/{run['id']}/attempts/{run['run_attempt']}/jobs?per_page=100")['jobs']
                names = {j['name'] for j in jobs if isinstance(j, dict) and isinstance(j.get('name'), str)}
            except (OSError, ValueError, KeyError, TypeError):
                info['status'] = 'Manual selection or artifact collection unavailable'
                continue
            for kind, runner in expected:
                name = f"public-benchmark-{kind}-{runner}-{run['id']}-{run['run_attempt']}"
                matched = [a for a in artifacts if isinstance(a, dict) and a.get('name') == name]
                if not matched and manual_job_name(kind, runner) not in names:
                    continue
                result = dict(kind=kind, runner=runner, rows=[], adoption_qualified=False, rust_payload_mode='unknown',
                              run_id=run['id'], run_attempt=run['run_attempt'], workflow_status=info['status'],
                              status='Summary unavailable' if run.get('status') == 'completed' else 'Pending')
                results.append(result)
                if len(matched) == 1:
                    try:
                        artifact = matched[0]
                        if artifact['expired'] or artifact['size_in_bytes'] > 2 * LIMIT:
                            raise ValueError('Expired or oversized manual summary')
                        summary = read_summary(archive_download(artifact['id']))
                        validate_summary(summary, run, kind, runner)
                        result.update(summary, artifact_id=artifact['id'])
                        result['rust_payload_mode'] = measured_payload_mode(summary)
                    except (OSError, ValueError, KeyError, TypeError, RuntimeError, NotImplementedError, zipfile.BadZipFile):
                        result['status'] = 'Summary invalid or unavailable'
    return results, runs_seen


def collect(sha, root, fetch=api, archive_download=download):
    report = dict(schema_version=1, repository=REPOSITORY, head_sha=sha,
                  generated_at=datetime.now(timezone.utc).isoformat(), workflows=[], results=[], coverage=coverage(root),
                  frozen_reports_available=(root / 'github-pages/benchmarks/index.html').is_file(),
                  rust_exports=rust_interfaces(root))
    for workflow, expected in WORKFLOWS.items():
        try:
            runs = fetch(f'actions/workflows/{workflow}/runs?branch=main&event=push&head_sha={sha}&per_page=100')['workflow_runs']
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
    report['dispatch_results'], report['dispatch_runs'] = collect_dispatches(sha, fetch, archive_download)
    return report


def escape(value):
    return html.escape(str(value), quote=True)


def run_link(row):
    if 'run_id' not in row:
        return '—'
    number, attempt = int(row['run_id']), int(row['run_attempt'])
    return f'<a href="https://github.com/{REPOSITORY}/actions/runs/{number}/attempts/{attempt}">Run {number}, attempt {attempt}</a>'


def compact_number(value, decimals=3):
    if type(value) not in (int, float):
        return json.dumps(value, ensure_ascii=False)
    try:
        return f'{value:.{decimals}f}'
    except (ValueError, OverflowError):
        return str(value)


def latency_ms(value, divisor=1):
    if type(value) not in (int, float):
        return json.dumps(value, ensure_ascii=False)
    try:
        value = value / divisor
    except (OverflowError, ValueError):
        return str(value) + ' / ' + str(divisor) + ' ms'
    if 0 < value < 0.001:
        return '<0.001 ms'
    return compact_number(value) + ' ms'


def display_interval(value, unit):
    if not isinstance(value, list) or len(value) != 2:
        return json.dumps(value, ensure_ascii=False)
    def bound(number, index):
        if number is None:
            return 'unbounded'
        if type(number) not in (int, float):
            return json.dumps(number, ensure_ascii=False)
        try:
            rounded = Decimal(str(number)).quantize(Decimal('0.001'), rounding=ROUND_FLOOR if index == 0 else ROUND_CEILING)
            text = f'{rounded:.3f}'
        except (InvalidOperation, ValueError, OverflowError):
            text = str(number)
        return text + (' ms' if unit == 'ms' else '×')
    return '[' + ', '.join(bound(number, index) for index, number in enumerate(value)) + ']'



def measurement_table(rows):
    labels = ('size', 'density', 'path', 'phase', 'scenario', 'rows', 'fixture')
    latencies = {
        'php_median_ns': ('PHP median', 1000000), 'rust_median_ns': ('Rust median', 1000000),
        'baseline_median_ns': ('Baseline median', 1000000), 'candidate_median_ns': ('Candidate median', 1000000),
        'baseline_p99_ns': ('Baseline p99', 1000000), 'candidate_p99_ns': ('Candidate p99', 1000000),
        'baseline_latency_ms': ('Baseline latency', 1), 'candidate_latency_ms': ('Candidate latency', 1),
        'native_median_ms': ('Native median', 1), 'median_ms': ('Median', 1), 'min_ms': ('Minimum', 1), 'max_ms': ('Maximum', 1),
    }
    ratios = {
        'median_rust_over_cpp': 'Rust / C++ latency', 'median_rust_over_php': 'Rust / PHP latency',
        'median_ratio': 'Median baseline / candidate', 'paired_median_ratio': 'Paired median baseline / candidate',
        'median_baseline_over_candidate_ratio': 'Median baseline / candidate',
        'median_cold_baseline_over_candidate_ratio': 'Cold median baseline / candidate',
        'p99_ratio': 'p99 baseline / candidate', 'baseline_over_candidate': 'Baseline / candidate',
    }
    intervals = {
        'bootstrap_95_percent_interval': ('Rust / baseline 95% interval', 'ratio'),
        'median_ratio_ci95': ('Median ratio 95% interval', 'ratio'), 'p99_ratio_ci95': ('p99 ratio 95% interval', 'ratio'),
        'speedup_interval': ('Speedup 95% interval', 'ratio'),
        'baseline_latency_interval_ms': ('Baseline quantile interval', 'ms'),
        'candidate_latency_interval_ms': ('Candidate quantile interval', 'ms'),
    }
    decision_fields = {'speedup_interval', 'baseline_latency_interval_ms', 'candidate_latency_interval_ms',
        'gate_speedup_simultaneous_ci95', 'host_median_speedup_simultaneous_ci95',
        'live_median_speedup_simultaneous_ci95', 'live_p99_speedup_simultaneous_ci95', 'gate_statistic'}
    has_diagnostics = any(set(row) & (set(intervals) - decision_fields) for row in rows)
    has_bounds = any(set(row) & decision_fields for row in rows)
    table = ['<table><tr><th>Workload</th><th>Latency / ratio</th>'
        + ('<th>Recorded / diagnostic intervals</th>' if has_diagnostics else '')
        + ('<th>Simultaneous decision bounds</th>' if has_bounds else '')
        + '<th>Scope / samples / eligibility</th></tr>']
    comparisons = {
        'shared_par2_projection': 'Shared PAR2 projection only',
        'shared_vendor_header_projection': 'Shared archive header projection only (locked PHP readers)',
        'native_only_diagnostic': 'Native-only diagnostic; no baseline',
        'same_php_control': 'PHP control; Rust speedup not applicable',
        'shared_independent_php_reference_projection': 'Shared independent PHP reference projection', 'exact_public_caller': 'Exact public caller', 'exact_public_fingerprint_operation': 'Exact public fingerprint operation',
        'exact_host_cli': 'Exact host CLI', 'synthetic_cli': 'Synthetic CLI',
    }
    metrics = {'p99_unavailable': 'Tail unavailable (<1,000 pairs)', 'p99_latency': 'p99 latency',
               'median_latency': 'Median latency', 'not_applicable': 'Comparison not applicable'}
    status_keys = {'decision', 'status', 'eligible_pairs', 'invalid_pairs', 'gate_passed', 'performance_gate',
                   'p99_sample_sufficient', 'qualification_gate_passed', 'p99_threshold_met', 'category',
                   'candidate_backend', 'correctness_passed', 'bootstrap_replicates', 'confidence_family_size',
                   'iterations', 'marginal_alpha'}
    for row in rows:
        label = ' · '.join(str(row[key]) for key in labels if key in row)
        values, diagnostics, bounds, states = [], [], [], []
        for key, value in row.items():
            if key in labels:
                continue
            if key in latencies:
                title, divisor = latencies[key]
                values.append(title + ': ' + ('unavailable' if value is None else latency_ms(value, divisor)))
            elif key in ratios:
                values.append(ratios[key] + ': ' + ('unavailable' if value is None else compact_number(value, 2) + '×'))
            elif key in ('gate_speedup_simultaneous_ci95', 'host_median_speedup_simultaneous_ci95', 'live_median_speedup_simultaneous_ci95', 'live_p99_speedup_simultaneous_ci95'):
                bounds.append(key + ': ' + ('unavailable' if value is None else display_interval(value, 'ratio')))
            elif key == 'gate_statistic':
                bounds.append(key + ': ' + str(value))
            elif key in intervals:
                title, unit = intervals[key]
                destination = bounds if key in ('speedup_interval', 'baseline_latency_interval_ms', 'candidate_latency_interval_ms') else diagnostics
                destination.append(title + ': ' + ('unavailable' if value is None else display_interval(value, unit)))
            elif key == 'body_ffi_median_ms' and isinstance(value, dict):
                values.extend('BODY ' + str(stage) + ': ' + latency_ms(duration) for stage, duration in value.items())
            elif key == 'improvement_percent':
                values.append('Improvement: ' + compact_number(value, 2) + '%')
            elif key == 'samples':
                states.append('N=' + str(value))
            elif key == 'eligible':
                states.append('Eligible' if value is True else 'Ineligible' if value is False else 'Eligibility: ' + str(value))
            elif key == 'comparison':
                states.append(comparisons.get(value, 'Comparison: ' + str(value)) if isinstance(value, str) else 'Comparison: ' + str(value))
            elif key == 'metric':
                states.append(metrics.get(value, 'Metric: ' + str(value)) if isinstance(value, str) else 'Metric: ' + str(value))
            elif key in status_keys:
                states.append(key.replace('_', ' ') + ': ' + json.dumps(value, ensure_ascii=False))
            else:
                # Keep unfamiliar fields visible; the exact envelope remains below.
                values.append(str(key) + ': ' + json.dumps(value, ensure_ascii=False))
        cells = [escape(label), '<br>'.join(escape(value) for value in values)]
        if has_diagnostics:
            cells.append('<br>'.join(escape(value) for value in diagnostics))
        if has_bounds:
            cells.append('<br>'.join(escape(value) for value in bounds))
        cells.append('<br>'.join(escape(value) for value in states))
        table.append('<tr><td>' + '</td><td>'.join(cells) + '</td></tr>')
    table.append('</table>')
    return '\n'.join(table)


def render(report):
    body = ['<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">',
            '<title>NNTmux Rust benchmarks</title><style>body{font:16px system-ui;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#182332}table{border-collapse:collapse;width:100%;margin:1rem 0}th,td{text-align:left;padding:.55rem;border-bottom:1px solid #ccd5dd}code{overflow-wrap:anywhere}pre{white-space:pre-wrap;overflow-wrap:anywhere}a{color:#175eaa}details{margin:1rem 0}</style>',
            '<h1>NNTmux Rust benchmarks</h1>',
            f'<p>main build <code>{escape(report["head_sha"])}</code>. Updated {escape(report["generated_at"])}. <a href="results.json">Machine-readable results</a>.</p>',
            '<p>Coverage is incomplete. Comparative median screens and diagnostic profiles are separate evidence. These builds do not establish the required 1.5× gain for every common path and representative uncommon p99 workload, or production throughput.</p>',
            '<p>Each main push runs fresh yEnc comparisons, BODY/fingerprint profiles, NZB digest/CPU comparisons, archive diagnostics, locked PHP-reader header comparisons and sample-planning comparisons on x86/ARM, plus release rendering on x86. Missing, failed, cancelled and inconclusive results remain visible. Trusted manual runs of this exact main commit appear separately below; feature-branch and older-source experiments are excluded. Raw evidence is retained in Actions for 14 days; published summaries last until the next build.</p>',
            '<h2>Build checks</h2><table><tr><th>Workflow</th><th>Latest state</th><th>Evidence</th></tr>']
    for row in report['workflows']:
        body.append(f'<tr><td>{escape(row["workflow"])}</td><td>{escape(row.get("status", "unknown"))}</td><td>{run_link(row)}</td></tr>')
    body.append('</table><h2>Current measurements</h2>')
    if report.get('frozen_reports_available'):
        body.append('<p><a href="benchmarks/">Historical speedup charts, PHP call paths and native flamegraphs</a>. These frozen reports retain their own executed-commit and run provenance; they are separate from this build.</p>')
    manual_heading = ['<h2>Trusted manual measurements</h2><p>The three newest trusted main dispatches per producer are shown separately. Each uses its latest attempt. Failures and unavailable selections remain visible; older manual results do not replace newer failures or automatic push screens. Qualification and screen modes retain their own sample counts and uncertainty.</p><table><tr><th>Producer</th><th>Latest attempt state</th><th>Run</th></tr>']
    for row in report.get('dispatch_runs', []):
        manual_heading.append('<tr><td>' + escape(row['workflow']) + '</td><td>' + escape(row.get('status', 'unknown')) + '</td><td>' + run_link(row) + '</td></tr>')
    manual_heading.append('</table>')
    for index, row in enumerate(report['results'] + report.get('dispatch_results', [])):
        if index == len(report['results']):
            body.extend(manual_heading)
        body.append(f'<details><summary>{escape(row["kind"])} · {escape(row["runner"])} · {escape(row.get("status", "unknown"))}</summary><p>{run_link(row)}. Evidence: {escape(row.get("evidence_type", "unavailable"))}; adoption qualification: not established.</p>')
        if row.get('candidate_status') == 'experimental_withdrawn_from_production':
            body.append('<p>This archived Rust fingerprint candidate is withdrawn from the application runtime. The application uses PHP fingerprinting. These archived-candidate measurements do not select the production implementation or qualify adoption.</p>')
        if row.get('kind') == 'cpu-snapshot':
            provenance = row.get('runtime_identity', {})
            supported = (row.get('status') in ('measured', 'measured_gate_failed')
                         and row.get('measurement_protocol_version') == 3
                         and row.get('confidence_status') == 'simultaneous_gate_intervals'
                         and row.get('confidence_family_size') in (3, 5)
                         and isinstance(provenance, dict)
                         and isinstance(row.get('head_sha'), str)
                         and re.fullmatch('[0-9a-f]{40,64}', row['head_sha'])
                         and provenance.get('executed_sha') == row['head_sha']
                         and all(isinstance(row.get(key), str) and re.fullmatch('[0-9a-f]{64}', row[key])
                                 for key in ('protocol_sha256', 'report_sha256')))
            if supported:
                body.append('<p>CPU protocol 3 uses independent paired bootstrap samples and simultaneous 95% intervals across its declared fixture gates. Other intervals are diagnostic per-statistic 95%; this does not establish production adoption qualification.</p>')
            else:
                version = row.get('measurement_protocol_version')
                if type(version) is int and version == 2:
                    limitation = ('source-pinned protocol 2 uses corrected mixed-output resampling with pointwise intervals only.'
                                  if row.get('uncertainty_method') == 'mixed_output_bootstrap'
                                  else 'historical protocol 2 uses dependent bootstrap samples.')
                elif type(version) is int and version == 3:
                    limitation = 'CPU protocol 3 lacks complete qualified sample or runtime provenance.'
                else:
                    limitation = 'CPU measurement protocol provenance is unavailable.'
                body.append('<p>CPU interval limitation: ' + limitation + ' Unqualified interval fields do not establish qualified uncertainty. Point estimates remain recorded measurements. <a href="performance.html#utility-results">Read the protocol limits</a>.</p>')
            if (row.get('status') in ('measured', 'measured_gate_failed')
                    and row.get('measurement_protocol_version') == 3
                    and row.get('host_confidence_status') == 'simultaneous_gate_intervals'
                    and row.get('host_confidence_family_size') == 2
                    and isinstance(provenance, dict)
                    and provenance.get('executed_sha') == row.get('head_sha')
                    and isinstance(row.get('head_sha'), str)
                    and re.fullmatch('[0-9a-f]{40,64}', row['head_sha'])
                    and all(isinstance(row.get(key), str) and re.fullmatch('[0-9a-f]{64}', row[key])
                            for key in ('protocol_sha256', 'report_sha256'))):
                body.append('<p>Exact live-host CLI decision bounds form a separate two-gate simultaneous 95% family (median and p99). They are separate from the synthetic and absent-/proc fixture family and do not establish production throughput.</p>')
        if row.get('kind') in ('cpp', 'php', 'profile'):
            body.append('<p>Rust payload mode: ' + escape(measured_payload_mode(row)) + '.</p>')
        if row.get('provenance_status'):
            body.append('<p>Executed-source provenance: ' + escape(row['provenance_status']) +
                        '; hardware qualification: ' + escape(row.get('hardware_qualification', 'unavailable')) + '.</p>')
        if row.get('rust_payload_kernel'):
            body.append('<p>Observed payload kernel: ' + escape(row['rust_payload_kernel']['name']) + '.</p>')
        if row.get('status') == 'unsupported_hardware':
            body.append('<p>The designated kernel ' + escape(row['required_payload_kernel']['name']) +
                        ' was unavailable on this worker. The strict qualification gate stopped this comparison; no speedup is claimed.</p>')
        if row.get('candidate_head_sha'):
            body.append('<p>Measured experiment source <code>' + escape(row['candidate_head_sha']) +
                        '</code> differs from this harness build; it is not current-source performance evidence.</p>')
        if 'workflow_status' in row:
            body.append(f'<p>Overall workflow: {escape(row["workflow_status"])}</p>')
        body.append(measurement_table(row['rows']) if row['rows'] else '<p>No current measurement rows available.</p>')
        body.append('<details><summary>Summary data and identities</summary><pre>' + escape(json.dumps(row, indent=2, ensure_ascii=False, allow_nan=False)) + '</pre></details></details>')
    if report.get('dispatch_runs') and not report.get('dispatch_results'):
        body.extend(manual_heading)
    historical = report.get('last_valid_comparison', {})
    if historical:
        body.append('<h2>Last valid complete C++ comparison</h2><p>' + escape(historical.get('status')) + '. Historical evidence remains separate from the current build; a newer PHP-only/profile-only run cannot replace this comparison.</p>')
        if historical.get('unavailable_runs') or historical.get('expired_artifacts'):
            body.append('<p>Unavailable attempts: ' + escape(historical.get('unavailable_runs', 0)) + '; expired summary artifacts: ' + escape(historical.get('expired_artifacts', 0)) + '. Newer unavailable attempts may contain comparisons that cannot be verified.</p>')
        if historical.get('results'):
            body.append('<p>Executed source <code>' + escape(historical['head_sha']) + '</code>. ' + run_link(historical) + '.</p>')
            for row in historical['results']:
                body.append('<h3>' + escape(row['runner']) + '</h3><p>Rust payload mode: ' + escape(measured_payload_mode(row)) + '.</p>' + measurement_table(row['rows']))
                body.append('<details><summary>Summary data and identities</summary><pre>' + escape(json.dumps(row, indent=2, ensure_ascii=False, allow_nan=False)) + '</pre></details>')
    body.append('<h2>Rust target coverage and priorities</h2><p>Next priorities: inspect cold fingerprint costs and worker startup, sample-planner projection/JSON costs, and repeated native body/format scans. Native-only archive diagnostics have no equivalent PHP baseline; locked-reader comparisons cover initial headers only. Qualification runs must bound uncommon tails before proposing replacements. Newly added Rust sources appear as gaps until their benchmark mapping is reviewed.</p><table><tr><th>Source target</th><th>Automated evidence</th><th>Scope / gap</th></tr>')
    for row in report['coverage']:
        body.append(f'<tr><td><code>{escape(row["target"])}</code></td><td>{escape(row["coverage"])}</td><td>{escape(row["detail"])}</td></tr>')
    body.append('</table><h2 id="rust-interfaces">Public Rust interfaces</h2><p>Tracked Rust ABI declarations are discovered from source, including compatibility exports and lifecycle calls. The classification describes caller wiring and evidence scope, not an individual-function timing or adoption approval. CI rejects new or removed declarations until their classification is reviewed. This source inventory does not expand macros or determine compiled conditional branches.</p><table><tr><th>Interface / source</th><th>Evidence scope</th><th>Caller and limits</th></tr>')
    for row in report.get('rust_exports', []):
        source = 'https://github.com/' + REPOSITORY + '/blob/' + report['head_sha'] + '/' + row['target'] + '#L' + str(row['line'])
        body.append('<tr><td><a href="' + escape(source) + '"><code>' + escape(row['name']) +
                    '</code></a></td><td>' + escape(row['coverage']) + ': ' + escape(', '.join(row['evidence_kinds']) or 'No comparative measurement') +
                    '</td><td>' + escape(row['detail']) + ' ' + escape(', '.join(row['callers'])) + '</td></tr>')
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
