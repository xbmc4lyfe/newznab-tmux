#!/usr/bin/env python3
"""Source-prepared, bounded real-caller idle TLS correctness packet; never timing.

Execution needs a separately authorized disposable Linux AArch64 window. This
file and the proposal are not a launch grant. It never builds or installs.
Only main() imports the existing benchmark's source helpers at eventual runtime.
"""

import argparse
import asyncio
import copy
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import platform
import shutil
import socket
import ssl
import struct
import sys
import time


ARMS = ("baseline", "candidate-held", "candidate")
CASES = ("healthy", "close_after_profile", "close_after_first_article")
WORKER_SECONDS, OUTER_SECONDS, CLEANUP_SECONDS = 30, 300, 15
# Reserve 20s for cancellation to traverse current process/trial cleanup, then
# a separate 15s final independent custody sweep inside the 300s outer budget.
BODY_SECONDS = OUTER_SECONDS - CLEANUP_SECONDS - 20
MAX_CONNECTIONS, MAX_TASKS, MAX_EVENTS = 4, 8, 2048
PEER_MEMORY, STDOUT_LIMIT, STDERR_LIMIT, RETENTION_LIMIT = 1048576, 1048576, 262144, 20971520
NATIVE_ROOT = "docker/app/nzb-yenc-ffi"
TRACE_HELPER = "scripts/fingerprint-tls-correctness-trace.php"
DRIVER = "scripts/test-fingerprint-tls-idle-close.py"
RECEIPT_SCHEMA = "fingerprint-native-build-receipt-v1"


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()


def fail(error):
    return {"type": type(error).__name__, "message": str(error)[:4096]}


class Packet:
    """Refuse writes beyond a packet cap; existing child outputs count too."""

    def __init__(self, root):
        self.root = root

    def retained(self):
        total = 0
        for path in self.root.rglob("*"):
            require(not path.is_symlink(), "Unexpected symlink in owned packet")
            if path.is_file():
                total += path.stat().st_size
        require(total <= RETENTION_LIMIT, "Total retained packet exceeds 20 MiB")
        return total

    def write(self, path, data):
        require(path.is_relative_to(self.root), "Output escaped owned packet")
        previous = path.stat().st_size if path.exists() else 0
        require(self.retained() - previous + len(data) <= RETENTION_LIMIT,
                "Packet retention cap would be exceeded")
        with path.open("wb") as stream:
            stream.write(data)

    def save(self, path, value):
        self.write(path, json.dumps(value, indent=2, sort_keys=True, allow_nan=False).encode() + b"\n")


class BoundedProcesses:
    """Live concurrent capture; never Process.communicate() or post-hoc truncation.

    A shielded spawn task is registered BEFORE awaiting process creation. Even
    cancellation at creation leaves a provisional lease for close() to resolve.
    Verified Linux session identity gates group signals; the retained child
    handle is the only allowed fallback when group identity is unresolved.
    """

    def __init__(self, helper, packet, repo, deadline):
        self.helper, self.packet, self.repo, self.deadline = helper, packet, repo, deadline
        self.active, self.pending, self.history = {}, {}, []

    def adopt(self, proc, prefix, argv):
        lease = {"pid": proc.pid, "start_ticks": None, "inspection_error": None,
                 "cleanup_confirmed": False, "provisional_spawn": False}
        entry = {"proc": proc, "lease": lease, "prefix": prefix, "readers": [],
                 "argv": list(map(str, argv)), "stdout": bytearray(), "stderr": bytearray(), "failures": []}
        self.active[proc.pid] = entry
        try:
            record = self.helper.process_stat(proc.pid)
            if record is not None:
                require(record["pgid"] == proc.pid and record["session"] == proc.pid,
                        "New child session identity was not verified")
                lease["start_ticks"] = record["start_ticks"]
        except BaseException as error:
            lease["inspection_error"] = str(error)
            entry["failures"].append(fail(error))
        # Start readers immediately after provisional custody, before other await.
        for name, stream, cap in (("stdout", proc.stdout, STDOUT_LIMIT), ("stderr", proc.stderr, STDERR_LIMIT)):
            entry["readers"].append(asyncio.create_task(self.read_pipe(entry, name, stream, cap)))
        return entry

    async def read_pipe(self, entry, name, stream, cap):
        while True:
            # One extra byte is enough to identify overflow; do not retain it.
            remaining = cap - len(entry[name])
            data = await stream.read(min(16384, remaining + 1))
            if not data:
                return
            if len(data) > remaining:
                entry[name].extend(data[:remaining])
                raise RuntimeError(f"Live {name} limit exceeded ({cap} bytes)")
            entry[name].extend(data)
            self.packet.retained()

    async def run(self, argv, prefix, env=None, require_success=True, external_output_limit=65536):
        require(not self.active and not self.pending, "Previous process custody remains unresolved")
        # Workers enforce the direct JSON-file limit BEFORE writing; OpenSSL's
        # owned RSA-2048 fixture files have a separate conservative allowance.
        # No other driver disk writes occur while run() is awaiting a child.
        # Keep two MiB free for final custody/error/peer receipts as well.
        require(self.packet.retained() + external_output_limit + STDOUT_LIMIT + STDERR_LIMIT + 2097152
                <= RETENTION_LIMIT, "Insufficient pre-reserved packet capacity for bounded child outputs")
        remaining = self.deadline - time.monotonic()
        require(remaining > 0, "Outer 300-second budget exhausted")
        spawn = asyncio.create_task(asyncio.create_subprocess_exec(
            *map(str, argv), cwd=self.repo, env=env, start_new_session=True,
            umask=0o077, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE,
            limit=16384,
        ))
        self.pending[spawn] = {"prefix": prefix, "argv": list(map(str, argv)), "provisional_spawn": True}
        entry = None
        error = None
        try:
            proc = await asyncio.shield(spawn)
            entry = self.adopt(proc, prefix, argv)
            self.pending.pop(spawn)
            require(entry["lease"]["inspection_error"] is None, "Child group identity inspection failed")
            waiter = asyncio.create_task(proc.wait())
            entry["waiter"] = waiter
            # gather propagates a live pipe overflow immediately, rather than
            # waiting for process exit. The stream buffers remain capped too.
            joined = asyncio.gather(waiter, *entry["readers"])
            entry["joined"] = joined
            await asyncio.wait_for(asyncio.shield(joined), min(WORKER_SECONDS, remaining))
        except BaseException as caught:
            error = caught
        finally:
            if entry is not None:
                if error is not None:
                    entry["failures"].append(fail(error))
                try:
                    await self.finish(entry)
                except BaseException as cleanup:
                    entry["failures"].append(fail(cleanup))
                    if error is None:
                        error = cleanup
        if error is not None:
            raise error
        require(entry is not None, "Spawn custody did not produce a child handle")
        if require_success:
            require(entry["proc"].returncode == 0, "Owned process failed; raw output and lease retained")
        return bytes(entry["stdout"]), entry["proc"].returncode

    async def finish(self, entry):
        failures = []
        try:
            await asyncio.wait_for(self.helper.terminate_owned(entry["proc"], entry["lease"]), 9)
        except BaseException as error:
            failures.append(fail(error))
        # Attempt each reader independently; one bad pipe never skips the other.
        for reader in entry["readers"]:
            try:
                await asyncio.wait_for(asyncio.shield(reader), 1)
            except BaseException as error:
                failures.append(fail(error))
                reader.cancel()
        joined = entry.get("joined")
        if joined is not None:
            # Retrieve gather failures, including cap failures, without leaking
            # a background exception or replacing the original raw failure.
            if not joined.done():
                joined.cancel()
            try:
                await joined
            except BaseException as error:
                failures.append(fail(error))
        entry["failures"].extend(failures)
        record = {"lease": entry["lease"], "returncode": entry["proc"].returncode,
                  "argv": entry["argv"], "failures": entry["failures"],
                  "stdout_bytes": len(entry["stdout"]), "stderr_bytes": len(entry["stderr"]),
                  "capture": "live_cap_not_postwrite_truncation"}
        self.history.append(record)
        for suffix, content in ((".stdout", bytes(entry["stdout"])), (".stderr", bytes(entry["stderr"])),
                                (".process.json", canonical(record) + b"\n")):
            try:
                self.packet.write(entry["prefix"].with_suffix(suffix), content)
            except BaseException as error:
                failures.append(fail(error))
        if entry["lease"]["cleanup_confirmed"]:
            self.active.pop(entry["proc"].pid, None)
        require(not failures, "Process capture/cleanup failure retained: " + str(failures))

    async def close(self):
        failures = []
        for spawn, provisional in list(self.pending.items()):
            try:
                proc = await asyncio.wait_for(asyncio.shield(spawn), 1)
                self.adopt(proc, provisional["prefix"], provisional["argv"])
                self.pending.pop(spawn)
            except BaseException as error:
                failures.append({"provisional": provisional, **fail(error)})
        for entry in list(self.active.values()):
            try:
                await self.finish(entry)
            except BaseException as error:
                failures.append(fail(error))
        require(not failures and not self.active and not self.pending,
                "Unresolved child/group/provisional custody: " + str(failures))


