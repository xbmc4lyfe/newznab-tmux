#!/usr/bin/env python3
"""Export fixed public caller metrics; raw evidence never leaves the allowlist."""

import argparse
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import statistics
from functools import lru_cache

CALLER_STRATA = {
    "sample-planning": {"small-dispatch": "dispatch_control", "boundary-1024": "common", "large-16384": "common",
                        "duplicates-inferred": "uncommon", "tight-budget": "uncommon", "unsupported-coercion": "fallback_control"},
    "vendor-archive": {name: 'common' for name in ('rar4-stored', 'rar5-mixed', 'zip-stored', 'seven-stored')}
        | {name: 'uncommon' for name in ('rar4-solid', 'rar5-encrypted-headers', 'zip-aes', 'seven-encrypted-headers')},
    "archive-parsing": {"public-rename-index": "common", "synthetic-128": "common", "synthetic-512": "uncommon", "synthetic-1024": "uncommon",
                        **dict.fromkeys(("rar4", "rar5", "zip", "seven-zip", "iso", "seven-zip-truncated", "seven-zip-corrupt",
                                        "seven-ranges-complete", "seven-ranges-split", "seven-ranges-missing", "seven-ranges-conflicting",
                                        "par2-ranges-complete", "par2-ranges-split", "par2-ranges-missing", "par2-ranges-conflicting", "par2-ranges-checksum"), "diagnostic")},
}


# Fixed declarations mirror the fixture contract, never arbitrary raw labels.
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
CALLER_STRATA.update({kind: {name: row['category'] for name, row in strata.items()} for kind, strata in REMAINING_STRATA.items()})
REMAINING_INSTRUMENTATION = {"scripts/benchmark-remaining-modules.php", "scripts/benchmark-sample-planning.php",
    "scripts/benchmark-article-processing.php", "scripts/benchmark-yenc.php", "tests/Fixtures/SamplePlanningBenchmarkFixtures.php",
    "tests/Fixtures/RemainingMetadataBenchmarkFixtures.php", "tests/Fixtures/RemainingArchiveBenchmarkFixtures.php"}
REMAINING_WRAPPER = "app/Services/Releases/Fingerprint/NativeMetadataParser.php"
REMAINING_SOURCES = {REMAINING_WRAPPER, "composer.json", "composer.lock", "docker/app/nzb-yenc-ffi/Cargo.toml",
    "docker/app/nzb-yenc-ffi/Cargo.lock", "docker/app/nzb-yenc-ffi/src/lib.rs"} | {
    "docker/app/nzb-yenc-ffi/src/" + module + ".rs" for modules in REMAINING_MODULES.values() for module in modules}


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
VENDOR_SOURCES = {'composer.lock', 'vendor/composer/installed.json', 'vendor/dariusiii/rarinfo/composer.json',
    'vendor/dariusiii/rarinfo/ArchiveReader.php', 'vendor/dariusiii/rarinfo/RarInfo.php',
    'vendor/dariusiii/rarinfo/ZipInfo.php', 'vendor/dariusiii/rarinfo/SzipInfo.php',
    'scripts/benchmark-vendor-archive.php', 'tests/Fixtures/VendorArchiveBenchmarkFixtures.php'} | {path for path, _ in VENDOR_INPUTS.values()}


