from pathlib import Path

Import("env")

directory = Path.home() / ".smartbarangay" / "firmware" / "esp32feedback"

if not (directory / "secrets.h").is_file():
    print("[WiFi] Private configuration missing.")
    env.Exit(1)

env.Prepend(CPPPATH=[str(directory)])
