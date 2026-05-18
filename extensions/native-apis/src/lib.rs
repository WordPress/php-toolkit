#![cfg_attr(windows, feature(abi_vectorcall))]

#[cfg(feature = "php-extension")]
use ext_php_rs::prelude::*;
#[cfg(feature = "php-extension")]
use ext_php_rs::zend::ModuleEntry;
#[cfg(feature = "php-extension")]
use ext_php_rs::{info_table_end, info_table_row, info_table_start};

mod html;
mod url_text;
mod xml;

#[cfg(feature = "php-extension")]
extern "C" fn php_module_info(_module: *mut ModuleEntry) {
    info_table_start!();
    info_table_row!("wp_native_apis", "enabled");
    info_table_row!("html", "registered");
    info_table_row!(
        "url_text",
        "registered under WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor; batch rewrite function registered"
    );
    info_table_row!("xml", "registered");
    info_table_end!();
}

#[cfg(feature = "php-extension")]
#[php_function]
pub fn wp_native_apis_extension_version() -> &'static str {
    env!("CARGO_PKG_VERSION")
}

#[cfg(feature = "php-extension")]
#[php_function]
pub fn wp_native_apis_rewrite_text_url_bases(
    text: String,
    base_url: Option<String>,
    compact_mapping: String,
) -> String {
    url_text::rewrite_text_url_bases(&text, base_url.as_deref(), &compact_mapping)
}

#[cfg(feature = "php-extension")]
#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<html::WpHtmlNativeTagProcessor>()
        .class::<html::WpHtmlNativeProcessor>()
        .class::<url_text::NativeUrlInTextProcessor>()
        .class::<xml::NativeXmlProcessor>()
        .function(wrap_function!(wp_native_apis_extension_version))
        .function(wrap_function!(wp_native_apis_rewrite_text_url_bases))
        .info_function(php_module_info)
}