class WitnessProtocol(asyncio.StreamReaderProtocol):
    """Witness local connection_lost plus the owned socket's closed descriptor.

    This does not claim a peer close_notify acknowledgement, a FIN/RST packet,
    or client EOF visibility. The client may still discover EOF later.
    """

    def __init__(self, peer, role):
        self.peer, self.role = peer, role
        self.local_lost = asyncio.get_running_loop().create_future()
        self.owned_socket = None
        super().__init__(asyncio.StreamReader(limit=1024), self.connected)

    def connection_made(self, transport):
        self.owned_socket = transport.get_extra_info("socket")
        super().connection_made(transport)

    def connected(self, reader, writer):
        self.peer.accept(self.role, reader, writer, self)

    def connection_lost(self, exc):
        try:
            super().connection_lost(exc)
        finally:
            if not self.local_lost.done():
                self.local_lost.set_result({"exception": None if exc is None else type(exc).__name__,
                                           "monotonic_ns": time.monotonic_ns()})

    async def closed_witness(self):
        evidence = await asyncio.shield(self.local_lost)
        # A selector transport closes its socket after its protocol callback
        # returns. One scheduled turn lets that callback finish, without sleep
        # or a dependency on client TLS shutdown acknowledgement.
        turn = asyncio.get_running_loop().create_future()
        asyncio.get_running_loop().call_soon(turn.set_result, None)
        await turn
        require(self.owned_socket is not None and self.owned_socket.fileno() == -1,
                "Local connection_lost lacks an owned socket-closed witness")
        return {**evidence, "owned_socket_fileno": -1,
                "meaning": "local_transport_closed_not_peer_tls_shutdown_ack"}


def deep_bytes(value):
    if isinstance(value, dict):
        return sys.getsizeof(value) + sum(deep_bytes(k) + deep_bytes(v) for k, v in value.items())
    if isinstance(value, (list, tuple)):
        return sys.getsizeof(value) + sum(deep_bytes(v) for v in value)
    return sys.getsizeof(value)


class OwnedListener:
    """Own raw accepts before TLS handoff, including failed handshakes."""

    def __init__(self, peer, role, context):
        self.peer, self.role, self.context = peer, role, context
        self.socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        try:
            self.socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            self.socket.bind(("127.0.0.1", 0))
            self.socket.listen(MAX_CONNECTIONS)
            self.socket.setblocking(False)
        except BaseException:
            self.socket.close()
            raise
        self.sockets = [self.socket]
        self.accept_task = asyncio.create_task(self.accept_loop())
        self.peer.listener_tasks.add(self.accept_task)
        self.accept_task.add_done_callback(self.peer.listener_tasks.discard)

    async def accept_loop(self):
        loop = asyncio.get_running_loop()
        while True:
            owned = None
            try:
                owned, address = await loop.sock_accept(self.socket)
                require(address[0] == "127.0.0.1" and self.peer.trial is not None,
                        "Raw connection outside loopback owned trial")
                require(self.peer.admitted < MAX_CONNECTIONS
                        and self.peer.live_tasks() < MAX_TASKS,
                        "Raw accepted connection/task cap exceeded")
                self.peer.admitted += 1
                self.peer.event(None, "raw_loopback_accept", accepted_role=self.role,
                                accepted_ordinal=self.peer.admitted)
                # Provisional raw-socket custody exists before any TLS await.
                self.peer.raw_sockets.add(owned)
                task = asyncio.create_task(self.handoff(owned))
                self.peer.handoffs.add(task)
                task.add_done_callback(self.peer.handoffs.discard)
                owned = None
            except asyncio.CancelledError:
                if owned is not None:
                    owned.close()
                raise
            except BaseException as error:
                if owned is not None:
                    owned.close()
                self.peer.errors.append({"trial": self.peer.trial, "role": self.role, **fail(error)})
                # A raw listener fault is sticky INVALID; stop further accepts.
                return

    async def handoff(self, owned):
        protocol = None
        try:
            def factory():
                nonlocal protocol
                protocol = WitnessProtocol(self.peer, self.role)
                self.peer.protocols.add(protocol)
                return protocol
            await asyncio.get_running_loop().connect_accepted_socket(
                factory, owned, ssl=self.context, ssl_handshake_timeout=5, ssl_shutdown_timeout=1)
            # Transport now owns the same socket object; peer protocol custody
            # and its local connection_lost/socket witness cover its lifetime.
            self.peer.raw_sockets.discard(owned)
        except BaseException as error:
            # connect_accepted_socket closes its created transport on failure.
            # Also close the provisional owned socket handle independently.
            owned.close()
            if owned.fileno() == -1:
                self.peer.raw_sockets.discard(owned)
            if protocol is not None and protocol.owned_socket is None:
                # App protocol never received connection_made on TLS failure;
                # the original accepted socket's -1 descriptor is the witness.
                self.peer.protocols.discard(protocol)
            if not isinstance(error, asyncio.CancelledError):
                self.peer.errors.append({"trial": self.peer.trial, "role": self.role,
                                         "stage": "TLS_handoff", **fail(error)})

    def close(self):
        self.socket.close()
        self.accept_task.cancel()

    async def wait_closed(self):
        await asyncio.gather(self.accept_task, return_exceptions=True)
        require(self.socket.fileno() == -1, "Listener socket remained open")

    def is_serving(self):
        return self.socket.fileno() != -1 and not self.accept_task.done()