SOURCE_PATHS = {
    "composer.json", "app/Services/Releases/Fingerprint/Par2MetadataParser.php",
    "app/Services/Releases/Verification/SpotCheckPlanner.php", "app/Services/Releases/Verification/NativeSpotCheckPlanner.php",
    "scripts/benchmark-sample-planning.php", "scripts/benchmark-archive-parsing.php",
    "tests/Fixtures/SamplePlanningBenchmarkFixtures.php", "tests/Fixtures/ArchiveParsingBenchmarkFixtures.php",
    "tests/Fixtures/par2/rename-index.par2", "docker/app/nzb-yenc-ffi/src/par2.rs", "docker/app/nzb-yenc-ffi/src/formats.rs",
    "docker/app/nzb-yenc-ffi/src/seven_zip_ranges.rs", "docker/app/nzb-yenc-ffi/src/par2_source_ranges.rs", "docker/app/nzb-yenc-ffi/src/sample_plan.rs",
    "scripts/benchmark-yenc.php", "scripts/build-rapidyenc.sh", "composer.lock",
    "scripts/benchmark-article-processing.php", "scripts/benchmark-release-names.php",
    "app/Services/YencService.php", "app/Services/NNTP/NNTPService.php",
    "app/Services/Releases/Fingerprint/NativeMetadataParser.php",
    "app/Services/Releases/Fingerprint/NzbFingerprintAnalyzer.php",
    "app/Support/AdminReleaseNames.php", "app/Support/ReleaseNameDisplay.php",
    "tests/Fixtures/YencArticles.php", "tests/Support/YencNntpHarness.php",
    "docker/app/nzb-yenc-ffi/Cargo.toml", "docker/app/nzb-yenc-ffi/Cargo.lock",
    "docker/app/nzb-yenc-ffi/src/lib.rs", "docker/app/nzb-yenc-ffi/src/release_names.rs",
    "docker/app/php/release-presentation-preload.php", "vendor/composer/installed.json",
}

SOURCE_PATHS |= VENDOR_SOURCES | REMAINING_SOURCES | REMAINING_INSTRUMENTATION


def number(value):
    try:
        return type(value) in (int, float) and math.isfinite(value)
    except OverflowError:
        return False


def reject_constant(value):
    raise ValueError("Non-finite JSON constant")


def finite_float(value):
    result = float(value)
    if not math.isfinite(result):
        raise ValueError("Non-finite JSON number")
    return result


def count(value):
    return type(value) is int and value >= 0


def digest(value):
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value) is not None


def valid_vendor_setup(protocol, records):
    declarations = protocol.get('strata')
    if not isinstance(declarations, dict) or set(declarations) != set(VENDOR_INPUTS):
        return False
    for name, (path, expected_hash) in VENDOR_INPUTS.items():
        row = declarations[name]
        if (not isinstance(row, dict) or row.get('category') != CALLER_STRATA['vendor-archive'][name]
                or row.get('path') != path or row.get('sha256') != expected_hash or row.get('paired') is not True):
            return False
    setups = [row for row in records if row.get('type') == 'setup']
    if len(setups) != 1:
        return False
    hashes, receipt = setups[0].get('build_source_identities'), setups[0].get('vendor_receipt')
    if not isinstance(hashes, dict) or not all(digest(hashes.get(key)) for key in VENDOR_SOURCES) or not isinstance(receipt, dict):
        return False
    files = receipt.get('files')
    expected_files = {key: hashes[key] for key in VENDOR_SOURCES if key.startswith('vendor/') or key == 'composer.lock'}
    return (all(hashes[path] == expected_hash for path, expected_hash in VENDOR_INPUTS.values())
            and receipt.get('package') == 'dariusiii/rarinfo' and receipt.get('version') in ('3.1', 'v3.1')
            and receipt.get('license') == ['BSD-3-Clause']
            and receipt.get('source_reference') == '2cd3220b76d807de1798a98d743f20224f30d63a'
            and isinstance(files, dict) and files == expected_files)


def valid_vendor_quantiles(source, samples):
    alpha = 0.05 / 32
    if not number(source.get('marginal_alpha')) or not math.isclose(source['marginal_alpha'], alpha, rel_tol=1e-12):
        return False
    quantiles = [source.get(key) for key in ('php', 'native')]
    if not all(isinstance(value, dict) and value.get('samples') == samples
               and number(value.get('confidence')) and math.isclose(value['confidence'], 1 - alpha, rel_tol=1e-12)
               and number(value.get('estimate')) and value['estimate'] > 0 for value in quantiles):
        return False
    for value in quantiles:
        interval = value.get('interval')
        if (not isinstance(interval, list) or len(interval) != 2
                or any(bound is not None and (not number(bound) or bound <= 0) for bound in interval)
                or interval[0] is not None and interval[0] > value['estimate']
                or interval[1] is not None and interval[1] < value['estimate']):
            return False
    baseline, candidate = (value['interval'] for value in quantiles)
    expected = [baseline[0] / candidate[1] if baseline[0] is not None and candidate[1] is not None else None,
                baseline[1] / candidate[0] if baseline[1] is not None and candidate[0] is not None else None]
    interval = source.get('simultaneous_95_percent_speedup_interval')
    return (isinstance(interval, list) and len(interval) == 2
            and all(actual is None if required is None else number(actual) and math.isclose(actual, required, rel_tol=1e-12)
                    for actual, required in zip(interval, expected)))


