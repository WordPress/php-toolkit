#![cfg_attr(not(feature = "php-extension"), allow(dead_code))]

use std::borrow::Cow;
use std::collections::HashMap;

#[cfg(feature = "php-extension")]
use ext_php_rs::prelude::*;
#[cfg(feature = "php-extension")]
use ext_php_rs::{
    boxed::ZBox,
    types::{ZendCallable, ZendHashTable, Zval},
    zend::Function,
};

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct HtmlTag {
    pub name: String,
    pub token_type: String,
    pub closing: bool,
    pub attributes: HashMap<String, String>,
    pub attribute_order: Vec<String>,
    pub attribute_name_initials: u32,
    pub source_start: usize,
    pub source_end: usize,
    pub text: String,
    pub comment_type: Option<String>,
    pub full_comment_text: Option<String>,
    pub breadcrumbs: Vec<String>,
    pub depth: usize,
}

#[cfg(feature = "php-extension")]
#[php_class]
#[php(name = "WP_HTML_Native_Tag_Processor")]
pub struct WpHtmlNativeTagProcessor {
    html: String,
    offset: usize,
    breadcrumbs: Vec<String>,
    parsing_namespace: String,
    synthesize_implied_closers: bool,
    ignore_html_body_starts: bool,
    current: Option<HtmlTag>,
    removals: Vec<HtmlTextRemoval>,
    removed_attributes: Vec<HtmlRemovedAttribute>,
    updated_attributes: Vec<HtmlUpdatedAttribute>,
    bookmarks: HashMap<String, HtmlBookmark>,
}

#[derive(Clone)]
struct HtmlBookmark {
    offset: usize,
    breadcrumbs: Vec<String>,
    parsing_namespace: String,
    synthesize_implied_closers: bool,
    ignore_html_body_starts: bool,
    current: Option<HtmlTag>,
}

struct HtmlTextRemoval {
    start: usize,
    length: usize,
    replacement: String,
}

struct HtmlRemovedAttribute {
    tag_start: usize,
    tag_end: usize,
    name: String,
}

struct HtmlUpdatedAttribute {
    tag_start: usize,
    tag_end: usize,
    name: String,
    value: Option<String>,
}

type HtmlAttributePrefixRemovals = (i64, Vec<(usize, usize)>, Vec<String>);

enum HtmlAttributeValue {
    Boolean,
    String(String),
}

#[cfg(feature = "php-extension")]
impl WpHtmlNativeTagProcessor {
    fn get_attribute_value(&self, name: &str) -> Option<HtmlAttributeValue> {
        self.current_tag().and_then(|tag| {
            if tag.token_type == "#tag" {
                if self.removed_attributes.iter().any(|removed| {
                    removed.tag_start == tag.source_start
                        && removed.tag_end == tag.source_end
                        && removed.name == name
                }) {
                    return None;
                }

                if let Some(updated) = self.updated_attributes.iter().rev().find(|updated| {
                    updated.tag_start == tag.source_start
                        && updated.tag_end == tag.source_end
                        && updated.name == name
                }) {
                    return match updated.value.as_ref() {
                        Some(value) => Some(HtmlAttributeValue::String(value.clone())),
                        None => Some(HtmlAttributeValue::Boolean),
                    };
                }

                tag.attributes
                    .get(name)
                    .cloned()
                    .map(|value| {
                        if value.is_empty()
                            && !find_html_attribute_has_value(
                                self.html.as_bytes(),
                                tag.source_start,
                                tag.source_end,
                                name,
                            )
                            .unwrap_or(true)
                        {
                            HtmlAttributeValue::Boolean
                        } else {
                            HtmlAttributeValue::String(value)
                        }
                    })
                    .or_else(|| {
                        match find_html_attribute_has_value(
                            self.html.as_bytes(),
                            tag.source_start,
                            tag.source_end,
                            name,
                        ) {
                            Some(false) => Some(HtmlAttributeValue::Boolean),
                            Some(true) => find_html_attribute_value(
                                self.html.as_bytes(),
                                tag.source_start,
                                tag.source_end,
                                name,
                            )
                            .map(HtmlAttributeValue::String),
                            None => None,
                        }
                    })
            } else {
                None
            }
        })
    }
}

#[cfg(feature = "php-extension")]
#[php_impl]
#[php(change_method_case = "snake_case")]
impl WpHtmlNativeTagProcessor {
    pub fn __construct(html: String) -> Self {
        Self {
            html,
            offset: 0,
            breadcrumbs: initial_html_breadcrumbs(),
            parsing_namespace: "html".to_string(),
            synthesize_implied_closers: false,
            ignore_html_body_starts: false,
            current: None,
            removals: Vec::new(),
            removed_attributes: Vec::new(),
            updated_attributes: Vec::new(),
            bookmarks: HashMap::new(),
        }
    }

    pub fn supports_public_api() -> bool {
        true
    }

    #[php(optional = query)]
    pub fn next_tag(&mut self, _query: Option<&Zval>) -> bool {
        self.next_tag_any(false, 1)
    }

    pub fn next_tag_any(&mut self, visit_closers: bool, mut match_offset: i64) -> bool {
        if match_offset < 1 {
            match_offset = 1;
        }

        while self.advance_to_next_tag_token() {
            if let Some(tag) = self.current_tag() {
                if visit_closers || !tag.closing {
                    match_offset -= 1;
                    if match_offset == 0 {
                        return true;
                    }
                }
            }
        }

        false
    }

    pub fn next_tag_any_metadata(
        &mut self,
        visit_closers: bool,
        match_offset: i64,
    ) -> Option<String> {
        if !self.next_tag_any(visit_closers, match_offset) {
            return None;
        }

        self.current_token_metadata()
    }

    pub fn next_tag_any_kind(&mut self, visit_closers: bool, match_offset: i64) -> i64 {
        if !self.next_tag_any(visit_closers, match_offset) {
            return 0;
        }

        if self.is_tag_closer() {
            2
        } else {
            1
        }
    }

    pub fn next_tag_any_kind_and_attribute_name_initials(
        &mut self,
        visit_closers: bool,
        match_offset: i64,
    ) -> i64 {
        if !self.next_tag_any(visit_closers, match_offset) {
            return 0;
        }

        let tag_kind = if self.is_tag_closer() { 2 } else { 1 };
        let attribute_name_initials = self
            .current_tag()
            .map(|tag| tag.attribute_name_initials as i64)
            .unwrap_or(0);

        tag_kind | (attribute_name_initials << 2)
    }

    pub fn next_tag_any_kind_and_attribute_name_initials_skip(&mut self) -> i64 {
        self.next_tag_any_kind_and_attribute_name_initials(false, 1)
    }

    pub fn next_tag_any_kind_and_attribute_name_initials_visit(&mut self) -> i64 {
        self.next_tag_any_kind_and_attribute_name_initials(true, 1)
    }

    pub fn next_tag_compact_summary_batch(
        &mut self,
        mut max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        if max_tags <= 0 {
            return None;
        }

        let mut rows = String::new();

        while max_tags > 0 && self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            if !rows.is_empty() {
                rows.push('\x1e');
            }

            rows.push_str(&tag.name.to_ascii_uppercase());
            rows.push('\x1f');
            rows.push(if tag.closing { '1' } else { '0' });

            max_tags -= 1;
        }

