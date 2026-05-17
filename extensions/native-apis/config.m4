PHP_ARG_ENABLE([wp_native_apis],
	[whether to enable the wp_native_apis extension],
	[AS_HELP_STRING([--enable-wp_native_apis], [Enable wp_native_apis extension])],
	[yes])

if test "$PHP_WP_NATIVE_APIS" != "no"; then
	AC_DEFINE([PHP_WP_NATIVE_APIS_VERSION], ["0.1.0"], [wp_native_apis extension version])
	PHP_NEW_EXTENSION([wp_native_apis], [native_apis_shim.c], [$ext_shared])
fi
