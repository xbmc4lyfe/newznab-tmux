#define FFI_SCOPE "NNTMUX_RELEASE_PRESENTATION"
#define FFI_LIB "/usr/local/lib/nntmux/libnzbyenc.so"
unsigned char *nntmux_release_names_html(const unsigned char *data, size_t length, size_t *output_length);
void nntmux_release_names_free(unsigned char *value, size_t length);