        if rows.is_empty() {
            None
        } else {
            Some(rows)
        }
    }

    pub fn next_tag_summary_batch(
        &mut self,
        max_tags: i64,
        visit_closers: bool,
    ) -> Vec<Vec<(String, Zval)>> {
        let limit = if max_tags > 0 {
            max_tags.min(256) as usize
        } else {
            return Vec::new();
        };
        let mut rows = Vec::new();

        while rows.len() < limit && self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            rows.push(html_tag_public_summary_row(tag));
        }

        rows
    }

    pub fn summarize_tag_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut open_tag_count = 0i64;
        let mut closing_tag_count = 0i64;
        let mut attribute_count = 0i64;
        let mut tag_names: std::collections::HashSet<String> = std::collections::HashSet::new();

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;
            tag_names.insert(tag.name.clone());

            if tag.closing {
                closing_tag_count += 1;
            } else {
                open_tag_count += 1;
                attribute_count += find_html_attribute_names_with_prefix_count(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    "",
                )
                .unwrap_or(0);
            }
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}\x1f{}",
            tag_count,
            open_tag_count,
            closing_tag_count,
            attribute_count,
            tag_names.len()
        )
    }

    pub fn summarize_heading_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut heading_count = 0i64;
        let mut h1_count = 0i64;
        let mut h2_count = 0i64;
        let mut h3_count = 0i64;
        let mut h4_count = 0i64;
        let mut h5_count = 0i64;
        let mut h6_count = 0i64;

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing {
                continue;
            }

            match tag.name.as_str() {
                "h1" => {
                    heading_count += 1;
                    h1_count += 1;
                }
                "h2" => {
                    heading_count += 1;
                    h2_count += 1;
                }
                "h3" => {
                    heading_count += 1;
                    h3_count += 1;
                }
                "h4" => {
                    heading_count += 1;
                    h4_count += 1;
                }
                "h5" => {
                    heading_count += 1;
                    h5_count += 1;
                }
                "h6" => {
                    heading_count += 1;
                    h6_count += 1;
                }
                _ => {}
            }
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
            tag_count, heading_count, h1_count, h2_count, h3_count, h4_count, h5_count, h6_count
        )
    }

    pub fn summarize_id_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut id_tag_count = 0i64;
        let mut duplicate_id_count = 0i64;
        let mut id_value_bytes = 0i64;
        let mut ids: std::collections::HashSet<String> = std::collections::HashSet::new();

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing || 0 == tag.attribute_name_initials {
                continue;
            }

            let Some(has_value) = find_html_attribute_has_value(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "id",
            ) else {
                continue;
            };

            id_tag_count += 1;

            if has_value {
                let id = find_html_attribute_value(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    "id",
                )
                .unwrap_or_default();

                id_value_bytes += id.len() as i64;
                if !ids.insert(id) {
                    duplicate_id_count += 1;
                }
            }
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}\x1f{}",
            tag_count,
            id_tag_count,
            ids.len(),
            duplicate_id_count,
            id_value_bytes
        )
    }

    pub fn summarize_attribute_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut attribute_count = 0i64;
        let mut attribute_value_bytes = 0i64;
        let mut attribute_names: std::collections::HashSet<String> =
            std::collections::HashSet::new();

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing || 0 == tag.attribute_name_initials {
                continue;
            }

            let Some(current_attribute_names) = find_html_attribute_names_with_prefix(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "",
            ) else {
                continue;
            };

            for attribute_name in current_attribute_names {
                attribute_count += 1;
                attribute_names.insert(attribute_name.clone());

                if find_html_attribute_has_value(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    &attribute_name,
                )
                .unwrap_or(false)
                {
                    attribute_value_bytes += find_html_attribute_value(
                        self.html.as_bytes(),
                        tag.source_start,
                        tag.source_end,
                        &attribute_name,
                    )
                    .map(|value| value.len() as i64)
                    .unwrap_or(0);
                }
            }
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}",
            tag_count,
            attribute_count,
            attribute_names.len(),
            attribute_value_bytes
        )
    }

    pub fn summarize_data_attribute_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut data_attribute_tag_count = 0i64;
        let mut data_attribute_count = 0i64;
        let mut data_attribute_value_bytes = 0i64;
        let mut data_attribute_names: std::collections::HashSet<String> =
            std::collections::HashSet::new();

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing || 0 == tag.attribute_name_initials {
                continue;
            }

            let Some(current_attribute_names) = find_html_attribute_names_with_prefix(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "data-",
            ) else {
                continue;
            };

            if current_attribute_names.is_empty() {
                continue;
            }

            data_attribute_tag_count += 1;
            for attribute_name in current_attribute_names {
                data_attribute_count += 1;
                data_attribute_names.insert(attribute_name.clone());

                if find_html_attribute_has_value(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    &attribute_name,
                )
                .unwrap_or(false)
                {
                    data_attribute_value_bytes += find_html_attribute_value(
                        self.html.as_bytes(),
                        tag.source_start,
                        tag.source_end,
                        &attribute_name,
                    )
                    .map(|value| value.len() as i64)
                    .unwrap_or(0);
                }
            }
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}\x1f{}",
            tag_count,
            data_attribute_tag_count,
            data_attribute_count,
            data_attribute_names.len(),
            data_attribute_value_bytes
        )
    }

    pub fn summarize_aria_attribute_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut aria_attribute_tag_count = 0i64;
        let mut aria_attribute_count = 0i64;
        let mut aria_attribute_value_bytes = 0i64;
        let mut aria_attribute_names: std::collections::HashSet<String> =
            std::collections::HashSet::new();

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing || 0 == tag.attribute_name_initials {
                continue;
            }

            let Some(current_attribute_names) = find_html_attribute_names_with_prefix(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "aria-",
            ) else {
                continue;
            };

            if current_attribute_names.is_empty() {
                continue;
            }

            aria_attribute_tag_count += 1;
            for attribute_name in current_attribute_names {
                aria_attribute_count += 1;
                aria_attribute_names.insert(attribute_name.clone());

                if find_html_attribute_has_value(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    &attribute_name,
                )
                .unwrap_or(false)
                {
                    aria_attribute_value_bytes += find_html_attribute_value(
                        self.html.as_bytes(),
                        tag.source_start,
                        tag.source_end,
                        &attribute_name,
                    )
                    .map(|value| value.len() as i64)
                    .unwrap_or(0);
                }
            }
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}\x1f{}",
            tag_count,
            aria_attribute_tag_count,
            aria_attribute_count,
            aria_attribute_names.len(),
            aria_attribute_value_bytes
        )
    }

    pub fn summarize_class_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut class_attribute_count = 0i64;
        let mut class_name_count = 0i64;
        let mut class_value_bytes = 0i64;
        let mut class_names: std::collections::HashSet<String> = std::collections::HashSet::new();
        let class_initial_bit = 1u32 << (b'c' - b'a');

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing || 0 == (tag.attribute_name_initials & class_initial_bit) {
                continue;
            }

            let has_class = find_html_attribute_has_value(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "class",
            );
            let Some(has_value) = has_class else {
                continue;
            };

            class_attribute_count += 1;

            let class_value = if has_value {
                find_html_attribute_value(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    "class",
                )
                .unwrap_or_default()
            } else {
                String::new()
            };
            class_value_bytes += class_value.len() as i64;

            let mut tag_class_names: Vec<String> = Vec::new();
            for class_name in class_value.split(['\t', '\n', '\x0c', '\r', ' ']) {
                if class_name.is_empty() {
                    continue;
                }

                let normalized = class_name.replace('\0', "\u{fffd}");
                if tag_class_names.iter().any(|seen| seen == &normalized) {
                    continue;
                }

                class_name_count += 1;
                class_names.insert(normalized.clone());
                tag_class_names.push(normalized);
            }
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}\x1f{}",
            tag_count,
            class_attribute_count,
            class_name_count,
            class_names.len(),
            class_value_bytes
        )
    }

    pub fn summarize_resource_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut resource_tag_count = 0i64;
        let mut resource_attribute_count = 0i64;
        let mut resource_value_bytes = 0i64;
        let mut resource_tag_names: std::collections::HashSet<String> =
            std::collections::HashSet::new();

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing {
                continue;
            }

            let attribute_name = match tag.name.as_str() {
                "a" | "link" => "href",
                "img" | "script" | "source" => "src",
                _ => continue,
            };

            let has_attribute = find_html_attribute_has_value(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                attribute_name,
            );
            let Some(has_value) = has_attribute else {
                continue;
            };

            resource_tag_count += 1;
            resource_attribute_count += 1;
            resource_tag_names.insert(tag.name.clone());

            if has_value {
                resource_value_bytes += find_html_attribute_value(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    attribute_name,
                )
                .map(|value| value.len() as i64)
                .unwrap_or(0);
            }
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}\x1f{}",
            tag_count,
            resource_tag_count,
            resource_attribute_count,
            resource_tag_names.len(),
            resource_value_bytes
        )
    }

    pub fn summarize_image_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut image_count = 0i64;
        let mut src_count = 0i64;
        let mut alt_count = 0i64;
        let mut empty_alt_count = 0i64;
        let mut dimension_count = 0i64;
        let mut src_value_bytes = 0i64;
        let mut alt_value_bytes = 0i64;

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing || tag.name != "img" {
                continue;
            }

            image_count += 1;

            if let Some(has_value) = find_html_attribute_has_value(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "src",
            ) {
                src_count += 1;
                if has_value {
                    src_value_bytes += find_html_attribute_value(
                        self.html.as_bytes(),
                        tag.source_start,
                        tag.source_end,
                        "src",
                    )
                    .map(|value| value.len() as i64)
                    .unwrap_or(0);
                }
            }

            if let Some(has_value) = find_html_attribute_has_value(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "alt",
            ) {
                alt_count += 1;
                if has_value {
                    let alt_value = find_html_attribute_value(
                        self.html.as_bytes(),
                        tag.source_start,
                        tag.source_end,
                        "alt",
                    )
                    .unwrap_or_default();
                    if alt_value.is_empty() {
                        empty_alt_count += 1;
                    }
                    alt_value_bytes += alt_value.len() as i64;
                } else {
                    empty_alt_count += 1;
                }
            }

            if find_html_attribute_has_value(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "width",
            )
            .is_some()
                && find_html_attribute_has_value(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    "height",
                )
                .is_some()
            {
                dimension_count += 1;
            }
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
            tag_count,
            image_count,
            src_count,
            alt_count,
            empty_alt_count,
            dimension_count,
            src_value_bytes,
            alt_value_bytes
        )
    }

    pub fn summarize_script_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut script_count = 0i64;
        let mut src_count = 0i64;
        let mut module_count = 0i64;
        let mut async_count = 0i64;
        let mut defer_count = 0i64;
        let mut inline_script_bytes = 0i64;
        let mut src_value_bytes = 0i64;

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing || tag.name != "script" {
                continue;
            }

            script_count += 1;

            if let Some(has_value) = find_html_attribute_has_value(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "src",
            ) {
                src_count += 1;
                if has_value {
                    src_value_bytes += find_html_attribute_value(
                        self.html.as_bytes(),
                        tag.source_start,
                        tag.source_end,
                        "src",
                    )
                    .map(|value| value.len() as i64)
                    .unwrap_or(0);
                }
            } else {
                inline_script_bytes += tag.text.len() as i64;
            }

            if Some(true)
                == find_html_attribute_has_value(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    "type",
                )
                && find_html_attribute_value(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    "type",
                )
                .map(|value| value.eq_ignore_ascii_case("module"))
                .unwrap_or(false)
            {
                module_count += 1;
            }

            if find_html_attribute_has_value(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "async",
            )
            .is_some()
            {
                async_count += 1;
            }

            if find_html_attribute_has_value(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "defer",
            )
            .is_some()
            {
                defer_count += 1;
            }
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
            tag_count,
            script_count,
            src_count,
            module_count,
            async_count,
            defer_count,
            inline_script_bytes,
            src_value_bytes
        )
    }

    pub fn summarize_form_inventory(&mut self, visit_closers: bool) -> String {
        let mut tag_count = 0i64;
        let mut form_count = 0i64;
        let mut control_count = 0i64;
        let mut named_control_count = 0i64;
        let mut control_name_value_bytes = 0i64;
        let mut control_names: std::collections::HashSet<String> = std::collections::HashSet::new();

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing {
                continue;
            }

            match tag.name.as_str() {
                "form" => {
                    form_count += 1;
                    continue;
                }
                "input" | "select" | "textarea" | "button" => {
                    control_count += 1;
                }
                _ => continue,
            }

            let has_name = find_html_attribute_has_value(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                "name",
            );
            let Some(has_value) = has_name else {
                continue;
            };

            named_control_count += 1;

            let name_value = if has_value {
                find_html_attribute_value(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    "name",
                )
                .unwrap_or_default()
            } else {
                String::new()
            };
            control_name_value_bytes += name_value.len() as i64;
            control_names.insert(name_value);
        }

        format!(
            "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
            tag_count,
            form_count,
            control_count,
            named_control_count,
            control_names.len(),
            control_name_value_bytes
        )
    }

    pub fn next_matching_tag_compact_summary_batch(
        &mut self,
        tag_name: String,
        mut max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        if max_tags <= 0 {
            return None;
        }

        let tag_name = tag_name.to_ascii_lowercase();
        let mut rows = String::new();

        while max_tags > 0 && self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.name != tag_name {
                continue;
            }

            if tag.closing && !visit_closers {
                continue;
            }

            if !rows.is_empty() {
                rows.push('\x1e');
            }

            rows.push_str(&tag.name.to_ascii_uppercase());
            rows.push('\x1f');
            rows.push(if tag.closing { '1' } else { '0' });

            max_tags -= 1;
        }

        if rows.is_empty() {
            None
        } else {
            Some(rows)
        }
    }

    pub fn next_matching_tag_summary_batch(
        &mut self,
        tag_name: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Vec<Vec<(String, Zval)>> {
        let limit = if max_tags > 0 {
            max_tags.min(256) as usize
        } else {
            return Vec::new();
        };
        let tag_name = tag_name.to_ascii_lowercase();
        let mut rows = Vec::new();

        while rows.len() < limit && self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.name != tag_name {
                continue;
            }

            if tag.closing && !visit_closers {
                continue;
            }

            rows.push(html_tag_public_summary_row(tag));
        }

        rows
    }

    pub fn next_matching_tag_attribute_compact_summary_batch(
        &mut self,
        tag_name: String,
        attribute_name: String,
        mut max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        if max_tags <= 0 {
            return None;
        }

        let tag_name = tag_name.to_ascii_lowercase();
        let attribute_name = attribute_name.to_ascii_lowercase();
        let mut rows = String::new();

        while max_tags > 0 && self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.name != tag_name {
                continue;
            }

            if tag.closing && !visit_closers {
                continue;
            }

            let attribute_value = if tag.closing {
                None
            } else {
                tag.attributes.get(&attribute_name).cloned().or_else(|| {
                    find_html_attribute_value(
                        self.html.as_bytes(),
                        tag.source_start,
                        tag.source_end,
                        &attribute_name,
                    )
                })
            };

            if !rows.is_empty() {
                rows.push('\x1e');
            }

            rows.push_str(&tag.name.to_ascii_uppercase());
            rows.push('\x1f');
            rows.push(if tag.closing { '1' } else { '0' });
            rows.push('\x1f');
            match attribute_value {
                Some(value) => {
                    rows.push('1');
                    rows.push_str(&value);
                }
                None => rows.push('0'),
            }

            max_tags -= 1;
        }

        if rows.is_empty() {
            None
        } else {
            Some(rows)
        }
    }

    pub fn next_matching_tag_attribute_summary_batch(
        &mut self,
        tag_name: String,
        attribute_name: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Vec<Vec<(String, Zval)>> {
        let limit = if max_tags > 0 {
            max_tags.min(256) as usize
        } else {
            return Vec::new();
        };
        let tag_name = tag_name.to_ascii_lowercase();
        let attribute_name = attribute_name.to_ascii_lowercase();
        let mut rows = Vec::new();

        while rows.len() < limit && self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.name != tag_name {
                continue;
            }

            if tag.closing && !visit_closers {
                continue;
            }

            rows.push(html_matching_tag_attribute_public_summary_row(
                self.html.as_bytes(),
                tag,
                &attribute_name,
            ));
        }

        rows
    }

    pub fn next_matching_tag_attributes_compact_summary_batch(
        &mut self,
        tag_name: String,
        attribute_names: String,
        mut max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        if max_tags <= 0 {
            return None;
        }

        let tag_name = tag_name.to_ascii_lowercase();
        let attribute_names: Vec<String> = attribute_names
            .split('\x1f')
            .filter(|name| !name.is_empty())
            .map(|name| name.to_ascii_lowercase())
            .collect();
        let attribute_initial_bits = html_attribute_names_initial_bits(&attribute_names);
        let mut rows = String::new();

        while max_tags > 0 && self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.name != tag_name {
                continue;
            }

            if tag.closing && !visit_closers {
                continue;
            }

            let attribute_values = if tag.closing
                || attribute_names.is_empty()
                || (attribute_initial_bits != 0
                    && 0 == (tag.attribute_name_initials & attribute_initial_bits))
            {
                Vec::new()
            } else {
                find_html_attribute_values(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    &attribute_names,
                )
            };

            if !rows.is_empty() {
                rows.push('\x1e');
            }

            rows.push_str(&tag.name.to_ascii_uppercase());
            rows.push('\x1f');
            rows.push(if tag.closing { '1' } else { '0' });
            for index in 0..attribute_names.len() {
                rows.push('\x1f');
                match attribute_values.get(index).and_then(|value| value.as_ref()) {
                    Some(value) => {
                        rows.push('1');
                        rows.push_str(value);
                    }
                    None => rows.push('0'),
                }
            }

            max_tags -= 1;
        }

        if rows.is_empty() {
            None
        } else {
            Some(rows)
        }
    }

    pub fn next_matching_tag_attributes_summary_batch(
        &mut self,
        tag_name: String,
        attribute_names: Vec<String>,
        max_tags: i64,
        visit_closers: bool,
    ) -> Vec<Vec<(String, Zval)>> {
        let limit = if max_tags > 0 {
            max_tags.min(256) as usize
        } else {
            return Vec::new();
        };
        let tag_name = tag_name.to_ascii_lowercase();
        let attribute_names: Vec<String> = attribute_names
            .into_iter()
            .filter(|name| !name.is_empty())
            .map(|name| name.to_ascii_lowercase())
            .collect();
        let attribute_initial_bits = html_attribute_names_initial_bits(&attribute_names);
        let mut rows = Vec::new();

        while rows.len() < limit && self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.name != tag_name {
                continue;
            }

            if tag.closing && !visit_closers {
                continue;
            }

            rows.push(html_matching_tag_attributes_public_summary_row(
                self.html.as_bytes(),
                tag,
                &attribute_names,
                attribute_initial_bits,
            ));
        }

        rows
    }

    pub fn summarize_matching_tag_attributes(
        &mut self,
        tag_name: String,
        attribute_names: String,
        visit_closers: bool,
    ) -> String {
        let tag_name = tag_name.to_ascii_lowercase();
        let attribute_names: Vec<String> = attribute_names
            .split('\x1f')
            .filter(|name| !name.is_empty())
            .map(|name| name.to_ascii_lowercase())
            .collect();
        let attribute_initial_bits = html_attribute_names_initial_bits(&attribute_names);
        let mut tag_count = 0i64;
        let mut attribute_count = 0i64;
        let mut attribute_value_bytes = 0i64;

        while self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.name != tag_name {
                continue;
            }

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;

            if tag.closing
                || attribute_names.is_empty()
                || (attribute_initial_bits != 0
                    && 0 == (tag.attribute_name_initials & attribute_initial_bits))
            {
                continue;
            }

            for value in find_html_attribute_values(
                self.html.as_bytes(),
                tag.source_start,
                tag.source_end,
                &attribute_names,
            )
            .into_iter()
            .flatten()
            {
                attribute_count += 1;
                attribute_value_bytes += value.len() as i64;
            }
        }

        format!(
            "{}\x1f{}\x1f{}",
            tag_count, attribute_count, attribute_value_bytes
        )
    }

    pub fn next_token(&mut self) -> bool {
        self.advance_to_next_token()
    }

    pub fn current_token_metadata(&self) -> Option<String> {
        self.current_tag().map(token_metadata)
    }

    pub fn get_tag(&self) -> Option<String> {
        self.current_tag().and_then(|tag| {
            if tag.token_type == "#tag" {
                Some(tag.name.to_ascii_uppercase())
            } else if tag.token_type == "#comment"
                && tag.comment_type.as_deref() == Some("COMMENT_AS_PI_NODE_LOOKALIKE")
            {
                Some(tag.name.clone())
            } else {
                None
            }
        })
    }

    pub fn get_token_name(&self) -> Option<String> {
        self.current_tag().map(|tag| {
            if tag.token_type == "#tag" {
                tag.name.to_ascii_uppercase()
            } else if tag.token_type == "#doctype" {
                tag.name.clone()
            } else {
                tag.token_type.clone()
            }
        })
    }

    pub fn get_token_type(&self) -> Option<String> {
        self.current_tag().map(|tag| tag.token_type.clone())
    }

    pub fn get_doctype_info(&self) -> Option<Zval> {
        html_doctype_info_zval(&self.html, self.current_tag()?)
    }

    pub fn is_tag_closer(&self) -> bool {
        self.current_tag().map(|tag| tag.closing).unwrap_or(false)
    }

    pub fn get_namespace(&self) -> String {
        self.parsing_namespace.clone()
    }

    pub fn change_parsing_namespace(&mut self, new_namespace: String) -> bool {
        if !matches!(new_namespace.as_str(), "html" | "math" | "svg") {
            return false;
        }

        self.parsing_namespace = new_namespace;

        true
    }

    pub fn get_qualified_tag_name(&self) -> Option<String> {
        self.get_tag()
    }

    pub fn get_qualified_attribute_name(&self, name: String) -> Option<String> {
        match self.current_tag() {
            Some(tag) if tag.token_type == "#tag" => Some(name),
            _ => None,
        }
    }

    pub fn has_self_closing_flag(&self) -> bool {
        match self.current_tag() {
            Some(tag) if tag.token_type == "#tag" && !tag.closing => {
                html_tag_has_self_closing_flag(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                )
            }
            _ => false,
        }
    }

    pub fn get_attribute(&self, name: String) -> Zval {
        let name = name.to_ascii_lowercase();

        match self.get_attribute_value(&name) {
            Some(HtmlAttributeValue::Boolean) => html_zval_bool(true),
            Some(HtmlAttributeValue::String(value)) => html_zval_string(&value),
            None => html_zval_null(),
        }
    }

    pub fn get_attribute_names_with_prefix(&self, prefix: String) -> Option<Vec<String>> {
        let prefix = prefix.to_ascii_lowercase();
        match self.current_tag() {
            Some(tag) if tag.token_type == "#tag" && !tag.closing => {
                find_html_attribute_names_with_prefix(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    &prefix,
                )
            }
            _ => None,
        }
    }

    pub fn class_list(&self) -> Option<Vec<String>> {
        let tag = self.current_tag()?;
        if tag.token_type != "#tag" || tag.closing {
            return None;
        }

        let class_attribute = match self.get_attribute_value("class")? {
            HtmlAttributeValue::Boolean => String::new(),
            HtmlAttributeValue::String(value) => value,
        };
        let mut classes = Vec::new();
        for class_name in class_attribute.split(['\t', '\n', '\x0c', '\r', ' ']) {
            if class_name.is_empty() {
                continue;
            }

            let normalized = class_name.replace('\0', "\u{fffd}");
            if !classes.iter().any(|seen| seen == &normalized) {
                classes.push(normalized);
            }
        }

        Some(classes)
    }

    pub fn has_class(&self, wanted_class: String) -> Option<bool> {
        let classes = self.class_list()?;
        Some(classes.iter().any(|class_name| class_name == &wanted_class))
    }

    pub fn get_attribute_names_with_prefix_string(&self, prefix: String) -> Option<String> {
        let prefix = prefix.to_ascii_lowercase();
        match self.current_tag() {
            Some(tag) if tag.token_type == "#tag" && !tag.closing => {
                find_html_attribute_names_with_prefix_string(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    &prefix,
                )
            }
            _ => None,
        }
    }

    pub fn count_attribute_names_with_prefix(&self, prefix: String) -> Option<i64> {
        let prefix = prefix.to_ascii_lowercase();
        let prefix_initial_bit = html_attribute_prefix_initial_bit(&prefix);
        match self.current_tag() {
            Some(tag) if tag.token_type == "#tag" && !tag.closing => {
                if prefix_initial_bit != 0
                    && 0 == (tag.attribute_name_initials & prefix_initial_bit)
                {
                    return Some(0);
                }

                find_html_attribute_names_with_prefix_count(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    &prefix,
                )
            }
            _ => None,
        }
    }

    pub fn summarize_attribute_names_with_prefix(
        &mut self,
        prefix: String,
        visit_closers: bool,
    ) -> String {
        let prefix = prefix.to_ascii_lowercase();
        let prefix_initial_bit = html_attribute_prefix_initial_bit(&prefix);
        let mut tag_count = 0i64;
        let mut attr_count = 0i64;

        while self.advance_to_next_tag_token() {
            match self.current_tag() {
                Some(HtmlTag { closing: true, .. }) if visit_closers => {
                    tag_count += 1;
                }
                Some(HtmlTag { closing: true, .. }) => {}
                Some(tag) => {
                    tag_count += 1;
                    if prefix_initial_bit == 0
                        || 0 != (tag.attribute_name_initials & prefix_initial_bit)
                    {
                        attr_count += find_html_attribute_names_with_prefix_count(
                            self.html.as_bytes(),
                            tag.source_start,
                            tag.source_end,
                            &prefix,
                        )
                        .unwrap_or(0);
                    }
                }
                None => {}
            }
        }

        format!("{}\x1f{}", tag_count, attr_count)
    }

    pub fn next_tag_prefix_summary_batch(
        &mut self,
        prefix: String,
        mut max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        if max_tags <= 0 {
            return None;
        }

        let prefix = prefix.to_ascii_lowercase();
        let prefix_initial_bit = html_attribute_prefix_initial_bit(&prefix);
        let mut rows = String::new();

        while max_tags > 0 && self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            if !rows.is_empty() {
                rows.push('\x1e');
            }

            rows.push_str(&tag.name.to_ascii_uppercase());
            rows.push('\x1f');
            rows.push(if tag.closing { '1' } else { '0' });
            rows.push('\x1f');

            let count = if tag.closing
                || (prefix_initial_bit != 0
                    && 0 == (tag.attribute_name_initials & prefix_initial_bit))
            {
                0
            } else {
                find_html_attribute_names_with_prefix_count(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    &prefix,
                )
                .unwrap_or(0)
            };
            rows.push_str(&count.to_string());

            max_tags -= 1;
        }

        if rows.is_empty() {
            None
        } else {
            Some(rows)
        }
    }

    pub fn next_tag_prefix_compact_summary_batch(
        &mut self,
        prefix: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        self.next_tag_prefix_summary_batch(prefix, max_tags, visit_closers)
    }

    pub fn next_tag_prefix_count_compact_batch(
        &mut self,
        prefix: String,
        mut max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        if max_tags <= 0 {
            return None;
        }

        let prefix = prefix.to_ascii_lowercase();
        let prefix_initial_bit = html_attribute_prefix_initial_bit(&prefix);
        let mut tag_count = 0i64;
        let mut attr_count = 0i64;

        while max_tags > 0 && self.advance_to_next_tag_token() {
            let Some(tag) = self.current_tag() else {
                continue;
            };

            if tag.closing && !visit_closers {
                continue;
            }

            tag_count += 1;
            if !tag.closing
                && (prefix_initial_bit == 0
                    || 0 != (tag.attribute_name_initials & prefix_initial_bit))
            {
                attr_count += find_html_attribute_names_with_prefix_count(
                    self.html.as_bytes(),
                    tag.source_start,
                    tag.source_end,
                    &prefix,
                )
                .unwrap_or(0);
            }
            max_tags -= 1;
        }

        if tag_count == 0 {
            None
        } else {
            Some(format!("{}\x1f{}", tag_count, attr_count))
        }
    }

    pub fn remove_attribute(&mut self, name: String) -> bool {
        let name = name.to_ascii_lowercase();
        let (tag_start, tag_end) = match self.current_tag() {
            Some(tag) if tag.token_type == "#tag" && !tag.closing => {
                (tag.source_start, tag.source_end)
            }
            _ => return false,
        };
        let removals =
            find_html_attribute_removals(self.html.as_bytes(), tag_start, tag_end, &name);

        if removals.is_empty() {
            let removed_pending_update = self.updated_attributes.iter().any(|updated| {
                updated.tag_start == tag_start && updated.tag_end == tag_end && updated.name == name
            });
            if removed_pending_update {
                self.updated_attributes.retain(|updated| {
                    updated.tag_start != tag_start
                        || updated.tag_end != tag_end
                        || updated.name != name
                });
                let insertion_start = html_tag_name_end(self.html.as_bytes(), tag_start, tag_end);
                self.removals.retain(|removal| {
                    removal.start != insertion_start
                        || removal.length != 0
                        || !html_attribute_insertion_matches_name(&removal.replacement, &name)
                });
                self.removed_attributes.push(HtmlRemovedAttribute {
                    tag_start,
                    tag_end,
                    name,
                });
            }

            return false;
        }

        for (start, length) in removals {
            self.removals.push(HtmlTextRemoval {
                start,
                length,
                replacement: String::new(),
            });
        }
        self.updated_attributes.retain(|updated| {
            updated.tag_start != tag_start || updated.tag_end != tag_end || updated.name != name
        });
        self.removed_attributes.push(HtmlRemovedAttribute {
            tag_start,
            tag_end,
            name,
        });

        true
    }

    pub fn set_attribute(&mut self, name: String, value: &Zval) -> bool {
        let tag = match self.current_tag() {
            Some(tag) if tag.token_type == "#tag" && !tag.closing => tag.clone(),
            _ => return false,
        };
        if !is_valid_html_attribute_name(&name) {
            return false;
        }
        if value.is_false() {
            return self.remove_attribute(name);
        }

        let comparable_name = name.to_ascii_lowercase();
        let updated_value = if value.is_true() {
            None
        } else {
            match value.coerce_to_string() {
                Some(value) => Some(value),
                None => return false,
            }
        };
        let replacement = match updated_value.as_ref() {
            Some(value) => format!("{name}=\"{}\"", html_escape_attribute_value(value)),
            None => name.clone(),
        };
        let bytes = self.html.as_bytes();
        let (start, length, replacement) = match find_html_attribute_span(
            bytes,
            tag.source_start,
            tag.source_end,
            &comparable_name,
        ) {
            Some((start, length)) => (start, length, replacement),
            None => (
                html_tag_name_end(bytes, tag.source_start, tag.source_end),
                0,
                format!(" {replacement}"),
            ),
        };

        if length == 0 {
            self.removals.retain(|removal| {
                removal.start != start
                    || removal.length != 0
                    || !html_attribute_insertion_matches_name(
                        &removal.replacement,
                        &comparable_name,
                    )
            });
        }

        self.removals.push(HtmlTextRemoval {
            start,
            length,
            replacement,
        });
        self.removed_attributes.retain(|removed| {
            removed.tag_start != tag.source_start
                || removed.tag_end != tag.source_end
                || removed.name != comparable_name
        });
        self.updated_attributes.push(HtmlUpdatedAttribute {
            tag_start: tag.source_start,
            tag_end: tag.source_end,
            name: comparable_name,
            value: updated_value,
        });

        true
    }

    pub fn add_class(&mut self, class_name: String) -> bool {
        let tag = match self.current_tag() {
            Some(tag) if tag.token_type == "#tag" && !tag.closing => tag.clone(),
            _ => return false,
        };

        let bytes = self.html.as_bytes();
        let original = tag.attributes.get("class").cloned().or_else(|| {
            find_html_attribute_value(bytes, tag.source_start, tag.source_end, "class")
        });
        let class_attribute_removed = self.removed_attributes.iter().any(|removed| {
            removed.tag_start == tag.source_start
                && removed.tag_end == tag.source_end
                && removed.name == "class"
        });
        let existing = self
            .updated_attributes
            .iter()
            .rev()
            .find(|updated| {
                updated.tag_start == tag.source_start
                    && updated.tag_end == tag.source_end
                    && updated.name == "class"
            })
            .map(|updated| updated.value.clone().unwrap_or_default())
            .or_else(|| {
                if class_attribute_removed {
                    Some(String::new())
                } else {
                    original.clone()
                }
            });

        let updated_value = match existing {
            Some(value) => {
                if value
                    .split_ascii_whitespace()
                    .any(|name| name == class_name)
                {
                    return true;
                }

                if let Some(original_value) = original.as_deref() {
                    if original_value
                        .split_ascii_whitespace()
                        .any(|name| name == class_name)
                    {
                        let current_classes: Vec<&str> = value.split_ascii_whitespace().collect();
                        let mut restored_classes: Vec<String> = Vec::new();

                        for original_class in original_value.split_ascii_whitespace() {
                            if original_class == class_name
                                || current_classes.contains(&original_class)
                            {
                                restored_classes.push(original_class.to_string());
                            }
                        }

                        for current_class in current_classes {
                            if !restored_classes.iter().any(|name| name == current_class) {
                                restored_classes.push(current_class.to_string());
                            }
                        }

                        restored_classes.join(" ")
                    } else if value.is_empty() {
                        class_name.clone()
                    } else {
                        format!("{value} {class_name}")
                    }
                } else if value.is_empty() {
                    class_name.clone()
                } else {
                    format!("{value} {class_name}")
                }
            }
            None => class_name.clone(),
        };

        let replacement = format!("class=\"{}\"", html_escape_attribute_value(&updated_value));
        let (start, length, replacement) =
            match find_html_attribute_span(bytes, tag.source_start, tag.source_end, "class") {
                Some((start, length)) => (start, length, replacement),
                None => (
                    html_tag_name_end(bytes, tag.source_start, tag.source_end),
                    0,
                    format!(" {replacement}"),
                ),
            };

        if length == 0 {
            self.removals.retain(|removal| {
                removal.start != start
                    || removal.length != 0
                    || !html_attribute_insertion_matches_name(&removal.replacement, "class")
            });
        }

        self.removals.push(HtmlTextRemoval {
            start,
            length,
            replacement,
        });
        self.removed_attributes.retain(|removed| {
            removed.tag_start != tag.source_start
                || removed.tag_end != tag.source_end
                || removed.name != "class"
        });
        self.updated_attributes.push(HtmlUpdatedAttribute {
            tag_start: tag.source_start,
            tag_end: tag.source_end,
            name: "class".to_string(),
            value: Some(updated_value),
        });

        true
    }

    pub fn remove_class(&mut self, class_name: String) -> bool {
        let tag = match self.current_tag() {
            Some(tag) if tag.token_type == "#tag" && !tag.closing => tag.clone(),
            _ => return false,
        };

        let bytes = self.html.as_bytes();
        let existing = self
            .updated_attributes
            .iter()
            .rev()
            .find(|updated| {
                updated.tag_start == tag.source_start
                    && updated.tag_end == tag.source_end
                    && updated.name == "class"
            })
            .map(|updated| updated.value.clone().unwrap_or_default())
            .or_else(|| {
                tag.attributes.get("class").cloned().or_else(|| {
                    find_html_attribute_value(bytes, tag.source_start, tag.source_end, "class")
                })
            });
        let value = match existing {
            Some(value) => value,
            None => return true,
        };

        let remaining: Vec<&str> = value
            .split_ascii_whitespace()
            .filter(|name| *name != class_name)
            .collect();
        let updated_value = remaining.join(" ");
        if updated_value == value {
            return true;
        }

        let removes_class_attribute = updated_value.is_empty();
        let replacement = if removes_class_attribute {
            String::new()
        } else {
            format!("class=\"{}\"", html_escape_attribute_value(&updated_value))
        };
        let (start, length, replacement) =
            match find_html_attribute_span(bytes, tag.source_start, tag.source_end, "class") {
                Some((start, length)) => (start, length, replacement),
                None => (
                    html_tag_name_end(bytes, tag.source_start, tag.source_end),
                    0,
                    if replacement.is_empty() {
                        replacement
                    } else {
                        format!(" {replacement}")
                    },
                ),
            };

        if length == 0 {
            self.removals.retain(|removal| {
                removal.start != start
                    || removal.length != 0
                    || !html_attribute_insertion_matches_name(&removal.replacement, "class")
            });
        }

        self.removals.push(HtmlTextRemoval {
            start,
            length,
            replacement,
        });
        self.updated_attributes.retain(|updated| {
            updated.tag_start != tag.source_start
                || updated.tag_end != tag.source_end
                || updated.name != "class"
        });
        if removes_class_attribute {
            self.removed_attributes.push(HtmlRemovedAttribute {
                tag_start: tag.source_start,
                tag_end: tag.source_end,
                name: "class".to_string(),
            });
        } else {
            self.updated_attributes.push(HtmlUpdatedAttribute {
                tag_start: tag.source_start,
                tag_end: tag.source_end,
                name: "class".to_string(),
                value: Some(updated_value),
            });
        }

        true
    }

    pub fn remove_attributes_with_prefix(&mut self, prefix: String) -> Option<i64> {
        let prefix = prefix.to_ascii_lowercase();
        let (tag_start, tag_end) = match self.current_tag() {
            Some(tag) if tag.token_type == "#tag" && !tag.closing => {
                (tag.source_start, tag.source_end)
            }
            _ => return None,
        };
        let removed_names: Vec<String> = self
            .removed_attributes
            .iter()
            .filter(|removed| removed.tag_start == tag_start && removed.tag_end == tag_end)
            .map(|removed| removed.name.clone())
            .collect();

        let (removed_count, removals, names) = find_html_attribute_removals_with_prefix(
            self.html.as_bytes(),
            tag_start,
            tag_end,
            &prefix,
            &removed_names,
        )?;

        for (start, length) in removals {
            self.removals.push(HtmlTextRemoval {
                start,
                length,
                replacement: String::new(),
            });
        }
        for name in names {
            self.removed_attributes.push(HtmlRemovedAttribute {
                tag_start,
                tag_end,
                name,
            });
        }

        Some(removed_count)
    }

    pub fn remove_attributes_with_prefix_from_document(
        &mut self,
        prefix: String,
        visit_closers: bool,
    ) -> String {
        let prefix = prefix.to_ascii_lowercase();
        let mut tag_count = 0i64;
        let mut removed_count = 0i64;

        while self.advance_to_next_tag_token() {
            let (tag_start, tag_end, is_closing) = match self.current_tag() {
                Some(tag) => (tag.source_start, tag.source_end, tag.closing),
                None => continue,
            };

            if is_closing {
                if visit_closers {
                    tag_count += 1;
                }
                continue;
            }

            tag_count += 1;

            let removed_names: Vec<String> = self
                .removed_attributes
                .iter()
                .filter(|removed| removed.tag_start == tag_start && removed.tag_end == tag_end)
                .map(|removed| removed.name.clone())
                .collect();

            let Some((count, removals, names)) = find_html_attribute_removals_with_prefix(
                self.html.as_bytes(),
                tag_start,
                tag_end,
                &prefix,
                &removed_names,
            ) else {
                continue;
            };

            removed_count += count;
            for (start, length) in removals {
                self.removals.push(HtmlTextRemoval {
                    start,
                    length,
                    replacement: String::new(),
                });
            }
            for name in names {
                self.removed_attributes.push(HtmlRemovedAttribute {
                    tag_start,
                    tag_end,
                    name,
                });
            }
        }

        format!(
            "{}\x1f{}\x1f{}",
            tag_count,
            removed_count,
            self.get_updated_html()
        )
    }

    pub fn get_updated_html(&self) -> String {
        if self.removals.is_empty() {
            return self.html.clone();
        }

        apply_html_text_removals(&self.html, &self.removals)
    }

    #[php(name = "__toString")]
    pub fn __to_string(&self) -> String {
        self.get_updated_html()
    }

    pub fn set_bookmark(&mut self, name: String) -> bool {
        if self.current.is_none() {
            return false;
        }

        self.bookmarks.insert(
            name,
            HtmlBookmark {
                offset: self.offset,
                breadcrumbs: self.breadcrumbs.clone(),
                parsing_namespace: self.parsing_namespace.clone(),
                synthesize_implied_closers: self.synthesize_implied_closers,
                ignore_html_body_starts: self.ignore_html_body_starts,
                current: self.current.clone(),
            },
        );

        true
    }

    pub fn release_bookmark(&mut self, name: String) -> bool {
        self.bookmarks.remove(&name).is_some()
    }

    pub fn has_bookmark(&self, name: String) -> bool {
        self.bookmarks.contains_key(&name)
    }

    pub fn seek(&mut self, name: String) -> bool {
        let Some(bookmark) = self.bookmarks.get(&name).cloned() else {
            return false;
        };

        self.offset = bookmark.offset;
        self.breadcrumbs = bookmark.breadcrumbs;
        self.parsing_namespace = bookmark.parsing_namespace;
        self.synthesize_implied_closers = bookmark.synthesize_implied_closers;
        self.ignore_html_body_starts = bookmark.ignore_html_body_starts;
        self.current = bookmark.current;

        true
    }

    pub fn get_modifiable_text(&self) -> String {
        self.current_tag()
            .map(|tag| tag.text.clone())
            .unwrap_or_default()
    }

    pub fn set_modifiable_text(&mut self, plaintext_content: String) -> bool {
        let Some(tag) = self.current.as_mut() else {
            return false;
        };

        let (start, end, replacement) = match tag.token_type.as_str() {
            "#text" => (
                tag.source_start,
                tag.source_end,
                html_escape_text(&plaintext_content),
            ),
            "#comment" if tag.comment_type.as_deref() == Some("COMMENT_AS_HTML_COMMENT") => {
                if plaintext_content.contains("-->") || plaintext_content.contains("--!>") {
                    return false;
                }

                (
                    tag.source_start.saturating_add(4),
                    tag.source_end.saturating_sub(3),
                    plaintext_content.clone(),
                )
            }
            _ => return false,
        };

        if end < start || end > self.html.len() {
            return false;
        }

        self.removals.push(HtmlTextRemoval {
            start,
            length: end - start,
            replacement,
        });
        tag.text = plaintext_content.clone();
        if tag.token_type == "#comment" {
            tag.full_comment_text = Some(plaintext_content);
        }

        true
    }

    pub fn subdivide_text_appropriately(&mut self) -> bool {
        let Some(tag) = self.current.as_mut() else {
            return false;
        };

        if tag.token_type != "#text" {
            return false;
        }

        let bytes = self.html.as_bytes();
        let start = tag.source_start;
        let end = tag.source_end.min(bytes.len());
        if start >= end {
            return false;
        }

        let mut split_at = start;
        if bytes[start] == b'\0' {
            while split_at < end && bytes[split_at] == b'\0' {
                split_at += 1;
            }

            tag.text.clear();
        } else {
            while split_at < end && is_html_ascii_whitespace(bytes[split_at]) {
                split_at += 1;
            }

            if split_at == start {
                return false;
            }

            tag.text = String::from_utf8_lossy(&bytes[start..split_at]).into_owned();
            normalize_html_newlines_in_place(&mut tag.text);
        }

        tag.source_end = split_at;
        self.offset = split_at;

        true
    }

    pub fn paused_at_incomplete_token(&self) -> bool {
        false
    }

    pub fn get_comment_type(&self) -> Option<String> {
        self.current_tag().and_then(|tag| tag.comment_type.clone())
    }

    pub fn get_full_comment_text(&self) -> Option<String> {
        self.current_tag().and_then(|tag| {
            if matches!(tag.token_type.as_str(), "#comment" | "#funky-comment") {
                tag.full_comment_text.clone()
            } else {
                None
            }
        })
    }
}