class IdlePeer:
    def __init__(self, helper):
        self.helper = helper
        self.events, self.records, self.errors = [], [], []
        self.event_bytes = 0
        self.handlers, self.writers, self.protocols = set(), set(), set()
        self.handoffs, self.raw_sockets = set(), set()
        self.listener_tasks = set()
        self.trial = self.case = self.arm = None
        self.articles, self.responses = None, {}
        self.start_index = 0
        self.admitted = 0
        self.old_profile = self.old_article = None
        self.barrier = None
        self.trigger_count = 0

    def event(self, record, kind, **values):
        event = {"trial": self.trial, "connection": None if record is None else record["connection"],
                 "role": None if record is None else record["role"], "kind": kind,
                 "monotonic_ns": time.monotonic_ns(), **values}
        size = deep_bytes(event) + 64
        require(len(self.events) < MAX_EVENTS and self.event_bytes + size <= PEER_MEMORY,
                "Peer event count or memory cap exhausted")
        self.event_bytes += size
        self.events.append(event)
        return event["monotonic_ns"]

    def live_tasks(self):
        return len(self.handlers) + len(self.handoffs) + len(self.listener_tasks)

    def prepare(self, trial, case, arm, articles):
        require(not self.handlers and not self.writers and not self.handoffs and not self.raw_sockets,
                "Previous peer trial remains active")
        self.trial, self.case, self.arm, self.articles = trial, case, arm, articles
        self.start_index = len(self.records)
        self.admitted = 0
        self.old_profile = self.old_article = self.barrier = None
        self.trigger_count = 0
        # Precompute all wire payloads/hashes before any collection.
        shim = self.helper.Peer(None)
        shim.articles = articles
        self.responses = {(role, command): shim.response(command, role)
                          for role in self.helper.ROLES for command in self.helper.expected_commands()}

    def accept(self, role, reader, writer, protocol):
        self.protocols.add(protocol)
        if self.live_tasks() >= MAX_TASKS:
            self.errors.append({"trial": self.trial, "role": role, "error": "peer_admission_cap"})
            writer.transport.abort()
            return
        task = asyncio.create_task(self.handle(role, reader, writer, protocol))
        self.handlers.add(task)
        task.add_done_callback(self.handlers.discard)

    async def send(self, writer, record, data):
        record["sent_bytes"] += len(data)
        for start in range(0, len(data), 65536):
            writer.write(data[start:start + 65536])
            await asyncio.wait_for(writer.drain(), WORKER_SECONDS)

    async def fault_barrier(self, primary, command):
        first = "OVER <sample-part1@fixture.invalid>"
        second = "OVER <sample-part2@fixture.invalid>"
        expected = first if self.case == "close_after_profile" else second
        if self.case == "healthy" or command != expected:
            return
        self.trigger_count += 1
        require(self.trigger_count == 1, "Repeated fault trigger")
        old = self.old_profile if self.case == "close_after_profile" else self.old_article
        require(old is not None and old["role"] == "alternate" and old["trial"] == self.trial,
                "Missing identity-pinned old alternate transport")
        identity = {key: old[key] for key in ("connection", "trial", "role")}
        require(old in self.records[self.start_index:], "Old alternate escaped current trial")
        self.event(primary, "primary_response_held", command=command, target=identity,
                   proves="prior_alternate_result_consumed")
        self.barrier = {"trigger": command, "target": identity, "started_ns": time.monotonic_ns(),
                        "baseline_already_closed": old["protocol"].local_lost.done()}
        if old["protocol"].local_lost.done():
            require(self.arm == "baseline", "Candidate old alternate closed before injected fault")
            self.event(old, "baseline_old_alternate_already_closed")
        else:
            old["fault_abort_requested"] = True
            self.event(old, "old_alternate_transport_abort_requested")
            old["writer"].transport.abort()
        # Never select a newly accepted replacement; only the pinned OLD record.
        witness = await asyncio.wait_for(old["protocol"].closed_witness(), 2)
        require(time.monotonic_ns() - self.barrier["started_ns"] <= 2_000_000_000,
                "Old alternate close-witness deadline exceeded")
        self.barrier["witness"] = witness
        self.barrier["released_ns"] = self.event(primary, "primary_response_released", target=identity)

    async def handle(self, role, reader, writer, protocol):
        self.writers.add(writer)
        record = None
        try:
            require(self.trial is not None and self.articles is not None, "Connection outside owned trial")
            tls = writer.get_extra_info("ssl_object")
            require(tls is not None, "Non-TLS peer stream")
            record = {"connection": len(self.records), "role": role, "trial": self.trial,
                      "commands": [], "auth_count": 0, "user_count": 0, "sent_bytes": 0,
                      "listener_port": writer.get_extra_info("sockname")[1],
                      "close_ns": None, "fault_abort_requested": False, "writer": writer, "protocol": protocol}
            self.records.append(record)
            self.event(record, "tls_callback_post_handshake", tls_version=tls.version())
            writer.transport.set_write_buffer_limits(high=65536, low=16384)
            await self.send(writer, record, b"200 synthetic fixture ready\r\n")
            for command, response, kind in (
                (f"AUTHINFO user local-{role}", b"381 password required\r\n", "auth_user"),
                (f"AUTHINFO pass fixture-{role}", b"281 authentication accepted\r\n", "auth_success"),
            ):
                line = await asyncio.wait_for(reader.readline(), WORKER_SECONDS)
                require(line == command.encode() + b"\r\n", "Unexpected local authentication/order")
                record["user_count" if kind == "auth_user" else "auth_count"] += 1
                self.event(record, kind)
                await self.send(writer, record, response)
            while True:
                line = await asyncio.wait_for(reader.readline(), WORKER_SECONDS)
                if not line:
                    self.event(record, "peer_observed_eof")
                    break
                require(len(line) <= 512 and line.endswith(b"\r\n"), "Malformed or oversized command")
                command = line[:-2].decode("ascii")
                require(len(record["commands"]) < 8 and (role, command) in self.responses,
                        "Unexpected or extra fingerprint command")
                record["commands"].append(command)
                self.event(record, "server_observed_command", command=command)
                if role == "primary":
                    await self.fault_barrier(record, command)
                await self.send(writer, record, self.responses[role, command])
                if role == "alternate" and command == "LIST OVERVIEW.FMT":
                    require(self.old_profile is None, "Alternate profile replay/hidden retry")
                    self.old_profile = record
                if role == "alternate" and command == "BODY <sample-part1@fixture.invalid>":
                    require(self.old_article is None, "Alternate first article replay/hidden retry")
                    self.old_article = record
        except BaseException as error:
            expected_abort = (record is not None and record["fault_abort_requested"]
                              and isinstance(error, (ConnectionResetError, BrokenPipeError)))
            if expected_abort:
                self.event(record, "local_abort_reader_completion", exception=type(error).__name__)
            elif not isinstance(error, asyncio.CancelledError):
                retained = {"trial": self.trial, "role": role, **fail(error)}
                self.errors.append(retained)
                try:
                    self.event(record, "unexpected_peer_error", error=retained)
                except BaseException:
                    pass
        finally:
            if record is not None:
                try:
                    record["close_ns"] = self.event(record, "peer_handler_exit")
                except BaseException as error:
                    self.errors.append({"trial": self.trial, **fail(error)})
            # Abrupt close is deliberate for bounded local cleanup. Do not wait
            # for TLS shutdown acknowledgements from an already dead client.
            writer.transport.abort()
            self.writers.discard(writer)

    async def settle(self):
        if self.handoffs:
            await asyncio.wait_for(asyncio.gather(*list(self.handoffs)), 6)
        if self.handlers:
            await asyncio.wait_for(asyncio.gather(*list(self.handlers)), 3)
        for protocol in list(self.protocols):
            await asyncio.wait_for(protocol.closed_witness(), 2)
        self.protocols.clear()
        require(not self.handlers and not self.writers, "Peer streams remain live")

    async def close(self):
        failures = []
        for task in list(self.handoffs):
            task.cancel()
        if self.handoffs:
            try:
                await asyncio.wait_for(asyncio.gather(*list(self.handoffs), return_exceptions=True), 1)
            except BaseException as error:
                failures.append(fail(error))
        for owned in list(self.raw_sockets):
            try:
                owned.close()
                require(owned.fileno() == -1, "Accepted socket remained open")
                self.raw_sockets.discard(owned)
            except BaseException as error:
                failures.append(fail(error))
        for writer in list(self.writers):
            try:
                writer.transport.abort()
            except BaseException as error:
                failures.append(fail(error))
        for task in list(self.handlers):
            task.cancel()
        if self.handlers:
            try:
                await asyncio.wait_for(asyncio.gather(*list(self.handlers), return_exceptions=True), 2)
            except BaseException as error:
                failures.append(fail(error))
        for protocol in list(self.protocols):
            try:
                await asyncio.wait_for(protocol.closed_witness(), 1)
                self.protocols.discard(protocol)
            except BaseException as error:
                failures.append(fail(error))
        require(not failures and not self.handlers and not self.writers and not self.protocols
                and not self.handoffs and not self.raw_sockets,
                "Unresolved peer custody: " + str(failures))
        # Retain bounded scalar evidence, not closed TLS/reader object graphs
        # from earlier trials. Unresolved resources above keep their handles.
        for record in self.records:
            record.pop("writer", None)
            record.pop("protocol", None)

    def snapshot(self):
        return [{key: value for key, value in record.items() if key not in ("writer", "protocol")}
                for record in self.records]

    def validate_fixture(self):
        require(not [error for error in self.errors if error.get("trial") == self.trial],
                "Unexpected peer failures make this fixture INVALID")
        records = self.records[self.start_index:]
        require(1 <= len(records) <= MAX_CONNECTIONS, "Invalid peer admission count")
        require(sum(record["role"] == "primary" for record in records) == 1,
                "Primary replacement/retry is forbidden")
        require(all(record["auth_count"] == record["user_count"] == 1 for record in records),
                "Incomplete or repeated authentication")
        for record in records:
            require(record["fault_abort_requested"] or any(
                event["kind"] == "peer_observed_eof" and event["connection"] == record["connection"]
                for event in self.events), "Missing bounded eventual peer EOF for an un-aborted stream")
        if self.case != "healthy":
            require(self.trigger_count == 1 and self.barrier is not None
                    and "witness" in self.barrier and "released_ns" in self.barrier,
                    "Missing trigger/identity/local close witness/deadline")
        return records


