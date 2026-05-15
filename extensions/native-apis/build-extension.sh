#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${script_dir}"

php_config="${PHP_CONFIG:-php-config}"

if ! command -v cargo >/dev/null 2>&1; then
	echo "Rust cargo was not found in PATH." >&2
	echo "Install Rust, then rerun this script." >&2
	exit 1
fi

if ! command -v "${php_config}" >/dev/null 2>&1; then
	echo "php-config was not found." >&2
	echo "Install PHP development headers or set PHP_CONFIG=/path/to/php-config." >&2
	echo "Example: PHP_CONFIG=/usr/bin/php-config LIBCLANG_PATH=/path/to/libclang/lib ./build-extension.sh" >&2
	exit 1
fi

if ! command -v clang >/dev/null 2>&1 && [ -z "${LIBCLANG_PATH:-}" ]; then
	echo "clang was not found and LIBCLANG_PATH is not set." >&2
	echo "Install clang/libclang or set LIBCLANG_PATH to the directory containing libclang." >&2
	exit 1
fi

export PHP_CONFIG="${php_config}"

cargo build --release --features php-extension

cat <<'MESSAGE'
Native API extension built.

Load it with:
php -d extension=extensions/native-apis/target/release/libwp_native_apis.so extensions/native-apis/tests/verify-native-apis.php
MESSAGE
