#!/usr/bin/env python3
"""Disposable, synthetic Linux TLS screen for the actual fingerprint collector.

This is not a provider-capacity, p99, publication, deployment, or adoption gate.
The listener callback observes a connection only AFTER its TLS handshake.
"""

import argparse
import asyncio
import binascii
import gzip
import hashlib
import json
import math
import os
from pathlib import Path
import platform
import random
import re
import shutil
import signal
import ssl
import statistics
import struct
import sys
import time
import traceback


SEED = 20261016
WORKER_SECONDS = 30
OUTER_SECONDS = 300
ROLES = ("primary", "alternate")
PHASES = ("first_after_preflight", "repeat")
SIZES = (65536, 786432)
FORMAT = ("Subject:", "From:", "Date:", "Message-ID:", "References:", ":bytes", ":lines")
SOURCE_FILES = (
    "scripts/benchmark-fingerprint-tls.py",
    "scripts/benchmark-fingerprint-tls-worker.php",
    "app/Services/NNTP/NNTPService.php",
    "app/Services/NNTP/FingerprintConnectionReuseNntpService.php",
    "app/Services/Releases/Fingerprint/ReleaseFingerprintCollector.php",
    "app/Services/Releases/Fingerprint/NzbFingerprintAnalyzer.php",
    "app/Services/Releases/Fingerprint/NativeMetadataParser.php",
    "app/Services/Releases/Fingerprint/Par2MetadataParser.php",
    "app/Services/Nzb/NzbService.php",
    "app/Services/YencService.php",
    "app/Services/Yenc/NativePayloadDecoder.php",
    "app/Extensions/helper/helpers.php",
    "app/Services/Tmux/Tmux.php",
    "app/Services/Configuration/ConfigurationProvider.php",
    "app/Services/Binaries/BinariesConfig.php",
    "composer.lock",
    "vendor/autoload.php",
    "vendor/composer/installed.json",
    "vendor/dariusiii/net_nntp/src/Client.php",
    "vendor/dariusiii/net_nntp/src/Protocol/Client.php",
)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def save(path, value):
    path.write_text(json.dumps(value, indent=2, sort_keys=True, allow_nan=False) + "\n")


def source_hashes(repo):
    return {name: sha((repo / name).read_bytes()) for name in SOURCE_FILES}


def process_stat(pid):
    try:
        fields = Path(f"/proc/{pid}/stat").read_text().rsplit(")", 1)[1].split()
        return {"pid": pid, "ppid": int(fields[1]), "pgid": int(fields[2]),
                "session": int(fields[3]), "start_ticks": int(fields[19])}
    except (FileNotFoundError, ProcessLookupError):
        return None
    except PermissionError as error:
        raise RuntimeError(f"Process identity is inaccessible for pid {pid}; custody is unknown") from error


def owned_members(lease):
    leader = process_stat(lease["pid"])
    require(leader is None or leader["start_ticks"] == lease["start_ticks"],
            "Owned process leader identity changed; refusing to signal")
    members = []
    for entry in Path("/proc").iterdir():
        if not entry.name.isdigit():
            continue
        record = process_stat(int(entry.name))
        if record and record["pgid"] == lease["pid"]:
            require(record["session"] == lease["pid"]
                    and record["start_ticks"] >= lease["start_ticks"],
                    "Process group ownership is unconfirmed; refusing to signal")
            members.append(record)
    return members


def inspect_owned_group(lease):
    if lease["start_ticks"] is None:
        record = process_stat(lease["pid"])
        if record is None:
            # No destructive signal: a fast-exited child may have vanished before /proc sampling.
            try:
                os.killpg(lease["pid"], 0)
            except ProcessLookupError:
                return []
            raise RuntimeError("A live group remains without a verified leader identity")
        require(record["pgid"] == lease["pid"] and record["session"] == lease["pid"],
                "Could not verify the newly owned process session")
        lease["start_ticks"] = record["start_ticks"]
    return owned_members(lease)


def signal_child_handle(proc, sig):
    # asyncio retains the actual child handle. This fallback never signals an unverified group.
    if proc.returncode is None:
        try:
            proc.send_signal(sig)
        except ProcessLookupError:
            pass