def source_map(helper, repo):
    native = sorted(path.relative_to(repo).as_posix() for path in (repo / NATIVE_ROOT).rglob("*")
                    if path.is_file() and "target" not in path.relative_to(repo / NATIVE_ROOT).parts
                    and (path.suffix == ".rs" or path.name in ("Cargo.toml", "Cargo.lock", "build.rs")))
    require(native and f"{NATIVE_ROOT}/Cargo.lock" in native, "Current native Rust source/lock tree missing")
    names = sorted(set(helper.SOURCE_FILES) | set(native) | {DRIVER, TRACE_HELPER})
    return {name: digest((repo / name).read_bytes()) for name in names}, native


def current_head(repo):
    # Reading Git metadata avoids launching a utility before provenance checks.
    git = repo / ".git"
    gitdir = git if git.is_dir() else (repo / git.read_text().strip().removeprefix("gitdir: ")).resolve()
    head = (gitdir / "HEAD").read_text().strip()
    if head.startswith("ref: "):
        name = head[5:]
        common_file = gitdir / "commondir"
        common = (gitdir / common_file.read_text().strip()).resolve() if common_file.exists() else gitdir
        for root in (gitdir, common):
            ref = root / name
            if ref.is_file():
                head = ref.read_text().strip()
                break
        else:
            matches = [line.split()[0] for line in (common / "packed-refs").read_text().splitlines()
                       if line and not line.startswith(("#", "^")) and line.split()[1] == name]
            require(len(matches) == 1, "Cannot resolve current checkout HEAD")
            head = matches[0]
    require(len(head) == 40 and all(c in "0123456789abcdef" for c in head), "Unknown checkout HEAD")
    return head


def native_provenance(path, library, repo, frozen, native, head):
    require(path.is_file() and path.stat().st_size <= 131072, "Missing/oversized native build receipt")
    receipt = json.loads(path.read_text())
    require(receipt.get("schema") == RECEIPT_SCHEMA, "Unknown native receipt schema")
    build_head = receipt.get("build_head")
    require(isinstance(build_head, str) and len(build_head) == 40
            and all(c in "0123456789abcdef" for c in build_head), "Unknown native build checkout HEAD")
    expected = {name: frozen[name] for name in native}
    require(receipt.get("source_files") == expected, "Native receipt source-file tree differs from current Rust tree")
    require(receipt.get("source_tree_sha256") == digest(canonical(expected)), "Native source-tree digest mismatch")
    require(receipt.get("cargo_lock_sha256") == expected[f"{NATIVE_ROOT}/Cargo.lock"], "Native Cargo.lock receipt mismatch")
    require(library.is_file() and 64 <= library.stat().st_size <= 134217728,
            "Missing/oversized native artifact")
    elf = library.read_bytes()
    require(len(elf) >= 64 and elf[:6] == b"\x7fELF\x02\x01"
            and struct.unpack_from("<H", elf, 18)[0] == 183, "Native artifact is not ELF64 little-endian AArch64")
    require(receipt.get("library_sha256") == digest(elf), "Native library hash differs from build receipt")
    require(receipt.get("profile") == "release" and receipt.get("isa") == "aarch64"
            and receipt.get("target") in ("aarch64-unknown-linux-gnu", "aarch64-unknown-linux-musl")
            and receipt.get("artifact_format") == "ELF64-LE-AARCH64", "Unknown profile/target/ISA/artifact provenance")
    for key in ("rustc_version", "cargo_version", "abi", "target_cpu"):
        require(isinstance(receipt.get(key), str) and receipt[key].strip()
                and receipt[key].lower() not in ("unknown", "unspecified", "n/a"), "Missing native provenance: " + key)
    require(isinstance(receipt.get("target_features"), list)
            and all(isinstance(item, str) and item for item in receipt["target_features"]), "Missing explicit ISA features")
    for key in ("rustflags", "cargo_features"):
        require(isinstance(receipt.get(key), list)
                and all(isinstance(item, str) and item for item in receipt[key]), "Missing explicit native build pin: " + key)
    require(isinstance(receipt.get("build_command"), list) and receipt["build_command"]
            and all(isinstance(item, str) and item for item in receipt["build_command"]), "Missing exact native build command")
    return {"receipt": receipt, "executed_head": head,
            "native_source_match": "exact_current_complete_source_map_not_full_PHP_HEAD_equality",
            "receipt_sha256": digest(path.read_bytes()), "library_sha256": digest(elf),
            "claim": "receipt_consistency_and_ELF_identity_only_not_ABI_certification"}


