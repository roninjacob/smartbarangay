"""Validate an external private header without putting credentials in build flags."""
from pathlib import Path
import re


HEADER_NAME = "smartbarangay_wifi_private.h"
PLACEHOLDERS = {"YOUR_2_4_GHZ_WIFI_NAME", "YOUR_WIFI_PASSWORD"}


def private_include_directory(project_dir):
    project = Path(project_dir).resolve()
    served_root = project.parents[2]
    directory = Path.home() / ".smartbarangay" / "firmware" / "esp32cam"
    header = (directory / HEADER_NAME).resolve()
    if header.is_relative_to(served_root):
        raise ValueError("Private Wi-Fi configuration must be outside htdocs.")
    try:
        content = header.read_text(encoding="utf-8-sig")
    except (OSError, UnicodeError):
        raise ValueError("Private Wi-Fi header missing or unreadable. See README Private Wi-Fi setup.") from None

    # Accept only the two string declarations and harmless comments/pragma.
    fields = {}
    declaration = re.compile(
        r'(?:static constexpr char (WIFI_SSID|WIFI_PASSWORD)\[\]\s*=\s*'
        r'|#define\s+(WIFI_SSID|WIFI_PASSWORD)\s+)'
        r'("(?:[^"\\\r\n]|\\["\\nrt])*")\s*;?'
    )
    for line in content.splitlines():
        line = line.strip()
        if not line or line.startswith("//") or line == "#pragma once":
            continue
        match = declaration.fullmatch(line)
        if not match:
            raise ValueError("Private Wi-Fi header has an unsupported declaration. See README.")
        key = match[1] or match[2]
        if key in fields:
            raise ValueError("Private Wi-Fi header contains duplicate declarations.")
        fields[key] = match[3][1:-1]

    if set(fields) != {"WIFI_SSID", "WIFI_PASSWORD"}:
        raise ValueError("Private Wi-Fi header must define both Wi-Fi fields.")
    if not fields["WIFI_SSID"].strip() or any(value in PLACEHOLDERS for value in fields.values()):
        raise ValueError("Private Wi-Fi configuration is empty or still contains placeholders.")
    return str(header.parent)


Import("env")
try:
    private_directory = private_include_directory(env.subst("$PROJECT_DIR"))
except ValueError as error:
    print("[WiFi configuration] " + str(error))
    env.Exit(1)
else:
    env.Prepend(CPPPATH=[private_directory])