async def terminate_owned(proc, lease):
    def signal_verified_group_or_child(sig):
        if lease["inspection_error"] is None:
            try:
                if inspect_owned_group(lease):
                    try:
                        os.killpg(lease["pid"], sig)
                    except ProcessLookupError:
                        pass
                return
            except Exception as error:
                lease["inspection_error"] = f"{type(error).__name__}: {error}"
        signal_child_handle(proc, sig)

    signal_verified_group_or_child(signal.SIGTERM)
    try:
        await asyncio.wait_for(proc.wait(), 2)
    except asyncio.TimeoutError:
        signal_verified_group_or_child(signal.SIGKILL)
        await asyncio.wait_for(proc.wait(), 3)
    require(lease["inspection_error"] is None,
            f"Process was reaped through its handle but group custody remains unresolved: {lease['inspection_error']}")
    try:
        # A reaped leader may have left its original session members behind.
        if inspect_owned_group(lease):
            signal_verified_group_or_child(signal.SIGKILL)
            deadline = time.monotonic() + 3
            while inspect_owned_group(lease) and time.monotonic() < deadline:
                await asyncio.sleep(0.01)
        require(not inspect_owned_group(lease), "Owned process group is still live after cleanup")
        require(lease["inspection_error"] is None, "Process group inspection failed during cleanup")
    except Exception as error:
        lease["inspection_error"] = f"{type(error).__name__}: {error}"
        raise
    lease["cleanup_confirmed"] = True


class Processes:
    def __init__(self, output, repo, deadline):
        self.output, self.repo, self.deadline = output, repo, deadline
        self.active = {}
        self.serial = 0

    async def run(self, argv, prefix, env=None):
        remaining = self.deadline - time.monotonic()
        require(remaining > 0, "Outer 300-second budget exhausted")
        self.serial += 1
        require(not self.active, "A previous owned process is still active")
        proc = await asyncio.create_subprocess_exec(
            *map(str, argv), cwd=self.repo, env=env, start_new_session=True,
            umask=0o077, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE,
        )
        # Register custody before /proc inspection or any further await can fail.
        lease = {"pid": proc.pid, "start_ticks": None, "inspection_error": None, "cleanup_confirmed": False}
        self.active[proc.pid] = (proc, lease)
        communication = asyncio.create_task(proc.communicate())
        failure = None
        cleanup_failure = None
        try:
            try:
                record = process_stat(proc.pid)
                if record is not None:
                    require(record["pgid"] == proc.pid and record["session"] == proc.pid,
                            "Could not verify the newly owned process session")
                    lease["start_ticks"] = record["start_ticks"]
            except Exception as error:
                lease["inspection_error"] = f"{type(error).__name__}: {error}"
                raise
            stdout, stderr = await asyncio.wait_for(
                asyncio.shield(communication), min(WORKER_SECONDS, remaining),
            )
        except BaseException as error:
            failure = error
        finally:
            try:
                await terminate_owned(proc, lease)
            except BaseException as error:
                cleanup_failure = error
            finally:
                if not communication.done():
                    try:
                        await asyncio.wait_for(asyncio.shield(communication), 3)
                    except BaseException as error:
                        if cleanup_failure is None:
                            cleanup_failure = error
                if communication.done() and not communication.cancelled():
                    stdout, stderr = communication.result()
                    prefix.with_suffix(".stdout").write_bytes(stdout)
                    prefix.with_suffix(".stderr").write_bytes(stderr)
                save(prefix.with_suffix(".process.json"), {
                    "argv": list(map(str, argv)), "lease": lease, "returncode": proc.returncode,
                    "failure": None if failure is None else type(failure).__name__,
                    "cleanup_failure": None if cleanup_failure is None else f"{type(cleanup_failure).__name__}: {cleanup_failure}",
                    "output_capture_complete": communication.done() and not communication.cancelled(),
                })
                if lease["cleanup_confirmed"]:
                    self.active.pop(proc.pid, None)
        if failure is not None:
            raise failure
        if cleanup_failure is not None:
            raise cleanup_failure
        require(proc.returncode == 0, f"Owned process failed; retain {prefix}.stderr")
        return stdout.decode("utf-8", errors="strict")

    async def close(self):
        failures = []
        for proc, lease in list(self.active.values()):
            try:
                await terminate_owned(proc, lease)
            except BaseException as error:
                failures.append(f"pid {proc.pid}: {type(error).__name__}: {error}")
            if lease["cleanup_confirmed"]:
                self.active.pop(proc.pid, None)
        require(not failures, "Unresolved process custody retained: " + "; ".join(failures))


