/*
 * The PHP module entry and public API are exported by the Rust static library.
 *
 * Playground's @php-wasm/compile-extension helper expects a phpize source
 * directory with at least one C file. This shim intentionally contains no
 * runtime code; it gives phpize/libtool an object to compile while the final
 * side module links libwp_native_apis.a via --extra-ldflags.
 */
void wp_native_apis_phpize_shim(void) {}