def normalize_report(report):
    """Preserve everything except checked observation retrieval-duration leaves."""
    normalized = copy.deepcopy(report)
    if isinstance(normalized, dict) and isinstance(normalized.get("data"), dict):
        observations = normalized["data"].get("article_observations", [])
        require(isinstance(observations, list), "Malformed raw article observations")
        for item in observations:
            require(isinstance(item, dict), "Malformed article observation")
            if "retrieval_duration_ms" in item:
                duration = item["retrieval_duration_ms"]
                require(type(duration) in (int, float) and math.isfinite(duration) and duration >= 0,
                        "Invalid checked retrieval duration leaf")
                del item["retrieval_duration_ms"]
    return normalized


def load_helper(repo):
    spec = importlib.util.spec_from_file_location("fingerprint_tls_source_helpers", repo / "scripts/benchmark-fingerprint-tls.py")
    require(spec is not None and spec.loader is not None, "Missing benchmark source helper")
    helper = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(helper)
    return helper


# Trace schema/oracle is below. It deliberately separates server observations
# from client attempt/EOF/admission evidence; a failed write need not reach peer.
def trace_oracle(raw, records, helper, arm, case):
    trace = raw.get("trace")
    require(isinstance(trace, dict) and trace.get("infrastructure_failure") is None,
            "Missing trace or trace infrastructure failure")
    events = trace.get("events")
    require(isinstance(events, list) and 0 < len(events) <= 256,
            "Missing/oversized client call-through trace")
    require(trace.get("max_events") == 256 and trace.get("max_serialized_bytes") == 65536
            and trace.get("max_command_bytes") == 256
            and type(trace.get("serialized_bytes")) is int and 0 < trace["serialized_bytes"] <= 65536,
            "Unknown client trace bounds")
    require(len(canonical(trace)) <= 65536, "Actual serialized client trace exceeds declared cap")
    previous_ns = -1
    for sequence, event in enumerate(events):
        require(isinstance(event, dict) and event.get("sequence") == sequence + 1
                and type(event.get("monotonic_ns")) is int and event["monotonic_ns"] >= previous_ns
                and type(event.get("client_id")) is int
                and event.get("client_role") in helper.ROLES and isinstance(event.get("event"), str),
                "Malformed, missing, or unordered call-through trace")
        previous_ns = event["monotonic_ns"]
        require(event.get("socket_id") is None or type(event.get("socket_id")) is int, "Malformed socket identity")
    differences = []

    def check(condition, message):
        if not condition:
            differences.append(message)

    attempts = [event for event in events if event["event"] == "command_attempt"]
    for event in attempts:
        require(isinstance(event.get("command"), str) and len(event["command"].encode()) <= 256,
                "Missing/oversized actual command attempt")
    fingerprint = [event for event in attempts if not event["command"].startswith("AUTHINFO ")]
    expected = helper.expected_commands()
    primary = [event["command"] for event in fingerprint if event["client_role"] == "primary"]
    alternate = [event["command"] for event in fingerprint if event["client_role"] == "alternate"]
    check(primary == expected, "Primary logical command sequence differs (replacement/retry/order forbidden)")
    connects = [event for event in events if event["event"] == "connect_enter"]
    completions = [event for event in events if event["event"] == "connect_return"]
    check(len(connects) == len(completions) == len(records), "Actual client admissions differ from accepted/authenticated peers")
    check(all(event.get("result") is True for event in completions), "Actual connect admission did not return true")
    command_completions = [event for event in events if event["event"] in ("command_return", "command_throw")]
    require([(event["client_id"], event["command"]) for event in attempts]
            == [(event["client_id"], event.get("command")) for event in command_completions],
            "Actual command call-through trace is incomplete/unpaired")
    correlation = []
    for role in helper.ROLES:
        client = [event for event in connects if event["client_role"] == role]
        server = [record for record in records if record["role"] == role]
        check(len(client) == len(server), f"{role} client/peer connection count mismatch")
        for ordinal, (admission, observed) in enumerate(zip(client, server)):
            check(admission.get("host") == "127.0.0.1" and admission.get("encryption") in ("tls", "ssl")
                  and admission.get("port") == observed["listener_port"],
                  "Unexpected actual connect endpoint/transport")
            logical = [event["command"] for event in fingerprint if event["client_id"] == admission["client_id"]]
            # A client object can connect at most once in this contract. This
            # prevents resource-ID reuse from correlating a replacement to OLD.
            check(sum(event["client_id"] == admission["client_id"] for event in connects) == 1,
                  "Same client admitted twice (hidden reconnect)")
            check(observed["commands"] == logical[:len(observed["commands"])],
                  "Server-observed commands are not the matching attempted prefix")
            correlation.append({"role": role, "connect_ordinal": ordinal,
                                "client_id": admission["client_id"], "peer_connection": observed["connection"],
                                "attempted_commands": logical, "server_observed_commands": observed["commands"]})
    branch = "healthy"
    expected_alt_connections = 3 if arm == "baseline" else 1
    expected_alternate = expected
    expected_observed_alternate = expected
    if case != "healthy" and arm != "baseline":
        affected = 1 if case == "close_after_profile" else 2
        over = f"OVER <sample-part{affected}@fixture.invalid>"
        head = f"HEAD <sample-part{affected}@fixture.invalid>"
        alt_connects = [event for event in connects if event["client_role"] == "alternate"]
        require(alt_connects, "No identifiable old alternate admission")
        old_id = alt_connects[0]["client_id"]
        old_affected = [event for event in fingerprint if event["client_id"] == old_id and event["command"] == over]
        if old_affected:
            branch = "stale_failure_after_prior_alternate_reuse_admission"
            expected_alternate = [command for command in expected
                                  if command != f"BODY <sample-part{affected}@fixture.invalid>"]
            expected_observed_alternate = expected[:2] + expected[5:] if affected == 1 else expected[:5]
            expected_alt_connections = 2 if affected == 1 else 1
            check(any(event["client_id"] == old_id and event["command"] == head for event in fingerprint),
                  "Stale failed OVER did not follow existing HEAD failure path")
            # An observation here means a naturally exercised stale path; it
            # does not assume that abort forced a particular write result.
            check(not any(event["client_id"] == old_id and event["command"] == f"BODY <sample-part{affected}@fixture.invalid>"
                          for event in fingerprint), "Unexpected stale BODY attempt")
        else:
            branch = "known_eof_before_affected_operation_existing_replacement"
            expected_alt_connections = 2
            require(len(alt_connects) >= 2, "Missing replacement/actual EOF evidence; do not invent a branch")
            next_connect = alt_connects[1]
            previous_command = "LIST OVERVIEW.FMT" if affected == 1 else "BODY <sample-part1@fixture.invalid>"
            previous = [event["sequence"] for event in fingerprint
                        if event["client_id"] == old_id and event["command"] == previous_command]
            require(previous, "Missing prior old-alternate operation")
            known = [event for event in events if event["event"] == "is_connected_result"
                     and event["client_id"] == old_id and event.get("result") is False
                     and type(event.get("socket_id_before")) is int
                     and previous[-1] < event["sequence"] < next_connect["sequence"]]
            require(known, "Replacement without a witnessed actual known-EOF check is fixture INVALID")
    check(alternate == expected_alternate, "Alternate logical attempt order/count differs from declared conditional oracle")
    check([command for record in records if record["role"] == "alternate" for command in record["commands"]]
          == expected_observed_alternate, "Alternate server-observed commands differ from declared conditional oracle")
    check(sum(record["role"] == "alternate" for record in records) == expected_alt_connections,
          "Alternate admissions/auth count differs from declared conditional oracle")
    check(len(records) == expected_alt_connections + 1,
          "Total admissions differ from declared conditional oracle")
    for role in helper.ROLES:
        role_records = [record for record in records if record["role"] == role]
        if branch == "healthy" or branch.startswith("known_eof") or role == "primary":
            check([command for record in role_records for command in record["commands"]] == expected,
                  f"{role} server-observed ordered commands differ")
    # Every admitted connection has exactly one role-local authentication pair;
    # command retries and hidden reconnects cannot disappear in peer logs.
    for admission in connects:
        auth = [event["command"] for event in attempts if event["client_id"] == admission["client_id"]
                and event["command"].startswith("AUTHINFO ")]
        role = admission["client_role"]
        check(auth == [f"AUTHINFO user local-{role}", "AUTHINFO pass [redacted]"],
              "Authentication attempt sequence differs or contains hidden retry")
    markers = {name: [event for event in events if event["event"] == name] for name in (
        "transport_boundary_enter", "transport_boundary_return", "alternate_release_enter",
        "alternate_release_return", "scope_enter", "scope_return")}
    boundary_before, boundary_after = markers["transport_boundary_enter"], markers["transport_boundary_return"]
    check(len(boundary_before) == len(boundary_after) == 1,
          "Missing transport/post-loop boundary lifecycle evidence")
    if boundary_before and boundary_after:
        before, after = boundary_before[0], boundary_after[0]
        check(before["sequence"] < after["sequence"] and type(before["socket_id"]) is int
              and after["socket_id"] == before["socket_id"], "Caller-owned primary changed at transport boundary")
        check(not [event for event in fingerprint if event["sequence"] > before["sequence"]],
              "Fingerprint commands occurred after post-loop transport boundary")
        if arm == "candidate":
            release_before, release_after = markers["alternate_release_enter"], markers["alternate_release_return"]
            check(len(release_before) == len(release_after) == 1, "Missing terminal alternate-only release")
            if release_before and release_after:
                check(before["sequence"] < release_before[0]["sequence"] < release_after[0]["sequence"] < after["sequence"],
                      "Alternate terminal release did not finish before post-loop analysis")
                check(release_before[0]["socket_id"] == release_after[0]["socket_id"] == before["socket_id"],
                      "Terminal alternate release changed primary identity")
        else:
            check(not markers["alternate_release_enter"] and not markers["alternate_release_return"],
                  "Unexpected terminal release for held/legacy arm")
    if arm != "baseline":
        check(len(markers["scope_enter"]) == len(markers["scope_return"]) == 1,
              "Missing candidate scope lifetime evidence")
        if markers["scope_return"]:
            final_scope = markers["scope_return"][0]
            check(final_scope["socket_id"] is None, "Primary still open after candidate scope cleanup")
            if boundary_after:
                check(final_scope["sequence"] > boundary_after[0]["sequence"], "Scope ended before post-loop boundary")
    else:
        check(not markers["scope_enter"] and not markers["scope_return"], "Legacy arm unexpectedly used candidate scope")
        check([record["commands"] for record in records if record["role"] == "alternate"]
              == [expected[:2], expected[2:5], expected[5:]], "Legacy per-operation alternate transport lifetime differs")
    return {"conditional_branch": branch, "contract_matches": not differences, "differences": differences,
            "fingerprint_command_attempts": len(fingerprint), "connections": len(records),
            "authentication_successes": sum(record["auth_count"] for record in records),
            "client_peer_correlation": correlation,
            "closure_interpretation": "doQuit is logical only; C helper-return precedes post-loop marker plus bounded eventual local socket closure/peer EOF; B candidate closes at scope end; A per-operation connections"}


