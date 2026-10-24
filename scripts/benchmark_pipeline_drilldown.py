#!/usr/bin/env python3
"""Inventory and drill-down verifier for all NNTmux Rust crates, files, functions, and paths."""
import argparse
import json
from pathlib import Path
import re

CRATE_DIRS = (
    'rust/pipeline-ffi/src',
    'docker/app/nzb-yenc-ffi/src',
    'rust/fingerprint-worker/src',
)
EXPECTED_PATHS = ('hot_path', 'uncommon_tail', 'boundary_batch', 'fallback_edge')


def discover_all_rust_files(root):
    root = Path(root)
    files = []
    for crate_dir in CRATE_DIRS:
        base = root / crate_dir
        if base.is_dir():
            for path in sorted(base.rglob('*.rs')):
                files.append(path.relative_to(root).as_posix())
    return sorted(files)


def discover_pipeline_exports(root):
    root = Path(root)
    exports = {}
    base = root / 'rust/pipeline-ffi/src'
    if not base.is_dir():
        return exports
    for path in sorted(base.glob('*.rs')):
        rel = path.relative_to(root).as_posix()
        text = path.read_text(encoding='utf-8')
        for match in re.finditer(
            r'#\[(?:unsafe\()?no_mangle\)?\][\s\S]{0,450}?pub\s+(?:unsafe\s+)?extern\s+"C"\s+fn\s+([a-z0-9_]+)',
            text,
        ):
            exports[match.group(1)] = rel
        for match in re.finditer(
            r'\b(?:string_export|u16_export|export_checker|nfo_metadata_export|nfo_scalar_export|nfo_append_export)!\s*\(\s*([a-z0-9_]+)',
            text,
        ):
            exports.setdefault(match.group(1), rel)
    return exports


def load_drilldown(root, strict_exports=False):
    root = Path(root)
    data_path = root / 'github-pages/docs/data/rust-module-benchmarks.json'
    if not data_path.is_file():
        return None
    payload = json.loads(data_path.read_text(encoding='utf-8'))
    validate_drilldown(payload, root, strict_exports=strict_exports)
    return payload


def validate_drilldown(payload, root=None, strict_exports=False):
    if not isinstance(payload, dict) or payload.get('schema_version') != 1:
        raise ValueError('Invalid rust-module-benchmarks schema_version')
    summary = payload.get('summary')
    components = payload.get('components')
    if not isinstance(summary, dict) or not isinstance(components, list) or not components:
        raise ValueError('Missing summary or components list in rust-module-benchmarks.json')

    seen_files = set()
    seen_functions = {}
    total_fns = 0
    total_paths = 0

    for comp in components:
        if not isinstance(comp, dict):
            raise ValueError('Component entry must be an object')
        for key in ('id', 'name', 'crate', 'description', 'hot_path_speedup', 'uncommon_p99_speedup', 'all_paths_speedup', 'files'):
            if key not in comp:
                raise ValueError(f'Component missing required key: {key}')
        if not isinstance(comp['files'], list) or not comp['files']:
            raise ValueError(f"Component {comp['id']} has no files")

        for file_entry in comp['files']:
            rust_file = file_entry.get('rust_file')
            if not isinstance(rust_file, str) or not rust_file.endswith('.rs'):
                raise ValueError(f'Invalid rust_file in component {comp["id"]}')
            if rust_file in seen_files:
                raise ValueError(f'Duplicate rust_file in drilldown: {rust_file}')
            seen_files.add(rust_file)

            functions = file_entry.get('functions')
            if not isinstance(functions, list) or not functions:
                raise ValueError(f'File {rust_file} has no benchmarked functions')

            for fn in functions:
                fn_name = fn.get('name')
                if not isinstance(fn_name, str) or not fn_name:
                    raise ValueError(f'Invalid function name in {rust_file}')
                seen_functions[(rust_file, fn_name)] = fn
                total_fns += 1
                paths = fn.get('paths')
                if not isinstance(paths, list) or [p.get('path_id') for p in paths] != list(EXPECTED_PATHS):
                    raise ValueError(f'Function {fn_name} in {rust_file} must benchmark all 4 potential paths')
                for path_row in paths:
                    total_paths += 1
                    for metric in ('php_median_ns', 'php_mean_ns', 'php_p99_ns', 'rust_median_ns', 'rust_mean_ns', 'rust_p99_ns', 'median_speedup', 'mean_speedup', 'p99_speedup', 'primary_speedup'):
                        val = path_row.get(metric)
                        if type(val) not in (int, float) or val <= 0:
                            raise ValueError(f'Invalid metric {metric} for {fn_name} ({path_row.get("path_id")})')

    if summary.get('rust_file_count') != len(seen_files):
        raise ValueError('Summary rust_file_count does not match file entries')
    if summary.get('function_count') != total_fns:
        raise ValueError('Summary function_count does not match function entries')
    if summary.get('path_count') != total_paths:
        raise ValueError('Summary path_count does not match path entries')

    if root is not None:
        root_path = Path(root)
        if all((root_path / d).is_dir() for d in CRATE_DIRS):
            expected_files = set(discover_all_rust_files(root_path))
            if seen_files != expected_files:
                missing = sorted(expected_files - seen_files)
                extra = sorted(seen_files - expected_files)
                raise ValueError(f'Rust file coverage mismatch: missing={missing}, extra={extra}')
            pipeline_exports = discover_pipeline_exports(root_path)
            recorded_pipeline_fns = {
                (rfile, fn_name)
                for (rfile, fn_name) in seen_functions
                if rfile.startswith('rust/pipeline-ffi/src/')
            }
            if len(recorded_pipeline_fns) < 300:
                raise ValueError(f'Too few pipeline FFI exports in drilldown: {len(recorded_pipeline_fns)}')
            if strict_exports:
                for sym, rfile in pipeline_exports.items():
                    if (rfile, sym) not in seen_functions:
                        raise ValueError(f'Missing pipeline FFI export in drilldown: {sym} ({rfile})')
    return True


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path.cwd())
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--strict-exports', action='store_true')
    args = parser.parse_args()
    payload = load_drilldown(args.root, strict_exports=args.strict_exports)
    if payload is None:
        raise SystemExit('Missing github-pages/docs/data/rust-module-benchmarks.json')
    if not args.check:
        print(json.dumps(payload['summary'], indent=2))


if __name__ == '__main__':
    main()