def disqualify_rows(rows):
    for row in rows:
        row['eligible'] = False
        if 'qualification_gate_passed' in row:
            row['qualification_gate_passed'] = False



@lru_cache(maxsize=128)
def remaining_ranks(samples, quantile):
    """Exact binomial order-statistic bounds; stable relative weights at the mode."""
    failure = 1 - quantile
    mode = math.floor((samples + 1) * failure)
    weights = [0.] * (samples + 1)
    weights[mode] = 1.
    for k in range(mode, 0, -1):
        weights[k - 1] = weights[k] * k / (samples - k + 1) * quantile / failure
    for k in range(mode, samples):
        weights[k + 1] = weights[k] * (samples - k) / (k + 1) * failure / quantile
    total, cumulative, lower, upper = sum(weights), 0., None, samples
    for k, weight in enumerate(weights):
        cumulative += weight / total
        if lower is None and cumulative >= .05 / 48 / 2:
            lower = k
        if cumulative >= 1 - .05 / 48 / 2:
            upper = k
            break
    return [samples - upper, samples - lower + 1]


def remaining_quantile(values, quantile):
    values = sorted(values)
    n = len(values)
    ranks = remaining_ranks(n, quantile)
    return dict(estimate=statistics.median(values) if quantile == .5 else values[math.ceil(quantile * n) - 1],
        interval=[values[ranks[0] - 1] if ranks[0] >= 1 else None,
                  values[ranks[1] - 1] if ranks[1] <= n else None],
        ranks=ranks, samples=n, confidence=1 - .05 / 48)


def same_numbers(actual, expected):
    if expected is None:
        return actual is None
    if isinstance(expected, dict):
        return isinstance(actual, dict) and set(actual) == set(expected) and all(same_numbers(actual[k], v) for k, v in expected.items())
    if isinstance(expected, list):
        return isinstance(actual, list) and len(actual) == len(expected) and all(same_numbers(a, b) for a, b in zip(actual, expected))
    return number(actual) and math.isclose(actual, expected, rel_tol=1e-12)


