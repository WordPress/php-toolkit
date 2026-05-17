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
	-e PHP_WASM_VERSION="${php_version}" \
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
			LIBCLANG_PATH="$(find -L /usr/lib /usr/local/lib -name "libclang.so*" -print -quit)"
			if [ -z "${LIBCLANG_PATH}" ]; then
				echo "Could not find libclang.so after installing libclang-dev." >&2
				exit 1
			fi
			export LIBCLANG_PATH
		fi
		export BINDGEN_EXTRA_CLANG_ARGS="--target=wasm32-unknown-emscripten --sysroot=${EMSDK}/upstream/emscripten/cache/sysroot -DZEND_ENABLE_ZVAL_LONG64 -D__x86_64__ ${BINDGEN_EXTRA_CLANG_ARGS:-}"
		export CFLAGS_wasm32_unknown_emscripten="-fPIC ${CFLAGS_wasm32_unknown_emscripten:-}"
		export CXXFLAGS_wasm32_unknown_emscripten="-fPIC ${CXXFLAGS_wasm32_unknown_emscripten:-}"
		export CC_wasm32_unknown_emscripten="${CC_wasm32_unknown_emscripten:-emcc}"
		export CXX_wasm32_unknown_emscripten="${CXX_wasm32_unknown_emscripten:-em++}"
		export AR_wasm32_unknown_emscripten="${AR_wasm32_unknown_emscripten:-emar}"
		export RANLIB_wasm32_unknown_emscripten="${RANLIB_wasm32_unknown_emscripten:-emranlib}"

		php_cfg=""
		case "${PHP_WASM_VERSION}" in
			8.5*)
				php_cfg="--cfg php85 --cfg php84 --cfg php83 --cfg php82 --cfg php81"
				;;
			8.4*)
				php_cfg="--cfg php84 --cfg php83 --cfg php82 --cfg php81"
				;;
			8.3*)
				php_cfg="--cfg php83 --cfg php82 --cfg php81"
				;;
			8.2*)
				php_cfg="--cfg php82 --cfg php81"
				;;
			8.1*)
				php_cfg="--cfg php81"
				;;
		esac
		export RUSTFLAGS="-C panic=abort ${php_cfg} ${RUSTFLAGS:-}"

		cargo +nightly fetch --target wasm32-unknown-emscripten
		ext_php_rs_dir="$(find "${CARGO_HOME:-$HOME/.cargo}/registry/src" -maxdepth 3 -type d -name "ext-php-rs-0.15.13" -print -quit)"
		if [ -z "${ext_php_rs_dir}" ]; then
			echo "Could not find ext-php-rs 0.15.13 sources after cargo fetch." >&2
			exit 1
		fi
		# ext-php-rs 0.15.13 has a 64-bit-oriented internal size guard that is too
		# tight on wasm32. Relax it only inside this disposable PHP.wasm build image.
		perl -0pi -e "s/std::mem::size_of::<PropertyDescriptor<\\(\\)>>\\(\\) <= 12 \\* std::mem::size_of::<usize>\\(\\)/std::mem::size_of::<PropertyDescriptor<()>>() <= 16 * std::mem::size_of::<usize>()/" "${ext_php_rs_dir}/src/internal/property.rs"

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
