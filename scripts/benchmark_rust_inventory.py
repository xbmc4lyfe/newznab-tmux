#!/usr/bin/env python3
"""Bounded source inventory of tracked Rust C exports and their evidence scope.

This describes benchmark wiring, never successful runs, per-export timings, or
adoption qualification. Internal Rust modules and CLI coverage are separate.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

SOURCE_ROOT = 'docker/app/nzb-yenc-ffi/src'
ABI_SOURCE = SOURCE_ROOT + '/lib.rs'
NATIVE_METADATA = 'app/Services/Releases/Fingerprint/NativeMetadataParser.php'
DECODER = 'app/Services/Yenc/NativePayloadDecoder.php'
PRESENTATION = 'docker/app/php/release-presentation-preload.php'
FINGERPRINT = 'tests/Fixtures/nzb-article-fingerprint/NzbArticleFingerprint.php'
MAX_FILES = 256
MAX_SOURCE_BYTES = 2 * 1024 * 1024
MAX_EXPORTS = 256

# Fixed evidence classifications are deliberately reviewed alongside API changes.
# A related benchmark family does not imply that an individual ABI was timed.
DECLARATIONS = {
    'nntmux_release_names_html': ('caller-comparison', ('presentation',), (PRESENTATION,),
        'Release presentation batch caller includes FFI setup, rendering and result conversion.'),
    'nntmux_release_names_free': ('lifecycle-included', ('presentation',), (PRESENTATION,),
        'Output ownership cleanup within presentation caller; no individual free timing.'),
    'nntmux_article_fingerprint_new': ('lifecycle-included', ('fingerprint-ffi',), (FINGERPRINT,),
        'Accumulator construction in bounded FFI fixture caller; no individual constructor timing.'),
    'nntmux_article_fingerprint_add': ('caller-comparison', ('fingerprint-ffi',), (FINGERPRINT,),
        'Bounded FFI accumulator candidate fixture; worker binary evidence is separate.'),
    'nntmux_article_fingerprint_value': ('caller-comparison', ('fingerprint-ffi',), (FINGERPRINT,),
        'Digest extraction within FFI caller operation; no individual export timing.'),
    'nntmux_article_fingerprint_free': ('lifecycle-included', ('fingerprint-ffi',), (FINGERPRINT,),
        'Accumulator release within FFI caller lifecycle; no individual free timing.'),
    'rapidyenc_decode_init': ('lifecycle-included', ('php',), (DECODER,),
        'Decoder initialization supports the PHP caller comparison; not an individual speed benchmark.'),
    'rapidyenc_version': ('lifecycle-included', ('php',), (DECODER,),
        'Version qualification in decoder setup; not an individual speed benchmark.'),
    'nntmux_yenc_supports_split_escapes': ('lifecycle-included', ('php',), (DECODER,),
        'Capability qualification in decoder setup; not an individual speed benchmark.'),
    'nntmux_par2_metadata_json': ('caller-comparison', ('archive-parsing',), (NATIVE_METADATA,),
        'PAR2 public parser comparison includes the native metadata bridge.'),
    'nntmux_format_metadata_json': ('shared-reference-comparison', ('vendor-archive', 'archive-parsing'), (NATIVE_METADATA,),
        'Vendor archive headers have shared-projection comparisons; other format fixtures are native diagnostics, not full-parser equivalence.'),
    'nntmux_article_dates_v1_json': ('shared-reference-comparison', ('metadata-modules',), (NATIVE_METADATA,),
        'Bounded independent PHP reference versus shared date projection; not production PHP speedup.'),
    'nntmux_seven_zip_ranges_json': ('diagnostic-only', ('archive-parsing',), (NATIVE_METADATA,),
        'Native seven-Zip range diagnostics include FFI and serialization; no paired baseline.'),
    'nntmux_par2_source_ranges_json': ('diagnostic-only', ('archive-parsing',), (NATIVE_METADATA,),
        'Native PAR2 source-range diagnostics include FFI and serialization; no paired baseline.'),
    'nntmux_yenc_framing_metadata_json': ('diagnostic-only', ('profile',), (NATIVE_METADATA,),
        'Article profile framing phase; diagnostic attribution, not independent adoption evidence.'),
    'nntmux_nntp_header_metadata_json': ('diagnostic-only', ('profile',), (NATIVE_METADATA,),
        'Article profile headers phase; diagnostic attribution, not independent adoption evidence.'),
    'nntmux_nntp_header_pair_comparison_json': ('unmeasured-gap', (), (NATIVE_METADATA,),
        'Current header-pair caller exists; no independent comparative benchmark is wired.'),
    'nntmux_nntp_body_measurement_json': ('diagnostic-only', ('profile',), (NATIVE_METADATA,),
        'Article profile body-metadata phase and bridge diagnostics; not independent adoption evidence.'),
    'nntmux_nntp_lines_body_relationship_json': ('diagnostic-only', ('profile',), (NATIVE_METADATA,),
        'Article profile relationship phase; diagnostic attribution, not independent adoption evidence.'),
    'nntmux_article_identity_v2_json': ('shared-reference-comparison', ('metadata-modules',), (NATIVE_METADATA,),
        'Current v2 caller versus bounded independent PHP reference shared projection; not production PHP speedup.'),
    'nntmux_article_identity_xref_v1_json': ('shared-reference-comparison', ('metadata-modules',), (NATIVE_METADATA,),
        'Current Xref caller versus bounded independent PHP reference shared projection; not production PHP speedup.'),
    'nntmux_article_identity_json': ('compatibility-gap', (), (),
        'Legacy compatibility API; current caller uses v2. No comparative benchmark for this export.'),
    'nntmux_metadata_string_free': ('lifecycle-included', ('sample-planning', 'archive-parsing', 'metadata-modules', 'profile'),
        (NATIVE_METADATA, 'app/Services/Releases/Verification/NativeSpotCheckPlanner.php'),
        'Shared JSON allocation cleanup inside callers; no individual free timing.'),
    'nntmux_spot_check_sample_v1_json': ('caller-comparison', ('sample-planning',),
        ('app/Services/Releases/Verification/NativeSpotCheckPlanner.php',),
        'Public sample planner comparison includes native JSON bridge and setup.'),
    'nntmux_header_filename_identity_v1': ('caller-comparison', ('filename-identity',),
        ('app/Services/Releases/Verification/NativeHeaderFilenameIdentity.php',),
        'Guarded candidate for HeaderFilenameIdentity::matches, not wired into production. The paired '
        'PHP-versus-Rust+FFI benchmark (scripts/benchmark-filename-identity.php) runs in CI on x86 and ARM; '
        'PHP stays authoritative until common-median and uncommon-p99 reach 1.5x.'),
    'rapidyenc_decode_ex': ('caller-comparison', ('php',), (DECODER,),
        'PHP decoder caller comparison; optional native ABI diagnostics are separate.'),
    'nntmux_yenc_decode_span_v1': ('caller-comparison', ('php',), (DECODER,),
        'Span decoder caller comparison includes allocation/copy and CRC; no individual export timing.'),
    'rapidyenc_crc_init': ('lifecycle-included', ('php',), (DECODER,),
        'CRC initialization supports decoder caller; not an individual speed benchmark.'),
    'nntmux_yenc_payload_kernel_v1': ('unmeasured-gap', (), (),
        'Kernel provenance query; no standalone comparative benchmark or current PHP caller.'),
    'rapidyenc_crc': ('caller-comparison', ('php',), (DECODER,),
        'CRC work included in PHP decoder caller; optional native ABI diagnostics are separate.'),
    'rapidyenc_decode_kernel': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'rapidyenc_encode_max_length': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'rapidyenc_decode': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'rapidyenc_decode_incremental': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'rapidyenc_crc_kernel': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'rapidyenc_crc_combine': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'rapidyenc_crc_zeros': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'rapidyenc_crc_unzero': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'rapidyenc_crc_multiply': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'rapidyenc_crc_2pow': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'rapidyenc_crc_256pow': ('experimental-compatibility-gap', (), (),
        'Only exported by experimental-rapidyenc-abi; experimental compatibility symbol with no production caller or individually qualified measurement.'),
    'nntmux_yenc_implementation_profile_v1': ('profile-unmeasured-gap', (), (),
        'Build-profile probe returns incumbent or experimental identity; no PHP caller, timing, or adoption authority.'),
}


def _mask_noncode(source):
    """Preserve positions/newlines while masking nested comments and literals.

    Retain only the exact ABI string token "C". Raw string bodies, including
    apparent declarations, are never parsed as code. This is a bounded lexical
    inventory, not a Rust compiler or cfg evaluator.
    """
    output = list(source)
    index = 0
    while index < len(source):
        start = index
        if source.startswith('//', index):
            end = source.find('\n', index)
            index = len(source) if end == -1 else end
        elif source.startswith('/*', index):
            index += 2
            depth = 1
            while index < len(source) and depth:
                if source.startswith('/*', index):
                    depth += 1
                    index += 2
                elif source.startswith('*/', index):
                    depth -= 1
                    index += 2
                else:
                    index += 1
        else:
            raw = re.match(r'(?:br|cr|r)(#{0,255})"', source[index:index + 260]) if source[index] in 'rbc' else None
            if raw:
                terminator = '"' + raw.group(1)
                end = source.find(terminator, index + raw.end())
                index = len(source) if end == -1 else end + len(terminator)
            elif source[index] == '"':
                index += 1
                while index < len(source):
                    if source[index] == '\\':
                        index += 2
                    elif source[index] == '"':
                        index += 1
                        break
                    else:
                        index += 1
                if source[start:index] == '"C"':
                    continue
            elif source[index] == "'" and (char := re.match(r"'(?:\\[^\n]|[^'\\\n])'", source[index:])):
                index += char.end()
            else:
                index += 1
                continue
        for position in range(start, min(index, len(source))):
            if output[position] != '\n':
                output[position] = ' '
    return ''.join(output)


# Linker visibility is independent of Rust visibility. Retain attributes so
# renamed symbols are classified by their exported name, not the Rust fn name.
EXPORT = re.compile(r'(?P<attributes>(?:#\s*\[[^\]]*\]\s*)+)'
                    r'(?:pub(?:\s*\([^)]*\))?\s+)?(?:const\s+)?(?:async\s+)?(?:unsafe\s+)?'
                    r'(?:extern(?:\s+"C")?\s+)?fn\s+(?:r#)?(?P<name>[A-Za-z_][A-Za-z_0-9]*)\s*\(')
EXPORT_ATTRIBUTE = re.compile(r'#\s*\[[^\]]*\b(?:no_mangle|export_name)\b[^\]]*\]')


def exported_functions(source):
    masked = _mask_noncode(source)
    covered = set()
    for match in EXPORT.finditer(masked):
        attributes = match.group('attributes')
        export_attributes = list(EXPORT_ATTRIBUTE.finditer(attributes))
        if not export_attributes:
            continue
        for attribute in export_attributes:
            covered.add(match.start('attributes') + attribute.start())
        name = match.group('name')
        if re.search(r'\bexport_name\s*=', attributes):
            original = source[match.start('attributes'):match.end('attributes')]
            # Unsupported literal expressions fail closed instead of silently
            # allowing an unclassified linker symbol into the next build.
            names = re.findall(r'\bexport_name\s*=\s*"([A-Za-z_][A-Za-z_0-9]*)"', original)
            if len(names) != 1:
                raise ValueError('Export name requires an explicit supported symbol literal')
            name = names[0]
        yield name, match.start('name')
    if any(attribute.start() not in covered for attribute in EXPORT_ATTRIBUTE.finditer(masked)):
        raise ValueError('Unsupported exported declaration requires an inventory classification')


def inventory(root):
    """Return all discovered exports, including explicit unknown coverage gaps."""
    root = Path(root).resolve()
    result = subprocess.run(['git', '-C', str(root), 'ls-files', '-z', '--', SOURCE_ROOT],
                            check=True, capture_output=True, timeout=15)
    paths = sorted(path for path in result.stdout.decode().split('\0') if path.endswith('.rs'))
    if len(paths) > MAX_FILES:
        raise ValueError('Rust source inventory exceeds file bound')
    rows = []
    for target in paths:
        path = root / target
        if path.is_symlink() or not path.resolve().is_relative_to(root):
            raise ValueError('Rust source inventory requires repository-owned regular files')
        if path.stat().st_size > MAX_SOURCE_BYTES:
            raise ValueError('Rust source inventory exceeds source size bound')
        data = path.read_bytes()
        source = data.decode('utf-8')
        for name, position in exported_functions(source):
            coverage, kinds, callers, detail = DECLARATIONS.get(name, (
                'unclassified-gap', (), (), 'New public export requires an explicit evidence classification.'))
            rows.append(dict(name=name, target=target,
                             line=source.count('\n', 0, position) + 1,
                             source_sha256=hashlib.sha256(data).hexdigest(), coverage=coverage,
                             detail=detail, evidence_kinds=list(kinds), callers=list(callers)))
            if len(rows) > MAX_EXPORTS:
                raise ValueError('Rust source inventory exceeds export bound')
    return sorted(rows, key=lambda row: (row['target'], row['line']))


def check(root):
    """Fail on unknown, duplicate, relocated or removed declared exports."""
    rows = inventory(root)
    names = [row['name'] for row in rows]
    issues = [f'missing declared export: {name}' for name in sorted(set(DECLARATIONS) - set(names))]
    for row in rows:
        name = row['name']
        if row['coverage'] == 'unclassified-gap':
            issues.append(f'unclassified export: {name}')
        elif row['target'] != ABI_SOURCE:
            issues.append(f'stale declared source for export: {name}')
        if names.count(name) != 1:
            issues.append(f'duplicate export: {name}')
    return issues


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    try:
        if args.check:
            issues = check(args.root)
            for issue in issues:
                print(issue, file=sys.stderr)
            return int(bool(issues))
        print(json.dumps(inventory(args.root), indent=2))
        return 0
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        print(f'Rust inventory failed: {error}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