def remaining_summary(kind, records):
    expected = REMAINING_STRATA[kind]
    protocols = [r for r in records if r.get('type') == 'protocol']
    setups = [r for r in records if r.get('type') == 'setup']
    terminals = [r for r in records if r.get('type') == 'summary']
    protocol = protocols[0] if len(protocols) == 1 else {}
    setup = setups[0] if len(setups) == 1 else {}
    terminal = terminals[0] if len(terminals) == 1 and records[-1] is terminals[0] else {}
    samples = protocol.get('samples_per_stratum')
    mode = protocol.get('mode')
    valid = (count(samples) and 1 <= samples <= 10000 and mode in ('screen', 'qualification')
             and (mode != 'qualification' or samples >= 1000))
    invalid = (not valid or protocol.get('protocol') != 'remaining-modules-v1' or protocol.get('family') != kind
        or protocol.get('strata') != expected or protocol.get('baseline_kind') != 'independent_php_reference_projection'
        or type(protocol.get('confidence_family_size')) is not int or protocol['confidence_family_size'] != 48)
    instrumentation = protocol.get('instrumentation')
    invalid |= (not isinstance(instrumentation, dict) or set(instrumentation) != REMAINING_INSTRUMENTATION
                or not all(digest(v) for v in instrumentation.values()))
    sources = setup.get('build_source_identities', {})
    snapshot = setup.get('source', {})
    library = setup.get('library', {})
    invalid |= (not isinstance(sources, dict) or not all(digest(sources.get(p)) for p in REMAINING_SOURCES)
        or not isinstance(snapshot, dict) or not isinstance(snapshot.get('files'), dict)
        or snapshot.get('files', {}).get(REMAINING_WRAPPER) != sources.get(REMAINING_WRAPPER)
        or not isinstance(library, dict) or not digest(library.get('sha256')))
    observed, pairs, fixtures, measured = {}, {}, {}, {}
    for r in records:
        typ = r.get('type')
        if typ not in ('observation', 'pair', 'fixture', 'stratum'):
            if typ not in ('protocol', 'setup', 'summary'):
                invalid = True
            continue
        name = r.get('stratum')
        if not isinstance(name, str) or name not in expected:
            invalid = True
            continue
        definition = expected[name]
        if typ == 'fixture':
            invalid |= name in fixtures or r.get('module') != definition['module'] or not digest(r.get('sha256'))
            fixtures[name] = r.get('sha256')
            continue
        if typ == 'stratum':
            invalid |= name in measured or r.get('module') != definition['module'] or r.get('category') != definition['category']
            measured[name] = r.get('decision')
            continue
        pair = r.get('pair')
        if not valid or not count(pair) or pair >= samples:
            invalid = True
            continue
        key = (name, pair)
        if typ == 'pair':
            invalid |= (key in pairs or r.get('eligible') is not True or r.get('exact_serialized_projection_parity') is not True
                or r.get('fixture_sha256') != fixtures.get(name) or r.get('order') not in (['php', 'native'], ['native', 'php']))
            pairs[key] = r
            continue
        backend = r.get('backend')
        if backend not in ('php', 'native') or (name, pair, backend) in observed:
            invalid = True
            continue
        child = r.get('child')
        result = child.get('result') if isinstance(child, dict) else None
        good = (r.get('eligible') is True and isinstance(child, dict) and child.get('status') == 'ok'
                and child.get('stdout_truncated_for_json') is False and isinstance(result, dict))
        if good:
            runtime = result.get('runtime_sources', {})
            files = runtime.get('files') if isinstance(runtime, dict) else None
            projection, full = result.get('projection', {}), result.get('full', {})
            good = (result.get('status') == 'ok' and result.get('backend') == backend
                and result.get('fixture_sha256') == fixtures.get(name) and result.get('instrumentation') == instrumentation
                and isinstance(files, dict) and bool(files) and runtime.get('root') == snapshot.get('root')
                and all(digest(v) and snapshot.get('files', {}).get(p) == v for p, v in files.items())
                and files.get(REMAINING_WRAPPER) == sources.get(REMAINING_WRAPPER)
                and runtime.get('classes', {}).get('App\\Services\\Releases\\Fingerprint\\NativeMetadataParser') == REMAINING_WRAPPER
                and isinstance(projection, dict) and digest(projection.get('sha256'))
                and isinstance(full, dict) and digest(full.get('sha256'))
                and (backend != 'native' or result.get('library_sha256') == library.get('sha256')))
            phases = result.get('observations')
            good &= isinstance(phases, dict) and set(phases) == {'cold', 'warmup', 'warm'}
            if good:
                engine = 'nntmux-rust-' + definition['module'].replace('_', '-') if kind == 'metadata-modules' else 'nntmux-rust-ffi'
                for phase in phases.values():
                    good &= (isinstance(phase, dict) and phase.get('correct') is True and number(phase.get('elapsed_ms'))
                        and phase['elapsed_ms'] > 0 and phase.get('projection_sha256') == projection['sha256']
                        and (phase.get('selected_library_cached') is True and phase.get('parser_engine') == engine if backend == 'native'
                             else phase.get('selected_library_cached') is False and phase.get('parser_engine') is None))
        invalid |= not good
        observed[name, pair, backend] = result if good else None
    rows = []
    for name, definition in expected.items():
        category = definition['category']
        decision = measured.get(name)
        if decision is None:
            continue
        if not isinstance(decision, dict) or set(decision) != {'cold', 'warm'}:
            invalid = True
            continue
        pair_count = sum((name, i) in pairs for i in range(samples)) if valid else 0
        complete_observations = valid and all(observed.get((name, i, b)) for i in range(samples) for b in ('php', 'native'))
        parity = complete_observations and all(observed[name,i,'php']['projection']['sha256'] == observed[name,i,'native']['projection']['sha256'] for i in range(samples))
        for phase in ('cold', 'warm'):
            source = decision[phase]
            row = dict(fixture=name, module=definition['module'], phase=phase, candidate_backend='rust', samples=pair_count,
                category=category, p99_sample_sufficient=pair_count >= 1000, comparison='shared_independent_php_reference_projection',
                confidence_family_size=48, marginal_alpha=.05/48, qualification_gate_passed=False, eligible=False)
            good = complete_observations and parity and pair_count == samples and isinstance(source, dict)
            if good:
                quantiles = [remaining_quantile([observed[name,i,b]['observations'][phase]['elapsed_ms'] for i in range(samples)], .99 if category == 'uncommon' else .5) for b in ('php','native')]
                baseline, native = (q['interval'] for q in quantiles)
                interval = [baseline[0] / native[1] if baseline[0] is not None and native[1] is not None else None,
                            baseline[1] / native[0] if baseline[1] is not None and native[0] is not None else None]
                qualifies = mode == 'qualification' and samples >= 1000 and interval[0] is not None and interval[0] >= 1.5
                status = 'qualified_projection' if qualifies else 'threshold_not_met' if interval[1] is not None and interval[1] < 1.5 else 'inconclusive'
                metric = 'p99_latency' if category == 'uncommon' else 'median_latency'
                good &= (same_numbers(source.get('php'), quantiles[0]) and same_numbers(source.get('native'), quantiles[1])
                    and same_numbers(source.get('php_over_native'), quantiles[0]['estimate'] / quantiles[1]['estimate'])
                    and same_numbers(source.get('simultaneous_95_percent_speedup_interval'), interval)
                    and same_numbers(source.get('marginal_alpha'), .05/48) and source.get('status') == status
                    and source.get('eligible_pairs') == samples and type(source.get('eligible_pairs')) is int
                    and source.get('invalid_pairs') == 0 and type(source.get('invalid_pairs')) is int
                    and source.get('metric') == metric and source.get('qualifies') is qualifies
                    and source.get('evidence') == ('screen_only_no_adoption' if mode == 'screen' else 'reference_projection_only_no_adoption'))
                unavailable = category == 'uncommon' and samples < 1000
                row.update(metric='p99_unavailable' if unavailable else metric, speedup_interval=[None,None] if unavailable else interval,
                    baseline_latency_interval_ms=None if unavailable else baseline, candidate_latency_interval_ms=None if unavailable else native,
                    correctness_observations=2*samples, native_proof_observations=samples, qualification_gate_passed=bool(good and qualifies))
                if not unavailable:
                    row.update(baseline_over_candidate=quantiles[0]['estimate']/quantiles[1]['estimate'],
                        baseline_latency_ms=quantiles[0]['estimate'], candidate_latency_ms=quantiles[1]['estimate'])
            else:
                row['comparison'] = 'invalid'
            row['eligible'] = bool(good)
            invalid |= not good
            rows.append(row)
    complete = (valid and len(rows) == 24 and bool(terminal) and set(fixtures) == set(expected)
        and len(pairs) == samples * 12 and len(observed) == samples * 24)
    if terminal:
        invalid |= (terminal.get('status') != 'measured' or terminal.get('identities_stable') is not True
            or terminal.get('invalid_pairs') != 0 or type(terminal.get('invalid_pairs')) is not int
            or terminal.get('strata') != measured or set(measured) != set(expected)
            or terminal.get('baseline_kind') != 'independent_php_reference_projection'
            or terminal.get('adoption_qualified') is not False or terminal.get('acceptance_criteria_met') is not False
            or terminal.get('performance_gates_passed') is not (complete and all(r['qualification_gate_passed'] for r in rows)))
    if invalid or not complete:
        disqualify_rows(rows)
    return dict(rows=rows, status='invalid' if invalid else 'measured' if complete else 'partial',
        completeness=dict(expected_rows=24, observed_rows=len(rows), complete=bool(complete)),
        evidence_type='shared_independent_php_reference_projection', baseline_kind='independent_php_reference_projection',
        benchmark_mode=mode if mode in ('screen','qualification') else 'unknown',
        protocol_sha256=hashlib.sha256(json.dumps(protocol, sort_keys=True, separators=(',', ':')).encode()).hexdigest(),
        source_sha256={p: value for p, value in (sources.items() if isinstance(sources, dict) else []) if p in SOURCE_PATHS and digest(value)}
            | {p: value for p, value in (instrumentation.items() if isinstance(instrumentation, dict) else []) if p in REMAINING_INSTRUMENTATION and digest(value)},
        library_sha256={'native': library['sha256']} if isinstance(library, dict) and digest(library.get('sha256')) else {})

