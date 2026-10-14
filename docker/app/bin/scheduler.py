#!/usr/bin/env python3
"""Own the scheduler's complete descendant lifetime, including detached jobs.

Only the unreaped schedule:work child receives TERM. Linux's subreaper contract
adopts orphaned background/nested workers, so ECHILD is the final drain barrier.
The private socket and lifetime flock replace PID/control files entirely.
"""
import argparse
import ctypes
import fcntl
import json
import math
import os
from pathlib import Path
import selectors
import signal
import socket
import struct
import subprocess
import sys
import time

SOCKET = Path('/var/tmp/nntmux/scheduler.sock')


class Supervisor:
    def __init__(self, command):
        self.command = command
        self.coordinator = None
        self.coordinator_stopping = False
        self.state = 'running'
        self.stopping = False
        self.exit_code = 0
        self.drain = None
        self.resume_waiters = []
        self.restore_paused = False
        self.restore_token = None
        self.clients = {}
        self.selector = selectors.DefaultSelector()

    def request_stop(self, *_):
        self.stopping = True

    def launch(self):
        # A signal received while Popen starts is observed before the next tick.
        if not self.stopping:
            self.coordinator = subprocess.Popen(self.command)
            self.coordinator_stopping = False

    def stop_coordinator(self):
        if self.coordinator is not None and not self.coordinator_stopping:
            # This PID remains our unreaped child, and cannot be reused.
            try:
                os.kill(self.coordinator.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            self.coordinator_stopping = True

    def reap(self):
        while True:
            try:
                pid, status = os.waitpid(-1, os.WNOHANG)
            except ChildProcessError:
                return False
            if pid == 0:
                return True
            if self.coordinator is not None and pid == self.coordinator.pid:
                self.coordinator.returncode = os.waitstatus_to_exitcode(status)
                if not self.coordinator_stopping:
                    self.exit_code = self.coordinator.returncode or 1
                    self.stopping = True
                self.coordinator = None

    def close(self, client):
        if client in self.clients:
            self.selector.unregister(client)
            del self.clients[client]
            client.close()

    def reply(self, client, ok, **body):
        try:
            client.sendall((json.dumps({'ok': ok, **body}) + '\n').encode())
            sent = True
        except OSError:
            sent = False
        self.close(client)
        return sent

    def abort_drain(self):
        if self.drain is not None:
            self.close(self.drain[0])
            self.drain = None
            if not self.stopping:
                self.state = 'running'

    def dispatch(self, client, request):
        action = request.get('action')
        if action == 'status':
            self.reap()
            self.reply(client, not self.stopping, state=self.state)
        elif action == 'check':
            has_children = self.reap()
            ok = self.state == 'paused' and not self.stopping and not has_children
            self.reply(client, ok, state=self.state)
        elif self.stopping:
            self.reply(client, False, error='scheduler is stopping')
        elif action == 'restore' and request.get('token') is not None and request.get('token') != self.restore_token:
            self.reply(client, True, state=self.state)
        elif action == 'restore' and self.restore_paused:
            self.reply(client, True, state=self.state)
        elif action in ('resume', 'restore'):
            self.restore_paused = False
            self.restore_token = None
            self.abort_drain()
            self.state = 'running'
            # A new coordinator cannot overlap the old coordinator. Reply only
            # after the new one has started, so deployment recovery can verify it.
            self.resume_waiters.append(client)
            self.clients[client]['pending'] = True
        elif action == 'drain':
            token = request.get('token')
            if token is not None and (not isinstance(token, str) or not token or len(token) > 128):
                self.reply(client, False, error='invalid drain token')
                return
            timeout = request.get('timeout')
            if not isinstance(timeout, (int, float)) or not math.isfinite(timeout) or timeout <= 0:
                self.reply(client, False, error='timeout must be positive and finite')
            elif self.state == 'paused':
                self.restore_paused = True
                self.restore_token = token
                self.reply(client, True, state='paused')
            elif self.drain is not None or self.resume_waiters:
                self.reply(client, False, error='another control operation is pending')
            else:
                self.restore_paused = False
                self.restore_token = token
                self.state = 'draining'
                self.drain = (client, time.monotonic() + timeout)
                self.clients[client]['pending'] = True
                self.stop_coordinator()
        else:
            self.reply(client, False, error='unknown control action')

    def read_client(self, client):
        entry = self.clients[client]
        try:
            chunk = client.recv(4096)
        except BlockingIOError:
            return
        except OSError:
            chunk = b''
        if not chunk:
            if self.drain is not None and self.drain[0] == client:
                self.abort_drain()
            else:
                self.close(client)
                self.resume_waiters = [item for item in self.resume_waiters if item != client]
            return
        if entry['pending']:
            return
        entry['buffer'] += chunk
        if len(entry['buffer']) > 4096:
            self.reply(client, False, error='request too large')
        elif b'\n' in entry['buffer']:
            try:
                request = json.loads(entry['buffer'].split(b'\n', 1)[0])
                if not isinstance(request, dict):
                    raise ValueError('request must be an object')
                self.dispatch(client, request)
            except (ValueError, TypeError):
                self.reply(client, False, error='invalid request')

    def serve(self):
        signal.signal(signal.SIGTERM, self.request_stop)
        signal.signal(signal.SIGINT, self.request_stop)
        signal.signal(signal.SIGQUIT, self.request_stop)
        libc = ctypes.CDLL(None, use_errno=True)
        if libc.prctl(36, 1, 0, 0, 0) != 0:  # PR_SET_CHILD_SUBREAPER
            raise OSError(ctypes.get_errno(), 'cannot become scheduler subreaper')
        SOCKET.parent.mkdir(parents=True, exist_ok=True)
        with open(str(SOCKET) + '.lock', 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            SOCKET.unlink(missing_ok=True)
            with socket.socket(socket.AF_UNIX) as server:
                server.bind(str(SOCKET))
                SOCKET.chmod(0o600)
                server.listen(16)
                server.setblocking(False)
                self.selector.register(server, selectors.EVENT_READ)
                try:
                    self.launch()
                    while True:
                        # Handle controls/disconnects before completing drain, so
                        # a dead requester does not leave admission paused.
                        for key, _ in self.selector.select(0.05):
                            if key.fileobj is server:
                                client, _ = server.accept()
                                _, uid, _ = struct.unpack('3i', client.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
                                if uid not in (os.getuid(), 0):
                                    client.close()
                                    continue
                                client.setblocking(False)
                                self.clients[client] = {'buffer': b'', 'pending': False, 'deadline': time.monotonic() + 5}
                                self.selector.register(client, selectors.EVENT_READ)
                            elif key.fileobj in self.clients:
                                self.read_client(key.fileobj)
                        has_children = self.reap()
                        if self.stopping:
                            self.state = 'stopping'
                            self.stop_coordinator()
                            self.abort_drain()
                            for client in self.resume_waiters:
                                self.reply(client, False, error='scheduler is stopping')
                            self.resume_waiters = []
                            if not has_children:
                                return self.exit_code
                        elif self.drain is not None:
                            client, deadline = self.drain
                            if not has_children:
                                self.state = 'paused'
                                self.drain = None
                                if not self.reply(client, True, state='paused'):
                                    self.state = 'running'
                            elif time.monotonic() >= deadline:
                                self.drain = None
                                self.state = 'running'
                                self.reply(client, False, error='scheduler drain timed out; admission restored')
                        if self.state == 'running' and not self.stopping:
                            if self.coordinator is None:
                                self.launch()
                            if not self.coordinator_stopping:
                                for client in self.resume_waiters:
                                    self.reply(client, True, state='running')
                                self.resume_waiters = []
                        for client, entry in list(self.clients.items()):
                            if not entry['pending'] and time.monotonic() >= entry['deadline']:
                                self.close(client)
                finally:
                    # Internal failures must also preserve already admitted work.
                    self.stopping = True
                    self.state = 'stopping'
                    self.stop_coordinator()
                    while self.reap():
                        time.sleep(0.05)
                    for client in list(self.clients):
                        self.close(client)
                    self.selector.close()
                    SOCKET.unlink(missing_ok=True)


def control(action, timeout, token):
    # Closing the socket on a signal lets the supervisor undo incomplete drain.
    def interrupted(signum, _):
        raise SystemExit(128 + signum)
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    with socket.socket(socket.AF_UNIX) as client:
        client.settimeout(timeout + 5 if action == 'drain' else 15)
        client.connect(str(SOCKET))
        client.sendall((json.dumps({'action': action, 'timeout': timeout, 'token': token}) + '\n').encode())
        response = b''
        while b'\n' not in response:
            chunk = client.recv(4096)
            if not chunk:
                raise RuntimeError('scheduler closed the control connection')
            response += chunk
        result = json.loads(response.split(b'\n', 1)[0])
        print(json.dumps(result), flush=True)
        return 0 if result.get('ok') else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', nargs='?', default='serve', choices=['serve', 'drain', 'resume', 'restore', 'status', 'check'])
    parser.add_argument('--timeout', type=float, default=1800)
    parser.add_argument('--token')
    args, command = parser.parse_known_args()
    if command and command[0] == '--':
        command = command[1:]
    if args.action == 'serve':
        return Supervisor(command or ['php', 'artisan', 'schedule:work']).serve()
    if command:
        parser.error('control commands do not accept a worker command')
    if not math.isfinite(args.timeout) or args.timeout <= 0:
        parser.error('--timeout must be positive and finite')
    return control(args.action, args.timeout, args.token)


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, RuntimeError) as error:
        print(f'scheduler: {error}', file=sys.stderr)
        sys.exit(1)
