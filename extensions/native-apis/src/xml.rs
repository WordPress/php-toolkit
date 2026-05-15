#![cfg_attr(not(feature = "php-extension"), allow(dead_code))]

use std::collections::{HashMap, HashSet};
use std::fmt::Write;
use std::rc::Rc;

#[cfg(feature = "php-extension")]
use ext_php_rs::prelude::*;
#[cfg(feature = "php-extension")]
use ext_php_rs::types::Zval;

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct XmlToken {
    pub start_offset: usize,
    pub name: String,
    pub token_type: String,
    pub namespace: Option<String>,
    pub local_name: String,
    pub closing: bool,
    pub empty_element: bool,
    pub attributes: HashMap<String, String>,
    pub attribute_order: Vec<String>,
    pub text: String,
    pub text_start: usize,
    pub text_end: usize,
    pub breadcrumbs: Vec<(String, String)>,
    pub depth: usize,
}

const XML_TEXT_RANGE_NONE: usize = usize::MAX;

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct XmlDocument {
    pub tokens: Vec<XmlToken>,
    pub error: Option<String>,
}

#[derive(Clone, Debug, PartialEq, Eq)]
struct XmlStreamState {
    offset: usize,
    stack: Vec<String>,
    context_stack_depth: usize,
    breadcrumb_stack: Vec<(String, String)>,
    namespace_stack: Vec<Rc<HashMap<String, String>>>,
    root_seen: bool,
    finished: bool,
}

impl XmlStreamState {
    fn new() -> Self {
        let mut root_namespaces = HashMap::new();
        root_namespaces.insert(
            "xml".to_string(),
            "http://www.w3.org/XML/1998/namespace".to_string(),
        );

        Self {
            offset: 0,
            stack: Vec::new(),
            context_stack_depth: 0,
            breadcrumb_stack: Vec::new(),
            namespace_stack: vec![Rc::new(root_namespaces)],
            root_seen: false,
            finished: false,
        }
    }
}

#[cfg(feature = "php-extension")]
#[derive(Clone)]
struct XmlBookmark {
    current: Option<usize>,
    stream: Option<XmlStreamState>,
    stream_reentrancy_base_state: Option<XmlStreamState>,
    current_stream_token: Option<XmlToken>,
    current_stream_token_start_state: Option<XmlStreamState>,
    exhausted: bool,
    expecting_more_input: bool,
    paused_at_incomplete_input: bool,
    last_error: Option<String>,
    pending_stream_error: Option<String>,
}

#[cfg(feature = "php-extension")]
fn encode_xml_native_cursor(state: &XmlStreamState) -> String {
    format!(
        "WP_NATIVE_XML_CURSOR_V2:{}|{}|{}|{}|{}|{}",
        state.stack.len(),
        if state.root_seen { "1" } else { "0" },
        if state.finished { "1" } else { "0" },
        encode_xml_cursor_string_list(&state.stack),
        encode_xml_cursor_breadcrumbs(&state.breadcrumb_stack),
        encode_xml_cursor_namespace_stack(&state.namespace_stack)
    )
}

#[cfg(feature = "php-extension")]
fn decode_xml_native_cursor(cursor: &str) -> Option<XmlStreamState> {
    let payload = cursor.strip_prefix("WP_NATIVE_XML_CURSOR_V2:")?;
    let parts: Vec<&str> = payload.split('|').collect();
    if parts.len() != 6 {
        return None;
    }

    let context_stack_depth = parts[0].parse::<usize>().ok()?;
    let root_seen = match parts[1] {
        "0" => false,
        "1" => true,
        _ => return None,
    };
    let finished = match parts[2] {
        "0" => false,
        "1" => true,
        _ => return None,
    };

    let mut state = XmlStreamState::new();
    state.offset = 0;
    state.context_stack_depth = context_stack_depth;
    state.root_seen = root_seen;
    state.finished = finished;
    state.stack = decode_xml_cursor_string_list(parts[3])?;
    state.breadcrumb_stack = decode_xml_cursor_breadcrumbs(parts[4])?;
    state.namespace_stack = decode_xml_cursor_namespace_stack(parts[5])?;
    if state.namespace_stack.is_empty() {
        state.namespace_stack = XmlStreamState::new().namespace_stack;
    }
    if state.context_stack_depth > state.stack.len() {
        return None;
    }

    Some(state)
}

#[cfg(feature = "php-extension")]
fn encode_xml_cursor_string_list(values: &[String]) -> String {
    values
        .iter()
        .map(|value| encode_xml_cursor_hex(value.as_bytes()))
        .collect::<Vec<String>>()
        .join(",")
}

#[cfg(feature = "php-extension")]
fn decode_xml_cursor_string_list(encoded: &str) -> Option<Vec<String>> {
    if encoded.is_empty() {
        return Some(Vec::new());
    }

    encoded.split(',').map(decode_xml_cursor_hex).collect()
}

#[cfg(feature = "php-extension")]
fn encode_xml_cursor_breadcrumbs(values: &[(String, String)]) -> String {
    values
        .iter()
        .map(|(namespace, local_name)| {
            format!(
                "{}={}",
                encode_xml_cursor_hex(namespace.as_bytes()),
                encode_xml_cursor_hex(local_name.as_bytes())
            )
        })
        .collect::<Vec<String>>()
        .join(",")
}

#[cfg(feature = "php-extension")]
fn decode_xml_cursor_breadcrumbs(encoded: &str) -> Option<Vec<(String, String)>> {
    if encoded.is_empty() {
        return Some(Vec::new());
    }

    encoded
        .split(',')
        .map(|entry| {
            let mut parts = entry.splitn(2, '=');
            let namespace = decode_xml_cursor_hex(parts.next()?)?;
            let local_name = decode_xml_cursor_hex(parts.next()?)?;
            Some((namespace, local_name))
        })
        .collect()
}

#[cfg(feature = "php-extension")]
fn encode_xml_cursor_namespace_stack(values: &[Rc<HashMap<String, String>>]) -> String {
    values
        .iter()
        .map(|frame| {
            frame
                .iter()
                .map(|(prefix, namespace)| {
                    format!(
                        "{}={}",
                        encode_xml_cursor_hex(prefix.as_bytes()),
                        encode_xml_cursor_hex(namespace.as_bytes())
                    )
                })
                .collect::<Vec<String>>()
                .join(",")
        })
        .collect::<Vec<String>>()
        .join(";")
}

#[cfg(feature = "php-extension")]
fn decode_xml_cursor_namespace_stack(encoded: &str) -> Option<Vec<Rc<HashMap<String, String>>>> {
    if encoded.is_empty() {
        return Some(Vec::new());
    }

    encoded
        .split(';')
        .map(|frame| {
            let mut namespaces = HashMap::new();
            if !frame.is_empty() {
                for entry in frame.split(',') {
                    let mut parts = entry.splitn(2, '=');
                    let prefix = decode_xml_cursor_hex(parts.next()?)?;
                    let namespace = decode_xml_cursor_hex(parts.next()?)?;
                    namespaces.insert(prefix, namespace);
                }
            }
            Some(Rc::new(namespaces))
        })
        .collect()
}

#[cfg(feature = "php-extension")]
fn encode_xml_cursor_hex(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut encoded = String::with_capacity(bytes.len() * 2);
    for byte in bytes {
        encoded.push(HEX[(byte >> 4) as usize] as char);
        encoded.push(HEX[(byte & 0x0f) as usize] as char);
    }
    encoded
}

#[cfg(feature = "php-extension")]
fn decode_xml_cursor_hex(encoded: &str) -> Option<String> {
    let bytes = encoded.as_bytes();
    if bytes.len() & 1 != 0 {
        return None;
    }

    let mut decoded = Vec::with_capacity(bytes.len() / 2);
    let mut cursor = 0;
    while cursor < bytes.len() {
        let high = xml_cursor_hex_value(bytes[cursor])?;
        let low = xml_cursor_hex_value(bytes[cursor + 1])?;
        decoded.push((high << 4) | low);
        cursor += 2;
    }

    String::from_utf8(decoded).ok()
}

#[cfg(feature = "php-extension")]
fn xml_cursor_hex_value(byte: u8) -> Option<u8> {
    match byte {
        b'0'..=b'9' => Some(byte - b'0'),
        b'a'..=b'f' => Some(byte - b'a' + 10),
        b'A'..=b'F' => Some(byte - b'A' + 10),
        _ => None,
    }
}

#[cfg(feature = "php-extension")]
#[php_class]
#[php(name = "WordPress\\XML\\NativeXMLProcessor")]
pub struct NativeXmlProcessor {
    source: String,
    document: Option<XmlDocument>,
    current: Option<usize>,
    stream: Option<XmlStreamState>,
    stream_reentrancy_base_state: Option<XmlStreamState>,
    current_stream_token: Option<XmlToken>,
    current_stream_token_start_state: Option<XmlStreamState>,
    exhausted: bool,
    expecting_more_input: bool,
    paused_at_incomplete_input: bool,
    last_error: Option<String>,
    pending_stream_error: Option<String>,
    bookmarks: HashMap<String, XmlBookmark>,
}

#[cfg(feature = "php-extension")]
#[php_impl]
#[php(change_method_case = "snake_case")]
impl NativeXmlProcessor {
    pub fn create_from_string(xml: String) -> Self {
        Self {
            source: xml,
            document: None,
            current: None,
            stream: None,
            stream_reentrancy_base_state: None,
            current_stream_token: None,
            current_stream_token_start_state: None,
            exhausted: false,
            expecting_more_input: false,
            paused_at_incomplete_input: false,
            last_error: None,
            pending_stream_error: None,
            bookmarks: HashMap::new(),
        }
    }

    pub fn create_for_streaming(
        xml: String,
        cursor: Option<String>,
        known_definite_encoding: String,
        _document_namespaces: &Zval,
    ) -> Option<Self> {
        if known_definite_encoding != "UTF-8" {
            return None;
        }

        let stream = if let Some(cursor) = cursor {
            if let Some(state) = decode_xml_native_cursor(&cursor) {
                Some(state)
            } else if cursor.starts_with("WP_NATIVE_XML_CURSOR_V1:") {
                None
            } else {
                return None;
            }
        } else {
            None
        };

        let mut processor = Self::create_from_string(xml);
        processor.stream_reentrancy_base_state =
            Some(stream.clone().unwrap_or_else(XmlStreamState::new));
        processor.stream = stream;
        processor.expecting_more_input = true;

        Some(processor)
    }

    pub fn get_reentrancy_cursor(&self) -> String {
        if let Some(state) = self.current_stream_token_start_state.as_ref() {
            return encode_xml_native_cursor(state);
        }

        if let Some(state) = self.current_stream_token_start_state() {
            return encode_xml_native_cursor(&state);
        }

        let offset = self
            .current_token()
            .map(|token| token.start_offset)
            .unwrap_or(0);

        format!("WP_NATIVE_XML_CURSOR_V1:{offset}")
    }

    pub fn next_token(&mut self) -> bool {
        if self.exhausted {
            return false;
        }

        if self.document.is_none() {
            return self.next_cursor_stream_token();
        }

        self.ensure_document();

        let next = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before token iteration");
        if next >= document.tokens.len() {
            self.current = None;
            self.current_stream_token = None;
            self.current_stream_token_start_state = None;
            self.exhausted = true;
            return false;
        }

        self.current = Some(next);
        self.current_stream_token = None;
        self.current_stream_token_start_state = None;
        true
    }

    pub fn next_token_metadata(&mut self) -> Option<String> {
        if !self.next_token() {
            return None;
        }

        self.current_token_metadata()
    }

    pub fn next_token_summary(&mut self) -> Option<String> {
        if !self.next_token() {
            return None;
        }

        self.current_token().map(xml_token_metadata)
    }

    pub fn next_token_compact_summary(&mut self) -> Option<String> {
        if !self.next_token() {
            return None;
        }

        self.current_token().map(xml_token_compact_summary)
    }

    pub fn next_token_compact_summary_batch(&mut self, max_tokens: i64) -> Option<String> {
        let limit = if max_tokens > 0 {
            max_tokens.min(1024) as usize
        } else {
            64
        };
        let mut summaries = String::new();
        let mut count = 0;

        if self.exhausted {
            return None;
        }

        if self.document.is_none() {
            if let Some(error) = self.pending_stream_error.take() {
                self.current_stream_token = None;
                self.last_error = Some(error);
                self.exhausted = true;
                return None;
            }

            if self.stream.is_none() {
                self.stream = Some(XmlStreamState::new());
            }

            let state = self
                .stream
                .as_mut()
                .expect("XML stream should be initialized before token iteration");
            while count < limit {
                match parse_next_xml_stream_token(&self.source, state) {
                    Ok(Some(token)) => {
                        self.paused_at_incomplete_input = false;
                        if count > 0 {
                            summaries.push('\x1e');
                        }
                        summaries.push_str(&xml_token_compact_summary(&token));
                        self.current_stream_token = Some(token);
                        count += 1;
                    }
                    Ok(None) => {
                        self.current_stream_token = None;
                        self.exhausted = true;
                        break;
                    }
                    Err(error) => {
                        self.current_stream_token = None;
                        if self.expecting_more_input && is_incomplete_xml_stream_error(&error) {
                            self.paused_at_incomplete_input = true;
                            break;
                        }

                        if summaries.is_empty() {
                            self.last_error = Some(error);
                            self.exhausted = true;
                        } else {
                            self.pending_stream_error = Some(error);
                        }
                        break;
                    }
                }
            }

            return if summaries.is_empty() {
                None
            } else {
                Some(summaries)
            };
        }

        while count < limit {
            if !self.next_token() {
                break;
            }

            if count > 0 {
                summaries.push('\x1e');
            }

            if let Some(token) = self.current_token() {
                summaries.push_str(&xml_token_compact_summary(token));
            }

            count += 1;
        }

        if summaries.is_empty() {
            None
        } else {
            Some(summaries)
        }
    }

    pub fn next_token_fast_compact_summary_batch(&mut self, max_tokens: i64) -> Option<String> {
        let limit = if max_tokens > 0 {
            max_tokens.min(1024) as usize
        } else {
            64
        };
        let mut summaries = String::new();
        let mut count = 0;

        if self.exhausted {
            return None;
        }

        if self.document.is_some() {
            return self.next_token_compact_summary_batch(max_tokens);
        }

        if let Some(error) = self.pending_stream_error.take() {
            self.current_stream_token = None;
            self.last_error = Some(error);
            self.exhausted = true;
            return None;
        }

        if self.stream.is_none() {
            self.stream = Some(XmlStreamState::new());
        }

        let state = self
            .stream
            .as_mut()
            .expect("XML stream should be initialized before compact token iteration");
        while count < limit {
            match parse_next_xml_stream_compact_summary(&self.source, state) {
                Ok(Some(summary)) => {
                    self.paused_at_incomplete_input = false;
                    if count > 0 {
                        summaries.push('\x1e');
                    }
                    summaries.push_str(&summary);
                    self.current_stream_token = None;
                    count += 1;
                }
                Ok(None) => {
                    self.current_stream_token = None;
                    self.exhausted = true;
                    break;
                }
                Err(error) => {
                    self.current_stream_token = None;
                    if self.expecting_more_input && is_incomplete_xml_stream_error(&error) {
                        self.paused_at_incomplete_input = true;
                        break;
                    }

                    if summaries.is_empty() {
                        self.last_error = Some(error);
                        self.exhausted = true;
                    } else {
                        self.pending_stream_error = Some(error);
                    }
                    break;
                }
            }
        }

        if summaries.is_empty() {
            None
        } else {
            Some(summaries)
        }
    }

    pub fn next_token_hot_compact_summary_batch(&mut self, max_tokens: i64) -> Option<String> {
        let limit = if max_tokens > 0 {
            max_tokens.min(1024) as usize
        } else {
            64
        };
        let mut summaries = String::new();
        let mut count = 0;

        if self.exhausted {
            return None;
        }

        if self.document.is_some() {
            while count < limit {
                if !self.next_token() {
                    break;
                }

                if count > 0 {
                    summaries.push('\x1e');
                }

                if let Some(token) = self.current_token() {
                    summaries.push_str(&xml_token_hot_compact_summary(token));
                }

                count += 1;
            }

            return if summaries.is_empty() {
                None
            } else {
                Some(summaries)
            };
        }

        if let Some(error) = self.pending_stream_error.take() {
            self.current_stream_token = None;
            self.last_error = Some(error);
            self.exhausted = true;
            return None;
        }

        if self.stream.is_none() {
            self.stream = Some(XmlStreamState::new());
        }

        let state = self
            .stream
            .as_mut()
            .expect("XML stream should be initialized before compact token iteration");
        while count < limit {
            match parse_next_xml_stream_hot_compact_summary(&self.source, state) {
                Ok(Some(summary)) => {
                    self.paused_at_incomplete_input = false;
                    if count > 0 {
                        summaries.push('\x1e');
                    }
                    summaries.push_str(&summary);
                    self.current_stream_token = None;
                    count += 1;
                }
                Ok(None) => {
                    self.current_stream_token = None;
                    self.exhausted = true;
                    break;
                }
                Err(error) => {
                    self.current_stream_token = None;
                    if self.expecting_more_input && is_incomplete_xml_stream_error(&error) {
                        self.paused_at_incomplete_input = true;
                        break;
                    }

                    if summaries.is_empty() {
                        self.last_error = Some(error);
                        self.exhausted = true;
                    } else {
                        self.pending_stream_error = Some(error);
                    }
                    break;
                }
            }
        }

        if summaries.is_empty() {
            None
        } else {
            Some(summaries)
        }
    }

    pub fn next_token_cursor_compact_summary_batch(&mut self, max_tokens: i64) -> Option<String> {
        let limit = if max_tokens > 0 {
            max_tokens.min(1024) as usize
        } else {
            64
        };
        let mut summaries = String::new();
        let mut count = 0;

        if self.exhausted {
            return None;
        }

        if self.document.is_some() {
            while count < limit {
                if !self.next_token() {
                    break;
                }

                if count > 0 {
                    summaries.push('\x1e');
                }

                if let Some(token) = self.current_token() {
                    summaries.push_str(&xml_token_cursor_compact_summary(token));
                }

                count += 1;
            }

            return if summaries.is_empty() {
                None
            } else {
                Some(summaries)
            };
        }

        if let Some(error) = self.pending_stream_error.take() {
            self.current_stream_token = None;
            self.last_error = Some(error);
            self.exhausted = true;
            return None;
        }

        if self.stream.is_none() {
            self.stream = Some(XmlStreamState::new());
        }

        let state = self
            .stream
            .as_mut()
            .expect("XML stream should be initialized before cursor compact token iteration");
        while count < limit {
            match parse_next_xml_stream_cursor_compact_summary(&self.source, state) {
                Ok(Some(summary)) => {
                    self.paused_at_incomplete_input = false;
                    if count > 0 {
                        summaries.push('\x1e');
                    }
                    summaries.push_str(&summary);
                    self.current_stream_token = None;
                    count += 1;
                }
                Ok(None) => {
                    self.current_stream_token = None;
                    self.exhausted = true;
                    break;
                }
                Err(error) => {
                    self.current_stream_token = None;
                    if self.expecting_more_input && is_incomplete_xml_stream_error(&error) {
                        self.paused_at_incomplete_input = true;
                        break;
                    }
                    if summaries.is_empty() {
                        self.last_error = Some(error);
                        self.exhausted = true;
                    } else {
                        self.pending_stream_error = Some(error);
                    }
                    break;
                }
            }
        }

        if summaries.is_empty() {
            None
        } else {
            Some(summaries)
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
            if let Some(token) = self.current_token() {
                rows.push(xml_token_public_summary_row(token));
            }
        }

        rows
    }

    pub fn next_tag_compact_summary_batch(
        &mut self,
        max_tags: i64,
        attribute_name: String,
    ) -> Option<String> {
        let limit = if max_tags > 0 {
            max_tags.min(256) as usize
        } else {
            64
        };
        let mut summaries = String::new();
        let mut tag_count = 0;
        let mut token_delta = 0;

        if self.exhausted {
            return None;
        }

        if self.document.is_none() {
            if let Some(error) = self.pending_stream_error.take() {
                self.current_stream_token = None;
                self.last_error = Some(error);
                self.exhausted = true;
                return None;
            }

            if self.stream.is_none() {
                self.stream = Some(XmlStreamState::new());
            }

            let state = self
                .stream
                .as_mut()
                .expect("XML stream should be initialized before tag iteration");
            while tag_count < limit {
                match parse_next_xml_stream_token(&self.source, state) {
                    Ok(Some(token)) => {
                        self.paused_at_incomplete_input = false;
                        token_delta += 1;

                        if token.token_type != "#tag" || token.closing {
                            self.current_stream_token = Some(token);
                            continue;
                        }

                        if tag_count > 0 {
                            summaries.push('\x1e');
                        }
                        summaries.push_str(&xml_tag_compact_summary(
                            &token,
                            token_delta,
                            &attribute_name,
                        ));
                        self.current_stream_token = Some(token);
                        tag_count += 1;
                    }
                    Ok(None) => {
                        self.current_stream_token = None;
                        self.exhausted = true;
                        break;
                    }
                    Err(error) => {
                        self.current_stream_token = None;
                        if self.expecting_more_input && is_incomplete_xml_stream_error(&error) {
                            self.paused_at_incomplete_input = true;
                            break;
                        }

                        if summaries.is_empty() {
                            self.last_error = Some(error);
                            self.exhausted = true;
                        } else {
                            self.pending_stream_error = Some(error);
                        }
                        break;
                    }
                }
            }

            return if summaries.is_empty() {
                None
            } else {
                Some(summaries)
            };
        }

        while tag_count < limit {
            let next = self.current.map_or(0, |index| index + 1);
            let document = self
                .document
                .as_ref()
                .expect("XML document should be parsed before tag iteration");
            if next >= document.tokens.len() {
                self.current = None;
                self.current_stream_token = None;
                self.exhausted = true;
                break;
            }

            self.current = Some(next);
            token_delta += 1;

            let token = &document.tokens[next];
            if token.token_type != "#tag" || token.closing {
                continue;
            }

            if tag_count > 0 {
                summaries.push('\x1e');
            }
            summaries.push_str(&xml_tag_compact_summary(
                token,
                token_delta,
                &attribute_name,
            ));
            tag_count += 1;
        }

        if summaries.is_empty() {
            None
        } else {
            Some(summaries)
        }
    }

    pub fn next_tag_summary_batch(
        &mut self,
        max_tags: i64,
        attribute_name: String,
    ) -> Vec<Vec<(String, Zval)>> {
        let limit = if max_tags > 0 {
            max_tags.min(256) as usize
        } else {
            return Vec::new();
        };
        let mut rows = Vec::new();

        while rows.len() < limit && self.next_token() {
            let Some(token) = self.current_token() else {
                continue;
            };

            if token.token_type != "#tag" || token.closing {
                continue;
            }

            rows.push(xml_tag_public_summary_row(token, &attribute_name));
        }

        rows
    }

    pub fn next_tag_count_batch(
        &mut self,
        max_tags: i64,
        attribute_name: String,
    ) -> Option<String> {
        let limit = if max_tags > 0 {
            max_tags.min(256) as usize
        } else {
            64
        };
        let tag_limit = limit as i64;
        let mut summary = XmlTokenStreamSummary {
            token_count: 0,
            tag_count: 0,
            attribute_count: 0,
        };

        if self.exhausted {
            return None;
        }

        if self.document.is_none() {
            if let Some(error) = self.pending_stream_error.take() {
                self.current_stream_token = None;
                self.last_error = Some(error);
                self.exhausted = true;
                return None;
            }

            if self.stream.is_none() {
                self.stream = Some(XmlStreamState::new());
            }

            let state = self
                .stream
                .as_mut()
                .expect("XML stream should be initialized before tag count iteration");
            while summary.tag_count < tag_limit {
                match parse_next_xml_stream_token(&self.source, state) {
                    Ok(Some(token)) => {
                        self.paused_at_incomplete_input = false;
                        summary.token_count += 1;

                        if token.token_type == "#tag" && !token.closing {
                            summary.tag_count += 1;
                            if token.attributes.contains_key(&attribute_name) {
                                summary.attribute_count += 1;
                            }
                        }

                        self.current_stream_token = Some(token);
                    }
                    Ok(None) => {
                        self.current_stream_token = None;
                        self.exhausted = true;
                        break;
                    }
                    Err(error) => {
                        self.current_stream_token = None;
                        if self.expecting_more_input && is_incomplete_xml_stream_error(&error) {
                            self.paused_at_incomplete_input = true;
                            break;
                        }

                        if summary.token_count == 0 {
                            self.last_error = Some(error);
                            self.exhausted = true;
                        } else {
                            self.pending_stream_error = Some(error);
                        }
                        break;
                    }
                }
            }

            return if summary.token_count == 0 {
                None
            } else {
                Some(xml_token_stream_summary(&summary))
            };
        }

        while summary.tag_count < tag_limit {
            let next = self.current.map_or(0, |index| index + 1);
            let document = self
                .document
                .as_ref()
                .expect("XML document should be parsed before tag count iteration");
            if next >= document.tokens.len() {
                self.current = None;
                self.current_stream_token = None;
                self.exhausted = true;
                break;
            }

            self.current = Some(next);
            let token = &document.tokens[next];
            summary.token_count += 1;

            if token.token_type != "#tag" || token.closing {
                continue;
            }

            summary.tag_count += 1;
            if token.attributes.contains_key(&attribute_name) {
                summary.attribute_count += 1;
            }
        }

        if summary.token_count == 0 {
            None
        } else {
            Some(xml_token_stream_summary(&summary))
        }
    }

    pub fn next_tag_count_compact_batch(
        &mut self,
        max_tags: i64,
        attribute_name: String,
    ) -> Option<String> {
        self.next_tag_count_batch(max_tags, attribute_name)
    }

    pub fn next_matching_tag_compact_summary_batch(
        &mut self,
        max_tags: i64,
        tag_namespace: String,
        tag_local_name: String,
        attribute_name: String,
    ) -> Option<String> {
        let limit = if max_tags > 0 {
            max_tags.min(256) as usize
        } else {
            64
        };
        let mut summaries = String::new();
        let mut tag_count = 0;
        let mut token_delta = 0;

        if self.exhausted {
            return None;
        }

        if self.document.is_none() {
            if let Some(error) = self.pending_stream_error.take() {
                self.current_stream_token = None;
                self.last_error = Some(error);
                self.exhausted = true;
                return None;
            }

            if self.stream.is_none() {
                self.stream = Some(XmlStreamState::new());
            }

            let state = self
                .stream
                .as_mut()
                .expect("XML stream should be initialized before matching tag iteration");
            while tag_count < limit {
                match parse_next_xml_stream_token(&self.source, state) {
                    Ok(Some(token)) => {
                        self.paused_at_incomplete_input = false;
                        token_delta += 1;

                        if !xml_token_matches_tag_name(&token, &tag_namespace, &tag_local_name) {
                            self.current_stream_token = Some(token);
                            continue;
                        }

                        if tag_count > 0 {
                            summaries.push('\x1e');
                        }
                        summaries.push_str(&xml_tag_compact_summary(
                            &token,
                            token_delta,
                            &attribute_name,
                        ));
                        self.current_stream_token = Some(token);
                        tag_count += 1;
                    }
                    Ok(None) => {
                        self.current_stream_token = None;
                        self.exhausted = true;
                        break;
                    }
                    Err(error) => {
                        self.current_stream_token = None;
                        if self.expecting_more_input && is_incomplete_xml_stream_error(&error) {
                            self.paused_at_incomplete_input = true;
                            break;
                        }

                        if summaries.is_empty() {
                            self.last_error = Some(error);
                            self.exhausted = true;
                        } else {
                            self.pending_stream_error = Some(error);
                        }
                        break;
                    }
                }
            }

            return if summaries.is_empty() {
                None
            } else {
                Some(summaries)
            };
        }

        while tag_count < limit {
            let next = self.current.map_or(0, |index| index + 1);
            let document = self
                .document
                .as_ref()
                .expect("XML document should be parsed before matching tag iteration");
            if next >= document.tokens.len() {
                self.current = None;
                self.current_stream_token = None;
                self.exhausted = true;
                break;
            }

            self.current = Some(next);
            token_delta += 1;

            let token = &document.tokens[next];
            if !xml_token_matches_tag_name(token, &tag_namespace, &tag_local_name) {
                continue;
            }

            if tag_count > 0 {
                summaries.push('\x1e');
            }
            summaries.push_str(&xml_tag_compact_summary(
                token,
                token_delta,
                &attribute_name,
            ));
            tag_count += 1;
        }

        if summaries.is_empty() {
            None
        } else {
            Some(summaries)
        }
    }

    pub fn next_matching_tag_summary_batch(
        &mut self,
        max_tags: i64,
        tag_namespace: String,
        tag_local_name: String,
        attribute_name: String,
    ) -> Vec<Vec<(String, Zval)>> {
        let limit = if max_tags > 0 && !tag_local_name.is_empty() {
            max_tags.min(256) as usize
        } else {
            return Vec::new();
        };
        let mut rows = Vec::new();

        while rows.len() < limit && self.next_token() {
            let Some(token) = self.current_token() else {
                continue;
            };

            if !xml_token_matches_tag_name(token, &tag_namespace, &tag_local_name) {
                continue;
            }

            rows.push(xml_tag_public_summary_row(token, &attribute_name));
        }

        rows
    }

    pub fn next_matching_tag_count_batch(
        &mut self,
        max_tags: i64,
        tag_namespace: String,
        tag_local_name: String,
        attribute_name: String,
    ) -> Option<String> {
        let limit = if max_tags > 0 {
            max_tags.min(256) as usize
        } else {
            64
        };
        let tag_limit = limit as i64;
        let mut summary = XmlTokenStreamSummary {
            token_count: 0,
            tag_count: 0,
            attribute_count: 0,
        };

        if self.exhausted {
            return None;
        }

        if self.document.is_none() {
            if let Some(error) = self.pending_stream_error.take() {
                self.current_stream_token = None;
                self.last_error = Some(error);
                self.exhausted = true;
                return None;
            }

            if self.stream.is_none() {
                self.stream = Some(XmlStreamState::new());
            }

            let state = self
                .stream
                .as_mut()
                .expect("XML stream should be initialized before matching tag count iteration");
            while summary.tag_count < tag_limit {
                match parse_next_xml_stream_token(&self.source, state) {
                    Ok(Some(token)) => {
                        self.paused_at_incomplete_input = false;
                        summary.token_count += 1;

                        if xml_token_matches_tag_name(&token, &tag_namespace, &tag_local_name) {
                            summary.tag_count += 1;
                            if token.attributes.contains_key(&attribute_name) {
                                summary.attribute_count += 1;
                            }
                        }

                        self.current_stream_token = Some(token);
                    }
                    Ok(None) => {
                        self.current_stream_token = None;
                        self.exhausted = true;
                        break;
                    }
                    Err(error) => {
                        self.current_stream_token = None;
                        if self.expecting_more_input && is_incomplete_xml_stream_error(&error) {
                            self.paused_at_incomplete_input = true;
                            break;
                        }

                        if summary.token_count == 0 {
                            self.last_error = Some(error);
                            self.exhausted = true;
                        } else {
                            self.pending_stream_error = Some(error);
                        }
                        break;
                    }
                }
            }

            return if summary.token_count == 0 {
                None
            } else {
                Some(xml_token_stream_summary(&summary))
            };
        }

        while summary.tag_count < tag_limit {
            let next = self.current.map_or(0, |index| index + 1);
            let document = self
                .document
                .as_ref()
                .expect("XML document should be parsed before matching tag count iteration");
            if next >= document.tokens.len() {
                self.current = None;
                self.current_stream_token = None;
                self.exhausted = true;
                break;
            }

            self.current = Some(next);
            let token = &document.tokens[next];
            summary.token_count += 1;

            if !xml_token_matches_tag_name(token, &tag_namespace, &tag_local_name) {
                continue;
            }

            summary.tag_count += 1;
            if token.attributes.contains_key(&attribute_name) {
                summary.attribute_count += 1;
            }
        }

        if summary.token_count == 0 {
            None
        } else {
            Some(xml_token_stream_summary(&summary))
        }
    }

    pub fn next_matching_tag_count_compact_batch(
        &mut self,
        max_tags: i64,
        tag_namespace: String,
        tag_local_name: String,
        attribute_name: String,
    ) -> Option<String> {
        self.next_matching_tag_count_batch(max_tags, tag_namespace, tag_local_name, attribute_name)
    }

    pub fn summarize_matching_tag_stream(
        &mut self,
        tag_namespace: String,
        tag_local_name: String,
        attribute_name: String,
    ) -> String {
        if self.exhausted || tag_local_name.is_empty() {
            return "0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlTokenStreamSummary {
                token_count: 0,
                tag_count: 0,
                attribute_count: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_matching_tag_stream_token(
                        token,
                        &tag_namespace,
                        &tag_local_name,
                        &attribute_name,
                        &mut summary,
                    );
                }
            }

            return xml_token_stream_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_matching_tag_stream(
                &self.source,
                &tag_namespace,
                &tag_local_name,
                &attribute_name,
            );

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_token_stream_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing matching tags");
        let summary = summarize_xml_matching_tag_stream(
            &document.tokens[start..],
            &tag_namespace,
            &tag_local_name,
            &attribute_name,
        );

        self.current = None;
        self.exhausted = true;

