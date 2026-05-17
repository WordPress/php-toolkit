/*
 * The PHP module entry and public API are exported by the Rust static library.
 *
 * Playground's @php-wasm/compile-extension helper expects a phpize source
 * directory with at least one C file. This shim intentionally contains no
 * runtime code; it gives phpize/libtool an object to compile while the final
 * side module links libwp_native_apis.a via --extra-ldflags.
 */
void wp_native_apis_phpize_shim(void) {}

#ifdef __EMSCRIPTEN__
/*
 * Rust's wasm32-unknown-emscripten standard library can leave references to
 * Emscripten's legacy JavaScript exception bookkeeping globals even when Rust
 * panics abort. PHP.wasm's JSPI runtime does not export those globals from the
 * main module, so define them in the side module instead of asking dlopen() to
 * resolve them through GOT.mem.
 */
int __THREW__;
int __threwValue;
#endif
