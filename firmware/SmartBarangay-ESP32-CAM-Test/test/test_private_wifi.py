"""Private header validation using synthetic credentials only."""
import contextlib
import io
from pathlib import Path
import runpy
import tempfile
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "private_wifi.py"


class BuildEnvironment:
    def __init__(self, project):
        self.project = project
        self.includes = []

    def subst(self, value):
        return str(self.project)

    def Prepend(self, CPPPATH):
        self.includes.extend(CPPPATH)

    def Exit(self, code):
        raise SystemExit(code)


class PrivateWifiTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.home = self.root / "user"
        self.header = self.home / ".smartbarangay" / "firmware" / "esp32cam" / "smartbarangay_wifi_private.h"
        self.header.parent.mkdir(parents=True)
        self.environment = BuildEnvironment(self.root / "htdocs" / "smartbarangay" / "firmware" / "cam")
        self.home_patch = patch.object(Path, "home", return_value=self.home)
        self.home_patch.start()
        self.addCleanup(self.home_patch.stop)
        self.write_header('"synthetic-network"', '"synthetic-password"')

    def write_header(self, ssid, password):
        self.header.write_text(
            '#pragma once\nstatic constexpr char WIFI_SSID[] = ' + ssid + ';\n'
            'static constexpr char WIFI_PASSWORD[] = ' + password + ';\n', encoding="utf-8"
        )

    def run_script(self):
        return runpy.run_path(str(SCRIPT), init_globals={"Import": lambda name: None, "env": self.environment})

    def assert_rejected(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output), self.assertRaises(SystemExit) as result:
            self.run_script()
        self.assertEqual(result.exception.code, 1)
        self.assertEqual(self.environment.includes, [])
        self.assertNotIn("synthetic-password", output.getvalue())
        return output.getvalue()

    def test_valid_header_adds_directory_without_credentials_in_flags(self):
        self.run_script()
        self.assertEqual(self.environment.includes, [str(self.header.parent.resolve())])

    def test_missing_header_fails_closed(self):
        self.header.unlink()
        self.assertIn("missing or unreadable", self.assert_rejected())

    def test_placeholder_header_fails_closed(self):
        self.write_header('"YOUR_2_4_GHZ_WIFI_NAME"', '"YOUR_WIFI_PASSWORD"')
        self.assertIn("placeholders", self.assert_rejected())

    def test_empty_ssid_fails_closed(self):
        self.write_header('""', '"synthetic-password"')
        self.assert_rejected()

    def test_empty_password_remains_supported_for_open_wifi(self):
        self.write_header('"synthetic-network"', '""')
        self.run_script()
        self.assertEqual(len(self.environment.includes), 1)

    def test_duplicate_and_missing_fields_fail_closed(self):
        self.header.write_text('#define WIFI_SSID "synthetic-network"\n#define WIFI_SSID "duplicate"\n')
        self.assertIn("duplicate", self.assert_rejected())
        self.header.write_text('#define WIFI_SSID "synthetic-network"\n')
        self.assertIn("both Wi-Fi fields", self.assert_rejected())

    def test_invalid_declaration_does_not_echo_private_input(self):
        self.header.write_text('synthetic-password invalid C++\n')
        self.assertIn("unsupported", self.assert_rejected())

    def test_configuration_under_served_root_is_rejected(self):
        self.home_patch.stop()
        with patch.object(Path, "home", return_value=self.root / "htdocs" / "private"):
            self.assertIn("outside htdocs", self.assert_rejected())


if __name__ == "__main__":
    unittest.main()
