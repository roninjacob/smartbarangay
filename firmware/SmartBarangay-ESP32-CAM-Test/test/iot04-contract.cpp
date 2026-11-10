#include "iot_protocol.h"
#include "iot_crypto.h"
#include <cassert>
#include <iostream>

using namespace deviceiot;
constexpr uint64_t utc = 1794000000000ULL;
const char *ticket = "SBQ1:550e8400-e29b-41d4-a716-446655440000";
const char *device = "11111111-1111-4111-8111-111111111111";
const char *pairing = "22222222-2222-4222-8222-222222222222";
const char *boot = "11111111111111111111111111111111";
const std::string challenge = "SBP1:" + std::string(64, 'a');
bool offer(Session &session, const std::string &qr, Observation &event, uint64_t now = 100, uint64_t captured = 100, bool sync = true) {
    return session.offer(reinterpret_cast<const uint8_t *>(qr.data()), qr.size(), captured, utc, 1, 1, now, utc, sync, event);
}
void emit(const Observation &event, const char *path, const char *nonce) {
    const std::string json = body(event, boot, pairing), hash = digest(json);
    const std::string input = canonical(path, device, 1, utc / 1000, nonce, hash.c_str());
    const std::string signature = digest(input, std::string(64, 'a').c_str());
    // JSON lines contain synthetic fixtures only, never a private build header.
    std::cout << path << '\n' << json << '\n' << nonce << '\n' << hash << '\n' << signature << '\n';
}
int main() {
    Observation automatic;
    memcpy(automatic.payload, ticket, 42); automatic.kind = Kind::Ticket;
    automatic.session = 1; automatic.sequence = 2; automatic.capturedUtcMs = utc;
    const auto automaticJson = automaticBody(automatic, boot);
    assert(automaticJson == std::string("{\"boot_id\":\"") + boot
        + "\",\"scanner_session\":1,\"sequence\":2,\"captured_at_ms\":1794000000000,\"payload\":\"" + ticket + "\"}");
    assert(automaticJson.find("pairing_id") == std::string::npos);
    assert(automaticBody(automatic, "invalid").empty());
    automatic.sequence = 0; assert(automaticBody(automatic, boot).empty());
    const auto emptyHash = digest("{}");
    assert(!canonical("/api/iot/v1/status", device, 1, utc / 1000, boot, emptyHash.c_str()).empty());
    assert(!canonical("/api/iot/v1/preview", device, 1, utc / 1000, boot, emptyHash.c_str()).empty());
    assert(digest("") == "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855");
    assert(digest("hello", "invalid").empty());
    assert(classify(reinterpret_cast<const uint8_t *>(ticket), 41) == Kind::Ticket);
    assert(classify(reinterpret_cast<const uint8_t *>(challenge.data()), 69) == Kind::Pair);
    assert(classify(reinterpret_cast<const uint8_t *>("SBP1:x"), 6) == Kind::Other);
    assert(classify(nullptr, 69) == Kind::Other);
    Observation event;
    Session session;
    assert(!offer(session, ticket, event));
    assert(!offer(session, challenge, event, 100, 100, false));
    assert(session.status == Status::NeedTime);
    assert(!offer(session, challenge, event, 5100, 100));
    assert(offer(session, challenge, event));
    assert(!offer(session, challenge, event)); // Busy and duplicate frames.
    emit(event, "/api/iot/v1/pair", "00000000000000000000000000000000");
    Reply paired; paired.http = 200; paired.wellFormed = true;
    std::memcpy(paired.pairingId, pairing, 37); paired.expiresUtcMs = utc + 600000;
    session.finish(event, paired, 200, utc);
    assert(session.status == Status::Paired);
    assert(offer(session, ticket, event, 1000, 1000));
    emit(event, "/api/iot/v1/scans", "11111111111111111111111111111111");
    Reply valid; valid.http = 200; valid.wellFormed = true; valid.valid = true;
    session.finish(event, valid, 1100, utc);
    assert(session.status == Status::Valid && lights(session.status, 0).green);
    assert(!offer(session, ticket, event, 7000, 7000)); // Same stationary QR stays latched beyond old cooldown.
    for (int status : {0, -1, 401, 403, 409, 422, 429, 503}) {
        Session failure;
        Observation pending;
        assert(offer(failure, challenge, pending));
        Reply error; error.http = status;
        failure.finish(pending, error, 200, utc);
        assert(failure.status != Status::Invalid && failure.status != Status::Valid);
        assert(!failure.pairingId[0]);
        assert(!lights(failure.status, 50).green && !lights(failure.status, 50).red);
        if (status == 429) assert(failure.backoffUntilMs == 60200);
        if (status == 401) assert(!offer(failure, challenge + "a", pending, 70000, 70000));
    }
    Session stale;
    assert(offer(stale, challenge, event));
    stale.finish(event, paired, 5100, utc); assert(stale.status == Status::Unknown);
    Session expired;
    assert(offer(expired, challenge, event));
    paired.expiresUtcMs = utc; expired.finish(event, paired, 200, utc); assert(!expired.pairingId[0]);
    Session invalid;
    assert(offer(invalid, challenge, event)); paired.expiresUtcMs = utc + 600000;
    invalid.finish(event, paired, 200, utc);
    assert(offer(invalid, ticket, event)); valid.valid = false;
    invalid.finish(event, valid, 200, utc); assert(lights(invalid.status, 0).red);
    invalid.clear(Status::Unknown); assert(!invalid.pairingId[0] && !offer(invalid, ticket, event));
    const std::string hash = digest("{}");
    assert(canonical("/api/iot/v1/pair", device, 0, utc / 1000, boot, hash.c_str()).empty());
    assert(canonical("/api/iot/v1/pair?x=1", device, 1, utc / 1000, boot, hash.c_str()).empty());
    assert(canonical("/api/iot/v1/pair", device, 1, 0, boot, hash.c_str()).empty());
}
