#!/usr/bin/env python3
"""Compare frozen PHP 7z listing services with verified matched inputs."""
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import random
import statistics
import subprocess
import sys
import time

BASELINE_COMMIT = '9af5f7c18eac862485ab9c736b65e282d5a3cede'
BASELINE_SERVICE_SHA256 = '83daf245e121d3c7aec60f74b416ddf9b54a6e2d491232a0814d78b487ac2dfc'
PUBLICATION_DEADLINE = 1791996376
SEED = 188820261014
CASES = ['compact', 'basic', 'encrypted-files', 'many', 'lzma2-header']
SHARED_SOURCES = [
    'app/Services/AdditionalProcessing/ArchiveExtractionService.php',
    'app/Services/AdditionalProcessing/Config/ProcessingConfiguration.php',
    'app/Services/AdditionalProcessing/State/ReleaseProcessingContext.php',
    'app/Services/Releases/ReleaseBrowseService.php',
    'app/Models/Release.php',
    'tests/Unit/AdditionalProcessing/CreatesProcessingConfiguration.php',
]
CANDIDATE_SOURCES = SHARED_SOURCES + [
    'app/Services/AdditionalProcessing/NativeSevenZipListing.php',
    'docker/app/nzb-yenc-ffi/Cargo.toml', 'docker/app/nzb-yenc-ffi/Cargo.lock',
    'scripts/benchmark-sevenzip.py', 'scripts/benchmark-sevenzip-worker.php',
]


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def file_identity(path):
    return {'sha256': digest(path), 'resolved_path': str(Path(path).resolve())}


def snapshot(paths):
    return {str(Path(path).absolute()): file_identity(path) for path in paths}


def changed_inputs(frozen):
    changed = []
    for path, identity in frozen.items():
        try:
            current = file_identity(path)
        except OSError as error:
            changed.append({'path': path, 'reason': str(error)})
            continue
        if current != identity:
            changed.append({'path': path, 'before': identity, 'after': current})
    return changed


def vendor_paths(repo):
    root = Path(repo)
    return [root / 'composer.lock', root / 'vendor/autoload.php',
            root / 'vendor/composer/installed.json',
            *sorted((root / 'vendor/composer').glob('autoload*.php'))]


def vendor_identity(repo):
    return {str(path.relative_to(repo)): digest(path) for path in vendor_paths(repo)}


def freeze_inputs(args):
    baseline = Path(args.baseline_repo)
    candidate = Path(args.candidate_repo)
    fixtures = Path(args.fixtures)
    paths = [*(baseline / file for file in SHARED_SOURCES),
             *(candidate / file for file in CANDIDATE_SOURCES),
             *sorted((candidate / 'docker/app/nzb-yenc-ffi/src').rglob('*.rs')),
             *vendor_paths(baseline), *vendor_paths(candidate),
             *sorted(path for path in fixtures.rglob('*') if path.is_file()),
             Path(args.native_library), Path(args.sevenzip)]
    build = candidate / 'docker/app/nzb-yenc-ffi/build.rs'
    if build.is_file():
        paths.append(build)
    companions = {Path(args.sevenzip).absolute().parent / '7z.so',
                  Path(args.sevenzip).resolve().parent / '7z.so'}
    paths.extend(path for path in sorted(companions) if path.is_file())
    return snapshot(paths)


def validate_gate_options(args):
    if args.samples < 5 or args.iterations < 1:
        raise ValueError('Use at least five pairs and one iteration.')
    if args.publication_gate and (args.samples != 31 or args.iterations != 20
                                 or args.seed != SEED or args.deadline_epoch != PUBLICATION_DEADLINE):
        raise ValueError('Publication requires 31 pairs, 20 iterations, the frozen seed, and explicit --deadline-epoch 1791996376.')


def deadline_expired(deadline, now=None):
    return deadline is not None and (time.time() if now is None else now) >= deadline


def run_worker(command, deadline):
    if deadline_expired(deadline):
        return None, {'reason': 'Deadline reached; worker was not launched'}
    timeout = min(120, deadline - time.time()) if deadline is not None else 120
    if timeout <= 0:
        return None, {'reason': 'Deadline reached; worker was not launched'}
    try:
        run = subprocess.run(command, capture_output=True, text=True, timeout=timeout)
    except subprocess.TimeoutExpired as error:
        return None, {'reason': 'Worker timeout', 'stdout': str(error.stdout), 'stderr': str(error.stderr)}
    if run.returncode:
        return None, {'exit': run.returncode, 'stderr': run.stderr, 'stdout': run.stdout}
    try:
        result = json.loads(run.stdout)
    except ValueError:
        return None, {'reason': 'Invalid worker JSON', 'stdout': run.stdout, 'stderr': run.stderr}
    return result, None