def caller_summary(kind, records):
    """Fixed public metric projection of the PHP caller harnesses."""
    if kind in REMAINING_STRATA:
        return remaining_summary(kind, records)
    expected = CALLER_STRATA[kind]
    protocols = [row for row in records if row.get("type") == "protocol"]
    terminal = records[-1] if records and records[-1].get("type") == "summary" else {}
    invalid = len(protocols) > 1
    protocol = protocols[0] if len(protocols) == 1 else {}
    samples = protocol.get("samples_per_stratum")
    valid_protocol = (protocol.get("protocol") == kind + "-v1" and count(samples) and 1 <= samples <= 10000
                      and protocol.get("mode") in ("screen", "qualification")
                      and (protocol["mode"] != "qualification" or samples >= 1000))
    invalid |= bool(protocol) and not valid_protocol
    if kind == 'vendor-archive':
        invalid |= not valid_vendor_setup(protocol, records)
    measured, pairs = {}, {key: set() for key in expected}
    for record in records:
        if record.get("type") not in ("stratum", "pair"):
            continue
        name = record.get("stratum")
        if not isinstance(name, str) or name not in expected:
            invalid = True
            continue
        if record["type"] == "stratum":
            invalid |= name in measured or record.get("category") != expected[name]
            measured[name] = record.get("decision")
        else:
            pair = record.get("pair")
            size = min(samples, 31) if valid_protocol and expected[name] == "diagnostic" else samples
            if not valid_protocol or not count(pair) or pair >= size or pair in pairs[name]:
                invalid = True
                continue
            pairs[name].add(pair)
            invalid |= record.get("eligible") is not True
            if kind == "sample-planning":
                invalid |= record.get("exact_serialized_parity") is not True
                invalid |= record.get("native_admission_expected") is not (not expected[name].endswith("control"))
            else:
                paired = expected[name] != "diagnostic"
                invalid |= record.get("paired") is not paired
                invalid |= record.get("shared_projection_parity") is not (True if paired else None)
    rows = []
    for name, category in expected.items():
        decision = measured.get(name)
        if decision is None:
            continue
        if not isinstance(decision, dict) or set(decision) != {"cold", "warm"}:
            invalid = True
            continue
        size = min(samples, 31) if valid_protocol and category == "diagnostic" else samples
        for phase in ("cold", "warm"):
            source = decision[phase]
            row = dict(fixture=name, phase=phase, candidate_backend="rust", samples=len(pairs[name]),
                       category=category, p99_sample_sufficient=len(pairs[name]) >= 1000)
            good = valid_protocol and len(pairs[name]) == size and isinstance(source, dict)
            if not isinstance(source, dict):
                row.update(eligible=False, comparison="invalid")
                rows.append(row)
                invalid = True
                continue
            if category == "diagnostic":
                row["comparison"] = "native_only_diagnostic"
                values = source.get("native_ms")
                good &= (source.get("status") == "diagnostic_only" and source.get("qualifies") is False
                         and source.get("eligible_observations") == size and source.get("invalid_observations") == 0
                         and isinstance(values, list) and len(values) == size
                         and all(number(value) and value > 0 for value in values))
                if good:
                    row["native_median_ms"] = statistics.median(values)
            elif category.endswith("control"):
                row.update(comparison="same_php_control", candidate_backend="php", metric="not_applicable")
                good &= (source.get("status") == "not_applicable" and source.get("qualifies") is False
                         and source.get("eligible_pairs") == size and source.get("invalid_pairs") == 0)
                for source_key, public_key in (("php_median_ms", "baseline_latency_ms"), ("candidate_fallback_median_ms", "candidate_latency_ms")):
                    if number(source.get(source_key)) and source[source_key] > 0:
                        row[public_key] = source[source_key]
                    else:
                        good = False
            else:
                row["comparison"] = "shared_vendor_header_projection" if kind == "vendor-archive" else "shared_par2_projection" if kind == "archive-parsing" else "exact_public_caller"
                row["metric"] = "p99_latency" if category == "uncommon" else "median_latency"
                good &= (source.get("status") in ("qualified", "inconclusive") and source.get("metric") == row["metric"]
                         and source.get("eligible_pairs") == size and source.get("invalid_pairs") == 0
                         and type(source.get("qualifies")) is bool)
                ratio = source.get("php_over_native")
                interval = source.get("simultaneous_95_percent_speedup_interval")
                good &= number(ratio) and ratio > 0
                good &= (isinstance(interval, list) and len(interval) == 2
                         and all(value is None or number(value) and value > 0 for value in interval)
                         and (None in interval or interval[0] <= interval[1]))
                quantiles = [source.get(key) for key in ("php", "native")]
                good &= all(isinstance(value, dict) and value.get("samples") == size
                            and number(value.get("estimate")) and value["estimate"] > 0 for value in quantiles)
                if kind == 'vendor-archive':
                    good &= valid_vendor_quantiles(source, size)
                    row.update(confidence_family_size=32, marginal_alpha=0.05 / 32)
                if good:
                    row["speedup_interval"] = interval
                    if kind == 'vendor-archive':
                        row.update(baseline_latency_interval_ms=None if category == 'uncommon' and size < 1000 else quantiles[0]['interval'],
                                   candidate_latency_interval_ms=None if category == 'uncommon' and size < 1000 else quantiles[1]['interval'])
                    if category == "uncommon" and size < 1000:
                        row["metric"] = "p99_unavailable"
                        row["speedup_interval"] = [None, None]
                    else:
                        row.update(baseline_over_candidate=ratio, baseline_latency_ms=quantiles[0]["estimate"],
                                   candidate_latency_ms=quantiles[1]["estimate"])
                    good &= math.isclose(ratio, quantiles[0]["estimate"] / quantiles[1]["estimate"], rel_tol=1e-12)
                    required = protocol["mode"] == "qualification" and size >= 1000 and interval[0] is not None and interval[0] >= 1.5
                    good &= source["qualifies"] is required
                    row["qualification_gate_passed"] = bool(required and good)
            row["eligible"] = bool(good)
            invalid |= not good
            rows.append(row)
    complete = len(rows) == 2 * len(expected) and bool(terminal) and valid_protocol
    if terminal:
        invalid |= terminal.get("identities_stable") is not True
        invalid |= terminal.get("status") not in (("qualified", "inconclusive") if kind == "sample-planning" else ("partial_semantics_only",))
        invalid |= terminal.get("invalid_pairs" if kind == "sample-planning" else "invalid_observations_or_pairs") != 0
        if kind == 'vendor-archive':
            invalid |= terminal.get('acceptance_criteria_met') is not False
            expected_gate = complete and all(row.get('qualification_gate_passed') is True for row in rows)
            invalid |= terminal.get('vendor_archive_shared_projection_qualified') is not expected_gate
        declared = terminal.get("strata")
        invalid |= (not isinstance(declared, dict) or set(declared) != set(expected)
                    or any(declared.get(name) != decision for name, decision in measured.items()))
    if invalid or not complete:
        disqualify_rows(rows)
    sources, libraries = {}, {}
    for record in records:
        hashes = record.get("build_source_identities")
        if isinstance(hashes, dict):
            sources.update({key: hashes[key] for key in SOURCE_PATHS if digest(hashes.get(key))})
        library = record.get("library")
        if isinstance(library, dict) and digest(library.get("sha256")):
            libraries["native"] = library["sha256"]
    return dict(rows=rows, status="invalid" if invalid else "measured" if complete else "partial",
                completeness=dict(expected_rows=2 * len(expected), observed_rows=len(rows), complete=complete),
                evidence_type="shared_vendor_archive_header_projection" if kind == "vendor-archive" else "shared_par2_projection_and_native_diagnostics" if kind == "archive-parsing" else "paired_public_caller_quantiles",
                protocol_sha256=hashlib.sha256(json.dumps(protocol, sort_keys=True, separators=(",", ":")).encode()).hexdigest(),
                source_sha256=sources, library_sha256=libraries)