def payload(size, part):
    blocks = [hashlib.sha256(f"synthetic-fingerprint-{size}-{part}-{index}".encode()).digest()
              for index in range((size + 31) // 32)]
    return b"".join(blocks)[:size]


def encoded_part(data, size, part):
    lines, line = [], bytearray()
    for byte in data:
        value = (byte + 42) % 256
        if value in (0, 10, 13, 61):
            line.extend((61, (value + 64) % 256))
        else:
            line.append(value)
        if len(line) >= 128:
            lines.append(bytes(line))
            line.clear()
    if line:
        lines.append(bytes(line))
    return (f"=ybegin part={part} total=2 line=128 size={size * 2} name=synthetic.bin\r\n"
            f"=ypart begin={(part - 1) * size + 1} end={part * size}\r\n").encode() + b"\r\n".join(lines) + (
                f"\r\n=yend size={size} part={part} pcrc32={binascii.crc32(data):08x}\r\n").encode()


def multiline(lines):
    return b"".join((b"." if line.startswith(b".") else b"") + line + b"\r\n"
                    for line in lines) + b".\r\n"


def fixture(size):
    articles = {}
    for part in (1, 2):
        message = f"sample-part{part}@fixture.invalid"
        raw = payload(size, part)
        body = encoded_part(raw, size, part)
        articles[message] = {"part": part, "decoded_sha256": sha(raw), "decoded_bytes": size,
                             "body": body, "body_sha256": sha(body)}
    segments = "".join(f'<segment bytes="{len(article["body"])}" number="{article["part"]}">{message}</segment>'
                       for message, article in articles.items())
    xml = ('<?xml version="1.0"?><nzb><file subject="synthetic.bin" poster="fixture" date="1792144800">'
           '<groups><group>alt.test</group></groups><segments>' + segments + '</segments></file></nzb>').encode()
    return articles, xml


def expected_commands():
    commands = ["CAPABILITIES", "LIST OVERVIEW.FMT"]
    for part in (1, 2):
        commands.extend(f"{command} <sample-part{part}@fixture.invalid>" for command in ("OVER", "HEAD", "BODY"))
    return commands


class Peer:
    def __init__(self, log):
        self.log = log
        self.records = []
        self.errors = []
        self.handlers = set()
        self.writers = set()
        self.trial = None
        self.collection = -1
        self.articles = None

    def event(self, record, kind, **values):
        event = {"connection": record["connection"], "role": record["role"],
                 "trial": record["trial"], "collection": record["collection"],
                 "kind": kind, "monotonic_ns": time.monotonic_ns(), **values}
        self.log.write(json.dumps(event, sort_keys=True) + "\n")
        self.log.flush()
        return event["monotonic_ns"]

    async def send(self, writer, record, response):
        record["sent_bytes"] += len(response)
        record["sent_hasher"].update(response)
        for start in range(0, len(response), 65536):
            writer.write(response[start:start + 65536])
            await asyncio.wait_for(writer.drain(), WORKER_SECONDS)

    def response(self, command, role):
        if command == "CAPABILITIES":
            return b"101 capabilities\r\n" + multiline([b"VERSION 2", b"IMPLEMENTATION synthetic-tls-screen", b"OVER MSGID"])
        if command == "LIST OVERVIEW.FMT":
            return b"215 overview format\r\n" + multiline([item.encode() for item in FORMAT])
        match = re.fullmatch(r"(OVER|HEAD|BODY) <([^<>]+)>", command)
        require(match and match[2] in self.articles, "Unexpected command or message identity")
        verb, message = match.groups()
        article = self.articles[message]
        if verb == "OVER":
            row = (f"0\tsynthetic.bin\tfixture@example.invalid\tFri, 16 Oct 2026 10:00:00 +0000\t<{message}>\t\t"
                   f"{len(article['body'])}\t{article['body'].count(bytes((10,)))}").encode()
            return b"224 overview\r\n" + multiline([row])
        if verb == "HEAD":
            headers = [b"Subject: synthetic.bin", b"From: fixture@example.invalid",
                       b"Date: Fri, 16 Oct 2026 10:00:00 +0000", f"Message-ID: <{message}>".encode(),
                       f"X-Provider-Fixture: {role}".encode(), b"User-Agent: Nyuu fixture"]
            return f"221 0 <{message}> headers\r\n".encode() + multiline(headers)
        return f"222 0 <{message}> body\r\n".encode() + multiline(article["body"][:-2].split(b"\r\n"))

    def accept(self, role, reader, writer):
        task = asyncio.create_task(self.handle(role, reader, writer))
        self.handlers.add(task)
        task.add_done_callback(self.handlers.discard)

    async def handle(self, role, reader, writer):
        self.writers.add(writer)
        record = None
        try:
            require(self.trial is not None, "TLS connection arrived outside an owned trial")
            if role == "primary":
                self.collection += 1
            require(self.collection in (0, 1), "Unexpected primary collection admission")
            transport = writer.get_extra_info("ssl_object")
            require(transport is not None, "Peer accepted a non-TLS stream")
            record = {"connection": len(self.records), "role": role, "trial": self.trial,
                      "collection": self.collection, "commands": [], "user_count": 0, "auth_count": 0,
                      "received_bytes": 0, "sent_bytes": 0, "received_hasher": hashlib.sha256(),
                      "sent_hasher": hashlib.sha256(), "auth_ns": None, "close_ns": None}
            self.records.append(record)
            self.event(record, "tls_callback_post_handshake", version=transport.version(), cipher=transport.cipher(),
                       session_reused=transport.session_reused)
            writer.transport.set_write_buffer_limits(high=65536, low=16384)
            await self.send(writer, record, b"200 synthetic fixture ready\r\n")
            authenticated = False
            user_seen = False
            while True:
                line = await asyncio.wait_for(reader.readline(), WORKER_SECONDS)
                if not line:
                    self.event(record, "eof")
                    break
                require(len(line) <= 512 and line.endswith(b"\r\n"), "Malformed or oversized NNTP command")
                record["received_bytes"] += len(line)
                record["received_hasher"].update(line)
                command = line[:-2].decode("ascii")
                if not authenticated:
                    if not user_seen:
                        require(command == f"AUTHINFO user local-{role}", "Wrong local role username or auth order")
                        user_seen = True
                        record["user_count"] += 1
                        self.event(record, "auth_user", username=f"local-{role}")
                        await self.send(writer, record, b"381 password required\r\n")
                    else:
                        require(command == f"AUTHINFO pass fixture-{role}", "Wrong local role password or auth order")
                        authenticated = True
                        record["auth_count"] += 1
                        record["auth_ns"] = self.event(record, "auth_success")
                        await self.send(writer, record, b"281 authentication accepted\r\n")
                else:
                    require(len(record["commands"]) < 8, "Extra fingerprint command")
                    record["commands"].append(command)
                    response = self.response(command, role)
                    self.event(record, "command", command=command, response_bytes=len(response), response_sha256=sha(response))
                    await self.send(writer, record, response)
        except Exception as error:
            self.errors.append({"trial": self.trial if record is None else record["trial"],
                                "role": role, "error": type(error).__name__, "message": str(error)})
        finally:
            if record is not None:
                record["close_ns"] = self.event(record, "close")
            writer.close()
            try:
                await asyncio.wait_for(writer.wait_closed(), 2)
            except (Exception, asyncio.CancelledError):
                writer.transport.abort()
            self.writers.discard(writer)

    async def settle(self):
        if self.handlers:
            await asyncio.wait_for(asyncio.gather(*list(self.handlers)), 3)
        require(not self.handlers and not self.writers, "Owned peer streams remain live")

    async def close(self):
        for writer in list(self.writers):
            writer.transport.abort()
        for task in list(self.handlers):
            task.cancel()
        if self.handlers:
            await asyncio.wait_for(asyncio.gather(*list(self.handlers), return_exceptions=True), 3)
        require(not self.handlers and not self.writers, "Owned peer cleanup incomplete")

    def validate(self, trial, arm):
        require(not self.errors, "Peer failures were retained; this observation is invalid")
        records = [record for record in self.records if record["trial"] == trial]
        summary = {}
        for collection, phase in enumerate(PHASES):
            summary[phase] = {}
            for role in ROLES:
                selected = [record for record in records if record["collection"] == collection and record["role"] == role]
                expected_count = 3 if role == "alternate" and arm == "baseline" else 1
                require(len(selected) == expected_count, f"Unexpected {phase}/{role} TLS connection count")
                commands = [command for record in selected for command in record["commands"]]
                require(commands == expected_commands(), f"Unexpected {phase}/{role} ordered command correlation")
                for record in selected:
                    require(record["user_count"] == record["auth_count"] == 1 and record["close_ns"] is not None,
                            "Missing role authentication or connection closure")
                intervals = [(record["auth_ns"], record["close_ns"]) for record in selected]
                events = sorted([(start, 1) for start, _ in intervals] + [(end, -1) for _, end in intervals])
                active = maximum = 0
                for _, change in events:
                    active += change
                    maximum = max(maximum, active)
                summary[phase][role] = {"tls_callbacks_post_handshake": len(selected),
                    "authentication_successes": len(selected), "fingerprint_commands": commands,
                    "authenticated_slot_ns": sum(end - start for start, end in intervals),
                    "maximum_simultaneous_authenticated_slots": maximum,
                    "sent_bytes": sum(record["sent_bytes"] for record in selected),
                    "received_bytes": sum(record["received_bytes"] for record in selected)}
        return summary

    def serializable(self):
        return [{**{key: value for key, value in record.items() if not key.endswith("hasher")},
                 "sent_sha256": record["sent_hasher"].hexdigest(),
                 "received_sha256": record["received_hasher"].hexdigest()} for record in self.records]


def normalized_report(report, articles):
    report = json.loads(json.dumps(report, allow_nan=False))
    require(isinstance(report, dict) and report.get("status") == "complete", "Missing complete collector report")
    data = report["data"]
    require(data["errors"] == [], "Collector errors invalidate an observation")
    require(data["collection_policy"]["article_samples_selected"] == 2
            and data["collection_policy"]["article_samples_omitted_due_to_limit"] == 0, "Wrong sample coverage")
    observations = data["article_observations"]
    require(len(observations) == 4, "Missing provider/sample observations")
    expected = [(message, role) for message in articles for role in ROLES]
    for observation, (message, role) in zip(observations, expected):
        duration = observation["retrieval_duration_ms"]
        require(type(duration) in (int, float) and math.isfinite(duration) and duration >= 0, "Invalid duration leaf")
        del observation["retrieval_duration_ms"]
        require(observation["message_id"] == message and observation["server_role"] == role, "Wrong report identity/order")
        require(observation["head_response"]["response_code"] == 221
                and observation["body_response"]["response_code"] == 222
                and observation["overview_response_code"] == 224, "Wrong protocol response evidence")
        metadata = observation["metadata"]
        require(metadata["article_body_sha256"] == articles[message]["body_sha256"], "Raw body hash mismatch")
        require(metadata["yenc"]["decoded_segment_sha256"] == articles[message]["decoded_sha256"]
                and metadata["yenc"]["decoded_segment_size"] == articles[message]["decoded_bytes"], "Decoded payload mismatch")
        require(metadata["yenc"]["declared_crc32_matches"] is True, "Declared multipart CRC was not verified")
    return report


def write_nzb(root, guid, xml):
    directory = root.joinpath(*guid[:4])
    directory.mkdir(parents=True)
    destination = directory / f"{guid}.nzb.gz"
    destination.write_bytes(gzip.compress(xml, mtime=0))
    return sha(destination.read_bytes())


def resource_custody():
    root = Path("/sys/fs/cgroup")
    require((root / "cgroup.controllers").is_file(), "The disposable screen requires readable Linux cgroup v2 limits")
    membership = Path("/proc/self/cgroup").read_text()
    relative = next((line[3:] for line in membership.splitlines() if line.startswith("0::")), None)
    require(relative is not None, "Cannot identify the owned runtime cgroup")
    group = (root / relative.lstrip("/")).resolve()
    if not group.is_relative_to(root) or not group.is_dir():
        group = root  # A cgroup namespace may expose only its own mounted root.
    limits = {}
    while True:
        limits[str(group)] = {name: (group / name).read_text() if (group / name).is_file() else "not_exposed"
                              for name in ("cpu.max", "memory.max", "pids.max")}
        if group == root:
            break
        group = group.parent
    return {"cpu_affinity": sorted(os.sched_getaffinity(0)), "resource_limits": Path("/proc/self/limits").read_text(),
            "cgroup_membership": membership, "cgroup_ancestor_limits": limits}


async def certificates(processes, directory, openssl):
    directory.mkdir(mode=0o700)
    ca, key, cert = directory / "ca.crt", directory / "server.key", directory / "server.crt"
    await processes.run([openssl, "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                         "-subj", "/CN=Disposable fingerprint TLS CA", "-addext", "basicConstraints=critical,CA:TRUE",
                         "-keyout", directory / "ca.key", "-out", ca], directory / "create-ca")
    await processes.run([openssl, "req", "-new", "-newkey", "rsa:2048", "-nodes",
                         "-subj", "/CN=127.0.0.1", "-keyout", key, "-out", directory / "server.csr"], directory / "create-leaf")
    extensions = directory / "leaf.ext"
    extensions.write_text("basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:127.0.0.1\n")
    await processes.run([openssl, "x509", "-req", "-in", directory / "server.csr", "-CA", ca,
                         "-CAkey", directory / "ca.key", "-CAcreateserial", "-days", "1",
                         "-extfile", extensions, "-out", cert], directory / "sign-leaf")
    await processes.run([openssl, "verify", "-CAfile", ca, "-verify_ip", "127.0.0.1", cert], directory / "verify-leaf")
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(cert, key)
    # Preserve OpenSSL's normal ticket/session behavior; do not inflate reconnect costs.
    return context, ca


async def screen(args, output):
    require(platform.system() == "Linux", "This screen requires a disposable Linux runtime")
    require(process_stat(os.getpid()) is not None, "Readable Linux process identity is required before spawning")
    repo = Path(__file__).resolve().parent.parent
    library = args.library.resolve(strict=True)
    require(library.is_file(), "Native library is not a regular file")
    elf = library.read_bytes()
    require(elf[:4] == b"\x7fELF" and elf[4] == 2 and elf[5] == 1, "Native library must be a 64-bit little-endian ELF")
    machine = {"x86_64": 62, "aarch64": 183}.get(platform.machine())
    require(machine and struct.unpack_from("<H", elf, 18)[0] == machine, "Native library architecture mismatch")
    php, openssl, ss_command = (shutil.which(name) for name in ("php", "openssl", "ss"))
    require(php and openssl and ss_command, "Existing PHP, OpenSSL and ss are required; nothing is installed")
    frozen = source_hashes(repo)
    library_hash = sha(elf)
    deadline = time.monotonic() + OUTER_SECONDS
    processes = Processes(output, repo, deadline)
    log = (output / "peer.jsonl").open("x")
    peer = Peer(log)
    servers = []
    trials = []
    manifest = {"status": "preparing", "synthetic": True, "source_sha256": frozen,
                "native_library": str(library), "native_library_sha256": library_hash,
                "python": sys.version, "python_openssl": ssl.OPENSSL_VERSION, "platform": platform.platform(),
                "seed": SEED, "pairs": args.pairs,
                "payload_bytes_per_part": list(SIZES), "worker_timeout_seconds": WORKER_SECONDS,
                "outer_budget_seconds": OUTER_SECONDS, "resource_custody": resource_custody(),
                "phase_definition": "first_after_preflight is partially warmed: native identity/xref/body/format families may initialize during the first collection; repeat is the second same-process collection with a fresh NNTP owner",
                "ratio_interpretation": "Uncorrected observer-instrumented diagnostics: synchronous peer JSON serialization and flush overhead differs with connection/event counts; require quieter confirmation before speedup interpretation",
                "limitations": ["SYNTHETIC decoded sizes, not an observed production distribution",
                    "ELF architecture and hash do not prove build ISA/ABI compatibility or production ARM qualification; a historical artifact requires external provenance pins and diagnostic-only interpretation",
                    "TLS callback timestamps begin after handshake, not full TCP accept time",
                    "Persistent alternate slots span primary reads and can crowd other workers",
                    "Unchanged settings/concurrency; no shared-provider fairness or release-throughput proof",
                    "Healthy screen only; idle-TLS-close parity needs a separate adverse correctness gate",
                    "Observer overhead is uncorrected and differs between arms; ratios require quieter confirmation before speedup interpretation",
                    "No p99, adoption, fresh-publication, Horizon, SQL, deployment or live-provider claim"]}
    try:
        manifest["php_version"] = await processes.run([php, "--version"], output / "php-version")
        manifest["openssl_version"] = await processes.run([openssl, "version", "-a"], output / "openssl-version")
        manifest["ss_version"] = await processes.run([ss_command, "--version"], output / "ss-version")
        context, ca = await certificates(processes, output / "certificates", openssl)
        manifest["public_certificate_sha256"] = {name: sha((output / "certificates" / name).read_bytes())
                                               for name in ("ca.crt", "server.crt")}
        for role in ROLES:
            server = await asyncio.start_server(lambda reader, writer, role=role: peer.accept(role, reader, writer),
                                                "127.0.0.1", 0, ssl=context, ssl_handshake_timeout=5, limit=1024)
            servers.append(server)
        ports = {role: server.sockets[0].getsockname()[1] for role, server in zip(ROLES, servers)}
        require(len(set(ports.values())) == 2 and all(1024 <= port <= 65535 for port in ports.values()),
                "Listeners must have distinct nonprivileged ephemeral ports")
        manifest["listener_ports"] = ports
        order = []
        rng = random.Random(SEED)
        for size in SIZES:
            for pair in range(args.pairs):
                arms = ["baseline", "candidate"]
                rng.shuffle(arms)
                order.append({"payload_bytes_per_part": size, "pair": pair, "arms": arms})
        manifest["paired_order"] = order
        manifest["status"] = "frozen_before_worker_execution"
        save(output / "manifest.json", manifest)
        env = {"PATH": os.pathsep.join(dict.fromkeys([str(Path(php).parent), str(Path(ss_command).parent), "/usr/bin", "/bin"])),
               "LANG": "C", "LC_ALL": "C", "TZ": "UTC"}
        for specification in order:
            size, pair = specification["payload_bytes_per_part"], specification["pair"]
            articles, xml = fixture(size)
            guid = sha(f"synthetic-fingerprint-{size}".encode())[:32]
            results = {}
            for arm in specification["arms"]:
                require(time.monotonic() < deadline, "Outer screen budget exhausted")
                require(source_hashes(repo) == frozen and sha(library.read_bytes()) == library_hash, "Source or native library changed")
                require(not processes.active and not peer.handlers and not peer.writers, "Prior trial custody is not clear")
                trial_id = f"synthetic-{size}-pair{pair}-{arm}"
                directory = output / trial_id
                directory.mkdir(mode=0o700)
                root = directory / "nzb"
                nzb_hash = write_nzb(root, guid, xml)
                config = {"arm": arm, "primary_port": ports["primary"], "alternate_port": ports["alternate"],
                          "ca_file": str(ca), "native_library": str(library), "nzb_root": str(root), "guid": guid,
                          "output_file": str(directory / "worker-output.json"), "expected_samples": 2,
                          "workload_id": f"SYNTHETIC-{size}-bytes-per-part", "source_sha256": frozen,
                          "native_library_sha256": library_hash, "ca_file_sha256": sha(ca.read_bytes()),
                          "nzb_sha256": nzb_hash,
                          "expected_payloads": {message: {"decoded_bytes": article["decoded_bytes"],
                              "decoded_sha256": article["decoded_sha256"], "raw_body_sha256": article["body_sha256"]}
                              for message, article in articles.items()}}
                # Shared CA and all trial artifacts are canonical children of this input's root.
                config_path = output / f"{trial_id}-input.json"
                save(config_path, config)
                peer.trial, peer.collection, peer.articles = trial_id, -1, articles
                await processes.run([php, "-d", "ffi.enable=true", repo / "scripts/benchmark-fingerprint-tls-worker.php", config_path], directory / "worker", env)
                await peer.settle()
                after_custody = resource_custody()
                save(directory / "resource-custody.json", {"before": manifest["resource_custody"], "after": after_custody})
                require(after_custody == manifest["resource_custody"], "Static resource custody changed during a trial")
                require(source_hashes(repo) == frozen and sha(library.read_bytes()) == library_hash, "Source/native drift during worker")
                raw = json.loads((directory / "worker-output.json").read_text())
                require(raw.get("valid") is True, "Worker marked this observation invalid")
                observations = raw["observations"]
                require([item["phase"] for item in observations] == list(PHASES), "Missing or unordered worker phases")
                normalized = []
                for item in observations:
                    require(type(item["caller_ns"]) is int and item["caller_ns"] > 0, "Invalid caller duration")
                    normalized.append(normalized_report(item["report"], articles))
                require(normalized[0] == normalized[1], "First/repeat report parity failed")
                peer_summary = peer.validate(trial_id, arm)
                record = {"trial": trial_id, "arm": arm, "pair": pair, "payload_bytes_per_part": size,
                          "nzb_gzip_sha256": nzb_hash, "caller_ns": {item["phase"]: item["caller_ns"] for item in observations},
                          "normalized_report_sha256": sha(json.dumps(normalized[0], sort_keys=True, allow_nan=False).encode()),
                          "peer": peer_summary, "valid": True}
                trials.append(record)
                save(directory / "validated.json", record)
                results[arm] = (normalized, record)
                peer.trial, peer.articles = None, None
                save(output / "trials.json", trials)
            require(results["baseline"][0] == results["candidate"][0], "Baseline/candidate whole-report parity failed")
            require(results["baseline"][1]["nzb_gzip_sha256"] == results["candidate"][1]["nzb_gzip_sha256"], "Input NZBs differ")
        paired = []
        for size in SIZES:
            for phase in PHASES:
                ratios = []
                for pair in range(args.pairs):
                    matched = {record["arm"]: record for record in trials
                               if record["payload_bytes_per_part"] == size and record["pair"] == pair}
                    ratios.append(matched["baseline"]["caller_ns"][phase] / matched["candidate"]["caller_ns"][phase])
                paired.append({"synthetic_payload_bytes_per_part": size, "phase": phase, "pairs": args.pairs,
                               "paired_baseline_over_candidate": ratios, "median_ratio": statistics.median(ratios)})
        manifest["status"] = "healthy_synthetic_screen_complete_not_adoption"
        save(output / "summary.json", {"paired": paired, "valid_trials": len(trials),
             "ratio_interpretation": manifest["ratio_interpretation"], "limitations": manifest["limitations"]})
    except BaseException as error:
        manifest["status"] = "failed_invalid_screen"
        manifest["failure"] = {"type": type(error).__name__, "message": str(error), "traceback": traceback.format_exc()}
        raise
    finally:
        pending_failure = sys.exc_info()[0] is not None
        cleanup_errors = []
        try:
            await processes.close()
        except BaseException as error:
            cleanup_errors.append(f"process cleanup: {type(error).__name__}: {error}")
        for server in servers:
            server.close()
        for server in servers:
            try:
                await asyncio.wait_for(server.wait_closed(), 2)
            except BaseException as error:
                cleanup_errors.append(f"listener cleanup: {type(error).__name__}: {error}")
        try:
            await peer.close()
        except BaseException as error:
            cleanup_errors.append(f"peer cleanup: {type(error).__name__}: {error}")
        # Attempt both private-key removals even if process or socket cleanup failed.
        keys = [output / "certificates" / name for name in ("ca.key", "server.key")]
        for key in keys:
            try:
                if key.is_file():
                    key.unlink()
            except OSError as error:
                cleanup_errors.append(f"owned key cleanup: {type(error).__name__}: {error}")
        manifest["owned_private_keys_removed"] = all(not key.exists() for key in keys)
        manifest["owned_processes_remaining"] = len(processes.active)
        manifest["owned_process_custody"] = [lease for _, lease in processes.active.values()]
        manifest["owned_peer_streams_remaining"] = len(peer.writers)
        manifest["peer_errors"] = peer.errors
        manifest["cleanup_errors"] = cleanup_errors
        if cleanup_errors:
            manifest["status"] = "failed_invalid_cleanup"
        try:
            manifest["resource_custody_after"] = resource_custody()
            manifest["resource_custody_drift"] = manifest["resource_custody_after"] != manifest["resource_custody"]
        except Exception as error:
            manifest["resource_custody_after"] = {"snapshot_failure": type(error).__name__, "message": str(error)}
            manifest["resource_custody_drift"] = True
        if manifest["resource_custody_drift"]:
            manifest["status"] = "failed_invalid_resource_drift"
        save(output / "peer-connections.json", peer.serializable())
        log.close()
        save(output / "manifest.json", manifest)
        if cleanup_errors and not pending_failure:
            raise RuntimeError("Owned cleanup failed; retain manifest and do not continue trials")
        if manifest["resource_custody_drift"] and not pending_failure:
            raise RuntimeError("Static resource custody changed; retain raw before/after snapshots")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--library", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)
    parser.add_argument("--pairs", type=int, choices=range(1, 6), default=5)
    args = parser.parse_args()
    output = args.output_dir.resolve()
    # Never reuse, clear, or delete a user-provided existing directory.
    output.mkdir(mode=0o700, parents=False, exist_ok=False)
    try:
        asyncio.run(asyncio.wait_for(screen(args, output), OUTER_SECONDS))
    except BaseException:
        (output / "runner-failure.txt").write_text(traceback.format_exc())
        raise


if __name__ == "__main__":
    main()