def validate_worker(value, mode, case, iterations):
    if not isinstance(value, dict) or value.get('mode') != mode or value.get('fixture') != case or value.get('iterations') != iterations:
        return 'Worker identity or iteration count differs'
    result = value.get('result')
    if not isinstance(result, dict) or result.get('success') is not True or not isinstance(result.get('files'), list) or not isinstance(result.get('encrypted'), bool):
        return 'Worker did not return a successful normalized listing'
    elapsed = value.get('elapsed_ns')
    if not isinstance(elapsed, list) or len(elapsed) != iterations or any(type(item) is not int or item <= 0 for item in elapsed):
        return 'Missing or invalid measured durations'
    if type(value.get('cold_ns')) is not int or value['cold_ns'] <= 0:
        return 'Missing or invalid cold duration'
    checks = value.get('call_checks')
    expected = [('cold', 0)] + [('warmup', index) for index in range(5)] + [('measured', index) for index in range(iterations)]
    if not isinstance(checks, list) or len(checks) != len(expected):
        return 'Missing cold, warmup or measured correctness observations'
    signature = checks[0].get('signature') if isinstance(checks[0], dict) else None
    if not isinstance(signature, str) or len(signature) != 64:
        return 'Missing result signature'
    for check, (phase, index) in zip(checks, expected):
        if not isinstance(check, dict) or (check.get('phase'), check.get('index')) != (phase, index):
            return 'Incorrect call sequence'
        if check.get('valid') is not True or check.get('matches_first') is not True or check.get('signature') != signature:
            return f'{phase} call {index} failed the correctness contract: {check.get("reason")}'
    if value.get('consistent') is not True:
        return 'Worker reported inconsistent results'
    return None


