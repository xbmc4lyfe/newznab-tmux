#!/usr/bin/env python3
"""Records API goldens from the running PHP reference, one per case in cases*.json.

Start it first, on a scratch copy of the php-ref-setup.sh database:
  docker exec nntmux-rust-test-mariadb-1 sh -c "mariadb -uroot -proot -e 'CREATE DATABASE nntmux_api_ref'; \
    mariadb-dump -uroot -proot --no-tablespaces nntmux | mariadb -uroot -proot nntmux_api_ref"
  cd newznab-tmux-php-ref/public && DB_DATABASE=nntmux_api_ref APP_DEBUG=false APP_ENV=production \
    MAIL_FROM_ADDRESS=admin@example.test php -S 127.0.0.1:8765 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php

Every case runs from a fresh seed.sql plus its own `setup` statements, so the array cache and
the request counters can't leak between cases. Usage: record.py [case file stems...]"""

import base64
import http.client
import json
import os
import pathlib
import subprocess
import sys

HERE = pathlib.Path(__file__).parent
HOST = "127.0.0.1"
PORT = 8765
DROPPED = {"date", "host", "connection", "x-powered-by", "set-cookie", "server-timing", "http/1.1"}
REF = pathlib.Path.home() / "git.xbmc4lyfe/newznab-tmux-php-ref"
QUERY_ENV = {"DB_DATABASE": "nntmux_api_ref", "APP_DEBUG": "false", "APP_ENV": "production", "MAIL_FROM_ADDRESS": "admin@example.test"}


def sql(statements):
    subprocess.run(
        ["docker", "exec", "-i", "nntmux-rust-test-mariadb-1", "mariadb", "-uroot", "-proot", "nntmux_api_ref"],
        input=statements.encode(),
        check=True,
    )


def audit():
    done = subprocess.run(
        ["docker", "exec", "nntmux-rust-test-mariadb-1", "mariadb", "-uroot", "-proot", "-N", "nntmux_api_ref",
         "-e", "SELECT request FROM user_requests WHERE request <> 'x' ORDER BY id"],
        capture_output=True,
        check=True,
    )
    return done.stdout.decode().splitlines()


def kernel_fetch(case):
    done = subprocess.run(
        ["php", str(HERE / "record_request.php"), str(REF)],
        input=json.dumps(case).encode(),
        env=os.environ | QUERY_ENV,
        capture_output=True,
        check=True,
    )
    expect = json.loads(done.stdout)
    # The real server adds the Content-Length and Content-Type defaults this path skips.
    expect["headers"] = sorted(tuple(h) for h in expect["headers"] if h[0] not in DROPPED)
    return expect


def fetch(case):
    if case.get("method") == "QUERY":
        return kernel_fetch(case)
    conn = http.client.HTTPConnection(HOST, PORT, timeout=60)
    body = case.get("body")
    if case.get("body_b64"):
        body = base64.b64decode(case["body_b64"])
    headers = {"Accept": "*/*", "User-Agent": "golden"} | case.get("headers", {})
    conn.request(case.get("method", "GET"), case["path"], body=body, headers=headers)
    response = conn.getresponse()
    data = response.read()
    kept = sorted((k.lower(), v) for k, v in response.getheaders() if k.lower() not in DROPPED)
    expect = {"status": response.status, "headers": kept}
    try:
        expect["body"] = data.decode()
    except UnicodeDecodeError:
        expect["body_b64"] = base64.b64encode(data).decode()
    return expect


def main():
    stems = sys.argv[1:] or sorted(p.stem for p in HERE.glob("cases*.json"))
    seed = (HERE / "seed.sql").read_text()
    for stem in stems:
        cases = json.loads((HERE / f"{stem}.json").read_text())
        out = []
        for case in cases:
            sql(seed + "\n" + "\n".join(case.get("setup", [])))
            for _ in range(case.get("repeat", 1)):
                expect = fetch(case)
            expect["audit"] = audit()
            out.append(case | {"expect": expect})
            print(case["name"], out[-1]["expect"]["status"])
        name = stem.replace("cases", "golden", 1)
        (HERE / f"{name}.json").write_text(json.dumps(out, indent=1, ensure_ascii=False) + "\n")


main()
