#!/usr/bin/env python3
"""Stream into the dedicated old scheduler container; never signal finite work."""
import fcntl
import json
import os
from pathlib import Path
import signal
import sys
import time

DIRECTORY = Path('/var/tmp/nntmux')
STATE = DIRECTORY / 'legacy-scheduler-drain.json'
PROTOCOL = 'legacy-scheduler-stop-v1'
interrupted = False


def interrupt(_signal, _frame):
    global interrupted
    interrupted = True


def process(pid):
    try:
        # comm may contain spaces and parentheses; fields after its final ')' are fixed.
        fields = Path(f'/proc/{pid}/stat').read_text().rsplit(')', 1)[1].split()
        arguments = Path(f'/proc/{pid}/cmdline').read_bytes().split(b'\0')[:-1]
        return {'pid': pid, 'start': fields[19], 'state': fields[0], 'parent': int(fields[1]), 'args': arguments}
    except (FileNotFoundError, ProcessLookupError):
        return None


def processes(include_zombies=False):
    result = []
    for path in Path('/proc').iterdir():
        if path.name.isdecimal():
            item = process(int(path.name))
            if item and (include_zombies or item['state'] not in ('Z', 'X')):
                result.append(item)
    return result


def is_coordinator(item):
    arguments = item['args']
    return (len(arguments) >= 3 and os.path.basename(arguments[0]) == b'php'
            and arguments[1] in (b'artisan', b'/app/artisan')
            and arguments[2] == b'schedule:work'
            and os.readlink(f"/proc/{item['pid']}/cwd") == '/app')


def identity_matches(item, state):
    return item and item['pid'] == state['pid'] and item['start'] == state['start']


def descriptor(state):
    # The descriptor binds every signal to this exact process, even if its PID is reused.
    handle = os.pidfd_open(state['pid'])
    if not identity_matches(process(state['pid']), state):
        os.close(handle)
        raise RuntimeError('Legacy scheduler identity changed; refusing to signal a replacement.')
    return handle


