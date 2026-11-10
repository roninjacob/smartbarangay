"""Synthetic external configuration and loopback TLS trust tests; no device flashing."""
import contextlib
import io
import json
from pathlib import Path
import runpy
import socket
import ssl
import subprocess
import tempfile
import threading
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/private_iot.py"


class Environment:
    def __init__(self, project, name="esp32cam"):
        self.project, self.name = project, name
        self.includes, self.defines = [], []

    def subst(self, value):
        return str(self.project) if value == "$PROJECT_DIR" else self.name

    def Prepend(self, CPPPATH):
        self.includes.extend(CPPPATH)

    def Append(self, CPPDEFINES):
        self.defines.extend(CPPDEFINES)

    def Exit(self, code):
        raise SystemExit(code)


class PrivateIotTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.material = tempfile.TemporaryDirectory(prefix="sb-iot04-tls-")
        cls.directory = Path(cls.material.name)
        cls.ca = cls.directory / "localhost.pem"
        cls.key = cls.directory / "localhost.key"
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                        "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost",
                        "-keyout", str(cls.key), "-out", str(cls.ca)], check=True, capture_output=True)

    @classmethod
    def tearDownClass(cls):
        cls.material.cleanup()

    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="sb-iot04-config-")
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.home = self.root / "user"
        self.source = self.home / ".smartbarangay/firmware/esp32cam/smartbarangay_iot.json"
        self.source.parent.mkdir(parents=True)
        self.environment = Environment(self.root / "htdocs/smartbarangay/firmware/cam")
        home_patch = patch.object(Path, "home", return_value=self.home)
        home_patch.start()
        self.addCleanup(home_patch.stop)
        self.config = {"enabled": True, "host": "localhost", "port": 443,
                       "device_id": "11111111-1111-4111-8111-111111111111", "key_version": 1,
                       "key_hex": "a" * 64, "root_ca_file": str(self.ca)}

    def run_script(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result = runpy.run_path(str(SCRIPT), init_globals={"Import": lambda _: None, "env": self.environment})
        self.assertNotIn("a" * 64, output.getvalue())
        return result

    def write(self):
        self.source.write_text(json.dumps(self.config), encoding="utf-8")

    def rejected(self):
        self.write()
        output = io.StringIO()
        with contextlib.redirect_stdout(output), self.assertRaises(SystemExit):
            self.run_script()
        self.assertEqual(self.environment.includes, [])
        self.assertNotIn("a" * 64, output.getvalue())

    def test_missing_is_disabled(self):
        self.run_script()
        self.assertEqual(self.environment.defines, [])

    def test_disabled_never_activates_stale_generated_header(self):
        self.config["enabled"] = False
        self.write()
        self.source.with_name("smartbarangay_iot_private.generated.h").write_text("stale synthetic", encoding="ascii")
        self.run_script()
        self.assertEqual(self.environment.defines, [])

    def test_diagnostics_ignores_even_malformed_private_input(self):
        self.environment.name = "esp32cam-diagnostics"
        self.source.write_text("{malformed", encoding="ascii")
        self.run_script()
        self.assertEqual(self.environment.includes, [])

    def test_valid_external_configuration_generates_private_header_only(self):
        self.write()
        self.run_script()
        self.assertEqual(self.environment.includes, [str(self.source.parent)])
        self.assertEqual(self.environment.defines, ["SB_IOT_PRIVATE_BUILD"])
        header = self.source.with_name("smartbarangay_iot_private.generated.h").read_text()
        self.assertIn("IOT_ROOT_CA", header)
        self.assertIn("IOT_KEY_VERSION = 1", header)
        self.assertNotIn("a" * 64, str(self.environment.defines))

    def test_invalid_hosts_fail_closed(self):
        for host in ("http://localhost", "localhost/path", "a..b", "a.-bad", "a?x=1", ""):
            with self.subTest(host=host):
                self.config["host"] = host
                self.rejected()

    def test_invalid_credentials_and_versions_fail_closed(self):
        for field, value in (("key_hex", "0" * 64), ("device_id", "invalid"), ("key_version", True),
                             ("key_version", 0), ("port", 65536), ("enabled", "yes")):
            with self.subTest(field=field, value=value):
                original = self.config[field]
                self.config[field] = value
                self.rejected()
                self.config[field] = original

    def test_malformed_and_duplicate_keys_fail_closed(self):
        for raw in ("{", '{"enabled":false,"enabled":true}', "[]"):
            self.source.write_text(raw, encoding="ascii")
            with self.assertRaises(SystemExit):
                self.run_script()

    def test_ca_must_be_external_valid_certificate(self):
        self.config["root_ca_file"] = "relative.pem"
        self.rejected()
        bad = self.root / "invalid.pem"
        bad.write_text("invalid certificate", encoding="ascii")
        self.config["root_ca_file"] = str(bad)
        self.rejected()
        exposed = self.root / "htdocs/ca.pem"
        exposed.parent.mkdir(parents=True)
        exposed.write_text(self.ca.read_text(), encoding="ascii")
        self.config["root_ca_file"] = str(exposed)
        self.rejected()

    def tls_handshake(self, trusted, hostname):
        server_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        server_context.load_cert_chain(self.ca, self.key)
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            listener.listen(1)
            listener.settimeout(5)
            def serve():
                try:
                    connection, _ = listener.accept()
                    with connection, server_context.wrap_socket(connection, server_side=True) as secure:
                        secure.sendall(b"synthetic")
                except (ssl.SSLError, OSError):
                    pass  # Expected when the test client rejects the certificate.
            worker = threading.Thread(target=serve, daemon=True)
            worker.start()
            context = ssl.create_default_context(cafile=str(self.ca)) if trusted else ssl.create_default_context()
            try:
                with socket.create_connection(listener.getsockname(), timeout=5) as connection:
                    with context.wrap_socket(connection, server_hostname=hostname) as secure:
                        self.assertEqual(secure.recv(32), b"synthetic")
            finally:
                worker.join(timeout=6)
                self.assertFalse(worker.is_alive())

    def test_host_tls_accepts_explicit_trust_and_matching_hostname(self):
        self.tls_handshake(True, "localhost")

    def test_host_tls_rejects_untrusted_certificate(self):
        with self.assertRaises(ssl.SSLCertVerificationError):
            self.tls_handshake(False, "localhost")

    def test_host_tls_rejects_wrong_hostname(self):
        with self.assertRaises(ssl.SSLCertVerificationError):
            self.tls_handshake(True, "wrong.invalid")


if __name__ == "__main__":
    unittest.main()
