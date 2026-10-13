#include <stdint.h>
#include <stdlib.h>
#include <string.h>

static int calls;
static int frees;

char *nntmux_spot_check_sample_v1_json(const char *data, size_t length,
                                    int64_t articles, int64_t bytes,
                                    int64_t article_bytes, int has_article_bytes)
{
    (void)data; (void)length; (void)articles;
    (void)article_bytes; (void)has_article_bytes;
    const char *value;
    if (++calls == 1) {
        value = "[{\"file_key\":\"probe\",\"totalparts\":2,\"totalparts_inferred\":true,\"number\":2,\"messageid\":\"terminal\",\"bytes\":2}]";
    } else if (bytes == 1001) {
        value = "{malformed";
    } else {
        value = "[{\"file_key\":\"fixture\",\"totalparts\":1,\"totalparts_inferred\":false,\"number\":1,\"messageid\":\"fixture\",\"bytes\":1003}]";
    }
    const size_t size = strlen(value) + 1;
    char *result = malloc(size);
    if (result != NULL) {
        memcpy(result, value, size);
    }
    return result;
}

void nntmux_metadata_string_free(char *value)
{
    if (value != NULL) {
        ++frees;
        free(value);
    }
}

int nntmux_test_free_count(void)
{
    return frees;
}
