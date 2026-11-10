"""Optional private IoT configuration. Missing means disabled; invalid never means insecure."""
from pathlib import Path
import json
import os
import re
import ssl
import tempfile


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError()
        result[key] = value
    return result


def configure(project_dir, environment):
    if environment == "esp32cam-diagnostics":
        return None
    project = Path(project_dir).resolve()
    served_root = project.parents[2]
    directory = Path.home() / ".smartbarangay" / "firmware" / "esp32cam"
    source = directory / "smartbarangay_iot.json"
    if source.resolve().is_relative_to(served_root):
        raise ValueError("IoT configuration must be outside htdocs.")
    if not source.exists():
        return None
    try:
        if source.stat().st_size > 32768:
            raise ValueError()
        config = json.loads(source.read_text(encoding="utf-8-sig"), object_pairs_hook=unique_object)
        if not isinstance(config, dict) or type(config.get("enabled")) is not bool:
            raise ValueError()
        if not config["enabled"]:
            return None
        if set(config) != {"enabled", "host", "port", "device_id", "key_version", "key_hex", "root_ca_file"}:
            raise ValueError()
        if not isinstance(config["host"], str) or len(config["host"]) > 253 or not all(
            re.fullmatch(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?", label) for label in config["host"].split(".")
        ):
            raise ValueError()
        if type(config["port"]) is not int or not 1 <= config["port"] <= 65535:
            raise ValueError()
        if type(config["key_version"]) is not int or not 1 <= config["key_version"] <= 999999999:
            raise ValueError()
        if not re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", config["device_id"]):
            raise ValueError()
        if not re.fullmatch(r"[0-9a-f]{64}", config["key_hex"]) or config["key_hex"] == "0" * 64:
            raise ValueError()
        ca_path = Path(config["root_ca_file"])
        if not ca_path.is_absolute() or ca_path.resolve().is_relative_to(served_root):
            raise ValueError()
        certificate = ca_path.read_text(encoding="ascii")
        if len(certificate) > 16384 or "PRIVATE KEY" in certificate:
            raise ValueError()
        ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT).load_verify_locations(cadata=certificate)
    except (OSError, UnicodeError, ValueError, TypeError, KeyError, ssl.SSLError):
        raise ValueError("Invalid private IoT configuration or trusted CA. See IoT-04 setup; values withheld.") from None
    header = directory / "smartbarangay_iot_private.generated.h"
    if header.resolve().is_relative_to(served_root):
        raise ValueError("Generated IoT header must be outside htdocs.")
    text = "#pragma once\n#define SB_IOT_CONFIGURED 1\n"
    for name, key in [("IOT_HOST", "host"), ("IOT_DEVICE_ID", "device_id"), ("IOT_KEY_HEX", "key_hex")]:
        text += "static constexpr char " + name + "[] = " + json.dumps(config[key]) + ";\n"
    text += "static constexpr char IOT_ROOT_CA[] = " + json.dumps(certificate) + ";\n"
    text += "static constexpr unsigned IOT_PORT = " + str(config["port"]) + ";\n"
    text += "static constexpr unsigned IOT_KEY_VERSION = " + str(config["key_version"]) + ";\n"
    # Replace atomically and never put key material in CPPDEFINES/compiler arguments.
    descriptor, temporary = tempfile.mkstemp(prefix=".iot-config-", dir=directory)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as file:
            file.write(text)
        os.replace(temporary, header)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    return str(directory)


Import("env")
try:
    private_directory = configure(env.subst("$PROJECT_DIR"), env.subst("$PIOENV"))
except ValueError as error:
    print("[IoT configuration] " + str(error))
    env.Exit(1)
else:
    if private_directory:
        env.Prepend(CPPPATH=[private_directory])
        env.Append(CPPDEFINES=["SB_IOT_PRIVATE_BUILD"])
        print("[IoT configuration] External private client configuration validated; values withheld.")
    else:
        print("[IoT configuration] Device API client disabled; scanner baseline remains available.")