#[cfg(feature = "php-extension")]
impl WpHtmlNativeTagProcessor {
    fn advance_to_next_token(&mut self) -> bool {
        self.current = parse_next_html_token(
            self.html.as_bytes(),
            &mut self.offset,
            &mut self.breadcrumbs,
            true,
            false,
            self.synthesize_implied_closers,
            self.ignore_html_body_starts,
        );
        self.current.is_some()
    }

    fn advance_to_next_tag_token(&mut self) -> bool {
        if !self.synthesize_implied_closers && !self.ignore_html_body_starts {
            self.current = parse_next_plain_html_tag_token(self.html.as_bytes(), &mut self.offset);

            return self.current.is_some();
        }

        loop {
            self.current = parse_next_html_token(
                self.html.as_bytes(),
                &mut self.offset,
                &mut self.breadcrumbs,
                false,
                false,
                self.synthesize_implied_closers,
                self.ignore_html_body_starts,
            );

            match self.current.as_ref() {
                Some(tag) if tag.token_type == "#tag" => return true,
                Some(_) => continue,
                None => return false,
            }
        }
    }

    fn current_tag(&self) -> Option<&HtmlTag> {
        self.current.as_ref()
    }
}

#[cfg(feature = "php-extension")]
#[php_class]
#[php(name = "WP_HTML_Native_Processor")]
pub struct WpHtmlNativeProcessor {
    inner: WpHtmlNativeTagProcessor,
}

#[cfg(feature = "php-extension")]
#[php_impl]
#[php(change_method_case = "snake_case")]
impl WpHtmlNativeProcessor {
    pub fn supports_public_api() -> bool {
        true
    }

    #[php(optional = context)]
    pub fn create_fragment(
        html: String,
        context: Option<String>,
        encoding: Option<String>,
    ) -> Option<Self> {
        if context.as_deref().unwrap_or("<body>") != "<body>"
            || encoding.as_deref().unwrap_or("UTF-8") != "UTF-8"
        {
            return None;
        }

        let mut inner = WpHtmlNativeTagProcessor::__construct(html);
        inner.synthesize_implied_closers = true;
        inner.ignore_html_body_starts = true;

        Some(Self { inner })
    }

    #[php(optional = known_definite_encoding)]
    pub fn create_full_parser(
        html: String,
        known_definite_encoding: Option<String>,
    ) -> Option<Self> {
        if known_definite_encoding.unwrap_or_else(|| "UTF-8".to_string()) != "UTF-8" {
            return None;
        }

        let mut inner = WpHtmlNativeTagProcessor::__construct(html);
        inner.synthesize_implied_closers = true;

        Some(Self { inner })
    }

    pub fn normalize(html: String) -> Option<String> {
        html_normalize_via_php(&html)
    }

    pub fn serialize(&mut self) -> Option<String> {
        if self.inner.offset != 0 || self.inner.current.is_some() {
            return None;
        }

        let serialized = html_normalize_via_php(&self.inner.html)?;
        self.inner.offset = self.inner.html.len();
        Some(serialized)
    }

    pub fn is_void(tag_name: String) -> bool {
        is_html_processor_void_element(&tag_name.to_ascii_lowercase())
    }

    pub fn is_special(tag_name: String) -> bool {
        is_html_processor_special_element(&tag_name.to_ascii_lowercase())
    }

    pub fn next_token(&mut self) -> bool {
        while self.inner.advance_to_next_token() {
            if self.inner.get_token_type().as_deref() != Some("#doctype") {
                return true;
            }
        }

        false
    }

    #[php(optional = node_to_process)]
    pub fn step(&mut self, node_to_process: Option<String>) -> bool {
        match node_to_process.as_deref().unwrap_or("process-next-node") {
            "process-next-node" => self.next_token(),
            "reprocess-current-node" => self.inner.current_tag().is_some(),
            _ => false,
        }
    }

    pub fn get_doctype_info(&self) -> Option<Zval> {
        self.inner.get_doctype_info()
    }

    pub fn next_token_metadata(&mut self) -> Option<String> {
        if !self.next_token() {
            return None;
        }

        self.current_token_metadata()
    }

    pub fn next_token_compact_summary_batch(&mut self, mut max_tokens: i64) -> Option<String> {
        if max_tokens <= 0 {
            return None;
        }

        let mut rows = String::new();
        while max_tokens > 0 && self.next_token() {
            let Some(token) = self.inner.current_tag() else {
                continue;
            };

            if !rows.is_empty() {
                rows.push('\x1e');
            }

            rows.push_str(&html_token_compact_summary(token));
            max_tokens -= 1;
        }

        if rows.is_empty() {
            None
        } else {
            Some(rows)
        }
    }

    pub fn next_token_summary_batch(&mut self, max_tokens: i64) -> Vec<Vec<(String, Zval)>> {
        let limit = if max_tokens > 0 {
            max_tokens.min(256) as usize
        } else {
            return Vec::new();
        };
        let mut rows = Vec::new();

        while rows.len() < limit && self.next_token() {
            let Some(token) = self.inner.current_tag() else {
                continue;
            };

            rows.push(html_token_public_summary_row(token));
        }

        rows
    }

    #[php(optional = query)]
    pub fn next_tag(&mut self, query: Option<&Zval>) -> bool {
        self.inner.next_tag(query)
    }

    pub fn next_tag_summary_batch(
        &mut self,
        max_tags: i64,
        visit_closers: bool,
    ) -> Vec<Vec<(String, Zval)>> {
        self.inner.next_tag_summary_batch(max_tags, visit_closers)
    }

    pub fn next_tag_compact_summary_batch(
        &mut self,
        max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        self.inner
            .next_tag_compact_summary_batch(max_tags, visit_closers)
    }

    pub fn next_matching_tag_compact_summary_batch(
        &mut self,
        tag_name: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        self.inner
            .next_matching_tag_compact_summary_batch(tag_name, max_tags, visit_closers)
    }

    pub fn next_matching_tag_summary_batch(
        &mut self,
        tag_name: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Vec<Vec<(String, Zval)>> {
        self.inner
            .next_matching_tag_summary_batch(tag_name, max_tags, visit_closers)
    }

    pub fn next_matching_tag_attribute_compact_summary_batch(
        &mut self,
        tag_name: String,
        attribute_name: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        self.inner
            .next_matching_tag_attribute_compact_summary_batch(
                tag_name,
                attribute_name,
                max_tags,
                visit_closers,
            )
    }

    pub fn next_matching_tag_attribute_summary_batch(
        &mut self,
        tag_name: String,
        attribute_name: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Vec<Vec<(String, Zval)>> {
        self.inner.next_matching_tag_attribute_summary_batch(
            tag_name,
            attribute_name,
            max_tags,
            visit_closers,
        )
    }

    pub fn next_matching_tag_attributes_compact_summary_batch(
        &mut self,
        tag_name: String,
        attribute_names: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        self.inner
            .next_matching_tag_attributes_compact_summary_batch(
                tag_name,
                attribute_names,
                max_tags,
                visit_closers,
            )
    }

    pub fn next_matching_tag_attributes_summary_batch(
        &mut self,
        tag_name: String,
        attribute_names: Vec<String>,
        max_tags: i64,
        visit_closers: bool,
    ) -> Vec<Vec<(String, Zval)>> {
        self.inner.next_matching_tag_attributes_summary_batch(
            tag_name,
            attribute_names,
            max_tags,
            visit_closers,
        )
    }

    pub fn summarize_matching_tag_attributes(
        &mut self,
        tag_name: String,
        attribute_names: String,
        visit_closers: bool,
    ) -> String {
        self.inner
            .summarize_matching_tag_attributes(tag_name, attribute_names, visit_closers)
    }

    pub fn current_token_metadata(&self) -> Option<String> {
        self.inner.current_tag().map(token_metadata)
    }

    pub fn get_token_name(&self) -> Option<String> {
        self.inner.get_token_name()
    }

    pub fn get_tag(&self) -> Option<String> {
        self.inner.get_tag()
    }

    pub fn get_token_type(&self) -> Option<String> {
        self.inner.get_token_type()
    }

    pub fn is_tag_closer(&self) -> bool {
        self.inner.is_tag_closer()
    }

    pub fn is_virtual(&self) -> bool {
        false
    }

    pub fn expects_closer(&self) -> Option<bool> {
        let token = self.inner.current_tag()?;

        if token.token_type != "#tag" {
            return Some(false);
        }

        Some(
            !is_html_processor_void_element(&token.name)
                && !is_html_raw_text_element(&token.name)
                && !self.has_self_closing_flag(),
        )
    }

    pub fn get_last_error(&self) -> Option<String> {
        None
    }

    pub fn get_unsupported_exception(&self) -> Option<String> {
        None
    }

    pub fn get_namespace(&self) -> String {
        self.inner.get_namespace()
    }

    pub fn change_parsing_namespace(&mut self, new_namespace: String) -> bool {
        self.inner.change_parsing_namespace(new_namespace)
    }

    pub fn get_qualified_tag_name(&self) -> Option<String> {
        self.inner.get_qualified_tag_name()
    }

    pub fn get_qualified_attribute_name(&self, name: String) -> Option<String> {
        self.inner.get_qualified_attribute_name(name)
    }

    pub fn has_self_closing_flag(&self) -> bool {
        self.inner.has_self_closing_flag()
    }

    pub fn paused_at_incomplete_token(&self) -> bool {
        self.inner.paused_at_incomplete_token()
    }

    pub fn get_attribute(&self, name: String) -> Zval {
        self.inner.get_attribute(name)
    }

    pub fn get_attribute_names_with_prefix(&self, prefix: String) -> Option<Vec<String>> {
        self.inner.get_attribute_names_with_prefix(prefix)
    }

    pub fn count_attribute_names_with_prefix(&self, prefix: String) -> Option<i64> {
        self.inner.count_attribute_names_with_prefix(prefix)
    }

    pub fn summarize_attribute_names_with_prefix(
        &mut self,
        prefix: String,
        visit_closers: bool,
    ) -> String {
        self.inner
            .summarize_attribute_names_with_prefix(prefix, visit_closers)
    }

    pub fn next_tag_prefix_summary_batch(
        &mut self,
        prefix: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        self.inner
            .next_tag_prefix_summary_batch(prefix, max_tags, visit_closers)
    }

    pub fn next_tag_prefix_compact_summary_batch(
        &mut self,
        prefix: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        self.inner
            .next_tag_prefix_compact_summary_batch(prefix, max_tags, visit_closers)
    }

    pub fn next_tag_prefix_count_compact_batch(
        &mut self,
        prefix: String,
        max_tags: i64,
        visit_closers: bool,
    ) -> Option<String> {
        self.inner
            .next_tag_prefix_count_compact_batch(prefix, max_tags, visit_closers)
    }

    pub fn summarize_tag_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_tag_inventory(visit_closers)
    }

    pub fn summarize_heading_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_heading_inventory(visit_closers)
    }

    pub fn summarize_id_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_id_inventory(visit_closers)
    }

    pub fn summarize_attribute_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_attribute_inventory(visit_closers)
    }

    pub fn summarize_data_attribute_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_data_attribute_inventory(visit_closers)
    }

    pub fn summarize_aria_attribute_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_aria_attribute_inventory(visit_closers)
    }

    pub fn summarize_class_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_class_inventory(visit_closers)
    }

    pub fn summarize_resource_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_resource_inventory(visit_closers)
    }

    pub fn summarize_image_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_image_inventory(visit_closers)
    }

    pub fn summarize_script_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_script_inventory(visit_closers)
    }

    pub fn summarize_form_inventory(&mut self, visit_closers: bool) -> String {
        self.inner.summarize_form_inventory(visit_closers)
    }

    pub fn remove_attributes_with_prefix(&mut self, prefix: String) -> Option<i64> {
        self.inner.remove_attributes_with_prefix(prefix)
    }

    pub fn remove_attributes_with_prefix_from_document(
        &mut self,
        prefix: String,
        visit_closers: bool,
    ) -> String {
        self.inner
            .remove_attributes_with_prefix_from_document(prefix, visit_closers)
    }

    pub fn get_updated_html(&self) -> String {
        self.inner.get_updated_html()
    }

    #[php(name = "__toString")]
    pub fn __to_string(&self) -> String {
        self.get_updated_html()
    }

    pub fn class_list(&self) -> Option<Vec<String>> {
        if self.is_virtual() {
            return None;
        }

        self.inner.class_list()
    }

    pub fn has_class(&self, wanted_class: String) -> Option<bool> {
        if self.is_virtual() {
            return None;
        }

        self.inner.has_class(wanted_class)
    }

    pub fn remove_attribute(&mut self, name: String) -> bool {
        self.inner.remove_attribute(name)
    }

    pub fn set_attribute(&mut self, name: String, value: &Zval) -> bool {
        if self.is_virtual() {
            return false;
        }

        self.inner.set_attribute(name, value)
    }

    pub fn add_class(&mut self, class_name: String) -> bool {
        if self.is_virtual() {
            return false;
        }

        self.inner.add_class(class_name)
    }

    pub fn remove_class(&mut self, class_name: String) -> bool {
        if self.is_virtual() {
            return false;
        }

        self.inner.remove_class(class_name)
    }

    pub fn set_bookmark(&mut self, name: String) -> bool {
        self.inner.set_bookmark(name)
    }

    pub fn release_bookmark(&mut self, name: String) -> bool {
        self.inner.release_bookmark(name)
    }

    pub fn has_bookmark(&self, name: String) -> bool {
        self.inner.has_bookmark(name)
    }

    pub fn seek(&mut self, name: String) -> bool {
        self.inner.seek(name)
    }

    pub fn get_modifiable_text(&self) -> String {
        self.inner
            .current_tag()
            .map(|tag| tag.text.clone())
            .unwrap_or_default()
    }

    pub fn set_modifiable_text(&mut self, plaintext_content: String) -> bool {
        self.inner.set_modifiable_text(plaintext_content)
    }

    pub fn subdivide_text_appropriately(&mut self) -> bool {
        self.inner.subdivide_text_appropriately()
    }

    pub fn get_comment_type(&self) -> Option<String> {
        self.inner.get_comment_type()
    }

    pub fn get_full_comment_text(&self) -> Option<String> {
        self.inner.get_full_comment_text()
    }

    pub fn get_breadcrumbs(&self) -> Vec<String> {
        self.inner
            .current_tag()
            .map(|tag| tag.breadcrumbs.clone())
            .unwrap_or_else(|| vec!["HTML".to_string(), "BODY".to_string()])
    }

    pub fn matches_breadcrumbs(&self, breadcrumbs: Vec<String>) -> bool {
        if breadcrumbs.is_empty() {
            return true;
        }

        let tag = match self.inner.current_tag() {
            Some(tag) if tag.token_type == "#tag" => tag,
            _ => return false,
        };

        if breadcrumbs.len() > tag.breadcrumbs.len() {
            return false;
        }

        let offset = tag.breadcrumbs.len() - breadcrumbs.len();
        for (index, breadcrumb) in breadcrumbs.iter().enumerate() {
            let crumb = breadcrumb.to_ascii_uppercase();
            let node = &tag.breadcrumbs[offset + index];
            if crumb != "*" && node != &crumb {
                return false;
            }
        }

        true
    }

    pub fn get_current_depth(&self) -> i64 {
        self.inner
            .current_tag()
            .map(|tag| tag.depth as i64)
            .unwrap_or(2)
    }
}

#[cfg(feature = "php-extension")]
fn token_metadata(tag: &HtmlTag) -> String {
    let token_name = if tag.token_type == "#tag" {
        tag.name.to_ascii_uppercase()
    } else if tag.token_type == "#doctype" {
        tag.name.clone()
    } else {
        tag.token_type.clone()
    };
    let closer = if tag.closing { "1" } else { "0" };

    let mut metadata =
        String::with_capacity(tag.token_type.len() + token_name.len() + tag.breadcrumbs.len() * 8);
    metadata.push_str(&tag.token_type);
    metadata.push('\x1f');
    metadata.push_str(&token_name);
    metadata.push('\x1f');
    metadata.push_str(closer);
    for breadcrumb in &tag.breadcrumbs {
        metadata.push('\x1f');
        metadata.push_str(breadcrumb);
    }

    metadata
}

#[cfg(feature = "php-extension")]
fn html_tag_public_summary_row(tag: &HtmlTag) -> Vec<(String, Zval)> {
    vec![
        (
            "tag_name".to_string(),
            html_zval_string(&tag.name.to_ascii_uppercase()),
        ),
        ("is_tag_closer".to_string(), html_zval_bool(tag.closing)),
    ]
}

#[cfg(feature = "php-extension")]
fn html_matching_tag_attribute_public_summary_row(
    html: &[u8],
    tag: &HtmlTag,
    attribute_name: &str,
) -> Vec<(String, Zval)> {
    let attribute_value = if tag.closing {
        None
    } else {
        tag.attributes.get(attribute_name).cloned().or_else(|| {
            find_html_attribute_value(html, tag.source_start, tag.source_end, attribute_name)
        })
    };

    vec![
        (
            "tag_name".to_string(),
            html_zval_string(&tag.name.to_ascii_uppercase()),
        ),
        ("is_tag_closer".to_string(), html_zval_bool(tag.closing)),
        (
            "attribute_value".to_string(),
            attribute_value
                .as_deref()
                .map(html_zval_string)
                .unwrap_or_else(html_zval_null),
        ),
    ]
}