def bootstrap_stratified(groups, seed, count=10000):
    rng = random.Random(seed)
    logs = [[math.log(value) for value in group] for group in groups]
    estimates = sorted(math.exp(statistics.mean(
        statistics.mean(rng.choice(group) for _ in group) for group in logs
    )) for _ in range(count))
    return [estimates[int(count * 0.025)], estimates[int(count * 0.975)]]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ['baseline-repo', 'candidate-repo', 'native-library', 'sevenzip', 'fixtures', 'output']:
        parser.add_argument('--' + name, required=True)
    parser.add_argument('--samples', type=int, default=31)
    parser.add_argument('--iterations', type=int, default=20)
    parser.add_argument('--seed', type=int, default=SEED)
    parser.add_argument('--publication-gate', action='store_true')
    parser.add_argument('--deadline-epoch', type=int)
    parser.add_argument('--runtime-image-id', help='Immutable image identity recorded by the disposable-runtime executor')
    args = parser.parse_args()
    try:
        validate_gate_options(args)
    except ValueError as error:
        parser.error(str(error))
    output = Path(args.output)
    output.mkdir(parents=True, exist_ok=False)
    worker = Path(args.candidate_repo) / 'scripts/benchmark-sevenzip-worker.php'
    fixtures = Path(args.fixtures)
    manifest = json.loads((fixtures / 'manifest.json').read_text())
    frozen = freeze_inputs(args)
    baseline_hash = digest(Path(args.baseline_repo) / SHARED_SOURCES[0])
    baseline_vendor, candidate_vendor = vendor_identity(args.baseline_repo), vendor_identity(args.candidate_repo)
    failures = []
    if baseline_hash != BASELINE_SERVICE_SHA256:
        failures.append({'reason': 'Baseline service differs from the exact PR1888 commit', 'expected': BASELINE_SERVICE_SHA256, 'actual': baseline_hash})
    if baseline_vendor != candidate_vendor:
        failures.append({'reason': 'Baseline/candidate vendor or lockfile identities differ', 'baseline': baseline_vendor, 'candidate': candidate_vendor})
    fixture_inventory = sorted(str(path.relative_to(fixtures)) for path in fixtures.rglob('*') if path.is_file())
    metadata = {
        'protocol_version': 2, 'time_ns': time.time_ns(), 'publication_gate': args.publication_gate,
        'deadline_epoch': args.deadline_epoch, 'baseline_commit': BASELINE_COMMIT,
        'cases': CASES, 'samples': args.samples, 'iterations': args.iterations,
        'seed': args.seed, 'warmups': 5, 'additional_cold_calls': 1,
        'scope': 'Warmed header listing service, excluding network, PHP startup and database finalization; first-call binding cost reported separately',
        'runtime': {'platform': sys.platform, 'architecture': os.uname().machine, 'image_id': args.runtime_image_id,
                    'php': subprocess.check_output(['php', '-v'], text=True).splitlines()[0],
                    'sevenzip': subprocess.run([args.sevenzip], capture_output=True, text=True).stdout.splitlines()[:4]},
        'frozen_inputs': frozen, 'fixture_inventory': fixture_inventory,
        'baseline_vendor_identity': baseline_vendor, 'candidate_vendor_identity': candidate_vendor,
        'fixture_manifest': manifest,
    }
    (output / 'manifest.json').write_text(json.dumps(metadata, indent=2) + '\n')
    rng = random.Random(args.seed)
    summaries, ratio_groups = [], []
    stopped = bool(failures)
    with (output / 'observations.jsonl').open('w') as stream:
        for case in CASES:
            ratios, baseline_us, native_us, baseline_cold, native_cold = [], [], [], [], []
            expected = None
            for pair in range(args.samples):
                if stopped or deadline_expired(args.deadline_epoch):
                    if not stopped:
                        failures.append({'fixture': case, 'pair': pair, 'reason': 'Deadline reached; remaining workers were not launched'})
                    stopped = True
                    break
                order = ['baseline', 'native']
                rng.shuffle(order)
                results, errors = {}, {}
                for mode in order:
                    repo = args.baseline_repo if mode == 'baseline' else args.candidate_repo
                    command = ['php', '-d', 'ffi.enable=true', str(worker), repo, mode,
                               args.fixtures, case, str(args.iterations), args.native_library, args.sevenzip]
                    value, error = run_worker(command, args.deadline_epoch)
                    if error is None:
                        reason = validate_worker(value, mode, case, args.iterations)
                        if reason is not None:
                            error = {'reason': reason, 'worker_output': value}
                    if error is not None:
                        errors[mode] = error
                    else:
                        results[mode] = value
                valid = not errors and len(results) == 2 and results['baseline']['result'] == results['native']['result']
                if valid and expected is None:
                    expected = results['baseline']['result']
                    (output / f'{case}-expected.json').write_text(json.dumps(expected, indent=2) + '\n')
                valid = valid and expected == results.get('native', {}).get('result')
                row = {'fixture': case, 'pair': pair, 'order': order, 'eligible': valid, 'errors': errors, 'results': results}
                stream.write(json.dumps(row) + '\n')
                stream.flush()
                if not valid:
                    failures.append({'fixture': case, 'pair': pair, 'reason': errors or 'Correctness/parity gate failed'})
                    continue
                left = statistics.mean(results['baseline']['elapsed_ns']) / 1000
                right = statistics.mean(results['native']['elapsed_ns']) / 1000
                baseline_us.append(left)
                native_us.append(right)
                baseline_cold.append(results['baseline']['cold_ns'] / 1000)
                native_cold.append(results['native']['cold_ns'] / 1000)
                ratios.append(left / right)
            summary = {'fixture': case, 'eligible_pairs': len(ratios), 'invalid_or_unexecuted_pairs': args.samples - len(ratios)}
            if ratios:
                summary.update({'baseline_median_us': statistics.median(baseline_us), 'rust_median_us': statistics.median(native_us),
                                'baseline_cold_median_us': statistics.median(baseline_cold), 'rust_cold_median_us': statistics.median(native_cold),
                                'paired_median_speedup': statistics.median(ratios),
                                'paired_geomean_speedup': math.exp(statistics.mean(map(math.log, ratios))),
                                'bootstrap95_geomean_speedup': bootstrap_stratified([ratios], args.seed + len(summaries)),
                                'min_speedup': min(ratios), 'max_speedup': max(ratios)})
            summaries.append(summary)
            ratio_groups.append(ratios)
            print(json.dumps(summary), flush=True)
    changes = changed_inputs(frozen)
    try:
        current_paths = set(freeze_inputs(args))
        original_paths = set(frozen)
        if current_paths != original_paths:
            changes.append({'reason': 'Frozen input inventory changed',
                            'added': sorted(current_paths - original_paths),
                            'removed': sorted(original_paths - current_paths)})
    except OSError as error:
        changes.append({'reason': 'Unable to recheck frozen input inventory', 'error': str(error)})
    inventory_after = sorted(str(path.relative_to(fixtures)) for path in fixtures.rglob('*') if path.is_file())
    if inventory_after != fixture_inventory:
        changes.append({'reason': 'Fixture inventory changed', 'before': fixture_inventory, 'after': inventory_after})
    if changes:
        failures.append({'reason': 'Frozen inputs changed during measurement', 'changes': changes})
    all_ratios = [value for group in ratio_groups for value in group]
    complete = all(len(group) == args.samples for group in ratio_groups)
    interval = bootstrap_stratified(ratio_groups, args.seed + 99) if complete else None
    finished = time.time()
    if deadline_expired(args.deadline_epoch, finished):
        failures.append({'reason': 'Run completed after its deadline', 'finished_epoch': finished})
    passed = not failures and complete and all(summary['paired_median_speedup'] >= 2 for summary in summaries) and interval[0] >= 2
    final = {'decision': 'pass' if passed else 'fail', 'publication_eligible': args.publication_gate and passed,
             'finished_epoch': finished, 'minimum_required_speedup': 2, 'results': summaries, 'failures': failures,
             'aggregate_geomean_speedup': math.exp(statistics.mean(map(math.log, all_ratios))) if all_ratios else None,
             'aggregate_stratified_bootstrap95': interval,
             'limitations': ['One Linux runtime; no production throughput claim',
                             'Speed gate covers warmed header listing; cold service-call binding costs are separate',
                             'Known-invalid baseline capped/password cases require separate correctness regression evidence']}
    (output / 'summary.json').write_text(json.dumps(final, indent=2) + '\n')
    print(json.dumps(final), flush=True)
    return 0 if passed else 1


if __name__ == '__main__':
    raise SystemExit(main())
