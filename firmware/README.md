# SmartBarangay firmware workspace

- [ESP32-CAM PlatformIO project](SmartBarangay-ESP32-CAM-Test/README.md)
- [Folder consolidation, development and recovery guide](../docs/iot-project-consolidation.md)

Open the ESP32-CAM subfolder in PlatformIO, or use its `platformio.ini` explicitly.
The enclosing repository owns version control; there is no nested repository.
Default environment: `esp32cam`. `esp32cam-diagnostics` is dummy-data only.

Firmware is not a Laravel web asset. The application web root must be `public/`.
This directory's `.htaccess` additionally denies Apache HTTP access; Git ignores
are not web access protection. Other servers must deny access or serve public only.
Never force-add local secrets, build artifacts, binaries or private backups.