#[cfg(feature = "php-extension")]
fn html_matching_tag_attributes_public_summary_row(
    html: &[u8],
    tag: &HtmlTag,
    attribute_names: &[String],
    attribute_initial_bits: u32,
) -> Vec<(String, Zval)> {
    let attribute_values = if tag.closing
        || attribute_names.is_empty()
        || (attribute_initial_bits != 0
            && 0 == (tag.attribute_name_initials & attribute_initial_bits))
    {
        Vec::new()
    } else {
        find_html_attribute_values(html, tag.source_start, tag.source_end, attribute_names)
    };

    let mut values = Vec::with_capacity(attribute_names.len());
    for (index, attribute_name) in attribute_names.iter().enumerate() {
        let value = attribute_values.get(index).and_then(|value| value.as_ref());
        values.push((
            attribute_name.as_str(),
            value
                .map(|value| html_zval_string(value))
                .unwrap_or_else(html_zval_null),
        ));
    }

    vec![
        (
            "tag_name".to_string(),
            html_zval_string(&tag.name.to_ascii_uppercase()),
        ),
        ("is_tag_closer".to_string(), html_zval_bool(tag.closing)),
        (
            "attribute_values".to_string(),
            html_zval_array(values.into_iter().collect()),
        ),
    ]
}

#[cfg(feature = "php-extension")]
fn html_token_public_summary_row(tag: &HtmlTag) -> Vec<(String, Zval)> {
    let token_name = if tag.token_type == "#tag" {
        tag.name.to_ascii_uppercase()
    } else if tag.token_type == "#doctype" {
        tag.name.clone()
    } else {
        tag.token_type.clone()
    };
    let breadcrumbs = tag
        .breadcrumbs
        .iter()
        .map(|breadcrumb| html_zval_string(breadcrumb))
        .collect();

    vec![
        ("token_type".to_string(), html_zval_string(&tag.token_type)),
        ("token_name".to_string(), html_zval_string(&token_name)),
        ("is_tag_closer".to_string(), html_zval_bool(tag.closing)),
        ("current_depth".to_string(), html_zval_i64(tag.depth as i64)),
        ("breadcrumbs".to_string(), html_zval_array(breadcrumbs)),
    ]
}

#[cfg(feature = "php-extension")]
fn html_zval_string(value: &str) -> Zval {
    let mut zval = Zval::new();
    let _ = zval.set_string(value, false);
    zval
}

#[cfg(feature = "php-extension")]
fn html_zval_bool(value: bool) -> Zval {
    let mut zval = Zval::new();
    zval.set_bool(value);
    zval
}

#[cfg(feature = "php-extension")]
fn html_zval_i64(value: i64) -> Zval {
    let mut zval = Zval::new();
    zval.set_long(value);
    zval
}

#[cfg(feature = "php-extension")]
fn html_zval_null() -> Zval {
    Zval::null()
}

#[cfg(feature = "php-extension")]
fn html_zval_array(value: ZBox<ZendHashTable>) -> Zval {
    let mut zval = Zval::new();
    zval.set_hashtable(value);
    zval
}

fn html_escape_text(value: &str) -> String {
    let mut escaped = String::with_capacity(value.len());
    for character in value.chars() {
        match character {
            '&' => escaped.push_str("&amp;"),
            '<' => escaped.push_str("&lt;"),
            '>' => escaped.push_str("&gt;"),
            '"' => escaped.push_str("&quot;"),
            '\'' => escaped.push_str("&apos;"),
            _ => escaped.push(character),
        }
    }

    escaped
}

fn is_html_ascii_whitespace(byte: u8) -> bool {
    matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0x0c)
}

fn normalize_html_newlines_in_place(text: &mut String) {
    if !text.contains('\r') {
        return;
    }

    *text = text.replace("\r\n", "\n").replace('\r', "\n");
}

#[cfg(feature = "php-extension")]
fn html_doctype_info_zval(html: &str, tag: &HtmlTag) -> Option<Zval> {
    if tag.token_type != "#doctype" {
        return None;
    }

    let doctype_html = html.get(tag.source_start..tag.source_end)?;
    let method = Function::try_from_method("WP_HTML_Doctype_Info", "from_doctype_token")?;
    let value = method.try_call(vec![&doctype_html]).ok()?;

    if value.is_null() {
        None
    } else {
        Some(value)
    }
}

#[cfg(feature = "php-extension")]
fn html_normalize_via_php(html: &str) -> Option<String> {
    let callable = ZendCallable::try_from_name("WP_HTML_Processor::normalize").ok()?;
    let value = callable.try_call(vec![&html]).ok()?;
    value.string()
}

fn html_token_compact_summary(tag: &HtmlTag) -> String {
    let token_kind = match tag.token_type.as_str() {
        "#tag" => "t",
        "#comment" => "c",
        "#doctype" => "d",
        "#text" => "s",
        _ => "o",
    };
    let token_name = if tag.token_type == "#tag" {
        Cow::Owned(tag.name.to_ascii_uppercase())
    } else if tag.token_type == "#doctype" {
        Cow::Borrowed(tag.name.as_str())
    } else {
        Cow::Borrowed(tag.token_type.as_str())
    };

    let mut summary = String::with_capacity(
        token_name.len() + tag.breadcrumbs.iter().map(String::len).sum::<usize>() + 16,
    );
    summary.push_str(token_kind);
    summary.push('\x1f');
    summary.push_str(&token_name);
    summary.push('\x1f');
    summary.push(if tag.closing { '1' } else { '0' });
    summary.push('\x1f');
    summary.push_str(&tag.depth.to_string());
    summary.push('\x1f');
    summary.push_str(&tag.breadcrumbs.join("\x1d"));

    summary
}

#[cfg_attr(feature = "php-extension", allow(dead_code))]
pub fn parse_html_tags(html: &str) -> Vec<HtmlTag> {
    let bytes = html.as_bytes();
    let mut tags = Vec::new();
    let mut offset = 0;
    let mut breadcrumbs = initial_html_breadcrumbs();

    while let Some(tag) = parse_next_html_token(
        bytes,
        &mut offset,
        &mut breadcrumbs,
        true,
        true,
        false,
        false,
    ) {
        tags.push(tag);
    }

    tags
}

fn initial_html_breadcrumbs() -> Vec<String> {
    vec!["HTML".to_string(), "BODY".to_string()]
}

fn parse_next_html_token(
    bytes: &[u8],
    offset: &mut usize,
    breadcrumbs: &mut Vec<String>,
    include_text: bool,
    include_attribute_values: bool,
    synthesize_implied_closers: bool,
    ignore_html_body_starts: bool,
) -> Option<HtmlTag> {
    if *offset >= bytes.len() {
        if synthesize_implied_closers {
            return synthesize_html_implied_closer_token(*offset, breadcrumbs);
        }

        return None;
    }

    while *offset < bytes.len() {
        let open = match find_byte(bytes, b'<', *offset) {
            Some(open) => open,
            None => {
                let text_start = *offset;
                let text_end = bytes.len();
                if synthesize_implied_closers {
                    if is_html_table_form_context(breadcrumbs) {
                        return synthesize_html_implied_closer_token(text_start, breadcrumbs);
                    }

                    let leading_null_end = html_leading_null_end(bytes, text_start, text_end);
                    if leading_null_end > text_start {
                        *offset = leading_null_end;
                        continue;
                    }

                    let leading_whitespace_end =
                        html_leading_whitespace_end(bytes, text_start, text_end);
                    if leading_whitespace_end > text_start && leading_whitespace_end < text_end {
                        let token = html_processor_text_token(
                            bytes,
                            text_start,
                            leading_whitespace_end,
                            breadcrumbs,
                        );
                        *offset = leading_whitespace_end;
                        if include_text {
                            return token;
                        }

                        continue;
                    }

                    if is_html_table_text_abort_context(breadcrumbs)
                        && bytes[text_start..text_end]
                            .iter()
                            .any(|byte| !is_html_ascii_whitespace(*byte))
                    {
                        breadcrumbs.truncate(2);
                        *offset = bytes.len();
                        return None;
                    }
                }

                let token = if synthesize_implied_closers {
                    html_processor_text_token(bytes, text_start, text_end, breadcrumbs)
                } else {
                    html_text_token(bytes, text_start, text_end, breadcrumbs)
                };
                *offset = text_end;
                if include_text {
                    return token;
                }

                if synthesize_implied_closers {
                    return synthesize_html_implied_closer_token(*offset, breadcrumbs);
                }

                return None;
            }
        };

        if open > *offset {
            if synthesize_implied_closers && is_html_table_form_context(breadcrumbs) {
                return synthesize_html_implied_closer_token(*offset, breadcrumbs);
            }

            if starts_invalid_html_opening_text(bytes, open) {
                let text_end = find_html_invalid_opening_text_end(bytes, open);
                let token = if synthesize_implied_closers {
                    html_processor_text_token(bytes, *offset, text_end, breadcrumbs)
                } else {
                    html_text_token(bytes, *offset, text_end, breadcrumbs)
                };
                *offset = text_end;
                if include_text && token.is_some() {
                    return token;
                }
                continue;
            }

            if synthesize_implied_closers {
                let leading_null_end = html_leading_null_end(bytes, *offset, open);
                if leading_null_end > *offset {
                    *offset = leading_null_end;
                    continue;
                }

                let leading_whitespace_end = html_leading_whitespace_end(bytes, *offset, open);
                if leading_whitespace_end > *offset && leading_whitespace_end < open {
                    let token = html_processor_text_token(
                        bytes,
                        *offset,
                        leading_whitespace_end,
                        breadcrumbs,
                    );
                    *offset = leading_whitespace_end;
                    if include_text && token.is_some() {
                        return token;
                    }

                    continue;
                }

                if is_html_table_text_abort_context(breadcrumbs)
                    && bytes[*offset..open]
                        .iter()
                        .any(|byte| !is_html_ascii_whitespace(*byte))
                {
                    breadcrumbs.truncate(2);
                    *offset = bytes.len();
                    return None;
                }
            }

            let token = if synthesize_implied_closers {
                html_processor_text_token(bytes, *offset, open, breadcrumbs)
            } else {
                html_text_token(bytes, *offset, open, breadcrumbs)
            };
            *offset = open;
            if include_text && token.is_some() {
                return token;
            }
        }

        let mut cursor = open + 1;
        if cursor >= bytes.len() {
            *offset = bytes.len();
            return None;
        }

        if bytes[cursor] == b'!'
            && cursor + 2 < bytes.len()
            && bytes[cursor + 1] == b'-'
            && bytes[cursor + 2] == b'-'
        {
            if synthesize_implied_closers && is_html_table_form_context(breadcrumbs) {
                return synthesize_html_implied_closer_token(open, breadcrumbs);
            }

            let comment_start = cursor + 3;
            if comment_start < bytes.len()
                && (bytes[comment_start] == b'>'
                    || (bytes[comment_start] == b'-'
                        && comment_start + 1 < bytes.len()
                        && bytes[comment_start + 1] == b'>'))
            {
                let mut token_breadcrumbs = breadcrumbs.clone();
                token_breadcrumbs.push("#comment".to_string());
                *offset = if bytes[comment_start] == b'>' {
                    comment_start + 1
                } else {
                    comment_start + 2
                };

                return Some(HtmlTag {
                    name: "#comment".to_string(),
                    token_type: "#comment".to_string(),
                    closing: false,
                    attributes: HashMap::new(),
                    attribute_order: Vec::new(),
                    attribute_name_initials: 0,
                    source_start: open,
                    source_end: *offset,
                    text: String::new(),
                    comment_type: Some("COMMENT_AS_ABRUPTLY_CLOSED_COMMENT".to_string()),
                    full_comment_text: Some(String::new()),
                    depth: token_breadcrumbs.len(),
                    breadcrumbs: token_breadcrumbs,
                });
            }

            let Some((comment_end, comment_close_len)) =
                find_html_comment_end(bytes, comment_start)
            else {
                *offset = bytes.len();
                return None;
            };
            let mut token_breadcrumbs = breadcrumbs.clone();
            token_breadcrumbs.push("#comment".to_string());
            *offset = comment_end + comment_close_len;

            return Some(HtmlTag {
                name: "#comment".to_string(),
                token_type: "#comment".to_string(),
                closing: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                attribute_name_initials: 0,
                source_start: open,
                source_end: *offset,
                text: String::from_utf8_lossy(&bytes[comment_start..comment_end]).into_owned(),
                comment_type: Some("COMMENT_AS_HTML_COMMENT".to_string()),
                full_comment_text: Some(
                    String::from_utf8_lossy(&bytes[comment_start..comment_end]).into_owned(),
                ),
                depth: token_breadcrumbs.len(),
                breadcrumbs: token_breadcrumbs,
            });
        }

        if bytes[cursor] == b'!' && is_ascii_case_insensitive_prefix(bytes, cursor + 1, b"DOCTYPE")
        {
            let doctype_text_start = cursor + 8;
            let doctype_end = find_byte(bytes, b'>', doctype_text_start).unwrap_or(bytes.len());
            let doctype_text =
                String::from_utf8_lossy(&bytes[doctype_text_start..doctype_end]).into_owned();
            *offset = if doctype_end < bytes.len() {
                doctype_end + 1
            } else {
                bytes.len()
            };

            return Some(HtmlTag {
                name: "html".to_string(),
                token_type: "#doctype".to_string(),
                closing: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                attribute_name_initials: 0,
                source_start: open,
                source_end: *offset,
                text: doctype_text,
                comment_type: None,
                full_comment_text: None,
                depth: 1,
                breadcrumbs: vec!["html".to_string()],
            });
        }

        if bytes[cursor] == b'!' && is_ascii_case_insensitive_prefix(bytes, cursor + 1, b"[CDATA[")
        {
            let cdata_text_start = cursor + 8;
            if let Some(cdata_end) = find_html_cdata_end(bytes, cdata_text_start) {
                let mut token_breadcrumbs = breadcrumbs.clone();
                token_breadcrumbs.push("#comment".to_string());
                *offset = cdata_end + 3;

                return Some(HtmlTag {
                    name: "#comment".to_string(),
                    token_type: "#comment".to_string(),
                    closing: false,
                    attributes: HashMap::new(),
                    attribute_order: Vec::new(),
                    attribute_name_initials: 0,
                    source_start: open,
                    source_end: *offset,
                    text: String::from_utf8_lossy(&bytes[cdata_text_start..cdata_end]).into_owned(),
                    comment_type: Some("COMMENT_AS_CDATA_LOOKALIKE".to_string()),
                    full_comment_text: Some(
                        String::from_utf8_lossy(&bytes[cursor + 1..cdata_end + 2]).into_owned(),
                    ),
                    depth: token_breadcrumbs.len(),
                    breadcrumbs: token_breadcrumbs,
                });
            }
        }

        if bytes[cursor] == b'?' {
            let comment_end = find_byte(bytes, b'>', cursor).unwrap_or(bytes.len());
            let full_comment_text =
                String::from_utf8_lossy(&bytes[cursor..comment_end]).into_owned();
            let mut token_breadcrumbs = breadcrumbs.clone();
            token_breadcrumbs.push("#comment".to_string());
            *offset = if comment_end < bytes.len() {
                comment_end + 1
            } else {
                bytes.len()
            };

            if comment_end > cursor && bytes[comment_end - 1] == b'?' {
                let target_start = cursor + 1;
                let target_end = span_html_pi_target(bytes, target_start);
                if target_end > target_start {
                    return Some(HtmlTag {
                        name: String::from_utf8_lossy(&bytes[target_start..target_end])
                            .into_owned(),
                        token_type: "#comment".to_string(),
                        closing: false,
                        attributes: HashMap::new(),
                        attribute_order: Vec::new(),
                        attribute_name_initials: 0,
                        source_start: open,
                        source_end: *offset,
                        text: String::from_utf8_lossy(&bytes[target_end..comment_end - 1])
                            .into_owned(),
                        comment_type: Some("COMMENT_AS_PI_NODE_LOOKALIKE".to_string()),
                        full_comment_text: Some(full_comment_text),
                        depth: token_breadcrumbs.len(),
                        breadcrumbs: token_breadcrumbs,
                    });
                }
            }

            return Some(HtmlTag {
                name: "#comment".to_string(),
                token_type: "#comment".to_string(),
                closing: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                attribute_name_initials: 0,
                source_start: open,
                source_end: *offset,
                text: String::from_utf8_lossy(&bytes[cursor + 1..comment_end]).into_owned(),
                comment_type: Some("COMMENT_AS_INVALID_HTML".to_string()),
                full_comment_text: Some(full_comment_text),
                depth: token_breadcrumbs.len(),
                breadcrumbs: token_breadcrumbs,
            });
        }

        if bytes[cursor] == b'!' {
            let comment_start = cursor + 1;
            let comment_end = find_byte(bytes, b'>', comment_start).unwrap_or(bytes.len());
            let text = String::from_utf8_lossy(&bytes[comment_start..comment_end]).into_owned();
            let mut token_breadcrumbs = breadcrumbs.clone();
            token_breadcrumbs.push("#comment".to_string());
            *offset = if comment_end < bytes.len() {
                comment_end + 1
            } else {
                bytes.len()
            };

            return Some(HtmlTag {
                name: "#comment".to_string(),
                token_type: "#comment".to_string(),
                closing: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                attribute_name_initials: 0,
                source_start: open,
                source_end: *offset,
                text: text.clone(),
                comment_type: Some("COMMENT_AS_INVALID_HTML".to_string()),
                full_comment_text: Some(text),
                depth: token_breadcrumbs.len(),
                breadcrumbs: token_breadcrumbs,
            });
        }

        let closing = bytes[cursor] == b'/';
        if closing {
            cursor += 1;
        }

        if closing && cursor < bytes.len() && bytes[cursor] == b'>' {
            let mut token_breadcrumbs = breadcrumbs.clone();
            token_breadcrumbs.push("#presumptuous-tag".to_string());
            *offset = cursor + 1;

            return Some(HtmlTag {
                name: "#presumptuous-tag".to_string(),
                token_type: "#presumptuous-tag".to_string(),
                closing: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                attribute_name_initials: 0,
                source_start: open,
                source_end: *offset,
                text: String::new(),
                comment_type: None,
                full_comment_text: None,
                depth: token_breadcrumbs.len(),
                breadcrumbs: token_breadcrumbs,
            });
        }

        if closing && (cursor >= bytes.len() || !bytes[cursor].is_ascii_alphabetic()) {
            let comment_end = find_byte(bytes, b'>', cursor).unwrap_or(bytes.len());
            let text = String::from_utf8_lossy(&bytes[cursor..comment_end]).into_owned();
            let mut token_breadcrumbs = breadcrumbs.clone();
            token_breadcrumbs.push("#funky-comment".to_string());
            *offset = if comment_end < bytes.len() {
                comment_end + 1
            } else {
                bytes.len()
            };

            return Some(HtmlTag {
                name: "#funky-comment".to_string(),
                token_type: "#funky-comment".to_string(),
                closing: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                attribute_name_initials: 0,
                source_start: open,
                source_end: *offset,
                text: text.clone(),
                comment_type: None,
                full_comment_text: Some(text),
                depth: token_breadcrumbs.len(),
                breadcrumbs: token_breadcrumbs,
            });
        }

        if !closing && (cursor >= bytes.len() || !bytes[cursor].is_ascii_alphabetic()) {
            let text_end = find_html_invalid_opening_text_end(bytes, open);
            let token = html_text_token(bytes, open, text_end, breadcrumbs);
            *offset = text_end;

            if include_text && token.is_some() {
                return token;
            }
            continue;
        }

        let name_start = cursor;
        cursor = span_name(bytes, cursor);
        if cursor == name_start {
            *offset = open + 1;
            continue;
        }

        let name = ascii_lower(&bytes[name_start..cursor]);
        let mut attributes = HashMap::new();
        let mut attribute_order = Vec::new();
        let mut attribute_name_initials = 0u32;

        while cursor < bytes.len() {
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() || bytes[cursor] == b'>' {
                break;
            }
            if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
                break;
            }

            let attr_start = cursor;
            cursor = span_html_attribute_name(bytes, cursor);
            if cursor == attr_start {
                cursor += 1;
                continue;
            }

            attribute_name_initials |= html_attribute_initial_bit(bytes[attr_start]);
            let attr_name = if include_attribute_values {
                Some(ascii_lower(&bytes[attr_start..cursor]))
            } else {
                None
            };
            cursor = skip_ascii_whitespace(bytes, cursor);

            if cursor < bytes.len() && bytes[cursor] == b'=' {
                cursor += 1;
                cursor = skip_ascii_whitespace(bytes, cursor);
                if include_attribute_values {
                    let (parsed, next) = parse_attribute_value(bytes, cursor);
                    cursor = next;

                    if let Some(name) = attr_name.as_ref() {
                        if !attributes.contains_key(name) {
                            attributes.insert(name.clone(), parsed);
                        }
                    }
                } else {
                    cursor = skip_html_attribute_value(bytes, cursor);
                }
            } else if let Some(name) = attr_name.as_ref() {
                if !attributes.contains_key(name) {
                    attributes.insert(name.clone(), String::new());
                }
            }

            if let Some(name) = attr_name {
                if !attribute_order
                    .iter()
                    .any(|existing_name| existing_name == &name)
                {
                    attribute_order.push(name);
                }
            }
        }

        let tag_end = find_byte(bytes, b'>', cursor).unwrap_or(bytes.len());
        if tag_end >= bytes.len() {
            *offset = bytes.len();
            return None;
        }

        let self_closing = cursor < bytes.len() && bytes[cursor] == b'/';
        let uppercase_name = name.to_ascii_uppercase();

        if synthesize_implied_closers
            && !closing
            && !self_closing
            && name != "form"
            && is_html_table_form_context(breadcrumbs)
        {
            return synthesize_html_implied_closer_token(open, breadcrumbs);
        }

        if synthesize_implied_closers
            && !closing
            && !self_closing
            && ignore_html_body_starts
            && matches!(name.as_str(), "html" | "body")
        {
            *offset = tag_end.saturating_add(1).min(bytes.len());
            continue;
        }

        if synthesize_implied_closers
            && !closing
            && !self_closing
            && name == "form"
            && breadcrumbs.iter().any(|open| open == "FORM")
        {
            *offset = tag_end.saturating_add(1).min(bytes.len());
            continue;
        }

        if synthesize_implied_closers
            && !closing
            && !self_closing
            && should_synthesize_html_table_form_closer(&name, breadcrumbs)
        {
            return synthesize_html_implied_closer_token(open, breadcrumbs);
        }

        if synthesize_implied_closers
            && !closing
            && !self_closing
            && should_synthesize_html_implied_colgroup(&name, breadcrumbs)
        {
            return Some(synthesize_html_implied_opener_token(
                "colgroup",
                open,
                breadcrumbs,
            ));
        }

        if synthesize_implied_closers
            && !closing
            && !self_closing
            && should_synthesize_html_implied_table_body(&name, breadcrumbs)
        {
            return Some(synthesize_html_implied_opener_token(
                "tbody",
                open,
                breadcrumbs,
            ));
        }

        if synthesize_implied_closers
            && !closing
            && !self_closing
            && should_synthesize_html_implied_table_row(&name, breadcrumbs)
        {
            return Some(synthesize_html_implied_opener_token(
                "tr",
                open,
                breadcrumbs,
            ));
        }

        if synthesize_implied_closers
            && closing
            && should_synthesize_html_implied_closer_before_closing(&uppercase_name, breadcrumbs)
        {
            return synthesize_html_implied_closer_token(open, breadcrumbs);
        }

        if synthesize_implied_closers
            && closing
            && uppercase_name == "SELECT"
            && !breadcrumbs.iter().any(|open| open == "SELECT")
        {
            *offset = tag_end.saturating_add(1).min(bytes.len());
            continue;
        }

        if synthesize_implied_closers
            && closing
            && should_abort_html_table_child_closer(&name, breadcrumbs)
        {
            breadcrumbs.truncate(2);
            *offset = bytes.len();
            return None;
        }

        if synthesize_implied_closers
            && closing
            && !html_breadcrumb_contains_open_element(breadcrumbs, &uppercase_name)
        {
            *offset = tag_end.saturating_add(1).min(bytes.len());
            continue;
        }

        if synthesize_implied_closers
            && !closing
            && !self_closing
            && should_synthesize_html_implied_closer(&name, breadcrumbs)
        {
            return synthesize_html_implied_closer_token(open, breadcrumbs);
        }

        if synthesize_implied_closers
            && !closing
            && should_abort_html_table_child_start(&name, breadcrumbs)
        {
            breadcrumbs.truncate(2);
            *offset = bytes.len();
            return None;
        }

        let stop_after_table_form_closer = synthesize_implied_closers
            && closing
            && uppercase_name == "FORM"
            && breadcrumbs.len() >= 4
            && breadcrumbs.last().map(|last| last.as_str()) == Some("FORM")
            && breadcrumbs
                .get(breadcrumbs.len().saturating_sub(2))
                .map(|ancestor| ancestor.as_str())
                == Some("TABLE");

        let token_breadcrumbs = if closing {
            pop_html_breadcrumb(breadcrumbs, &uppercase_name);
            breadcrumbs.clone()
        } else {
            let mut next_breadcrumbs = breadcrumbs.clone();
            next_breadcrumbs.push(uppercase_name.clone());
            if !is_html_void_element(&name) {
                breadcrumbs.push(uppercase_name.clone());
            }
            next_breadcrumbs
        };
        let depth = token_breadcrumbs.len();
        let mut text = String::new();

        *offset = if !closing
            && !self_closing
            && is_html_raw_text_element(&name)
            && tag_end < bytes.len()
        {
            let content_start = tag_end + 1;
            match find_html_closing_tag(bytes, &name, content_start) {
                Some(closing_start) => {
                    text = parse_html_raw_text_content(&name, &bytes[content_start..closing_start]);
                    pop_html_breadcrumb(breadcrumbs, &uppercase_name);
                    find_byte(bytes, b'>', closing_start).map_or(bytes.len(), |close| close + 1)
                }
                None => {
                    text = parse_html_raw_text_content(&name, &bytes[content_start..]);
                    pop_html_breadcrumb(breadcrumbs, &uppercase_name);
                    bytes.len()
                }
            }
        } else {
            tag_end.saturating_add(1).min(bytes.len())
        };

        if stop_after_table_form_closer {
            breadcrumbs.truncate(2);
            *offset = bytes.len();
        }

        return Some(HtmlTag {
            name,
            token_type: "#tag".to_string(),
            closing,
            attributes,
            attribute_order,
            attribute_name_initials,
            source_start: open,
            source_end: tag_end.saturating_add(1).min(bytes.len()),
            text,
            comment_type: None,
            full_comment_text: None,
            breadcrumbs: token_breadcrumbs,
            depth,
        });
    }

    None
}

