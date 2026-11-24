#pragma once
#include <cstdint>
#include <cstring>
#include <string>
#ifdef SB_IOT_HOST_TEST
#include <openssl/evp.h>
#include <openssl/hmac.h>
#else
#include <mbedtls/md.h>
#endif

namespace deviceiot {
inline std::string digestBytes(const uint8_t *message, size_t length, const char *keyHex = nullptr) {
    uint8_t key[32] = {}, output[32] = {};
    if (keyHex) {
        if (std::strlen(keyHex) != 64) return {};
        for (size_t i = 0; i < 32; ++i) {
            auto nibble = [](char c) -> int { return c >= '0' && c <= '9' ? c - '0' : (c >= 'a' && c <= 'f' ? c - 'a' + 10 : -1); };
            const int high = nibble(keyHex[i * 2]), low = nibble(keyHex[i * 2 + 1]);
            if (high < 0 || low < 0) return {};
            key[i] = static_cast<uint8_t>(high * 16 + low);
        }
    }
#ifdef SB_IOT_HOST_TEST
    unsigned outputLength = 0;
    const bool ok = keyHex ? HMAC(EVP_sha256(), key, sizeof(key), message, length, output, &outputLength) != nullptr
                           : EVP_Digest(message, length, output, &outputLength, EVP_sha256(), nullptr) == 1;
#else
    const mbedtls_md_info_t *algorithm = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);
    const bool ok = algorithm && (keyHex
        ? mbedtls_md_hmac(algorithm, key, sizeof(key), message, length, output)
        : mbedtls_md(algorithm, message, length, output)) == 0;
#endif
    volatile uint8_t *wipe = key;
    for (size_t i = 0; i < sizeof(key); ++i) wipe[i] = 0;
    if (!ok) return {};
    const char alphabet[] = "0123456789abcdef";
    std::string result(64, '0');
    for (size_t i = 0; i < sizeof(output); ++i) { result[i * 2] = alphabet[output[i] >> 4]; result[i * 2 + 1] = alphabet[output[i] & 15]; }
    return result;
}
inline std::string digest(const std::string &message, const char *keyHex = nullptr) {
    return digestBytes(reinterpret_cast<const uint8_t *>(message.data()), message.size(), keyHex);
}
} // namespace deviceiot
