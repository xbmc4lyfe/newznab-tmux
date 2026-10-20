#!/usr/bin/env python3
"""Offline installer/config tests; never loads a LaunchAgent or connects to production."""
import argparse
import contextlib
import io
import json
import os
from pathlib import Path
import plistlib
import shlex
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

import install_launchd
import run_worker


class LaunchdTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="fingerprint installer ' ")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.binary = self.root / "source binary ' quoted"
        self.binary.write_bytes(b"#!/bin/sh\nexit 0\n")
        self.binary.chmod(0o700)
        self.config = self.root / "source config ' quoted.env"
        self.password = "secret with spaces ' quotes $HOME $(touch nope) `whoami`;end"
        values = {"DATABASE_URL": "mysql://user:fixture@database/db", "NNTP_SERVER": "news.example",
                  "NNTP_PASSWORD": self.password, "NNTP_SERVER_A": ""}
        self.config.write_text("".join(f"{k}={shlex.quote(v)}\n" for k, v in values.items()))
        self.config.chmod(0o600)
        self.args = argparse.Namespace(binary=self.binary, config=self.config,
            label="com.nntmux.fingerprint-test", install_root=self.root / "installed ' with spaces",
            launch_agent_directory=self.root / "Launch Agents ' quoted", domain="gui",
            install_only=True, dry_run=False)

    def test_literal_shell_quoting_never_executes_or_interpolates(self):
        values = run_worker.read_config(self.config)
        self.assertEqual(values["NNTP_PASSWORD"], self.password)
        self.assertFalse((self.root / "nope").exists())
        self.assertEqual(values["NNTP_SERVER_A"], "")

    def test_config_requires_owned_regular_private_file(self):
        self.config.chmod(0o640)
        with self.assertRaisesRegex(ValueError, "0600"):
            run_worker.read_config(self.config)
        self.config.chmod(0o600)
        link = self.root / "symlink.env"
        link.symlink_to(self.config)
        with self.assertRaises(OSError):
            run_worker.read_config(link)

    def test_rejects_unknown_and_duplicate_settings_without_exposing_values(self):
        self.config.write_text(self.config.read_text() + "PATH='secret value'\n")
        with self.assertRaisesRegex(ValueError, "Unsupported worker setting"):
            run_worker.read_config(self.config)
        self.config.write_text("DATABASE_URL=one\nDATABASE_URL=two\nNNTP_SERVER=news\n")
        with self.assertRaisesRegex(ValueError, "Duplicate"):
            run_worker.read_config(self.config)

    def test_requires_darwin_and_valid_label(self):
        with patch("install_launchd.platform.system", return_value="Linux"):
            with self.assertRaisesRegex(ValueError, "macOS"):
                install_launchd.prepare(self.args)
        self.args.label = "../unsafe"
        with patch("install_launchd.platform.system", return_value="Darwin"):
            with self.assertRaisesRegex(ValueError, "label"):
                install_launchd.prepare(self.args)

    def test_private_install_and_structured_plist_preserve_quoted_paths(self):
        with patch("install_launchd.platform.system", return_value="Darwin"), \
             patch("install_launchd.subprocess.run", return_value=subprocess.CompletedProcess([], 1)) as command, \
             contextlib.redirect_stdout(io.StringIO()) as output:
            plan = install_launchd.install(self.args)
        self.assertNotIn(self.password, output.getvalue())
        self.assertEqual(stat.S_IMODE(plan["config"].stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(plan["installed_binary"].stat().st_mode), 0o700)
        self.assertEqual(plan["installed_binary"].read_bytes(), self.binary.read_bytes())
        values = run_worker.read_config(plan["config"])
        self.assertEqual(values["NNTP_PASSWORD"], self.password)
        self.assertTrue(values["FINGERPRINT_WORKER_ID"])
        plist = plistlib.loads(plan["plist_path"].read_bytes())
        self.assertEqual(plist["ProgramArguments"][1], str(plan["runner"]))
        self.assertEqual(plist["ProgramArguments"][3], str(plan["installed_binary"]))
        self.assertEqual(plist["ProgramArguments"][5], str(plan["config"]))
        self.assertTrue(plist["KeepAlive"])
        self.assertTrue(plist["RunAtLoad"])
        self.assertEqual(plist["ExitTimeOut"], 1900)
        self.assertEqual(plist["Umask"], 0o077)
        command.assert_called_once()  # An install-only run only checks whether the label is loaded.

    def test_loaded_service_is_never_overwritten(self):
        with patch("install_launchd.platform.system", return_value="Darwin"), \
             patch("install_launchd.subprocess.run", return_value=subprocess.CompletedProcess([], 0)):
            with self.assertRaisesRegex(ValueError, "already loaded"):
                install_launchd.install(self.args)
        self.assertFalse(self.args.install_root.exists())

    def test_dry_run_writes_nothing_and_prints_no_credentials(self):
        self.args.dry_run = True
        with patch("install_launchd.platform.system", return_value="Darwin"), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            install_launchd.install(self.args)
        json.loads(output.getvalue())
        self.assertNotIn(self.password, output.getvalue())
        self.assertFalse(self.args.install_root.exists())

    def test_runner_execs_without_shell_and_clears_stale_inherited_settings(self):
        self.config.write_text(self.config.read_text() + "FINGERPRINT_WORKER_ID=laptop-test\n")
        with patch.object(sys, "argv", ["run_worker.py", "--binary", str(self.binary), "--config", str(self.config)]), \
             patch.dict(os.environ, {"NNTP_USERNAME": "stale-inherited", "NNTP_PASSWORD_A": "stale"}), \
             patch("run_worker.os.execve") as execute:
            run_worker.main()
        binary, arguments, environment = execute.call_args.args
        self.assertEqual(binary, str(self.binary))
        self.assertEqual(arguments, [str(self.binary)])
        self.assertEqual(environment["NNTP_PASSWORD"], self.password)
        self.assertNotIn("NNTP_USERNAME", environment)
        self.assertNotIn("NNTP_PASSWORD_A", environment)


if __name__ == "__main__":
    unittest.main()