fn parse_next_plain_html_tag_token(bytes: &[u8], offset: &mut usize) -> Option<HtmlTag> {
    while *offset < bytes.len() {
        let open = match find_byte(bytes, b'<', *offset) {
            Some(open) => open,
            None => {
                *offset = bytes.len();
                return None;
            }
        };

        if open + 1 >= bytes.len() {
            *offset = bytes.len();
            return None;
        }

        if open > *offset && starts_invalid_html_opening_text(bytes, open) {
            *offset = find_html_invalid_opening_text_end(bytes, open);
            continue;
        }

        let mut cursor = open + 1;

        if bytes[cursor] == b'!'
            && cursor + 2 < bytes.len()
            && bytes[cursor + 1] == b'-'
            && bytes[cursor + 2] == b'-'
        {
            let comment_start = cursor + 3;
            if comment_start < bytes.len()
                && (bytes[comment_start] == b'>'
                    || (bytes[comment_start] == b'-'
                        && comment_start + 1 < bytes.len()
                        && bytes[comment_start + 1] == b'>'))
            {
                *offset = if bytes[comment_start] == b'>' {
                    comment_start + 1
                } else {
                    comment_start + 2
                };
                continue;
            }

            let Some((comment_end, comment_close_len)) =
                find_html_comment_end(bytes, comment_start)
            else {
                *offset = bytes.len();
                return None;
            };

            *offset = comment_end + comment_close_len;
            continue;
        }

        if bytes[cursor] == b'!' && is_ascii_case_insensitive_prefix(bytes, cursor + 1, b"DOCTYPE")
        {
            let doctype_text_start = cursor + 8;
            let doctype_end = find_byte(bytes, b'>', doctype_text_start).unwrap_or(bytes.len());
            *offset = if doctype_end < bytes.len() {
                doctype_end + 1
            } else {
                bytes.len()
            };
            continue;
        }

        if bytes[cursor] == b'!' && is_ascii_case_insensitive_prefix(bytes, cursor + 1, b"[CDATA[")
        {
            let cdata_text_start = cursor + 8;
            *offset = find_html_cdata_end(bytes, cdata_text_start)
                .and_then(|cdata_end| cdata_end.checked_add(3))
                .or_else(|| find_byte(bytes, b'>', cdata_text_start).map(|end| end + 1))
                .unwrap_or(bytes.len());
            continue;
        }

        if bytes[cursor] == b'?' {
            *offset = find_byte(bytes, b'>', cursor).map_or(bytes.len(), |end| end + 1);
            continue;
        }

        if bytes[cursor] == b'!' {
            *offset = find_byte(bytes, b'>', cursor + 1).map_or(bytes.len(), |end| end + 1);
            continue;
        }

        let closing = bytes[cursor] == b'/';
        if closing {
            cursor += 1;
        }

        if closing && cursor < bytes.len() && bytes[cursor] == b'>' {
            *offset = cursor + 1;
            continue;
        }

        if closing && (cursor >= bytes.len() || !bytes[cursor].is_ascii_alphabetic()) {
            *offset = find_byte(bytes, b'>', cursor).map_or(bytes.len(), |end| end + 1);
            continue;
        }

        if !closing && (cursor >= bytes.len() || !bytes[cursor].is_ascii_alphabetic()) {
            *offset = find_html_invalid_opening_text_end(bytes, open);
            continue;
        }

        let name_start = cursor;
        cursor = span_name(bytes, cursor);
        if cursor == name_start {
            *offset = open + 1;
            continue;
        }

        let name = ascii_lower(&bytes[name_start..cursor]);
        let mut attribute_name_initials = 0u32;

        while cursor < bytes.len() {
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() || bytes[cursor] == b'>' {
                break;
            }
            if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
                break;
            }

            let attr_start = cursor;
            cursor = span_html_attribute_name(bytes, cursor);
            if cursor == attr_start {
                cursor += 1;
                continue;
            }

            attribute_name_initials |= html_attribute_initial_bit(bytes[attr_start]);
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor < bytes.len() && bytes[cursor] == b'=' {
                cursor += 1;
                cursor = skip_ascii_whitespace(bytes, cursor);
                cursor = skip_html_attribute_value(bytes, cursor);
            }
        }

        let tag_end = find_byte(bytes, b'>', cursor).unwrap_or(bytes.len());
        if tag_end >= bytes.len() {
            *offset = bytes.len();
            return None;
        }

        let self_closing = cursor < bytes.len() && bytes[cursor] == b'/';
        let mut text = String::new();

        *offset = if !closing && !self_closing && is_html_raw_text_element(&name) {
            let content_start = tag_end + 1;
            match find_html_closing_tag(bytes, &name, content_start) {
                Some(closing_start) => {
                    text = parse_html_raw_text_content(&name, &bytes[content_start..closing_start]);
                    find_byte(bytes, b'>', closing_start).map_or(bytes.len(), |close| close + 1)
                }
                None => {
                    text = parse_html_raw_text_content(&name, &bytes[content_start..]);
                    bytes.len()
                }
            }
        } else {
            tag_end + 1
        };

        return Some(HtmlTag {
            name,
            token_type: "#tag".to_string(),
            closing,
            attributes: HashMap::new(),
            attribute_order: Vec::new(),
            attribute_name_initials,
            source_start: open,
            source_end: tag_end + 1,
            text,
            comment_type: None,
            full_comment_text: None,
            breadcrumbs: Vec::new(),
            depth: 0,
        });
    }

    None
}

fn html_text_token(
    bytes: &[u8],
    start: usize,
    end: usize,
    breadcrumbs: &[String],
) -> Option<HtmlTag> {
    if end <= start {
        return None;
    }

    let text = decode_html_text_value(&String::from_utf8_lossy(&bytes[start..end]));
    if text.is_empty() {
        return None;
    }

    let mut token_breadcrumbs = breadcrumbs.to_vec();
    token_breadcrumbs.push("#text".to_string());
    Some(HtmlTag {
        name: "#text".to_string(),
        token_type: "#text".to_string(),
        closing: false,
        attributes: HashMap::new(),
        attribute_order: Vec::new(),
        attribute_name_initials: 0,
        source_start: start,
        source_end: end,
        text,
        comment_type: None,
        full_comment_text: None,
        depth: token_breadcrumbs.len(),
        breadcrumbs: token_breadcrumbs,
    })
}

fn html_processor_text_token(
    bytes: &[u8],
    start: usize,
    end: usize,
    breadcrumbs: &[String],
) -> Option<HtmlTag> {
    let mut token = html_text_token(bytes, start, end, breadcrumbs)?;
    token.text.retain(|character| character != '\0');
    normalize_html_newlines_in_place(&mut token.text);
    if token.text.is_empty() {
        return None;
    }

    Some(token)
}

fn html_leading_null_end(bytes: &[u8], start: usize, end: usize) -> usize {
    let mut cursor = start;
    while cursor < end && bytes[cursor] == b'\0' {
        cursor += 1;
    }

    cursor
}

fn html_leading_whitespace_end(bytes: &[u8], start: usize, end: usize) -> usize {
    let mut cursor = start;
    while cursor < end {
        if is_html_ascii_whitespace(bytes[cursor]) {
            cursor += 1;
            continue;
        }

        if bytes[cursor] == b'&' {
            if let Some((replacement, next)) =
                decode_html_character_reference(bytes, cursor + 1, HtmlDecodeContext::Text)
            {
                let replacement = replacement.as_ref().as_bytes();
                if replacement.len() == 1 && is_html_ascii_whitespace(replacement[0]) {
                    cursor = next.min(end);
                    continue;
                }
            }
        }

        break;
    }

    cursor
}

fn is_html_table_text_abort_context(breadcrumbs: &[String]) -> bool {
    matches!(
        breadcrumbs.last().map(|name| name.as_str()),
        Some("TABLE" | "TBODY" | "THEAD" | "TFOOT" | "TR" | "COLGROUP")
    )
}

fn parse_attribute_value(bytes: &[u8], cursor: usize) -> (String, usize) {
    if cursor >= bytes.len() {
        return (String::new(), cursor);
    }

    if bytes[cursor] == b'"' || bytes[cursor] == b'\'' {
        let quote = bytes[cursor];
        let start = cursor + 1;
        let end = find_byte(bytes, quote, start).unwrap_or(bytes.len());
        return (
            decode_html_attribute_value(&String::from_utf8_lossy(&bytes[start..end])),
            end + 1,
        );
    }

    let end = span_unquoted_value(bytes, cursor);
    (
        decode_html_attribute_value(&String::from_utf8_lossy(&bytes[cursor..end])),
        end,
    )
}

fn skip_html_attribute_value(bytes: &[u8], cursor: usize) -> usize {
    if cursor >= bytes.len() {
        return cursor;
    }

    if bytes[cursor] == b'"' || bytes[cursor] == b'\'' {
        let quote = bytes[cursor];
        let end = find_byte(bytes, quote, cursor + 1).unwrap_or(bytes.len());
        return end.saturating_add(1).min(bytes.len());
    }

    span_unquoted_value(bytes, cursor)
}

fn html_tag_has_self_closing_flag(bytes: &[u8], source_start: usize, source_end: usize) -> bool {
    if source_start >= bytes.len() || source_end <= source_start {
        return false;
    }

    let mut cursor = source_end.min(bytes.len());
    while cursor > source_start && bytes[cursor - 1].is_ascii_whitespace() {
        cursor -= 1;
    }
    if cursor <= source_start || bytes[cursor - 1] != b'>' {
        return false;
    }

    cursor -= 1;
    while cursor > source_start && bytes[cursor - 1].is_ascii_whitespace() {
        cursor -= 1;
    }

    cursor > source_start && bytes[cursor - 1] == b'/'
}

fn find_html_attribute_value(
    bytes: &[u8],
    source_start: usize,
    source_end: usize,
    wanted_name: &str,
) -> Option<String> {
    if source_start >= bytes.len() || source_end <= source_start {
        return None;
    }

    let tag_end = source_end.saturating_sub(1).min(bytes.len());
    let mut cursor = source_start.saturating_add(1);
    if cursor < tag_end && bytes[cursor] == b'/' {
        return None;
    }

    cursor = skip_ascii_whitespace(bytes, cursor);
    cursor = span_name(bytes, cursor);

    while cursor < tag_end {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= tag_end || bytes[cursor] == b'>' {
            break;
        }
        if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
            break;
        }

        let attr_start = cursor;
        cursor = span_html_attribute_name(bytes, cursor);
        if cursor == attr_start {
            cursor += 1;
            continue;
        }

        let attr_name = ascii_lower(&bytes[attr_start..cursor]);
        cursor = skip_ascii_whitespace(bytes, cursor);

        if cursor < tag_end && bytes[cursor] == b'=' {
            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            if attr_name == wanted_name {
                return Some(parse_attribute_value(bytes, cursor).0);
            }

            cursor = skip_html_attribute_value(bytes, cursor);
        } else if attr_name == wanted_name {
            return Some(String::new());
        }
    }

    None
}

fn find_html_attribute_has_value(
    bytes: &[u8],
    source_start: usize,
    source_end: usize,
    wanted_name: &str,
) -> Option<bool> {
    if source_start >= bytes.len() || source_end <= source_start {
        return None;
    }

    let tag_end = source_end.saturating_sub(1).min(bytes.len());
    let mut cursor = source_start.saturating_add(1);
    if cursor < tag_end && bytes[cursor] == b'/' {
        return None;
    }

    cursor = skip_ascii_whitespace(bytes, cursor);
    cursor = span_name(bytes, cursor);

    while cursor < tag_end {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= tag_end || bytes[cursor] == b'>' {
            break;
        }
        if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
            break;
        }

        let attr_start = cursor;
        cursor = span_html_attribute_name(bytes, cursor);
        if cursor == attr_start {
            cursor += 1;
            continue;
        }

        let attr_name = ascii_lower(&bytes[attr_start..cursor]);
        cursor = skip_ascii_whitespace(bytes, cursor);

        if attr_name == wanted_name {
            return Some(cursor < tag_end && bytes[cursor] == b'=');
        }

        if cursor < tag_end && bytes[cursor] == b'=' {
            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            cursor = skip_html_attribute_value(bytes, cursor);
        }
    }

    None
}

fn find_html_attribute_span(
    bytes: &[u8],
    source_start: usize,
    source_end: usize,
    wanted_name: &str,
) -> Option<(usize, usize)> {
    if source_start >= bytes.len() || source_end <= source_start {
        return None;
    }

    let tag_end = source_end.saturating_sub(1).min(bytes.len());
    let mut cursor = source_start.saturating_add(1);
    if cursor < tag_end && bytes[cursor] == b'/' {
        return None;
    }

    cursor = skip_ascii_whitespace(bytes, cursor);
    cursor = span_name(bytes, cursor);

    while cursor < tag_end {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= tag_end || bytes[cursor] == b'>' {
            break;
        }
        if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
            break;
        }

        let attr_start = cursor;
        cursor = span_html_attribute_name(bytes, cursor);
        if cursor == attr_start {
            cursor += 1;
            continue;
        }

        let attr_name = ascii_lower(&bytes[attr_start..cursor]);
        let attr_name_end = cursor;
        cursor = skip_ascii_whitespace(bytes, cursor);

        let mut attr_end = attr_name_end;
        if cursor < tag_end && bytes[cursor] == b'=' {
            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            cursor = skip_html_attribute_value(bytes, cursor);
            attr_end = cursor;
        }

        if attr_name == wanted_name {
            return Some((attr_start, attr_end.saturating_sub(attr_start)));
        }
    }

    None
}

fn html_tag_name_end(bytes: &[u8], source_start: usize, source_end: usize) -> usize {
    let tag_end = source_end.saturating_sub(1).min(bytes.len());
    let mut cursor = source_start.saturating_add(1);
    if cursor < tag_end && bytes[cursor] == b'/' {
        cursor += 1;
    }

    span_name(bytes, skip_ascii_whitespace(bytes, cursor))
}

fn is_valid_html_attribute_name(name: &str) -> bool {
    if name.is_empty() {
        return false;
    }

    !name.chars().any(|ch| {
        matches!(
            ch,
            '"' | '\'' | '>' | '&' | '<' | '/' | '=' | ' ' | '\t' | '\n' | '\r' | '\u{0c}'
        ) || ch.is_control()
            || matches!(
                ch as u32,
                0xfdd0
                    ..=0xfdef
                        | 0xfffe
                        | 0xffff
                        | 0x1fffe
                        | 0x1ffff
                        | 0x2fffe
                        | 0x2ffff
                        | 0x3fffe
                        | 0x3ffff
                        | 0x4fffe
                        | 0x4ffff
                        | 0x5fffe
                        | 0x5ffff
                        | 0x6fffe
                        | 0x6ffff
                        | 0x7fffe
                        | 0x7ffff
                        | 0x8fffe
                        | 0x8ffff
                        | 0x9fffe
                        | 0x9ffff
                        | 0xafffe
                        | 0xaffff
                        | 0xbfffe
                        | 0xbffff
                        | 0xcfffe
                        | 0xcffff
                        | 0xdfffe
                        | 0xdffff
                        | 0xefffe
                        | 0xeffff
                        | 0xffffe
                        | 0xfffff
                        | 0x10fffe
                        | 0x10ffff
            )
    })
}

fn html_escape_attribute_value(value: &str) -> String {
    let mut escaped = String::with_capacity(value.len());
    for ch in value.chars() {
        match ch {
            '&' => escaped.push_str("&amp;"),
            '"' => escaped.push_str("&quot;"),
            '<' => escaped.push_str("&lt;"),
            '>' => escaped.push_str("&gt;"),
            _ => escaped.push(ch),
        }
    }
    escaped
}

fn find_html_attribute_values(
    bytes: &[u8],
    source_start: usize,
    source_end: usize,
    wanted_names: &[String],
) -> Vec<Option<String>> {
    let mut values = vec![None; wanted_names.len()];
    if wanted_names.is_empty() || source_start >= bytes.len() || source_end <= source_start {
        return values;
    }

    let tag_end = source_end.saturating_sub(1).min(bytes.len());
    let mut cursor = source_start.saturating_add(1);
    if cursor < tag_end && bytes[cursor] == b'/' {
        return values;
    }

    cursor = skip_ascii_whitespace(bytes, cursor);
    cursor = span_name(bytes, cursor);

    while cursor < tag_end && values.iter().any(|value| value.is_none()) {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= tag_end || bytes[cursor] == b'>' {
            break;
        }
        if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
            break;
        }

        let attr_start = cursor;
        cursor = span_html_attribute_name(bytes, cursor);
        if cursor == attr_start {
            cursor += 1;
            continue;
        }

        let attr_name = &bytes[attr_start..cursor];
        cursor = skip_ascii_whitespace(bytes, cursor);

        let matching_index = wanted_names
            .iter()
            .enumerate()
            .find_map(|(index, wanted_name)| {
                if values[index].is_none() && attr_name.eq_ignore_ascii_case(wanted_name.as_bytes())
                {
                    Some(index)
                } else {
                    None
                }
            });

        if cursor < tag_end && bytes[cursor] == b'=' {
            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            if let Some(index) = matching_index {
                let (parsed, next) = parse_attribute_value(bytes, cursor);
                values[index] = Some(parsed);
                cursor = next;
            } else {
                cursor = skip_html_attribute_value(bytes, cursor);
            }
        } else if let Some(index) = matching_index {
            values[index] = Some(String::new());
        }
    }

    values
}

fn find_html_attribute_names_with_prefix(
    bytes: &[u8],
    source_start: usize,
    source_end: usize,
    wanted_prefix: &str,
) -> Option<Vec<String>> {
    if source_start >= bytes.len() || source_end <= source_start {
        return None;
    }

    let tag_end = source_end.saturating_sub(1).min(bytes.len());
    let mut cursor = source_start.saturating_add(1);
    if cursor < tag_end && bytes[cursor] == b'/' {
        return None;
    }

    cursor = skip_ascii_whitespace(bytes, cursor);
    cursor = span_name(bytes, cursor);

    let mut matches = Vec::new();
    let mut matched_slices: Vec<&[u8]> = Vec::new();
    while cursor < tag_end {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= tag_end || bytes[cursor] == b'>' {
            break;
        }
        if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
            break;
        }

        let attr_start = cursor;
        cursor = span_html_attribute_name(bytes, cursor);
        if cursor == attr_start {
            cursor += 1;
            continue;
        }

        if !ascii_starts_with_ignore_case(&bytes[attr_start..cursor], wanted_prefix.as_bytes()) {
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor < tag_end && bytes[cursor] == b'=' {
                cursor += 1;
                cursor = skip_ascii_whitespace(bytes, cursor);
                cursor = skip_html_attribute_value(bytes, cursor);
            }
            continue;
        }

        let attr_name = &bytes[attr_start..cursor];
        if !matched_slices
            .iter()
            .any(|name| name.eq_ignore_ascii_case(attr_name))
        {
            matched_slices.push(attr_name);
            matches.push(ascii_lower(attr_name));
        }

        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor < tag_end && bytes[cursor] == b'=' {
            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            cursor = skip_html_attribute_value(bytes, cursor);
        }
    }

    Some(matches)
}

