#!/usr/bin/env python3
"""Generates rust/porting/ledger/<workstream>.toml from a php-final checkout.

Run once at the start of the rewrite. After that the ledger files are edited by hand as
entries are ported. Usage: generate_ledger.py <php-final checkout> <routes.json> <commands.json>
routes.json comes from `php artisan route:list --json`, and commands.json from
`php artisan list --format=json`.
"""

import json
import pathlib
import re
import sys
from collections import defaultdict

checkout = pathlib.Path(sys.argv[1])
routes = json.load(open(sys.argv[2]))
out = pathlib.Path(__file__).parent / "ledger"

DROPPED_ROUTE_PREFIXES = {
    "forum": "the forum is dropped",
    "horizon": "Horizon goes away; the Rust workers don't use it",
    "pulse": "Laravel Pulse goes away; Grafana covers it",
    "_boost": "Laravel dev tooling",
    "_ignition": "Laravel dev tooling",
    "livewire": "Livewire goes away; pages use htmx",
    "broadcasting": "Laravel Echo auth, unused by the htmx pages",
    "storage": "Laravel local storage route, not used in production",
}
API_ROUTES = {"api", "rss", "getnzb", "apihelp", "apiv2help", "rsshelp"}

COMMAND_RULES = [
    (r"^(nntmux:)?(binaries|backfill|articles|multiprocessing:(update|backfill|safe)|group|groups)|nntp|header|xover|part-repair|collection", "ws-ingest"),
    (r"categor", "ws-naming"),
    (r"fix|rename|predb|irc|multiprocessing:fixrel|match:", "ws-naming"),
    (r"verification|fingerprint", "ws-releases"),
    (r"^(releases|nzbs|multiprocessing:releases)|nzb|release", "ws-releases"),
    (r"^(tv|movie|anime|books|steam|music|games|cbp)|imdb|tmdb|tvdb|trakt|anidb|igdb|xxx|fanart", "ws-metadata"),
    (r"^postprocess|multiprocessing:postprocess|nfo|par2|additional|sample", "ws-postprocess"),
    (r"manticore|search|index", "ws-search"),
    (r"^(gdpr|mail|role|captcha|cloudflare|admin)|user|invit|account|password", "ws-admin"),
    (r"^update|install|migrate|deploy", "ws-deploy"),
]
JOB_OWNERS = {
    "CollectReleaseFingerprintMetadata": "ws-releases",
    "ReindexReleaseJob": "ws-search",
    "RecordApiUsage": "ws-api",
    "UpdateUserApiAccess": "ws-api",
}


def command_owner(name):
    for pattern, owner in COMMAND_RULES:
        if re.search(pattern, name):
            return owner
    return "ws-ops"


entries = defaultdict(list)


def add(owner, kind, ident, source="", status="todo", note=""):
    entries[owner].append(
        {"kind": kind, "id": ident, "source": source, "status": status, "note": note}
    )


for route in routes:
    uri = route["uri"]
    first = uri.split("/")[0]
    ident = f"{route['method']} /{uri}".replace("//", "/")
    action = route["action"] or "closure"
    if first in DROPPED_ROUTE_PREFIXES or action.startswith("TeamTeaTime"):
        reason = DROPPED_ROUTE_PREFIXES.get(first, "the forum is dropped")
        add("dropped", "route", ident, action, "dropped", reason)
    elif first == "admin" or first == "btcpay":
        add("ws-admin", "route", ident, action)
    elif first in API_ROUTES:
        add("ws-api", "route", ident, action)
    elif first == "internal":
        add("ws-ops", "route", ident, action)
    else:
        add("ws-site", "route", ident, action)

for path in sorted((checkout / "app/Console/Commands").rglob("*.php")):
    text = path.read_text(errors="replace")
    match = re.search(r"signature\s*=\s*['\"]([^\s'\"]+)", text)
    if match:
        add(command_owner(match.group(1)), "command", match.group(1), str(path.relative_to(checkout)))

for path in sorted((checkout / "app/Jobs").glob("*.php")):
    name = path.stem
    owner = JOB_OWNERS.get(name, "ws-admin")
    add(owner, "job", name, str(path.relative_to(checkout)))

console = (checkout / "routes/console.php").read_text()
for match in re.finditer(r"Schedule::(command|exec|call|job)\(([^\n]*)", console):
    add("ws-ops", "schedule", f"{match.group(1)}: {match.group(2).strip()[:90]}", "routes/console.php")

out.mkdir(exist_ok=True)


def quote(value):
    return json.dumps(value, ensure_ascii=False)


for owner, items in sorted(entries.items()):
    lines = [f"# Entry points owned by {owner}. Status: todo, ported or dropped (with a note).", ""]
    for item in sorted(items, key=lambda i: (i["kind"], i["id"])):
        lines.append("[[entry]]")
        lines.append(f"kind = {quote(item['kind'])}")
        lines.append(f"id = {quote(item['id'])}")
        lines.append(f"source = {quote(item['source'])}")
        lines.append(f"status = {quote(item['status'])}")
        if item["note"]:
            lines.append(f"note = {quote(item['note'])}")
        lines.append("")
    (out / f"{owner}.toml").write_text("\n".join(lines))
    print(owner, len(items))
