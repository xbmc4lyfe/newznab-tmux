import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location(
    "nzb_fingerprint", Path(__file__).resolve().parents[2] / "scripts/nzb_fingerprint.py"
)
fingerprint = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = fingerprint
SPEC.loader.exec_module(fingerprint)


class BrokenTransport(io.BytesIO):
    def write(self, data):
        raise TimeoutError("partial command write")

    def sendall(self, data):
        self.write(data)


class ShortWriteSocket:
    def __init__(self):
        self.sent = bytearray()

    def sendall(self, data):
        self.sent.extend(data)


class ShortWriteFile(io.BytesIO):
    def __init__(self, sock):
        super().__init__(b"223 1 <sample@example.net>\r\n")
        self.sock = sock

    def write(self, data):
        self.sock.sent.extend(data[:2])
        return 2


class NzbFingerprintTransportTest(unittest.TestCase):
    def test_short_file_writes_do_not_truncate_the_nntp_command(self):
        server = object.__new__(fingerprint.Nntp)
        server.poisoned = False
        server.sock = ShortWriteSocket()
        server.file = ShortWriteFile(server.sock)

        result = server.status("STAT", "<sample@example.net>")

        self.assertTrue(result["available"])
        self.assertEqual(b"STAT <sample@example.net>\r\n", server.sock.sent)

    def test_write_timeout_closes_and_poisons_transport(self):
        server = object.__new__(fingerprint.Nntp)
        server.poisoned = False
        server.file = BrokenTransport()
        server.sock = BrokenTransport()
        with self.assertRaises(OSError):
            server.status("STAT", "<sample@example.net>")
        self.assertTrue(server.poisoned)
        self.assertTrue(server.file.closed)
        self.assertTrue(server.sock.closed)

    def test_missing_segment_bytes_still_produces_json_without_network(self):
        with tempfile.TemporaryDirectory() as scratch:
            nzb = Path(scratch) / "sample.nzb"
            output = Path(scratch) / "result.json"
            nzb.write_text('<nzb><file subject="one.rar"><segments><segment number="1">one@example.net</segment></segments></file></nzb>')
            with patch.object(sys, "argv", ["nzb_fingerprint", str(nzb), "--output", str(output)]):
                self.assertEqual(0, fingerprint.main())
            result = json.loads(output.read_text())
            self.assertEqual(0, result["file_coverage"][0]["nzb_advertised_bytes"])


if __name__ == "__main__":
    unittest.main()
