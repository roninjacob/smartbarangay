#pragma once

#include <algorithm>
#include <cstdint>
#include <cstring>
#include "esp32/rom/tjpgd.h"

// The AI Thinker ESP32 ROM emits small RGB tiles. Convert each tile directly
// into QUIRC's input image while it is hot, with no VGA-sized RGB allocation.
struct JpegLuminance {
    alignas(4) uint8_t workspace[4096];
    const uint8_t *input;
    size_t remaining;
    uint8_t *gray;

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
        if (tile->bottom >= decoder->height || tile->right >= decoder->width) return 0;
        auto *rgb = static_cast<const uint8_t *>(pixels);
        for (unsigned y = tile->top; y <= tile->bottom; ++y) {
            uint8_t *row = self->gray + y * decoder->width + tile->left;
            for (unsigned x = tile->left; x <= tile->right; ++x, rgb += 3) {
                *row++ = (77u * rgb[0] + 150u * rgb[1] + 29u * rgb[2]) >> 8;
            }
        }
        return 1;
    }

    bool decode(const uint8_t *jpeg, size_t size, uint8_t *out, unsigned width, unsigned height) {
        JDEC decoder{};
        input = jpeg;
        remaining = size;
        gray = out;
        return out && jd_prepare(&decoder, read, workspace, sizeof(workspace), this) == JDR_OK
            && decoder.width == width && decoder.height == height && jd_decomp(&decoder, write, 0) == JDR_OK;
    }
};