        xml_token_stream_summary(&summary)
    }

    pub fn summarize_matching_tag_attributes_stream(
        &mut self,
        tag_namespace: String,
        tag_local_name: String,
        attribute_names: String,
    ) -> String {
        if self.exhausted || tag_local_name.is_empty() {
            return "0\x1f0\x1f0".to_string();
        }

        let attribute_names = parse_compact_attribute_names(&attribute_names);

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlTokenStreamSummary {
                token_count: 0,
                tag_count: 0,
                attribute_count: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_matching_tag_attributes_stream_token(
                        token,
                        &tag_namespace,
                        &tag_local_name,
                        &attribute_names,
                        &mut summary,
                    );
                }
            }

            return xml_token_stream_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_matching_tag_attributes_stream(
                &self.source,
                &tag_namespace,
                &tag_local_name,
                &attribute_names,
            );

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_token_stream_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing matching tag attributes");
        let summary = summarize_xml_matching_tag_attributes_stream(
            &document.tokens[start..],
            &tag_namespace,
            &tag_local_name,
            &attribute_names,
        );

        self.current = None;
        self.exhausted = true;

        xml_token_stream_summary(&summary)
    }

    pub fn next_tag(&mut self) -> bool {
        while self.next_token() {
            if self.get_token_type().as_deref() == Some("#tag") && !self.is_tag_closer() {
                return true;
            }
        }

        false
    }

    pub fn get_token_name(&self) -> Option<String> {
        let token = self.current_token()?;

        Some(token.local_name.clone())
    }

    pub fn get_token_type(&self) -> Option<String> {
        let token = self.current_token()?;

        Some(token.token_type.clone())
    }

    pub fn is_tag_closer(&self) -> bool {
        self.current_token()
            .map(|token| token.closing)
            .unwrap_or(false)
    }

    pub fn is_empty_element(&self) -> bool {
        self.current_token()
            .map(|token| token.empty_element)
            .unwrap_or(false)
    }

    pub fn expects_closer(&self) -> bool {
        self.current_token()
            .map(|token| token.token_type == "#tag" && !token.closing && !token.empty_element)
            .unwrap_or(false)
    }

    pub fn is_tag_opener(&self) -> bool {
        self.expects_closer()
    }

    pub fn get_token_byte_offset_in_the_input_stream(&self) -> i64 {
        self.current_token()
            .map(|token| token.start_offset as i64)
            .unwrap_or(0)
    }

    pub fn get_attribute(
        &self,
        namespace_or_name: String,
        local_name: Option<String>,
    ) -> Option<String> {
        let token = self.current_token()?;

        if token.attributes.is_empty() {
            return None;
        }

        if token.token_type != "#tag" && token.token_type != "#xml-declaration" {
            return None;
        }

        let name = match local_name {
            Some(local_name) if namespace_or_name.is_empty() => local_name,
            Some(local_name) => format!("{{{}}}{}", namespace_or_name, local_name),
            None => namespace_or_name,
        };

        token.attributes.get(&name).cloned()
    }

    pub fn get_attribute_names_with_prefix(
        &self,
        full_namespace_prefix: Option<String>,
        local_name_prefix: String,
    ) -> Option<Vec<Vec<String>>> {
        self.current_token()
            .filter(|token| token.token_type == "#tag" && !token.closing)
            .map(|token| {
                let mut matches = Vec::new();
                for attribute_name in &token.attribute_order {
                    let (namespace, local_name) = split_resolved_attribute_name(attribute_name);
                    if !local_name.starts_with(&local_name_prefix) {
                        continue;
                    }

                    match &full_namespace_prefix {
                        Some(prefix) if namespace.starts_with(prefix) => {
                            matches.push(vec![namespace.to_string(), local_name.to_string()])
                        }
                        None if namespace.is_empty() => {
                            matches.push(vec![namespace.to_string(), local_name.to_string()])
                        }
                        _ => {}
                    }
                }
                matches
            })
    }

    pub fn summarize_attribute_names_with_prefix(
        &mut self,
        full_namespace_prefix: Option<String>,
        local_name_prefix: String,
    ) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlAttributePrefixSummary {
                token_count: 0,
                tag_count: 0,
                attribute_count: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_token_attribute_names_with_prefix(
                        token,
                        full_namespace_prefix.as_deref(),
                        &local_name_prefix,
                        &mut summary,
                    );
                }
            }

            return format!(
                "{}\x1f{}\x1f{}",
                summary.token_count, summary.tag_count, summary.attribute_count
            );
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_attribute_names_with_prefix(
                &self.source,
                full_namespace_prefix.as_deref(),
                &local_name_prefix,
            );

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return format!(
                "{}\x1f{}\x1f{}",
                scan.summary.token_count, scan.summary.tag_count, scan.summary.attribute_count
            );
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing remaining tokens");
        let summary = summarize_xml_attribute_names_with_prefix(
            &document.tokens[start..],
            full_namespace_prefix.as_deref(),
            &local_name_prefix,
        );

        self.current = None;
        self.exhausted = true;

        format!(
            "{}\x1f{}\x1f{}",
            summary.token_count, summary.tag_count, summary.attribute_count
        )
    }

    pub fn remove_attributes_with_prefix_from_document(
        &mut self,
        full_namespace_prefix: Option<String>,
        local_name_prefix: String,
    ) -> String {
        if self.exhausted {
            return format!("0\x1f0\x1f{}", self.source);
        }

        if self.current.is_some() || self.document.is_some() {
            self.ensure_document();
            self.current = None;
            self.exhausted = true;

            return format!("0\x1f0\x1f{}", self.source);
        }

        let scan = remove_xml_source_attributes_with_prefix(
            &self.source,
            full_namespace_prefix.as_deref(),
            &local_name_prefix,
        );

        self.last_error = scan.error;
        self.current = None;
        self.exhausted = true;

        format!(
            "{}\x1f{}\x1f{}",
            scan.summary.tag_count, scan.summary.attribute_count, scan.xml
        )
    }

    pub fn summarize_token_stream(&mut self, attribute_name: String) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlTokenStreamSummary {
                token_count: 0,
                tag_count: 0,
                attribute_count: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_token_stream_token(token, &attribute_name, &mut summary);
                }
            }

            return format!(
                "{}\x1f{}\x1f{}",
                summary.token_count, summary.tag_count, summary.attribute_count
            );
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_token_stream(&self.source, &attribute_name);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return format!(
                "{}\x1f{}\x1f{}",
                scan.summary.token_count, scan.summary.tag_count, scan.summary.attribute_count
            );
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing remaining tokens");
        let summary = summarize_xml_token_stream(&document.tokens[start..], &attribute_name);

        self.current = None;
        self.exhausted = true;

        format!(
            "{}\x1f{}\x1f{}",
            summary.token_count, summary.tag_count, summary.attribute_count
        )
    }

    pub fn summarize_document_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlDocumentInventorySummary {
                token_count: 0,
                tag_count: 0,
                closing_tag_count: 0,
                text_token_count: 0,
                comment_count: 0,
                cdata_count: 0,
                max_depth: 0,
                empty_element_count: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_document_inventory_token(token, &mut summary);
                }
            }

            return xml_document_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_document_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_document_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing document inventory");
        let summary = summarize_xml_document_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_document_inventory_summary(&summary)
    }

    pub fn summarize_element_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlElementInventorySummary {
                token_count: 0,
                tag_count: 0,
                closing_tag_count: 0,
                unique_tag_name_count: 0,
                duplicate_tag_name_count: 0,
                namespaced_tag_count: 0,
                empty_element_count: 0,
            };
            let mut tag_names = HashSet::new();

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_element_inventory_token(token, &mut summary, &mut tag_names);
                }
            }
            summary.unique_tag_name_count = tag_names.len() as i64;

            return xml_element_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_element_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_element_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing element inventory");
        let summary = summarize_xml_element_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_element_inventory_summary(&summary)
    }

    pub fn summarize_depth_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlDepthInventorySummary {
                token_count: 0,
                tag_count: 0,
                closing_tag_count: 0,
                empty_element_count: 0,
                root_level_tag_count: 0,
                nested_tag_count: 0,
                total_tag_depth: 0,
                max_depth: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_depth_inventory_token(token, &mut summary);
                }
            }

            return xml_depth_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_depth_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_depth_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing depth inventory");
        let summary = summarize_xml_depth_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_depth_inventory_summary(&summary)
    }

    pub fn summarize_attribute_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlAttributeInventorySummary {
                token_count: 0,
                tag_count: 0,
                attribute_count: 0,
                namespaced_attribute_count: 0,
                tags_with_attributes_count: 0,
                max_attribute_count: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_attribute_inventory_token(token, &mut summary);
                }
            }

            return xml_attribute_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_attribute_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_attribute_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing attribute inventory");
        let summary = summarize_xml_attribute_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_attribute_inventory_summary(&summary)
    }

    pub fn summarize_attribute_inventory_array(&mut self) -> Vec<(String, Zval)> {
        if self.exhausted {
            return xml_attribute_inventory_public_summary_row(&XmlAttributeInventorySummary {
                token_count: 0,
                tag_count: 0,
                attribute_count: 0,
                namespaced_attribute_count: 0,
                tags_with_attributes_count: 0,
                max_attribute_count: 0,
            });
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlAttributeInventorySummary {
                token_count: 0,
                tag_count: 0,
                attribute_count: 0,
                namespaced_attribute_count: 0,
                tags_with_attributes_count: 0,
                max_attribute_count: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_attribute_inventory_token(token, &mut summary);
                }
            }

            return xml_attribute_inventory_public_summary_row(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_attribute_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_attribute_inventory_public_summary_row(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing attribute inventory");
        let summary = summarize_xml_attribute_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_attribute_inventory_public_summary_row(&summary)
    }

    pub fn summarize_id_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlIdInventorySummary {
                token_count: 0,
                tag_count: 0,
                id_attribute_count: 0,
                unique_id_count: 0,
                duplicate_id_count: 0,
                id_value_bytes: 0,
            };
            let mut seen_ids = HashSet::new();
            let mut duplicate_ids = HashSet::new();

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_id_inventory_token(
                        token,
                        &mut summary,
                        &mut seen_ids,
                        &mut duplicate_ids,
                    );
                }
            }

            return xml_id_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_id_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_id_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing ID inventory");
        let summary = summarize_xml_id_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_id_inventory_summary(&summary)
    }

    pub fn summarize_id_inventory_array(&mut self) -> Vec<(String, Zval)> {
        if self.exhausted {
            return xml_id_inventory_public_summary_row(&XmlIdInventorySummary {
                token_count: 0,
                tag_count: 0,
                id_attribute_count: 0,
                unique_id_count: 0,
                duplicate_id_count: 0,
                id_value_bytes: 0,
            });
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlIdInventorySummary {
                token_count: 0,
                tag_count: 0,
                id_attribute_count: 0,
                unique_id_count: 0,
                duplicate_id_count: 0,
                id_value_bytes: 0,
            };
            let mut seen_ids = HashSet::new();
            let mut duplicate_ids = HashSet::new();

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_id_inventory_token(
                        token,
                        &mut summary,
                        &mut seen_ids,
                        &mut duplicate_ids,
                    );
                }
            }

            return xml_id_inventory_public_summary_row(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_id_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_id_inventory_public_summary_row(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing ID inventory");
        let summary = summarize_xml_id_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_id_inventory_public_summary_row(&summary)
    }

    pub fn summarize_namespace_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlNamespaceInventorySummary {
                token_count: 0,
                tag_count: 0,
                namespaced_tag_count: 0,
                attribute_count: 0,
                namespaced_attribute_count: 0,
                unique_namespace_count: 0,
            };
            let mut namespaces = HashSet::new();

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_namespace_inventory_token(token, &mut summary, &mut namespaces);
                }
            }
            summary.unique_namespace_count = namespaces.len() as i64;

            return xml_namespace_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_namespace_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_namespace_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing namespace inventory");
        let summary = summarize_xml_namespace_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_namespace_inventory_summary(&summary)
    }

    pub fn summarize_text_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlTextInventorySummary {
                token_count: 0,
                text_token_count: 0,
                cdata_count: 0,
                non_empty_text_count: 0,
                whitespace_text_count: 0,
                total_text_bytes: 0,
                max_text_bytes: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_text_inventory_token(token, &mut summary);
                }
            }

            return xml_text_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_text_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_text_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing text inventory");
        let summary = summarize_xml_text_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_text_inventory_summary(&summary)
    }

    pub fn summarize_processing_instruction_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlProcessingInstructionInventorySummary {
                token_count: 0,
                processing_instruction_count: 0,
                xml_declaration_count: 0,
                non_empty_instruction_count: 0,
                total_instruction_bytes: 0,
                max_instruction_bytes: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_processing_instruction_inventory_token(token, &mut summary);
                }
            }

            return xml_processing_instruction_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_processing_instruction_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_processing_instruction_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self.document.as_ref().expect(
            "XML document should be parsed before summarizing processing instruction inventory",
        );
        let summary = summarize_xml_processing_instruction_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_processing_instruction_inventory_summary(&summary)
    }

    pub fn summarize_comment_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlCommentInventorySummary {
                token_count: 0,
                comment_count: 0,
                non_empty_comment_count: 0,
                empty_comment_count: 0,
                total_comment_bytes: 0,
                max_comment_bytes: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_comment_inventory_token(token, &mut summary);
                }
            }

            return xml_comment_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_comment_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_comment_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing comment inventory");
        let summary = summarize_xml_comment_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_comment_inventory_summary(&summary)
    }

    pub fn summarize_payload_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlPayloadInventorySummary {
                token_count: 0,
                text_token_count: 0,
                cdata_count: 0,
                comment_count: 0,
                processing_instruction_count: 0,
                total_payload_bytes: 0,
                max_payload_bytes: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_payload_inventory_token(token, &mut summary);
                }
            }

            return xml_payload_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_payload_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_payload_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing payload inventory");
        let summary = summarize_xml_payload_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_payload_inventory_summary(&summary)
    }

    pub fn summarize_content_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlContentInventorySummary {
                token_count: 0,
                tag_count: 0,
                attribute_count: 0,
                text_token_count: 0,
                cdata_count: 0,
                comment_count: 0,
                processing_instruction_count: 0,
                total_attribute_value_bytes: 0,
                max_attribute_value_bytes: 0,
                total_payload_bytes: 0,
                max_payload_bytes: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_content_inventory_token(token, &mut summary);
                }
            }

            return xml_content_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_content_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_content_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing content inventory");
        let summary = summarize_xml_content_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_content_inventory_summary(&summary)
    }

    pub fn summarize_leaf_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlLeafInventorySummary {
                token_count: 0,
                tag_count: 0,
                closing_tag_count: 0,
                empty_element_count: 0,
                leaf_element_count: 0,
                branch_element_count: 0,
                max_child_element_count: 0,
            };
            let mut open_child_counts = Vec::new();

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_leaf_inventory_token(token, &mut summary, &mut open_child_counts);
                }
            }

            return xml_leaf_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_leaf_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_leaf_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing leaf inventory");
        let summary = summarize_xml_leaf_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_leaf_inventory_summary(&summary)
    }

    pub fn summarize_structural_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0"
                .to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = empty_xml_structural_inventory_summary();
            let mut tag_names = HashSet::new();
            let mut open_child_counts = Vec::new();

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_structural_inventory_token(
                        token,
                        &mut summary,
                        &mut tag_names,
                        &mut open_child_counts,
                    );
                }
            }
            summary.unique_tag_name_count = tag_names.len() as i64;

            return xml_structural_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_structural_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_structural_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing structural inventory");
        let summary = summarize_xml_structural_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_structural_inventory_summary(&summary)
    }

    pub fn summarize_import_inventory(&mut self) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0\x1f0"
                .to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = empty_xml_import_inventory_summary();
            let mut tag_names = HashSet::new();
            let mut open_child_counts = Vec::new();

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_import_inventory_token(
                        token,
                        &mut summary,
                        &mut tag_names,
                        &mut open_child_counts,
                    );
                }
            }
            summary.structural.unique_tag_name_count = tag_names.len() as i64;

            return xml_import_inventory_summary(&summary);
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_import_inventory(&self.source);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return xml_import_inventory_summary(&scan.summary);
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing import inventory");
        let summary = summarize_xml_import_inventory(&document.tokens[start..]);

        self.current = None;
        self.exhausted = true;

        xml_import_inventory_summary(&summary)
    }

    pub fn summarize_tag_stream(&mut self, attribute_name: String) -> String {
        if self.exhausted {
            return "0\x1f0\x1f0".to_string();
        }

        if self.document.is_none() && self.stream.is_some() {
            let mut summary = XmlTokenStreamSummary {
                token_count: 0,
                tag_count: 0,
                attribute_count: 0,
            };

            while self.next_stream_token() {
                if let Some(token) = self.current_stream_token.as_ref() {
                    summarize_xml_tag_stream_token(token, &attribute_name, &mut summary);
                }
            }

            return format!(
                "{}\x1f{}\x1f{}",
                summary.token_count, summary.tag_count, summary.attribute_count
            );
        }

        if self.current.is_none() && self.document.is_none() {
            let scan = summarize_xml_source_tag_stream(&self.source, &attribute_name);

            self.last_error = scan.error;
            self.current = None;
            self.exhausted = true;

            return format!(
                "{}\x1f{}\x1f{}",
                scan.summary.token_count, scan.summary.tag_count, scan.summary.attribute_count
            );
        }

        self.ensure_document();

        let start = self.current.map_or(0, |index| index + 1);
        let document = self
            .document
            .as_ref()
            .expect("XML document should be parsed before summarizing remaining tags");
        let summary = summarize_xml_tag_stream(&document.tokens[start..], &attribute_name);

        self.current = None;
        self.exhausted = true;

        format!(
            "{}\x1f{}\x1f{}",
            summary.token_count, summary.tag_count, summary.attribute_count
        )
    }

    pub fn get_tag_local_name(&self) -> Option<String> {
        let token = self.current_token()?;

        if token.token_type == "#tag" {
            Some(token.local_name.clone())
        } else {
            None
        }
    }

    pub fn get_tag_namespace(&self) -> Option<String> {
        self.current_token().and_then(|token| {
            if token.token_type == "#tag" {
                Some(token.namespace.clone().unwrap_or_default())
            } else {
                None
            }
        })
    }

    pub fn get_tag_namespace_and_local_name(&self) -> Option<String> {
        self.current_token().and_then(|token| {
            if token.token_type == "#tag" {
                Some(match &token.namespace {
                    Some(namespace) if !namespace.is_empty() => {
                        format!("{{{}}}{}", namespace, token.local_name)
                    }
                    _ => token.local_name.clone(),
                })
            } else {
                None
            }
        })
    }

    pub fn get_modifiable_text(&self) -> String {
        self.current_token()
            .map(|token| {
                if token.token_type == "#tag" && !token.closing && !token.empty_element {
                    return xml_tag_modifiable_text(
                        self.source.as_bytes(),
                        xml_tag_content_start(self.source.as_bytes(), token.start_offset),
                        &token.name,
                    );
                }

                if token.text.is_empty() && token.text_start != XML_TEXT_RANGE_NONE {
                    let bytes = self.source.as_bytes();
                    return if token.token_type == "#text" {
                        decode_xml_text_bytes(&bytes[token.text_start..token.text_end])
                    } else {
                        normalize_xml_text(&String::from_utf8_lossy(
                            &bytes[token.text_start..token.text_end],
                        ))
                    };
                }

                token.text.clone()
            })
            .unwrap_or_default()
    }

    pub fn get_doctype_name(&self) -> Option<String> {
        self.current_token().and_then(|token| {
            if token.token_type != "#doctype" {
                return None;
            }

            parse_doctype_parts(self.source.as_bytes(), token.start_offset).0
        })
    }

    pub fn get_system_literal(&self) -> Option<String> {
        self.current_token().and_then(|token| {
            if token.token_type != "#doctype" {
                return None;
            }

            parse_doctype_parts(self.source.as_bytes(), token.start_offset).1
        })
    }

    pub fn get_pubid_literal(&self) -> Option<String> {
        self.current_token().and_then(|token| {
            if token.token_type != "#doctype" {
                return None;
            }

            parse_doctype_parts(self.source.as_bytes(), token.start_offset).2
        })
    }

    pub fn set_modifiable_text(&mut self, new_value: String) -> bool {
        let token = match self.current_token() {
            Some(token)
                if (token.token_type == "#text"
                    || token.token_type == "#comment"
                    || token.token_type == "#cdata-section")
                    && token.text_start != XML_TEXT_RANGE_NONE =>
            {
                token.clone()
            }
            _ => return false,
        };
        if self.document.is_some() {
            return false;
        }

        let replacement = if token.token_type == "#cdata-section" {
            new_value.replace("]]>", "]]&gt;")
        } else {
            escape_xml_text_value(&new_value)
        };
        let replacement_len = replacement.len();
        self.apply_source_edit(token.text_start, token.text_end, &replacement);
        if let Some(current_token) = self.current_stream_token.as_mut() {
            current_token.text = new_value;
            current_token.text_end = current_token.text_start + replacement_len;
        }

        true
    }

    pub fn set_attribute(
        &mut self,
        xml_namespace: String,
        local_name: String,
        value: String,
    ) -> bool {
        if xml_namespace == "xmlns" || !is_xml_unprefixed_name(&local_name) {
            return false;
        }

        let token = match self.current_token() {
            Some(token) if token.token_type == "#tag" && !token.closing => token.clone(),
            _ => return false,
        };
        if self.document.is_some() {
            return false;
        }

        let name_end = match xml_tag_name_end_at(self.source.as_bytes(), token.start_offset) {
            Some(name_end) => name_end,
            None => return false,
        };
        let tag_attributes =
            match parse_xml_source_tag_attributes(self.source.as_bytes(), name_end, false) {
                Ok(tag_attributes) => tag_attributes,
                Err(_) => return false,
            };

        let attribute_name = match self.current_source_attribute_name(
            &xml_namespace,
            &local_name,
            &tag_attributes,
        ) {
            Some(attribute_name) => attribute_name,
            None => return false,
        };
        let resolved_attribute_name = if xml_namespace.is_empty() {
            local_name.clone()
        } else {
            format!("{{{}}}{}", xml_namespace, local_name)
        };
        let serialized_attribute = format!(
            "{}=\"{}\"",
            attribute_name,
            escape_xml_attribute_value(&value)
        );
        for attribute in &tag_attributes.attributes {
            if self.source_attribute_resolves_to(
                &attribute.name,
                &xml_namespace,
                &local_name,
                &tag_attributes,
            ) {
                self.apply_source_edit(attribute.start, attribute.end, &serialized_attribute);
                self.update_current_attribute(resolved_attribute_name, Some(value));
                return true;
            }
        }

        self.apply_source_edit(name_end, name_end, &format!(" {}", serialized_attribute));
        self.update_current_attribute(resolved_attribute_name, Some(value));
        true
    }

    pub fn remove_attribute(&mut self, xml_namespace: String, local_name: String) -> bool {
        if xml_namespace == "xmlns" || !is_xml_unprefixed_name(&local_name) {
            return false;
        }

        let token = match self.current_token() {
            Some(token) if token.token_type == "#tag" && !token.closing => token.clone(),
            _ => return false,
        };
        if self.document.is_some() {
            return false;
        }

        let name_end = match xml_tag_name_end_at(self.source.as_bytes(), token.start_offset) {
            Some(name_end) => name_end,
            None => return false,
        };
        let tag_attributes =
            match parse_xml_source_tag_attributes(self.source.as_bytes(), name_end, false) {
                Ok(tag_attributes) => tag_attributes,
                Err(_) => return false,
            };

        let resolved_attribute_name = if xml_namespace.is_empty() {
            local_name
        } else {
            format!("{{{}}}{}", xml_namespace, local_name)
        };
        for attribute in &tag_attributes.attributes {
            if self.source_attribute_resolves_to(
                &attribute.name,
                &xml_namespace,
                split_resolved_attribute_name(&resolved_attribute_name).1,
                &tag_attributes,
            ) {
                self.apply_source_edit(attribute.start, attribute.end, "");
                self.update_current_attribute(resolved_attribute_name, None);
                return true;
            }
        }

        false
    }

    pub fn get_updated_xml(&self) -> String {
        self.source.clone()
    }

    #[php(name = "__toString")]
    pub fn __to_string(&self) -> String {
        self.get_updated_xml()
    }

    pub fn set_bookmark(&mut self, name: String) -> bool {
        if self.current_token().is_none() {
            return false;
        }

        self.bookmarks.insert(
            name,
            XmlBookmark {
                current: self.current,
                stream: self.stream.clone(),
                stream_reentrancy_base_state: self.stream_reentrancy_base_state.clone(),
                current_stream_token: self.current_stream_token.clone(),
                current_stream_token_start_state: self.current_stream_token_start_state.clone(),
                exhausted: self.exhausted,
                expecting_more_input: self.expecting_more_input,
                paused_at_incomplete_input: self.paused_at_incomplete_input,
                last_error: self.last_error.clone(),
                pending_stream_error: self.pending_stream_error.clone(),
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

        self.current = bookmark.current;
        self.stream = bookmark.stream;
        self.stream_reentrancy_base_state = bookmark.stream_reentrancy_base_state;
        self.current_stream_token = bookmark.current_stream_token;
        self.current_stream_token_start_state = bookmark.current_stream_token_start_state;
        self.exhausted = bookmark.exhausted;
        self.expecting_more_input = bookmark.expecting_more_input;
        self.paused_at_incomplete_input = bookmark.paused_at_incomplete_input;
        self.last_error = bookmark.last_error;
        self.pending_stream_error = bookmark.pending_stream_error;

        true
    }

    pub fn get_breadcrumbs(&self) -> Vec<Vec<String>> {
        self.current_token_breadcrumbs()
            .map(|breadcrumbs| {
                breadcrumbs
                    .into_iter()
                    .map(|(namespace, local_name)| vec![namespace, local_name])
                    .collect()
            })
            .unwrap_or_default()
    }

    pub fn matches_breadcrumbs(&self, breadcrumbs: Vec<String>) -> bool {
        if breadcrumbs.is_empty() {
            return true;
        }

        match self.current_token() {
            Some(token) if token.token_type == "#tag" => {}
            _ => return false,
        }

        let token_breadcrumbs = match self.current_token_breadcrumbs() {
            Some(token_breadcrumbs) => token_breadcrumbs,
            None => return false,
        };

        if breadcrumbs.len() > token_breadcrumbs.len() {
            return false;
        }

        let offset = token_breadcrumbs.len() - breadcrumbs.len();
        for (index, breadcrumb) in breadcrumbs.iter().enumerate() {
            let (_, local_name) = &token_breadcrumbs[offset + index];
            if breadcrumb != "*" && breadcrumb != local_name {
                return false;
            }
        }

        true
    }

    pub fn get_current_depth(&self) -> i64 {
        self.current_token()
            .map(|token| token.depth as i64)
            .unwrap_or(0)
    }

    pub fn is_finished(&self) -> bool {
        self.exhausted
            && !self.expecting_more_input
            && self.last_error.is_none()
            && self
                .document
                .as_ref()
                .map(|document| document.error.is_none())
                .unwrap_or(true)
    }

    pub fn input_finished(&mut self) {
        self.expecting_more_input = false;
        if self.paused_at_incomplete_input {
            self.paused_at_incomplete_input = false;
            self.exhausted = false;
            if let Some(stream) = self.stream.as_mut() {
                stream.finished = false;
            }
        }
    }

    pub fn append_bytes(&mut self, next_chunk: String) -> bool {
        if !self.expecting_more_input {
            return false;
        }

        self.source.push_str(&next_chunk);
        self.exhausted = false;
        self.paused_at_incomplete_input = false;
        self.current_stream_token = None;
        self.current_stream_token_start_state = None;
        self.last_error = None;
        self.pending_stream_error = None;
        if let Some(stream) = self.stream.as_mut() {
            stream.finished = false;
        }

        true
    }

    pub fn is_expecting_more_input(&self) -> bool {
        self.expecting_more_input
    }

    pub fn is_paused_at_incomplete_input(&self) -> bool {
        self.paused_at_incomplete_input
    }

    pub fn get_last_error(&self) -> Option<String> {
        if self.paused_at_incomplete_input {
            return None;
        }

        if self.exhausted && self.document.is_none() {
            return self.last_error.clone();
        }

        if self.stream.is_some() {
            return self.last_error.clone();
        }

        self.document
            .as_ref()
            .map(|document| document.error.clone())
            .unwrap_or_else(|| parse_xml_document(&self.source).error)
    }

    pub fn get_exception(&self) -> Option<String> {
        self.get_last_error()
    }

    pub fn current_token_metadata(&self) -> Option<String> {
        self.current_token().map(xml_token_metadata)
    }
}

#[cfg(feature = "php-extension")]
impl NativeXmlProcessor {
    fn ensure_document(&mut self) {
        if self.document.is_none() {
            let document = parse_xml_document(&self.source);
            self.last_error = document.error.clone();
            self.document = Some(document);
            self.stream = None;
            self.stream_reentrancy_base_state = None;
            self.current_stream_token = None;
            self.current_stream_token_start_state = None;
        }
    }

    fn next_cursor_stream_token(&mut self) -> bool {
        if let Some(error) = self.pending_stream_error.take() {
            self.current_stream_token = None;
            self.current_stream_token_start_state = None;
            self.last_error = Some(error);
            self.exhausted = true;
            return false;
        }

        if self.stream.is_none() {
            self.stream = Some(XmlStreamState::new());
        }

        let state = self
            .stream
            .as_mut()
            .expect("XML stream should be initialized before token iteration");
        match parse_next_xml_stream_token_with_payloads(&self.source, state, false, false) {
            Ok(Some(token)) => {
                self.current_stream_token = Some(token);
                self.current_stream_token_start_state = None;
                self.paused_at_incomplete_input = false;
                true
            }
            Ok(None) => {
                self.current_stream_token = None;
                self.current_stream_token_start_state = None;
                self.exhausted = true;
                false
            }
            Err(error) if self.expecting_more_input && is_incomplete_xml_stream_error(&error) => {
                self.current_stream_token = None;
                self.current_stream_token_start_state = None;
                self.paused_at_incomplete_input = true;
                false
            }
            Err(error) => {
                self.current_stream_token = None;
                self.current_stream_token_start_state = None;
                self.last_error = Some(error);
                self.exhausted = true;
                false
            }
        }
    }

    fn next_stream_token(&mut self) -> bool {
        if let Some(error) = self.pending_stream_error.take() {
            self.current_stream_token = None;
            self.current_stream_token_start_state = None;
            self.last_error = Some(error);
            self.exhausted = true;
            return false;
        }

        if self.stream.is_none() {
            self.stream = Some(XmlStreamState::new());
        }

        let state = self
            .stream
            .as_mut()
            .expect("XML stream should be initialized before token iteration");
        match parse_next_xml_stream_token(&self.source, state) {
            Ok(Some(token)) => {
                self.current_stream_token = Some(token);
                self.current_stream_token_start_state = None;
                self.paused_at_incomplete_input = false;
                true
            }
            Ok(None) => {
                self.current_stream_token = None;
                self.current_stream_token_start_state = None;
                self.exhausted = true;
                false
            }
            Err(error) if self.expecting_more_input && is_incomplete_xml_stream_error(&error) => {
                self.current_stream_token = None;
                self.current_stream_token_start_state = None;
                self.paused_at_incomplete_input = true;
                false
            }
            Err(error) => {
                self.current_stream_token = None;
                self.current_stream_token_start_state = None;
                self.last_error = Some(error);
                self.exhausted = true;
                false
            }
        }
    }

    fn current_token(&self) -> Option<&XmlToken> {
        if let Some(token) = self.current_stream_token.as_ref() {
            return Some(token);
        }

        self.current.and_then(|index| {
            self.document
                .as_ref()
                .and_then(|document| document.tokens.get(index))
        })
    }

    fn current_token_breadcrumbs(&self) -> Option<Vec<(String, String)>> {
        let token = self.current_token()?;
        if !token.breadcrumbs.is_empty() || token.depth == 0 {
            return Some(token.breadcrumbs.clone());
        }

        if self.current_stream_token.is_none() {
            return Some(token.breadcrumbs.clone());
        }

        let mut state = self.current_stream_token_start_state()?;
        match parse_next_xml_stream_token(&self.source, &mut state) {
            Ok(Some(materialized))
                if materialized.start_offset == token.start_offset
                    && materialized.token_type == token.token_type
                    && materialized.name == token.name
                    && materialized.closing == token.closing =>
            {
                Some(materialized.breadcrumbs)
            }
            _ => Some(token.breadcrumbs.clone()),
        }
    }

    fn current_stream_token_start_state(&self) -> Option<XmlStreamState> {
        let target = self.current_stream_token.as_ref()?;
        let mut state = self
            .stream_reentrancy_base_state
            .clone()
            .unwrap_or_else(XmlStreamState::new);

        loop {
            let token_start_state = state.clone();
            match parse_next_xml_stream_token(&self.source, &mut state) {
                Ok(Some(token)) => {
                    if token.start_offset == target.start_offset
                        && token.token_type == target.token_type
                        && token.name == target.name
                        && token.closing == target.closing
                    {
                        return Some(token_start_state);
                    }

                    if token.start_offset > target.start_offset {
                        return None;
                    }
                }
                Ok(None) | Err(_) => return None,
            }
        }
    }

    fn apply_source_edit(&mut self, start: usize, end: usize, replacement: &str) {
        let removed = end.saturating_sub(start);
        let inserted = replacement.len();
        self.source.replace_range(start..end, replacement);
        self.adjust_offsets_after_source_edit(start, removed, inserted);
    }

    fn adjust_offsets_after_source_edit(&mut self, start: usize, removed: usize, inserted: usize) {
        fn adjust(offset: &mut usize, start: usize, removed: usize, inserted: usize) {
            if *offset >= start + removed {
                if inserted >= removed {
                    *offset += inserted - removed;
                } else {
                    *offset = offset.saturating_sub(removed - inserted);
                }
            }
        }

        if let Some(state) = self.stream.as_mut() {
            adjust(&mut state.offset, start, removed, inserted);
        }
        if let Some(state) = self.stream_reentrancy_base_state.as_mut() {
            adjust(&mut state.offset, start, removed, inserted);
        }
        if let Some(state) = self.current_stream_token_start_state.as_mut() {
            adjust(&mut state.offset, start, removed, inserted);
        }
        for bookmark in self.bookmarks.values_mut() {
            if let Some(state) = bookmark.stream.as_mut() {
                adjust(&mut state.offset, start, removed, inserted);
            }
            if let Some(state) = bookmark.stream_reentrancy_base_state.as_mut() {
                adjust(&mut state.offset, start, removed, inserted);
            }
            if let Some(state) = bookmark.current_stream_token_start_state.as_mut() {
                adjust(&mut state.offset, start, removed, inserted);
            }
        }
    }

    fn update_current_attribute(&mut self, name: String, value: Option<String>) {
        if let Some(token) = self.current_stream_token.as_mut() {
            match value {
                Some(value) => {
                    if !token.attributes.contains_key(&name) {
                        token.attribute_order.push(name.clone());
                    }
                    token.attributes.insert(name, value);
                }
                None => {
                    token.attributes.remove(&name);
                    token
                        .attribute_order
                        .retain(|attribute_name| attribute_name != &name);
                }
            }
        }
    }

    fn current_source_attribute_name(
        &self,
        xml_namespace: &str,
        local_name: &str,
        tag_attributes: &XmlSourceTagAttributes,
    ) -> Option<String> {
        if xml_namespace.is_empty() {
            return Some(local_name.to_string());
        }

        let namespaces = self.current_source_tag_namespaces(tag_attributes)?;
        for (prefix, namespace) in namespaces.iter() {
            if namespace == xml_namespace && !prefix.is_empty() {
                return Some(format!("{}:{}", prefix, local_name));
            }
        }

        None
    }

    fn source_attribute_resolves_to(
        &self,
        attribute_name: &str,
        xml_namespace: &str,
        local_name: &str,
        tag_attributes: &XmlSourceTagAttributes,
    ) -> bool {
        let (prefix, attribute_local_name) = split_qualified_name(attribute_name);
        if attribute_local_name != local_name {
            return false;
        }

        match prefix {
            None => xml_namespace.is_empty(),
            Some("xmlns") => false,
            Some(prefix) => self
                .current_source_tag_namespaces(tag_attributes)
                .and_then(|namespaces| namespaces.get(prefix).cloned())
                .map(|namespace| namespace == xml_namespace)
                .unwrap_or(false),
        }
    }

    fn current_source_tag_namespaces(
        &self,
        tag_attributes: &XmlSourceTagAttributes,
    ) -> Option<HashMap<String, String>> {
        let state = self.current_stream_token_start_state()?;
        let current_namespaces = state
            .namespace_stack
            .last()
            .cloned()
            .unwrap_or_else(|| Rc::new(HashMap::new()));
        let namespace_declarations =
            resolve_xml_source_namespace_declarations(&tag_attributes.namespace_declarations)
                .ok()?;

        if namespace_declarations.is_empty() {
            return Some((*current_namespaces).clone());
        }

        Some((*extend_xml_namespaces(&current_namespaces, namespace_declarations)).clone())
    }
}

#[cfg(feature = "php-extension")]
fn is_incomplete_xml_stream_error(error: &str) -> bool {
    matches!(
        error,
        "Unexpected end of input after `<`."
            | "Unclosed XML tag."
            | "Unclosed comment."
            | "Unclosed XML comment."
            | "Unclosed CDATA section."
            | "XML declaration closer not found."
            | "Unclosed XML declaration."
            | "Processing instruction closer not found."
            | "Unclosed DOCTYPE declaration."
            | "Expected `=` after XML attribute name."
            | "Expected quoted XML attribute value."
            | "Unclosed XML attribute value."
            | "Unclosed declaration."
    )
}

pub fn parse_xml_document(xml: &str) -> XmlDocument {
    let bytes = xml.as_bytes();
    if bytes.starts_with(&[0xef, 0xbb, 0xbf]) {
        return xml_error("Unexpected UTF-8 BOM byte sequence.", Vec::new());
    }

    let mut tokens = Vec::new();
    let mut stack = Vec::new();
    let mut breadcrumb_stack = Vec::new();
    let mut root_namespaces = HashMap::new();
    root_namespaces.insert(
        "xml".to_string(),
        "http://www.w3.org/XML/1998/namespace".to_string(),
    );
    let mut namespace_stack = vec![Rc::new(root_namespaces)];
    let mut offset = 0;
    let mut root_seen = false;

    while let Some(open) = find_byte(bytes, b'<', offset) {
        if offset < open {
            let text = String::from_utf8_lossy(&bytes[offset..open]);
            if root_seen && stack.is_empty() && !is_xml_whitespace(&text) {
                return xml_error("Unexpected non-whitespace text after root element.", tokens);
            }

            if root_seen || !is_xml_whitespace(&text) {
                push_xml_text_token(bytes, offset, open, &breadcrumb_stack, &mut tokens);
            }
        }

        let token_start = open;
        let mut cursor = open + 1;
        if cursor >= bytes.len() {
            return xml_error("Unexpected end of input after `<`.", tokens);
        }

        if bytes[cursor] == b'!'
            && cursor + 2 < bytes.len()
            && bytes[cursor + 1] == b'-'
            && bytes[cursor + 2] == b'-'
        {
            let comment_start = cursor + 3;
            let comment_end = match find_xml_comment_end(bytes, comment_start) {
                Some(comment_end) => comment_end,
                None => return xml_error("Unclosed comment.", tokens),
            };
            if is_malformed_xml_comment(&bytes[comment_start..comment_end]) {
                return xml_error("Malformed XML comment.", tokens);
            }

            tokens.push(XmlToken {
                start_offset: token_start,
                name: "#comment".to_string(),
                token_type: "#comment".to_string(),
                namespace: None,
                local_name: "#comment".to_string(),
                closing: false,
                empty_element: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                text: normalize_xml_text(&String::from_utf8_lossy(
                    &bytes[comment_start..comment_end],
                )),
                text_start: comment_start,
                text_end: comment_end,
                breadcrumbs: breadcrumb_stack.clone(),
                depth: breadcrumb_stack.len(),
            });

            offset = comment_end + 3;
            continue;
        }

        if bytes[cursor] == b'!'
            && cursor + 8 < bytes.len()
            && &bytes[cursor + 1..cursor + 8] == b"[CDATA["
        {
            if root_seen && stack.is_empty() {
                return xml_error("Unexpected CDATA section after root element.", tokens);
            }

            let cdata_start = cursor + 8;
            let cdata_end = match find_xml_cdata_end(bytes, cdata_start) {
                Some(cdata_end) => cdata_end,
                None => return xml_error("Unclosed CDATA section.", tokens),
            };

            tokens.push(XmlToken {
                start_offset: token_start,
                name: "#cdata-section".to_string(),
                token_type: "#cdata-section".to_string(),
                namespace: None,
                local_name: "#cdata-section".to_string(),
                closing: false,
                empty_element: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                text: normalize_xml_text(&String::from_utf8_lossy(&bytes[cdata_start..cdata_end])),
                text_start: cdata_start,
                text_end: cdata_end,
                breadcrumbs: breadcrumb_stack.clone(),
                depth: breadcrumb_stack.len(),
            });

            offset = cdata_end + 3;
            continue;
        }

        if cursor == 1 && bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let declaration_start = cursor + 1;
            let declaration_end =
                match find_xml_processing_instruction_end(bytes, declaration_start) {
                    Some(declaration_end) => declaration_end,
                    None => return xml_error("XML declaration closer not found.", tokens),
                };

            let (attributes, attribute_order) =
                match parse_xml_attributes(bytes, declaration_start + 3, declaration_end) {
                    Some(attributes) => attributes,
                    None => {
                        return xml_error("Invalid attribute found in XML declaration.", tokens)
                    }
                };

            tokens.push(XmlToken {
                start_offset: token_start,
                name: "#xml-declaration".to_string(),
                token_type: "#xml-declaration".to_string(),
                namespace: None,
                local_name: "#xml-declaration".to_string(),
                closing: false,
                empty_element: false,
                attributes,
                attribute_order,
                text: normalize_xml_text(&String::from_utf8_lossy(
                    &bytes[declaration_start..declaration_end],
                )),
                text_start: declaration_start,
                text_end: declaration_end,
                breadcrumbs: Vec::new(),
                depth: 0,
            });

            offset = declaration_end + 2;
            continue;
        }

        if bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let instruction_start = cursor + 1;
            let instruction_end =
                match find_xml_processing_instruction_end(bytes, instruction_start) {
                    Some(instruction_end) => instruction_end,
                    None => return xml_error("Processing instruction closer not found.", tokens),
                };

            tokens.push(XmlToken {
                start_offset: token_start,
                name: "#processing-instructions".to_string(),
                token_type: "#processing-instructions".to_string(),
                namespace: None,
                local_name: "#processing-instructions".to_string(),
                closing: false,
                empty_element: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                text: normalize_xml_text(&String::from_utf8_lossy(
                    &bytes[instruction_start + 3..instruction_end],
                )),
                text_start: instruction_start + 3,
                text_end: instruction_end,
                breadcrumbs: breadcrumb_stack.clone(),
                depth: breadcrumb_stack.len(),
            });

            offset = instruction_end + 2;
            continue;
        }

        if bytes[cursor] == b'!' && is_ascii_case_insensitive_prefix(bytes, cursor + 1, b"DOCTYPE")
        {
            let doctype_end = match find_byte(bytes, b'>', cursor + 8) {
                Some(doctype_end) => doctype_end,
                None => return xml_error("Unclosed DOCTYPE declaration.", tokens),
            };

            tokens.push(XmlToken {
                start_offset: token_start,
                name: "#doctype".to_string(),
                token_type: "#doctype".to_string(),
                namespace: None,
                local_name: "#doctype".to_string(),
                closing: false,
                empty_element: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                text: String::new(),
                text_start: XML_TEXT_RANGE_NONE,
                text_end: XML_TEXT_RANGE_NONE,
                breadcrumbs: Vec::new(),
                depth: 0,
            });

            offset = doctype_end + 1;
            continue;
        }

        if bytes[cursor] == b'?' {
            return xml_error("Unsupported processing instruction.", tokens);
        }

        if bytes[cursor] == b'!' {
            offset = match find_byte(bytes, b'>', cursor) {
                Some(close) => close + 1,
                None => return xml_error("Unclosed declaration.", tokens),
            };
            continue;
        }

        let closing = bytes[cursor] == b'/';
        if closing {
            cursor += 1;
        }

        cursor = skip_ascii_whitespace(bytes, cursor);
        let name_start = cursor;
        cursor = span_name(bytes, cursor);
        if cursor == name_start {
            return xml_error("Expected XML tag name.", tokens);
        }

        let name = String::from_utf8_lossy(&bytes[name_start..cursor]).into_owned();
        let mut attributes = HashMap::new();
        let mut attribute_order = Vec::new();
        let mut namespace_declarations = HashMap::new();
        let mut self_closing = false;

        while cursor < bytes.len() {
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() {
                return xml_error("Unclosed XML tag.", tokens);
            }
            if bytes[cursor] == b'>' {
                break;
            }
            if closing {
                return xml_error("Invalid closing tag encountered.", tokens);
            }
            if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
                self_closing = true;
                cursor += 1;
                break;
            }

            let attr_start = cursor;
            cursor = span_name(bytes, cursor);
            if cursor == attr_start {
                return xml_error("Expected XML attribute name.", tokens);
            }

            let attr_name = String::from_utf8_lossy(&bytes[attr_start..cursor]).into_owned();
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() || bytes[cursor] != b'=' {
                return xml_error("Expected `=` after XML attribute name.", tokens);
            }

            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() || (bytes[cursor] != b'"' && bytes[cursor] != b'\'') {
                return xml_error("Expected quoted XML attribute value.", tokens);
            }

            let quote = bytes[cursor];
            let value_start = cursor + 1;
            let value_end = match find_byte(bytes, quote, value_start) {
                Some(value_end) => value_end,
                None => return xml_error("Unclosed XML attribute value.", tokens),
            };
            if bytes[value_start..value_end].contains(&b'<') {
                return xml_error("Disallowed character in XML attribute value.", tokens);
            }
            if attributes.contains_key(&attr_name) {
                return xml_error("Duplicate XML attribute encountered.", tokens);
            }
            attributes.insert(
                attr_name.clone(),
                decode_xml_value(&String::from_utf8_lossy(&bytes[value_start..value_end])),
            );
            attribute_order.push(attr_name);
            cursor = value_end + 1;
        }
        if cursor >= bytes.len() {
            return xml_error("Unclosed XML tag.", tokens);
        }

        for (attr_name, attr_value) in &attributes {
            if attr_name == "xmlns" {
                namespace_declarations.insert(String::new(), attr_value.clone());
            } else if let Some(prefix) = attr_name.strip_prefix("xmlns:") {
                if attr_value.is_empty() {
                    return xml_error(
                        &format!("Invalid XML namespace declaration for `{}`.", attr_name),
                        tokens,
                    );
                }
                if prefix == "xmlns"
                    || (prefix == "xml" && attr_value != "http://www.w3.org/XML/1998/namespace")
                {
                    return xml_error("Invalid reserved XML namespace declaration.", tokens);
                }
                namespace_declarations.insert(prefix.to_string(), attr_value.clone());
            }
        }

        let current_namespaces = namespace_stack
            .last()
            .cloned()
            .unwrap_or_else(|| Rc::new(HashMap::new()));
        let namespaces = if !closing && !namespace_declarations.is_empty() {
            extend_xml_namespaces(&current_namespaces, namespace_declarations)
        } else {
            current_namespaces
        };

        let (prefix, local_name) = split_qualified_name(&name);
        let namespace = match prefix {
            Some(prefix) => namespaces.get(prefix).cloned(),
            None => namespaces.get("").cloned(),
        };
        if prefix.is_some() && namespace.is_none() {
            return xml_error("Undeclared XML namespace prefix.", tokens);
        }
        let local_name = local_name.to_string();
        let mut resolved_attributes = HashMap::new();
        let mut resolved_attribute_order = Vec::new();
        let mut seen_resolved_attribute_names = HashSet::new();
        for attr_name in &attribute_order {
            if attr_name == "xmlns" || attr_name.starts_with("xmlns:") {
                continue;
            }

            let attr_value = attributes
                .get(attr_name)
                .expect("attribute_order should reference parsed attributes");
            let (attr_prefix, attr_local_name) = split_qualified_name(attr_name);
            let resolved_name = if let Some(attr_prefix) = attr_prefix {
                let attr_namespace = match namespaces.get(attr_prefix) {
                    Some(attr_namespace) => attr_namespace,
                    None => return xml_error("Undeclared XML attribute namespace prefix.", tokens),
                };
                format!("{{{}}}{}", attr_namespace, attr_local_name)
            } else {
                attr_name.clone()
            };

            if !seen_resolved_attribute_names.insert(resolved_name.clone()) {
                return xml_error("Duplicate XML attribute encountered.", tokens);
            }

            resolved_attributes.insert(resolved_name.clone(), attr_value.clone());
            resolved_attribute_order.push(resolved_name);
        }

        let breadcrumb = (namespace.clone().unwrap_or_default(), local_name.clone());
        let mut breadcrumbs = breadcrumb_stack.clone();
        if !closing {
            if stack.is_empty() && root_seen {
                return xml_error("Unexpected element after root element.", tokens);
            }

            if stack.is_empty() {
                root_seen = true;
            }

            breadcrumbs.push(breadcrumb.clone());
        }

        if closing {
            match stack.pop() {
                Some(open_name) if open_name == name => {}
                Some(open_name) => {
                    return xml_error(
                        &format!(
                            "Mismatched closing tag `{}` expected `{}`.",
                            name, open_name
                        ),
                        tokens,
                    )
                }
                None => return xml_error("Closing tag without an open element.", tokens),
            }
            if namespace_stack.len() > 1 {
                namespace_stack.pop();
            }
            breadcrumb_stack.pop();
        } else if !self_closing {
            stack.push(name.clone());
            breadcrumb_stack.push(breadcrumb);
            namespace_stack.push(Rc::clone(&namespaces));
        }

        let depth = if closing {
            breadcrumb_stack.len()
        } else {
            breadcrumbs.len()
        };

        let tag_end = match find_byte(bytes, b'>', cursor) {
            Some(close) => close + 1,
            None => return xml_error("Unclosed XML tag.", tokens),
        };
        tokens.push(XmlToken {
            start_offset: token_start,
            name,
            token_type: "#tag".to_string(),
            namespace,
            local_name,
            closing,
            empty_element: self_closing,
            attributes: resolved_attributes,
            attribute_order: resolved_attribute_order,
            text: String::new(),
            text_start: XML_TEXT_RANGE_NONE,
            text_end: XML_TEXT_RANGE_NONE,
            breadcrumbs,
            depth,
        });

        offset = tag_end;
    }

    if offset < bytes.len() {
        let text = String::from_utf8_lossy(&bytes[offset..]);
        if root_seen && stack.is_empty() && !is_xml_whitespace(&text) {
            return xml_error("Unexpected non-whitespace text after root element.", tokens);
        }

        if root_seen || !is_xml_whitespace(&text) {
            push_xml_text_token(bytes, offset, bytes.len(), &breadcrumb_stack, &mut tokens);
        }
    }

    if let Some(open_name) = stack.pop() {
        return xml_error(&format!("Unclosed XML element `{}`.", open_name), tokens);
    }

    if !root_seen {
        return xml_error("Missing XML document element.", tokens);
    }

    XmlDocument {
        tokens,
        error: None,
    }
}

