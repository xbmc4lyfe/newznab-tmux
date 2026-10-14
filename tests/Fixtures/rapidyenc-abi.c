#include <stddef.h>
#include <stdint.h>

/* Minimal ABI fixture: optional CRC exports emulate older decode-only builds. */
int rapidyenc_version(void) {
    return 0x010101;
}

void rapidyenc_decode_init(void) {}

static int split_escape_capability_calls = 0;
static int last_payload_had_split_escape = 0;
static int span_decode_calls = 0;
static int span_crc_requests = 0;
static int crc_calls = 0;
static int span_crc_overlaps = 0;
static int fail_next_span_decode = 0;

int fixture_span_decode_calls(void) { return span_decode_calls; }
int fixture_span_crc_requests(void) { return span_crc_requests; }
int fixture_span_crc_overlaps(void) { return span_crc_overlaps; }
void fixture_fail_next_span_decode(void) { fail_next_span_decode = 1; }
void fixture_reset_span_decode_calls(void) {
    span_decode_calls = 0;
    span_crc_requests = 0;
    span_crc_overlaps = 0;
}
int fixture_crc_calls(void) { return crc_calls; }
void fixture_reset_crc_calls(void) { crc_calls = 0; }

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

static size_t fixture_decode(const void *src, void *dest, size_t length) {
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

#ifndef OMIT_DECODER
size_t rapidyenc_decode_ex(int is_raw, const void *src, void *dest, size_t length, int *state) {
    return fixture_decode(src, dest, length);
}
#endif

static uint32_t fixture_crc(const void *src, size_t length, uint32_t initial) {
    const unsigned char *input = src;
    uint32_t crc = ~initial;
    for (size_t i = 0; i < length; i++) {
        crc ^= input[i];
        for (int bit = 0; bit < 8; bit++) {
            crc = (crc >> 1) ^ ((crc & 1) ? 0xedb88320U : 0);
        }
    }
    return ~crc;
}

#ifdef WITH_SPAN_DECODER
static void (*span_decode_callback)(void) = NULL;

void fixture_set_span_decode_callback(void (*callback)(void)) {
    span_decode_callback = callback;
}

static int fixture_ranges_overlap(uintptr_t left, size_t left_length,
        uintptr_t right, size_t right_length) {
    if (left_length == 0 || right_length == 0) {
        return 0;
    }
    return left <= right ? right - left < left_length : left - right < right_length;
}

size_t nntmux_yenc_decode_span_v1(const void *src, size_t source_length,
        size_t payload_offset, size_t payload_length, void *dest,
        size_t destination_capacity, uint32_t *crc32_output) {
    span_decode_calls++;
    if (source_length > PTRDIFF_MAX || (src == NULL && source_length != 0)
            || (dest == NULL && payload_length != 0) || payload_offset > source_length
            || payload_length > source_length - payload_offset
            || destination_capacity < payload_length) {
        return SIZE_MAX;
    }
    if (fail_next_span_decode) {
        fail_next_span_decode = 0;
        return SIZE_MAX;
    }
    if (crc32_output != NULL
            && (fixture_ranges_overlap((uintptr_t)crc32_output, sizeof(uint32_t),
                    (uintptr_t)src, source_length)
                || fixture_ranges_overlap((uintptr_t)crc32_output, sizeof(uint32_t),
                    (uintptr_t)dest, destination_capacity))) {
        span_crc_overlaps++;
    }
    if (payload_length == 0) {
        if (crc32_output != NULL) {
            span_crc_requests++;
            *crc32_output = 0;
        }
        return 0;
    }
    /* Validate the complete range and capacity before touching either buffer. */
    const unsigned char *input = src;
    size_t written = fixture_decode(input + payload_offset, dest, payload_length);
    if (written > payload_length) {
        return SIZE_MAX;
    }
    if (crc32_output != NULL) {
        span_crc_requests++;
#ifdef BAD_SPAN_CRC
        *crc32_output = 0;
#else
        *crc32_output = fixture_crc(dest, written, 0);
#endif
    }
    /* Reenter PHP after both writes, before its outer call can copy them out. */
    if (span_decode_callback != NULL) {
        void (*callback)(void) = span_decode_callback;
        span_decode_callback = NULL;
        callback();
    }
#ifdef BAD_SPAN_LENGTH
    return payload_length + 1;
#else
    return written;
#endif
}
#endif

#ifdef WITH_CRC
static int crc_initialized = 0;

void rapidyenc_crc_init(void) {
    crc_initialized = 1;
}

uint32_t rapidyenc_crc(const void *src, size_t length, uint32_t initial) {
    crc_calls++;
#ifdef BAD_CRC
    return 0;
#else
    if (!crc_initialized) {
        return 0;
    }
    return fixture_crc(src, length, initial);
#endif
}
#endif
