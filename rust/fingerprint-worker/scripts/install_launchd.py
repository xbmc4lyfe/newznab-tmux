#!/usr/bin/env python3
"""Install one private standalone worker binary and a macOS per-user LaunchAgent."""
import argparse
import json
import os
from pathlib import Path
import platform
import plistlib
import re
import shlex
import socket
import stat
import subprocess
import sys
import tempfile
import uuid

from run_worker import read_config


def atomic_write(path, body, mode):
    fd, temporary = tempfile.mkstemp(prefix=".worker-install-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            os.fchmod(stream.fileno(), mode)
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def prepare(args):
    if platform.system() != "Darwin":
        raise ValueError("launchd installation requires macOS")
    if os.geteuid() == 0:
        raise ValueError("Run as the worker's login user, without sudo")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,119}", args.label):
        raise ValueError("Invalid LaunchAgent label")
    binary = args.binary.absolute()
    info = binary.lstat()
    if not stat.S_ISREG(info.st_mode) or not os.access(binary, os.X_OK):
        raise ValueError("--binary must be an executable regular file")
    values = read_config(args.config.absolute())
    worker_id = values.get("FINGERPRINT_WORKER_ID") or (
        f"{socket.gethostname().split('.')[0][:50]}-{os.geteuid()}-{uuid.uuid4().hex[:12]}"
    )
    values["FINGERPRINT_WORKER_ID"] = worker_id
    root = args.install_root.absolute()
    logs = root / "logs"
    installed_binary = root / "nntmux-fingerprint-worker"
    installed_config = root / "worker.env"
    runner = root / "run_worker.py"
    plist_path = args.launch_agent_directory.absolute() / f"{args.label}.plist"
    domain = f"{args.domain}/{os.geteuid()}"
    plist = {
        "Label": args.label,
        "ProgramArguments": [str(Path(sys.executable).absolute()), str(runner),
                             "--binary", str(installed_binary), "--config", str(installed_config)],
        "WorkingDirectory": str(root),
        "RunAtLoad": True,
        "KeepAlive": True,
        "ThrottleInterval": 30,
        "ExitTimeOut": 1900,
        "ProcessType": "Background",
        "Umask": 0o077,
        "StandardOutPath": str(logs / "worker.stdout.log"),
        "StandardErrorPath": str(logs / "worker.stderr.log"),
        "EnvironmentVariables": {"PATH": "/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"},
    }
    config_body = "".join(f"{key}={shlex.quote(value)}\n" for key, value in sorted(values.items())).encode()
    return {"binary": binary, "root": root, "logs": logs, "installed_binary": installed_binary,
            "config": installed_config, "runner": runner, "plist_path": plist_path, "plist": plist,
            "config_body": config_body, "worker_id": worker_id, "domain": domain}


def install(args):
    plan = prepare(args)
    public = {"label": args.label, "worker_id": plan["worker_id"], "binary": str(plan["installed_binary"]),
              "config": str(plan["config"]), "plist": str(plan["plist_path"]),
              "service": plan["domain"] + "/" + args.label, "load": not args.install_only}
    if args.dry_run:
        print(json.dumps(public, indent=2))
        return plan
    service = plan["domain"] + "/" + args.label
    loaded = subprocess.run(["/bin/launchctl", "print", service], stdout=subprocess.DEVNULL,
                            stderr=subprocess.DEVNULL, check=False)
    if loaded.returncode == 0:
        raise ValueError("This label is already loaded; gracefully boot it out and wait for exit before reinstalling")
    for directory in [plan["root"], plan["logs"]]:
        directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        info = directory.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022:
            raise ValueError("Install/log directories must be owned by this user and not writable by others")
    plan["plist_path"].parent.mkdir(parents=True, exist_ok=True)
    # Read before replacing so reinstalling from the installed binary itself also works.
    atomic_write(plan["installed_binary"], plan["binary"].read_bytes(), 0o700)
    atomic_write(plan["runner"], Path(__file__).with_name("run_worker.py").read_bytes(), 0o700)
    atomic_write(plan["config"], plan["config_body"], 0o600)
    atomic_write(plan["plist_path"], plistlib.dumps(plan["plist"], fmt=plistlib.FMT_XML), 0o600)
    if not args.install_only:
        subprocess.run(["/bin/launchctl", "bootstrap", plan["domain"], str(plan["plist_path"])], check=True)
        subprocess.run(["/bin/launchctl", "print", service], stdout=subprocess.DEVNULL, check=True)
    print(json.dumps(public, indent=2))
    return plan


def arguments():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--binary", required=True, type=Path)
    parser.add_argument("--config", required=True, type=Path)
    parser.add_argument("--label", required=True)
    parser.add_argument("--install-root", type=Path,
                        default=Path.home() / "Library/Application Support/NNTmux/fingerprint-worker")
    parser.add_argument("--launch-agent-directory", type=Path, default=Path.home() / "Library/LaunchAgents")
    parser.add_argument("--domain", choices=["gui", "user"], default="gui")
    parser.add_argument("--install-only", action="store_true", help="Write the install and plist without loading it")
    parser.add_argument("--dry-run", action="store_true", help="Validate and show a secret-free plan without writing/loading")
    return parser.parse_args()


if __name__ == "__main__":
    try:
        install(arguments())
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        print(f"LaunchAgent installation failed: {error}", file=sys.stderr)
        sys.exit(1)
