#!/usr/bin/env python3
"""Export bounded synthetic identity measurements; never publish raw observations.

Legacy reports without measurement-time receipts remain ineligible. New runs
freeze executed inputs; all metrics and producer gates remain non-adoption evidence.
"""
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import re

KINDS = ('filename-identity', 'per-article-obfuscation')
FILENAME = dict.fromkeys(('readable-quoted', 'obfuscated-quoted', 'unquoted'), 'common') | dict.fromkeys(
    ('multi-quote-apostrophe', 'unicode', 'invalid-utf8', 'long-subject'), 'uncommon')
ARTICLE = dict.fromkeys(('real-uploads', 'kenpsx', 'boneless', 'mixed', 'text-heavy-obfuscated',
    'readable-40-obfuscated', 'readable-25-obfuscated', 'text-half-obfuscated', 'long-token-obfuscated'), 'common') | dict.fromkeys(
    ('readable-yenc', 'bare-random', 'single-part-hashed', 'readable-tenth-obfuscated', 'small-text', 'small-readable',
     'text-third-obfuscated', 'long-text-obfuscated', 'text-with-uploads', 'non-yenc', 'small-range', 'crafted-whitespace'), 'routed') | dict.fromkeys(
    ('large-range', 'long-subjects', 'adversarial'), 'uncommon')
SOURCES = {'app/Services/Releases/Verification/HeaderFilenameIdentity.php',
    'app/Services/Releases/Verification/NativeHeaderFilenameIdentity.php',
    'docker/app/nzb-yenc-ffi/src/header_filename_identity.rs'}
LIMIT = 32 * 1024 * 1024
ROOT = Path(__file__).resolve().parents[1]
SOURCE_PATHS = SOURCES | {
    'app/Services/Binaries/HeaderParser.php', 'app/Services/Binaries/NativePerArticleObfuscation.php',
    'app/Services/Binaries/PerArticleObfuscationGate.php', 'scripts/benchmark-filename-identity.php',
    'scripts/benchmark-per-article-obfuscation.php', 'tests/Support/HeaderFilenameIdentityCorpus.php',
    'tests/Support/PerArticleObfuscationCorpus.php', 'composer.json', 'composer.lock',
    'vendor/composer/installed.json', 'docker/app/nzb-yenc-ffi/Cargo.toml', 'docker/app/nzb-yenc-ffi/Cargo.lock',
} | {p.relative_to(ROOT).as_posix() for p in (ROOT / 'docker/app/nzb-yenc-ffi/src').rglob('*.rs')}
RESOURCE_KEYS = {'resource_limits_before', 'resource_limits_after', 'resource_limits_comparison'}


def strict_json(raw):
    def invalid(value):
        raise ValueError('Nonfinite JSON')
    def finite(value):
        result = float(value)
        if not math.isfinite(result):
            raise ValueError('Nonfinite JSON')
        return result
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('Duplicate JSON key')
            result[key] = value
        return result
    return json.loads(raw, parse_constant=invalid, parse_float=finite, object_pairs_hook=unique)


def bounded_read(path, limit):
    with path.open('rb') as stream:
        raw = stream.read(limit + 1)
    if len(raw) > limit:
        raise ValueError('Oversized evidence')
    return raw


def capture(root, library):
    """Freeze executed inputs, including generated Unicode bytes, before/after timing."""
    return {'executed_sha': os.environ.get('GITHUB_SHA', ''),
            'source_sha256': {key: hashlib.sha256((root / key).read_bytes()).hexdigest() for key in sorted(SOURCE_PATHS)},
            'library_sha256': hashlib.sha256(library.read_bytes()).hexdigest()}


