#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/../.." && pwd)"

php_versions="${PHP_WASM_VERSIONS:-8.0,8.1,8.2,8.3,8.4,8.5}"
out_dir="${1:-${repo_root}/build/wp_native_apis-wasm-extension}"

cd "${repo_root}"

npx --yes @php-wasm/compile-extension \
	--prepare-image \
	--php-versions "${php_versions}" \
	--jobs 1

npx --yes @php-wasm/compile-extension \
	--source ./extensions/native-apis \
	--name wp_native_apis \
	--php-versions "${php_versions}" \
	--out "${out_dir}" \
	--jobs 1

cat <<MESSAGE
Built Playground extension manifest:
${out_dir}/manifest.json
MESSAGE
