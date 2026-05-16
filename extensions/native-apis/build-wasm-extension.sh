#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/../.." && pwd)"
cd "${repo_root}"

php_wasm_version="${PHP_WASM_VERSION:-8.3}"
compile_extension_package="${PHP_WASM_COMPILE_EXTENSION_PACKAGE:-@php-wasm/compile-extension@3.1.33}"
rust_toolchain="${RUST_TOOLCHAIN:-nightly}"
out_dir="${PHP_WASM_OUT_DIR:-${script_dir}/dist/wasm}"
wrapper_source="${script_dir}/wasm-extension"
wrapper_build_dir="${wrapper_source}/build"
image_tag="playground-php-wasm:compile-extension-php${php_wasm_version//./-}-jspi"
rust_archive="${script_dir}/target/wasm32-unknown-emscripten/release/libwp_native_apis.a"
staged_archive="${wrapper_build_dir}/libwp_native_apis.a"

if ! command -v node >/dev/null 2>&1; then
	echo "Node.js was not found in PATH." >&2
	exit 1
fi

if ! command -v npx >/dev/null 2>&1; then
	echo "npx was not found in PATH." >&2
	exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker is required to build PHP.wasm native API extensions." >&2
	exit 1
fi

mkdir -p "${wrapper_build_dir}"

npx --yes "${compile_extension_package}" \
	--prepare-image \
	--php-versions "${php_wasm_version}" \
	--jobs 1

