#include <stddef.h>
#include <stdint.h>

/* Minimal ABI fixture: optional CRC exports emulate older decode-only builds. */
int rapidyenc_version(void) {
    return 0x010101;
}

void rapidyenc_decode_init(void) {}

static int split_escape_capability_calls = 0;
static int last_payload_had_split_escape = 0;

int fixture_split_escape_capability_calls(void) {
    return split_escape_capability_calls;
}

int fixture_last_payload_had_split_escape(void) {
    return last_payload_had_split_escape;
}

#ifdef WITH_SPLIT_ESCAPE_CAPABILITY
#ifndef SPLIT_ESCAPE_CAPABILITY
#define SPLIT_ESCAPE_CAPABILITY 1
#endif
int nntmux_yenc_supports_split_escapes(void) {
    split_escape_capability_calls++;
    return SPLIT_ESCAPE_CAPABILITY;
}
#endif

#ifndef OMIT_DECODER
size_t rapidyenc_decode_ex(int is_raw, const void *src, void *dest, size_t length, int *state) {
    const unsigned char *input = src;
    unsigned char *output = dest;
    size_t written = 0;
    last_payload_had_split_escape = 0;
    for (size_t i = 0; i < length; i++) {
        unsigned char byte = input[i];
        if (byte == '\r' || byte == '\n') {
            continue;
        }
        if (byte == '=') {
            if (i + 1 < length && (input[i + 1] == '\r' || input[i + 1] == '\n')) {
                last_payload_had_split_escape = 1;
            }
#ifdef SPLIT_ESCAPE_DECODER
            while (i + 1 < length && (input[i + 1] == '\r' || input[i + 1] == '\n')) {
                i++;
            }
#endif
            if (++i == length) {
                break;
            }
            byte = input[i] - 64;
        }
        output[written++] = byte - 42;
    }
#ifdef BAD_SPLIT_LENGTH
    if (last_payload_had_split_escape) {
        return length + 1;
    }
#endif
    return written;
}
#endif

#ifdef WITH_CRC
static int crc_initialized = 0;

void rapidyenc_crc_init(void) {
    crc_initialized = 1;
}

uint32_t rapidyenc_crc(const void *src, size_t length, uint32_t initial) {
#ifdef BAD_CRC
    return 0;
#else
    if (!crc_initialized) {
        return 0;
    }
    const unsigned char *input = src;
    uint32_t crc = ~initial;
    for (size_t i = 0; i < length; i++) {
        crc ^= input[i];
        for (int bit = 0; bit < 8; bit++) {
            crc = (crc >> 1) ^ ((crc & 1) ? 0xedb88320U : 0);
        }
    }
    return ~crc;
#endif
}
#endif
