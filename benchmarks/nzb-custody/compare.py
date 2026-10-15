#!/usr/bin/env python3
"""Bounded offline Rust rejection screen. Writes only a NEW output directory."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import random
import statistics
import subprocess
import tempfile
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', required=True, type=Path)
args = parser.parse_args()
out = args.output.resolve()
out.mkdir(parents=True, exist_ok=False)
repo = Path(__file__).resolve().parents[2]
fixture = Path(__file__).resolve().parent

def command(argv, **kwargs):
    return subprocess.run(argv, check=True, capture_output=True, text=True, timeout=180, **kwargs)

helper = out / 'sync'
command(['rustc', '--edition=2021', '-C', 'opt-level=3', '-C', 'panic=abort', str(fixture / 'sync.rs'), '-o', str(helper)])
bridge = out / 'RustBridge.php'
bridge.write_text('''<?php
namespace App\\Services\\Nzb;
final class DirectorySyncBridge {
 public static string $fail = '';
 public static bool $trace = false;
 public static array $events = [];
 public static ?\\Closure $afterSync = null;
 public static function sync(string $directory, string $boundary): bool {
  if (self::$fail === $boundary) { return false; }
  try {
   $p = new \\Symfony\\Component\\Process\\Process([getenv('CUSTODY_HELPER'), $directory]);
   $p->setTimeout(5); $p->run();
   $r = json_decode($p->getOutput(), true);
   $ok = $p->isSuccessful() && is_array($r) && ($r['ok'] ?? false) === true;
  } catch (\\Throwable) { return false; }
  if ($ok && self::$afterSync !== null) { (self::$afterSync)($directory, $boundary); }
  return $ok;
 }
}
''')
raw = []
rng = random.Random(169)
for phase, blocks, count, warmup in [('warm', 2, 5, 1), ('cold', 10, 1, 0)]:
    for block in range(blocks):
        order = ['native', 'rust']
        rng.shuffle(order)
        for variant in order:
            owned = tempfile.mkdtemp(prefix='custody-owned-')
            env = os.environ | dict(CUSTODY_VARIANT=variant, CUSTODY_BRIDGE=str(bridge), CUSTODY_HELPER=str(helper), CUSTODY_OWNED=owned, CUSTODY_WARMUP=str(warmup), CUSTODY_COUNT=str(count))
            started = time.perf_counter_ns()
            try:
                result = command(['php', str(fixture / 'flow.php')], env=env)
                wall_ms = (time.perf_counter_ns() - started) / 1e6
                if result.stderr: raise RuntimeError('Unexpected fixture stderr')
                values = json.loads(result.stdout)
                raw.append(dict(phase=phase, block=block, variant=variant, wall_ms=wall_ms, **values))
            except Exception as error:
                # Preserve the failed run without local paths or environment values.
                raw.append(dict(phase=phase, block=block, variant=variant, failure=type(error).__name__))
                (out / 'raw.json').write_text(json.dumps(raw, indent=2))
                raise
            (out / 'raw.json').write_text(json.dumps(raw, indent=2))
summary = {}
for phase in ['warm', 'cold']:
    summary[phase] = {}
    for variant in ['native', 'rust']:
        rows = [r for r in raw if r['phase'] == phase and r['variant'] == variant]
        values = sorted(v for r in rows for v in r['flow_ms'])
        summary[phase][variant] = dict(n=len(values), median_flow_ms=statistics.median(values), sample_p99_flow_ms=values[max(0, (99 * len(values) + 99) // 100 - 1)], median_process_wall_ms=statistics.median(r['wall_ms'] for r in rows))
    summary[phase]['native_over_rust'] = summary[phase]['native']['median_flow_ms'] / summary[phase]['rust']['median_flow_ms']
sources = ['app/Services/Nzb/NzbUploadManifestService.php', 'app/Services/Nzb/DirectorySyncBridge.php', 'benchmarks/nzb-custody/sync.rs', 'benchmarks/nzb-custody/flow.php', 'benchmarks/nzb-custody/compare.py']
meta = dict(head=command(['git', 'rev-parse', 'HEAD'], cwd=repo).stdout.strip(), platform=platform.platform(), machine=platform.machine(), rustc=command(['rustc', '--version']).stdout.strip(), php=command(['php', '-r', 'echo PHP_VERSION;']).stdout, source_sha256={name: hashlib.sha256((repo / name).read_bytes()).hexdigest() for name in sources}, helper_sha256=hashlib.sha256(helper.read_bytes()).hexdigest(), protocol='seed169 randomized paired blocks; 1 warmup then 5 flows per warm process; 10 paired fresh cold processes; shared host, synthetic fixture; p99 unqualified; no runtime deployment')
(out / 'metadata.json').write_text(json.dumps(meta, indent=2))
(out / 'summary.json').write_text(json.dumps(summary, indent=2))
print(json.dumps(summary))