fn find_html_attribute_names_with_prefix_string(
    bytes: &[u8],
    source_start: usize,
    source_end: usize,
    wanted_prefix: &str,
) -> Option<String> {
    if source_start >= bytes.len() || source_end <= source_start {
        return None;
    }

    let tag_end = source_end.saturating_sub(1).min(bytes.len());
    let mut cursor = source_start.saturating_add(1);
    if cursor < tag_end && bytes[cursor] == b'/' {
        return None;
    }

    cursor = skip_ascii_whitespace(bytes, cursor);
    cursor = span_name(bytes, cursor);

    let mut matches = String::new();
    let mut matched_slices: Vec<&[u8]> = Vec::new();
    while cursor < tag_end {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= tag_end || bytes[cursor] == b'>' {
            break;
        }
        if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
            break;
        }

        let attr_start = cursor;
        cursor = span_html_attribute_name(bytes, cursor);
        if cursor == attr_start {
            cursor += 1;
            continue;
        }

        if !ascii_starts_with_ignore_case(&bytes[attr_start..cursor], wanted_prefix.as_bytes()) {
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor < tag_end && bytes[cursor] == b'=' {
                cursor += 1;
                cursor = skip_ascii_whitespace(bytes, cursor);
                cursor = skip_html_attribute_value(bytes, cursor);
            }
            continue;
        }

        let attr_name = &bytes[attr_start..cursor];
        if !matched_slices
            .iter()
            .any(|name| name.eq_ignore_ascii_case(attr_name))
        {
            if !matches.is_empty() {
                matches.push('\x1f');
            }

            matched_slices.push(attr_name);
            push_ascii_lower(&mut matches, attr_name);
        }

        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor < tag_end && bytes[cursor] == b'=' {
            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            cursor = skip_html_attribute_value(bytes, cursor);
        }
    }

    Some(matches)
}

fn find_html_attribute_names_with_prefix_count(
    bytes: &[u8],
    source_start: usize,
    source_end: usize,
    wanted_prefix: &str,
) -> Option<i64> {
    if source_start >= bytes.len() || source_end <= source_start {
        return None;
    }

    let tag_end = source_end.saturating_sub(1).min(bytes.len());
    let mut cursor = source_start.saturating_add(1);
    if cursor < tag_end && bytes[cursor] == b'/' {
        return None;
    }

    cursor = skip_ascii_whitespace(bytes, cursor);
    cursor = span_name(bytes, cursor);

    let mut count = 0;
    let mut matches: Vec<&[u8]> = Vec::new();
    while cursor < tag_end {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= tag_end || bytes[cursor] == b'>' {
            break;
        }
        if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
            break;
        }

        let attr_start = cursor;
        cursor = span_html_attribute_name(bytes, cursor);
        if cursor == attr_start {
            cursor += 1;
            continue;
        }

        if ascii_starts_with_ignore_case(&bytes[attr_start..cursor], wanted_prefix.as_bytes()) {
            let attr_name = &bytes[attr_start..cursor];
            if !matches
                .iter()
                .any(|name| name.eq_ignore_ascii_case(attr_name))
            {
                matches.push(attr_name);
                count += 1;
            }
        }

        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor < tag_end && bytes[cursor] == b'=' {
            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            cursor = skip_html_attribute_value(bytes, cursor);
        }
    }

    Some(count)
}

fn find_html_attribute_removals(
    bytes: &[u8],
    source_start: usize,
    source_end: usize,
    wanted_name: &str,
) -> Vec<(usize, usize)> {
    if source_start >= bytes.len() || source_end <= source_start {
        return Vec::new();
    }

    let tag_end = source_end.saturating_sub(1).min(bytes.len());
    let mut cursor = source_start.saturating_add(1);
    if cursor < tag_end && bytes[cursor] == b'/' {
        return Vec::new();
    }

    cursor = skip_ascii_whitespace(bytes, cursor);
    cursor = span_name(bytes, cursor);

    let mut removals = Vec::new();
    while cursor < tag_end {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= tag_end || bytes[cursor] == b'>' {
            break;
        }
        if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
            break;
        }

        let attr_start = cursor;
        cursor = span_html_attribute_name(bytes, cursor);
        if cursor == attr_start {
            cursor += 1;
            continue;
        }

        let attr_name = ascii_lower(&bytes[attr_start..cursor]);
        let attr_name_end = cursor;
        cursor = skip_ascii_whitespace(bytes, cursor);

        let mut attr_end = attr_name_end;
        if cursor < tag_end && bytes[cursor] == b'=' {
            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            cursor = skip_html_attribute_value(bytes, cursor);
            attr_end = cursor;
        }

        if attr_name == wanted_name {
            removals.push((attr_start, attr_end.saturating_sub(attr_start)));
        }
    }

    removals
}

fn find_html_attribute_removals_with_prefix(
    bytes: &[u8],
    source_start: usize,
    source_end: usize,
    wanted_prefix: &str,
    removed_names: &[String],
) -> Option<HtmlAttributePrefixRemovals> {
    if source_start >= bytes.len() || source_end <= source_start {
        return None;
    }

    let tag_end = source_end.saturating_sub(1).min(bytes.len());
    let mut cursor = source_start.saturating_add(1);
    if cursor < tag_end && bytes[cursor] == b'/' {
        return None;
    }

    cursor = skip_ascii_whitespace(bytes, cursor);
    cursor = span_name(bytes, cursor);

    let mut count = 0;
    let mut names = Vec::new();
    let mut removals = Vec::new();
    while cursor < tag_end {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= tag_end || bytes[cursor] == b'>' {
            break;
        }
        if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
            break;
        }

        let attr_start = cursor;
        cursor = span_html_attribute_name(bytes, cursor);
        if cursor == attr_start {
            cursor += 1;
            continue;
        }

        let attr_name = ascii_lower(&bytes[attr_start..cursor]);
        let attr_name_end = cursor;
        cursor = skip_ascii_whitespace(bytes, cursor);

        let mut attr_end = attr_name_end;
        if cursor < tag_end && bytes[cursor] == b'=' {
            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            cursor = skip_html_attribute_value(bytes, cursor);
            attr_end = cursor;
        }

        if !attr_name.starts_with(wanted_prefix)
            || removed_names.iter().any(|name| name == &attr_name)
        {
            continue;
        }

        removals.push((attr_start, attr_end.saturating_sub(attr_start)));
        if !names.iter().any(|name| name == &attr_name) {
            names.push(attr_name);
            count += 1;
        }
    }

    Some((count, removals, names))
}

fn apply_html_text_removals(html: &str, removals: &[HtmlTextRemoval]) -> String {
    let mut removals: Vec<(usize, usize, usize, &str)> = removals
        .iter()
        .enumerate()
        .map(|(index, removal)| {
            (
                removal.start,
                removal.length,
                index,
                removal.replacement.as_str(),
            )
        })
        .collect();
    removals.sort_unstable_by(|left, right| {
        left.0
            .cmp(&right.0)
            .then_with(|| left.1.cmp(&right.1))
            .then_with(|| {
                if left.1 == 0 && right.1 == 0 {
                    right.2.cmp(&left.2)
                } else {
                    left.2.cmp(&right.2)
                }
            })
    });

    let mut compact: Vec<(usize, usize, &str)> = Vec::with_capacity(removals.len());
    for (start, length, _, replacement) in removals {
        if let Some(last) = compact.last_mut() {
            if last.0 == start && last.1 == length && length != 0 {
                last.2 = replacement;
                continue;
            }
        }

        compact.push((start, length, replacement));
    }

    let mut updated = String::with_capacity(html.len());
    let mut cursor = 0usize;

    for (start, length, replacement) in compact {
        if start < cursor || start > html.len() {
            continue;
        }

        updated.push_str(&html[cursor..start]);
        updated.push_str(replacement);
        cursor = start.saturating_add(length).min(html.len());
    }

    updated.push_str(&html[cursor..]);
    updated
}

fn html_attribute_insertion_matches_name(replacement: &str, comparable_name: &str) -> bool {
    let trimmed = replacement.trim_start();
    if trimmed.is_empty() {
        return false;
    }

    let name_end = trimmed
        .find(|byte: char| byte == '=' || byte.is_ascii_whitespace())
        .unwrap_or(trimmed.len());

    trimmed[..name_end].eq_ignore_ascii_case(comparable_name)
}

fn ascii_starts_with_ignore_case(bytes: &[u8], prefix: &[u8]) -> bool {
    bytes
        .get(..prefix.len())
        .map(|candidate| candidate.eq_ignore_ascii_case(prefix))
        .unwrap_or(false)
}

#[derive(Clone, Copy)]
enum HtmlDecodeContext {
    Attribute,
    Text,
}

fn decode_html_attribute_value(value: &str) -> String {
    decode_html_value(value, HtmlDecodeContext::Attribute)
}

fn decode_html_text_value(value: &str) -> String {
    decode_html_value(value, HtmlDecodeContext::Text)
}

fn decode_html_value(value: &str, context: HtmlDecodeContext) -> String {
    let bytes = value.as_bytes();
    let mut decoded = String::new();
    let mut cursor = 0;
    let mut literal_start = 0;

    while cursor < bytes.len() {
        if bytes[cursor] != b'&' {
            cursor += 1;
            continue;
        }

        match decode_html_character_reference(bytes, cursor + 1, context) {
            Some((replacement, next)) => {
                decoded.push_str(&String::from_utf8_lossy(&bytes[literal_start..cursor]));
                decoded.push_str(&replacement);
                cursor = next;
                literal_start = next;
            }
            None => {
                cursor += 1;
            }
        }
    }

    decoded.push_str(&String::from_utf8_lossy(&bytes[literal_start..]));

    decoded
}

fn parse_html_raw_text_content(name: &str, bytes: &[u8]) -> String {
    let text = String::from_utf8_lossy(bytes);
    if matches!(name, "textarea" | "title") {
        decode_html_text_value(&text)
    } else {
        text.into_owned()
    }
}

fn decode_html_character_reference(
    bytes: &[u8],
    start: usize,
    context: HtmlDecodeContext,
) -> Option<(Cow<'static, str>, usize)> {
    if start >= bytes.len() {
        return None;
    }

    if bytes[start] == b'#' {
        return decode_numeric_character_reference(bytes, start + 1);
    }

    if let Some(name_end) = find_byte(bytes, b';', start) {
        let name = &bytes[start..name_end];
        if let Some(replacement) = decode_named_character_reference(name, true) {
            return Some((Cow::Borrowed(replacement), name_end + 1));
        }
    }

    let mut cursor = start;
    while cursor < bytes.len() && bytes[cursor].is_ascii_alphanumeric() {
        cursor += 1;
    }

    let name = &bytes[start..cursor];
    let mut match_end = cursor;
    while match_end > start {
        if let Some(replacement) = decode_named_character_reference(&bytes[start..match_end], false)
        {
            let ambiguous_follower = match_end < bytes.len()
                && (bytes[match_end].is_ascii_alphanumeric() || bytes[match_end] == b'=');

            if ambiguous_follower && matches!(context, HtmlDecodeContext::Attribute) {
                return None;
            }

            return Some((Cow::Borrowed(replacement), match_end));
        }

        match_end -= 1;
    }

    if name.is_empty() {
        return None;
    }

    None
}

fn decode_named_character_reference(name: &[u8], has_semicolon: bool) -> Option<&'static str> {
    let replacement = match name {
        b"amp" => "&",
        b"lt" => "<",
        b"gt" => ">",
        b"quot" => "\"",
        b"apos" if has_semicolon => "'",
        b"nbsp" => "\u{00a0}",
        b"copy" => "\u{00a9}",
        b"reg" => "\u{00ae}",
        b"not" => "\u{00ac}",
        b"ndash" if has_semicolon => "\u{2013}",
        b"mdash" if has_semicolon => "\u{2014}",
        b"hellip" if has_semicolon => "\u{2026}",
        b"notin" if has_semicolon => "\u{2209}",
        _ => return None,
    };

    Some(replacement)
}

fn decode_numeric_character_reference(
    bytes: &[u8],
    start: usize,
) -> Option<(Cow<'static, str>, usize)> {
    if start >= bytes.len() {
        return None;
    }

    let (radix, digits_start, max_digits) = if bytes[start] == b'x' || bytes[start] == b'X' {
        (16, start + 1, 6)
    } else {
        (10, start, 7)
    };
    let valid_digit = |byte: u8| {
        if radix == 16 {
            byte.is_ascii_hexdigit()
        } else {
            byte.is_ascii_digit()
        }
    };
    let mut digits_end = digits_start;
    while digits_end < bytes.len() && valid_digit(bytes[digits_end]) {
        digits_end += 1;
    }

    if digits_end == digits_start {
        return None;
    }

    let mut significant_start = digits_start;
    while significant_start < digits_end && bytes[significant_start] == b'0' {
        significant_start += 1;
    }

    let semicolon_end = if digits_end < bytes.len() && bytes[digits_end] == b';' {
        digits_end + 1
    } else {
        digits_end
    };

    if significant_start == digits_end {
        return Some((Cow::Borrowed("\u{fffd}"), semicolon_end));
    }

    if digits_end - significant_start > max_digits {
        return Some((Cow::Borrowed("\u{fffd}"), semicolon_end));
    }

    let digits = std::str::from_utf8(&bytes[significant_start..digits_end]).ok()?;
    let mut value = u32::from_str_radix(digits, radix).ok()?;

    if (0x80..=0x9f).contains(&value) {
        value = WINDOWS_1252_REPLACEMENTS[(value - 0x80) as usize];
    }

    let character = char::from_u32(value).unwrap_or('\u{fffd}');

    Some((Cow::Owned(character.to_string()), semicolon_end))
}

const WINDOWS_1252_REPLACEMENTS: [u32; 32] = [
    0x20ac, 0x81, 0x201a, 0x0192, 0x201e, 0x2026, 0x2020, 0x2021, 0x02c6, 0x2030, 0x0160, 0x2039,
    0x0152, 0x8d, 0x017d, 0x8f, 0x90, 0x2018, 0x2019, 0x201c, 0x201d, 0x2022, 0x2013, 0x2014,
    0x02dc, 0x2122, 0x0161, 0x203a, 0x0153, 0x9d, 0x017e, 0x0178,
];

fn find_byte(bytes: &[u8], needle: u8, start: usize) -> Option<usize> {
    bytes
        .get(start..)
        .and_then(|tail| tail.iter().position(|byte| *byte == needle))
        .map(|position| position + start)
}

fn find_html_comment_end(bytes: &[u8], start: usize) -> Option<(usize, usize)> {
    let tail = bytes.get(start..)?;
    let mut cursor = 0;
    while cursor + 2 < tail.len() {
        if &tail[cursor..cursor + 3] == b"-->" {
            return Some((start + cursor, 3));
        }
        if cursor + 3 < tail.len() && &tail[cursor..cursor + 4] == b"--!>" {
            return Some((start + cursor, 4));
        }
        cursor += 1;
    }

    None
}

fn find_html_cdata_end(bytes: &[u8], start: usize) -> Option<usize> {
    bytes.get(start..).and_then(|tail| {
        tail.windows(3)
            .position(|window| window == b"]]>")
            .map(|position| position + start)
    })
}

fn find_html_invalid_opening_text_end(bytes: &[u8], start: usize) -> usize {
    let mut cursor = start;

    while let Some(open) = find_byte(bytes, b'<', cursor) {
        if open > cursor && starts_valid_html_token(bytes, open) {
            return open;
        }

        if starts_valid_html_token(bytes, open) {
            return if open == start { start + 1 } else { open };
        }

        let invalid_close = find_byte(bytes, b'>', open + 1).unwrap_or(bytes.len());
        if let Some(next_open) = find_byte(bytes, b'<', open + 1) {
            if next_open < invalid_close {
                if starts_valid_html_token(bytes, next_open) {
                    return next_open;
                }

                cursor = next_open;
                continue;
            }
        }

        cursor = invalid_close.saturating_add(1).min(bytes.len());
    }

    bytes.len()
}

fn starts_invalid_html_opening_text(bytes: &[u8], open: usize) -> bool {
    match bytes.get(open + 1) {
        None => true,
        Some(b'!' | b'?' | b'/') => false,
        Some(next) => !next.is_ascii_alphabetic(),
    }
}

fn starts_valid_html_token(bytes: &[u8], open: usize) -> bool {
    let Some(next) = bytes.get(open + 1) else {
        return false;
    };

    matches!(*next, b'!' | b'?' | b'/') || next.is_ascii_alphabetic()
}

fn is_ascii_case_insensitive_prefix(bytes: &[u8], start: usize, prefix: &[u8]) -> bool {
    bytes
        .get(start..start + prefix.len())
        .map(|candidate| candidate.eq_ignore_ascii_case(prefix))
        .unwrap_or(false)
}

fn skip_ascii_whitespace(bytes: &[u8], mut cursor: usize) -> usize {
    while cursor < bytes.len() && bytes[cursor].is_ascii_whitespace() {
        cursor += 1;
    }
    cursor
}

fn span_name(bytes: &[u8], mut cursor: usize) -> usize {
    while cursor < bytes.len()
        && (bytes[cursor].is_ascii_alphanumeric() || matches!(bytes[cursor], b':' | b'_' | b'-'))
    {
        cursor += 1;
    }
    cursor
}

fn span_html_attribute_name(bytes: &[u8], mut cursor: usize) -> usize {
    if cursor < bytes.len() && bytes[cursor] == b'=' {
        cursor += 1;
        while cursor < bytes.len()
            && !bytes[cursor].is_ascii_whitespace()
            && !matches!(bytes[cursor], b'=' | b'>' | b'/')
        {
            cursor += 1;
        }
        return cursor;
    }

    while cursor < bytes.len()
        && !bytes[cursor].is_ascii_whitespace()
        && !matches!(bytes[cursor], b'=' | b'>' | b'/')
    {
        cursor += 1;
    }
    cursor
}

fn span_html_pi_target(bytes: &[u8], cursor: usize) -> usize {
    if cursor >= bytes.len()
        || !(bytes[cursor].is_ascii_alphabetic() || matches!(bytes[cursor], b':' | b'_'))
    {
        return cursor;
    }

    let mut cursor = cursor + 1;
    while cursor < bytes.len()
        && (bytes[cursor].is_ascii_alphanumeric()
            || matches!(bytes[cursor], b':' | b'_' | b'-' | b'.'))
    {
        cursor += 1;
    }

    cursor
}

fn span_unquoted_value(bytes: &[u8], mut cursor: usize) -> usize {
    while cursor < bytes.len() && !bytes[cursor].is_ascii_whitespace() && bytes[cursor] != b'>' {
        cursor += 1;
    }
    cursor
}

fn ascii_lower(bytes: &[u8]) -> String {
    bytes
        .iter()
        .map(|byte| byte.to_ascii_lowercase() as char)
        .collect()
}

fn push_ascii_lower(output: &mut String, bytes: &[u8]) {
    for byte in bytes {
        output.push(byte.to_ascii_lowercase() as char);
    }
}

fn html_attribute_initial_bit(byte: u8) -> u32 {
    let lower = byte.to_ascii_lowercase();
    if lower.is_ascii_lowercase() {
        1u32 << (lower - b'a')
    } else {
        0
    }
}

fn html_attribute_prefix_initial_bit(prefix: &str) -> u32 {
    prefix
        .as_bytes()
        .first()
        .map(|byte| html_attribute_initial_bit(*byte))
        .unwrap_or(0)
}

fn html_attribute_names_initial_bits(names: &[String]) -> u32 {
    names.iter().fold(0u32, |bits, name| {
        bits | html_attribute_prefix_initial_bit(name)
    })
}

fn is_html_void_element(name: &str) -> bool {
    matches!(
        name,
        "area"
            | "base"
            | "br"
            | "col"
            | "embed"
            | "hr"
            | "img"
            | "input"
            | "link"
            | "meta"
            | "source"
            | "track"
            | "wbr"
    )
}

fn is_html_processor_void_element(name: &str) -> bool {
    matches!(
        name,
        "area"
            | "base"
            | "basefont"
            | "bgsound"
            | "br"
            | "col"
            | "embed"
            | "frame"
            | "hr"
            | "img"
            | "input"
            | "keygen"
            | "link"
            | "meta"
            | "param"
            | "source"
            | "track"
            | "wbr"
    )
}

fn is_html_processor_special_element(name: &str) -> bool {
    matches!(
        name,
        "address"
            | "applet"
            | "area"
            | "article"
            | "aside"
            | "base"
            | "basefont"
            | "bgsound"
            | "blockquote"
            | "body"
            | "br"
            | "button"
            | "caption"
            | "center"
            | "col"
            | "colgroup"
            | "dd"
            | "details"
            | "dir"
            | "div"
            | "dl"
            | "dt"
            | "embed"
            | "fieldset"
            | "figcaption"
            | "figure"
            | "footer"
            | "form"
            | "frame"
            | "frameset"
            | "h1"
            | "h2"
            | "h3"
            | "h4"
            | "h5"
            | "h6"
            | "head"
            | "header"
            | "hgroup"
            | "hr"
            | "html"
            | "iframe"
            | "img"
            | "input"
            | "keygen"
            | "li"
            | "link"
            | "listing"
            | "main"
            | "marquee"
            | "menu"
            | "meta"
            | "nav"
            | "noembed"
            | "noframes"
            | "noscript"
            | "object"
            | "ol"
            | "p"
            | "param"
            | "plaintext"
            | "pre"
            | "script"
            | "search"
            | "section"
            | "select"
            | "source"
            | "style"
            | "summary"
            | "table"
            | "tbody"
            | "td"
            | "template"
            | "textarea"
            | "tfoot"
            | "th"
            | "thead"
            | "title"
            | "tr"
            | "track"
            | "ul"
            | "wbr"
            | "xmp"
    )
}

fn is_html_raw_text_element(name: &str) -> bool {
    matches!(
        name,
        "iframe" | "noembed" | "noframes" | "script" | "style" | "textarea" | "title" | "xmp"
    )
}

fn find_html_closing_tag(bytes: &[u8], name: &str, start: usize) -> Option<usize> {
    let needle = name.as_bytes();
    let mut cursor = start;

    while cursor + needle.len() + 2 <= bytes.len() {
        let open = find_byte(bytes, b'<', cursor)?;

        let name_start = open + 2;
        let name_end = name_start + needle.len();
        if open + 1 < bytes.len()
            && bytes[open + 1] == b'/'
            && name_end <= bytes.len()
            && bytes[name_start..name_end].eq_ignore_ascii_case(needle)
            && is_html_tag_name_boundary(bytes, name_end)
        {
            return Some(open);
        }

        cursor = open + 1;
    }

    None
}

fn is_html_tag_name_boundary(bytes: &[u8], cursor: usize) -> bool {
    cursor >= bytes.len()
        || bytes[cursor].is_ascii_whitespace()
        || matches!(bytes[cursor], b'>' | b'/')
}