def save(state):
    temporary = STATE.with_suffix('.tmp')
    with temporary.open('w', encoding='utf-8') as stream:
        json.dump(state, stream)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, STATE)
    handle = os.open(STATE.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(handle)
    finally:
        os.close(handle)


def verified_stop(state):
    if (state.get('protocol') != PROTOCOL
            or state.get('stop') not in ('confirmed', 'preserved')
            or type(state.get('initially_stopped')) is not bool
            or (state['stop'] == 'confirmed' and state['initially_stopped'])):
        raise RuntimeError('Legacy scheduler STOP is unconfirmed; preserving its admission hold.')


def restore(token=None):
    if not STATE.exists():
        return
    state = json.loads(STATE.read_text())
    if state.get('token') != token:
        return
    handle = descriptor(state)
    try:
        verified_stop(state)
        item = process(state['pid'])
        if not identity_matches(item, state) or item['state'] != 'T':
            raise RuntimeError('Legacy scheduler admission barrier was lost; refusing restoration.')
        if state['stop'] == 'confirmed':
            # Confirmed means successful STOP plus observed T before encoding;
            # it does not attest the final directory-fsync acknowledgment. Direct
            # competing signal authority is outside this helper's observable scope.
            signal.pidfd_send_signal(handle, signal.SIGCONT)
            deadline = time.monotonic() + 5
            while True:
                item = process(state['pid'])
                if not identity_matches(item, state) or item['state'] in ('Z', 'X'):
                    raise RuntimeError('Legacy scheduler identity exited during restoration; preserving its hold.')
                if item['state'] not in ('T', 't'):
                    break
                if time.monotonic() >= deadline:
                    raise RuntimeError('Legacy scheduler restoration was not observed; preserving its hold.')
                time.sleep(.02)
        STATE.unlink()
    finally:
        os.close(handle)


def active_work(state):
    verified_stop(state)
    coordinator = process(state['pid'])
    if not identity_matches(coordinator, state) or coordinator['state'] != 'T':
        raise RuntimeError('Legacy scheduler admission barrier was lost; refusing to stop containers.')
    # Detached Laravel background shells are reparented to PID 1. Descendant-only
    # inventories miss them, so inspect every process in this dedicated namespace.
    inventory = processes(include_zombies=True)
    workers = [item for item in inventory if item['pid'] not in (1, os.getpid(), state['pid'])
               and item['state'] not in ('Z', 'X')]
    if workers:
        return workers
    # /proc enumeration is not atomic: an unseen child can fork while its listed
    # parent exits. The cgroup's kernel task counter closes that empty-scan race.
    # Only zombies parented to the frozen coordinator are stable allowances;
    # PID 1 may reap its own zombies concurrently, so wait for those to disappear.
    allowed = [1, os.getpid(), state['pid']]
    allowed.extend(item['pid'] for item in inventory
                   if item['state'] == 'Z' and item['parent'] == state['pid'])
    expected = sum(len(list(Path(f'/proc/{pid}/task').iterdir())) for pid in allowed)
    counters = [Path('/sys/fs/cgroup/pids.current'), Path('/sys/fs/cgroup/pids/pids.current')]
    counter = next((path for path in counters if path.is_file()), None)
    if counter is None:
        raise RuntimeError('Cannot verify the dedicated scheduler cgroup task count; refusing to drain.')
    actual = int(counter.read_text())
    if actual < expected:
        raise RuntimeError('Scheduler cgroup task ownership could not be verified.')
    return [] if actual == expected else [{'pid': None}]


def drain(timeout, token=None):
    if STATE.exists():
        state = json.loads(STATE.read_text())
        if state.get('token') != token:
            raise RuntimeError('Another operation owns the legacy scheduler admission barrier.')
        active_work(state)
    else:
        candidates = [item for item in processes() if is_coordinator(item)]
        if len(candidates) != 1:
            raise RuntimeError('Expected exactly one legacy scheduler coordinator; refusing an unverified drain.')
        item = candidates[0]
        state = {'pid': item['pid'], 'start': item['start'], 'initially_stopped': item['state'] == 'T',
                 'token': token, 'protocol': PROTOCOL, 'stop': 'intent'}
        save(state)
    handle = descriptor(state)
    try:
        if state['stop'] == 'intent':
            item = process(state['pid'])
            if not identity_matches(item, state):
                raise RuntimeError('Legacy scheduler exited before the admission barrier.')
            if item['state'] == 'T':
                state['stop'] = 'preserved'
            else:
                if state['initially_stopped'] or item['state'] in ('Z', 'X', 't'):
                    raise RuntimeError('Legacy scheduler state changed before STOP; preserving its hold.')
                signal.pidfd_send_signal(handle, signal.SIGSTOP)
                barrier_deadline = time.monotonic() + 5
                while True:
                    item = process(state['pid'])
                    if not identity_matches(item, state):
                        raise RuntimeError('Legacy scheduler exited before the admission barrier.')
                    if item['state'] == 'T':
                        state['stop'] = 'confirmed'
                        break
                    if interrupted or time.monotonic() >= barrier_deadline:
                        raise RuntimeError('Could not freeze the legacy scheduler coordinator.')
                    time.sleep(.02)
            # Intent survives any failure before replacement. If replacement
            # succeeds but directory fsync fails, surviving confirmation still
            # truthfully records STOP/observation, while drain reports failure.
            save(state)
        deadline = time.monotonic() + timeout
        while True:
            if interrupted:
                raise RuntimeError('Legacy scheduler drain interrupted; active workers were preserved.')
            workers = active_work(state)
            if not workers:
                print('Legacy scheduler admission paused; all finite scheduled work finished.', flush=True)
                return
            if time.monotonic() >= deadline:
                raise RuntimeError(f'Legacy scheduler drain timed out with {len(workers)} active processes; workers were preserved.')
            time.sleep(.1)
    finally:
        os.close(handle)


def main():
    if not hasattr(os, 'pidfd_open') or not hasattr(signal, 'pidfd_send_signal'):
        raise RuntimeError('Legacy scheduler drain requires Linux pidfd support and Python 3.9 or newer.')
    # Never apply this inventory to the host, a shared namespace, or a non-init PID1.
    if os.path.basename(os.readlink('/proc/1/exe')) not in ('docker-init', 'tini'):
        raise RuntimeError('Legacy scheduler drain requires a dedicated container with Docker init.')
    DIRECTORY.mkdir(mode=0o770, parents=True, exist_ok=True)
    with (DIRECTORY / 'legacy-scheduler-drain.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        mode = sys.argv[1] if len(sys.argv) > 1 else ''
        token = sys.argv[3] if len(sys.argv) > 3 else None
        if mode == 'resume':
            restore(token)
        elif mode == 'check':
            state = json.loads(STATE.read_text()) if STATE.exists() else None
            if state is None or state.get('token') != token or active_work(state):
                raise RuntimeError('Legacy scheduler is not drained; refusing to stop containers.')
        elif mode == 'drain' and len(sys.argv) in (3, 4) and sys.argv[2].isdecimal():
            try:
                drain(int(sys.argv[2]), token)
            except BaseException:
                try:
                    restore(token)
                except Exception as error:
                    print(f'Legacy scheduler restoration failed; admission may remain paused: {error}', file=sys.stderr)
                raise
        else:
            raise RuntimeError('Expected drain TIMEOUT, check, or resume.')


if __name__ == '__main__':
    for signum in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
        signal.signal(signum, interrupt)
    try:
        main()
    except Exception as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