fn parse_next_xml_stream_token(
    xml: &str,
    state: &mut XmlStreamState,
) -> Result<Option<XmlToken>, String> {
    parse_next_xml_stream_token_with_payloads(xml, state, true, true)
}

fn parse_next_xml_stream_token_with_payloads(
    xml: &str,
    state: &mut XmlStreamState,
    materialize_text: bool,
    materialize_breadcrumbs: bool,
) -> Result<Option<XmlToken>, String> {
    let bytes = xml.as_bytes();
    if state.offset == 0 && bytes.starts_with(&[0xef, 0xbb, 0xbf]) {
        state.finished = true;
        return Err("Unexpected UTF-8 BOM byte sequence.".to_string());
    }

    if state.finished {
        return Ok(None);
    }

    loop {
        let open = match find_byte(bytes, b'<', state.offset) {
            Some(open) => open,
            None => {
                if state.offset < bytes.len() {
                    if state.root_seen
                        && state.stack.is_empty()
                        && !is_xml_whitespace_bytes(&bytes[state.offset..])
                    {
                        state.finished = true;
                        return Err(
                            "Unexpected non-whitespace text after root element.".to_string()
                        );
                    }

                    let token =
                        if state.root_seen || !is_xml_whitespace_bytes(&bytes[state.offset..]) {
                            xml_text_token_with_payload(
                                bytes,
                                state.offset,
                                bytes.len(),
                                &state.breadcrumb_stack,
                                materialize_text,
                                materialize_breadcrumbs,
                            )
                        } else {
                            None
                        };
                    state.offset = bytes.len();
                    if token.is_some() {
                        return Ok(token);
                    }
                }

                state.finished = true;
                if let Some(open_name) = state.stack.pop() {
                    return Err(format!("Unclosed XML element `{}`.", open_name));
                }
                if !state.root_seen {
                    return Err("Missing XML document element.".to_string());
                }

                return Ok(None);
            }
        };

        if state.offset < open {
            if state.root_seen
                && state.stack.is_empty()
                && !is_xml_whitespace_bytes(&bytes[state.offset..open])
            {
                state.finished = true;
                return Err("Unexpected non-whitespace text after root element.".to_string());
            }

            let token = if state.root_seen || !is_xml_whitespace_bytes(&bytes[state.offset..open]) {
                xml_text_token_with_payload(
                    bytes,
                    state.offset,
                    open,
                    &state.breadcrumb_stack,
                    materialize_text,
                    materialize_breadcrumbs,
                )
            } else {
                None
            };
            state.offset = open;
            if token.is_some() {
                return Ok(token);
            }
        }

        let token_start = open;
        let mut cursor = open + 1;
        if cursor >= bytes.len() {
            state.finished = true;
            return Err("Unexpected end of input after `<`.".to_string());
        }

        if bytes[cursor] == b'!'
            && cursor + 2 < bytes.len()
            && bytes[cursor + 1] == b'-'
            && bytes[cursor + 2] == b'-'
        {
            let comment_start = cursor + 3;
            let comment_end = match find_xml_comment_end(bytes, comment_start) {
                Some(comment_end) => comment_end,
                None => {
                    state.finished = true;
                    return Err("Unclosed comment.".to_string());
                }
            };
            if is_malformed_xml_comment(&bytes[comment_start..comment_end]) {
                state.finished = true;
                return Err("Malformed XML comment.".to_string());
            }

            state.offset = comment_end + 3;
            return Ok(Some(XmlToken {
                start_offset: token_start,
                name: "#comment".to_string(),
                token_type: "#comment".to_string(),
                namespace: None,
                local_name: "#comment".to_string(),
                closing: false,
                empty_element: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                text: if materialize_text {
                    normalize_xml_text(&String::from_utf8_lossy(&bytes[comment_start..comment_end]))
                } else {
                    String::new()
                },
                text_start: comment_start,
                text_end: comment_end,
                breadcrumbs: if materialize_breadcrumbs {
                    state.breadcrumb_stack.clone()
                } else {
                    Vec::new()
                },
                depth: state.breadcrumb_stack.len(),
            }));
        }

        if bytes[cursor] == b'!'
            && cursor + 8 < bytes.len()
            && &bytes[cursor + 1..cursor + 8] == b"[CDATA["
        {
            if state.root_seen && state.stack.is_empty() {
                state.finished = true;
                return Err("Unexpected CDATA section after root element.".to_string());
            }

            let cdata_start = cursor + 8;
            let cdata_end = match find_xml_cdata_end(bytes, cdata_start) {
                Some(cdata_end) => cdata_end,
                None => {
                    state.finished = true;
                    return Err("Unclosed CDATA section.".to_string());
                }
            };

            state.offset = cdata_end + 3;
            return Ok(Some(XmlToken {
                start_offset: token_start,
                name: "#cdata-section".to_string(),
                token_type: "#cdata-section".to_string(),
                namespace: None,
                local_name: "#cdata-section".to_string(),
                closing: false,
                empty_element: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                text: if materialize_text {
                    normalize_xml_text(&String::from_utf8_lossy(&bytes[cdata_start..cdata_end]))
                } else {
                    String::new()
                },
                text_start: cdata_start,
                text_end: cdata_end,
                breadcrumbs: if materialize_breadcrumbs {
                    state.breadcrumb_stack.clone()
                } else {
                    Vec::new()
                },
                depth: state.breadcrumb_stack.len(),
            }));
        }

        if cursor == 1 && bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let declaration_start = cursor + 1;
            let declaration_end =
                match find_xml_processing_instruction_end(bytes, declaration_start) {
                    Some(declaration_end) => declaration_end,
                    None => {
                        state.finished = true;
                        return Err("XML declaration closer not found.".to_string());
                    }
                };

            let (attributes, attribute_order) =
                match parse_xml_attributes(bytes, declaration_start + 3, declaration_end) {
                    Some(attributes) => attributes,
                    None => {
                        state.finished = true;
                        return Err("Invalid attribute found in XML declaration.".to_string());
                    }
                };

            state.offset = declaration_end + 2;
            return Ok(Some(XmlToken {
                start_offset: token_start,
                name: "#xml-declaration".to_string(),
                token_type: "#xml-declaration".to_string(),
                namespace: None,
                local_name: "#xml-declaration".to_string(),
                closing: false,
                empty_element: false,
                attributes,
                attribute_order,
                text: if materialize_text {
                    normalize_xml_text(&String::from_utf8_lossy(
                        &bytes[declaration_start..declaration_end],
                    ))
                } else {
                    String::new()
                },
                text_start: declaration_start,
                text_end: declaration_end,
                breadcrumbs: Vec::new(),
                depth: 0,
            }));
        }

        if bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let instruction_start = cursor + 1;
            let instruction_end =
                match find_xml_processing_instruction_end(bytes, instruction_start) {
                    Some(instruction_end) => instruction_end,
                    None => {
                        state.finished = true;
                        return Err("Processing instruction closer not found.".to_string());
                    }
                };

            state.offset = instruction_end + 2;
            return Ok(Some(XmlToken {
                start_offset: token_start,
                name: "#processing-instructions".to_string(),
                token_type: "#processing-instructions".to_string(),
                namespace: None,
                local_name: "#processing-instructions".to_string(),
                closing: false,
                empty_element: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                text: if materialize_text {
                    normalize_xml_text(&String::from_utf8_lossy(
                        &bytes[instruction_start + 3..instruction_end],
                    ))
                } else {
                    String::new()
                },
                text_start: instruction_start + 3,
                text_end: instruction_end,
                breadcrumbs: if materialize_breadcrumbs {
                    state.breadcrumb_stack.clone()
                } else {
                    Vec::new()
                },
                depth: state.breadcrumb_stack.len(),
            }));
        }

        if bytes[cursor] == b'!' && is_ascii_case_insensitive_prefix(bytes, cursor + 1, b"DOCTYPE")
        {
            let doctype_end = match find_byte(bytes, b'>', cursor + 8) {
                Some(doctype_end) => doctype_end,
                None => {
                    state.finished = true;
                    return Err("Unclosed DOCTYPE declaration.".to_string());
                }
            };

            state.offset = doctype_end + 1;
            return Ok(Some(XmlToken {
                start_offset: token_start,
                name: "#doctype".to_string(),
                token_type: "#doctype".to_string(),
                namespace: None,
                local_name: "#doctype".to_string(),
                closing: false,
                empty_element: false,
                attributes: HashMap::new(),
                attribute_order: Vec::new(),
                text: String::new(),
                text_start: XML_TEXT_RANGE_NONE,
                text_end: XML_TEXT_RANGE_NONE,
                breadcrumbs: Vec::new(),
                depth: 0,
            }));
        }

        if bytes[cursor] == b'?' {
            state.finished = true;
            return Err("Unsupported processing instruction.".to_string());
        }

        if bytes[cursor] == b'!' {
            state.offset = match find_byte(bytes, b'>', cursor) {
                Some(close) => close + 1,
                None => {
                    state.finished = true;
                    return Err("Unclosed declaration.".to_string());
                }
            };
            continue;
        }

        let closing = bytes[cursor] == b'/';
        if closing {
            cursor += 1;
        }

        cursor = skip_ascii_whitespace(bytes, cursor);
        let name_start = cursor;
        cursor = span_name(bytes, cursor);
        if cursor == name_start {
            state.finished = true;
            return Err("Expected XML tag name.".to_string());
        }

        let name = String::from_utf8_lossy(&bytes[name_start..cursor]).into_owned();
        let mut attributes = HashMap::new();
        let mut attribute_order = Vec::new();
        let mut namespace_declarations = HashMap::new();
        let mut self_closing = false;

        while cursor < bytes.len() {
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() {
                state.finished = true;
                return Err("Unclosed XML tag.".to_string());
            }
            if bytes[cursor] == b'>' {
                break;
            }
            if closing {
                state.finished = true;
                return Err("Invalid closing tag encountered.".to_string());
            }
            if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
                self_closing = true;
                cursor += 1;
                break;
            }

            let attr_start = cursor;
            cursor = span_name(bytes, cursor);
            if cursor == attr_start {
                state.finished = true;
                return Err("Expected XML attribute name.".to_string());
            }

            let attr_name = String::from_utf8_lossy(&bytes[attr_start..cursor]).into_owned();
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() || bytes[cursor] != b'=' {
                state.finished = true;
                return Err("Expected `=` after XML attribute name.".to_string());
            }

            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() || (bytes[cursor] != b'"' && bytes[cursor] != b'\'') {
                state.finished = true;
                return Err("Expected quoted XML attribute value.".to_string());
            }

            let quote = bytes[cursor];
            let value_start = cursor + 1;
            let value_end = match find_byte(bytes, quote, value_start) {
                Some(value_end) => value_end,
                None => {
                    state.finished = true;
                    return Err("Unclosed XML attribute value.".to_string());
                }
            };
            if bytes[value_start..value_end].contains(&b'<') {
                state.finished = true;
                return Err("Disallowed character in XML attribute value.".to_string());
            }
            if attributes.contains_key(&attr_name) {
                state.finished = true;
                return Err("Duplicate XML attribute encountered.".to_string());
            }
            attributes.insert(
                attr_name.clone(),
                decode_xml_value(&String::from_utf8_lossy(&bytes[value_start..value_end])),
            );
            attribute_order.push(attr_name);
            cursor = value_end + 1;
        }
        if cursor >= bytes.len() {
            state.finished = true;
            return Err("Unclosed XML tag.".to_string());
        }

        let current_namespaces = state
            .namespace_stack
            .last()
            .cloned()
            .unwrap_or_else(|| Rc::new(HashMap::new()));
        for (attr_name, attr_value) in &attributes {
            if attr_name == "xmlns" {
                if current_namespaces.get("") != Some(attr_value) {
                    namespace_declarations.insert(String::new(), attr_value.clone());
                }
            } else if let Some(prefix) = attr_name.strip_prefix("xmlns:") {
                if attr_value.is_empty() {
                    state.finished = true;
                    return Err(format!(
                        "Invalid XML namespace declaration for `{}`.",
                        attr_name
                    ));
                }
                if prefix == "xmlns"
                    || (prefix == "xml" && attr_value != "http://www.w3.org/XML/1998/namespace")
                {
                    state.finished = true;
                    return Err("Invalid reserved XML namespace declaration.".to_string());
                }
                if current_namespaces.get(prefix) != Some(attr_value) {
                    namespace_declarations.insert(prefix.to_string(), attr_value.clone());
                }
            }
        }

        let namespaces = if !closing && !namespace_declarations.is_empty() {
            extend_xml_namespaces(&current_namespaces, namespace_declarations)
        } else {
            current_namespaces
        };

        let (prefix, local_name) = split_qualified_name(&name);
        let namespace = match prefix {
            Some(prefix) => namespaces.get(prefix).cloned(),
            None => namespaces.get("").cloned(),
        };
        if prefix.is_some() && namespace.is_none() {
            state.finished = true;
            return Err("Undeclared XML namespace prefix.".to_string());
        }
        let local_name = local_name.to_string();
        let mut resolved_attributes = HashMap::new();
        let mut resolved_attribute_order = Vec::new();
        let mut seen_resolved_attribute_names = HashSet::new();
        for attr_name in &attribute_order {
            if attr_name == "xmlns" || attr_name.starts_with("xmlns:") {
                continue;
            }

            let attr_value = attributes
                .get(attr_name)
                .expect("attribute_order should reference parsed attributes");
            let (attr_prefix, attr_local_name) = split_qualified_name(attr_name);
            let resolved_name = if let Some(attr_prefix) = attr_prefix {
                let attr_namespace = match namespaces.get(attr_prefix) {
                    Some(attr_namespace) => attr_namespace,
                    None => {
                        state.finished = true;
                        return Err("Undeclared XML attribute namespace prefix.".to_string());
                    }
                };
                format!("{{{}}}{}", attr_namespace, attr_local_name)
            } else {
                attr_name.clone()
            };

            if !seen_resolved_attribute_names.insert(resolved_name.clone()) {
                state.finished = true;
                return Err("Duplicate XML attribute encountered.".to_string());
            }

            resolved_attributes.insert(resolved_name.clone(), attr_value.clone());
            resolved_attribute_order.push(resolved_name);
        }

        let breadcrumb = (namespace.clone().unwrap_or_default(), local_name.clone());
        let mut breadcrumbs = if materialize_breadcrumbs {
            state.breadcrumb_stack.clone()
        } else {
            Vec::new()
        };
        if !closing {
            if state.stack.is_empty() && state.root_seen {
                state.finished = true;
                return Err("Unexpected element after root element.".to_string());
            }

            if state.stack.is_empty() {
                state.root_seen = true;
            }

            if materialize_breadcrumbs {
                breadcrumbs.push(breadcrumb.clone());
            }
        }

        if closing {
            if state.context_stack_depth > 0 && state.stack.len() <= state.context_stack_depth {
                state.finished = true;
                return Err("syntax".to_string());
            }

            match state.stack.pop() {
                Some(open_name) if open_name == name => {}
                Some(open_name) => {
                    state.finished = true;
                    return Err(format!(
                        "Mismatched closing tag `{}` expected `{}`.",
                        name, open_name
                    ));
                }
                None => {
                    state.finished = true;
                    return Err("Closing tag without an open element.".to_string());
                }
            }
            if state.namespace_stack.len() > 1 {
                state.namespace_stack.pop();
            }
            state.breadcrumb_stack.pop();
        } else if !self_closing {
            state.stack.push(name.clone());
            state.breadcrumb_stack.push(breadcrumb);
            state.namespace_stack.push(Rc::clone(&namespaces));
        }

        let depth = if closing {
            state.breadcrumb_stack.len()
        } else if materialize_breadcrumbs {
            breadcrumbs.len()
        } else if self_closing {
            state.breadcrumb_stack.len() + 1
        } else {
            state.breadcrumb_stack.len()
        };

        let tag_end = match find_byte(bytes, b'>', cursor) {
            Some(close) => close + 1,
            None => {
                state.finished = true;
                return Err("Unclosed XML tag.".to_string());
            }
        };
        state.offset = tag_end;

        return Ok(Some(XmlToken {
            start_offset: token_start,
            name,
            token_type: "#tag".to_string(),
            namespace,
            local_name,
            closing,
            empty_element: self_closing,
            attributes: resolved_attributes,
            attribute_order: resolved_attribute_order,
            text: String::new(),
            text_start: XML_TEXT_RANGE_NONE,
            text_end: XML_TEXT_RANGE_NONE,
            breadcrumbs,
            depth,
        }));
    }
}

