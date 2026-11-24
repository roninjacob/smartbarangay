# SmartBarangay firmware workspace

- [ESP32-CAM scanner (PlatformIO)](ESP32-CAM/README.md) — AI Thinker ESP32-CAM, COM3
- [ESP32 feedback device (PlatformIO)](ESP32-Feedback/README.md) — ESP32 DevKit with OLED and LEDs, COM4
- [Folder consolidation, development and recovery guide](../docs/archive/iot-project-consolidation.md)
  (historical; written for the former `SmartBarangay-ESP32-CAM-Test` folder name)

Open each subfolder in PlatformIO, or use its `platformio.ini` explicitly.
The enclosing repository owns version control; there is no nested repository.
ESP32-CAM environments: `esp32cam` (production) and `esp32cam-diagnostics`
(dummy-data only, development). ESP32 feedback environment: `esp32dev`.

Firmware is not a Laravel web asset. The application web root must be `public/`.
This directory's `.htaccess` additionally denies Apache HTTP access; Git ignores
are not web access protection. Other servers must deny access or serve public only.
Never force-add local secrets, build artifacts, binaries or private backups.
