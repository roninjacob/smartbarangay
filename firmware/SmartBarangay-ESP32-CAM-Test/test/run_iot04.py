"""Host contract/lifecycle tests and independent Python/PHP-compatible HMAC vectors."""
from pathlib import Path
import hashlib
import hmac
import json
import subprocess

ROOT = Path(__file__).resolve().parents[1]
out = ROOT / ".iot04-test"
out.mkdir(exist_ok=True)
exe = out / "contract.exe"
subprocess.run(["g++", "-std=c++17", "-Wall", "-Wextra", "-Werror", "-DSB_IOT_HOST_TEST", "-I" + str(ROOT / "include"),
                str(ROOT / "test/iot04-contract.cpp"), "-lcrypto", "-o", str(exe)], check=True)
lines = subprocess.check_output([str(exe)], text=True).splitlines()
assert len(lines) == 10
vectors = []
for offset in (0, 5):
    path, body, nonce, body_hash, signature = lines[offset:offset + 5]
    assert hashlib.sha256(body.encode()).hexdigest() == body_hash
    canonical = "\n".join(["SB-IOT-1", "POST", path, "11111111-1111-4111-8111-111111111111", "1", "1794000000", nonce, body_hash])
    assert hmac.new(bytes.fromhex("a" * 64), canonical.encode(), hashlib.sha256).hexdigest() == signature
    vectors.append({"path": path, "body": body, "nonce": nonce, "signature": signature})
expected = ROOT / "test/iot04-vectors.json"
assert json.loads(expected.read_text()) == vectors, "Firmware and reviewed Laravel vectors differ"
source = (ROOT / "src/iot_client.cpp").read_text()
assert "setInsecure(" not in source and "getString(" not in source
assert "setCACert(IOT_ROOT_CA)" in source and "https://" in source
assert "HTTPC_DISABLE_FOLLOW_REDIRECTS" in source and "ResponseBuffer" in source and "DeadlineClient" in source
assert "xQueueCreate(1," in source and "xQueueOverwrite" in source
assert "SB_LED_WIRING_APPROVED" in source and "No buzzer GPIO" in source
print("PASS: native lifecycle/auth/canonical/serialization/clock/expiry/replay/failure/feedback assertions; 2 independent HMAC vectors; secure transport source guards.")