fn parse_next_xml_stream_compact_summary(
    xml: &str,
    state: &mut XmlStreamState,
) -> Result<Option<String>, String> {
    parse_next_xml_stream_summary(xml, state, xml_compact_summary_row)
}

fn parse_next_xml_stream_hot_compact_summary(
    xml: &str,
    state: &mut XmlStreamState,
) -> Result<Option<String>, String> {
    parse_next_xml_stream_summary(xml, state, xml_hot_compact_summary_row)
}

fn parse_next_xml_stream_cursor_compact_summary(
    xml: &str,
    state: &mut XmlStreamState,
) -> Result<Option<String>, String> {
    parse_next_xml_stream_summary(xml, state, xml_cursor_compact_summary_row)
}

fn parse_next_xml_stream_summary(
    xml: &str,
    state: &mut XmlStreamState,
    summary_row: fn(&str, &str, &str, bool, bool, usize, Option<&str>) -> String,
) -> Result<Option<String>, String> {
    let bytes = xml.as_bytes();
    if state.offset == 0 && bytes.starts_with(&[0xef, 0xbb, 0xbf]) {
        state.finished = true;
        return Err("Unexpected UTF-8 BOM byte sequence.".to_string());
    }

    if state.finished {
        return Ok(None);
    }

    loop {
        let open = match find_byte(bytes, b'<', state.offset) {
            Some(open) => open,
            None => {
                if state.offset < bytes.len() {
                    if state.root_seen
                        && state.stack.is_empty()
                        && !is_xml_whitespace_bytes(&bytes[state.offset..])
                    {
                        state.finished = true;
                        return Err(
                            "Unexpected non-whitespace text after root element.".to_string()
                        );
                    }

                    let has_text =
                        state.root_seen || !is_xml_whitespace_bytes(&bytes[state.offset..]);
                    state.offset = bytes.len();
                    if has_text {
                        return Ok(Some(summary_row(
                            "s",
                            "#text",
                            "",
                            false,
                            false,
                            state.breadcrumb_stack.len(),
                            None,
                        )));
                    }
                }

                state.finished = true;
                if let Some(open_name) = state.stack.pop() {
                    return Err(format!("Unclosed XML element `{}`.", open_name));
                }
                if !state.root_seen {
                    return Err("Missing XML document element.".to_string());
                }

                return Ok(None);
            }
        };

        if state.offset < open {
            if state.root_seen
                && state.stack.is_empty()
                && !is_xml_whitespace_bytes(&bytes[state.offset..open])
            {
                state.finished = true;
                return Err("Unexpected non-whitespace text after root element.".to_string());
            }

            let has_text = state.root_seen || !is_xml_whitespace_bytes(&bytes[state.offset..open]);
            state.offset = open;
            if has_text {
                return Ok(Some(summary_row(
                    "s",
                    "#text",
                    "",
                    false,
                    false,
                    state.breadcrumb_stack.len(),
                    None,
                )));
            }
        }

        let mut cursor = open + 1;
        if cursor >= bytes.len() {
            state.finished = true;
            return Err("Unexpected end of input after `<`.".to_string());
        }

        if bytes[cursor] == b'!'
            && cursor + 2 < bytes.len()
            && bytes[cursor + 1] == b'-'
            && bytes[cursor + 2] == b'-'
        {
            let comment_start = cursor + 3;
            let comment_end = match find_xml_comment_end(bytes, comment_start) {
                Some(comment_end) => comment_end,
                None => {
                    state.finished = true;
                    return Err("Unclosed comment.".to_string());
                }
            };
            if is_malformed_xml_comment(&bytes[comment_start..comment_end]) {
                state.finished = true;
                return Err("Malformed XML comment.".to_string());
            }

            state.offset = comment_end + 3;
            return Ok(Some(summary_row(
                "c",
                "#comment",
                "",
                false,
                false,
                state.breadcrumb_stack.len(),
                None,
            )));
        }

        if bytes[cursor] == b'!'
            && cursor + 8 < bytes.len()
            && &bytes[cursor + 1..cursor + 8] == b"[CDATA["
        {
            if state.root_seen && state.stack.is_empty() {
                state.finished = true;
                return Err("Unexpected CDATA section after root element.".to_string());
            }

            let cdata_start = cursor + 8;
            let cdata_end = match find_xml_cdata_end(bytes, cdata_start) {
                Some(cdata_end) => cdata_end,
                None => {
                    state.finished = true;
                    return Err("Unclosed CDATA section.".to_string());
                }
            };

            state.offset = cdata_end + 3;
            return Ok(Some(summary_row(
                "a",
                "#cdata-section",
                "",
                false,
                false,
                state.breadcrumb_stack.len(),
                None,
            )));
        }

        if cursor == 1 && bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let declaration_start = cursor + 1;
            let declaration_end =
                match find_xml_processing_instruction_end(bytes, declaration_start) {
                    Some(declaration_end) => declaration_end,
                    None => {
                        state.finished = true;
                        return Err("XML declaration closer not found.".to_string());
                    }
                };

            if parse_xml_attributes(bytes, declaration_start + 3, declaration_end).is_none() {
                state.finished = true;
                return Err("Invalid attribute found in XML declaration.".to_string());
            }

            state.offset = declaration_end + 2;
            return Ok(Some(summary_row(
                "x",
                "#xml-declaration",
                "",
                false,
                false,
                0,
                None,
            )));
        }

        if bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let instruction_start = cursor + 1;
            let instruction_end =
                match find_xml_processing_instruction_end(bytes, instruction_start) {
                    Some(instruction_end) => instruction_end,
                    None => {
                        state.finished = true;
                        return Err("Processing instruction closer not found.".to_string());
                    }
                };

            state.offset = instruction_end + 2;
            return Ok(Some(summary_row(
                "p",
                "#processing-instructions",
                "",
                false,
                false,
                state.breadcrumb_stack.len(),
                None,
            )));
        }

        if bytes[cursor] == b'!' && is_ascii_case_insensitive_prefix(bytes, cursor + 1, b"DOCTYPE")
        {
            let doctype_end = match find_byte(bytes, b'>', cursor + 8) {
                Some(doctype_end) => doctype_end,
                None => {
                    state.finished = true;
                    return Err("Unclosed DOCTYPE declaration.".to_string());
                }
            };

            state.offset = doctype_end + 1;
            return Ok(Some(summary_row(
                "d", "#doctype", "", false, false, 0, None,
            )));
        }

        if bytes[cursor] == b'?' {
            state.finished = true;
            return Err("Unsupported processing instruction.".to_string());
        }

        if bytes[cursor] == b'!' {
            state.offset = match find_byte(bytes, b'>', cursor) {
                Some(close) => close + 1,
                None => {
                    state.finished = true;
                    return Err("Unclosed declaration.".to_string());
                }
            };
            continue;
        }

        let closing = bytes[cursor] == b'/';
        if closing {
            cursor += 1;
        }

        cursor = skip_ascii_whitespace(bytes, cursor);
        let name_start = cursor;
        cursor = span_name(bytes, cursor);
        if cursor == name_start {
            state.finished = true;
            return Err("Expected XML tag name.".to_string());
        }

        let name = String::from_utf8_lossy(&bytes[name_start..cursor]).into_owned();
        let mut attributes = HashMap::new();
        let mut attribute_order = Vec::new();
        let mut namespace_declarations = HashMap::new();
        let mut self_closing = false;

        while cursor < bytes.len() {
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() {
                state.finished = true;
                return Err("Unclosed XML tag.".to_string());
            }
            if bytes[cursor] == b'>' {
                break;
            }
            if closing {
                state.finished = true;
                return Err("Invalid closing tag encountered.".to_string());
            }
            if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
                self_closing = true;
                cursor += 1;
                break;
            }

            let attr_start = cursor;
            cursor = span_name(bytes, cursor);
            if cursor == attr_start {
                state.finished = true;
                return Err("Expected XML attribute name.".to_string());
            }

            let attr_name = String::from_utf8_lossy(&bytes[attr_start..cursor]).into_owned();
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() || bytes[cursor] != b'=' {
                state.finished = true;
                return Err("Expected `=` after XML attribute name.".to_string());
            }

            cursor += 1;
            cursor = skip_ascii_whitespace(bytes, cursor);
            if cursor >= bytes.len() || (bytes[cursor] != b'"' && bytes[cursor] != b'\'') {
                state.finished = true;
                return Err("Expected quoted XML attribute value.".to_string());
            }

            let quote = bytes[cursor];
            let value_start = cursor + 1;
            let value_end = match find_byte(bytes, quote, value_start) {
                Some(value_end) => value_end,
                None => {
                    state.finished = true;
                    return Err("Unclosed XML attribute value.".to_string());
                }
            };
            if bytes[value_start..value_end].contains(&b'<') {
                state.finished = true;
                return Err("Disallowed character in XML attribute value.".to_string());
            }
            if attributes.contains_key(&attr_name) {
                state.finished = true;
                return Err("Duplicate XML attribute encountered.".to_string());
            }
            attributes.insert(
                attr_name.clone(),
                decode_xml_value(&String::from_utf8_lossy(&bytes[value_start..value_end])),
            );
            attribute_order.push(attr_name);
            cursor = value_end + 1;
        }
        if cursor >= bytes.len() {
            state.finished = true;
            return Err("Unclosed XML tag.".to_string());
        }

        let current_namespaces = state
            .namespace_stack
            .last()
            .cloned()
            .unwrap_or_else(|| Rc::new(HashMap::new()));
        for (attr_name, attr_value) in &attributes {
            if attr_name == "xmlns" {
                if current_namespaces.get("") != Some(attr_value) {
                    namespace_declarations.insert(String::new(), attr_value.clone());
                }
            } else if let Some(prefix) = attr_name.strip_prefix("xmlns:") {
                if attr_value.is_empty() {
                    state.finished = true;
                    return Err(format!(
                        "Invalid XML namespace declaration for `{}`.",
                        attr_name
                    ));
                }
                if prefix == "xmlns"
                    || (prefix == "xml" && attr_value != "http://www.w3.org/XML/1998/namespace")
                {
                    state.finished = true;
                    return Err("Invalid reserved XML namespace declaration.".to_string());
                }
                if current_namespaces.get(prefix) != Some(attr_value) {
                    namespace_declarations.insert(prefix.to_string(), attr_value.clone());
                }
            }
        }

        let namespaces = if !closing && !namespace_declarations.is_empty() {
            extend_xml_namespaces(&current_namespaces, namespace_declarations)
        } else {
            current_namespaces
        };

        let (prefix, local_name) = split_qualified_name(&name);
        let namespace = match prefix {
            Some(prefix) => namespaces.get(prefix).cloned(),
            None => namespaces.get("").cloned(),
        };
        if prefix.is_some() && namespace.is_none() {
            state.finished = true;
            return Err("Undeclared XML namespace prefix.".to_string());
        }

        let mut seen_resolved_attribute_names = HashSet::new();
        let mut id_attribute = None;
        for attr_name in &attribute_order {
            if attr_name == "xmlns" || attr_name.starts_with("xmlns:") {
                continue;
            }

            let (attr_prefix, attr_local_name) = split_qualified_name(attr_name);
            let resolved_name = if let Some(attr_prefix) = attr_prefix {
                let attr_namespace = match namespaces.get(attr_prefix) {
                    Some(attr_namespace) => attr_namespace,
                    None => {
                        state.finished = true;
                        return Err("Undeclared XML attribute namespace prefix.".to_string());
                    }
                };
                format!("{{{}}}{}", attr_namespace, attr_local_name)
            } else {
                attr_name.clone()
            };

            if !seen_resolved_attribute_names.insert(resolved_name.clone()) {
                state.finished = true;
                return Err("Duplicate XML attribute encountered.".to_string());
            }

            if resolved_name == "id" {
                id_attribute = attributes.get(attr_name).map(|value| value.as_str());
            }
        }

        let local_name = local_name.to_string();
        let breadcrumb = (namespace.clone().unwrap_or_default(), local_name.clone());
        let depth;
        if closing {
            match state.stack.pop() {
                Some(open_name) if open_name == name => {}
                Some(open_name) => {
                    state.finished = true;
                    return Err(format!(
                        "Mismatched closing tag `{}` expected `{}`.",
                        name, open_name
                    ));
                }
                None => {
                    state.finished = true;
                    return Err("Closing tag without an open element.".to_string());
                }
            }
            if state.namespace_stack.len() > 1 {
                state.namespace_stack.pop();
            }
            state.breadcrumb_stack.pop();
            depth = state.breadcrumb_stack.len();
        } else {
            if state.stack.is_empty() && state.root_seen {
                state.finished = true;
                return Err("Unexpected element after root element.".to_string());
            }

            if state.stack.is_empty() {
                state.root_seen = true;
            }

            depth = state.breadcrumb_stack.len() + 1;
            if !self_closing {
                state.stack.push(name);
                state.breadcrumb_stack.push(breadcrumb);
                state.namespace_stack.push(Rc::clone(&namespaces));
            }
        }

        let tag_end = match find_byte(bytes, b'>', cursor) {
            Some(close) => close + 1,
            None => {
                state.finished = true;
                return Err("Unclosed XML tag.".to_string());
            }
        };
        state.offset = tag_end;

        return Ok(Some(summary_row(
            "t",
            &local_name,
            namespace.as_deref().unwrap_or_default(),
            closing,
            self_closing,
            depth,
            id_attribute,
        )));
    }
}

fn xml_tag_modifiable_text(bytes: &[u8], content_start: usize, name: &str) -> String {
    if content_start >= bytes.len() || name.is_empty() {
        return String::new();
    }

    let name_bytes = name.as_bytes();
    let mut offset = content_start;
    let mut same_name_depth = 0usize;

    while let Some(open) = find_byte(bytes, b'<', offset) {
        let cursor = open + 1;
        if cursor >= bytes.len() {
            break;
        }

        if bytes[cursor] == b'?' {
            if let Some(end) = find_xml_processing_instruction_end(bytes, cursor + 1) {
                offset = end + 2;
                continue;
            }
        }

        if bytes[cursor] == b'!'
            && cursor + 2 < bytes.len()
            && bytes[cursor + 1] == b'-'
            && bytes[cursor + 2] == b'-'
        {
            if let Some(end) = find_xml_comment_end(bytes, cursor + 3) {
                offset = end + 3;
                continue;
            }
        }

        if bytes[cursor] == b'!'
            && cursor + 7 < bytes.len()
            && &bytes[cursor + 1..cursor + 8] == b"[CDATA["
        {
            if let Some(end) = find_xml_cdata_end(bytes, cursor + 8) {
                offset = end + 3;
                continue;
            }
        }

        let closing = bytes[cursor] == b'/';
        let name_start = if closing { cursor + 1 } else { cursor };
        let name_end = span_name(bytes, name_start);
        if name_end == name_start {
            offset = match find_byte(bytes, b'>', cursor) {
                Some(close) => close + 1,
                None => break,
            };
            continue;
        }

        let tag_end = match find_byte(bytes, b'>', name_end) {
            Some(close) => close + 1,
            None => break,
        };

        if &bytes[name_start..name_end] == name_bytes {
            if closing {
                if 0 == same_name_depth {
                    return decode_xml_text_bytes(&bytes[content_start..name_start]);
                }

                same_name_depth -= 1;
            } else if !xml_source_tag_is_self_closing(bytes, name_end, tag_end - 1) {
                same_name_depth += 1;
            }
        }

        offset = tag_end;
    }

    String::new()
}

fn xml_tag_content_start(bytes: &[u8], token_start: usize) -> usize {
    find_byte(bytes, b'>', token_start)
        .map(|close| close + 1)
        .unwrap_or(bytes.len())
}

fn xml_source_tag_is_self_closing(bytes: &[u8], after_name: usize, close: usize) -> bool {
    bytes
        .get(after_name..close)
        .and_then(|source| source.iter().rev().find(|byte| !byte.is_ascii_whitespace()))
        .map(|byte| *byte == b'/')
        .unwrap_or(false)
}

fn xml_text_token(
    bytes: &[u8],
    start: usize,
    end: usize,
    breadcrumbs: &[(String, String)],
) -> Option<XmlToken> {
    xml_text_token_with_payload(bytes, start, end, breadcrumbs, true, true)
}

fn xml_text_token_with_payload(
    bytes: &[u8],
    start: usize,
    end: usize,
    breadcrumbs: &[(String, String)],
    materialize_text: bool,
    materialize_breadcrumbs: bool,
) -> Option<XmlToken> {
    if end <= start {
        return None;
    }

    let text = if materialize_text {
        let text = decode_xml_text_bytes(&bytes[start..end]);
        if text.is_empty() {
            return None;
        }
        text
    } else {
        String::new()
    };

    Some(XmlToken {
        start_offset: start,
        name: "#text".to_string(),
        token_type: "#text".to_string(),
        namespace: None,
        local_name: "#text".to_string(),
        closing: false,
        empty_element: false,
        attributes: HashMap::new(),
        attribute_order: Vec::new(),
        text,
        text_start: start,
        text_end: end,
        breadcrumbs: if materialize_breadcrumbs {
            breadcrumbs.to_vec()
        } else {
            Vec::new()
        },
        depth: breadcrumbs.len(),
    })
}

fn push_xml_text_token(
    bytes: &[u8],
    start: usize,
    end: usize,
    breadcrumbs: &[(String, String)],
    tokens: &mut Vec<XmlToken>,
) {
    if let Some(token) = xml_text_token(bytes, start, end, breadcrumbs) {
        tokens.push(token);
    }
}

fn decode_xml_text_bytes(bytes: &[u8]) -> String {
    if !bytes.contains(&b'\r') {
        return decode_xml_value(&String::from_utf8_lossy(bytes));
    }

    decode_xml_value(&normalize_xml_text(&String::from_utf8_lossy(bytes)))
}

fn normalize_xml_text(text: &str) -> String {
    if !text.as_bytes().contains(&b'\r') {
        return text.to_string();
    }

    text.replace("\r\n", "\n").replace('\r', "\n")
}

fn is_xml_whitespace(text: &str) -> bool {
    text.bytes()
        .all(|byte| matches!(byte, b' ' | b'\t' | b'\n' | b'\r'))
}

fn is_xml_whitespace_bytes(bytes: &[u8]) -> bool {
    bytes
        .iter()
        .all(|byte| matches!(byte, b' ' | b'\t' | b'\n' | b'\r'))
}