async def trials(args, packet, helper, repo, processes, peer, servers, manifest):
    php, openssl, ss_command = (shutil.which(name) for name in ("php", "openssl", "ss"))
    require(php and openssl and ss_command, "Existing PHP/OpenSSL/ss required; no installation permitted")
    context, ca = await helper.certificates(processes, packet.root / "certificates", openssl)
    manifest["public_certificate_sha256"] = {name: digest((packet.root / "certificates" / name).read_bytes())
                                               for name in ("ca.crt", "server.crt")}
    for role in helper.ROLES:
        server = OwnedListener(peer, role, context)
        servers.append(server)
    ports = {role: server.sockets[0].getsockname()[1] for role, server in zip(helper.ROLES, servers)}
    require(len(set(ports.values())) == 2 and all(1024 <= port <= 65535 for port in ports.values()),
            "Distinct nonprivileged loopback-only ports required")
    manifest["listener_ports"] = ports
    articles, xml = helper.fixture(65536)
    guid = digest(b"synthetic-fingerprint-idle-tls-65536")[:32]
    manifest["fixture"] = {"guid": guid, "nzb_xml_sha256": digest(xml),
                           "expected_payloads": {message: {"decoded_bytes": article["decoded_bytes"],
                               "decoded_sha256": article["decoded_sha256"], "raw_body_sha256": article["body_sha256"]}
                               for message, article in articles.items()}}
    env = {"PATH": os.pathsep.join(dict.fromkeys([str(Path(php).parent), str(Path(ss_command).parent), "/usr/bin", "/bin"])),
           "LANG": "C", "LC_ALL": "C", "TZ": "UTC"}
    manifest["status"] = "frozen_before_correctness_workers"
    packet.save(packet.root / "manifest.json", manifest)
    for case in CASES:
        baseline = None
        for arm in ARMS:
            require(not processes.active and not processes.pending and not peer.handlers and not peer.writers,
                    "Previous trial custody unresolved")
            frozen, _ = source_map(helper, repo)
            require(frozen == manifest["source_sha256"] and current_head(repo) == manifest["executed_head"]
                    and digest(args.library.read_bytes()) == manifest["native"]["library_sha256"]
                    and digest(args.native_receipt.read_bytes()) == manifest["native"]["receipt_sha256"],
                    "Frozen source/checkout/native/receipt drift invalidates packet")
            trial = f"{case}-{arm}"
            directory = packet.root / trial
            directory.mkdir(mode=0o700)
            nzb_root = directory / "nzb"
            nzb_hash = helper.write_nzb(nzb_root, guid, xml)
            config = {"purpose": "idle_tls_correctness", "arm": arm, "collections": 1,
                      "primary_port": ports["primary"], "alternate_port": ports["alternate"],
                      "ca_file": str(ca), "native_library": str(args.library), "nzb_root": str(nzb_root), "guid": guid,
                      "output_file": str(directory / "worker-output.json"), "expected_samples": 2,
                      "workload_id": "SYNTHETIC-idle-tls-65536-bytes-per-part",
                      "source_sha256": manifest["source_sha256"],
                      "native_library_sha256": manifest["native"]["library_sha256"],
                      "ca_file_sha256": digest(ca.read_bytes()), "nzb_sha256": nzb_hash,
                      "expected_payloads": manifest["fixture"]["expected_payloads"]}
            config_path = packet.root / f"{trial}-input.json"
            packet.save(config_path, config)
            require(config_path.stat().st_size <= 65536, "Worker input exceeds confined input cap")
            peer.prepare(trial, case, arm, articles)
            result = {"trial": trial, "case": case, "arm": arm, "status": "fixture_invalid", "failures": [],
                      "nzb_gzip_sha256": nzb_hash, "raw_output": f"{trial}/worker-output.json"}
            manifest["caller_workers_launched"] += 1
            require(manifest["caller_workers_launched"] <= 9, "Caller worker cap exceeded")
            try:
                stdout, returncode = await processes.run(
                    [php, "-d", "ffi.enable=true", repo / "scripts/benchmark-fingerprint-tls-worker.php", config_path],
                    directory / "worker", env, require_success=False, external_output_limit=STDOUT_LIMIT)
                result["worker_returncode"] = returncode
                await peer.settle()
                records = peer.validate_fixture()
                raw_path = directory / "worker-output.json"
                require(raw_path.is_file() and raw_path.stat().st_size <= STDOUT_LIMIT,
                        "Missing/oversized worker output (startup/native fault is INVALID)")
                raw = json.loads(raw_path.read_text())
                require(json.loads(stdout.decode("utf-8")) == raw, "Worker stdout/output result mismatch")
                require(returncode == 0 and raw.get("purpose") == "idle_tls_correctness"
                        and raw.get("validation_scope") == "caller_capture_not_parity"
                        and raw.get("preflight_completed") is True and raw.get("valid") is True
                        and raw.get("infrastructure_valid") is True and raw.get("collections") == 1
                        and raw.get("limits") == {"max_output_file_bytes": STDOUT_LIMIT, "max_captured_stdout_bytes": 65536},
                        "Worker setup/native/preflight/capture failure is INVALID, never candidate RED")
                require(raw["arm"] == arm and len(raw.get("observations", [])) == 1
                        and raw["observations"][0].get("phase") == "first_after_preflight", "Unexpected collection capture")
                preflight = raw.get("preflight", {})
                require(preflight.get("source_sha256") == config["source_sha256"]
                        and preflight.get("native_library_sha256") == config["native_library_sha256"]
                        and preflight.get("gzip_sha256") == nzb_hash
                        and preflight.get("ca_file_sha256") == config["ca_file_sha256"]
                        and preflight.get("expected_payloads") == config["expected_payloads"], "Worker preflight pins differ")
                result["trace_oracle"] = trace_oracle(raw, records, helper, arm, case)
                observation = raw["observations"][0]
                normalized = {"report": normalize_report(observation.get("report")), "failure": observation.get("failure")}
                packet.save(directory / "normalized-observation.json", normalized)
                result["normalized_observation_sha256"] = digest(canonical(normalized))
                if arm == "baseline":
                    # The fresh-client result is authoritative only if complete
                    # and payload-checked; no hand-authored candidate success.
                    helper.normalized_report(observation.get("report"), articles)
                    require(observation.get("failure") is None and result["trace_oracle"]["contract_matches"],
                            "Authoritative baseline caller/command contract is invalid")
                    baseline = normalized
                    result["status"] = "baseline_complete_authoritative"
                elif baseline is None:
                    result["failures"].append({"type": "InvalidBaseline", "message": "No complete authoritative baseline"})
                else:
                    result["whole_report_and_error_parity"] = normalized == baseline
                    result["status"] = ("correctness_green" if normalized == baseline and result["trace_oracle"]["contract_matches"]
                                        else "correctness_red")
                result["fault_barrier"] = peer.barrier
            except BaseException as error:
                result["failures"].append(fail(error))
                if isinstance(error, (asyncio.CancelledError, KeyboardInterrupt, SystemExit)):
                    raise
            finally:
                # Every trial owns its streams. Independent cleanup still runs
                # when parsing, provenance, the barrier or the oracle fails.
                try:
                    await peer.close()
                except BaseException as error:
                    result["status"] = "fixture_invalid"
                    result["failures"].append({"cleanup": fail(error)})
                frozen_after, _ = source_map(helper, repo)
                if (frozen_after != manifest["source_sha256"] or current_head(repo) != manifest["executed_head"]
                        or digest(args.library.read_bytes()) != manifest["native"]["library_sha256"]
                        or digest(args.native_receipt.read_bytes()) != manifest["native"]["receipt_sha256"]):
                    result["status"] = "fixture_invalid"
                    result["failures"].append({"type": "FrozenInputDrift", "message": "Source/native/receipt/checkout changed"})
                result["peer_errors"] = [error for error in peer.errors if error.get("trial") == trial]
                manifest["trials"].append(result)
                # No peer serialization or disk flush occurs during collection.
                packet.save(directory / "validated.json", result)
                packet.save(packet.root / "peer-events.json", peer.events)
                packet.save(packet.root / "peer-connections.json", peer.snapshot())
                packet.save(packet.root / "manifest.json", manifest)
                packet.retained()
            require(not processes.active and not processes.pending and not peer.handlers and not peer.writers,
                    "Unresolved trial custody prevents another worker")
            require(result["failures"] == [] or result["status"] == "fixture_invalid", "Unexpected result state")
    require(manifest["caller_workers_launched"] == 9, "Correctness packet did not capture exactly nine workers")
    states = [item["status"] for item in manifest["trials"]]
    manifest["status"] = ("fixture_invalid" if "fixture_invalid" in states else
                          "correctness_red" if "correctness_red" in states else "correctness_green")
    manifest["performance_stages_permitted"] = False


