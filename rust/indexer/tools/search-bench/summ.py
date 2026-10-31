#!/usr/bin/env python3
# Prints the results of a run as compact tables. Usage: summ.py results.jsonl
import json, sys
rows = [json.loads(l) for l in open(sys.argv[1])]
print("== build")
for r in rows:
    if r["kind"] == "build":
        print(f'{r["engine"]:15} {r["docs"]:>9} docs {r["secs"]:8.1f} s {r["docs_per_sec"]:>9.0f}/s {r["gb"]:7.2f} GB')
for r in rows:
    if r["kind"] == "rss":
        print(f'{r["engine"]:15} server RSS {r["rss_kb_now"]/1e6:.2f} GB now, {r["rss_kb_peak"]/1e6:.2f} GB peak')
for r in rows:
    if r["kind"] == "load":
        print(f'load {r["at"]:5} {r["engine"]:15} {r["loadavg"]:20} import running: {r["import_running"]}')
keys = sorted({(r["mode"], r["clients"], r["burn"]) for r in rows if r["kind"] == "latency"})
for mode, c, b in keys:
    sel = [r for r in rows if r["kind"] == "latency" and (r["mode"], r["clients"], r["burn"]) == (mode, c, b)]
    engines = list(dict.fromkeys(r["engine"] for r in sel))
    classes = sorted({r["class"] for r in sel}, key=lambda x: (x == "ALL", x))
    print(f"\n== latency p95 ms ({mode}, {c} clients, {b} burn threads)")
    print(f'{"class":14}' + "".join(f"{e:>15}" for e in engines))
    for cl in classes:
        line = f"{cl:14}"
        for e in engines:
            m = [r for r in sel if r["engine"] == e and r["class"] == cl]
            line += f'{m[-1]["p95_ms"]:>15.2f}' if m else f'{"":>15}'
        print(line)
    for name, k in (("ALL p50", "p50_ms"), ("ALL p99", "p99_ms"), ("ALL max", "max_ms"), ("qps", "qps")):
        line = f"{name:14}"
        for e in engines:
            m = [r for r in sel if r["engine"] == e and r["class"] == "ALL"]
            line += f'{m[-1][k]:>15.2f}' if m else f'{"":>15}'
        print(line)
q = [r for r in rows if r["kind"] == "quality"]
engines = list(dict.fromkeys(r["engine"] for r in q))
for metric in ("precision", "recall_newest", "false_zero_rate", "hit_at_1", "hit_at_100", "wrong_episode_rate"):
    print(f"\n== {metric}")
    print(f'{"class":14}' + "".join(f"{e:>15}" for e in engines))
    for cl in sorted({r["class"] for r in q}):
        line = f"{cl:14}"; show = False
        for e in engines:
            m = [r for r in q if r["engine"] == e and r["class"] == cl]
            v = m[-1].get(metric) if m else None
            if v is None: line += f'{"-":>15}'
            else: line += f"{v:>15.3f}"; show = True
        if show: print(line)
print("\n== samples")
for r in q:
    if (r["errors"] or r["samples"]) and r["engine"] in ("meili-tuned", "meili-typo7", "tantivy-date"):
        print(r["engine"], r["class"], "errors", r["errors"], [x[:150] for x in r["samples"][:3]])
for r in rows:
    if r["kind"] == "live":
        print(f'\nlive {r["engine"]}: batch {r["batch"]} every {r["every_ms"]} ms, burn {r["burn"]}: visible p50 {r["visible_p50_ms"]:.1f} p95 {r["visible_p95_ms"]:.1f} max {r["visible_max_ms"]:.1f} ms, publish p95 {r["publish_p95_ms"]:.1f} ms, timeouts {r["timeouts"]}, batches {r["batches"]}')