fn decode_xml_value(value: &str) -> String {
    let bytes = value.as_bytes();
    if !bytes.contains(&b'&') {
        return value.to_string();
    }

    let mut decoded = String::new();
    let mut cursor = 0;
    let mut literal_start = 0;

    while cursor < bytes.len() {
        if bytes[cursor] != b'&' {
            cursor += 1;
            continue;
        }

        match decode_xml_character_reference(bytes, cursor + 1) {
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

fn decode_xml_character_reference(bytes: &[u8], start: usize) -> Option<(String, usize)> {
    let semicolon = find_byte(bytes, b';', start)?;
    let reference = &bytes[start..semicolon];
    let replacement = match reference {
        b"amp" => "&".to_string(),
        b"lt" => "<".to_string(),
        b"gt" => ">".to_string(),
        b"quot" => "\"".to_string(),
        b"apos" => "'".to_string(),
        _ if reference.starts_with(b"#") => {
            decode_xml_numeric_character_reference(&reference[1..])?
        }
        _ => return None,
    };

    Some((replacement, semicolon + 1))
}

fn decode_xml_numeric_character_reference(reference: &[u8]) -> Option<String> {
    if reference.is_empty() {
        return None;
    }

    let (radix, digits) = if reference[0] == b'x' || reference[0] == b'X' {
        (16, &reference[1..])
    } else {
        (10, reference)
    };

    if digits.is_empty() {
        return None;
    }

    let valid_digit = |byte: u8| {
        if radix == 16 {
            byte.is_ascii_hexdigit()
        } else {
            byte.is_ascii_digit()
        }
    };

    if !digits.iter().all(|byte| valid_digit(*byte)) {
        return None;
    }

    let digits = std::str::from_utf8(digits).ok()?;
    let value = u32::from_str_radix(digits, radix).ok()?;
    if !is_valid_xml_codepoint(value) {
        return None;
    }

    char::from_u32(value).map(|character| character.to_string())
}

fn is_valid_xml_codepoint(value: u32) -> bool {
    matches!(value, 0x09 | 0x0a | 0x0d | 0x20..=0xd7ff | 0xe000..=0xfffd | 0x10000..=0x10ffff)
}

fn find_xml_comment_end(bytes: &[u8], start: usize) -> Option<usize> {
    bytes
        .get(start..)?
        .windows(3)
        .position(|window| window == b"-->")
        .map(|position| position + start)
}

fn is_malformed_xml_comment(comment: &[u8]) -> bool {
    comment.ends_with(b"-") || comment.windows(2).any(|window| window == b"--")
}

fn find_xml_cdata_end(bytes: &[u8], start: usize) -> Option<usize> {
    bytes
        .get(start..)?
        .windows(3)
        .position(|window| window == b"]]>")
        .map(|position| position + start)
}

fn find_xml_processing_instruction_end(bytes: &[u8], start: usize) -> Option<usize> {
    bytes
        .get(start..)?
        .windows(2)
        .position(|window| window == b"?>")
        .map(|position| position + start)
}

fn is_xml_declaration(bytes: &[u8], start: usize) -> bool {
    bytes
        .get(start..start + 3)
        .map(|candidate| candidate == b"xml")
        .unwrap_or(false)
}

fn is_ascii_case_insensitive_prefix(bytes: &[u8], start: usize, prefix: &[u8]) -> bool {
    bytes
        .get(start..start + prefix.len())
        .map(|candidate| candidate.eq_ignore_ascii_case(prefix))
        .unwrap_or(false)
}

fn parse_xml_attributes(
    bytes: &[u8],
    mut cursor: usize,
    end: usize,
) -> Option<(HashMap<String, String>, Vec<String>)> {
    let mut attributes = HashMap::new();
    let mut attribute_order = Vec::new();

    while cursor < end {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= end {
            break;
        }

        let name_start = cursor;
        cursor = span_name(bytes, cursor);
        if cursor == name_start {
            return None;
        }

        let name = String::from_utf8_lossy(&bytes[name_start..cursor]).into_owned();
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= end || bytes[cursor] != b'=' {
            return None;
        }

        cursor += 1;
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= end || (bytes[cursor] != b'"' && bytes[cursor] != b'\'') {
            return None;
        }

        let quote = bytes[cursor];
        let value_start = cursor + 1;
        let value_end = match find_byte(bytes, quote, value_start) {
            Some(value_end) if value_end <= end => value_end,
            _ => return None,
        };
        if bytes[value_start..value_end].contains(&b'<') {
            return None;
        }
        if attributes.contains_key(&name) {
            return None;
        }

        attributes.insert(
            name.clone(),
            decode_xml_value(&String::from_utf8_lossy(&bytes[value_start..value_end])),
        );
        attribute_order.push(name);
        cursor = value_end + 1;
    }

    Some((attributes, attribute_order))
}

fn xml_error(message: &str, tokens: Vec<XmlToken>) -> XmlDocument {
    XmlDocument {
        tokens,
        error: Some(message.to_string()),
    }
}

fn find_byte(bytes: &[u8], needle: u8, start: usize) -> Option<usize> {
    bytes
        .get(start..)
        .and_then(|tail| tail.iter().position(|byte| *byte == needle))
        .map(|position| position + start)
}

fn skip_ascii_whitespace(bytes: &[u8], mut cursor: usize) -> usize {
    while cursor < bytes.len() && bytes[cursor].is_ascii_whitespace() {
        cursor += 1;
    }
    cursor
}

fn span_name(bytes: &[u8], mut cursor: usize) -> usize {
    while cursor < bytes.len()
        && (bytes[cursor].is_ascii_alphanumeric()
            || bytes[cursor] >= 0x80
            || matches!(bytes[cursor], b':' | b'_' | b'-' | b'.'))
    {
        cursor += 1;
    }
    cursor
}

#[cfg(feature = "php-extension")]
fn is_xml_unprefixed_name(name: &str) -> bool {
    !name.is_empty()
        && !name.as_bytes().contains(&b':')
        && span_name(name.as_bytes(), 0) == name.len()
}

#[cfg(feature = "php-extension")]
fn xml_tag_name_end_at(bytes: &[u8], token_start: usize) -> Option<usize> {
    if bytes.get(token_start) != Some(&b'<') || bytes.get(token_start + 1) == Some(&b'/') {
        return None;
    }

    let name_start = token_start + 1;
    let name_end = span_name(bytes, name_start);
    if name_end == name_start {
        return None;
    }

    Some(name_end)
}

#[cfg(feature = "php-extension")]
fn escape_xml_attribute_value(value: &str) -> String {
    let mut escaped = String::with_capacity(value.len());
    for character in value.chars() {
        match character {
            '&' => escaped.push_str("&amp;"),
            '<' => escaped.push_str("&lt;"),
            '>' => escaped.push_str("&gt;"),
            '"' => escaped.push_str("&quot;"),
            _ => escaped.push(character),
        }
    }

    escaped
}

#[cfg(feature = "php-extension")]
fn escape_xml_text_value(value: &str) -> String {
    let mut escaped = String::with_capacity(value.len());
    for character in value.chars() {
        match character {
            '&' => escaped.push_str("&amp;"),
            '<' => escaped.push_str("&lt;"),
            '>' => escaped.push_str("&gt;"),
            _ => escaped.push(character),
        }
    }

    escaped
}

fn parse_doctype_parts(
    bytes: &[u8],
    token_start: usize,
) -> (Option<String>, Option<String>, Option<String>) {
    if bytes.len() < token_start + 9 {
        return (None, None, None);
    }

    let name_start = skip_ascii_whitespace(bytes, token_start + 9);
    let name_end = span_name(bytes, name_start);
    if name_end == name_start {
        return (None, None, None);
    }

    let name = Some(String::from_utf8_lossy(&bytes[name_start..name_end]).into_owned());
    let mut cursor = skip_ascii_whitespace(bytes, name_end);

    if bytes.get(cursor..cursor + 6) == Some(b"SYSTEM") {
        cursor = skip_ascii_whitespace(bytes, cursor + 6);
        let system = parse_quoted_xml_literal(bytes, cursor).map(|(literal, _)| literal);

        return (name, system, None);
    }

    if bytes.get(cursor..cursor + 6) == Some(b"PUBLIC") {
        cursor = skip_ascii_whitespace(bytes, cursor + 6);
        let (pubid, next) = match parse_quoted_xml_literal(bytes, cursor) {
            Some((pubid, next)) => (Some(pubid), next),
            None => return (name, None, None),
        };
        cursor = skip_ascii_whitespace(bytes, next);
        let system = parse_quoted_xml_literal(bytes, cursor).map(|(literal, _)| literal);

        return (name, system, pubid);
    }

    (name, None, None)
}

fn parse_quoted_xml_literal(bytes: &[u8], cursor: usize) -> Option<(String, usize)> {
    let quote = *bytes.get(cursor)?;
    if quote != b'\'' && quote != b'"' {
        return None;
    }

    let value_start = cursor + 1;
    let value_end = find_byte(bytes, quote, value_start)?;

    Some((
        String::from_utf8_lossy(&bytes[value_start..value_end]).into_owned(),
        value_end + 1,
    ))
}

fn split_qualified_name(name: &str) -> (Option<&str>, &str) {
    match name.find(':') {
        Some(index) => (Some(&name[..index]), &name[index + 1..]),
        None => (None, name),
    }
}

fn extend_xml_namespaces(
    current_namespaces: &Rc<HashMap<String, String>>,
    namespace_declarations: HashMap<String, String>,
) -> Rc<HashMap<String, String>> {
    if namespace_declarations
        .iter()
        .all(|(prefix, namespace)| current_namespaces.get(prefix) == Some(namespace))
    {
        return Rc::clone(current_namespaces);
    }

    let mut extended_namespaces = (**current_namespaces).clone();
    extended_namespaces.extend(namespace_declarations);
    Rc::new(extended_namespaces)
}

fn split_resolved_attribute_name(name: &str) -> (&str, &str) {
    if !name.starts_with('{') {
        return ("", name);
    }

    match name.find('}') {
        Some(index) => (&name[1..index], &name[index + 1..]),
        None => ("", name),
    }
}

#[derive(Debug, PartialEq, Eq)]
struct XmlAttributePrefixSummary {
    token_count: i64,
    tag_count: i64,
    attribute_count: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlAttributePrefixScan {
    summary: XmlAttributePrefixSummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlAttributePrefixRemovalScan {
    summary: XmlAttributePrefixSummary,
    removals: Vec<(usize, usize)>,
    xml: String,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlTokenStreamSummary {
    token_count: i64,
    tag_count: i64,
    attribute_count: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlTokenStreamScan {
    summary: XmlTokenStreamSummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlDocumentInventorySummary {
    token_count: i64,
    tag_count: i64,
    closing_tag_count: i64,
    text_token_count: i64,
    comment_count: i64,
    cdata_count: i64,
    max_depth: i64,
    empty_element_count: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlDocumentInventoryScan {
    summary: XmlDocumentInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlElementInventorySummary {
    token_count: i64,
    tag_count: i64,
    closing_tag_count: i64,
    unique_tag_name_count: i64,
    duplicate_tag_name_count: i64,
    namespaced_tag_count: i64,
    empty_element_count: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlElementInventoryScan {
    summary: XmlElementInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlDepthInventorySummary {
    token_count: i64,
    tag_count: i64,
    closing_tag_count: i64,
    empty_element_count: i64,
    root_level_tag_count: i64,
    nested_tag_count: i64,
    total_tag_depth: i64,
    max_depth: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlDepthInventoryScan {
    summary: XmlDepthInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlAttributeInventorySummary {
    token_count: i64,
    tag_count: i64,
    attribute_count: i64,
    namespaced_attribute_count: i64,
    tags_with_attributes_count: i64,
    max_attribute_count: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlAttributeInventoryScan {
    summary: XmlAttributeInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlIdInventorySummary {
    token_count: i64,
    tag_count: i64,
    id_attribute_count: i64,
    unique_id_count: i64,
    duplicate_id_count: i64,
    id_value_bytes: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlIdInventoryScan {
    summary: XmlIdInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlNamespaceInventorySummary {
    token_count: i64,
    tag_count: i64,
    namespaced_tag_count: i64,
    attribute_count: i64,
    namespaced_attribute_count: i64,
    unique_namespace_count: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlNamespaceInventoryScan {
    summary: XmlNamespaceInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlTextInventorySummary {
    token_count: i64,
    text_token_count: i64,
    cdata_count: i64,
    non_empty_text_count: i64,
    whitespace_text_count: i64,
    total_text_bytes: i64,
    max_text_bytes: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlTextInventoryScan {
    summary: XmlTextInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlProcessingInstructionInventorySummary {
    token_count: i64,
    processing_instruction_count: i64,
    xml_declaration_count: i64,
    non_empty_instruction_count: i64,
    total_instruction_bytes: i64,
    max_instruction_bytes: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlProcessingInstructionInventoryScan {
    summary: XmlProcessingInstructionInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlCommentInventorySummary {
    token_count: i64,
    comment_count: i64,
    non_empty_comment_count: i64,
    empty_comment_count: i64,
    total_comment_bytes: i64,
    max_comment_bytes: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlCommentInventoryScan {
    summary: XmlCommentInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlPayloadInventorySummary {
    token_count: i64,
    text_token_count: i64,
    cdata_count: i64,
    comment_count: i64,
    processing_instruction_count: i64,
    total_payload_bytes: i64,
    max_payload_bytes: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlPayloadInventoryScan {
    summary: XmlPayloadInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlContentInventorySummary {
    token_count: i64,
    tag_count: i64,
    attribute_count: i64,
    text_token_count: i64,
    cdata_count: i64,
    comment_count: i64,
    processing_instruction_count: i64,
    total_attribute_value_bytes: i64,
    max_attribute_value_bytes: i64,
    total_payload_bytes: i64,
    max_payload_bytes: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlContentInventoryScan {
    summary: XmlContentInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlLeafInventorySummary {
    token_count: i64,
    tag_count: i64,
    closing_tag_count: i64,
    empty_element_count: i64,
    leaf_element_count: i64,
    branch_element_count: i64,
    max_child_element_count: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlLeafInventoryScan {
    summary: XmlLeafInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlStructuralInventorySummary {
    token_count: i64,
    tag_count: i64,
    closing_tag_count: i64,
    unique_tag_name_count: i64,
    duplicate_tag_name_count: i64,
    namespaced_tag_count: i64,
    empty_element_count: i64,
    root_level_tag_count: i64,
    nested_tag_count: i64,
    total_tag_depth: i64,
    max_depth: i64,
    leaf_element_count: i64,
    branch_element_count: i64,
    max_child_element_count: i64,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlStructuralInventoryScan {
    summary: XmlStructuralInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlImportInventorySummary {
    structural: XmlStructuralInventorySummary,
    content: XmlContentInventorySummary,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlImportInventoryScan {
    summary: XmlImportInventorySummary,
    error: Option<String>,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlSourceAttribute {
    name: String,
    start: usize,
    end: usize,
}

#[derive(Debug, PartialEq, Eq)]
struct XmlSourceTagAttributes {
    attributes: Vec<XmlSourceAttribute>,
    namespace_declarations: HashMap<String, String>,
    self_closing: bool,
    cursor: usize,
}

fn parse_xml_source_tag_attributes(
    bytes: &[u8],
    mut cursor: usize,
    closing: bool,
) -> Result<XmlSourceTagAttributes, &'static str> {
    let mut attributes = Vec::new();
    let mut seen_attribute_names = HashSet::new();
    let mut namespace_declarations = HashMap::new();
    let mut self_closing = false;

    while cursor < bytes.len() {
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= bytes.len() {
            return Err("Unclosed XML tag.");
        }
        if bytes[cursor] == b'>' {
            break;
        }
        if closing {
            return Err("Invalid closing tag encountered.");
        }
        if bytes[cursor] == b'/' && cursor + 1 < bytes.len() && bytes[cursor + 1] == b'>' {
            self_closing = true;
            cursor += 1;
            break;
        }

        let attr_start = cursor;
        cursor = span_name(bytes, cursor);
        if cursor == attr_start {
            return Err("Expected XML attribute name.");
        }

        let attr_name = String::from_utf8_lossy(&bytes[attr_start..cursor]).into_owned();
        if !seen_attribute_names.insert(attr_name.clone()) {
            return Err("Duplicate XML attribute encountered.");
        }

        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= bytes.len() || bytes[cursor] != b'=' {
            return Err("Expected `=` after XML attribute name.");
        }

        cursor += 1;
        cursor = skip_ascii_whitespace(bytes, cursor);
        if cursor >= bytes.len() || (bytes[cursor] != b'"' && bytes[cursor] != b'\'') {
            return Err("Expected quoted XML attribute value.");
        }

        let quote = bytes[cursor];
        let value_start = cursor + 1;
        let value_end = match find_byte(bytes, quote, value_start) {
            Some(value_end) => value_end,
            None => return Err("Unclosed XML attribute value."),
        };
        if bytes[value_start..value_end].contains(&b'<') {
            return Err("Disallowed character in XML attribute value.");
        }
        if attr_name == "xmlns" || attr_name.starts_with("xmlns:") {
            namespace_declarations.insert(
                attr_name.clone(),
                decode_xml_value(&String::from_utf8_lossy(&bytes[value_start..value_end])),
            );
        }

        attributes.push(XmlSourceAttribute {
            name: attr_name,
            start: attr_start,
            end: value_end + 1,
        });
        cursor = value_end + 1;
    }

    if cursor >= bytes.len() {
        return Err("Unclosed XML tag.");
    }

    Ok(XmlSourceTagAttributes {
        attributes,
        namespace_declarations,
        self_closing,
        cursor,
    })
}

fn resolve_xml_source_namespace_declarations(
    namespace_declarations: &HashMap<String, String>,
) -> Result<HashMap<String, String>, String> {
    let mut declared_namespaces = HashMap::new();
    for (attr_name, attr_value) in namespace_declarations {
        if attr_name == "xmlns" {
            declared_namespaces.insert(String::new(), attr_value.clone());
        } else if let Some(prefix) = attr_name.strip_prefix("xmlns:") {
            if attr_value.is_empty() {
                return Err(format!(
                    "Invalid XML namespace declaration for `{}`.",
                    attr_name
                ));
            }
            if prefix == "xmlns"
                || (prefix == "xml" && attr_value != "http://www.w3.org/XML/1998/namespace")
            {
                return Err("Invalid reserved XML namespace declaration.".to_string());
            }
            declared_namespaces.insert(prefix.to_string(), attr_value.clone());
        }
    }

    Ok(declared_namespaces)
}

fn summarize_xml_source_attribute_names_with_prefix(
    xml: &str,
    full_namespace_prefix: Option<&str>,
    local_name_prefix: &str,
) -> XmlAttributePrefixScan {
    let bytes = xml.as_bytes();
    let mut summary = XmlAttributePrefixSummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
    };

    if bytes.starts_with(&[0xef, 0xbb, 0xbf]) {
        return xml_summary_error("Unexpected UTF-8 BOM byte sequence.", summary);
    }

    let mut stack = Vec::new();
    let mut root_namespaces = HashMap::new();
    root_namespaces.insert(
        "xml".to_string(),
        "http://www.w3.org/XML/1998/namespace".to_string(),
    );
    let mut namespace_stack = vec![root_namespaces];
    let mut offset = 0;
    let mut root_seen = false;

    while let Some(open) = find_byte(bytes, b'<', offset) {
        if offset < open {
            let text = &bytes[offset..open];
            if root_seen && stack.is_empty() && !is_xml_whitespace_bytes(text) {
                return xml_summary_error(
                    "Unexpected non-whitespace text after root element.",
                    summary,
                );
            }

            if !text.is_empty() {
                summary.token_count += 1;
            }
        }

        let mut cursor = open + 1;
        if cursor >= bytes.len() {
            return xml_summary_error("Unexpected end of input after `<`.", summary);
        }

        if bytes[cursor] == b'!'
            && cursor + 2 < bytes.len()
            && bytes[cursor + 1] == b'-'
            && bytes[cursor + 2] == b'-'
        {
            let comment_start = cursor + 3;
            let comment_end = match find_xml_comment_end(bytes, comment_start) {
                Some(comment_end) => comment_end,
                None => return xml_summary_error("Unclosed comment.", summary),
            };
            if is_malformed_xml_comment(&bytes[comment_start..comment_end]) {
                return xml_summary_error("Malformed XML comment.", summary);
            }

            summary.token_count += 1;
            offset = comment_end + 3;
            continue;
        }

        if bytes[cursor] == b'!'
            && cursor + 8 < bytes.len()
            && &bytes[cursor + 1..cursor + 8] == b"[CDATA["
        {
            if root_seen && stack.is_empty() {
                return xml_summary_error("Unexpected CDATA section after root element.", summary);
            }

            let cdata_start = cursor + 8;
            let cdata_end = match find_xml_cdata_end(bytes, cdata_start) {
                Some(cdata_end) => cdata_end,
                None => return xml_summary_error("Unclosed CDATA section.", summary),
            };

            summary.token_count += 1;
            offset = cdata_end + 3;
            continue;
        }

        if cursor == 1 && bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let declaration_start = cursor + 1;
            let declaration_end =
                match find_xml_processing_instruction_end(bytes, declaration_start) {
                    Some(declaration_end) => declaration_end,
                    None => return xml_summary_error("XML declaration closer not found.", summary),
                };

            if parse_xml_attributes(bytes, declaration_start + 3, declaration_end).is_none() {
                return xml_summary_error("Invalid attribute found in XML declaration.", summary);
            }

            summary.token_count += 1;
            offset = declaration_end + 2;
            continue;
        }

        if bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let instruction_start = cursor + 1;
            let instruction_end =
                match find_xml_processing_instruction_end(bytes, instruction_start) {
                    Some(instruction_end) => instruction_end,
                    None => {
                        return xml_summary_error(
                            "Processing instruction closer not found.",
                            summary,
                        )
                    }
                };

            summary.token_count += 1;
            offset = instruction_end + 2;
            continue;
        }

        if bytes[cursor] == b'!' && is_ascii_case_insensitive_prefix(bytes, cursor + 1, b"DOCTYPE")
        {
            let doctype_end = match find_byte(bytes, b'>', cursor + 8) {
                Some(doctype_end) => doctype_end,
                None => return xml_summary_error("Unclosed DOCTYPE declaration.", summary),
            };

            summary.token_count += 1;
            offset = doctype_end + 1;
            continue;
        }

        if bytes[cursor] == b'?' {
            return xml_summary_error("Unsupported processing instruction.", summary);
        }

        if bytes[cursor] == b'!' {
            offset = match find_byte(bytes, b'>', cursor) {
                Some(close) => close + 1,
                None => return xml_summary_error("Unclosed declaration.", summary),
            };
            continue;
        }

        let closing = bytes[cursor] == b'/';
        if closing {
            cursor += 1;
        }

        cursor = skip_ascii_whitespace(bytes, cursor);
        let name_start = cursor;
        cursor = span_name(bytes, cursor);
        if cursor == name_start {
            return xml_summary_error("Expected XML tag name.", summary);
        }

        let name = String::from_utf8_lossy(&bytes[name_start..cursor]).into_owned();
        let tag_attributes = match parse_xml_source_tag_attributes(bytes, cursor, closing) {
            Ok(tag_attributes) => tag_attributes,
            Err(message) => return xml_summary_error(message, summary),
        };
        cursor = tag_attributes.cursor;

        let declared_namespaces =
            match resolve_xml_source_namespace_declarations(&tag_attributes.namespace_declarations)
            {
                Ok(declared_namespaces) => declared_namespaces,
                Err(message) => return xml_summary_error(&message, summary),
            };

        let mut namespaces = namespace_stack.last().cloned().unwrap_or_default();
        if !closing {
            namespaces.extend(declared_namespaces);
        }

        let (prefix, _) = split_qualified_name(&name);
        let namespace = match prefix {
            Some(prefix) => namespaces.get(prefix).cloned(),
            None => namespaces.get("").cloned(),
        };
        if prefix.is_some() && namespace.is_none() {
            return xml_summary_error("Undeclared XML namespace prefix.", summary);
        }

        let mut seen_resolved_attribute_names = HashSet::new();
        if !closing {
            if stack.is_empty() && root_seen {
                return xml_summary_error("Unexpected element after root element.", summary);
            }

            if stack.is_empty() {
                root_seen = true;
            }

            summary.tag_count += 1;
        }

        for attribute in &tag_attributes.attributes {
            let attr_name = &attribute.name;
            if attr_name == "xmlns" || attr_name.starts_with("xmlns:") {
                continue;
            }

            let (attr_prefix, attr_local_name) = split_qualified_name(attr_name);
            let (attribute_namespace, resolved_name) = if let Some(attr_prefix) = attr_prefix {
                let attr_namespace = match namespaces.get(attr_prefix) {
                    Some(attr_namespace) => attr_namespace,
                    None => {
                        return xml_summary_error(
                            "Undeclared XML attribute namespace prefix.",
                            summary,
                        )
                    }
                };
                (
                    attr_namespace.as_str(),
                    format!("{{{}}}{}", attr_namespace, attr_local_name),
                )
            } else {
                ("", attr_name.clone())
            };

            if !seen_resolved_attribute_names.insert(resolved_name) {
                return xml_summary_error("Duplicate XML attribute encountered.", summary);
            }

            if closing || !attr_local_name.starts_with(local_name_prefix) {
                continue;
            }

            match full_namespace_prefix {
                Some(prefix) if attribute_namespace.starts_with(prefix) => {
                    summary.attribute_count += 1
                }
                None if attribute_namespace.is_empty() => summary.attribute_count += 1,
                _ => {}
            }
        }

        if closing {
            match stack.pop() {
                Some(open_name) if open_name == name => {}
                Some(open_name) => {
                    return xml_summary_error(
                        &format!(
                            "Mismatched closing tag `{}` expected `{}`.",
                            name, open_name
                        ),
                        summary,
                    )
                }
                None => return xml_summary_error("Closing tag without an open element.", summary),
            }
            if namespace_stack.len() > 1 {
                namespace_stack.pop();
            }
        } else if !tag_attributes.self_closing {
            stack.push(name);
            namespace_stack.push(namespaces);
        }

        summary.token_count += 1;
        offset = match find_byte(bytes, b'>', cursor) {
            Some(close) => close + 1,
            None => return xml_summary_error("Unclosed XML tag.", summary),
        };
    }

    if offset < bytes.len() {
        let text = &bytes[offset..];
        if root_seen && stack.is_empty() && !is_xml_whitespace_bytes(text) {
            return xml_summary_error(
                "Unexpected non-whitespace text after root element.",
                summary,
            );
        }

        if !text.is_empty() {
            summary.token_count += 1;
        }
    }

    if let Some(open_name) = stack.pop() {
        return xml_summary_error(&format!("Unclosed XML element `{}`.", open_name), summary);
    }

    XmlAttributePrefixScan {
        summary,
        error: None,
    }
}

fn summarize_xml_source_token_stream(xml: &str, attribute_name: &str) -> XmlTokenStreamScan {
    summarize_xml_source_stream(xml, attribute_name, true, None)
}

fn summarize_xml_source_document_inventory(xml: &str) -> XmlDocumentInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlDocumentInventorySummary {
        token_count: 0,
        tag_count: 0,
        closing_tag_count: 0,
        text_token_count: 0,
        comment_count: 0,
        cdata_count: 0,
        max_depth: 0,
        empty_element_count: 0,
    };

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => summarize_xml_document_inventory_token(&token, &mut summary),
            Ok(None) => {
                return XmlDocumentInventoryScan {
                    summary,
                    error: None,
                }
            }
            Err(error) => {
                return XmlDocumentInventoryScan {
                    summary,
                    error: Some(error),
                }
            }
        }
    }
}

fn summarize_xml_source_element_inventory(xml: &str) -> XmlElementInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlElementInventorySummary {
        token_count: 0,
        tag_count: 0,
        closing_tag_count: 0,
        unique_tag_name_count: 0,
        duplicate_tag_name_count: 0,
        namespaced_tag_count: 0,
        empty_element_count: 0,
    };
    let mut tag_names = HashSet::new();

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => {
                summarize_xml_element_inventory_token(&token, &mut summary, &mut tag_names)
            }
            Ok(None) => {
                summary.unique_tag_name_count = tag_names.len() as i64;
                return XmlElementInventoryScan {
                    summary,
                    error: None,
                };
            }
            Err(error) => {
                summary.unique_tag_name_count = tag_names.len() as i64;
                return XmlElementInventoryScan {
                    summary,
                    error: Some(error),
                };
            }
        }
    }
}

fn summarize_xml_source_depth_inventory(xml: &str) -> XmlDepthInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlDepthInventorySummary {
        token_count: 0,
        tag_count: 0,
        closing_tag_count: 0,
        empty_element_count: 0,
        root_level_tag_count: 0,
        nested_tag_count: 0,
        total_tag_depth: 0,
        max_depth: 0,
    };

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => summarize_xml_depth_inventory_token(&token, &mut summary),
            Ok(None) => {
                return XmlDepthInventoryScan {
                    summary,
                    error: None,
                }
            }
            Err(error) => {
                return XmlDepthInventoryScan {
                    summary,
                    error: Some(error),
                }
            }
        }
    }
}

fn summarize_xml_source_attribute_inventory(xml: &str) -> XmlAttributeInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlAttributeInventorySummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
        namespaced_attribute_count: 0,
        tags_with_attributes_count: 0,
        max_attribute_count: 0,
    };

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => summarize_xml_attribute_inventory_token(&token, &mut summary),
            Ok(None) => {
                return XmlAttributeInventoryScan {
                    summary,
                    error: None,
                }
            }
            Err(error) => {
                return XmlAttributeInventoryScan {
                    summary,
                    error: Some(error),
                }
            }
        }
    }
}

fn summarize_xml_source_id_inventory(xml: &str) -> XmlIdInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlIdInventorySummary {
        token_count: 0,
        tag_count: 0,
        id_attribute_count: 0,
        unique_id_count: 0,
        duplicate_id_count: 0,
        id_value_bytes: 0,
    };
    let mut seen_ids = HashSet::new();
    let mut duplicate_ids = HashSet::new();

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => summarize_xml_id_inventory_token(
                &token,
                &mut summary,
                &mut seen_ids,
                &mut duplicate_ids,
            ),
            Ok(None) => {
                return XmlIdInventoryScan {
                    summary,
                    error: None,
                }
            }
            Err(error) => {
                return XmlIdInventoryScan {
                    summary,
                    error: Some(error),
                }
            }
        }
    }
}

fn summarize_xml_source_namespace_inventory(xml: &str) -> XmlNamespaceInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlNamespaceInventorySummary {
        token_count: 0,
        tag_count: 0,
        namespaced_tag_count: 0,
        attribute_count: 0,
        namespaced_attribute_count: 0,
        unique_namespace_count: 0,
    };
    let mut namespaces = HashSet::new();

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => {
                summarize_xml_namespace_inventory_token(&token, &mut summary, &mut namespaces)
            }
            Ok(None) => {
                summary.unique_namespace_count = namespaces.len() as i64;
                return XmlNamespaceInventoryScan {
                    summary,
                    error: None,
                };
            }
            Err(error) => {
                summary.unique_namespace_count = namespaces.len() as i64;
                return XmlNamespaceInventoryScan {
                    summary,
                    error: Some(error),
                };
            }
        }
    }
}

fn summarize_xml_source_text_inventory(xml: &str) -> XmlTextInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlTextInventorySummary {
        token_count: 0,
        text_token_count: 0,
        cdata_count: 0,
        non_empty_text_count: 0,
        whitespace_text_count: 0,
        total_text_bytes: 0,
        max_text_bytes: 0,
    };

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => summarize_xml_text_inventory_token(&token, &mut summary),
            Ok(None) => {
                return XmlTextInventoryScan {
                    summary,
                    error: None,
                }
            }
            Err(error) => {
                return XmlTextInventoryScan {
                    summary,
                    error: Some(error),
                }
            }
        }
    }
}

fn summarize_xml_source_processing_instruction_inventory(
    xml: &str,
) -> XmlProcessingInstructionInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlProcessingInstructionInventorySummary {
        token_count: 0,
        processing_instruction_count: 0,
        xml_declaration_count: 0,
        non_empty_instruction_count: 0,
        total_instruction_bytes: 0,
        max_instruction_bytes: 0,
    };

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => {
                summarize_xml_processing_instruction_inventory_token(&token, &mut summary)
            }
            Ok(None) => {
                return XmlProcessingInstructionInventoryScan {
                    summary,
                    error: None,
                }
            }
            Err(error) => {
                return XmlProcessingInstructionInventoryScan {
                    summary,
                    error: Some(error),
                }
            }
        }
    }
}

fn summarize_xml_source_comment_inventory(xml: &str) -> XmlCommentInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlCommentInventorySummary {
        token_count: 0,
        comment_count: 0,
        non_empty_comment_count: 0,
        empty_comment_count: 0,
        total_comment_bytes: 0,
        max_comment_bytes: 0,
    };

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => summarize_xml_comment_inventory_token(&token, &mut summary),
            Ok(None) => {
                return XmlCommentInventoryScan {
                    summary,
                    error: None,
                }
            }
            Err(error) => {
                return XmlCommentInventoryScan {
                    summary,
                    error: Some(error),
                }
            }
        }
    }
}

fn summarize_xml_source_payload_inventory(xml: &str) -> XmlPayloadInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlPayloadInventorySummary {
        token_count: 0,
        text_token_count: 0,
        cdata_count: 0,
        comment_count: 0,
        processing_instruction_count: 0,
        total_payload_bytes: 0,
        max_payload_bytes: 0,
    };

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => summarize_xml_payload_inventory_token(&token, &mut summary),
            Ok(None) => {
                return XmlPayloadInventoryScan {
                    summary,
                    error: None,
                }
            }
            Err(error) => {
                return XmlPayloadInventoryScan {
                    summary,
                    error: Some(error),
                }
            }
        }
    }
}

fn summarize_xml_source_content_inventory(xml: &str) -> XmlContentInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlContentInventorySummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
        text_token_count: 0,
        cdata_count: 0,
        comment_count: 0,
        processing_instruction_count: 0,
        total_attribute_value_bytes: 0,
        max_attribute_value_bytes: 0,
        total_payload_bytes: 0,
        max_payload_bytes: 0,
    };

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => summarize_xml_content_inventory_token(&token, &mut summary),
            Ok(None) => {
                return XmlContentInventoryScan {
                    summary,
                    error: None,
                }
            }
            Err(error) => {
                return XmlContentInventoryScan {
                    summary,
                    error: Some(error),
                }
            }
        }
    }
}

fn summarize_xml_source_leaf_inventory(xml: &str) -> XmlLeafInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = XmlLeafInventorySummary {
        token_count: 0,
        tag_count: 0,
        closing_tag_count: 0,
        empty_element_count: 0,
        leaf_element_count: 0,
        branch_element_count: 0,
        max_child_element_count: 0,
    };
    let mut open_child_counts = Vec::new();

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => {
                summarize_xml_leaf_inventory_token(&token, &mut summary, &mut open_child_counts);
            }
            Ok(None) => {
                return XmlLeafInventoryScan {
                    summary,
                    error: None,
                }
            }
            Err(error) => {
                return XmlLeafInventoryScan {
                    summary,
                    error: Some(error),
                }
            }
        }
    }
}

fn summarize_xml_source_structural_inventory(xml: &str) -> XmlStructuralInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = empty_xml_structural_inventory_summary();
    let mut tag_names = HashSet::new();
    let mut open_child_counts = Vec::new();

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => {
                summarize_xml_structural_inventory_token(
                    &token,
                    &mut summary,
                    &mut tag_names,
                    &mut open_child_counts,
                );
            }
            Ok(None) => {
                summary.unique_tag_name_count = tag_names.len() as i64;
                return XmlStructuralInventoryScan {
                    summary,
                    error: None,
                };
            }
            Err(error) => {
                summary.unique_tag_name_count = tag_names.len() as i64;
                return XmlStructuralInventoryScan {
                    summary,
                    error: Some(error),
                };
            }
        }
    }
}

fn summarize_xml_source_import_inventory(xml: &str) -> XmlImportInventoryScan {
    let mut state = XmlStreamState::new();
    let mut summary = empty_xml_import_inventory_summary();
    let mut tag_names = HashSet::new();
    let mut open_child_counts = Vec::new();

    loop {
        match parse_next_xml_stream_token(xml, &mut state) {
            Ok(Some(token)) => {
                summarize_xml_import_inventory_token(
                    &token,
                    &mut summary,
                    &mut tag_names,
                    &mut open_child_counts,
                );
            }
            Ok(None) => {
                summary.structural.unique_tag_name_count = tag_names.len() as i64;
                return XmlImportInventoryScan {
                    summary,
                    error: None,
                };
            }
            Err(error) => {
                summary.structural.unique_tag_name_count = tag_names.len() as i64;
                return XmlImportInventoryScan {
                    summary,
                    error: Some(error),
                };
            }
        }
    }
}

fn summarize_xml_source_tag_stream(xml: &str, attribute_name: &str) -> XmlTokenStreamScan {
    summarize_xml_source_stream(xml, attribute_name, false, None)
}

fn summarize_xml_source_matching_tag_stream(
    xml: &str,
    tag_namespace: &str,
    tag_local_name: &str,
    attribute_name: &str,
) -> XmlTokenStreamScan {
    summarize_xml_source_stream(
        xml,
        attribute_name,
        false,
        Some((tag_namespace, tag_local_name)),
    )
}

fn summarize_xml_source_matching_tag_attributes_stream(
    xml: &str,
    tag_namespace: &str,
    tag_local_name: &str,
    attribute_names: &[String],
) -> XmlTokenStreamScan {
    summarize_xml_source_stream_for_attribute_names(
        xml,
        attribute_names,
        false,
        Some((tag_namespace, tag_local_name)),
    )
}

fn summarize_xml_source_stream(
    xml: &str,
    attribute_name: &str,
    include_declaration_attributes: bool,
    matching_tag_name: Option<(&str, &str)>,
) -> XmlTokenStreamScan {
    let mut attribute_names = Vec::new();
    if !attribute_name.is_empty() {
        attribute_names.push(attribute_name.to_string());
    }

    summarize_xml_source_stream_for_attribute_names(
        xml,
        &attribute_names,
        include_declaration_attributes,
        matching_tag_name,
    )
}

fn summarize_xml_source_stream_for_attribute_names(
    xml: &str,
    attribute_names: &[String],
    include_declaration_attributes: bool,
    matching_tag_name: Option<(&str, &str)>,
) -> XmlTokenStreamScan {
    let bytes = xml.as_bytes();
    let mut summary = XmlTokenStreamSummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
    };

    if bytes.starts_with(&[0xef, 0xbb, 0xbf]) {
        return xml_token_summary_error("Unexpected UTF-8 BOM byte sequence.", summary);
    }

    let mut stack = Vec::new();
    let mut root_namespaces = HashMap::new();
    root_namespaces.insert(
        "xml".to_string(),
        "http://www.w3.org/XML/1998/namespace".to_string(),
    );
    let mut namespace_stack = vec![root_namespaces];
    let mut offset = 0;
    let mut root_seen = false;

    while let Some(open) = find_byte(bytes, b'<', offset) {
        if offset < open {
            let text = &bytes[offset..open];
            if root_seen && stack.is_empty() && !is_xml_whitespace_bytes(text) {
                return xml_token_summary_error(
                    "Unexpected non-whitespace text after root element.",
                    summary,
                );
            }

            if !text.is_empty() {
                summary.token_count += 1;
            }
        }

        let mut cursor = open + 1;
        if cursor >= bytes.len() {
            return xml_token_summary_error("Unexpected end of input after `<`.", summary);
        }

        if bytes[cursor] == b'!'
            && cursor + 2 < bytes.len()
            && bytes[cursor + 1] == b'-'
            && bytes[cursor + 2] == b'-'
        {
            let comment_start = cursor + 3;
            let comment_end = match find_xml_comment_end(bytes, comment_start) {
                Some(comment_end) => comment_end,
                None => return xml_token_summary_error("Unclosed comment.", summary),
            };
            if is_malformed_xml_comment(&bytes[comment_start..comment_end]) {
                return xml_token_summary_error("Malformed XML comment.", summary);
            }

            summary.token_count += 1;
            offset = comment_end + 3;
            continue;
        }

        if bytes[cursor] == b'!'
            && cursor + 8 < bytes.len()
            && &bytes[cursor + 1..cursor + 8] == b"[CDATA["
        {
            if root_seen && stack.is_empty() {
                return xml_token_summary_error(
                    "Unexpected CDATA section after root element.",
                    summary,
                );
            }

            let cdata_start = cursor + 8;
            let cdata_end = match find_xml_cdata_end(bytes, cdata_start) {
                Some(cdata_end) => cdata_end,
                None => return xml_token_summary_error("Unclosed CDATA section.", summary),
            };

            summary.token_count += 1;
            offset = cdata_end + 3;
            continue;
        }

        if cursor == 1 && bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let declaration_start = cursor + 1;
            let declaration_end =
                match find_xml_processing_instruction_end(bytes, declaration_start) {
                    Some(declaration_end) => declaration_end,
                    None => {
                        return xml_token_summary_error(
                            "XML declaration closer not found.",
                            summary,
                        )
                    }
                };

            let (attributes, _) =
                match parse_xml_attributes(bytes, declaration_start + 3, declaration_end) {
                    Some(attributes) => attributes,
                    None => {
                        return xml_token_summary_error(
                            "Invalid attribute found in XML declaration.",
                            summary,
                        )
                    }
                };

            summary.token_count += 1;
            if include_declaration_attributes {
                for attribute_name in attribute_names {
                    if attributes.contains_key(attribute_name) {
                        summary.attribute_count += 1;
                    }
                }
            }
            offset = declaration_end + 2;
            continue;
        }

        if bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let instruction_start = cursor + 1;
            let instruction_end =
                match find_xml_processing_instruction_end(bytes, instruction_start) {
                    Some(instruction_end) => instruction_end,
                    None => {
                        return xml_token_summary_error(
                            "Processing instruction closer not found.",
                            summary,
                        )
                    }
                };

            summary.token_count += 1;
            offset = instruction_end + 2;
            continue;
        }

        if bytes[cursor] == b'!' && is_ascii_case_insensitive_prefix(bytes, cursor + 1, b"DOCTYPE")
        {
            let doctype_end = match find_byte(bytes, b'>', cursor + 8) {
                Some(doctype_end) => doctype_end,
                None => return xml_token_summary_error("Unclosed DOCTYPE declaration.", summary),
            };

            summary.token_count += 1;
            offset = doctype_end + 1;
            continue;
        }

        if bytes[cursor] == b'?' {
            return xml_token_summary_error("Unsupported processing instruction.", summary);
        }

        if bytes[cursor] == b'!' {
            offset = match find_byte(bytes, b'>', cursor) {
                Some(close) => close + 1,
                None => return xml_token_summary_error("Unclosed declaration.", summary),
            };
            continue;
        }

        let closing = bytes[cursor] == b'/';
        if closing {
            cursor += 1;
        }

        cursor = skip_ascii_whitespace(bytes, cursor);
        let name_start = cursor;
        cursor = span_name(bytes, cursor);
        if cursor == name_start {
            return xml_token_summary_error("Expected XML tag name.", summary);
        }

        let name = String::from_utf8_lossy(&bytes[name_start..cursor]).into_owned();
        let tag_attributes = match parse_xml_source_tag_attributes(bytes, cursor, closing) {
            Ok(tag_attributes) => tag_attributes,
            Err(message) => return xml_token_summary_error(message, summary),
        };
        cursor = tag_attributes.cursor;

        let declared_namespaces =
            match resolve_xml_source_namespace_declarations(&tag_attributes.namespace_declarations)
            {
                Ok(declared_namespaces) => declared_namespaces,
                Err(message) => return xml_token_summary_error(&message, summary),
            };

        let mut namespaces = namespace_stack.last().cloned().unwrap_or_default();
        if !closing {
            namespaces.extend(declared_namespaces);
        }

        let (prefix, local_name) = split_qualified_name(&name);
        let namespace = match prefix {
            Some(prefix) => namespaces.get(prefix).cloned(),
            None => namespaces.get("").cloned(),
        };
        if prefix.is_some() && namespace.is_none() {
            return xml_token_summary_error("Undeclared XML namespace prefix.", summary);
        }

        let matches_tag = !closing
            && matching_tag_name
                .map(|(matching_namespace, matching_local_name)| {
                    namespace.as_deref().unwrap_or_default() == matching_namespace
                        && local_name == matching_local_name
                })
                .unwrap_or(true);
        let mut seen_resolved_attribute_names = HashSet::new();
        if !closing {
            if stack.is_empty() && root_seen {
                return xml_token_summary_error("Unexpected element after root element.", summary);
            }

            if stack.is_empty() {
                root_seen = true;
            }

            if matches_tag {
                summary.tag_count += 1;
            }
        }

        for attribute in &tag_attributes.attributes {
            let attr_name = &attribute.name;
            if attr_name == "xmlns" || attr_name.starts_with("xmlns:") {
                continue;
            }

            let (attr_prefix, attr_local_name) = split_qualified_name(attr_name);
            let resolved_name = if let Some(attr_prefix) = attr_prefix {
                let attr_namespace = match namespaces.get(attr_prefix) {
                    Some(attr_namespace) => attr_namespace,
                    None => {
                        return xml_token_summary_error(
                            "Undeclared XML attribute namespace prefix.",
                            summary,
                        )
                    }
                };
                format!("{{{}}}{}", attr_namespace, attr_local_name)
            } else {
                attr_name.clone()
            };

            if !seen_resolved_attribute_names.insert(resolved_name.clone()) {
                return xml_token_summary_error("Duplicate XML attribute encountered.", summary);
            }

            if matches_tag && attribute_names_contains(attribute_names, &resolved_name) {
                summary.attribute_count += 1;
            }
        }

        if closing {
            match stack.pop() {
                Some(open_name) if open_name == name => {}
                Some(open_name) => {
                    return xml_token_summary_error(
                        &format!(
                            "Mismatched closing tag `{}` expected `{}`.",
                            name, open_name
                        ),
                        summary,
                    )
                }
                None => {
                    return xml_token_summary_error("Closing tag without an open element.", summary)
                }
            }
            if namespace_stack.len() > 1 {
                namespace_stack.pop();
            }
        } else if !tag_attributes.self_closing {
            stack.push(name);
            namespace_stack.push(namespaces);
        }

        summary.token_count += 1;
        offset = match find_byte(bytes, b'>', cursor) {
            Some(close) => close + 1,
            None => return xml_token_summary_error("Unclosed XML tag.", summary),
        };
    }

    if offset < bytes.len() {
        let text = &bytes[offset..];
        if root_seen && stack.is_empty() && !is_xml_whitespace_bytes(text) {
            return xml_token_summary_error(
                "Unexpected non-whitespace text after root element.",
                summary,
            );
        }

        if !text.is_empty() {
            summary.token_count += 1;
        }
    }

    if let Some(open_name) = stack.pop() {
        return xml_token_summary_error(&format!("Unclosed XML element `{}`.", open_name), summary);
    }

    XmlTokenStreamScan {
        summary,
        error: None,
    }
}