async def driver(args, packet):
    started = time.monotonic()
    require(platform.system() == "Linux" and platform.machine().lower() in ("aarch64", "arm64"),
            "This packet requires Linux AArch64; historical x86 is not accepted")
    repo = Path(__file__).resolve().parent.parent
    helper = load_helper(repo)
    require(helper.process_stat(os.getpid()) is not None, "Readable Linux process identity required")
    frozen, native = source_map(helper, repo)
    head = current_head(repo)
    provenance = native_provenance(args.native_receipt, args.library, repo, frozen, native, head)
    deadline = started + BODY_SECONDS
    processes = BoundedProcesses(helper, packet, repo, deadline)
    peer, servers = IdlePeer(helper), []
    manifest = {"schema": "fingerprint-idle-tls-correctness-v1", "status": "preparing",
                "source_preparation_is_not_launch_authorization": True,
                "purpose": "idle_tls_correctness", "validation_scope": "caller_capture_not_parity",
                "executed_head": head, "source_sha256": frozen, "native": provenance,
                "platform": platform.platform(), "python": sys.version, "python_openssl": ssl.OPENSSL_VERSION,
                "bounds": {"caller_workers": 9, "collections_per_worker": 1, "worker_seconds": WORKER_SECONDS,
                           "outer_seconds_including_cleanup_reserve": OUTER_SECONDS, "cleanup_seconds": CLEANUP_SECONDS,
                           "body_seconds": BODY_SECONDS, "inflight_cancellation_cleanup_reserve_seconds": 20,
                           "connections_per_worker": MAX_CONNECTIONS, "live_peer_tasks": MAX_TASKS,
                           "peer_events_total": MAX_EVENTS, "peer_event_memory_bytes": PEER_MEMORY,
                           "worker_stdout_bytes": STDOUT_LIMIT, "worker_stderr_bytes": STDERR_LIMIT,
                           "retained_packet_bytes": RETENTION_LIMIT},
                "cases": list(CASES), "arms": list(ARMS), "caller_workers_launched": 0, "trials": [],
                "resource_custody": helper.resource_custody(), "performance_stages_permitted": False,
                "limitations": ["Synthetic loopback correctness only; no production/provider/headroom/adoption claim",
                    "A uses a correctness-only call-through observer; this is not an uninstrumented performance baseline",
                    "Local transport/socket closure is not client EOF visibility, TLS close_notify acknowledgement, or FIN/RST classification",
                    "C release-return marker plus eventual peer EOF does not assert cross-process timestamp ordering",
                    "Native receipt consistency/ELF identity are not ABI certification; native probes cover exercised functions only",
                    "No ratios, speed comparison, retries, replacement policy changes, resampling, deployment, or new builds"]}
    cleanup_errors = []
    try:
        await asyncio.wait_for(trials(args, packet, helper, repo, processes, peer, servers, manifest),
                               max(0, deadline - time.monotonic()))
    except BaseException as error:
        manifest["status"] = "fixture_invalid"
        manifest["failure"] = fail(error)
    finally:
        async def cleanup():
            jobs = [("processes", processes.close()), ("peer", peer.close())]
            for server in servers:
                server.close()
                jobs.append(("listener", server.wait_closed()))
            # Independent cleanup attempts run together under one 15s reserve;
            # a failed process inspection never suppresses listener/key cleanup.
            tasks = [(name, asyncio.create_task(job)) for name, job in jobs]
            done, pending = await asyncio.wait([task for _, task in tasks], timeout=CLEANUP_SECONDS - 1)
            for name, task in tasks:
                if task in pending:
                    task.cancel()
                    cleanup_errors.append({"resource": name, "message": "Cleanup deadline; custody unresolved"})
                else:
                    try:
                        task.result()
                    except BaseException as error:
                        cleanup_errors.append({"resource": name, **fail(error)})
            if pending:
                await asyncio.gather(*pending, return_exceptions=True)
        try:
            await asyncio.wait_for(cleanup(), CLEANUP_SECONDS)
        except BaseException as error:
            cleanup_errors.append({"resource": "cleanup", **fail(error)})
        keys = [packet.root / "certificates" / name for name in ("ca.key", "server.key")]
        for key in keys:
            try:
                if key.is_file():
                    key.unlink()
            except BaseException as error:
                cleanup_errors.append({"resource": "owned_private_key", **fail(error)})
        manifest["cleanup"] = {"errors": cleanup_errors, "private_keys_removed": all(not key.exists() for key in keys),
                               "process_leases": [entry["lease"] for entry in processes.active.values()],
                               "provisional_spawns": list(processes.pending.values()),
                               "peer_tasks": len(peer.handlers), "peer_writers": len(peer.writers),
                               "peer_protocols": len(peer.protocols), "TLS_handoffs": len(peer.handoffs),
                               "listener_accept_tasks": len(peer.listener_tasks),
                               "raw_accepted_sockets": len(peer.raw_sockets),
                               "listeners_closed": all(not server.is_serving() for server in servers)}
        manifest["process_history"] = processes.history
        manifest["peer_errors"] = peer.errors
        try:
            after = helper.resource_custody()
            manifest["resource_custody_after"] = after
            require(after == manifest["resource_custody"], "Static resource custody drift")
            final_sources, _ = source_map(helper, repo)
            require(final_sources == frozen and current_head(repo) == head
                    and digest(args.library.read_bytes()) == provenance["library_sha256"]
                    and digest(args.native_receipt.read_bytes()) == provenance["receipt_sha256"],
                    "Source/checkout/native/receipt drift during final cleanup")
        except BaseException as error:
            cleanup_errors.append({"resource": "runtime_identity", **fail(error)})
        if (cleanup_errors or peer.errors or processes.active or processes.pending or peer.handlers or peer.writers or peer.protocols
                or peer.handoffs or peer.raw_sockets or peer.listener_tasks):
            manifest["status"] = "fixture_invalid"
        packet.save(packet.root / "peer-events.json", peer.events)
        packet.save(packet.root / "peer-connections.json", peer.snapshot())
        packet.save(packet.root / "manifest.json", manifest)
    return manifest["status"]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--library", required=True, type=Path)
    parser.add_argument("--native-receipt", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)
    args = parser.parse_args()
    args.library = args.library.resolve(strict=True)
    args.native_receipt = args.native_receipt.resolve(strict=True)
    require(args.library.is_file() and args.native_receipt.is_file(), "Library and receipt must be existing regular files")
    os.umask(0o077)
    output = args.output_dir.resolve()
    output.mkdir(mode=0o700, parents=False, exist_ok=False)
    require(output.stat().st_mode & 0o777 == 0o700, "Owned packet must have mode 0700")
    packet = Packet(output)
    try:
        status = asyncio.run(driver(args, packet))
    except BaseException as error:
        # Preflight rejection is retained, even before listeners/processes exist.
        packet.save(output / "runner-invalid.json", {"status": "fixture_invalid", "failure": fail(error),
                    "performance_stages_permitted": False,
                    "caller_workers_launched": "consult_manifest_if_created_otherwise_zero"})
        raise
    print(json.dumps({"status": status, "manifest": str(output / "manifest.json"),
                      "performance_stages_permitted": False}, sort_keys=True))
    return 0 if status == "correctness_green" else 1


if __name__ == "__main__":
    sys.exit(main())