def env_count(key):
    value = os.environ.get(key, "")
    return int(value) if re.fullmatch(r"[0-9]{1,20}", value) else 0


def export(kind, runner):
    sha = os.environ.get("GITHUB_SHA", "")
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    summary = dict(schema_version=1, kind=kind, runner=runner,
                   head_sha=sha if re.fullmatch(r"[0-9a-f]{40,64}", sha) else "",
                   repository=repository if re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository) else "",
                   run_id=env_count("GITHUB_RUN_ID"), run_attempt=env_count("GITHUB_RUN_ATTEMPT"),
                   adoption_qualified=False, rust_payload_mode="unknown", status="missing", rows=[])
    if kind in REMAINING_STRATA:
        summary.update(baseline_kind='independent_php_reference_projection',
            evidence_type='shared_independent_php_reference_projection', benchmark_mode='unknown')
    path = Path("utility-evidence") / kind / "observations.jsonl"
    records, malformed = [], False
    hasher = hashlib.sha256()
    try:
        with path.open("rb") as source:
            for raw in source:
                hasher.update(raw)
                try:
                    record = json.loads(raw, parse_constant=reject_constant, parse_float=finite_float)
                    if isinstance(record, dict):
                        records.append(record)
                    else:
                        malformed = True
                except (ValueError, TypeError, UnicodeError):
                    malformed = True
    except FileNotFoundError:
        return summary
    except (OSError, UnicodeError):
        malformed = True
    try:
        summary.update(caller_summary(kind, records))
    except (ValueError, TypeError, KeyError, AttributeError, OverflowError):
        summary["status"] = "invalid"
    summary["report_sha256"] = hasher.hexdigest()
    if malformed:
        summary["status"] = "invalid"
    try:
        spec = importlib.util.spec_from_file_location('benchmark_resources', Path(__file__).with_name('benchmark-resource-limits.py'))
        resources = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(resources)
        summary.setdefault('source_sha256', {}).update(resources.verified_receipt(Path('utility-evidence')))
    except (OSError, UnicodeError, ValueError, TypeError, KeyError, AttributeError, OverflowError):
        summary['status'] = 'invalid'
    try:
        runtime = json.loads(Path("utility-evidence/runtime.json").read_text(), parse_constant=reject_constant, parse_float=finite_float)
        if not isinstance(runtime, dict) or runtime.get("executed_sha") != summary["head_sha"]:
            raise ValueError("Executed build identity mismatch")
        keys = ("executed_sha", "rustc", "cargo", "php", "python", "architecture", "cpu_model", "runner_image_os", "runner_image_version", "build_profile")
        summary["runtime_identity"] = {key: runtime[key] for key in keys if isinstance(runtime.get(key), str) and len(runtime[key]) <= 4096}
        if count(runtime.get("logical_cpus")):
            summary["runtime_identity"]["logical_cpus"] = runtime["logical_cpus"]
    except FileNotFoundError:
        summary["status"] = "partial" if summary["status"] != "invalid" else "invalid"
    except (OSError, UnicodeError, ValueError, TypeError, OverflowError):
        summary["status"] = "invalid"
    if summary['status'] != 'measured':
        disqualify_rows(summary['rows'])
    return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--kind", choices=CALLER_STRATA, required=True)
    parser.add_argument("--runner", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if not re.fullmatch(r"[A-Za-z0-9_. -]{1,80}", args.runner):
        parser.error("runner must be a short public label")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(export(args.kind, args.runner), indent=2, allow_nan=False) + "\n")


if __name__ == "__main__":
    main()