fn remove_xml_source_attributes_with_prefix(
    xml: &str,
    full_namespace_prefix: Option<&str>,
    local_name_prefix: &str,
) -> XmlAttributePrefixRemovalScan {
    let bytes = xml.as_bytes();
    let mut summary = XmlAttributePrefixSummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
    };
    let mut removals = Vec::new();

    if bytes.starts_with(&[0xef, 0xbb, 0xbf]) {
        return xml_removal_error(
            "Unexpected UTF-8 BOM byte sequence.",
            xml,
            summary,
            removals,
        );
    }

    let mut stack = Vec::new();
    let mut root_namespaces = HashMap::new();
    root_namespaces.insert(
        "xml".to_string(),
        "http://www.w3.org/XML/1998/namespace".to_string(),
    );
    let mut namespace_stack = vec![root_namespaces];
    let mut offset = 0;
    let mut root_seen = false;

    while let Some(open) = find_byte(bytes, b'<', offset) {
        if offset < open {
            let text = &bytes[offset..open];
            if root_seen && stack.is_empty() && !is_xml_whitespace_bytes(text) {
                return xml_removal_error(
                    "Unexpected non-whitespace text after root element.",
                    xml,
                    summary,
                    removals,
                );
            }

            if !text.is_empty() {
                summary.token_count += 1;
            }
        }

        let mut cursor = open + 1;
        if cursor >= bytes.len() {
            return xml_removal_error("Unexpected end of input after `<`.", xml, summary, removals);
        }

        if bytes[cursor] == b'!'
            && cursor + 2 < bytes.len()
            && bytes[cursor + 1] == b'-'
            && bytes[cursor + 2] == b'-'
        {
            let comment_start = cursor + 3;
            let comment_end = match find_xml_comment_end(bytes, comment_start) {
                Some(comment_end) => comment_end,
                None => return xml_removal_error("Unclosed comment.", xml, summary, removals),
            };
            if is_malformed_xml_comment(&bytes[comment_start..comment_end]) {
                return xml_removal_error("Malformed XML comment.", xml, summary, removals);
            }

            summary.token_count += 1;
            offset = comment_end + 3;
            continue;
        }

        if bytes[cursor] == b'!'
            && cursor + 8 < bytes.len()
            && &bytes[cursor + 1..cursor + 8] == b"[CDATA["
        {
            if root_seen && stack.is_empty() {
                return xml_removal_error(
                    "Unexpected CDATA section after root element.",
                    xml,
                    summary,
                    removals,
                );
            }

            let cdata_start = cursor + 8;
            let cdata_end = match find_xml_cdata_end(bytes, cdata_start) {
                Some(cdata_end) => cdata_end,
                None => {
                    return xml_removal_error("Unclosed CDATA section.", xml, summary, removals)
                }
            };

            summary.token_count += 1;
            offset = cdata_end + 3;
            continue;
        }

        if cursor == 1 && bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let declaration_start = cursor + 1;
            let declaration_end =
                match find_xml_processing_instruction_end(bytes, declaration_start) {
                    Some(declaration_end) => declaration_end,
                    None => {
                        return xml_removal_error(
                            "XML declaration closer not found.",
                            xml,
                            summary,
                            removals,
                        )
                    }
                };

            if parse_xml_attributes(bytes, declaration_start + 3, declaration_end).is_none() {
                return xml_removal_error(
                    "Invalid attribute found in XML declaration.",
                    xml,
                    summary,
                    removals,
                );
            }

            summary.token_count += 1;
            offset = declaration_end + 2;
            continue;
        }

        if bytes[cursor] == b'?' && is_xml_declaration(bytes, cursor + 1) {
            let instruction_start = cursor + 1;
            let instruction_end =
                match find_xml_processing_instruction_end(bytes, instruction_start) {
                    Some(instruction_end) => instruction_end,
                    None => {
                        return xml_removal_error(
                            "Processing instruction closer not found.",
                            xml,
                            summary,
                            removals,
                        )
                    }
                };

            summary.token_count += 1;
            offset = instruction_end + 2;
            continue;
        }

        if bytes[cursor] == b'!' && is_ascii_case_insensitive_prefix(bytes, cursor + 1, b"DOCTYPE")
        {
            let doctype_end = match find_byte(bytes, b'>', cursor + 8) {
                Some(doctype_end) => doctype_end,
                None => {
                    return xml_removal_error(
                        "Unclosed DOCTYPE declaration.",
                        xml,
                        summary,
                        removals,
                    )
                }
            };

            summary.token_count += 1;
            offset = doctype_end + 1;
            continue;
        }

        if bytes[cursor] == b'?' {
            return xml_removal_error(
                "Unsupported processing instruction.",
                xml,
                summary,
                removals,
            );
        }

        if bytes[cursor] == b'!' {
            offset = match find_byte(bytes, b'>', cursor) {
                Some(close) => close + 1,
                None => return xml_removal_error("Unclosed declaration.", xml, summary, removals),
            };
            continue;
        }

        let closing = bytes[cursor] == b'/';
        if closing {
            cursor += 1;
        }

        cursor = skip_ascii_whitespace(bytes, cursor);
        let name_start = cursor;
        cursor = span_name(bytes, cursor);
        if cursor == name_start {
            return xml_removal_error("Expected XML tag name.", xml, summary, removals);
        }

        let name = String::from_utf8_lossy(&bytes[name_start..cursor]).into_owned();
        let tag_attributes = match parse_xml_source_tag_attributes(bytes, cursor, closing) {
            Ok(tag_attributes) => tag_attributes,
            Err(message) => {
                return xml_removal_error(message, xml, summary, removals);
            }
        };
        cursor = tag_attributes.cursor;

        let declared_namespaces =
            match resolve_xml_source_namespace_declarations(&tag_attributes.namespace_declarations)
            {
                Ok(declared_namespaces) => declared_namespaces,
                Err(message) => {
                    return xml_removal_error(&message, xml, summary, removals);
                }
            };

        let mut namespaces = namespace_stack.last().cloned().unwrap_or_default();
        if !closing {
            namespaces.extend(declared_namespaces);
        }

        let (prefix, _) = split_qualified_name(&name);
        let namespace = match prefix {
            Some(prefix) => namespaces.get(prefix).cloned(),
            None => namespaces.get("").cloned(),
        };
        if prefix.is_some() && namespace.is_none() {
            return xml_removal_error("Undeclared XML namespace prefix.", xml, summary, removals);
        }

        let mut seen_resolved_attribute_names = HashSet::new();
        if !closing {
            if stack.is_empty() && root_seen {
                return xml_removal_error(
                    "Unexpected element after root element.",
                    xml,
                    summary,
                    removals,
                );
            }

            if stack.is_empty() {
                root_seen = true;
            }

            summary.tag_count += 1;
        }

        for attribute in &tag_attributes.attributes {
            let attr_name = &attribute.name;
            if attr_name == "xmlns" || attr_name.starts_with("xmlns:") {
                continue;
            }

            let (attr_prefix, attr_local_name) = split_qualified_name(attr_name);
            let (attribute_namespace, resolved_name) = if let Some(attr_prefix) = attr_prefix {
                let attr_namespace = match namespaces.get(attr_prefix) {
                    Some(attr_namespace) => attr_namespace,
                    None => {
                        return xml_removal_error(
                            "Undeclared XML attribute namespace prefix.",
                            xml,
                            summary,
                            removals,
                        )
                    }
                };
                (
                    attr_namespace.as_str(),
                    format!("{{{}}}{}", attr_namespace, attr_local_name),
                )
            } else {
                ("", attr_name.clone())
            };

            if !seen_resolved_attribute_names.insert(resolved_name) {
                return xml_removal_error(
                    "Duplicate XML attribute encountered.",
                    xml,
                    summary,
                    removals,
                );
            }

            if closing || !attr_local_name.starts_with(local_name_prefix) {
                continue;
            }

            let is_match = match full_namespace_prefix {
                Some(prefix) => attribute_namespace.starts_with(prefix),
                None => attribute_namespace.is_empty(),
            };
            if is_match {
                summary.attribute_count += 1;
                removals.push((
                    attribute.start,
                    attribute.end.saturating_sub(attribute.start),
                ));
            }
        }

        if closing {
            match stack.pop() {
                Some(open_name) if open_name == name => {}
                Some(open_name) => {
                    return xml_removal_error(
                        &format!(
                            "Mismatched closing tag `{}` expected `{}`.",
                            name, open_name
                        ),
                        xml,
                        summary,
                        removals,
                    )
                }
                None => {
                    return xml_removal_error(
                        "Closing tag without an open element.",
                        xml,
                        summary,
                        removals,
                    )
                }
            }
            if namespace_stack.len() > 1 {
                namespace_stack.pop();
            }
        } else if !tag_attributes.self_closing {
            stack.push(name);
            namespace_stack.push(namespaces);
        }

        summary.token_count += 1;
        offset = match find_byte(bytes, b'>', cursor) {
            Some(close) => close + 1,
            None => return xml_removal_error("Unclosed XML tag.", xml, summary, removals),
        };
    }

    if offset < bytes.len() {
        let text = &bytes[offset..];
        if root_seen && stack.is_empty() && !is_xml_whitespace_bytes(text) {
            return xml_removal_error(
                "Unexpected non-whitespace text after root element.",
                xml,
                summary,
                removals,
            );
        }

        if !text.is_empty() {
            summary.token_count += 1;
        }
    }

    if let Some(open_name) = stack.pop() {
        return xml_removal_error(
            &format!("Unclosed XML element `{}`.", open_name),
            xml,
            summary,
            removals,
        );
    }

    let updated_xml = apply_xml_text_removals(xml, &removals);
    XmlAttributePrefixRemovalScan {
        summary,
        removals,
        xml: updated_xml,
        error: None,
    }
}

fn xml_summary_error(message: &str, summary: XmlAttributePrefixSummary) -> XmlAttributePrefixScan {
    XmlAttributePrefixScan {
        summary,
        error: Some(message.to_string()),
    }
}

fn xml_token_stream_summary(summary: &XmlTokenStreamSummary) -> String {
    format!(
        "{}\x1f{}\x1f{}",
        summary.token_count, summary.tag_count, summary.attribute_count
    )
}

fn xml_document_inventory_summary(summary: &XmlDocumentInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.tag_count,
        summary.closing_tag_count,
        summary.text_token_count,
        summary.comment_count,
        summary.cdata_count,
        summary.max_depth,
        summary.empty_element_count
    )
}

fn xml_element_inventory_summary(summary: &XmlElementInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.tag_count,
        summary.closing_tag_count,
        summary.unique_tag_name_count,
        summary.duplicate_tag_name_count,
        summary.namespaced_tag_count,
        summary.empty_element_count
    )
}

fn xml_depth_inventory_summary(summary: &XmlDepthInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.tag_count,
        summary.closing_tag_count,
        summary.empty_element_count,
        summary.root_level_tag_count,
        summary.nested_tag_count,
        summary.total_tag_depth,
        summary.max_depth
    )
}

fn xml_attribute_inventory_summary(summary: &XmlAttributeInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.tag_count,
        summary.attribute_count,
        summary.namespaced_attribute_count,
        summary.tags_with_attributes_count,
        summary.max_attribute_count
    )
}

fn xml_id_inventory_summary(summary: &XmlIdInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.tag_count,
        summary.id_attribute_count,
        summary.unique_id_count,
        summary.duplicate_id_count,
        summary.id_value_bytes
    )
}

#[cfg(feature = "php-extension")]
fn xml_attribute_inventory_public_summary_row(
    summary: &XmlAttributeInventorySummary,
) -> Vec<(String, Zval)> {
    vec![
        ("token_count".to_string(), xml_zval_i64(summary.token_count)),
        ("tag_count".to_string(), xml_zval_i64(summary.tag_count)),
        (
            "attribute_count".to_string(),
            xml_zval_i64(summary.attribute_count),
        ),
        (
            "namespaced_attribute_count".to_string(),
            xml_zval_i64(summary.namespaced_attribute_count),
        ),
        (
            "tags_with_attributes_count".to_string(),
            xml_zval_i64(summary.tags_with_attributes_count),
        ),
        (
            "max_attribute_count".to_string(),
            xml_zval_i64(summary.max_attribute_count),
        ),
    ]
}

#[cfg(feature = "php-extension")]
fn xml_id_inventory_public_summary_row(summary: &XmlIdInventorySummary) -> Vec<(String, Zval)> {
    vec![
        ("token_count".to_string(), xml_zval_i64(summary.token_count)),
        ("tag_count".to_string(), xml_zval_i64(summary.tag_count)),
        (
            "id_attribute_count".to_string(),
            xml_zval_i64(summary.id_attribute_count),
        ),
        (
            "unique_id_count".to_string(),
            xml_zval_i64(summary.unique_id_count),
        ),
        (
            "duplicate_id_count".to_string(),
            xml_zval_i64(summary.duplicate_id_count),
        ),
        (
            "id_value_bytes".to_string(),
            xml_zval_i64(summary.id_value_bytes),
        ),
    ]
}

fn xml_namespace_inventory_summary(summary: &XmlNamespaceInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.tag_count,
        summary.namespaced_tag_count,
        summary.attribute_count,
        summary.namespaced_attribute_count,
        summary.unique_namespace_count
    )
}

fn xml_text_inventory_summary(summary: &XmlTextInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.text_token_count,
        summary.cdata_count,
        summary.non_empty_text_count,
        summary.whitespace_text_count,
        summary.total_text_bytes,
        summary.max_text_bytes
    )
}

fn xml_processing_instruction_inventory_summary(
    summary: &XmlProcessingInstructionInventorySummary,
) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.processing_instruction_count,
        summary.xml_declaration_count,
        summary.non_empty_instruction_count,
        summary.total_instruction_bytes,
        summary.max_instruction_bytes
    )
}

fn xml_comment_inventory_summary(summary: &XmlCommentInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.comment_count,
        summary.non_empty_comment_count,
        summary.empty_comment_count,
        summary.total_comment_bytes,
        summary.max_comment_bytes
    )
}

fn xml_payload_inventory_summary(summary: &XmlPayloadInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.text_token_count,
        summary.cdata_count,
        summary.comment_count,
        summary.processing_instruction_count,
        summary.total_payload_bytes,
        summary.max_payload_bytes
    )
}

fn xml_content_inventory_summary(summary: &XmlContentInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.tag_count,
        summary.attribute_count,
        summary.text_token_count,
        summary.cdata_count,
        summary.comment_count,
        summary.processing_instruction_count,
        summary.total_attribute_value_bytes,
        summary.max_attribute_value_bytes,
        summary.total_payload_bytes,
        summary.max_payload_bytes
    )
}

fn xml_leaf_inventory_summary(summary: &XmlLeafInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.tag_count,
        summary.closing_tag_count,
        summary.empty_element_count,
        summary.leaf_element_count,
        summary.branch_element_count,
        summary.max_child_element_count
    )
}

fn xml_structural_inventory_summary(summary: &XmlStructuralInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.token_count,
        summary.tag_count,
        summary.closing_tag_count,
        summary.unique_tag_name_count,
        summary.duplicate_tag_name_count,
        summary.namespaced_tag_count,
        summary.empty_element_count,
        summary.root_level_tag_count,
        summary.nested_tag_count,
        summary.total_tag_depth,
        summary.max_depth,
        summary.leaf_element_count,
        summary.branch_element_count,
        summary.max_child_element_count
    )
}

fn xml_import_inventory_summary(summary: &XmlImportInventorySummary) -> String {
    format!(
        "{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}\x1f{}",
        summary.structural.token_count,
        summary.structural.tag_count,
        summary.structural.closing_tag_count,
        summary.structural.unique_tag_name_count,
        summary.structural.duplicate_tag_name_count,
        summary.structural.namespaced_tag_count,
        summary.structural.empty_element_count,
        summary.structural.root_level_tag_count,
        summary.structural.nested_tag_count,
        summary.structural.total_tag_depth,
        summary.structural.max_depth,
        summary.structural.leaf_element_count,
        summary.structural.branch_element_count,
        summary.structural.max_child_element_count,
        summary.content.attribute_count,
        summary.content.text_token_count,
        summary.content.cdata_count,
        summary.content.comment_count,
        summary.content.processing_instruction_count,
        summary.content.total_attribute_value_bytes,
        summary.content.max_attribute_value_bytes,
        summary.content.total_payload_bytes,
        summary.content.max_payload_bytes
    )
}

fn xml_token_summary_error(message: &str, summary: XmlTokenStreamSummary) -> XmlTokenStreamScan {
    XmlTokenStreamScan {
        summary,
        error: Some(message.to_string()),
    }
}

fn xml_removal_error(
    message: &str,
    xml: &str,
    summary: XmlAttributePrefixSummary,
    removals: Vec<(usize, usize)>,
) -> XmlAttributePrefixRemovalScan {
    XmlAttributePrefixRemovalScan {
        summary,
        removals,
        xml: xml.to_string(),
        error: Some(message.to_string()),
    }
}

fn apply_xml_text_removals(xml: &str, removals: &[(usize, usize)]) -> String {
    if removals.is_empty() {
        return xml.to_string();
    }

    let mut removals = removals.to_vec();
    removals.sort_unstable();
    removals.dedup();

    let mut updated = String::with_capacity(xml.len());
    let mut cursor = 0usize;

    for (start, length) in removals {
        if start < cursor || start > xml.len() {
            continue;
        }

        updated.push_str(&xml[cursor..start]);
        cursor = start.saturating_add(length).min(xml.len());
    }

    updated.push_str(&xml[cursor..]);
    updated
}

fn summarize_xml_attribute_names_with_prefix(
    tokens: &[XmlToken],
    full_namespace_prefix: Option<&str>,
    local_name_prefix: &str,
) -> XmlAttributePrefixSummary {
    let mut summary = XmlAttributePrefixSummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
    };

    for token in tokens {
        summarize_xml_token_attribute_names_with_prefix(
            token,
            full_namespace_prefix,
            local_name_prefix,
            &mut summary,
        );
    }

    summary
}

fn summarize_xml_token_stream(tokens: &[XmlToken], attribute_name: &str) -> XmlTokenStreamSummary {
    let mut summary = XmlTokenStreamSummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
    };

    for token in tokens {
        summarize_xml_token_stream_token(token, attribute_name, &mut summary);
    }

    summary
}

fn summarize_xml_document_inventory(tokens: &[XmlToken]) -> XmlDocumentInventorySummary {
    let mut summary = XmlDocumentInventorySummary {
        token_count: 0,
        tag_count: 0,
        closing_tag_count: 0,
        text_token_count: 0,
        comment_count: 0,
        cdata_count: 0,
        max_depth: 0,
        empty_element_count: 0,
    };

    for token in tokens {
        summarize_xml_document_inventory_token(token, &mut summary);
    }

    summary
}

fn summarize_xml_element_inventory(tokens: &[XmlToken]) -> XmlElementInventorySummary {
    let mut summary = XmlElementInventorySummary {
        token_count: 0,
        tag_count: 0,
        closing_tag_count: 0,
        unique_tag_name_count: 0,
        duplicate_tag_name_count: 0,
        namespaced_tag_count: 0,
        empty_element_count: 0,
    };
    let mut tag_names = HashSet::new();

    for token in tokens {
        summarize_xml_element_inventory_token(token, &mut summary, &mut tag_names);
    }
    summary.unique_tag_name_count = tag_names.len() as i64;

    summary
}

fn summarize_xml_depth_inventory(tokens: &[XmlToken]) -> XmlDepthInventorySummary {
    let mut summary = XmlDepthInventorySummary {
        token_count: 0,
        tag_count: 0,
        closing_tag_count: 0,
        empty_element_count: 0,
        root_level_tag_count: 0,
        nested_tag_count: 0,
        total_tag_depth: 0,
        max_depth: 0,
    };

    for token in tokens {
        summarize_xml_depth_inventory_token(token, &mut summary);
    }

    summary
}

fn summarize_xml_attribute_inventory(tokens: &[XmlToken]) -> XmlAttributeInventorySummary {
    let mut summary = XmlAttributeInventorySummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
        namespaced_attribute_count: 0,
        tags_with_attributes_count: 0,
        max_attribute_count: 0,
    };

    for token in tokens {
        summarize_xml_attribute_inventory_token(token, &mut summary);
    }

    summary
}

fn summarize_xml_id_inventory(tokens: &[XmlToken]) -> XmlIdInventorySummary {
    let mut summary = XmlIdInventorySummary {
        token_count: 0,
        tag_count: 0,
        id_attribute_count: 0,
        unique_id_count: 0,
        duplicate_id_count: 0,
        id_value_bytes: 0,
    };
    let mut seen_ids = HashSet::new();
    let mut duplicate_ids = HashSet::new();

    for token in tokens {
        summarize_xml_id_inventory_token(token, &mut summary, &mut seen_ids, &mut duplicate_ids);
    }

    summary
}

fn summarize_xml_namespace_inventory(tokens: &[XmlToken]) -> XmlNamespaceInventorySummary {
    let mut summary = XmlNamespaceInventorySummary {
        token_count: 0,
        tag_count: 0,
        namespaced_tag_count: 0,
        attribute_count: 0,
        namespaced_attribute_count: 0,
        unique_namespace_count: 0,
    };
    let mut namespaces = HashSet::new();

    for token in tokens {
        summarize_xml_namespace_inventory_token(token, &mut summary, &mut namespaces);
    }
    summary.unique_namespace_count = namespaces.len() as i64;

    summary
}

fn summarize_xml_text_inventory(tokens: &[XmlToken]) -> XmlTextInventorySummary {
    let mut summary = XmlTextInventorySummary {
        token_count: 0,
        text_token_count: 0,
        cdata_count: 0,
        non_empty_text_count: 0,
        whitespace_text_count: 0,
        total_text_bytes: 0,
        max_text_bytes: 0,
    };

    for token in tokens {
        summarize_xml_text_inventory_token(token, &mut summary);
    }

    summary
}

fn summarize_xml_processing_instruction_inventory(
    tokens: &[XmlToken],
) -> XmlProcessingInstructionInventorySummary {
    let mut summary = XmlProcessingInstructionInventorySummary {
        token_count: 0,
        processing_instruction_count: 0,
        xml_declaration_count: 0,
        non_empty_instruction_count: 0,
        total_instruction_bytes: 0,
        max_instruction_bytes: 0,
    };

    for token in tokens {
        summarize_xml_processing_instruction_inventory_token(token, &mut summary);
    }

    summary
}

fn summarize_xml_comment_inventory(tokens: &[XmlToken]) -> XmlCommentInventorySummary {
    let mut summary = XmlCommentInventorySummary {
        token_count: 0,
        comment_count: 0,
        non_empty_comment_count: 0,
        empty_comment_count: 0,
        total_comment_bytes: 0,
        max_comment_bytes: 0,
    };

    for token in tokens {
        summarize_xml_comment_inventory_token(token, &mut summary);
    }

    summary
}

fn summarize_xml_payload_inventory(tokens: &[XmlToken]) -> XmlPayloadInventorySummary {
    let mut summary = XmlPayloadInventorySummary {
        token_count: 0,
        text_token_count: 0,
        cdata_count: 0,
        comment_count: 0,
        processing_instruction_count: 0,
        total_payload_bytes: 0,
        max_payload_bytes: 0,
    };

    for token in tokens {
        summarize_xml_payload_inventory_token(token, &mut summary);
    }

    summary
}

fn summarize_xml_content_inventory(tokens: &[XmlToken]) -> XmlContentInventorySummary {
    let mut summary = XmlContentInventorySummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
        text_token_count: 0,
        cdata_count: 0,
        comment_count: 0,
        processing_instruction_count: 0,
        total_attribute_value_bytes: 0,
        max_attribute_value_bytes: 0,
        total_payload_bytes: 0,
        max_payload_bytes: 0,
    };

    for token in tokens {
        summarize_xml_content_inventory_token(token, &mut summary);
    }

    summary
}

fn summarize_xml_leaf_inventory(tokens: &[XmlToken]) -> XmlLeafInventorySummary {
    let mut summary = XmlLeafInventorySummary {
        token_count: 0,
        tag_count: 0,
        closing_tag_count: 0,
        empty_element_count: 0,
        leaf_element_count: 0,
        branch_element_count: 0,
        max_child_element_count: 0,
    };
    let mut open_child_counts = Vec::new();

    for token in tokens {
        summarize_xml_leaf_inventory_token(token, &mut summary, &mut open_child_counts);
    }

    summary
}

fn summarize_xml_structural_inventory(tokens: &[XmlToken]) -> XmlStructuralInventorySummary {
    let mut summary = empty_xml_structural_inventory_summary();
    let mut tag_names = HashSet::new();
    let mut open_child_counts = Vec::new();

    for token in tokens {
        summarize_xml_structural_inventory_token(
            token,
            &mut summary,
            &mut tag_names,
            &mut open_child_counts,
        );
    }
    summary.unique_tag_name_count = tag_names.len() as i64;

    summary
}

fn summarize_xml_import_inventory(tokens: &[XmlToken]) -> XmlImportInventorySummary {
    let mut summary = empty_xml_import_inventory_summary();
    let mut tag_names = HashSet::new();
    let mut open_child_counts = Vec::new();

    for token in tokens {
        summarize_xml_import_inventory_token(
            token,
            &mut summary,
            &mut tag_names,
            &mut open_child_counts,
        );
    }
    summary.structural.unique_tag_name_count = tag_names.len() as i64;

    summary
}

fn summarize_xml_tag_stream(tokens: &[XmlToken], attribute_name: &str) -> XmlTokenStreamSummary {
    let mut summary = XmlTokenStreamSummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
    };

    for token in tokens {
        summarize_xml_tag_stream_token(token, attribute_name, &mut summary);
    }

    summary
}

fn summarize_xml_matching_tag_stream(
    tokens: &[XmlToken],
    tag_namespace: &str,
    tag_local_name: &str,
    attribute_name: &str,
) -> XmlTokenStreamSummary {
    let mut summary = XmlTokenStreamSummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
    };

    for token in tokens {
        summarize_xml_matching_tag_stream_token(
            token,
            tag_namespace,
            tag_local_name,
            attribute_name,
            &mut summary,
        );
    }

    summary
}

fn summarize_xml_matching_tag_attributes_stream(
    tokens: &[XmlToken],
    tag_namespace: &str,
    tag_local_name: &str,
    attribute_names: &[String],
) -> XmlTokenStreamSummary {
    let mut summary = XmlTokenStreamSummary {
        token_count: 0,
        tag_count: 0,
        attribute_count: 0,
    };

    for token in tokens {
        summarize_xml_matching_tag_attributes_stream_token(
            token,
            tag_namespace,
            tag_local_name,
            attribute_names,
            &mut summary,
        );
    }

    summary
}

fn summarize_xml_token_stream_token(
    token: &XmlToken,
    attribute_name: &str,
    summary: &mut XmlTokenStreamSummary,
) {
    summary.token_count += 1;

    if token.token_type == "#tag" && !token.closing {
        summary.tag_count += 1;
    }

    if (token.token_type == "#tag" || token.token_type == "#xml-declaration")
        && token.attributes.contains_key(attribute_name)
    {
        summary.attribute_count += 1;
    }
}

fn summarize_xml_document_inventory_token(
    token: &XmlToken,
    summary: &mut XmlDocumentInventorySummary,
) {
    summary.token_count += 1;
    summary.max_depth = summary.max_depth.max(token.depth as i64);

    match token.token_type.as_str() {
        "#tag" if token.closing => summary.closing_tag_count += 1,
        "#tag" => {
            summary.tag_count += 1;
            if token.empty_element {
                summary.empty_element_count += 1;
            }
        }
        "#text" => summary.text_token_count += 1,
        "#comment" => summary.comment_count += 1,
        "#cdata-section" => summary.cdata_count += 1,
        _ => {}
    }
}

fn summarize_xml_element_inventory_token(
    token: &XmlToken,
    summary: &mut XmlElementInventorySummary,
    tag_names: &mut HashSet<String>,
) {
    summary.token_count += 1;

    if token.token_type != "#tag" {
        return;
    }

    if token.closing {
        summary.closing_tag_count += 1;
        return;
    }

    summary.tag_count += 1;

    let expanded_name = if let Some(namespace) = token.namespace.as_ref() {
        if namespace.is_empty() {
            token.local_name.clone()
        } else {
            format!("{{{}}}{}", namespace, token.local_name)
        }
    } else {
        token.local_name.clone()
    };
    if !tag_names.insert(expanded_name) {
        summary.duplicate_tag_name_count += 1;
    }

    if token
        .namespace
        .as_ref()
        .is_some_and(|namespace| !namespace.is_empty())
    {
        summary.namespaced_tag_count += 1;
    }

    if token.empty_element {
        summary.empty_element_count += 1;
    }
}

fn summarize_xml_depth_inventory_token(token: &XmlToken, summary: &mut XmlDepthInventorySummary) {
    summary.token_count += 1;
    summary.max_depth = summary.max_depth.max(token.depth as i64);

    if token.token_type != "#tag" {
        return;
    }

    if token.closing {
        summary.closing_tag_count += 1;
        return;
    }

    summary.tag_count += 1;
    summary.total_tag_depth += token.depth as i64;

    if token.empty_element {
        summary.empty_element_count += 1;
    }

    if token.depth == 1 {
        summary.root_level_tag_count += 1;
    } else if token.depth > 2 {
        summary.nested_tag_count += 1;
    }
}

fn summarize_xml_attribute_inventory_token(
    token: &XmlToken,
    summary: &mut XmlAttributeInventorySummary,
) {
    summary.token_count += 1;

    if token.token_type != "#tag" || token.closing {
        return;
    }

    summary.tag_count += 1;

    let attribute_count = token.attribute_order.len() as i64;
    if attribute_count == 0 {
        return;
    }

    summary.attribute_count += attribute_count;
    summary.tags_with_attributes_count += 1;
    summary.max_attribute_count = summary.max_attribute_count.max(attribute_count);

    for attribute_name in &token.attribute_order {
        let (namespace, _) = split_resolved_attribute_name(attribute_name);
        if !namespace.is_empty() {
            summary.namespaced_attribute_count += 1;
        }
    }
}

fn summarize_xml_id_inventory_token(
    token: &XmlToken,
    summary: &mut XmlIdInventorySummary,
    seen_ids: &mut HashSet<String>,
    duplicate_ids: &mut HashSet<String>,
) {
    summary.token_count += 1;

    if token.token_type != "#tag" || token.closing {
        return;
    }

    summary.tag_count += 1;

    let Some(id_value) = token.attributes.get("id") else {
        return;
    };

    summary.id_attribute_count += 1;
    summary.id_value_bytes += id_value.len() as i64;

    if !seen_ids.insert(id_value.clone()) && duplicate_ids.insert(id_value.clone()) {
        summary.duplicate_id_count += 1;
    }
    summary.unique_id_count = seen_ids.len() as i64;
}

fn summarize_xml_namespace_inventory_token(
    token: &XmlToken,
    summary: &mut XmlNamespaceInventorySummary,
    namespaces: &mut HashSet<String>,
) {
    summary.token_count += 1;

    if token.token_type != "#tag" || token.closing {
        return;
    }

    summary.tag_count += 1;

    if let Some(namespace) = token.namespace.as_ref() {
        if !namespace.is_empty() {
            summary.namespaced_tag_count += 1;
            namespaces.insert(namespace.clone());
        }
    }

    for attribute_name in &token.attribute_order {
        summary.attribute_count += 1;

        let (namespace, _) = split_resolved_attribute_name(attribute_name);
        if !namespace.is_empty() {
            summary.namespaced_attribute_count += 1;
            namespaces.insert(namespace.to_string());
        }
    }
}

fn summarize_xml_text_inventory_token(token: &XmlToken, summary: &mut XmlTextInventorySummary) {
    summary.token_count += 1;

    if token.token_type != "#text" && token.token_type != "#cdata-section" {
        return;
    }

    if token.token_type == "#text" {
        summary.text_token_count += 1;
    } else {
        summary.cdata_count += 1;
    }

    let text_bytes = token.text.len() as i64;
    summary.total_text_bytes += text_bytes;
    summary.max_text_bytes = summary.max_text_bytes.max(text_bytes);

    if token.text.trim().is_empty() {
        summary.whitespace_text_count += 1;
    } else {
        summary.non_empty_text_count += 1;
    }
}

fn summarize_xml_processing_instruction_inventory_token(
    token: &XmlToken,
    summary: &mut XmlProcessingInstructionInventorySummary,
) {
    summary.token_count += 1;

    if token.token_type != "#xml-declaration" && token.token_type != "#processing-instructions" {
        return;
    }

    if token.token_type == "#xml-declaration" {
        summary.xml_declaration_count += 1;
    } else {
        summary.processing_instruction_count += 1;
    }

    let text_bytes = token.text.len() as i64;
    summary.total_instruction_bytes += text_bytes;
    summary.max_instruction_bytes = summary.max_instruction_bytes.max(text_bytes);

    if !token.text.trim().is_empty() {
        summary.non_empty_instruction_count += 1;
    }
}

fn summarize_xml_comment_inventory_token(
    token: &XmlToken,
    summary: &mut XmlCommentInventorySummary,
) {
    summary.token_count += 1;

    if token.token_type != "#comment" {
        return;
    }

    summary.comment_count += 1;

    let text_bytes = token.text.len() as i64;
    summary.total_comment_bytes += text_bytes;
    summary.max_comment_bytes = summary.max_comment_bytes.max(text_bytes);

    if token.text.trim().is_empty() {
        summary.empty_comment_count += 1;
    } else {
        summary.non_empty_comment_count += 1;
    }
}

fn summarize_xml_payload_inventory_token(
    token: &XmlToken,
    summary: &mut XmlPayloadInventorySummary,
) {
    summary.token_count += 1;

    match token.token_type.as_str() {
        "#text" => summary.text_token_count += 1,
        "#cdata-section" => summary.cdata_count += 1,
        "#comment" => summary.comment_count += 1,
        "#processing-instructions" => summary.processing_instruction_count += 1,
        _ => return,
    }

    let payload_bytes = token.text.len() as i64;
    summary.total_payload_bytes += payload_bytes;
    summary.max_payload_bytes = summary.max_payload_bytes.max(payload_bytes);
}

fn summarize_xml_content_inventory_token(
    token: &XmlToken,
    summary: &mut XmlContentInventorySummary,
) {
    summary.token_count += 1;

    if token.token_type == "#tag" && !token.closing {
        summary.tag_count += 1;
        for attribute_name in &token.attribute_order {
            summary.attribute_count += 1;
            if let Some(value) = token.attributes.get(attribute_name) {
                let value_bytes = value.len() as i64;
                summary.total_attribute_value_bytes += value_bytes;
                summary.max_attribute_value_bytes =
                    summary.max_attribute_value_bytes.max(value_bytes);
            }
        }
        return;
    }

    match token.token_type.as_str() {
        "#text" => summary.text_token_count += 1,
        "#cdata-section" => summary.cdata_count += 1,
        "#comment" => summary.comment_count += 1,
        "#processing-instructions" => summary.processing_instruction_count += 1,
        _ => return,
    }

    let payload_bytes = token.text.len() as i64;
    summary.total_payload_bytes += payload_bytes;
    summary.max_payload_bytes = summary.max_payload_bytes.max(payload_bytes);
}

fn summarize_xml_leaf_inventory_token(
    token: &XmlToken,
    summary: &mut XmlLeafInventorySummary,
    open_child_counts: &mut Vec<i64>,
) {
    summary.token_count += 1;

    if token.token_type != "#tag" {
        return;
    }

    if token.closing {
        summary.closing_tag_count += 1;

        if let Some(child_count) = open_child_counts.pop() {
            if child_count == 0 {
                summary.leaf_element_count += 1;
            } else {
                summary.branch_element_count += 1;
            }

            summary.max_child_element_count = summary.max_child_element_count.max(child_count);
        }

        return;
    }

    summary.tag_count += 1;

    if let Some(parent_child_count) = open_child_counts.last_mut() {
        *parent_child_count += 1;
    }

    if token.empty_element {
        summary.empty_element_count += 1;
        summary.leaf_element_count += 1;
    } else {
        open_child_counts.push(0);
    }
}

