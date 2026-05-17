#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/../.." && pwd)"

php_versions="${PHP_WASM_VERSIONS:-8.4}"
php_version="${php_versions%%,*}"
image_tag="playground-php-wasm:compile-extension-php${php_version//./-}-jspi"
out_dir="${1:-${script_dir}/playground/dist/wp_native_apis}"

cd "${repo_root}"

npx --yes @php-wasm/compile-extension \
	--prepare-image \
	--php-versions "${php_version}" \
	--jobs 1

docker run --rm \
	--entrypoint bash \
	-v "${script_dir}:/src" \
	-w /src \
	"${image_tag}" \
	-lc '
		set -euo pipefail

		if ! command -v cargo >/dev/null 2>&1; then
			apt-get update
			apt-get install -y --no-install-recommends ca-certificates curl pkg-config clang libclang-dev
			curl --proto "=https" --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal
			. "$HOME/.cargo/env"
		fi

		rustup toolchain install nightly --profile minimal --component rust-src
		source /root/emsdk/emsdk_env.sh

		export PHP_CONFIG=/usr/local/bin/php-config
		if [ -z "${LIBCLANG_PATH:-}" ]; then
			LIBCLANG_PATH="$(find /usr/lib -name "libclang.so*" -type f -print -quit)"
			LIBCLANG_PATH="$(dirname "${LIBCLANG_PATH}")"
			export LIBCLANG_PATH
		fi
		export BINDGEN_EXTRA_CLANG_ARGS="--target=wasm32-unknown-emscripten --sysroot=${EMSDK}/upstream/emscripten/cache/sysroot -DZEND_ENABLE_ZVAL_LONG64 -D__x86_64__ ${BINDGEN_EXTRA_CLANG_ARGS:-}"
		export CFLAGS_wasm32_unknown_emscripten="-fPIC ${CFLAGS_wasm32_unknown_emscripten:-}"
		export CXXFLAGS_wasm32_unknown_emscripten="-fPIC ${CXXFLAGS_wasm32_unknown_emscripten:-}"
		export CC_wasm32_unknown_emscripten="${CC_wasm32_unknown_emscripten:-emcc}"
		export CXX_wasm32_unknown_emscripten="${CXX_wasm32_unknown_emscripten:-em++}"
		export AR_wasm32_unknown_emscripten="${AR_wasm32_unknown_emscripten:-emar}"
		export RANLIB_wasm32_unknown_emscripten="${RANLIB_wasm32_unknown_emscripten:-emranlib}"
		export RUSTFLAGS="-C panic=abort ${RUSTFLAGS:-}"

		cargo +nightly build \
			--release \
			--target wasm32-unknown-emscripten \
			-Zbuild-std=std,panic_abort \
			--features php-extension
	'

npx --yes @php-wasm/compile-extension \
	--source ./extensions/native-apis \
	--name wp_native_apis \
	--php-versions "${php_version}" \
	--extra-ldflags "/build/target/wasm32-unknown-emscripten/release/libwp_native_apis.a" \
	--out "${out_dir}" \
	--jobs 1

cat <<MESSAGE
Built Playground extension manifest:
${out_dir}/manifest.json
MESSAGE
