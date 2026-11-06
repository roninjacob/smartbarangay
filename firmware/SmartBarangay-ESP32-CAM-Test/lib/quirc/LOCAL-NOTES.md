# Vendored dependency provenance

Upstream: https://github.com/dlbeer/quirc, tag v1.2, commit `542848dd6b9b0eaa9587bbf25b9bc67bd8a71fca`.

License retained in LICENSE and source headers (ISC). PlatformIO builds only `lib/*.c`, using the local library.json manifest; upstream examples and desktop tools are not built. No ESP32 camera-wrapper tasks are used.

Local adaptation in lib/quirc.c: under ESP32, map this translation unit's malloc/calloc to heap_caps_malloc/heap_caps_calloc with SPIRAM and 8-bit capabilities. Standard free remains compatible. No algorithm changes. Non-ESP32 host builds retain normal allocation.

quirc_version() in upstream v1.2 reports the historical string 1.0; the pinned tag/commit above, not that string, identifies this vendor revision.