def receipts(root, result, report):
    import importlib.util
    evidence = root / 'filename-identity-evidence'
    snapshot_bytes = {phase: bounded_read(evidence / ('measurement-' + phase + '.json'), 1024 * 1024) for phase in ('before', 'after')}
    before, after = [strict_json(snapshot_bytes[phase]) for phase in ('before', 'after')]
    if before != after or before['executed_sha'] != result['head_sha'] or set(before['source_sha256']) != SOURCE_PATHS:
        raise ValueError('Measurement identity changed')
    hashes = {key: digest(value) for key, value in before['source_sha256'].items()}
    if digest(before['library_sha256']) != report['environment']['library_sha256']:
        raise ValueError('Measured library mismatch')
    for key, value in report['environment'].get('source_sha256', {}).items():
        if key not in hashes or value != hashes[key]:
            raise ValueError('Report source mismatch')
    runtime_raw = bounded_read(evidence / 'runtime.json', 65536)
    runtime = strict_json(runtime_raw)
    if runtime['executed_sha'] != result['head_sha']:
        raise ValueError('Runtime source mismatch')
    allowed = ('executed_sha', 'rustc', 'cargo', 'architecture', 'runner_image_version', 'build_profile')
    if any(not isinstance(runtime.get(key), str) or not runtime[key] or len(runtime[key]) > 4096 for key in allowed):
        raise ValueError('Incomplete runtime')
    expected_arch = {'aarch64', 'arm64'} if result['runner'] == 'ubuntu-24.04-arm' else {'x86_64', 'amd64'}
    if runtime['architecture'] not in expected_arch:
        raise ValueError('Runner architecture mismatch')
    result['runtime_identity'] = {key: runtime[key] for key in allowed}
    result['identity_runtime_sha256'] = hashlib.sha256(runtime_raw).hexdigest()
    spec = importlib.util.spec_from_file_location('identity_resources', ROOT / 'scripts/benchmark-resource-limits.py')
    resources = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(resources)
    for phase in ('before', 'after', 'comparison'):
        strict_json(bounded_read(evidence / ('resource-limits-' + phase + '.json'), 1024 * 1024))
    hashes.update(resources.verified_receipt(evidence))
    result['source_sha256'] = hashes
    result['identity_provenance_status'] = 'verified_current'
    result['identity_manifest_sha256'] = {phase: hashlib.sha256(snapshot_bytes[phase]).hexdigest()
        for phase in ('before', 'after')}



def numeric(value, zero=False):
    if type(value) not in (int, float) or not math.isfinite(value) or (value < 0 if zero else value <= 0):
        raise ValueError('Invalid number')
    return value


def count(value):
    if type(value) is not int or value < 1 or value > 100000000:
        raise ValueError('Invalid sample count')
    return value


def digest(value):
    if not isinstance(value, str) or not re.fullmatch('[0-9a-f]{64}', value):
        raise ValueError('Invalid digest')
    return value


def interval(value):
    if not isinstance(value, list) or len(value) != 2:
        raise ValueError('Invalid interval')
    result = [numeric(v) for v in value]
    if result[0] > result[1]:
        raise ValueError('Reversed interval')
    return result


def row(fixture, category, phase, metric, samples, ratio, bounds, baseline=None, candidate=None):
    result = dict(fixture=fixture, category=category, phase=phase, metric=metric,
                  samples=count(samples), baseline_over_candidate=numeric(ratio),
                  diagnostic_speedup_interval=interval(bounds), eligible=False)
    if baseline is not None:
        result['baseline_latency_ms'] = numeric(baseline, zero=True)
        result['candidate_latency_ms'] = numeric(candidate, zero=True)
    return result


