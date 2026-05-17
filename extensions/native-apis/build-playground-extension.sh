#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/../.." && pwd)"

php_versions="${PHP_WASM_VERSIONS:-8.4}"
php_version="${php_versions%%,*}"
out_dir="${1:-${repo_root}/build/wp_native_apis-wasm-extension}"

cd "${repo_root}"

npx --yes @php-wasm/compile-extension \
	--prepare-image \
	--php-versions "${php_version}" \
	--jobs 1

npx --yes @php-wasm/compile-extension \
	--source ./extensions/native-apis \
	--name wp_native_apis \
	--php-versions "${php_version}" \
	--out "${out_dir}" \
	--jobs 1

cat <<MESSAGE
Built Playground extension manifest:
${out_dir}/manifest.json
MESSAGE
