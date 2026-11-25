#pragma once

#include <algorithm>
#include <cstdint>
#include <cstring>
#include "esp32/rom/tjpgd.h"

// The AI Thinker ESP32 ROM emits small RGB tiles. Convert each tile directly
// into QUIRC's input image while it is hot, with no VGA-sized RGB allocation.
// scale 0 decodes at full size; scale 1 uses the ROM decoder's 1/2 descaling
// (JD_USE_SCALE), which skips most of the IDCT and output work.
struct JpegLuminance {
    alignas(4) uint8_t workspace[4096];
    const uint8_t *input;
    size_t remaining;
    uint8_t *gray;
    unsigned outWidth, outHeight;

    static UINT read(JDEC *decoder, BYTE *destination, UINT count) {
        auto *self = static_cast<JpegLuminance *>(decoder->device);
        const size_t used = std::min<size_t>(count, self->remaining);
        if (destination) memcpy(destination, self->input, used);
        self->input += used;
        self->remaining -= used;
        return used;
    }

    static UINT write(JDEC *decoder, void *pixels, JRECT *tile) {
        auto *self = static_cast<JpegLuminance *>(decoder->device);
        if (tile->bottom >= self->outHeight || tile->right >= self->outWidth) return 0;
        auto *rgb = static_cast<const uint8_t *>(pixels);
        for (unsigned y = tile->top; y <= tile->bottom; ++y) {
            uint8_t *row = self->gray + y * self->outWidth + tile->left;
            for (unsigned x = tile->left; x <= tile->right; ++x, rgb += 3) {
                *row++ = (77u * rgb[0] + 150u * rgb[1] + 29u * rgb[2]) >> 8;
            }
        }
        return 1;
    }

    bool decode(const uint8_t *jpeg, size_t size, uint8_t *out, unsigned width, unsigned height, uint8_t scale = 0) {
        JDEC decoder{};
        input = jpeg;
        remaining = size;
        gray = out;
        outWidth = width >> scale;
        outHeight = height >> scale;
        return out && scale <= 1 && jd_prepare(&decoder, read, workspace, sizeof(workspace), this) == JDR_OK
            && decoder.width == width && decoder.height == height && jd_decomp(&decoder, write, scale) == JDR_OK;
    }
};