def measurements(kind, report):
    definitions = FILENAME if kind == 'filename-identity' else ARTICLE
    strata = report['strata']
    if not isinstance(strata, dict) or set(strata) != set(definitions):
        raise ValueError('Incomplete fixture matrix')
    rows = []
    if kind == 'filename-identity':
        for fixture, category in definitions.items():
            value = strata[fixture]
            if value['category'] != category or type(value['parity_mismatches']) is not int or value['parity_mismatches'] != 0:
                raise ValueError('Invalid parity')
            for metric, key in (('median_latency', 'median'), ('p99_latency', 'p99')):
                block = value[key]
                samples = count(value['observations'])
                if count(block['php']['samples']) != samples or count(block['rust']['samples']) != samples:
                    raise ValueError('Sample mismatch')
                rows.append(row(fixture, category, 'warm', metric, samples, block['speedup'],
                    [block['speedup_lower'], block['speedup_upper']], block['php']['estimate'] / 1e6, block['rust']['estimate'] / 1e6))
        batch = report['batch']
        if report['protocol']['batch_size'] != 16:
            raise ValueError('Unexpected batch size')
        for metric, key in (('median_latency', 'median'), ('p99_latency', 'p99')):
            block = batch[key]
            rows.append(row('release-batch-16', 'common', 'release-batch', metric, batch['observations'], block['speedup'],
                [block['speedup_lower'], block['speedup_upper']], block['php']['estimate'] / 1e6, block['rust']['estimate'] / 1e6))
    else:
        cold = report['cold']
        bounded = report['startup_bounded_p99']
        if set(cold) != set(definitions) - {'large-range'} or set(bounded) != {k for k, v in definitions.items() if v == 'uncommon'}:
            raise ValueError('Incomplete cold/startup matrix')
        for fixture, category in definitions.items():
            value = strata[fixture]
            statistic = 'p99' if category == 'uncommon' else 'median'
            if value['category'] != category or value['statistic'] != statistic or 'parity' in value:
                raise ValueError('Invalid stratum')
            if category == 'uncommon' and count(value['samples']) < 1000:
                raise ValueError('Insufficient tail samples')
            rows.append(row(fixture, category, 'warm', statistic + '_latency', value['samples'], value['speedup'],
                value['speedup_ci95'], value['php_ms'], value['rust_ms']))
            if type(value['declined']) is not bool:
                raise ValueError('Invalid decline flag')
            rows[-1].update(candidate_backend='php' if category == 'routed' else 'rust',
                            comparison='same_php_control' if category == 'routed' else 'exact_public_caller',
                            native_declined=value['declined'])
            if fixture in cold:
                value = cold[fixture]
                if type(value['used_rust']) is not bool or type(value['rust_runs']) is not int or not 0 <= value['rust_runs'] <= count(value['samples']):
                    raise ValueError('Invalid cold routing')
                rows.append(row(fixture, category, 'cold', 'median_latency', value['samples'], value['speedup'],
                    value['speedup_ci95'], value['php_ms'], value['rust_ms']))
                rows[-1].update(candidate_backend='rust' if value['used_rust'] else 'php',
                    comparison='exact_public_caller' if value['used_rust'] else 'same_php_control',
                    used_rust=value['used_rust'], rust_runs=value['rust_runs'])
            if fixture in bounded:
                value = bounded[fixture]
                rows.append(row(fixture, category, 'startup-bound', 'p99_latency', strata[fixture]['samples'], value['speedup'], value['speedup_ci95']))
    return rows