fn empty_xml_structural_inventory_summary() -> XmlStructuralInventorySummary {
    XmlStructuralInventorySummary {
        token_count: 0,
        tag_count: 0,
        closing_tag_count: 0,
        unique_tag_name_count: 0,
        duplicate_tag_name_count: 0,
        namespaced_tag_count: 0,
        empty_element_count: 0,
        root_level_tag_count: 0,
        nested_tag_count: 0,
        total_tag_depth: 0,
        max_depth: 0,
        leaf_element_count: 0,
        branch_element_count: 0,
        max_child_element_count: 0,
    }
}

fn summarize_xml_structural_inventory_token(
    token: &XmlToken,
    summary: &mut XmlStructuralInventorySummary,
    tag_names: &mut HashSet<String>,
    open_child_counts: &mut Vec<i64>,
) {
    summary.token_count += 1;
    summary.max_depth = summary.max_depth.max(token.depth as i64);

    if token.token_type != "#tag" {
        return;
    }

    if token.closing {
        summary.closing_tag_count += 1;

        if let Some(child_count) = open_child_counts.pop() {
            if child_count == 0 {
                summary.leaf_element_count += 1;
            } else {
                summary.branch_element_count += 1;
            }

            summary.max_child_element_count = summary.max_child_element_count.max(child_count);
        }

        return;
    }

    summary.tag_count += 1;
    summary.total_tag_depth += token.depth as i64;

    if token.depth == 1 {
        summary.root_level_tag_count += 1;
    } else if token.depth > 2 {
        summary.nested_tag_count += 1;
    }

    let expanded_name = if let Some(namespace) = token.namespace.as_ref() {
        if namespace.is_empty() {
            token.local_name.clone()
        } else {
            format!("{{{}}}{}", namespace, token.local_name)
        }
    } else {
        token.local_name.clone()
    };
    if !tag_names.insert(expanded_name) {
        summary.duplicate_tag_name_count += 1;
    }

    if token
        .namespace
        .as_ref()
        .is_some_and(|namespace| !namespace.is_empty())
    {
        summary.namespaced_tag_count += 1;
    }

    if let Some(parent_child_count) = open_child_counts.last_mut() {
        *parent_child_count += 1;
    }

    if token.empty_element {
        summary.empty_element_count += 1;
        summary.leaf_element_count += 1;
    } else {
        open_child_counts.push(0);
    }
}

fn empty_xml_import_inventory_summary() -> XmlImportInventorySummary {
    XmlImportInventorySummary {
        structural: empty_xml_structural_inventory_summary(),
        content: XmlContentInventorySummary {
            token_count: 0,
            tag_count: 0,
            attribute_count: 0,
            text_token_count: 0,
            cdata_count: 0,
            comment_count: 0,
            processing_instruction_count: 0,
            total_attribute_value_bytes: 0,
            max_attribute_value_bytes: 0,
            total_payload_bytes: 0,
            max_payload_bytes: 0,
        },
    }
}

fn summarize_xml_import_inventory_token(
    token: &XmlToken,
    summary: &mut XmlImportInventorySummary,
    tag_names: &mut HashSet<String>,
    open_child_counts: &mut Vec<i64>,
) {
    summarize_xml_structural_inventory_token(
        token,
        &mut summary.structural,
        tag_names,
        open_child_counts,
    );
    summarize_xml_content_inventory_token(token, &mut summary.content);
}

fn summarize_xml_tag_stream_token(
    token: &XmlToken,
    attribute_name: &str,
    summary: &mut XmlTokenStreamSummary,
) {
    summary.token_count += 1;

    if token.token_type != "#tag" || token.closing {
        return;
    }

    summary.tag_count += 1;
    if token.attributes.contains_key(attribute_name) {
        summary.attribute_count += 1;
    }
}

fn summarize_xml_matching_tag_stream_token(
    token: &XmlToken,
    tag_namespace: &str,
    tag_local_name: &str,
    attribute_name: &str,
    summary: &mut XmlTokenStreamSummary,
) {
    summary.token_count += 1;

    if !xml_token_matches_tag_name(token, tag_namespace, tag_local_name) {
        return;
    }

    summary.tag_count += 1;
    if token.attributes.contains_key(attribute_name) {
        summary.attribute_count += 1;
    }
}

fn summarize_xml_matching_tag_attributes_stream_token(
    token: &XmlToken,
    tag_namespace: &str,
    tag_local_name: &str,
    attribute_names: &[String],
    summary: &mut XmlTokenStreamSummary,
) {
    summary.token_count += 1;

    if !xml_token_matches_tag_name(token, tag_namespace, tag_local_name) {
        return;
    }

    summary.tag_count += 1;
    for attribute_name in attribute_names {
        if token.attributes.contains_key(attribute_name) {
            summary.attribute_count += 1;
        }
    }
}

fn parse_compact_attribute_names(attribute_names: &str) -> Vec<String> {
    let mut parsed = Vec::new();
    for name in attribute_names.split('\x1f') {
        if !name.is_empty() && !parsed.iter().any(|parsed_name| parsed_name == name) {
            parsed.push(name.to_string());
        }
    }

    parsed
}

fn attribute_names_contains(attribute_names: &[String], attribute_name: &str) -> bool {
    attribute_names
        .iter()
        .any(|candidate| candidate.as_str() == attribute_name)
}

fn summarize_xml_token_attribute_names_with_prefix(
    token: &XmlToken,
    full_namespace_prefix: Option<&str>,
    local_name_prefix: &str,
    summary: &mut XmlAttributePrefixSummary,
) {
    summary.token_count += 1;
    if token.token_type != "#tag" || token.closing {
        return;
    }

    summary.tag_count += 1;
    for attribute_name in &token.attribute_order {
        let (namespace, local_name) = split_resolved_attribute_name(attribute_name);
        if !local_name.starts_with(local_name_prefix) {
            continue;
        }

        match full_namespace_prefix {
            Some(prefix) if namespace.starts_with(prefix) => summary.attribute_count += 1,
            None if namespace.is_empty() => summary.attribute_count += 1,
            _ => {}
        }
    }
}

fn xml_token_metadata(token: &XmlToken) -> String {
    xml_token_metadata_row(token, true)
}

#[cfg(test)]
fn xml_token_summary(token: &XmlToken) -> String {
    xml_token_metadata_row(token, false)
}

fn xml_token_compact_summary(token: &XmlToken) -> String {
    let token_kind = match token.token_type.as_str() {
        "#tag" => "t",
        "#xml-declaration" => "x",
        "#doctype" => "d",
        "#processing-instructions" => "p",
        "#comment" => "c",
        "#cdata-section" => "a",
        _ => "s",
    };
    let namespace = if token.token_type == "#tag" {
        token.namespace.as_deref().unwrap_or_default()
    } else {
        ""
    };
    xml_compact_summary_row(
        token_kind,
        &token.local_name,
        namespace,
        token.closing,
        token.empty_element,
        token.depth,
        token.attributes.get("id").map(|value| value.as_str()),
    )
}

fn xml_token_hot_compact_summary(token: &XmlToken) -> String {
    let token_kind = match token.token_type.as_str() {
        "#tag" => "t",
        "#xml-declaration" => "x",
        "#doctype" => "d",
        "#processing-instructions" => "p",
        "#comment" => "c",
        "#cdata-section" => "a",
        _ => "s",
    };
    let namespace = if token.token_type == "#tag" {
        token.namespace.as_deref().unwrap_or_default()
    } else {
        ""
    };
    xml_hot_compact_summary_row(
        token_kind,
        &token.local_name,
        namespace,
        token.closing,
        token.empty_element,
        token.depth,
        token.attributes.get("id").map(|value| value.as_str()),
    )
}

fn xml_token_cursor_compact_summary(token: &XmlToken) -> String {
    let token_kind = match token.token_type.as_str() {
        "#tag" => "t",
        "#xml-declaration" => "x",
        "#doctype" => "d",
        "#processing-instructions" => "p",
        "#comment" => "c",
        "#cdata-section" => "a",
        _ => "s",
    };
    let namespace = if token.token_type == "#tag" {
        token.namespace.as_deref().unwrap_or_default()
    } else {
        ""
    };
    xml_cursor_compact_summary_row(
        token_kind,
        &token.local_name,
        namespace,
        token.closing,
        token.empty_element,
        token.depth,
        token.attributes.get("id").map(|value| value.as_str()),
    )
}

fn xml_compact_summary_row(
    token_kind: &str,
    local_name: &str,
    namespace: &str,
    closing: bool,
    empty_element: bool,
    depth: usize,
    id_attribute: Option<&str>,
) -> String {
    let mut summary = String::with_capacity(
        local_name.len()
            + namespace.len()
            + id_attribute.map(|value| value.len()).unwrap_or(0)
            + 20,
    );
    append_xml_metadata_field(&mut summary, token_kind);
    append_xml_metadata_field(&mut summary, local_name);
    append_xml_metadata_field(&mut summary, namespace);
    summary.push('\x1f');
    summary.push(if closing { '1' } else { '0' });
    summary.push(if empty_element { '1' } else { '0' });
    summary.push('\x1f');
    let _ = write!(&mut summary, "{}", depth);
    summary.push('\x1f');
    if let Some(value) = id_attribute {
        summary.push('1');
        summary.push_str(value);
    } else {
        summary.push('0');
    }

    summary
}

fn xml_hot_compact_summary_row(
    token_kind: &str,
    local_name: &str,
    namespace: &str,
    closing: bool,
    empty_element: bool,
    depth: usize,
    id_attribute: Option<&str>,
) -> String {
    let mut summary = String::with_capacity(
        local_name.len()
            + namespace.len()
            + id_attribute.map(|value| value.len()).unwrap_or(0)
            + 20,
    );
    summary.push_str(token_kind);
    summary.push('\x1f');
    summary.push_str(local_name);
    summary.push('\x1f');
    summary.push_str(namespace);
    summary.push('\x1f');
    if let Some(value) = id_attribute {
        summary.push('1');
        summary.push_str(value);
    } else {
        summary.push('0');
    }
    summary.push('\x1f');
    summary.push(if closing { '1' } else { '0' });
    summary.push(if empty_element { '1' } else { '0' });
    summary.push('\x1f');
    let _ = write!(&mut summary, "{}", depth);

    summary
}

fn xml_cursor_compact_summary_row(
    token_kind: &str,
    local_name: &str,
    namespace: &str,
    _closing: bool,
    _empty_element: bool,
    _depth: usize,
    id_attribute: Option<&str>,
) -> String {
    if token_kind != "t" {
        return token_kind.to_string();
    }

    let mut summary = String::with_capacity(
        local_name.len() * 2
            + namespace.len()
            + id_attribute.map(|value| value.len()).unwrap_or(0)
            + 8,
    );
    summary.push_str(token_kind);
    summary.push('\x1f');
    summary.push_str(local_name);
    summary.push('\x1f');
    if namespace.is_empty() {
        summary.push_str(local_name);
    } else {
        summary.push('{');
        summary.push_str(namespace);
        summary.push('}');
        summary.push_str(local_name);
    }
    summary.push('\x1f');
    if let Some(value) = id_attribute {
        summary.push('1');
        summary.push_str(value);
    } else {
        summary.push('0');
    }

    summary
}

#[cfg(feature = "php-extension")]
fn xml_token_public_summary_row(token: &XmlToken) -> Vec<(String, Zval)> {
    let tag_namespace = if token.token_type == "#tag" {
        token.namespace.as_deref().unwrap_or_default()
    } else {
        ""
    };
    let tag_namespace_and_local_name = if token.token_type == "#tag" {
        if tag_namespace.is_empty() {
            token.local_name.clone()
        } else {
            format!("{{{}}}{}", tag_namespace, token.local_name)
        }
    } else {
        String::new()
    };
    let token_name = if token.token_type == "#tag" {
        token.local_name.clone()
    } else {
        token.token_type.clone()
    };

    vec![
        (
            "token_type".to_string(),
            xml_zval_string(token.token_type.as_str()),
        ),
        ("token_name".to_string(), xml_zval_string(&token_name)),
        (
            "tag_local_name".to_string(),
            xml_zval_string(if token.token_type == "#tag" {
                token.local_name.as_str()
            } else {
                ""
            }),
        ),
        ("tag_namespace".to_string(), xml_zval_string(tag_namespace)),
        (
            "tag_namespace_and_local_name".to_string(),
            xml_zval_string(&tag_namespace_and_local_name),
        ),
        ("is_tag_closer".to_string(), xml_zval_bool(token.closing)),
        (
            "is_empty_element".to_string(),
            xml_zval_bool(token.empty_element),
        ),
        (
            "current_depth".to_string(),
            xml_zval_i64(token.depth as i64),
        ),
        (
            "id".to_string(),
            token
                .attributes
                .get("id")
                .map(|value| xml_zval_string(value))
                .unwrap_or_else(xml_zval_null),
        ),
    ]
}

#[cfg(feature = "php-extension")]
fn xml_tag_public_summary_row(token: &XmlToken, attribute_name: &str) -> Vec<(String, Zval)> {
    let namespace = token.namespace.as_deref().unwrap_or_default();
    let namespace_and_local_name = if namespace.is_empty() {
        token.local_name.clone()
    } else {
        format!("{{{}}}{}", namespace, token.local_name)
    };
    let attribute_value = token
        .attributes
        .get(attribute_name)
        .map(|value| xml_zval_string(value))
        .unwrap_or_else(xml_zval_null);

    vec![
        (
            "tag_local_name".to_string(),
            xml_zval_string(&token.local_name),
        ),
        ("tag_namespace".to_string(), xml_zval_string(namespace)),
        (
            "tag_namespace_and_local_name".to_string(),
            xml_zval_string(&namespace_and_local_name),
        ),
        (
            "is_empty_element".to_string(),
            xml_zval_bool(token.empty_element),
        ),
        (
            "current_depth".to_string(),
            xml_zval_i64(token.depth as i64),
        ),
        (attribute_name.to_string(), attribute_value),
    ]
}

#[cfg(feature = "php-extension")]
fn xml_zval_string(value: &str) -> Zval {
    let mut zval = Zval::new();
    let _ = zval.set_string(value, false);
    zval
}

#[cfg(feature = "php-extension")]
fn xml_zval_bool(value: bool) -> Zval {
    let mut zval = Zval::new();
    zval.set_bool(value);
    zval
}

#[cfg(feature = "php-extension")]
fn xml_zval_i64(value: i64) -> Zval {
    let mut zval = Zval::new();
    zval.set_long(value);
    zval
}

#[cfg(feature = "php-extension")]
fn xml_zval_null() -> Zval {
    Zval::null()
}

fn xml_token_matches_tag_name(token: &XmlToken, tag_namespace: &str, tag_local_name: &str) -> bool {
    token.token_type == "#tag"
        && !token.closing
        && token.local_name == tag_local_name
        && token.namespace.as_deref().unwrap_or_default() == tag_namespace
}

fn xml_tag_compact_summary(
    token: &XmlToken,
    tokens_consumed: usize,
    attribute_name: &str,
) -> String {
    let namespace = token.namespace.as_deref().unwrap_or_default();
    let attribute = token
        .attributes
        .get(attribute_name)
        .map(|value| {
            let mut encoded = String::with_capacity(value.len() + 1);
            encoded.push('1');
            encoded.push_str(value);
            encoded
        })
        .unwrap_or_else(|| "0".to_string());
    let mut summary = String::with_capacity(
        token.local_name.len()
            + namespace.len()
            + attribute.len()
            + tokens_consumed.to_string().len()
            + 12,
    );
    append_xml_metadata_field(&mut summary, &tokens_consumed.to_string());
    append_xml_metadata_field(&mut summary, &token.local_name);
    append_xml_metadata_field(&mut summary, namespace);
    append_xml_metadata_field(&mut summary, if token.empty_element { "1" } else { "0" });
    append_xml_metadata_field(&mut summary, &token.depth.to_string());
    append_xml_metadata_field(&mut summary, &attribute);

    summary
}

fn xml_token_metadata_row(token: &XmlToken, include_attributes: bool) -> String {
    let namespace = if token.token_type == "#tag" {
        token.namespace.as_deref().unwrap_or_default()
    } else {
        ""
    };

    let mut metadata = String::with_capacity(64 + token.attribute_order.len() * 24);
    append_xml_metadata_field(&mut metadata, &token.token_type);
    append_xml_metadata_field(&mut metadata, &token.local_name);
    append_xml_metadata_field(
        &mut metadata,
        if token.token_type == "#tag" {
            &token.local_name
        } else {
            ""
        },
    );
    append_xml_metadata_field(&mut metadata, namespace);
    metadata.push('\x1f');
    if token.token_type == "#tag" {
        if namespace.is_empty() {
            metadata.push_str(&token.local_name);
        } else {
            metadata.push('{');
            metadata.push_str(namespace);
            metadata.push('}');
            metadata.push_str(&token.local_name);
        }
    }
    append_xml_metadata_field(&mut metadata, if token.closing { "1" } else { "0" });
    append_xml_metadata_field(&mut metadata, if token.empty_element { "1" } else { "0" });
    append_xml_metadata_field(&mut metadata, &token.depth.to_string());

    if include_attributes {
        for attribute_name in &token.attribute_order {
            if let Some(attribute_value) = token.attributes.get(attribute_name) {
                append_xml_metadata_field(&mut metadata, attribute_name);
                append_xml_metadata_field(&mut metadata, attribute_value);
            }
        }
    }

    metadata
}

fn append_xml_metadata_field(metadata: &mut String, field: &str) {
    if !metadata.is_empty() {
        metadata.push('\x1f');
    }
    metadata.push_str(field);
}

#[cfg(test)]
mod tests {
    use super::{
        extend_xml_namespaces, parse_compact_attribute_names,
        parse_next_xml_stream_compact_summary, parse_next_xml_stream_token, parse_xml_document,
        remove_xml_source_attributes_with_prefix, summarize_xml_attribute_inventory,
        summarize_xml_attribute_names_with_prefix, summarize_xml_comment_inventory,
        summarize_xml_content_inventory, summarize_xml_depth_inventory,
        summarize_xml_element_inventory, summarize_xml_id_inventory,
        summarize_xml_import_inventory, summarize_xml_leaf_inventory,
        summarize_xml_namespace_inventory, summarize_xml_payload_inventory,
        summarize_xml_processing_instruction_inventory, summarize_xml_source_attribute_inventory,
        summarize_xml_source_attribute_names_with_prefix, summarize_xml_source_comment_inventory,
        summarize_xml_source_content_inventory, summarize_xml_source_depth_inventory,
        summarize_xml_source_document_inventory, summarize_xml_source_element_inventory,
        summarize_xml_source_id_inventory, summarize_xml_source_import_inventory,
        summarize_xml_source_leaf_inventory, summarize_xml_source_matching_tag_attributes_stream,
        summarize_xml_source_matching_tag_stream, summarize_xml_source_namespace_inventory,
        summarize_xml_source_payload_inventory,
        summarize_xml_source_processing_instruction_inventory,
        summarize_xml_source_structural_inventory, summarize_xml_source_tag_stream,
        summarize_xml_source_text_inventory, summarize_xml_source_token_stream,
        summarize_xml_structural_inventory, summarize_xml_text_inventory,
        xml_comment_inventory_summary, xml_content_inventory_summary, xml_depth_inventory_summary,
        xml_document_inventory_summary, xml_element_inventory_summary,
        xml_import_inventory_summary, xml_leaf_inventory_summary, xml_payload_inventory_summary,
        xml_processing_instruction_inventory_summary, xml_structural_inventory_summary,
        xml_tag_compact_summary, xml_text_inventory_summary, xml_token_compact_summary,
        xml_token_matches_tag_name, xml_token_metadata, xml_token_summary,
        XmlAttributeInventorySummary, XmlAttributePrefixSummary, XmlCommentInventorySummary,
        XmlContentInventorySummary, XmlDepthInventorySummary, XmlDocumentInventorySummary,
        XmlElementInventorySummary, XmlIdInventorySummary, XmlImportInventorySummary,
        XmlLeafInventorySummary, XmlNamespaceInventorySummary, XmlPayloadInventorySummary,
        XmlProcessingInstructionInventorySummary, XmlStreamState, XmlStructuralInventorySummary,
        XmlTextInventorySummary, XmlTokenStreamSummary,
    };
    use std::collections::HashMap;
    use std::rc::Rc;

    #[test]
    fn parses_xml_names_and_attributes() {
        let document = parse_xml_document(
            "<root xmlns:ns=\"https://example.com/ns\"><item id=\"7\" ns:name='value' /></root>",
        );

        assert_eq!(None, document.error);
        assert_eq!(3, document.tokens.len());
        assert_eq!("root", document.tokens[0].name);
        assert_eq!("#tag", document.tokens[0].token_type);
        assert_eq!("root", document.tokens[0].local_name);
        assert!(!document.tokens[0].empty_element);
        assert_eq!("item", document.tokens[1].name);
        assert_eq!("item", document.tokens[1].local_name);
        assert!(document.tokens[1].empty_element);
        assert_eq!(
            Some(&"7".to_string()),
            document.tokens[1].attributes.get("id")
        );
        assert_eq!(
            Some(&"value".to_string()),
            document.tokens[1]
                .attributes
                .get("{https://example.com/ns}name")
        );
        assert!(document.tokens[2].closing);
        assert!(!document.tokens[2].empty_element);
    }

    #[test]
    fn reuses_unchanged_namespace_maps() {
        let mut current = HashMap::new();
        current.insert(
            "xml".to_string(),
            "http://www.w3.org/XML/1998/namespace".to_string(),
        );
        current.insert("wp".to_string(), "https://wordpress.org".to_string());
        let current = Rc::new(current);

        let mut unchanged = HashMap::new();
        unchanged.insert("wp".to_string(), "https://wordpress.org".to_string());
        let reused = extend_xml_namespaces(&current, unchanged);
        assert!(Rc::ptr_eq(&current, &reused));

        let mut changed = HashMap::new();
        changed.insert("wp".to_string(), "https://example.com".to_string());
        let extended = extend_xml_namespaces(&current, changed);
        assert!(!Rc::ptr_eq(&current, &extended));
        assert_eq!(Some(&"https://example.com".to_string()), extended.get("wp"));
    }

    #[test]
    fn streaming_tokens_match_document_parser_tokens() {
        let xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><root xmlns:wp=\"https://wordpress.org\"><wp:item id=\"7\"><wp:title>Title &amp; More</wp:title><!--note--><![CDATA[raw]]></wp:item></root>";
        let document = parse_xml_document(xml);
        let mut state = XmlStreamState::new();
        let mut streamed = Vec::new();

        while let Some(token) = parse_next_xml_stream_token(xml, &mut state).unwrap() {
            streamed.push(token);
        }

        assert_eq!(None, document.error);
        assert_eq!(document.tokens, streamed);
    }

    #[test]
    fn records_text_and_comment_tokens() {
        let document = parse_xml_document(
            "<root xmlns:wp=\"w.org\"><wp:text>Hello<!--note-->World</wp:text></root>",
        );

        assert_eq!(None, document.error);
        assert_eq!(7, document.tokens.len());
        assert_eq!("#text", document.tokens[2].token_type);
        assert_eq!("Hello", document.tokens[2].text);
        assert_eq!(
            vec![
                ("".to_string(), "root".to_string()),
                ("w.org".to_string(), "text".to_string())
            ],
            document.tokens[2].breadcrumbs
        );
        assert_eq!(2, document.tokens[2].depth);
        assert_eq!("#comment", document.tokens[3].token_type);
        assert_eq!("note", document.tokens[3].text);
        assert_eq!(
            vec![
                ("".to_string(), "root".to_string()),
                ("w.org".to_string(), "text".to_string())
            ],
            document.tokens[3].breadcrumbs
        );
        assert_eq!("#text", document.tokens[4].token_type);
        assert_eq!("World", document.tokens[4].text);
    }

    #[test]
    fn rejects_malformed_comments() {
        let contains_double_hyphen = parse_xml_document("<!-- comment -- not allowed -->");
        assert!(contains_double_hyphen.error.is_some());
        assert!(contains_double_hyphen.tokens.is_empty());

        let ends_with_hyphen = parse_xml_document("<!-- comment ends with --->");
        assert!(ends_with_hyphen.error.is_some());
        assert!(ends_with_hyphen.tokens.is_empty());
    }

    #[test]
    fn decodes_xml_character_references_in_text_and_attributes() {
        let document = parse_xml_document(
            "<root a=\"&amp; &amp;amp; &lt; &gt; &quot; &apos;&#65;&#x42; &#0; &unknown;\">&amp; &amp;amp; &lt; &gt; &quot; &apos;&#67;&#x44; &#xD800; &unknown;</root>",
        );

        assert_eq!(None, document.error);
        assert_eq!(
            Some(&"& &amp; < > \" 'AB &#0; &unknown;".to_string()),
            document.tokens[0].attributes.get("a")
        );
        assert_eq!(
            "& &amp; < > \" 'CD &#xD800; &unknown;",
            document.tokens[1].text
        );
    }

    #[test]
    fn records_cdata_section_tokens() {
        let document = parse_xml_document(
            "<root xmlns:wp=\"w.org\"><wp:text><![CDATA[<b>&c]]></wp:text></root>",
        );

        assert_eq!(None, document.error);
        assert_eq!(5, document.tokens.len());
        assert_eq!("#cdata-section", document.tokens[2].token_type);
        assert_eq!("#cdata-section", document.tokens[2].local_name);
        assert_eq!("<b>&c", document.tokens[2].text);
        assert_eq!(
            vec![
                ("".to_string(), "root".to_string()),
                ("w.org".to_string(), "text".to_string())
            ],
            document.tokens[2].breadcrumbs
        );
        assert_eq!(2, document.tokens[2].depth);
    }

    #[test]
    fn records_xml_declaration_and_doctype_tokens() {
        let document =
            parse_xml_document("<?xml version=\"1.0\" encoding=\"UTF-8\"?><!DOCTYPE root><root />");

        assert_eq!(None, document.error);
        assert_eq!("#xml-declaration", document.tokens[0].token_type);
        assert_eq!("#xml-declaration", document.tokens[0].local_name);
        assert_eq!(
            "xml version=\"1.0\" encoding=\"UTF-8\"",
            document.tokens[0].text
        );
        assert_eq!(
            Some(&"1.0".to_string()),
            document.tokens[0].attributes.get("version")
        );
        assert_eq!(
            Some(&"UTF-8".to_string()),
            document.tokens[0].attributes.get("encoding")
        );
        assert_eq!(
            Vec::<(String, String)>::new(),
            document.tokens[0].breadcrumbs
        );
        assert_eq!(0, document.tokens[0].depth);
        assert_eq!("#doctype", document.tokens[1].token_type);
        assert_eq!("#doctype", document.tokens[1].local_name);
        assert_eq!("root", document.tokens[2].local_name);
    }

    #[test]
    fn reports_unclosed_cdata_sections() {
        let document = parse_xml_document("<root><![CDATA[unfinished</root>");

        assert!(document.error.is_some());
    }

    #[test]
    fn reports_unsupported_processing_instructions() {
        let document = parse_xml_document("<root><?pi data?><child /></root>");

        assert!(document.error.is_some());
    }

    #[test]
    fn records_xml_processing_instruction_tokens() {
        let document = parse_xml_document(
            "<?xml version=\"1.0\" encoding=\"UTF-8\" ?><?xml stylesheet type=\"text/xsl\" href=\"style.xsl\" ?><root />",
        );

        assert_eq!(None, document.error);
        assert_eq!("#xml-declaration", document.tokens[0].token_type);
        assert_eq!("#processing-instructions", document.tokens[1].token_type);
        assert_eq!("#processing-instructions", document.tokens[1].local_name);
        assert_eq!(
            " stylesheet type=\"text/xsl\" href=\"style.xsl\" ",
            document.tokens[1].text
        );
        assert_eq!(0, document.tokens[1].depth);
    }

    #[test]
    fn summarizes_xml_attribute_names_with_prefix() {
        let document = parse_xml_document(
            "<root xmlns:wp=\"https://wordpress.org\"><wp:item wp:data-id=\"7\" data-kind=\"post\" /><item data-id=\"8\" /></root>",
        );

        assert_eq!(None, document.error);
        assert_eq!(
            XmlAttributePrefixSummary {
                token_count: 4,
                tag_count: 3,
                attribute_count: 2,
            },
            summarize_xml_attribute_names_with_prefix(&document.tokens, None, "data-")
        );
        assert_eq!(
            XmlAttributePrefixSummary {
                token_count: 4,
                tag_count: 3,
                attribute_count: 1,
            },
            summarize_xml_attribute_names_with_prefix(
                &document.tokens,
                Some("https://wordpress.org"),
                "data-"
            )
        );
        assert_eq!(
            XmlAttributePrefixSummary {
                token_count: 2,
                tag_count: 1,
                attribute_count: 1,
            },
            summarize_xml_attribute_names_with_prefix(&document.tokens[2..], None, "data-")
        );
    }