docker run --rm \
	-v "${script_dir}:/workspace/extensions/native-apis" \
	-w /workspace/extensions/native-apis \
	-e "RUST_TOOLCHAIN=${rust_toolchain}" \
	-e CARGO_HOME=/tmp/cargo-home \
	-e RUSTUP_HOME=/tmp/rustup-home \
	--entrypoint bash \
	"${image_tag}" \
	-lc '
		set -euo pipefail

		if [ -f /root/emsdk/emsdk_env.sh ]; then
			# shellcheck disable=SC1091
			source /root/emsdk/emsdk_env.sh
		fi

		if command -v emcc >/dev/null 2>&1; then
			emscripten_sysroot="${EMSDK:-/root/emsdk}/upstream/emscripten/cache/sysroot"
			zend_long_flags="-DZEND_ENABLE_ZVAL_LONG64 -D__x86_64__"
			export CC_wasm32_unknown_emscripten=emcc
			export AR_wasm32_unknown_emscripten=emar
			export CFLAGS_wasm32_unknown_emscripten="-fPIC -fwasm-exceptions ${zend_long_flags} ${CFLAGS_wasm32_unknown_emscripten:-}"
			export CXXFLAGS_wasm32_unknown_emscripten="-fPIC -fwasm-exceptions ${zend_long_flags} ${CXXFLAGS_wasm32_unknown_emscripten:-}"
			if [ -d "${emscripten_sysroot}" ]; then
				export BINDGEN_EXTRA_CLANG_ARGS="--target=wasm32-unknown-emscripten --sysroot=${emscripten_sysroot} ${zend_long_flags} ${BINDGEN_EXTRA_CLANG_ARGS:-}"
			fi
		fi

		if ! command -v cargo >/dev/null 2>&1; then
			if ! command -v curl >/dev/null 2>&1; then
				apt-get update
				apt-get install -y --no-install-recommends curl ca-certificates
			fi
			curl --proto "=https" --tlsv1.2 -sSf https://sh.rustup.rs |
				sh -s -- -y --profile minimal --default-toolchain none
			# shellcheck disable=SC1091
			source "${CARGO_HOME}/env"
		fi

		if ! find /usr/lib /lib \( -name "libclang.so*" -o -name "libclang-*.so*" \) -print -quit 2>/dev/null |
			grep -q .; then
			apt-get update
			apt-get install -y --no-install-recommends libclang-dev clang
		fi

		libclang_library="$(
			find /usr/lib /lib \( -name "libclang.so*" -o -name "libclang-*.so*" \) -print -quit 2>/dev/null || true
		)"
		if [ -z "${libclang_library}" ]; then
			echo "Unable to find libclang after installing libclang-dev." >&2
			exit 1
		fi
		export LIBCLANG_PATH="${libclang_library%/*}"

		rustup toolchain install "${RUST_TOOLCHAIN}" --profile minimal --component rust-src
		rustup target add wasm32-unknown-emscripten --toolchain "${RUST_TOOLCHAIN}"

		cargo +"${RUST_TOOLCHAIN}" fetch --target wasm32-unknown-emscripten
		ext_php_rs_dir="$(
			find "${CARGO_HOME}/registry/src" -maxdepth 2 -type d -name "ext-php-rs-0.15.13" -print -quit
		)"
		if [ -z "${ext_php_rs_dir}" ]; then
			echo "Unable to find downloaded ext-php-rs 0.15.13 source." >&2
			exit 1
		fi

		if [ ! -f "${ext_php_rs_dir}/.wp-native-apis-wasm32-patched" ]; then
			# PHP.wasm is wasm32 but compile-extension builds it with 64-bit
			# zend_long. Patch ext-php-rs wasm32 helper assumptions while
			# building this side module until upstream carries wasm32 support.
			sed -i "/into_const_num!(i64, zend_register_long_constant);/i #[cfg(target_pointer_width = \"64\")]" \
				"${ext_php_rs_dir}/src/constant.rs"
			sed -i "s/ArrayKey::Long(key))/ArrayKey::Long(key.into()))/" \
				"${ext_php_rs_dir}/src/types/array/array_key.rs"
			sed -i "s/key.set_long(self.current_num);/key.set_long(self.current_num as crate::types::ZendLong);/" \
				"${ext_php_rs_dir}/src/types/array/iterators.rs"
			sed -i "s/z.set_long(real_index);/z.set_long(real_index as crate::types::ZendLong);/" \
				"${ext_php_rs_dir}/src/types/iterator.rs"
			sed -i "s/std::mem::size_of::<PropertyDescriptor<()>>() <= 12 \* std::mem::size_of::<usize>()/std::mem::size_of::<PropertyDescriptor<()>>() <= 16 * std::mem::size_of::<usize>()/" \
				"${ext_php_rs_dir}/src/internal/property.rs"
			sed -i "s/^\([[:space:]]*\)self\\.content_length$/\1self.content_length.into()/" \
				"${ext_php_rs_dir}/src/zend/globals.rs"
			sed -i "/zend_ce_[a-z_]*,/d; /zend_standard_class_def,/d" \
				"${ext_php_rs_dir}/src/zend/ce.rs"
			sed -i "s/unsafe { zend_ce_[a-z_]*.as_ref() }.unwrap()/panic!(\"stock ClassEntry is unavailable in PHP.wasm side modules\")/g" \
				"${ext_php_rs_dir}/src/zend/ce.rs"
			sed -i "s/unsafe { zend_standard_class_def.as_ref() }.unwrap()/panic!(\"stdClass ClassEntry is unavailable in PHP.wasm side modules\")/" \
				"${ext_php_rs_dir}/src/zend/ce.rs"
			touch "${ext_php_rs_dir}/.wp-native-apis-wasm32-patched"
		fi

		RUSTFLAGS="-Zemscripten-wasm-eh -Zunstable-options -C panic=immediate-abort ${RUSTFLAGS:-}" \
			cargo +"${RUST_TOOLCHAIN}" build \
				-Zbuild-std=std \
				--release \
				--target wasm32-unknown-emscripten \
				--features php-extension
	'

if [ ! -f "${rust_archive}" ]; then
	echo "Expected Rust wasm archive was not built: ${rust_archive}" >&2
	exit 1
fi

cp "${rust_archive}" "${staged_archive}"

npx --yes "${compile_extension_package}" \
	--source "${wrapper_source}" \
	--name wp_native_apis \
	--php-versions "${php_wasm_version}" \
	--out "${out_dir}" \
	--jobs 1 \
	--extra-ldflags "/build/build/libwp_native_apis.a"

cat <<MESSAGE
Native API PHP.wasm extension built.

Manifest:
${out_dir}/manifest.json

Verify it with Playground CLI:
npx --yes @wp-playground/cli@3.1.33 php --php=${php_wasm_version} \\
	--php-extension=${out_dir}/manifest.json \\
	--wordpress-install-mode=do-not-attempt-installing \\
	--skip-sqlite-setup \\
	--mount=${script_dir}/tests:/tmp/native-api-tests \\
	-- /tmp/native-api-tests/verify-playground-wasm.php
MESSAGE