fn should_synthesize_html_implied_closer(name: &str, breadcrumbs: &[String]) -> bool {
    let Some(last_open) = breadcrumbs.last() else {
        return false;
    };

    if last_open == "P" && html_start_tag_closes_p(name) {
        return true;
    }

    if is_html_heading_name(last_open) && is_html_heading_start_tag(name) {
        return true;
    }

    if name == "button" && breadcrumbs.iter().any(|open| open == "BUTTON") {
        return breadcrumbs.len() > 2;
    }

    if name == "nobr" && breadcrumbs.iter().any(|open| open == "NOBR") {
        return breadcrumbs.len() > 2;
    }

    matches!(
        (name, last_open.as_str()),
        ("li", "LI")
            | ("option", "OPTION")
            | ("p", "P")
            | ("rt", "RT")
            | ("rt", "RP")
            | ("rp", "RT")
            | ("rp", "RP")
            | ("optgroup", "OPTION")
            | ("optgroup", "OPTGROUP")
            | ("dt", "DT")
            | ("dt", "DD")
            | ("dd", "DT")
            | ("dd", "DD")
            | ("caption", "CAPTION")
            | ("caption", "COLGROUP")
            | ("caption", "TD")
            | ("caption", "TH")
            | ("caption", "TR")
            | ("caption", "TBODY")
            | ("caption", "THEAD")
            | ("caption", "TFOOT")
            | ("col", "CAPTION")
            | ("colgroup", "CAPTION")
            | ("colgroup", "TD")
            | ("colgroup", "TH")
            | ("colgroup", "TR")
            | ("colgroup", "TBODY")
            | ("colgroup", "THEAD")
            | ("colgroup", "TFOOT")
            | ("tbody", "CAPTION")
            | ("tbody", "TD")
            | ("tbody", "TH")
            | ("tbody", "TR")
            | ("tbody", "TBODY")
            | ("tbody", "THEAD")
            | ("tbody", "TFOOT")
            | ("thead", "CAPTION")
            | ("thead", "TD")
            | ("thead", "TH")
            | ("thead", "TR")
            | ("thead", "TBODY")
            | ("thead", "THEAD")
            | ("thead", "TFOOT")
            | ("tfoot", "CAPTION")
            | ("tfoot", "TD")
            | ("tfoot", "TH")
            | ("tfoot", "TR")
            | ("tfoot", "TBODY")
            | ("tfoot", "THEAD")
            | ("tfoot", "TFOOT")
            | ("tr", "CAPTION")
            | ("td", "CAPTION")
            | ("th", "CAPTION")
            | ("tr", "COLGROUP")
            | ("td", "COLGROUP")
            | ("th", "COLGROUP")
            | ("tbody", "COLGROUP")
            | ("thead", "COLGROUP")
            | ("tfoot", "COLGROUP")
            | ("hr", "OPTION")
            | ("input", "OPTION")
            | ("input", "SELECT")
            | ("textarea", "OPTION")
            | ("textarea", "SELECT")
            | ("td", "TD")
            | ("td", "TH")
            | ("th", "TD")
            | ("th", "TH")
            | ("tr", "TD")
            | ("tr", "TH")
            | ("tr", "TR")
    )
}

fn is_html_heading_name(name: &str) -> bool {
    matches!(name, "H1" | "H2" | "H3" | "H4" | "H5" | "H6")
}

fn is_html_heading_start_tag(name: &str) -> bool {
    matches!(name, "h1" | "h2" | "h3" | "h4" | "h5" | "h6")
}

fn html_start_tag_closes_p(name: &str) -> bool {
    matches!(
        name,
        "address"
            | "article"
            | "aside"
            | "blockquote"
            | "details"
            | "dialog"
            | "dir"
            | "div"
            | "dl"
            | "fieldset"
            | "figcaption"
            | "figure"
            | "footer"
            | "form"
            | "h1"
            | "h2"
            | "h3"
            | "h4"
            | "h5"
            | "h6"
            | "header"
            | "hgroup"
            | "hr"
            | "listing"
            | "main"
            | "menu"
            | "nav"
            | "ol"
            | "p"
            | "pre"
            | "search"
            | "section"
            | "table"
            | "ul"
    )
}

fn should_synthesize_html_implied_colgroup(name: &str, breadcrumbs: &[String]) -> bool {
    name == "col" && breadcrumbs.last().map(|last| last.as_str()) == Some("TABLE")
}

fn should_synthesize_html_implied_table_body(name: &str, breadcrumbs: &[String]) -> bool {
    matches!(name, "tr" | "td" | "th")
        && breadcrumbs.last().map(|last| last.as_str()) == Some("TABLE")
}

fn should_synthesize_html_implied_table_row(name: &str, breadcrumbs: &[String]) -> bool {
    matches!(name, "td" | "th")
        && matches!(
            breadcrumbs.last().map(|last| last.as_str()),
            Some("TBODY" | "THEAD" | "TFOOT")
        )
}

fn should_synthesize_html_table_form_closer(name: &str, breadcrumbs: &[String]) -> bool {
    matches!(
        name,
        "caption" | "col" | "colgroup" | "tbody" | "thead" | "tfoot" | "tr" | "td" | "th"
    ) && breadcrumbs.len() >= 4
        && breadcrumbs.last().map(|last| last.as_str()) == Some("FORM")
        && breadcrumbs
            .get(breadcrumbs.len().saturating_sub(2))
            .map(|ancestor| ancestor.as_str())
            == Some("TABLE")
}

fn is_html_table_form_context(breadcrumbs: &[String]) -> bool {
    breadcrumbs.len() >= 4
        && breadcrumbs.last().map(|last| last.as_str()) == Some("FORM")
        && breadcrumbs
            .get(breadcrumbs.len().saturating_sub(2))
            .map(|ancestor| ancestor.as_str())
            == Some("TABLE")
}

fn should_abort_html_table_child_start(name: &str, breadcrumbs: &[String]) -> bool {
    breadcrumbs.last().map(|last| last.as_str()) == Some("TABLE")
        && !matches!(
            name,
            "caption"
                | "col"
                | "colgroup"
                | "form"
                | "script"
                | "style"
                | "tbody"
                | "td"
                | "template"
                | "tfoot"
                | "th"
                | "thead"
                | "tr"
        )
}

fn should_abort_html_table_child_closer(name: &str, breadcrumbs: &[String]) -> bool {
    breadcrumbs.last().map(|last| last.as_str()) == Some("TABLE")
        && !matches!(
            name,
            "caption"
                | "colgroup"
                | "table"
                | "tbody"
                | "td"
                | "template"
                | "tfoot"
                | "th"
                | "thead"
                | "tr"
        )
}

fn should_synthesize_html_implied_closer_before_closing(
    closing_name: &str,
    breadcrumbs: &[String],
) -> bool {
    let Some(last_open) = breadcrumbs.last() else {
        return false;
    };

    if closing_name == "BUTTON" && last_open != "BUTTON" {
        return breadcrumbs.iter().any(|open| open == "BUTTON");
    }

    if closing_name == "NOBR" && last_open != "NOBR" {
        return breadcrumbs.iter().any(|open| open == "NOBR");
    }

    matches!(
        (last_open.as_str(), closing_name),
        ("LI", "UL")
            | ("LI", "OL")
            | ("OPTION", "SELECT")
            | ("OPTION", "OPTGROUP")
            | ("OPTGROUP", "SELECT")
            | ("DT", "DL")
            | ("DD", "DL")
            | ("RT", "RUBY")
            | ("RP", "RUBY")
            | ("SUMMARY", "DETAILS")
            | ("CAPTION", "TABLE")
            | ("COLGROUP", "TABLE")
            | ("TD", "TR")
            | ("TH", "TR")
            | ("TD", "TBODY")
            | ("TH", "TBODY")
            | ("TD", "THEAD")
            | ("TH", "THEAD")
            | ("TD", "TFOOT")
            | ("TH", "TFOOT")
            | ("TD", "TABLE")
            | ("TH", "TABLE")
            | ("TR", "TBODY")
            | ("TR", "THEAD")
            | ("TR", "TFOOT")
            | ("TR", "TABLE")
            | ("TBODY", "TABLE")
            | ("THEAD", "TABLE")
            | ("TFOOT", "TABLE")
    )
}

fn synthesize_html_implied_closer_token(
    source_offset: usize,
    breadcrumbs: &mut Vec<String>,
) -> Option<HtmlTag> {
    let name = match breadcrumbs.last().map(|name| name.as_str()) {
        Some("LI") => "li",
        Some("OPTION") => "option",
        Some("OPTGROUP") => "optgroup",
        Some("P") => "p",
        Some("RT") => "rt",
        Some("RP") => "rp",
        Some("DT") => "dt",
        Some("DD") => "dd",
        Some("CAPTION") => "caption",
        Some("COLGROUP") => "colgroup",
        Some("TD") => "td",
        Some("TH") => "th",
        Some("TR") => "tr",
        Some("TBODY") => "tbody",
        Some("THEAD") => "thead",
        Some("TFOOT") => "tfoot",
        Some(_) if breadcrumbs.len() > 2 => "",
        _ => return None,
    };
    let name = if name.is_empty() {
        breadcrumbs
            .last()
            .map(|last| last.to_ascii_lowercase())
            .unwrap_or_default()
    } else {
        name.to_string()
    };

    breadcrumbs.pop();

    Some(HtmlTag {
        name,
        token_type: "#tag".to_string(),
        closing: true,
        attributes: HashMap::new(),
        attribute_order: Vec::new(),
        attribute_name_initials: 0,
        source_start: source_offset,
        source_end: source_offset,
        text: String::new(),
        comment_type: None,
        full_comment_text: None,
        breadcrumbs: breadcrumbs.clone(),
        depth: breadcrumbs.len(),
    })
}

fn synthesize_html_implied_opener_token(
    name: &str,
    source_offset: usize,
    breadcrumbs: &mut Vec<String>,
) -> HtmlTag {
    let uppercase_name = name.to_ascii_uppercase();
    breadcrumbs.push(uppercase_name.clone());
    let token_breadcrumbs = breadcrumbs.clone();

    HtmlTag {
        name: name.to_string(),
        token_type: "#tag".to_string(),
        closing: false,
        attributes: HashMap::new(),
        attribute_order: Vec::new(),
        attribute_name_initials: 0,
        source_start: source_offset,
        source_end: source_offset,
        text: String::new(),
        comment_type: None,
        full_comment_text: None,
        breadcrumbs: token_breadcrumbs,
        depth: breadcrumbs.len(),
    }
}

fn pop_html_breadcrumb(breadcrumbs: &mut Vec<String>, name: &str) {
    while breadcrumbs.len() > 2 {
        match breadcrumbs.pop() {
            Some(open_name) if open_name == name => break,
            Some(_) => continue,
            None => break,
        }
    }
}

fn html_breadcrumb_contains_open_element(breadcrumbs: &[String], name: &str) -> bool {
    breadcrumbs
        .iter()
        .skip(2)
        .any(|open_name| open_name == name)
}

#[cfg(test)]
mod tests {
    use super::{
        apply_html_text_removals, find_html_attribute_names_with_prefix_count,
        find_html_attribute_names_with_prefix_string, find_html_attribute_removals,
        find_html_attribute_removals_with_prefix, html_tag_has_self_closing_flag,
        html_token_compact_summary, initial_html_breadcrumbs, parse_html_tags,
        parse_next_html_token, parse_next_plain_html_tag_token, HtmlTextRemoval,
    };

    fn collect_processor_tokens(html: &str) -> Vec<(String, bool, String, String)> {
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        tokens
    }

    #[test]
    fn parses_html_tag_names_and_attributes() {
        let tags = parse_html_tags(
            "<main data-id='7'><A href=/x?one=1&amp;two=2 data-kind=\"nav\">x</A></main>",
        );

        assert_eq!(5, tags.len());
        assert_eq!("main", tags[0].name);
        assert_eq!(Some(&"7".to_string()), tags[0].attributes.get("data-id"));
        assert_eq!("a", tags[1].name);
        assert_eq!(
            Some(&"/x?one=1&two=2".to_string()),
            tags[1].attributes.get("href")
        );
        assert_eq!(
            Some(&"nav".to_string()),
            tags[1].attributes.get("data-kind")
        );
        assert_eq!("#text", tags[2].token_type);
        assert_eq!("x", tags[2].text);
        assert!(tags[3].closing);

        let tags = parse_html_tags("<p .x=\"dot\" @x=\"at\" data-x=\"ok\">x</p>");

        assert_eq!(Some(&"dot".to_string()), tags[0].attributes.get(".x"));
        assert_eq!(Some(&"at".to_string()), tags[0].attributes.get("@x"));
        assert_eq!(Some(&"ok".to_string()), tags[0].attributes.get("data-x"));
        assert_eq!(
            vec![".x".to_string(), "@x".to_string(), "data-x".to_string()],
            tags[0].attribute_order
        );

        let tags = parse_html_tags("<p =b a/=c data-x=\"ok\">x</p>");

        assert_eq!(Some(&String::new()), tags[0].attributes.get("=b"));
        assert_eq!(Some(&String::new()), tags[0].attributes.get("a"));
        assert_eq!(Some(&String::new()), tags[0].attributes.get("=c"));
        assert_eq!(None, tags[0].attributes.get("b"));
        assert_eq!(Some(&"ok".to_string()), tags[0].attributes.get("data-x"));
        assert_eq!(
            vec![
                "=b".to_string(),
                "a".to_string(),
                "=c".to_string(),
                "data-x".to_string()
            ],
            tags[0].attribute_order
        );
    }

    #[test]
    fn parses_plain_html_tags_without_decoding_discarded_tokens() {
        let html =
            "text <!--comment--><!DOCTYPE html><main data-id='7'>x<script>a < b</script></main>";
        let mut offset = 0;
        let mut tags = Vec::new();

        while let Some(tag) = parse_next_plain_html_tag_token(html.as_bytes(), &mut offset) {
            tags.push((tag.name, tag.closing, tag.attribute_name_initials, tag.text));
        }

        assert_eq!(3, tags.len());
        assert_eq!("main", tags[0].0);
        assert!(!tags[0].1);
        assert_ne!(
            0,
            tags[0].2 & super::html_attribute_prefix_initial_bit("data-")
        );
        assert_eq!("script", tags[1].0);
        assert_eq!("a < b", tags[1].3);
        assert_eq!("main", tags[2].0);
        assert!(tags[2].1);
    }

    #[test]
    fn synthesizes_selected_processor_implied_closers() {
        let html = "<ul><li>One<li>Two</ul>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "ul".to_string(),
                    false,
                    "HTML/BODY/UL".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "li".to_string(),
                    false,
                    "HTML/BODY/UL/LI".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/UL/LI/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "li".to_string(),
                    true,
                    "HTML/BODY/UL".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "li".to_string(),
                    false,
                    "HTML/BODY/UL/LI".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/UL/LI/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "li".to_string(),
                    true,
                    "HTML/BODY/UL".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "ul".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<table><tr><td>A<td>B</tr></table>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "table".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<table><form><tr><td>x</td></tr></table>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    false,
                    "HTML/BODY/TABLE/FORM".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "table".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<table><form></form><tr><td>x</td></tr></table>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    false,
                    "HTML/BODY/TABLE/FORM".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<table><form> <tr><td>x</table>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    false,
                    "HTML/BODY/TABLE/FORM".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "tbody".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "table".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<table><form>x<tr><td>y</table>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    false,
                    "HTML/BODY/TABLE/FORM".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<table><caption>A<tr><td>B</table>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "caption".to_string(),
                    false,
                    "HTML/BODY/TABLE/CAPTION".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/CAPTION/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "caption".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "table".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<div><span>Text";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "div".to_string(),
                    false,
                    "HTML/BODY/DIV".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "span".to_string(),
                    false,
                    "HTML/BODY/DIV/SPAN".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/DIV/SPAN/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "span".to_string(),
                    true,
                    "HTML/BODY/DIV".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "div".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<a><img>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "a".to_string(),
                    false,
                    "HTML/BODY/A".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "img".to_string(),
                    false,
                    "HTML/BODY/A/IMG".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "a".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<p>Text<div>Block</div>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "p".to_string(),
                    false,
                    "HTML/BODY/P".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/P/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "p".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "div".to_string(),
                    false,
                    "HTML/BODY/DIV".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/DIV/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "div".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<h1>One<h2>Two</h2>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "h1".to_string(),
                    false,
                    "HTML/BODY/H1".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/H1/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "h1".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "h2".to_string(),
                    false,
                    "HTML/BODY/H2".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/H2/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "h2".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );

        let html = "<p>Text<span>Inline</span>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((
                token.name,
                token.closing,
                token.breadcrumbs.join("/"),
                token.token_type,
            ));
        }

        assert_eq!(
            vec![
                (
                    "p".to_string(),
                    false,
                    "HTML/BODY/P".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/P/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "span".to_string(),
                    false,
                    "HTML/BODY/P/SPAN".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/P/SPAN/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "span".to_string(),
                    true,
                    "HTML/BODY/P".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "p".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            tokens
        );
    }

    #[test]
    fn handles_table_form_comments_and_unsupported_table_starts() {
        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "table".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            collect_processor_tokens("<table><tbody><tr><td>a<tbody><tr><td>b</table>")
        );

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    false,
                    "HTML/BODY/TABLE/FORM".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#comment".to_string(),
                    false,
                    "HTML/BODY/TABLE/#comment".to_string(),
                    "#comment".to_string(),
                ),
                (
                    "tbody".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "table".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            collect_processor_tokens("<table><form><!--x--><tr><td>y</table>")
        );

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "caption".to_string(),
                    false,
                    "HTML/BODY/TABLE/CAPTION".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/CAPTION/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "caption".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "table".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            collect_processor_tokens("<table><tr><td>x<caption>c</caption></table>")
        );

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "table".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            collect_processor_tokens("<table></tr><tr><td>x</table>")
        );

        assert_eq!(
            vec![(
                "table".to_string(),
                false,
                "HTML/BODY/TABLE".to_string(),
                "#tag".to_string(),
            )],
            collect_processor_tokens("<table></p><tr><td>x</table>")
        );

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "td".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "#text".to_string(),
                    false,
                    "HTML/BODY/TABLE/TBODY/TR/TD/#text".to_string(),
                    "#text".to_string(),
                ),
                (
                    "td".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY/TR".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tr".to_string(),
                    true,
                    "HTML/BODY/TABLE/TBODY".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "tbody".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "table".to_string(),
                    true,
                    "HTML/BODY".to_string(),
                    "#tag".to_string(),
                ),
            ],
            collect_processor_tokens("<table></template><tr><td>x</table>")
        );

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    false,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    false,
                    "HTML/BODY/TABLE/FORM".to_string(),
                    "#tag".to_string(),
                ),
                (
                    "form".to_string(),
                    true,
                    "HTML/BODY/TABLE".to_string(),
                    "#tag".to_string(),
                ),
            ],
            collect_processor_tokens("<table><form><div>x</div><tr><td>y</table>")
        );

        assert_eq!(
            vec![(
                "table".to_string(),
                false,
                "HTML/BODY/TABLE".to_string(),
                "#tag".to_string(),
            )],
            collect_processor_tokens("<table><select>x</select><tr><td>y</table>")
        );

        assert_eq!(
            vec![(
                "table".to_string(),
                false,
                "HTML/BODY/TABLE".to_string(),
                "#tag".to_string(),
            )],
            collect_processor_tokens("<table><span>x</span><tr><td>y</table>")
        );
    }

    #[test]
    fn subdivides_processor_text_tokens_and_stops_at_table_text() {
        let html = "<p> A <b>B</b></p>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((token.name, token.text, token.breadcrumbs.join("/")));
        }

        assert_eq!(
            vec![
                ("p".to_string(), String::new(), "HTML/BODY/P".to_string()),
                (
                    "#text".to_string(),
                    " ".to_string(),
                    "HTML/BODY/P/#text".to_string(),
                ),
                (
                    "#text".to_string(),
                    "A ".to_string(),
                    "HTML/BODY/P/#text".to_string(),
                ),
                ("b".to_string(), String::new(), "HTML/BODY/P/B".to_string()),
                (
                    "#text".to_string(),
                    "B".to_string(),
                    "HTML/BODY/P/B/#text".to_string(),
                ),
                ("b".to_string(), String::new(), "HTML/BODY/P".to_string()),
                ("p".to_string(), String::new(), "HTML/BODY".to_string()),
            ],
            tokens
        );

        let html = "<p> &#10;&#x20;A</p>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((token.name, token.text, token.breadcrumbs.join("/")));
        }

        assert_eq!(
            vec![
                ("p".to_string(), String::new(), "HTML/BODY/P".to_string()),
                (
                    "#text".to_string(),
                    " \n ".to_string(),
                    "HTML/BODY/P/#text".to_string(),
                ),
                (
                    "#text".to_string(),
                    "A".to_string(),
                    "HTML/BODY/P/#text".to_string(),
                ),
                ("p".to_string(), String::new(), "HTML/BODY".to_string()),
            ],
            tokens
        );

        let html = "<p>\0 A\0B</p>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((token.name, token.text, token.breadcrumbs.join("/")));
        }

        assert_eq!(
            vec![
                ("p".to_string(), String::new(), "HTML/BODY/P".to_string()),
                (
                    "#text".to_string(),
                    " ".to_string(),
                    "HTML/BODY/P/#text".to_string(),
                ),
                (
                    "#text".to_string(),
                    "AB".to_string(),
                    "HTML/BODY/P/#text".to_string(),
                ),
                ("p".to_string(), String::new(), "HTML/BODY".to_string()),
            ],
            tokens
        );

        let html = "<p>\r\n\tA\rB</p>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((token.name, token.text, token.breadcrumbs.join("/")));
        }

        assert_eq!(
            vec![
                ("p".to_string(), String::new(), "HTML/BODY/P".to_string()),
                (
                    "#text".to_string(),
                    "\n\t".to_string(),
                    "HTML/BODY/P/#text".to_string(),
                ),
                (
                    "#text".to_string(),
                    "A\nB".to_string(),
                    "HTML/BODY/P/#text".to_string(),
                ),
                ("p".to_string(), String::new(), "HTML/BODY".to_string()),
            ],
            tokens
        );

        let html = "<table> A <tr><td>B</table>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((token.name, token.text, token.breadcrumbs.join("/")));
        }

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    String::new(),
                    "HTML/BODY/TABLE".to_string(),
                ),
                (
                    "#text".to_string(),
                    " ".to_string(),
                    "HTML/BODY/TABLE/#text".to_string(),
                ),
            ],
            tokens
        );

        let html = "<table>&#x20;A<tr><td>B</table>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let mut tokens = Vec::new();

        while let Some(token) = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            true,
            true,
            true,
            false,
        ) {
            tokens.push((token.name, token.text, token.breadcrumbs.join("/")));
        }

        assert_eq!(
            vec![
                (
                    "table".to_string(),
                    String::new(),
                    "HTML/BODY/TABLE".to_string(),
                ),
                (
                    "#text".to_string(),
                    " ".to_string(),
                    "HTML/BODY/TABLE/#text".to_string(),
                ),
            ],
            tokens
        );
    }

    #[test]
    fn serializes_attribute_prefix_names_for_extension_bridge() {
        let html = "<main data-id='7' DATA-kind=\"nav\" aria-label=\"Main\"></main>";
        let tags = parse_html_tags(html);

        assert_eq!(
            Some("data-id\x1fdata-kind".to_string()),
            find_html_attribute_names_with_prefix_string(
                html.as_bytes(),
                tags[0].source_start,
                tags[0].source_end,
                "data-",
            )
        );
        assert_eq!(
            Some(2),
            find_html_attribute_names_with_prefix_count(
                html.as_bytes(),
                tags[0].source_start,
                tags[0].source_end,
                "data-",
            )
        );

        let duplicate_html = "<main data-id='7' DATA-id=\"duplicate\" data-kind=\"nav\"></main>";
        let duplicate_tags = parse_html_tags(duplicate_html);
        assert_eq!(
            Some("data-id\x1fdata-kind".to_string()),
            find_html_attribute_names_with_prefix_string(
                duplicate_html.as_bytes(),
                duplicate_tags[0].source_start,
                duplicate_tags[0].source_end,
                "data-",
            )
        );
        assert_eq!(
            Some(2),
            find_html_attribute_names_with_prefix_count(
                duplicate_html.as_bytes(),
                duplicate_tags[0].source_start,
                duplicate_tags[0].source_end,
                "data-",
            )
        );

        assert_eq!(
            Some(String::new()),
            find_html_attribute_names_with_prefix_string(
                html.as_bytes(),
                tags[0].source_start,
                tags[0].source_end,
                "role",
            )
        );
        assert_eq!(
            Some(0),
            find_html_attribute_names_with_prefix_count(
                html.as_bytes(),
                tags[0].source_start,
                tags[0].source_end,
                "role",
            )
        );
        assert!(tags[1].closing);
        assert_eq!(
            None,
            find_html_attribute_names_with_prefix_string(
                html.as_bytes(),
                tags[1].source_start,
                tags[1].source_end,
                "data-",
            )
        );
        assert_eq!(
            None,
            find_html_attribute_names_with_prefix_count(
                html.as_bytes(),
                tags[1].source_start,
                tags[1].source_end,
                "data-",
            )
        );
    }

    #[test]
    fn detects_html_self_closing_flags() {
        let html = "<main /><img data-src=\"/x\" ><br/>";
        let tags = parse_html_tags(html);

        assert_eq!("main", tags[0].name);
        assert!(html_tag_has_self_closing_flag(
            html.as_bytes(),
            tags[0].source_start,
            tags[0].source_end,
        ));
        assert_eq!("img", tags[1].name);
        assert!(!html_tag_has_self_closing_flag(
            html.as_bytes(),
            tags[1].source_start,
            tags[1].source_end,
        ));
        assert_eq!("br", tags[2].name);
        assert!(html_tag_has_self_closing_flag(
            html.as_bytes(),
            tags[2].source_start,
            tags[2].source_end,
        ));
    }

    #[test]
    fn records_attribute_initials_without_value_decoding() {
        let html = "<main data-id='7' DATA-kind=\"nav\" aria-label=\"Main\" hidden></main>";
        let mut offset = 0;
        let mut breadcrumbs = initial_html_breadcrumbs();
        let tag = parse_next_html_token(
            html.as_bytes(),
            &mut offset,
            &mut breadcrumbs,
            false,
            false,
            false,
            false,
        )
        .expect("expected opening tag");

        let data_bit = 1u32 << (b'd' - b'a');
        let aria_bit = 1u32 << (b'a' - b'a');
        let hidden_bit = 1u32 << (b'h' - b'a');
        let class_bit = 1u32 << (b'c' - b'a');

        assert_ne!(0, tag.attribute_name_initials & data_bit);
        assert_ne!(0, tag.attribute_name_initials & aria_bit);
        assert_ne!(0, tag.attribute_name_initials & hidden_bit);
        assert_eq!(0, tag.attribute_name_initials & class_bit);
        assert!(tag.attributes.is_empty());
        assert!(tag.attribute_order.is_empty());
    }

    #[test]
    fn finds_and_applies_attribute_removals() {
        let html = "<main data-id='7' DATA-id=\"duplicate\" class=\"entry\"><a DATA-kind=\"nav\" href=\"/x\">Link</a></main>";
        let tags = parse_html_tags(html);
        let removals = find_html_attribute_removals(
            html.as_bytes(),
            tags[0].source_start,
            tags[0].source_end,
            "data-id",
        );

        assert_eq!(2, removals.len());
        assert_eq!(
            "<main   class=\"entry\"><a DATA-kind=\"nav\" href=\"/x\">Link</a></main>",
            apply_html_text_removals(
                html,
                &removals
                    .iter()
                    .map(|(start, length)| HtmlTextRemoval {
                        start: *start,
                        length: *length,
                        replacement: String::new(),
                    })
                    .collect::<Vec<_>>(),
            )
        );

        let boolean_html = "<p data-x disabled data-y=\"1\">Text</p>";
        let boolean_tags = parse_html_tags(boolean_html);
        let removals = find_html_attribute_removals(
            boolean_html.as_bytes(),
            boolean_tags[0].source_start,
            boolean_tags[0].source_end,
            "disabled",
        );

        assert_eq!(1, removals.len());
        assert_eq!(
            "<p data-x  data-y=\"1\">Text</p>",
            apply_html_text_removals(
                boolean_html,
                &removals
                    .iter()
                    .map(|(start, length)| HtmlTextRemoval {
                        start: *start,
                        length: *length,
                        replacement: String::new(),
                    })
                    .collect::<Vec<_>>(),
            )
        );
    }

    #[test]
    fn finds_and_applies_attribute_removals_with_prefix() {
        let html = "<main data-id='7' DATA-id=\"duplicate\" data-track=\"1\" class=\"entry\"><a DATA-kind=\"nav\" href=\"/x\">Link</a></main>";
        let tags = parse_html_tags(html);
        let (count, removals, names) = find_html_attribute_removals_with_prefix(
            html.as_bytes(),
            tags[0].source_start,
            tags[0].source_end,
            "data-",
            &[],
        )
        .expect("expected opening tag removals");

        assert_eq!(2, count);
        assert_eq!(3, removals.len());
        assert_eq!(vec!["data-id".to_string(), "data-track".to_string()], names);
        assert_eq!(
            "<main    class=\"entry\"><a DATA-kind=\"nav\" href=\"/x\">Link</a></main>",
            apply_html_text_removals(
                html,
                &removals
                    .iter()
                    .map(|(start, length)| HtmlTextRemoval {
                        start: *start,
                        length: *length,
                        replacement: String::new(),
                    })
                    .collect::<Vec<_>>(),
            )
        );

        let (count, removals, names) = find_html_attribute_removals_with_prefix(
            html.as_bytes(),
            tags[0].source_start,
            tags[0].source_end,
            "data-",
            &["data-id".to_string(), "data-track".to_string()],
        )
        .expect("expected opening tag removals");

        assert_eq!(0, count);
        assert!(removals.is_empty());
        assert!(names.is_empty());

        let boolean_html = "<p data-x disabled data-y=\"1\">Text</p>";
        let boolean_tags = parse_html_tags(boolean_html);
        let (count, removals, names) = find_html_attribute_removals_with_prefix(
            boolean_html.as_bytes(),
            boolean_tags[0].source_start,
            boolean_tags[0].source_end,
            "d",
            &[],
        )
        .expect("expected opening tag removals");

        assert_eq!(3, count);
        assert_eq!(3, removals.len());
        assert_eq!(
            vec![
                "data-x".to_string(),
                "disabled".to_string(),
                "data-y".to_string()
            ],
            names
        );
        assert_eq!(
            "<p   >Text</p>",
            apply_html_text_removals(
                boolean_html,
                &removals
                    .iter()
                    .map(|(start, length)| HtmlTextRemoval {
                        start: *start,
                        length: *length,
                        replacement: String::new(),
                    })
                    .collect::<Vec<_>>(),
            )
        );
    }

    #[test]
    fn decodes_html_attribute_character_references() {
        let tags = parse_html_tags(
            "<a title=\"A&amp;B&#x2F;C&#47;D&quot;&nbsp;&copy;&reg;&hellip;&mdash;&notin;\">x</a>",
        );
        let expected = format!(
            "A&B/C/D\"{}{}{}{}{}{}",
            '\u{00a0}', '\u{00a9}', '\u{00ae}', '\u{2026}', '\u{2014}', '\u{2209}'
        );

        assert_eq!(Some(&expected), tags[0].attributes.get("title"));
    }

    #[test]
    fn decodes_numeric_and_legacy_html_character_references() {
        let tags = parse_html_tags(
            "<a title=\"&#0 &#xD800 &#128 &#x85 &#x41 &copy &notin &ampx &notit;\"></a><title>&notin &ampx &copyx &#x110000</title>",
        );
        let expected_attribute = format!(
            "{} {} {} {} A {} &notin &ampx &notit;",
            '\u{fffd}', '\u{fffd}', '\u{20ac}', '\u{2026}', '\u{00a9}'
        );
        let expected_title = format!("{}in &x {}x {}", '\u{00ac}', '\u{00a9}', '\u{fffd}');

        assert_eq!(Some(&expected_attribute), tags[0].attributes.get("title"));
        assert_eq!("title", tags[2].name);
        assert_eq!(expected_title, tags[2].text);
    }

    #[test]
    fn records_opening_and_closing_tags_for_native_cursors() {
        let tags = parse_html_tags("<section><p>Text</p></section>");

        assert_eq!(5, tags.len());
        assert_eq!("section", tags[0].name);
        assert!(!tags[0].closing);
        assert_eq!("p", tags[1].name);
        assert!(!tags[1].closing);
        assert_eq!("#text", tags[2].token_type);
        assert_eq!("Text", tags[2].text);
        assert_eq!("p", tags[3].name);
        assert!(tags[3].closing);
        assert_eq!("section", tags[4].name);
        assert!(tags[4].closing);
    }

    #[test]
    fn records_fragment_breadcrumbs_and_depth_for_processor_tokens() {
        let tags = parse_html_tags("<section><p><img></p></section>");

        assert_eq!(5, tags.len());
        assert_eq!(
            vec![
                "HTML".to_string(),
                "BODY".to_string(),
                "SECTION".to_string()
            ],
            tags[0].breadcrumbs
        );
        assert_eq!(3, tags[0].depth);
        assert_eq!(
            vec![
                "HTML".to_string(),
                "BODY".to_string(),
                "SECTION".to_string(),
                "P".to_string()
            ],
            tags[1].breadcrumbs
        );
        assert_eq!(4, tags[1].depth);
        assert_eq!(
            vec![
                "HTML".to_string(),
                "BODY".to_string(),
                "SECTION".to_string(),
                "P".to_string(),
                "IMG".to_string()
            ],
            tags[2].breadcrumbs
        );
        assert_eq!(5, tags[2].depth);
        assert_eq!(
            vec![
                "HTML".to_string(),
                "BODY".to_string(),
                "SECTION".to_string()
            ],
            tags[3].breadcrumbs
        );
        assert_eq!(3, tags[3].depth);
        assert_eq!(
            vec!["HTML".to_string(), "BODY".to_string()],
            tags[4].breadcrumbs
        );
        assert_eq!(2, tags[4].depth);
    }

    #[test]
    fn serializes_compact_html_token_summaries() {
        let tags = parse_html_tags("<section><p data-id=\"7\">Text</p></section>");

        assert_eq!(
            "t\x1fSECTION\x1f0\x1f3\x1fHTML\x1dBODY\x1dSECTION",
            html_token_compact_summary(&tags[0])
        );
        assert_eq!(
            "s\x1f#text\x1f0\x1f5\x1fHTML\x1dBODY\x1dSECTION\x1dP\x1d#text",
            html_token_compact_summary(&tags[2])
        );
        assert_eq!(
            "t\x1fP\x1f1\x1f3\x1fHTML\x1dBODY\x1dSECTION",
            html_token_compact_summary(&tags[3])
        );
    }

    #[test]
    fn records_text_and_comment_tokens() {
        let tags = parse_html_tags("<p>Hello<!--note--><em>World</em></p>");

        assert_eq!(7, tags.len());
        assert_eq!("#tag", tags[0].token_type);
        assert_eq!("#text", tags[1].token_type);
        assert_eq!("Hello", tags[1].text);
        assert_eq!(
            vec![
                "HTML".to_string(),
                "BODY".to_string(),
                "P".to_string(),
                "#text".to_string()
            ],
            tags[1].breadcrumbs
        );
        assert_eq!("#comment", tags[2].token_type);
        assert_eq!("note", tags[2].text);
        assert_eq!(
            Some("COMMENT_AS_HTML_COMMENT".to_string()),
            tags[2].comment_type
        );
        assert_eq!(Some("note".to_string()), tags[2].full_comment_text);
        assert_eq!(
            vec![
                "HTML".to_string(),
                "BODY".to_string(),
                "P".to_string(),
                "#comment".to_string()
            ],
            tags[2].breadcrumbs
        );
        assert_eq!("#text", tags[4].token_type);
        assert_eq!("World", tags[4].text);

        let tags = parse_html_tags("<!--note--!><p>x</p>");

        assert_eq!(4, tags.len());
        assert_eq!("#comment", tags[0].token_type);
        assert_eq!("note", tags[0].text);
        assert_eq!(
            Some("COMMENT_AS_HTML_COMMENT".to_string()),
            tags[0].comment_type
        );
        assert_eq!(Some("note".to_string()), tags[0].full_comment_text);
        assert_eq!("p", tags[1].name);

        let tags = parse_html_tags("<!--note-- <p>x</p>");
        assert!(tags.is_empty());
    }

    #[test]
    fn records_doctype_tokens_for_tag_processor_parity() {
        let tags = parse_html_tags("<!DOCTYPE html><p>Text</p>");

        assert_eq!(4, tags.len());
        assert_eq!("#doctype", tags[0].token_type);
        assert_eq!("html", tags[0].name);
        assert_eq!(" html", tags[0].text);
        assert_eq!(vec!["html".to_string()], tags[0].breadcrumbs);
        assert_eq!(1, tags[0].depth);
        assert_eq!("#tag", tags[1].token_type);
        assert_eq!("p", tags[1].name);
    }

    #[test]
    fn records_doctype_token_name_as_html_for_malformed_names() {
        for html in [
            "<!DOCTYPE><p>x</p>",
            "<!DOCTYPE svg><p>x</p>",
            "<!DOCTYPE 123><p>x</p>",
            "<!DOCTYPE html<p><p>x</p>",
        ] {
            let tags = parse_html_tags(html);

            assert_eq!("#doctype", tags[0].token_type);
            assert_eq!("html", tags[0].name);
            assert_eq!("p", tags[1].name);
        }
    }

    #[test]
    fn records_raw_text_contents_on_opening_tags() {
        let tags = parse_html_tags(
            "<script>if (a < b) { c(); }</script><iframe>A&amp;<b>C</b></iframe><noembed>D&amp;<i>E</i></noembed><noframes>F&amp;<u>G</u></noframes><xmp>H&amp;<q>I</q></xmp><p>x</p>",
        );

        assert_eq!(8, tags.len());
        assert_eq!("#tag", tags[0].token_type);
        assert_eq!("script", tags[0].name);
        assert_eq!("if (a < b) { c(); }", tags[0].text);
        assert_eq!(
            vec!["HTML".to_string(), "BODY".to_string(), "SCRIPT".to_string()],
            tags[0].breadcrumbs
        );
        assert_eq!("iframe", tags[1].name);
        assert_eq!("A&amp;<b>C</b>", tags[1].text);
        assert_eq!("noembed", tags[2].name);
        assert_eq!("D&amp;<i>E</i>", tags[2].text);
        assert_eq!("noframes", tags[3].name);
        assert_eq!("F&amp;<u>G</u>", tags[3].text);
        assert_eq!("xmp", tags[4].name);
        assert_eq!("H&amp;<q>I</q>", tags[4].text);
        assert_eq!("p", tags[5].name);
        assert!(!tags[5].closing);
        assert_eq!("#text", tags[6].token_type);
        assert_eq!("x", tags[6].text);
        assert_eq!("p", tags[7].name);
        assert!(tags[7].closing);
    }

    #[test]
    fn decodes_rcdata_text_contents_on_opening_tags() {
        let tags = parse_html_tags(
            "<title>A&amp;B&nbsp;&copy;</title><textarea>C&lt;D&hellip;</textarea>",
        );

        assert_eq!(2, tags.len());
        assert_eq!("title", tags[0].name);
        assert_eq!(format!("A&B{}{}", '\u{00a0}', '\u{00a9}'), tags[0].text);
        assert_eq!("textarea", tags[1].name);
        assert_eq!(format!("C<D{}", '\u{2026}'), tags[1].text);
    }

    #[test]
    fn records_processing_instruction_lookalike_comments() {
        let tags = parse_html_tags("<?pi.name data?><p>x</p>");

        assert_eq!(4, tags.len());
        assert_eq!("#comment", tags[0].token_type);
        assert_eq!("pi.name", tags[0].name);
        assert_eq!(" data", tags[0].text);
        assert_eq!(
            Some("COMMENT_AS_PI_NODE_LOOKALIKE".to_string()),
            tags[0].comment_type
        );
        assert_eq!(
            Some("?pi.name data?".to_string()),
            tags[0].full_comment_text
        );
        assert_eq!(
            vec![
                "HTML".to_string(),
                "BODY".to_string(),
                "#comment".to_string()
            ],
            tags[0].breadcrumbs
        );
        assert_eq!("p", tags[1].name);
    }

    #[test]
    fn records_invalid_processing_instruction_lookalikes_as_comments() {
        let tags = parse_html_tags("<?1 data?><?pi data><p>x</p>");

        assert_eq!(5, tags.len());
        assert_eq!("#comment", tags[0].token_type);
        assert_eq!("#comment", tags[0].name);
        assert_eq!("1 data?", tags[0].text);
        assert_eq!(
            Some("COMMENT_AS_INVALID_HTML".to_string()),
            tags[0].comment_type
        );
        assert_eq!(Some("?1 data?".to_string()), tags[0].full_comment_text);
        assert_eq!("#comment", tags[1].token_type);
        assert_eq!("#comment", tags[1].name);
        assert_eq!("pi data", tags[1].text);
        assert_eq!(
            Some("COMMENT_AS_INVALID_HTML".to_string()),
            tags[1].comment_type
        );
        assert_eq!(Some("?pi data".to_string()), tags[1].full_comment_text);
        assert_eq!("p", tags[2].name);
    }

    #[test]
    fn records_invalid_declarations_as_comments() {
        let tags = parse_html_tags("<!notdoctype><p>x</p>");

        assert_eq!(4, tags.len());
        assert_eq!("#comment", tags[0].token_type);
        assert_eq!("notdoctype", tags[0].text);
        assert_eq!(
            Some("COMMENT_AS_INVALID_HTML".to_string()),
            tags[0].comment_type
        );
        assert_eq!(Some("notdoctype".to_string()), tags[0].full_comment_text);
        assert_eq!(
            vec![
                "HTML".to_string(),
                "BODY".to_string(),
                "#comment".to_string()
            ],
            tags[0].breadcrumbs
        );
        assert_eq!("p", tags[1].name);
    }

    #[test]
    fn records_cdata_lookalike_comments() {
        let tags = parse_html_tags("<![CDATA[x]]><p>x</p>");

        assert_eq!(4, tags.len());
        assert_eq!("#comment", tags[0].token_type);
        assert_eq!("x", tags[0].text);
        assert_eq!(
            Some("COMMENT_AS_CDATA_LOOKALIKE".to_string()),
            tags[0].comment_type
        );
        assert_eq!(Some("[CDATA[x]]".to_string()), tags[0].full_comment_text);
        assert_eq!("p", tags[1].name);
    }

    #[test]
    fn records_presumptuous_tags_and_funky_closing_comments() {
        let tags = parse_html_tags("</></1></%bad><//></ p></_x></:x><p>x</p>");

        assert_eq!(10, tags.len());
        assert_eq!("#presumptuous-tag", tags[0].token_type);
        assert_eq!("", tags[0].text);
        assert_eq!(None, tags[0].full_comment_text);
        assert_eq!("#funky-comment", tags[1].token_type);
        assert_eq!("1", tags[1].text);
        assert_eq!(Some("1".to_string()), tags[1].full_comment_text);
        assert_eq!("#funky-comment", tags[2].token_type);
        assert_eq!("%bad", tags[2].text);
        assert_eq!(Some("%bad".to_string()), tags[2].full_comment_text);
        assert_eq!("#funky-comment", tags[3].token_type);
        assert_eq!("/", tags[3].text);
        assert_eq!(Some("/".to_string()), tags[3].full_comment_text);
        assert_eq!("#funky-comment", tags[4].token_type);
        assert_eq!(" p", tags[4].text);
        assert_eq!(Some(" p".to_string()), tags[4].full_comment_text);
        assert_eq!("#funky-comment", tags[5].token_type);
        assert_eq!("_x", tags[5].text);
        assert_eq!(Some("_x".to_string()), tags[5].full_comment_text);
        assert_eq!("#funky-comment", tags[6].token_type);
        assert_eq!(":x", tags[6].text);
        assert_eq!(Some(":x".to_string()), tags[6].full_comment_text);
        assert_eq!("p", tags[7].name);
    }

    #[test]
    fn records_invalid_opening_tags_as_text() {
        let tags = parse_html_tags("before &amp; <1><%bad><_x><:x><.x><-x>< p><a:b>x</a:b>");

        assert_eq!("#text", tags[0].token_type);
        assert_eq!("before & <1><%bad><_x><:x><.x><-x>< p>", tags[0].text);
        assert_eq!("#tag", tags[1].token_type);
        assert_eq!("a:b", tags[1].name);

        let adjacent_tags = parse_html_tags("<<p>x</p><");

        assert_eq!("#text", adjacent_tags[0].token_type);
        assert_eq!("<", adjacent_tags[0].text);
        assert_eq!("#tag", adjacent_tags[1].token_type);
        assert_eq!("p", adjacent_tags[1].name);
        assert_eq!("#text", adjacent_tags[2].token_type);
        assert_eq!("x", adjacent_tags[2].text);
        assert_eq!("#tag", adjacent_tags[3].token_type);
        assert!(adjacent_tags[3].closing);
        assert_eq!(4, adjacent_tags.len());

        let consecutive_tags = parse_html_tags("<< <<p>x</p>");

        assert_eq!("#text", consecutive_tags[0].token_type);
        assert_eq!("<< <", consecutive_tags[0].text);
        assert_eq!("#tag", consecutive_tags[1].token_type);
        assert_eq!("p", consecutive_tags[1].name);

        let incomplete_tags = parse_html_tags("x<y <");

        assert_eq!("#text", incomplete_tags[0].token_type);
        assert_eq!("x", incomplete_tags[0].text);
        assert_eq!(1, incomplete_tags.len());

        assert!(parse_html_tags("<").is_empty());
    }

    #[test]
    fn records_abruptly_closed_html_comments() {
        let tags = parse_html_tags("<!--><!---><p>x</p>");

        assert_eq!(5, tags.len());
        assert_eq!("#comment", tags[0].token_type);
        assert_eq!("", tags[0].text);
        assert_eq!(
            Some("COMMENT_AS_ABRUPTLY_CLOSED_COMMENT".to_string()),
            tags[0].comment_type
        );
        assert_eq!(Some("".to_string()), tags[0].full_comment_text);
        assert_eq!("#comment", tags[1].token_type);
        assert_eq!("", tags[1].text);
        assert_eq!(
            Some("COMMENT_AS_ABRUPTLY_CLOSED_COMMENT".to_string()),
            tags[1].comment_type
        );
        assert_eq!(Some("".to_string()), tags[1].full_comment_text);
        assert_eq!("p", tags[2].name);
    }
}