    #[test]
    fn summarizes_xml_depth_inventory() {
        let xml = "<?xml version=\"1.0\"?><root><section><item /><item><child /></item></section><single /></root>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlDepthInventorySummary {
                token_count: 10,
                tag_count: 6,
                closing_tag_count: 3,
                empty_element_count: 3,
                root_level_tag_count: 1,
                nested_tag_count: 3,
                total_tag_depth: 15,
                max_depth: 4,
            },
            summarize_xml_depth_inventory(&document.tokens)
        );
        assert_eq!(
            XmlDepthInventorySummary {
                token_count: 8,
                tag_count: 5,
                closing_tag_count: 3,
                empty_element_count: 3,
                root_level_tag_count: 0,
                nested_tag_count: 3,
                total_tag_depth: 14,
                max_depth: 4,
            },
            summarize_xml_depth_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_depth_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            "10\x1f6\x1f3\x1f3\x1f1\x1f3\x1f15\x1f4",
            xml_depth_inventory_summary(&scan.summary)
        );
    }

    #[test]
    fn summarizes_xml_element_inventory() {
        let xml = "<?xml version=\"1.0\"?><wp:root xmlns:wp=\"https://wordpress.org\"><wp:item /><wp:item><plain /></wp:item><plain /></wp:root>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlElementInventorySummary {
                token_count: 8,
                tag_count: 5,
                closing_tag_count: 2,
                unique_tag_name_count: 3,
                duplicate_tag_name_count: 2,
                namespaced_tag_count: 3,
                empty_element_count: 3,
            },
            summarize_xml_element_inventory(&document.tokens)
        );
        assert_eq!(
            XmlElementInventorySummary {
                token_count: 6,
                tag_count: 4,
                closing_tag_count: 2,
                unique_tag_name_count: 2,
                duplicate_tag_name_count: 2,
                namespaced_tag_count: 2,
                empty_element_count: 3,
            },
            summarize_xml_element_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_element_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            "8\x1f5\x1f2\x1f3\x1f2\x1f3\x1f3",
            xml_element_inventory_summary(&scan.summary)
        );

        let invalid_scan = summarize_xml_source_element_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlElementInventorySummary {
                token_count: 2,
                tag_count: 2,
                closing_tag_count: 0,
                unique_tag_name_count: 2,
                duplicate_tag_name_count: 0,
                namespaced_tag_count: 0,
                empty_element_count: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_attribute_inventory() {
        let xml = "<?xml version=\"1.0\"?><wp:root xmlns:wp=\"https://wordpress.org\" id=\"root\"><wp:item id=\"7\" wp:slug=\"first\"><wp:title>Title</wp:title><empty data-id=\"x\" /></wp:item></wp:root>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlAttributeInventorySummary {
                token_count: 9,
                tag_count: 4,
                attribute_count: 4,
                namespaced_attribute_count: 1,
                tags_with_attributes_count: 3,
                max_attribute_count: 2,
            },
            summarize_xml_attribute_inventory(&document.tokens)
        );
        assert_eq!(
            XmlAttributeInventorySummary {
                token_count: 7,
                tag_count: 3,
                attribute_count: 3,
                namespaced_attribute_count: 1,
                tags_with_attributes_count: 2,
                max_attribute_count: 2,
            },
            summarize_xml_attribute_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_attribute_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlAttributeInventorySummary {
                token_count: 9,
                tag_count: 4,
                attribute_count: 4,
                namespaced_attribute_count: 1,
                tags_with_attributes_count: 3,
                max_attribute_count: 2,
            },
            scan.summary
        );

        let invalid_scan = summarize_xml_source_attribute_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlAttributeInventorySummary {
                token_count: 2,
                tag_count: 2,
                attribute_count: 0,
                namespaced_attribute_count: 0,
                tags_with_attributes_count: 0,
                max_attribute_count: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_id_inventory() {
        let xml = "<?xml version=\"1.0\"?><wp:root xmlns:wp=\"https://wordpress.org\" id=\"root\"><wp:item id=\"7\" wp:id=\"ignored\"><wp:title id=\"title\">Title</wp:title><empty id=\"7\" /><plain /></wp:item></wp:root>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlIdInventorySummary {
                token_count: 10,
                tag_count: 5,
                id_attribute_count: 4,
                unique_id_count: 3,
                duplicate_id_count: 1,
                id_value_bytes: 11,
            },
            summarize_xml_id_inventory(&document.tokens)
        );
        assert_eq!(
            XmlIdInventorySummary {
                token_count: 8,
                tag_count: 4,
                id_attribute_count: 3,
                unique_id_count: 2,
                duplicate_id_count: 1,
                id_value_bytes: 7,
            },
            summarize_xml_id_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_id_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlIdInventorySummary {
                token_count: 10,
                tag_count: 5,
                id_attribute_count: 4,
                unique_id_count: 3,
                duplicate_id_count: 1,
                id_value_bytes: 11,
            },
            scan.summary
        );

        let invalid_scan =
            summarize_xml_source_id_inventory("<root id=\"r\"><item id=\"r\"></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlIdInventorySummary {
                token_count: 2,
                tag_count: 2,
                id_attribute_count: 2,
                unique_id_count: 1,
                duplicate_id_count: 1,
                id_value_bytes: 2,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_namespace_inventory() {
        let xml = "<?xml version=\"1.0\"?><wp:root xmlns:wp=\"https://wordpress.org\" xmlns:media=\"https://example.com/media\" id=\"root\"><wp:item id=\"7\" wp:slug=\"first\" media:type=\"image\"><media:title>Title</media:title><empty data-id=\"x\" /></wp:item></wp:root>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlNamespaceInventorySummary {
                token_count: 9,
                tag_count: 4,
                namespaced_tag_count: 3,
                attribute_count: 5,
                namespaced_attribute_count: 2,
                unique_namespace_count: 2,
            },
            summarize_xml_namespace_inventory(&document.tokens)
        );
        assert_eq!(
            XmlNamespaceInventorySummary {
                token_count: 7,
                tag_count: 3,
                namespaced_tag_count: 2,
                attribute_count: 4,
                namespaced_attribute_count: 2,
                unique_namespace_count: 2,
            },
            summarize_xml_namespace_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_namespace_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlNamespaceInventorySummary {
                token_count: 9,
                tag_count: 4,
                namespaced_tag_count: 3,
                attribute_count: 5,
                namespaced_attribute_count: 2,
                unique_namespace_count: 2,
            },
            scan.summary
        );

        let invalid_scan = summarize_xml_source_namespace_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlNamespaceInventorySummary {
                token_count: 2,
                tag_count: 2,
                namespaced_tag_count: 0,
                attribute_count: 0,
                namespaced_attribute_count: 0,
                unique_namespace_count: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_text_inventory() {
        let xml = "<?xml version=\"1.0\"?><root> Alpha <item>Two</item><data><![CDATA[raw]]></data><space>   </space></root>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlTextInventorySummary {
                token_count: 13,
                text_token_count: 3,
                cdata_count: 1,
                non_empty_text_count: 3,
                whitespace_text_count: 1,
                total_text_bytes: 16,
                max_text_bytes: 7,
            },
            summarize_xml_text_inventory(&document.tokens)
        );
        assert_eq!(
            XmlTextInventorySummary {
                token_count: 11,
                text_token_count: 3,
                cdata_count: 1,
                non_empty_text_count: 3,
                whitespace_text_count: 1,
                total_text_bytes: 16,
                max_text_bytes: 7,
            },
            summarize_xml_text_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_text_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            "13\x1f3\x1f1\x1f3\x1f1\x1f16\x1f7",
            xml_text_inventory_summary(&scan.summary)
        );

        let invalid_scan = summarize_xml_source_text_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlTextInventorySummary {
                token_count: 2,
                text_token_count: 0,
                cdata_count: 0,
                non_empty_text_count: 0,
                whitespace_text_count: 0,
                total_text_bytes: 0,
                max_text_bytes: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_processing_instruction_inventory() {
        let xml = "<?xml version=\"1.0\"?><root><?xml-stylesheet type=\"text/xsl\"?><?xml audit data?><item /></root><?xml trailing?>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlProcessingInstructionInventorySummary {
                token_count: 7,
                processing_instruction_count: 3,
                xml_declaration_count: 1,
                non_empty_instruction_count: 4,
                total_instruction_bytes: 64,
                max_instruction_bytes: 27,
            },
            summarize_xml_processing_instruction_inventory(&document.tokens)
        );
        assert_eq!(
            XmlProcessingInstructionInventorySummary {
                token_count: 5,
                processing_instruction_count: 3,
                xml_declaration_count: 0,
                non_empty_instruction_count: 3,
                total_instruction_bytes: 47,
                max_instruction_bytes: 27,
            },
            summarize_xml_processing_instruction_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_processing_instruction_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            "7\x1f3\x1f1\x1f4\x1f64\x1f27",
            xml_processing_instruction_inventory_summary(&scan.summary)
        );

        let invalid_scan =
            summarize_xml_source_processing_instruction_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlProcessingInstructionInventorySummary {
                token_count: 2,
                processing_instruction_count: 0,
                xml_declaration_count: 0,
                non_empty_instruction_count: 0,
                total_instruction_bytes: 0,
                max_instruction_bytes: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_comment_inventory() {
        let xml = "<?xml version=\"1.0\"?><root><!-- lead --><item><!-- --></item><empty><!--x--></empty><!--   --></root><!--trailer-->";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlCommentInventorySummary {
                token_count: 12,
                comment_count: 5,
                non_empty_comment_count: 3,
                empty_comment_count: 2,
                total_comment_bytes: 18,
                max_comment_bytes: 7,
            },
            summarize_xml_comment_inventory(&document.tokens)
        );
        assert_eq!(
            XmlCommentInventorySummary {
                token_count: 10,
                comment_count: 5,
                non_empty_comment_count: 3,
                empty_comment_count: 2,
                total_comment_bytes: 18,
                max_comment_bytes: 7,
            },
            summarize_xml_comment_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_comment_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            "12\x1f5\x1f3\x1f2\x1f18\x1f7",
            xml_comment_inventory_summary(&scan.summary)
        );

        let invalid_scan = summarize_xml_source_comment_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlCommentInventorySummary {
                token_count: 2,
                comment_count: 0,
                non_empty_comment_count: 0,
                empty_comment_count: 0,
                total_comment_bytes: 0,
                max_comment_bytes: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_payload_inventory() {
        let xml = "<?xml version=\"1.0\"?><root>Alpha<!-- note --><item><![CDATA[raw]]><?xml audit data?></item><space>   </space></root><?xml trailing?>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlPayloadInventorySummary {
                token_count: 13,
                text_token_count: 2,
                cdata_count: 1,
                comment_count: 1,
                processing_instruction_count: 2,
                total_payload_bytes: 37,
                max_payload_bytes: 11,
            },
            summarize_xml_payload_inventory(&document.tokens)
        );
        assert_eq!(
            XmlPayloadInventorySummary {
                token_count: 11,
                text_token_count: 2,
                cdata_count: 1,
                comment_count: 1,
                processing_instruction_count: 2,
                total_payload_bytes: 37,
                max_payload_bytes: 11,
            },
            summarize_xml_payload_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_payload_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            "13\x1f2\x1f1\x1f1\x1f2\x1f37\x1f11",
            xml_payload_inventory_summary(&scan.summary)
        );

        let invalid_scan = summarize_xml_source_payload_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlPayloadInventorySummary {
                token_count: 2,
                text_token_count: 0,
                cdata_count: 0,
                comment_count: 0,
                processing_instruction_count: 0,
                total_payload_bytes: 0,
                max_payload_bytes: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_content_inventory() {
        let xml = "<?xml version=\"1.0\"?><root id=\"root\"><item data-kind=\"post\">Title<!-- note --><![CDATA[raw]]><?xml audit data?></item><empty data-id=\"x\" /></root><?xml trailing?>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlContentInventorySummary {
                token_count: 11,
                tag_count: 3,
                attribute_count: 3,
                text_token_count: 1,
                cdata_count: 1,
                comment_count: 1,
                processing_instruction_count: 2,
                total_attribute_value_bytes: 9,
                max_attribute_value_bytes: 4,
                total_payload_bytes: 34,
                max_payload_bytes: 11,
            },
            summarize_xml_content_inventory(&document.tokens)
        );
        assert_eq!(
            XmlContentInventorySummary {
                token_count: 9,
                tag_count: 2,
                attribute_count: 2,
                text_token_count: 1,
                cdata_count: 1,
                comment_count: 1,
                processing_instruction_count: 2,
                total_attribute_value_bytes: 5,
                max_attribute_value_bytes: 4,
                total_payload_bytes: 34,
                max_payload_bytes: 11,
            },
            summarize_xml_content_inventory(&document.tokens[2..])
        );
        assert_eq!(
            "11\x1f3\x1f3\x1f1\x1f1\x1f1\x1f2\x1f9\x1f4\x1f34\x1f11",
            xml_content_inventory_summary(&summarize_xml_content_inventory(&document.tokens))
        );

        let scan = summarize_xml_source_content_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            summarize_xml_content_inventory(&document.tokens),
            scan.summary
        );

        let invalid_scan = summarize_xml_source_content_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlContentInventorySummary {
                token_count: 2,
                tag_count: 2,
                attribute_count: 0,
                text_token_count: 0,
                cdata_count: 0,
                comment_count: 0,
                processing_instruction_count: 0,
                total_attribute_value_bytes: 0,
                max_attribute_value_bytes: 0,
                total_payload_bytes: 0,
                max_payload_bytes: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_leaf_inventory() {
        let xml = "<?xml version=\"1.0\"?><root><section><item /><item><child /></item></section><single /></root>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlLeafInventorySummary {
                token_count: 10,
                tag_count: 6,
                closing_tag_count: 3,
                empty_element_count: 3,
                leaf_element_count: 3,
                branch_element_count: 3,
                max_child_element_count: 2,
            },
            summarize_xml_leaf_inventory(&document.tokens)
        );
        assert_eq!(
            XmlLeafInventorySummary {
                token_count: 8,
                tag_count: 5,
                closing_tag_count: 3,
                empty_element_count: 3,
                leaf_element_count: 3,
                branch_element_count: 2,
                max_child_element_count: 2,
            },
            summarize_xml_leaf_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_leaf_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            "10\x1f6\x1f3\x1f3\x1f3\x1f3\x1f2",
            xml_leaf_inventory_summary(&scan.summary)
        );

        let invalid_scan = summarize_xml_source_leaf_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlLeafInventorySummary {
                token_count: 2,
                tag_count: 2,
                closing_tag_count: 0,
                empty_element_count: 0,
                leaf_element_count: 0,
                branch_element_count: 0,
                max_child_element_count: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_structural_inventory() {
        let xml = "<?xml version=\"1.0\"?><wp:root xmlns:wp=\"https://wordpress.org\"><wp:section><wp:item /><wp:item><child /></wp:item></wp:section><single /></wp:root>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlStructuralInventorySummary {
                token_count: 10,
                tag_count: 6,
                closing_tag_count: 3,
                unique_tag_name_count: 5,
                duplicate_tag_name_count: 1,
                namespaced_tag_count: 4,
                empty_element_count: 3,
                root_level_tag_count: 1,
                nested_tag_count: 3,
                total_tag_depth: 15,
                max_depth: 4,
                leaf_element_count: 3,
                branch_element_count: 3,
                max_child_element_count: 2,
            },
            summarize_xml_structural_inventory(&document.tokens)
        );
        assert_eq!(
            XmlStructuralInventorySummary {
                token_count: 8,
                tag_count: 5,
                closing_tag_count: 3,
                unique_tag_name_count: 4,
                duplicate_tag_name_count: 1,
                namespaced_tag_count: 3,
                empty_element_count: 3,
                root_level_tag_count: 0,
                nested_tag_count: 3,
                total_tag_depth: 14,
                max_depth: 4,
                leaf_element_count: 3,
                branch_element_count: 2,
                max_child_element_count: 2,
            },
            summarize_xml_structural_inventory(&document.tokens[2..])
        );

        let scan = summarize_xml_source_structural_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            "10\x1f6\x1f3\x1f5\x1f1\x1f4\x1f3\x1f1\x1f3\x1f15\x1f4\x1f3\x1f3\x1f2",
            xml_structural_inventory_summary(&scan.summary)
        );

        let invalid_scan = summarize_xml_source_structural_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlStructuralInventorySummary {
                token_count: 2,
                tag_count: 2,
                closing_tag_count: 0,
                unique_tag_name_count: 2,
                duplicate_tag_name_count: 0,
                namespaced_tag_count: 0,
                empty_element_count: 0,
                root_level_tag_count: 1,
                nested_tag_count: 0,
                total_tag_depth: 3,
                max_depth: 2,
                leaf_element_count: 0,
                branch_element_count: 0,
                max_child_element_count: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_import_inventory() {
        let xml = "<?xml version=\"1.0\"?><root id=\"root\"><item data-kind=\"post\">Title<!-- note --><![CDATA[raw]]><?xml audit data?></item><empty data-id=\"x\" /></root><?xml trailing?>";
        let document = parse_xml_document(xml);

        assert_eq!(None, document.error);
        assert_eq!(
            XmlImportInventorySummary {
                structural: XmlStructuralInventorySummary {
                    token_count: 11,
                    tag_count: 3,
                    closing_tag_count: 2,
                    unique_tag_name_count: 3,
                    duplicate_tag_name_count: 0,
                    namespaced_tag_count: 0,
                    empty_element_count: 1,
                    root_level_tag_count: 1,
                    nested_tag_count: 0,
                    total_tag_depth: 5,
                    max_depth: 2,
                    leaf_element_count: 2,
                    branch_element_count: 1,
                    max_child_element_count: 2,
                },
                content: XmlContentInventorySummary {
                    token_count: 11,
                    tag_count: 3,
                    attribute_count: 3,
                    text_token_count: 1,
                    cdata_count: 1,
                    comment_count: 1,
                    processing_instruction_count: 2,
                    total_attribute_value_bytes: 9,
                    max_attribute_value_bytes: 4,
                    total_payload_bytes: 34,
                    max_payload_bytes: 11,
                },
            },
            summarize_xml_import_inventory(&document.tokens)
        );
        assert_eq!(
            XmlImportInventorySummary {
                structural: XmlStructuralInventorySummary {
                    token_count: 9,
                    tag_count: 2,
                    closing_tag_count: 2,
                    unique_tag_name_count: 2,
                    duplicate_tag_name_count: 0,
                    namespaced_tag_count: 0,
                    empty_element_count: 1,
                    root_level_tag_count: 0,
                    nested_tag_count: 0,
                    total_tag_depth: 4,
                    max_depth: 2,
                    leaf_element_count: 2,
                    branch_element_count: 0,
                    max_child_element_count: 0,
                },
                content: XmlContentInventorySummary {
                    token_count: 9,
                    tag_count: 2,
                    attribute_count: 2,
                    text_token_count: 1,
                    cdata_count: 1,
                    comment_count: 1,
                    processing_instruction_count: 2,
                    total_attribute_value_bytes: 5,
                    max_attribute_value_bytes: 4,
                    total_payload_bytes: 34,
                    max_payload_bytes: 11,
                },
            },
            summarize_xml_import_inventory(&document.tokens[2..])
        );
        assert_eq!(
            "11\x1f3\x1f2\x1f3\x1f0\x1f0\x1f1\x1f1\x1f0\x1f5\x1f2\x1f2\x1f1\x1f2\x1f3\x1f1\x1f1\x1f1\x1f2\x1f9\x1f4\x1f34\x1f11",
            xml_import_inventory_summary(&summarize_xml_import_inventory(&document.tokens))
        );

        let scan = summarize_xml_source_import_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            summarize_xml_import_inventory(&document.tokens),
            scan.summary
        );

        let invalid_scan = summarize_xml_source_import_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlImportInventorySummary {
                structural: XmlStructuralInventorySummary {
                    token_count: 2,
                    tag_count: 2,
                    closing_tag_count: 0,
                    unique_tag_name_count: 2,
                    duplicate_tag_name_count: 0,
                    namespaced_tag_count: 0,
                    empty_element_count: 0,
                    root_level_tag_count: 1,
                    nested_tag_count: 0,
                    total_tag_depth: 3,
                    max_depth: 2,
                    leaf_element_count: 0,
                    branch_element_count: 0,
                    max_child_element_count: 0,
                },
                content: XmlContentInventorySummary {
                    token_count: 2,
                    tag_count: 2,
                    attribute_count: 0,
                    text_token_count: 0,
                    cdata_count: 0,
                    comment_count: 0,
                    processing_instruction_count: 0,
                    total_attribute_value_bytes: 0,
                    max_attribute_value_bytes: 0,
                    total_payload_bytes: 0,
                    max_payload_bytes: 0,
                },
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_attribute_names_with_prefix_from_source() {
        let xml = "<root xmlns:wp=\"https://wordpress.org\"><wp:item wp:data-id=\"7\" data-kind=\"post\" /><item data-id=\"8\" /></root>";

        let scan = summarize_xml_source_attribute_names_with_prefix(xml, None, "data-");
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlAttributePrefixSummary {
                token_count: 4,
                tag_count: 3,
                attribute_count: 2,
            },
            scan.summary
        );

        let namespaced_scan = summarize_xml_source_attribute_names_with_prefix(
            xml,
            Some("https://wordpress.org"),
            "data-",
        );
        assert_eq!(None, namespaced_scan.error);
        assert_eq!(
            XmlAttributePrefixSummary {
                token_count: 4,
                tag_count: 3,
                attribute_count: 1,
            },
            namespaced_scan.summary
        );

        let invalid_scan =
            summarize_xml_source_attribute_names_with_prefix("<root><item></root>", None, "data-");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );

        let invalid_namespace_scan = summarize_xml_source_tag_stream("<root xmlns:a=\"\" />", "id");
        assert_eq!(
            Some("Invalid XML namespace declaration for `xmlns:a`.".to_string()),
            invalid_namespace_scan.error
        );
    }

    #[test]
    fn summarizes_xml_token_stream_from_source() {
        let xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><root id=\"root\"><item id=\"7\">Text</item><empty></empty></root>";

        let scan = summarize_xml_source_token_stream(xml, "id");
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlTokenStreamSummary {
                token_count: 8,
                tag_count: 3,
                attribute_count: 2,
            },
            scan.summary
        );

        let invalid_scan = summarize_xml_source_token_stream("<root><item></root>", "id");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
    }

    #[test]
    fn summarizes_xml_document_inventory_from_source() {
        let xml = "<?xml version=\"1.0\"?><root><!-- note --><item>Text</item><empty /><data><![CDATA[x]]></data></root>";

        let scan = summarize_xml_source_document_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlDocumentInventorySummary {
                token_count: 11,
                tag_count: 4,
                closing_tag_count: 3,
                text_token_count: 1,
                comment_count: 1,
                cdata_count: 1,
                max_depth: 2,
                empty_element_count: 1,
            },
            scan.summary
        );
        assert_eq!(
            "11\x1f4\x1f3\x1f1\x1f1\x1f1\x1f2\x1f1",
            xml_document_inventory_summary(&scan.summary)
        );

        let invalid_scan = summarize_xml_source_document_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
    }

    #[test]
    fn summarizes_xml_depth_inventory_from_source() {
        let xml = "<?xml version=\"1.0\"?><root><section><item /><item><child /></item></section><single /></root>";

        let scan = summarize_xml_source_depth_inventory(xml);
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlDepthInventorySummary {
                token_count: 10,
                tag_count: 6,
                closing_tag_count: 3,
                empty_element_count: 3,
                root_level_tag_count: 1,
                nested_tag_count: 3,
                total_tag_depth: 15,
                max_depth: 4,
            },
            scan.summary
        );
        assert_eq!(
            "10\x1f6\x1f3\x1f3\x1f1\x1f3\x1f15\x1f4",
            xml_depth_inventory_summary(&scan.summary)
        );

        let invalid_scan = summarize_xml_source_depth_inventory("<root><item></root>");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
    }

    #[test]
    fn summarizes_xml_tag_stream_from_source() {
        let xml = "<?xml version=\"1.0\"?><root version=\"root\"><item version=\"7\">Text</item><empty></empty></root>";

        let scan = summarize_xml_source_tag_stream(xml, "version");
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlTokenStreamSummary {
                token_count: 8,
                tag_count: 3,
                attribute_count: 2,
            },
            scan.summary
        );

        let invalid_scan = summarize_xml_source_tag_stream("<root><item></root>", "id");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
    }

    #[test]
    fn summarizes_xml_matching_tag_stream_from_source() {
        let xml = "<?xml version=\"1.0\"?><wp:root xmlns:wp=\"https://wordpress.org\"><wp:item id=\"7\"><wp:title>Title</wp:title></wp:item><item id=\"plain\" /><wp:item id=\"8\" /></wp:root>";

        let scan =
            summarize_xml_source_matching_tag_stream(xml, "https://wordpress.org", "item", "id");
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlTokenStreamSummary {
                token_count: 10,
                tag_count: 2,
                attribute_count: 2,
            },
            scan.summary
        );

        let plain_scan = summarize_xml_source_matching_tag_stream(xml, "", "item", "id");
        assert_eq!(None, plain_scan.error);
        assert_eq!(
            XmlTokenStreamSummary {
                token_count: 10,
                tag_count: 1,
                attribute_count: 1,
            },
            plain_scan.summary
        );

        let invalid_scan = summarize_xml_source_matching_tag_stream(
            "<root><wp:item></root>",
            "https://wordpress.org",
            "item",
            "id",
        );
        assert_eq!(
            Some("Undeclared XML namespace prefix.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlTokenStreamSummary {
                token_count: 1,
                tag_count: 0,
                attribute_count: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn summarizes_xml_matching_tag_attributes_from_source() {
        let xml = "<?xml version=\"1.0\"?><wp:root xmlns:wp=\"https://wordpress.org\"><wp:item id=\"7\" slug=\"first\"><wp:title>Title</wp:title></wp:item><item id=\"plain\" slug=\"skip\" /><wp:item id=\"8\" status=\"draft\" /></wp:root>";
        let attribute_names = parse_compact_attribute_names("id\x1fslug\x1fstatus");

        let scan = summarize_xml_source_matching_tag_attributes_stream(
            xml,
            "https://wordpress.org",
            "item",
            &attribute_names,
        );
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlTokenStreamSummary {
                token_count: 10,
                tag_count: 2,
                attribute_count: 4,
            },
            scan.summary
        );

        let invalid_scan = summarize_xml_source_matching_tag_attributes_stream(
            "<root><item></root>",
            "",
            "item",
            &attribute_names,
        );
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!(
            XmlTokenStreamSummary {
                token_count: 2,
                tag_count: 1,
                attribute_count: 0,
            },
            invalid_scan.summary
        );
    }

    #[test]
    fn creates_compact_xml_tag_summaries() {
        let document = parse_xml_document(
            "<?xml version=\"1.0\"?><root id=\"root\"><item id=\"7\">Text</item><empty /></root>",
        );

        assert_eq!(
            "2\x1froot\x1f\x1f0\x1f1\x1f1root",
            xml_tag_compact_summary(&document.tokens[1], 2, "id")
        );
        assert_eq!(
            "1\x1fitem\x1f\x1f0\x1f2\x1f17",
            xml_tag_compact_summary(&document.tokens[2], 1, "id")
        );
        assert_eq!(
            "3\x1fempty\x1f\x1f1\x1f2\x1f0",
            xml_tag_compact_summary(&document.tokens[5], 3, "id")
        );
    }

    #[test]
    fn matches_xml_tag_names_for_filtered_batches() {
        let document = parse_xml_document(
            "<wp:root xmlns:wp=\"https://wordpress.org\"><wp:item id=\"7\"><wp:title>Title</wp:title></wp:item><item id=\"plain\" /></wp:root>",
        );

        assert!(xml_token_matches_tag_name(
            &document.tokens[1],
            "https://wordpress.org",
            "item"
        ));
        assert!(!xml_token_matches_tag_name(
            &document.tokens[2],
            "https://wordpress.org",
            "item"
        ));
        assert!(!xml_token_matches_tag_name(&document.tokens[5], "", "item"));
        assert!(xml_token_matches_tag_name(&document.tokens[6], "", "item"));
    }

    #[test]
    fn removes_xml_attribute_names_with_prefix_from_source() {
        let xml = "<root xmlns:wp=\"https://wordpress.org\" data-root=\"1\"><wp:item wp:data-id=\"7\" data-kind=\"post\" keep=\"x\" /><item data-id=\"8\" /></root>";

        let scan = remove_xml_source_attributes_with_prefix(xml, None, "data-");
        assert_eq!(None, scan.error);
        assert_eq!(
            XmlAttributePrefixSummary {
                token_count: 4,
                tag_count: 3,
                attribute_count: 3,
            },
            scan.summary
        );
        assert_eq!(3, scan.removals.len());
        assert_eq!(
            "<root xmlns:wp=\"https://wordpress.org\" ><wp:item wp:data-id=\"7\"  keep=\"x\" /><item  /></root>",
            scan.xml
        );

        let namespaced_scan =
            remove_xml_source_attributes_with_prefix(xml, Some("https://wordpress.org"), "data-");
        assert_eq!(None, namespaced_scan.error);
        assert_eq!(
            XmlAttributePrefixSummary {
                token_count: 4,
                tag_count: 3,
                attribute_count: 1,
            },
            namespaced_scan.summary
        );
        assert_eq!(1, namespaced_scan.removals.len());
        assert_eq!(
            "<root xmlns:wp=\"https://wordpress.org\" data-root=\"1\"><wp:item  data-kind=\"post\" keep=\"x\" /><item data-id=\"8\" /></root>",
            namespaced_scan.xml
        );

        let invalid_scan =
            remove_xml_source_attributes_with_prefix("<root><item></root>", None, "data-");
        assert_eq!(
            Some("Mismatched closing tag `root` expected `item`.".to_string()),
            invalid_scan.error
        );
        assert_eq!("<root><item></root>", invalid_scan.xml);
    }

    #[test]
    fn enforces_misc_grammar_after_root_element() {
        let trailing_text = parse_xml_document("<root></root>text");
        assert!(trailing_text.error.is_some());

        let second_root = parse_xml_document("<root></root><another-root />");
        assert!(second_root.error.is_some());

        let cdata = parse_xml_document("<root></root><![CDATA[ cdata ]]>");
        assert!(cdata.error.is_some());

        let misc = parse_xml_document(
            "<root></root>   <?xml processing directive! ?> <!-- comment --> <?xml another pi ?>",
        );
        assert_eq!(None, misc.error);
    }

    #[test]
    fn reports_invalid_closing_tags_after_prior_tokens() {
        let document = parse_xml_document("<content>Test</content post-type=\"test\">");

        assert!(document.error.is_some());
        assert_eq!(2, document.tokens.len());
        assert_eq!("content", document.tokens[0].local_name);
        assert_eq!("#text", document.tokens[1].token_type);
        assert_eq!("Test", document.tokens[1].text);
    }

    #[test]
    fn rejects_malformed_attributes() {
        let disallowed_value = parse_xml_document("<root enabled=\"I love <3 this\" />");
        assert!(disallowed_value.error.is_some());
        assert!(disallowed_value.tokens.is_empty());

        let duplicate = parse_xml_document("<root id=\"first\" id=\"second\" />");
        assert!(duplicate.error.is_some());
        assert!(duplicate.tokens.is_empty());
    }

    #[test]
    fn rejects_namespace_errors() {
        let duplicate_expanded_attribute = parse_xml_document(
            "<x xmlns:n1=\"http://www.w3.org\" xmlns:n2=\"http://www.w3.org\"><bad n1:a=\"1\" n2:a=\"2\" /></x>",
        );
        assert!(duplicate_expanded_attribute.error.is_some());

        let undeclared_tag = parse_xml_document("<wp:content />");
        assert!(undeclared_tag.error.is_some());

        let undeclared_attribute = parse_xml_document("<root wp:attr=\"value\" />");
        assert!(undeclared_attribute.error.is_some());

        let reserved_xml = parse_xml_document("<root xmlns:xml=\"http://example.com\" />");
        assert!(reserved_xml.error.is_some());

        let reserved_xmlns = parse_xml_document("<root xmlns:xmlns=\"http://example.com\" />");
        assert!(reserved_xmlns.error.is_some());

        let empty_prefixed_namespace = parse_xml_document("<root xmlns:a=\"\" />");
        assert!(empty_prefixed_namespace.error.is_some());
        assert!(empty_prefixed_namespace.tokens.is_empty());
    }

    #[test]
    fn rejects_utf8_bom_at_start_of_document() {
        let document = parse_xml_document("\u{feff}<root>Content</root>");

        assert!(document.error.is_some());
        assert!(document.tokens.is_empty());
    }

    #[test]
    fn supports_uncommon_xml_name_characters() {
        let dotted = parse_xml_document("<my.tag my.attr=\"value\" />");
        assert_eq!(None, dotted.error);
        assert_eq!("my.tag", dotted.tokens[0].local_name);
        assert_eq!(
            Some(&"value".to_string()),
            dotted.tokens[0].attributes.get("my.attr")
        );

        let unicode = parse_xml_document("<tagὄ attrὄ=\"value\" />");
        assert_eq!(None, unicode.error);
        assert_eq!("tagὄ", unicode.tokens[0].local_name);
        assert_eq!(
            Some(&"value".to_string()),
            unicode.tokens[0].attributes.get("attrὄ")
        );
    }

    #[test]
    fn rejects_incomplete_opening_tags_without_exposing_them() {
        let document = parse_xml_document("<swit");

        assert!(document.error.is_some());
        assert!(document.tokens.is_empty());
    }

    #[test]
    fn records_breadcrumbs_and_depth_for_opening_and_closing_tags() {
        let document = parse_xml_document(
            "<root xmlns:wp=\"w.org\"><wp:text><post /></wp:text><image /></root>",
        );

        assert_eq!(None, document.error);
        assert_eq!(
            vec![("".to_string(), "root".to_string())],
            document.tokens[0].breadcrumbs
        );
        assert_eq!(1, document.tokens[0].depth);
        assert_eq!(
            vec![
                ("".to_string(), "root".to_string()),
                ("w.org".to_string(), "text".to_string())
            ],
            document.tokens[1].breadcrumbs
        );
        assert_eq!(2, document.tokens[1].depth);
        assert_eq!(
            vec![
                ("".to_string(), "root".to_string()),
                ("w.org".to_string(), "text".to_string()),
                ("".to_string(), "post".to_string())
            ],
            document.tokens[2].breadcrumbs
        );
        assert_eq!(3, document.tokens[2].depth);
        assert!(document.tokens[3].closing);
        assert_eq!(1, document.tokens[3].depth);
        assert_eq!(
            vec![
                ("".to_string(), "root".to_string()),
                ("".to_string(), "image".to_string())
            ],
            document.tokens[4].breadcrumbs
        );
        assert_eq!(2, document.tokens[4].depth);
    }

    #[test]
    fn reports_mismatched_tags() {
        let document = parse_xml_document("<root><item></root>");

        assert!(document.error.is_some());
    }

    #[test]
    fn resolves_element_and_attribute_namespaces() {
        let document = parse_xml_document(
            "<root xmlns=\"https://default.example\" xmlns:wp=\"https://wordpress.org\"><wp:item wp:id=\"7\" plain=\"yes\" /></root>",
        );

        assert_eq!(None, document.error);
        assert_eq!(
            Some("https://default.example".to_string()),
            document.tokens[0].namespace
        );
        assert_eq!("root", document.tokens[0].local_name);
        assert_eq!(
            Some("https://wordpress.org".to_string()),
            document.tokens[1].namespace
        );
        assert_eq!("item", document.tokens[1].local_name);
        assert_eq!(
            Some(&"7".to_string()),
            document.tokens[1]
                .attributes
                .get("{https://wordpress.org}id")
        );
        assert_eq!(
            Some(&"yes".to_string()),
            document.tokens[1].attributes.get("plain")
        );
    }

    #[test]
    fn exports_compact_current_token_metadata() {
        let document = parse_xml_document(
            "<root xmlns:wp=\"https://wordpress.org\"><wp:item wp:id=\"7\" /></root>",
        );

        assert_eq!(None, document.error);
        assert_eq!(
            "#tag\x1froot\x1froot\x1f\x1froot\x1f0\x1f0\x1f1",
            xml_token_metadata(&document.tokens[0])
        );
        assert_eq!(
            "#tag\x1fitem\x1fitem\x1fhttps://wordpress.org\x1f{https://wordpress.org}item\x1f0\x1f1\x1f2\x1f{https://wordpress.org}id\x1f7",
            xml_token_metadata(&document.tokens[1])
        );
        assert_eq!(
            "#tag\x1fitem\x1fitem\x1fhttps://wordpress.org\x1f{https://wordpress.org}item\x1f0\x1f1\x1f2",
            xml_token_summary(&document.tokens[1])
        );
        assert_eq!(
            "t\x1fitem\x1fhttps://wordpress.org\x1f01\x1f2\x1f0",
            xml_token_compact_summary(&document.tokens[1])
        );
    }

    #[test]
    fn fast_compact_xml_token_batches_match_full_tokens() {
        let xml = "<?xml version=\"1.0\"?><wp:root xmlns:wp=\"https://wordpress.org\" id=\"root\"><wp:item id=\"7\"><wp:title>Title</wp:title><!-- note --><![CDATA[x]]><?xml audit?></wp:item><empty /></wp:root>";
        let document = parse_xml_document(xml);
        let mut state = XmlStreamState::new();
        let mut rows = Vec::new();

        assert_eq!(None, document.error);
        loop {
            match parse_next_xml_stream_compact_summary(xml, &mut state) {
                Ok(Some(row)) => rows.push(row),
                Ok(None) => break,
                Err(error) => panic!("Unexpected compact stream error: {error}"),
            }
        }

        assert_eq!(
            document
                .tokens
                .iter()
                .map(xml_token_compact_summary)
                .collect::<Vec<_>>(),
            rows
        );
    }

    #[test]
    fn records_resolved_attribute_order() {
        let document = parse_xml_document(
            "<content xmlns:wp=\"http://wordpress.org/export/1.2/\" wp:data-foo=\"bar\" wp:data-bar=\"baz\" data-foo=\"no-ns\" />",
        );

        assert_eq!(None, document.error);
        assert_eq!(
            vec![
                "{http://wordpress.org/export/1.2/}data-foo".to_string(),
                "{http://wordpress.org/export/1.2/}data-bar".to_string(),
                "data-foo".to_string()
            ],
            document.tokens[0].attribute_order
        );
    }
}
