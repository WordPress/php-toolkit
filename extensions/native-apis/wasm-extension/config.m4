PHP_ARG_ENABLE(
	[wp_native_apis],
	[whether to enable the wp_native_apis extension],
	[AS_HELP_STRING([--enable-wp-native-apis], [Enable wp_native_apis])],
	[yes]
)

if test "$PHP_WP_NATIVE_APIS" != "no"; then
	PHP_NEW_EXTENSION([wp_native_apis], [wp_native_apis_wasm.c], [$ext_shared])
fi