def export(kind, runner, root=Path('.'), environ=None):
    env = os.environ if environ is None else environ
    result = dict(schema_version=1, repository=env.get('GITHUB_REPOSITORY', ''), head_sha=env.get('GITHUB_SHA', ''),
        run_id=int(env.get('GITHUB_RUN_ID', '0')), run_attempt=int(env.get('GITHUB_RUN_ATTEMPT', '0')),
        kind=kind, runner=runner, adoption_qualified=False, rows=[], status='missing',
        evidence_type='synthetic_descriptive_comparison', identity_provenance_status='incomplete',
        identity_uncertainty='descriptive_order_statistics' if kind == 'filename-identity' else 'pointwise_paired_bootstrap')
    relative = 'benchmark/summary.json' if kind == 'filename-identity' else 'per-article-obfuscation/report.json'
    try:
        path = root / 'filename-identity-evidence' / relative
        raw = bounded_read(path, LIMIT)
        if len(raw) > LIMIT:
            raise ValueError('Oversized report')
        result['report_sha256'] = hashlib.sha256(raw).hexdigest()
        report = strict_json(raw)
        protocol = report['protocol']
        if protocol['name'] != kind + '-v1':
            raise ValueError('Unexpected protocol')
        result['protocol_sha256'] = hashlib.sha256(json.dumps(protocol, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()).hexdigest()
        status = report['status']
        allowed = ('qualified', 'not_qualified', 'invalid') if kind == 'filename-identity' else ('qualified', 'not-qualified', 'inconclusive', 'invalid')
        if status not in allowed:
            raise ValueError('Unexpected benchmark status')
        result['benchmark_status'] = status
        environment = report['environment']
        executed = environment['source_sha' if kind == 'filename-identity' else 'source_commit']
        if not re.fullmatch('[0-9a-f]{40}', result['head_sha']) or executed != result['head_sha']:
            raise ValueError('Executed source mismatch')
        result['library_sha256'] = {'native': digest(environment['library_sha256'])}
        if kind == 'filename-identity':
            hashes = environment.get('source_sha256', {})
            result['source_sha256'] = {key: digest(hashes[key]) for key in SOURCES}
        if status == 'invalid':
            result['status'] = 'invalid'
        else:
            result['rows'] = measurements(kind, report)
            # Current legacy producers omit measurement-time source/input hashes,
            # generated Unicode/build identity and/or runtime/resource snapshots.
            result['status'] = 'incomplete_provenance'
            try:
                receipts(root, result, report)
                result['status'] = 'recorded'
                for item in result['rows']:
                    item['eligible'] = True
            except FileNotFoundError:
                pass
            validate_public(result)
    except FileNotFoundError:
        pass
    except (OSError, ValueError, TypeError, KeyError, AttributeError, OverflowError, RecursionError):
        result.update(status='invalid', rows=[], identity_provenance_status='invalid')
    return result


def validate_public(summary):
    """Validate the public adapter contract independently at the Pages boundary."""
    kind = summary['kind']
    definitions = FILENAME if kind == 'filename-identity' else ARTICLE
    if (summary.get('evidence_type') != 'synthetic_descriptive_comparison'
            or summary.get('identity_uncertainty') != ('descriptive_order_statistics' if kind == 'filename-identity' else 'pointwise_paired_bootstrap')
            or summary.get('identity_provenance_status') not in ('incomplete', 'invalid', 'verified_current')
            or summary.get('status') not in ('missing', 'invalid', 'incomplete_provenance', 'recorded')):
        raise ValueError('Invalid identity summary status')
    for mapping in ('source_sha256', 'library_sha256'):
        for value in summary.get(mapping, {}).values():
            digest(value)
    status = summary['status']
    benchmark = summary.get('benchmark_status')
    allowed = ('qualified', 'not_qualified', 'invalid') if kind == 'filename-identity' else ('qualified', 'not-qualified', 'inconclusive', 'invalid')
    if benchmark is not None and benchmark not in allowed:
        raise ValueError('Invalid producer status')
    for key in ('report_sha256', 'protocol_sha256', 'identity_runtime_sha256'):
        if key in summary:
            digest(summary[key])
    if set(summary.get('source_sha256', {})) - SOURCE_PATHS - RESOURCE_KEYS or set(summary.get('library_sha256', {})) - {'native'}:
        raise ValueError('Invalid identity source names')
    if status in ('invalid', 'missing'):
        if summary['rows']:
            raise ValueError('Failed identity summary contains measurements')
        return
    if benchmark not in set(allowed) - {'invalid'} or not all(key in summary for key in ('protocol_sha256', 'report_sha256')) or set(summary.get('library_sha256', {})) != {'native'}:
        raise ValueError('Incomplete measured report identity')
    if status == 'recorded':
        runtime = summary.get('runtime_identity', {})
        fields = {'executed_sha', 'rustc', 'cargo', 'architecture', 'runner_image_version', 'build_profile'}
        manifests = summary.get('identity_manifest_sha256', {})
        if (summary['identity_provenance_status'] != 'verified_current' or set(runtime) != fields
                or runtime.get('executed_sha') != summary['head_sha']
                or any(not isinstance(v, str) or not v or len(v) > 4096 for v in runtime.values())
                or set(summary.get('source_sha256', {})) != SOURCE_PATHS | RESOURCE_KEYS
                or set(manifests) != {'before', 'after'} or 'identity_runtime_sha256' not in summary):
            raise ValueError('Incomplete measurement receipt')
        expected_arch = {'aarch64', 'arm64'} if summary['runner'] == 'ubuntu-24.04-arm' else {'x86_64', 'amd64'}
        if runtime['architecture'] not in expected_arch:
            raise ValueError('Runner architecture mismatch')
        for value in manifests.values():
            digest(value)
    elif summary['identity_provenance_status'] != 'incomplete':
        raise ValueError('Invalid incomplete provenance')
    expected = {(name, 'warm', metric) for name in definitions for metric in
        (('median_latency', 'p99_latency') if kind == 'filename-identity' else ('p99_latency' if definitions[name] == 'uncommon' else 'median_latency',))}
    if kind == 'filename-identity':
        expected |= {('release-batch-16', 'release-batch', metric) for metric in ('median_latency', 'p99_latency')}
    else:
        expected |= {(name, 'cold', 'median_latency') for name in definitions if name != 'large-range'}
        expected |= {(name, 'startup-bound', 'p99_latency') for name, category in definitions.items() if category == 'uncommon'}
    keys = []
    for value in summary['rows']:
        required = {'fixture', 'phase', 'metric', 'category', 'samples', 'baseline_over_candidate', 'diagnostic_speedup_interval', 'eligible'}
        latency = {'baseline_latency_ms', 'candidate_latency_ms'} if value.get('phase') != 'startup-bound' else set()
        routing = ({'candidate_backend', 'comparison', 'native_declined'} if value.get('phase') == 'warm' else
            {'candidate_backend', 'comparison', 'used_rust', 'rust_runs'} if value.get('phase') == 'cold' else set()) if kind == 'per-article-obfuscation' else set()
        if set(value) != required | latency | routing:
            raise ValueError('Unexpected identity row fields')
        key = (value['fixture'], value['phase'], value['metric'])
        keys.append(key)
        if key not in expected or value['category'] != definitions.get(value['fixture'], 'common') or type(value['eligible']) is not bool or value['eligible'] != (status == 'recorded'):
            raise ValueError('Invalid identity row')
        count(value['samples'])
        numeric(value['baseline_over_candidate'])
        interval(value['diagnostic_speedup_interval'])
        for field in latency:
            numeric(value[field], zero=True)
        if routing:
            if value['candidate_backend'] not in ('php', 'rust') or value['comparison'] != ('same_php_control' if value['candidate_backend'] == 'php' else 'exact_public_caller'):
                raise ValueError('Invalid routing comparison')
            if value['phase'] == 'warm':
                if type(value['native_declined']) is not bool or (value['category'] == 'routed') != (value['candidate_backend'] == 'php'):
                    raise ValueError('Invalid warm routing')
            elif (type(value['used_rust']) is not bool or type(value['rust_runs']) is not int
                    or not 0 <= value['rust_runs'] <= value['samples']
                    or value['used_rust'] != (value['rust_runs'] * 2 > value['samples'])
                    or value['used_rust'] != (value['candidate_backend'] == 'rust')):
                raise ValueError('Invalid cold routing')
        if value['metric'] == 'p99_latency' and value['samples'] < 1000:
            raise ValueError('Insufficient identity tail observations')
    if set(keys) != expected or len(keys) != len(expected):
        raise ValueError('Incomplete identity matrix')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--kind', choices=KINDS)
    parser.add_argument('--capture', action='store_true')
    parser.add_argument('--library', type=Path)
    parser.add_argument('--runner', choices=('ubuntu-latest', 'ubuntu-24.04-arm'))
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    if args.capture:
        if args.library is None:
            parser.error('--capture requires --library')
        value = capture(Path('.'), args.library)
    else:
        if args.kind is None or args.runner is None:
            parser.error('--kind and --runner are required for export')
        value = export(args.kind, args.runner)
    args.output.write_text(json.dumps(value, indent=2, allow_nan=False) + '\n')


if __name__ == '__main__':
    main()
