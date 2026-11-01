#!/usr/bin/env python3
"""Recompute Rust-rewrite progress metrics for an NNTmux checkout.

Usage:
    python3 rewrite_metrics.py [REPO] [--json out.json] [--subsystems subsystems.toml]

Needs python3.11+ and a php CLI (only while PHP remains; with no PHP files left
the PHP side reports zero). Read-only: it never writes to the repo.

Every PHP class member (method, constant, enum case) under app/ is put in one
bucket:

  scaffold  references a rust/pipeline-ffi export or RustPipelineLibrary, carries a
            W##/Wave tag, or is FFI plumbing in a file that loads pipeline-ffi.
            pipeline-ffi never ships in the image, so this is deletable as-is.
  dead      not scaffold, and its name is never referenced from live code, routes,
            config, views, migrations or framework hooks (name-based fixed point;
            an estimate, it errs toward "live").
  live      everything else: the PHP that still has to be ported.

Lines outside members (namespace, use, properties, file headers) count as live.

The headline progress_pct counts every PHP line as not done (scaffold too). The
rewrite is Rust-native, so the "ffi" section counts C-ABI exports, cdylib crates
and PHP FFI loaders as remaining work as well; the target for all of them is 0.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tomllib
from collections import Counter, defaultdict
from pathlib import Path

HERE = Path(__file__).resolve().parent

# Names Laravel/PHP calls by convention rather than by an explicit call site.
FRAMEWORK = set("""
__construct __destruct __invoke __get __set __call __callStatic __toString __isset __unset __serialize
__unserialize __sleep __wakeup __clone __set_state __debugInfo
handle boot booted booting register rules authorize messages attributes prepareForValidation passedValidation
failedValidation withValidator validated validate passes message render report context shouldReport toResponse
toArray toJson jsonSerialize offsetGet offsetSet offsetExists offsetUnset getIterator count
build envelope content attachments headers via toMail toDatabase toBroadcast toVonage toSlack
broadcastOn broadcastAs broadcastWith middleware failed retryUntil backoff tags uniqueId uniqueFor
displayName viaConnection viaQueue shouldQueue terminate schedule commands definition casts up down run
created creating updated updating saved saving deleted deleting restored restoring retrieved forceDeleted
replicating viewAny view create update delete restore forceDelete before after
get set serialize shouldRender data with resolve resolveRouteBinding getRouteKeyName newFactory
schema description title arguments prompts uri mimeType tools resources
""".split())


def sh(args: list[str], cwd: Path) -> str:
    return subprocess.run(args, cwd=cwd, check=True, capture_output=True, text=True).stdout


def rs_files(root: Path) -> list[Path]:
    return [p for p in root.rglob("*.rs") if "target" not in p.parts]


def line_count(p: Path) -> int:
    with p.open("rb") as f:
        return sum(1 for _ in f)


def extern_syms(root: Path) -> set[str]:
    pat = re.compile(r'extern\s+"C"\s+fn\s+([A-Za-z0-9_]+)')
    out = set()
    for p in rs_files(root):
        out.update(pat.findall(p.read_text(errors="replace")))
    return out


WAVE_MOD = re.compile(r"^\s*(?:pub(?:\([^)]*\))?\s+)?mod\s+([A-Za-z0-9_]+)\s*\{", re.M)
WAVE_NAME = re.compile(r"(^|_)w\d{2,3}(_|$)|wave\d+", re.I)


def rust_wave_lines(root: Path) -> tuple[int, int]:
    """Lines and count of inline `mod w##…`/`wave…`/`…_w###` blocks (brace matched)."""
    total = blocks = 0
    for p in rs_files(root):
        src = p.read_text(errors="replace")
        covered = -1
        for m in WAVE_MOD.finditer(src):
            if not WAVE_NAME.search(m.group(1)) or m.start() < covered:
                continue
            i, depth = m.end() - 1, 0
            n = len(src)
            while i < n:
                c = src[i]
                if c == '"':  # skip string literal
                    i += 1
                    while i < n and src[i] != '"':
                        i += 2 if src[i] == "\\" else 1
                elif c == "/" and src.startswith("//", i):
                    i = src.find("\n", i)
                    i = n if i < 0 else i
                    continue
                elif c == "{":
                    depth += 1
                elif c == "}":
                    depth -= 1
                    if depth == 0:
                        break
                i += 1
            total += src.count("\n", m.start(), i) + 1
            blocks += 1
            covered = i
    return total, blocks


def load_members(repo: Path, php_files: list[str], pipeline: set[str], live_ffi: set[str], tmp: Path):
    (tmp / "pipeline_syms.txt").write_text("\n".join(sorted(pipeline)))
    (tmp / "live_syms.txt").write_text("\n".join(sorted(live_ffi)))
    members, files = [], {}
    chunk = 400
    for i in range(0, len(php_files), chunk):
        out = sh(["php", "-d", "memory_limit=2G", str(HERE / "php_members.php"),
                  str(tmp / "pipeline_syms.txt"), str(tmp / "live_syms.txt"), *php_files[i:i + chunk]], repo)
        for line in out.splitlines():
            row = json.loads(line)
            if row["type"] == "member":
                members.append(row)
            else:
                files[row["file"]] = row
    return members, files


def text_words(paths: list[Path]) -> set[str]:
    out: set[str] = set()
    word = re.compile(r"[A-Za-z_][A-Za-z0-9_]{2,}")
    for p in paths:
        try:
            out.update(word.findall(p.read_text(errors="replace")))
        except OSError:
            pass
    return out


def classify(repo: Path, members: list[dict], files: dict) -> None:
    native = re.compile(r"(^native|Native$|Native[A-Z])")
    for m in members:
        hard = m["wtag"] or m["ffi_name"] or (m["pipeline"] and not m["live_ffi"] and native.search(m["name"]))
        m["bucket"] = "scaffold" if hard else None
    # Classes that dispatch by computed name ($this->{$x}(), call_user_func, [$this, 'x']) keep all members live.
    dynamic = re.compile(r"->\{|::\{|call_user_func|\[\s*\$this\s*,|\[\s*(?:self|static)::class\s*,")
    dyn_files = {f for f in files if dynamic.search((repo / f).read_text(errors="replace"))}

    roots: list[Path] = []
    for d in ("routes", "config", "resources", "database", "bootstrap", "docker", "nntmux", "scripts", "misc"):
        base = repo / d
        if base.is_dir():
            roots += [p for p in base.rglob("*") if p.is_file() and p.suffix in {".php", ".js", ".sh", ".py", ".json", ".yml", ".yaml", ".ts", ".vue"}]
    live_names = text_words(roots) | FRAMEWORK
    for f in files.values():
        live_names.update(f["refs"])

    pending = [m for m in members if m["bucket"] is None]
    changed = True
    while changed:
        changed = False
        rest = []
        for m in pending:
            if m["name"] in live_names or m["kind"] == "case" or m["file"] in dyn_files:
                m["bucket"] = "live"
                live_names.update(r for r in m["refs"] if r != m["name"])
                changed = True
            else:
                rest.append(m)
        pending = rest
    for m in pending:
        # Unreachable code that still calls pipeline-ffi is scaffold; the rest is plain dead PHP.
        m["bucket"] = "scaffold" if m["pipeline"] and not m["live_ffi"] else "dead"
    for m in members:
        # Live members that still branch into pipeline-ffi need the FFI branch stripped.
        m["ffi_branch"] = m["bucket"] == "live" and m["pipeline"] and not m["live_ffi"]


def subsystem_of(path: str, subs: list[dict]) -> str:
    for s in subs:
        if any(re.search(p, path) for p in s["paths"]):
            return s["key"]
    return "platform"


def entrypoints(repo: Path) -> dict:
    out = {}
    console = (repo / "routes/console.php")
    if console.exists():
        txt = console.read_text(errors="replace")
        out["scheduler_entries"] = len(re.findall(r"Schedule::(command|job|call|exec)\(", txt))
    routes = 0
    for f in ("web.php", "api.php", "rss.php", "internal.php"):
        p = repo / "routes" / f
        if p.exists():
            routes += len(re.findall(r"Route::(get|post|put|patch|delete|match|any|resource|apiResource|view|redirect)\(", p.read_text(errors="replace")))
    out["http_routes"] = routes
    jobs = repo / "app/Jobs"
    out["queue_jobs"] = len(list(jobs.glob("*.php"))) if jobs.is_dir() else 0
    cmds = repo / "app/Console/Commands"
    out["artisan_commands"] = len(list(cmds.glob("*.php"))) if cmds.is_dir() else 0
    tmux = repo / "app/Services/Tmux"
    if tmux.is_dir():
        txt = "".join(p.read_text(errors="replace") for p in tmux.rglob("*.php"))
        out["tmux_artisan_targets"] = len(set(re.findall(r"'((?:multiprocessing|releases|tmux|predb|update|postprocess):[a-z:_-]+)'", txt)))
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("repo", nargs="?", default=".")
    ap.add_argument("--json")
    ap.add_argument("--subsystems", default=str(HERE / "subsystems.toml"))
    ap.add_argument("--dump-members", help="write per-member TSV (file, name, start, end, bucket)")
    a = ap.parse_args()
    repo = Path(a.repo).resolve()
    cfg = tomllib.loads(Path(a.subsystems).read_text())
    subs = cfg["subsystem"]
    tmp = Path(os.environ.get("TMPDIR", "/tmp")) / f"rewrite-metrics-{os.getpid()}"
    tmp.mkdir(parents=True, exist_ok=True)

    head = sh(["git", "rev-parse", "--short", "HEAD"], repo).strip()
    php_files = sorted(sh(["git", "ls-files", "app/*.php", "app/**/*.php"], repo).split())

    pipeline_root = repo / "rust/pipeline-ffi"
    live_roots = [repo / "docker/app/nzb-yenc-ffi", repo / "rust/fingerprint-worker"]
    pipeline = extern_syms(pipeline_root) if pipeline_root.is_dir() else set()
    live_ffi = {s for r in live_roots if r.is_dir() for s in extern_syms(r) if s.startswith(("nntmux_", "rapidyenc_"))}
    pipeline -= live_ffi

    members, files = load_members(repo, php_files, pipeline, live_ffi, tmp) if php_files else ([], {})
    classify(repo, members, files)

    per = defaultdict(Counter)
    file_sub = {f: subsystem_of(f, subs) for f in php_files}
    for f in php_files:
        s = file_sub[f]
        per[s]["files"] += 1
        per[s]["lines"] += files.get(f, {}).get("lines", 0)
    for m in members:
        n = m["end"] - m["start"] + 1
        s = file_sub[m["file"]]
        per[s][m["bucket"]] += n
        per[s]["members_" + m["bucket"]] += 1
        if m["ffi_branch"]:
            per[s]["ffi_branch_members"] += 1
    for s in per.values():
        s["live"] = s["lines"] - s["scaffold"] - s["dead"]  # outside-member lines count as live

    tests = sh(["git", "ls-files", "tests"], repo).split()
    wave_tests = [t for t in tests if re.search(r"W\d{2,3}\w*Test\.php$", t)]
    php_tests = [t for t in tests if t.endswith(".php")]

    rust = {}
    if pipeline_root.is_dir():
        wl, wb = rust_wave_lines(pipeline_root)
        rust["pipeline_ffi_lines"] = sum(line_count(p) for p in rs_files(pipeline_root))
        rust["pipeline_ffi_wave_lines"] = wl
        rust["pipeline_ffi_wave_blocks"] = wb
        rust["pipeline_ffi_exports"] = len(pipeline)
    for r in live_roots:
        if r.is_dir():
            rust[r.name + "_lines"] = sum(line_count(p) for p in rs_files(r))
    ws = repo / "rust"
    new_crates = []
    if ws.is_dir():
        for d in sorted(p for p in ws.iterdir() if (p / "Cargo.toml").exists() and p.name not in {"pipeline-ffi", "fingerprint-worker"}):
            new_crates.append({"crate": d.name, "lines": sum(line_count(p) for p in rs_files(d))})
        crates = ws / "crates"
        if crates.is_dir():
            for d in sorted(p for p in crates.iterdir() if (p / "Cargo.toml").exists()):
                new_crates.append({"crate": "crates/" + d.name, "lines": sum(line_count(p) for p in rs_files(d))})
    rust["workspace_crates"] = new_crates
    rust["workspace_lines"] = sum(c["lines"] for c in new_crates)

    # FFI bridges: the rewrite is Rust-native, so every C-ABI export, cdylib crate and
    # PHP FFI loader is unfinished work, whether or not it runs in production.
    ffi = {"rust_c_exports": 0, "rust_c_exports_pipeline": 0, "cdylib_crates": [], "php_ffi_files": 0, "php_ffi_files_live": []}
    tracked_rs = [repo / f for f in sh(["git", "ls-files", "*.rs"], repo).split()]
    exp = re.compile(r'extern\s+"C"\s+fn\s+[A-Za-z0-9_]+')
    for f in tracked_rs:
        n = len(exp.findall(f.read_text(errors="replace")))
        ffi["rust_c_exports"] += n
        if "rust/pipeline-ffi" in str(f):
            ffi["rust_c_exports_pipeline"] += n
    for f in sh(["git", "ls-files", "*Cargo.toml"], repo).split():
        if re.search(r'crate-type\s*=\s*\[[^\]]*"cdylib"', (repo / f).read_text(errors="replace")):
            ffi["cdylib_crates"].append(str(Path(f).parent))
    ffi_word = re.compile(r"\bFFI\b")
    live_word = re.compile(r"\b(" + "|".join(map(re.escape, sorted(live_ffi))) + r")\b") if live_ffi else None
    for f in sh(["git", "ls-files", "app/*.php", "app/**/*.php", "docker/*.php", "docker/**/*.php"], repo).split():
        txt = (repo / f).read_text(errors="replace")
        if ffi_word.search(txt):
            ffi["php_ffi_files"] += 1
            if live_word and live_word.search(txt):
                ffi["php_ffi_files_live"].append(f)

    statuses = Counter(s["status"] for s in subs)
    ledger_errors = []
    for s in subs:
        live = per[s["key"]]["live"]
        if s["status"] == "deleted" and per[s["key"]]["files"]:
            ledger_errors.append(f'{s["key"]}: status=deleted but {per[s["key"]]["files"]} PHP files remain')
        if s["status"] in {"shadow", "rust", "deleted"} and not s.get("evidence"):
            ledger_errors.append(f'{s["key"]}: status={s["status"]} without evidence')

    tot = Counter()
    for s in per.values():
        tot.update(s)
    report = {
        "commit": head,
        "php": {"files": len(php_files), "lines": tot["lines"], "live": tot["live"], "dead": tot["dead"], "scaffold": tot["scaffold"],
                "members": {b: tot["members_" + b] for b in ("live", "dead", "scaffold")},
                "live_members_with_ffi_branch": tot["ffi_branch_members"]},
        "subsystems": [{"key": s["key"], "title": s["title"], "phase": s["phase"], "status": s["status"],
                        **{k: per[s["key"]][k] for k in ("files", "lines", "live", "dead", "scaffold")}} for s in subs],
        "tests": {"php_test_files": len(php_tests), "php_test_lines": sum(line_count(repo / t) for t in php_tests),
                  "wave_parity_files": len(wave_tests), "wave_parity_lines": sum(line_count(repo / t) for t in wave_tests)},
        "rust": rust,
        "entrypoints": entrypoints(repo),
        "ffi": ffi,
        "cutover": {"by_status": dict(statuses), "done": statuses.get("deleted", 0), "total": len(subs), "ledger_errors": ledger_errors},
    }
    base = cfg.get("baseline", {})
    # Headline: all PHP counts as not done, scaffold and dead included. Port progress uses live only.
    if base.get("total_lines"):
        report["progress_pct"] = round(100 * (1 - tot["lines"] / base["total_lines"]), 1)
    if base.get("live_lines"):
        report["port_progress_pct"] = round(100 * (1 - tot["live"] / base["live_lines"]), 1)

    if a.dump_members:
        with open(a.dump_members, "w") as f:
            for m in members:
                f.write(f'{m["file"]}\t{m["kind"]}\t{m["name"]}\t{m["start"]}\t{m["end"]}\t{m["bucket"]}\n')
    if a.json:
        Path(a.json).write_text(json.dumps(report, indent=2))

    p = report["php"]
    print(f"NNTmux rewrite metrics @ {head}")
    print(f"PHP app/: {p['files']} files, {p['lines']:,} lines = live {p['live']:,} + dead {p['dead']:,} + scaffold {p['scaffold']:,}")
    print(f"Live members that still branch into pipeline-ffi: {p['live_members_with_ffi_branch']:,}")
    if "progress_pct" in report:
        print(f"Progress vs baseline {base.get('commit')}: {report['progress_pct']}% of PHP removed (port progress on live PHP: {report.get('port_progress_pct')}%)")
    print(f"\n{'subsystem':16} {'phase':>5} {'status':8} {'files':>6} {'lines':>8} {'live':>8} {'dead':>8} {'scaffold':>9}")
    for s in report["subsystems"]:
        print(f"{s['key']:16} {s['phase']:>5} {s['status']:8} {s['files']:>6} {s['lines']:>8,} {s['live']:>8,} {s['dead']:>8,} {s['scaffold']:>9,}")
    t = report["tests"]
    print(f"\nTests: {t['php_test_files']} PHP test files ({t['php_test_lines']:,} lines); wave parity {t['wave_parity_files']} files ({t['wave_parity_lines']:,} lines)")
    print("Rust: " + ", ".join(f"{k}={v:,}" for k, v in rust.items() if isinstance(v, int)))
    print("Entry points still on PHP: " + ", ".join(f"{k}={v}" for k, v in report["entrypoints"].items()))
    f = report["ffi"]
    print(f"FFI bridges: {f['rust_c_exports']} Rust C exports ({f['rust_c_exports_pipeline']} in pipeline-ffi), cdylib crates {f['cdylib_crates']}, "
          f"{f['php_ffi_files']} PHP files using FFI ({len(f['php_ffi_files_live'])} on live libnzbyenc symbols)")
    c = report["cutover"]
    print(f"Cutover: {c['done']}/{c['total']} subsystems deleted; by status {c['by_status']}")
    for e in c["ledger_errors"]:
        print("  LEDGER ERROR: " + e)
    return 1 if c["ledger_errors"] else 0


if __name__ == "__main__":
    sys.exit(main())
