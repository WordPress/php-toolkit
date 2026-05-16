#include "php.h"

char **environ = 0;

/*
 * The PHP module entry is provided by the Rust staticlib linked through
 * @php-wasm/compile-extension --extra-ldflags. This translation unit exists so
 * phpize has a conventional extension source file to compile.
 */
