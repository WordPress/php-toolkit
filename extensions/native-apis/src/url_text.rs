#![cfg_attr(not(feature = "php-extension"), allow(dead_code))]

#[cfg(feature = "php-extension")]
use ext_php_rs::{
    prelude::*,
    types::{ZendCallable, Zval},
};

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct UrlTextCandidate {
    pub raw_url: String,
    pub preprocessed_url: String,
    pub starts_at: usize,
    pub length: usize,
    pub had_protocol: bool,
    pub did_prepend_protocol: bool,
}

#[cfg(feature = "php-extension")]
#[php_class]
#[php(name = "WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor")]
pub struct NativeUrlInTextProcessor {
    text: String,
    bytes_already_parsed: usize,
    current: Option<UrlTextCandidate>,
    replacements: Vec<UrlTextReplacement>,
    validate_urls: bool,
    base_url: Option<String>,
    base_protocol: Option<String>,
}

#[derive(Clone, Debug)]
struct UrlTextReplacement {
    start: usize,
    length: usize,
    text: String,
}

#[cfg(feature = "php-extension")]
#[php_impl]
#[php(change_method_case = "snake_case")]
impl NativeUrlInTextProcessor {
    #[php(optional = base_url)]
    pub fn __construct(text: String, base_url: Option<String>) -> Self {
        let base_protocol = base_url.as_deref().and_then(parse_url_scheme);

        Self {
            text,
            bytes_already_parsed: 0,
            current: None,
            replacements: Vec::new(),
            validate_urls: true,
            base_url,
            base_protocol,
        }
    }

    pub fn supports_public_api() -> bool {
        true
    }

    pub fn use_url_validation(&mut self) {
        self.validate_urls = true;
    }

    pub fn set_base_url(&mut self, base_url: String) {
        self.base_protocol = parse_url_scheme(&base_url);
        self.base_url = Some(base_url);
    }

    pub fn next_url(&mut self) -> bool {
        self.current = None;

        while let Some(mut candidate) =
            find_next_url_text_candidate(&self.text, self.bytes_already_parsed)
        {
            self.bytes_already_parsed = candidate.starts_at + candidate.length;

            if self.validate_urls
                && !validate_url_text_candidate(&mut candidate, self.base_protocol.as_deref())
            {
                continue;
            }

            self.current = Some(candidate);
            return true;
        }

        false
    }

    pub fn get_raw_url(&self) -> Option<String> {
        self.current
            .as_ref()
            .map(|candidate| candidate.raw_url.clone())
    }

    pub fn get_preprocessed_url(&self) -> Option<String> {
        self.current
            .as_ref()
            .map(|candidate| candidate.preprocessed_url.clone())
    }

    pub fn get_parsed_url(&self) -> Zval {
        let Some(candidate) = self.current.as_ref() else {
            return url_zval_bool(false);
        };

        let Ok(callable) =
            ZendCallable::try_from_name("WordPress\\DataLiberation\\URL\\WPURL::parse")
        else {
            return url_zval_bool(false);
        };

        let result = match self.base_url.as_ref() {
            Some(base_url) => callable.try_call(vec![&candidate.preprocessed_url, base_url]),
            None => callable.try_call(vec![&candidate.preprocessed_url]),
        };

        match result {
            Ok(value) if !value.is_false() && !value.is_null() => value,
            _ => url_zval_bool(false),
        }
    }

    pub fn get_url_starts_at(&self) -> Option<i64> {
        self.current
            .as_ref()
            .map(|candidate| candidate.starts_at as i64)
    }

    pub fn get_url_length(&self) -> Option<i64> {
        self.current
            .as_ref()
            .map(|candidate| candidate.length as i64)
    }

    pub fn had_protocol(&self) -> Option<bool> {
        self.current
            .as_ref()
            .map(|candidate| candidate.had_protocol)
    }

    pub fn did_prepend_protocol(&self) -> Option<bool> {
        self.current
            .as_ref()
            .map(|candidate| candidate.did_prepend_protocol)
    }

    pub fn set_raw_url(&mut self, new_url: String) -> bool {
        let Some(candidate) = self.current.as_mut() else {
            return false;
        };

        if let Some(replacement) = self
            .replacements
            .iter_mut()
            .find(|replacement| replacement.start == candidate.starts_at)
        {
            replacement.length = candidate.length;
            replacement.text = new_url.clone();
        } else {
            self.replacements.push(UrlTextReplacement {
                start: candidate.starts_at,
                length: candidate.length,
                text: new_url.clone(),
            });
        }
        candidate.raw_url = new_url;
        true
    }

    pub fn get_updated_text(&mut self) -> String {
        if self.replacements.is_empty() {
            return self.text.clone();
        }

        self.replacements
            .sort_by(|left, right| left.start.cmp(&right.start));

        let mut output = String::with_capacity(self.text.len());
        let mut copied = 0;
        for replacement in &self.replacements {
            if replacement.start < copied {
                continue;
            }

            output.push_str(&self.text[copied..replacement.start]);
            output.push_str(&replacement.text);

            if replacement.start < self.bytes_already_parsed {
                let old_end = replacement.start + replacement.length;
                let old_cursor_delta = self.bytes_already_parsed.saturating_sub(old_end);
                self.bytes_already_parsed = output.len() + old_cursor_delta;
            }

            if let Some(current) = self.current.as_mut() {
                if current.starts_at == replacement.start {
                    current.starts_at = output.len() - replacement.text.len();
                    current.length = replacement.text.len();
                }
            }

            copied = replacement.start + replacement.length;
        }

        output.push_str(&self.text[copied..]);
        self.text = output;
        self.replacements.clear();

        self.text.clone()
    }
}

pub fn find_next_url_text_candidate(text: &str, offset: usize) -> Option<UrlTextCandidate> {
    let bytes = text.as_bytes();
    let mut cursor = offset.min(bytes.len());

    while cursor < bytes.len() {
        if !is_url_left_boundary(bytes, cursor) {
            cursor += 1;
            continue;
        }

        if let Some(candidate) = parse_url_text_candidate_at(text, cursor) {
            return Some(candidate);
        }

        cursor += 1;
    }

    None
}

#[derive(Clone, Debug)]
struct PlainTextLiteralMapping {
    from: String,
    to: String,
}

#[derive(Clone, Debug)]
struct PlainTextLiteralReplacement {
    start: usize,
    length: usize,
    text: String,
}

pub fn rewrite_plain_text_literal_urls(text: &str, compact_mapping: &str) -> Option<String> {
    if has_plain_text_literal_structural_delimiter(text.as_bytes()) {
        return None;
    }

    let mappings = parse_plain_text_literal_mappings(compact_mapping);
    if mappings.is_empty() {
        return None;
    }

    let mut replacements = Vec::new();
    for mapping in &mappings {
        let mut offset = 0;
        while offset + mapping.from.len() <= text.len() {
            let Some(relative_position) =
                ascii_find_case_insensitive(&text.as_bytes()[offset..], mapping.from.as_bytes())
            else {
                break;
            };

            let position = offset + relative_position;
            if !plain_text_literal_origin_has_valid_left_boundary(text.as_bytes(), position)
                || !plain_text_literal_origin_has_valid_right_boundary(
                    text.as_bytes(),
                    position + mapping.from.len(),
                )
            {
                return None;
            }

            replacements.push(PlainTextLiteralReplacement {
                start: position,
                length: mapping.from.len(),
                text: mapping.to.clone(),
            });
            offset = position + mapping.from.len();
        }
    }

    if replacements.is_empty() {
        return None;
    }

    replacements.sort_by(|left, right| left.start.cmp(&right.start));

    let mut output = String::with_capacity(text.len());
    let mut copied = 0;
    for replacement in replacements {
        if replacement.start < copied {
            return None;
        }

        output.push_str(&text[copied..replacement.start]);
        output.push_str(&replacement.text);
        copied = replacement.start + replacement.length;
    }
    output.push_str(&text[copied..]);

    Some(output)
}

fn parse_plain_text_literal_mappings(compact_mapping: &str) -> Vec<PlainTextLiteralMapping> {
    compact_mapping
        .split('\x1e')
        .filter_map(|row| {
            let (from, to) = row.split_once('\x1f')?;
            if !is_valid_plain_text_literal_mapping(from, to) {
                return None;
            }

            Some(PlainTextLiteralMapping {
                from: from.to_string(),
                to: to.to_string(),
            })
        })
        .collect()
}

fn is_valid_plain_text_literal_mapping(from: &str, to: &str) -> bool {
    if from.is_empty() || to.is_empty() || !from.is_ascii() || !to.is_ascii() {
        return false;
    }

    let Some(from_origin_end) = plain_text_literal_http_origin_end(from) else {
        return false;
    };
    if from_origin_end != from.len() {
        return false;
    }

    let Some(to_origin_end) = plain_text_literal_http_origin_end(to) else {
        return false;
    };
    if to[to_origin_end..].contains('?') || to[to_origin_end..].contains('#') {
        return false;
    }
    to_origin_end == to.len() || to.as_bytes()[to_origin_end] == b'/'
}

fn plain_text_literal_http_origin_end(url: &str) -> Option<usize> {
    let bytes = url.as_bytes();
    let authority_start = if ascii_starts_with(bytes, 0, b"http://") {
        7
    } else if ascii_starts_with(bytes, 0, b"https://") {
        8
    } else {
        return None;
    };

    let authority_end = bytes[authority_start..]
        .iter()
        .position(|byte| matches!(*byte, b'/' | b'?' | b'#'))
        .map(|offset| authority_start + offset)
        .unwrap_or(bytes.len());
    if authority_end <= authority_start || bytes[authority_start..authority_end].contains(&b'@') {
        return None;
    }

    Some(authority_end)
}

fn has_plain_text_literal_structural_delimiter(bytes: &[u8]) -> bool {
    bytes.iter().any(|byte| {
        matches!(
            *byte,
            b'<' | b'>' | b'"' | b'\'' | b'\\' | b'{' | b'}' | b'[' | b']' | b'(' | b')'
        )
    })
}

fn plain_text_literal_origin_has_valid_left_boundary(bytes: &[u8], position: usize) -> bool {
    position == 0 || bytes[position - 1].is_ascii_whitespace()
}

fn plain_text_literal_origin_has_valid_right_boundary(bytes: &[u8], position: usize) -> bool {
    position >= bytes.len() || matches!(bytes[position], b'/' | b'?' | b'#')
}

fn ascii_find_case_insensitive(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() || haystack.len() < needle.len() {
        return None;
    }

    (0..=haystack.len() - needle.len())
        .find(|offset| haystack[*offset..*offset + needle.len()].eq_ignore_ascii_case(needle))
}

fn parse_url_text_candidate_at(text: &str, start: usize) -> Option<UrlTextCandidate> {
    let bytes = text.as_bytes();
    let mut had_protocol = false;
    let mut host_start = start;

    if ascii_starts_with(bytes, start, b"https:") {
        had_protocol = true;
        host_start = start + 6;
    } else if ascii_starts_with(bytes, start, b"http:") {
        had_protocol = true;
        host_start = start + 5;
    } else if start + 2 <= bytes.len() && &bytes[start..start + 2] == b"//" {
        if start > 0 && bytes[start - 1] == b':' {
            return None;
        }
        host_start = start + 2;
    }

    if had_protocol {
        while host_start < bytes.len() && bytes[host_start] == b'/' {
            host_start += 1;
        }
    }

    if host_start >= bytes.len() {
        return None;
    }

    let mut host_end = host_start;
    while host_end < bytes.len() && is_hostish_byte(bytes[host_end]) {
        host_end += 1;
    }

    if host_end <= host_start || !candidate_host_has_url_shape(&text[host_start..host_end]) {
        return None;
    }

    let mut end = find_candidate_end(bytes, host_end);
    let trimmed_end = trim_candidate_end(bytes, start, end);
    if trimmed_end <= start {
        return None;
    }

    if let Some(port_colon) = malformed_port_colon(bytes, host_end, end) {
        end = port_colon;
    }

    let display_end = trim_candidate_end(bytes, start, end);
    if display_end <= start {
        return None;
    }

    Some(UrlTextCandidate {
        raw_url: text[start..display_end].to_string(),
        preprocessed_url: text[start..display_end].to_string(),
        starts_at: start,
        length: display_end - start,
        had_protocol: had_protocol || start + 2 <= bytes.len() && &bytes[start..start + 2] == b"//",
        did_prepend_protocol: false,
    })
}

fn validate_url_text_candidate(
    candidate: &mut UrlTextCandidate,
    base_protocol: Option<&str>,
) -> bool {
    let mut preprocessed_url = candidate.raw_url.clone();
    if !candidate.had_protocol {
        let Some(protocol) = base_protocol else {
            return false;
        };

        if !is_http_or_https_scheme(protocol) {
            return false;
        }

        preprocessed_url = format!("{protocol}://{}", candidate.raw_url);
        candidate.did_prepend_protocol = true;
    } else if preprocessed_url.starts_with("//") {
        let Some(protocol) = base_protocol else {
            return false;
        };

        if !is_http_or_https_scheme(protocol) {
            return false;
        }
    } else if !starts_with_http_or_https_scheme(&preprocessed_url) {
        return false;
    }

    if has_authority_auth_details(&preprocessed_url) {
        return false;
    }

    if has_invalid_authority_port(&preprocessed_url) {
        return false;
    }

    if !candidate.had_protocol {
        let Some(hostname) = candidate_hostname(&candidate.raw_url) else {
            return false;
        };

        let Some(last_dot) = hostname.rfind('.') else {
            return false;
        };

        if !is_known_public_domain(&hostname[last_dot + 1..]) {
            return false;
        }
    }

    candidate.preprocessed_url = preprocessed_url;
    true
}

#[cfg(feature = "php-extension")]
fn url_zval_bool(value: bool) -> Zval {
    let mut zval = Zval::new();
    zval.set_bool(value);
    zval
}

fn parse_url_scheme(url: &str) -> Option<String> {
    let colon = url.find(':')?;
    let first_delimiter = url
        .find(|character| matches!(character, '/' | '?' | '#'))
        .unwrap_or(url.len());
    if colon > first_delimiter {
        return None;
    }

    Some(url[..colon].to_ascii_lowercase())
}

fn is_http_or_https_scheme(scheme: &str) -> bool {
    scheme.eq_ignore_ascii_case("http") || scheme.eq_ignore_ascii_case("https")
}

fn starts_with_http_or_https_scheme(url: &str) -> bool {
    ascii_starts_with(url.as_bytes(), 0, b"http:")
        || ascii_starts_with(url.as_bytes(), 0, b"https:")
}

fn authority_range(url: &str) -> Option<(usize, usize)> {
    let bytes = url.as_bytes();
    let authority_start = if bytes.starts_with(b"//") {
        2
    } else if ascii_starts_with(bytes, 0, b"http://") {
        7
    } else if ascii_starts_with(bytes, 0, b"https://") {
        8
    } else {
        return None;
    };

    let authority_end = bytes[authority_start..]
        .iter()
        .position(|byte| matches!(*byte, b'/' | b'?' | b'#'))
        .map(|offset| authority_start + offset)
        .unwrap_or(bytes.len());

    Some((authority_start, authority_end))
}

fn has_authority_auth_details(url: &str) -> bool {
    let Some((start, end)) = authority_range(url) else {
        return false;
    };

    url.as_bytes()[start..end].contains(&b'@')
}

fn has_invalid_authority_port(url: &str) -> bool {
    let Some((start, end)) = authority_range(url) else {
        return false;
    };

    let authority = &url[start..end];
    if authority.starts_with('[') {
        return authority.find(']').is_none();
    }

    let Some(colon) = authority.rfind(':') else {
        return false;
    };

    let port = &authority[colon + 1..];
    !port.is_empty()
        && port.bytes().all(|byte| byte.is_ascii_digit())
        && port.parse::<u16>().is_err()
}

fn candidate_hostname(raw_url: &str) -> Option<&str> {
    let bytes = raw_url.as_bytes();
    let mut start = 0;
    if bytes.starts_with(b"//") {
        start = 2;
    } else if ascii_starts_with(bytes, 0, b"http:") {
        start = 5;
        while start < bytes.len() && bytes[start] == b'/' {
            start += 1;
        }
    } else if ascii_starts_with(bytes, 0, b"https:") {
        start = 6;
        while start < bytes.len() && bytes[start] == b'/' {
            start += 1;
        }
    }

    let end = bytes[start..]
        .iter()
        .position(|byte| !is_hostish_byte(*byte))
        .map(|offset| start + offset)
        .unwrap_or(bytes.len());
    if end <= start {
        return None;
    }

    Some(&raw_url[start..end])
}

fn find_candidate_end(bytes: &[u8], mut cursor: usize) -> usize {
    while cursor < bytes.len() {
        let byte = bytes[cursor];
        if byte <= b' ' || byte == b'<' || byte == b'>' {
            break;
        }
        cursor += 1;
    }

    cursor
}

fn malformed_port_colon(bytes: &[u8], host_end: usize, candidate_end: usize) -> Option<usize> {
    if host_end >= candidate_end || bytes[host_end] != b':' {
        return None;
    }

    let mut cursor = host_end + 1;
    let digits_start = cursor;
    while cursor < candidate_end && bytes[cursor].is_ascii_digit() {
        cursor += 1;
    }

    let digit_count = cursor - digits_start;
    if digit_count == 0 || digit_count > 5 {
        return Some(host_end);
    }

    None
}

fn trim_candidate_end(bytes: &[u8], start: usize, mut end: usize) -> usize {
    while end > start && is_trailing_url_punctuation(bytes[end - 1]) {
        end -= 1;
    }

    end
}

fn is_trailing_url_punctuation(byte: u8) -> bool {
    matches!(
        byte,
        b'(' | b'{' | b'[' | b'`' | b'!' | b';' | b':' | b'\'' | b'"' | b'.' | b',' | b'?' | b')'
    )
}

fn is_url_left_boundary(bytes: &[u8], offset: usize) -> bool {
    if offset == 0 {
        return true;
    }

    if offset >= 2 && bytes[offset - 1] == b'/' && bytes[offset - 2] == b'/' {
        return false;
    }

    let previous = bytes[offset - 1];
    !(previous.is_ascii_alphanumeric()
        || previous == b'_'
        || previous == b'-'
        || previous == b'.'
        || previous == b'@')
}

fn candidate_host_has_url_shape(host: &str) -> bool {
    if host.eq_ignore_ascii_case("localhost") || host.parse::<std::net::Ipv4Addr>().is_ok() {
        return true;
    }

    let Some(last_dot) = host.rfind('.') else {
        return false;
    };
    let tld = &host[last_dot + 1..];
    tld.len() >= 2
        && tld.len() <= 63
        && tld
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'-')
        && host.split('.').all(is_valid_hostname_label)
}

fn is_known_public_domain(tld: &str) -> bool {
    if tld.eq_ignore_ascii_case("internal") {
        return true;
    }

    if tld.is_empty()
        || !tld
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'-')
    {
        return false;
    }

    let needle = format!("'{}'", tld.to_ascii_lowercase());
    include_str!("../../../components/DataLiberation/URL/public-suffix-list.php").contains(&needle)
}

fn is_valid_hostname_label(label: &str) -> bool {
    let bytes = label.as_bytes();
    !bytes.is_empty()
        && bytes.len() <= 63
        && bytes[0] != b'-'
        && bytes[bytes.len() - 1] != b'-'
        && bytes
            .iter()
            .all(|byte| byte.is_ascii_alphanumeric() || *byte == b'-' || *byte == b'%')
}

fn is_hostish_byte(byte: u8) -> bool {
    byte.is_ascii_alphanumeric()
        || byte == b'.'
        || byte == b'-'
        || byte == b'%'
        || byte == b'['
        || byte == b']'
}

fn ascii_starts_with(bytes: &[u8], offset: usize, needle: &[u8]) -> bool {
    offset + needle.len() <= bytes.len()
        && bytes[offset..offset + needle.len()].eq_ignore_ascii_case(needle)
}

#[cfg(test)]
mod tests {
    use super::{
        find_next_url_text_candidate, rewrite_plain_text_literal_urls, validate_url_text_candidate,
        UrlTextCandidate,
    };

    #[test]
    fn finds_http_https_and_bare_domain_candidates() {
        let text = "Visit https://example.com/a?x=1, then example.org/docs.";
        let first = find_next_url_text_candidate(text, 0).expect("first URL");
        assert_eq!("https://example.com/a?x=1", first.raw_url);
        assert_eq!(6, first.starts_at);

        let second =
            find_next_url_text_candidate(text, first.starts_at + first.length).expect("second URL");
        assert_eq!("example.org/docs", second.raw_url);
    }

    #[test]
    fn trims_common_trailing_punctuation() {
        let text = "See (https://wordpress.org/plugins).";
        let candidate = find_next_url_text_candidate(text, 0).expect("URL");
        assert_eq!("https://wordpress.org/plugins", candidate.raw_url);
        assert_eq!(candidate.raw_url.len(), candidate.length);
    }

    #[test]
    fn truncates_malformed_ports_at_the_colon() {
        let text = "Visit http://w.org:/c now";
        let candidate = find_next_url_text_candidate(text, 0).expect("URL");
        assert_eq!("http://w.org", candidate.raw_url);
    }

    #[test]
    fn ignores_embedded_protocol_fragments() {
        assert!(find_next_url_text_candidate("ahttp://example.com", 0).is_none());
    }

    #[test]
    fn accepts_punycode_tlds() {
        let text = "Visit http://xn--fsqu00a.xn--0zwm56d";
        let candidate = find_next_url_text_candidate(text, 0).expect("URL");
        assert_eq!("http://xn--fsqu00a.xn--0zwm56d", candidate.raw_url);
    }

    #[test]
    fn validates_public_url_candidates_with_base_protocol() {
        let mut candidate = find_next_url_text_candidate("Visit example.com/docs", 0).expect("URL");
        assert!(validate_url_text_candidate(&mut candidate, Some("https")));
        assert_eq!("https://example.com/docs", candidate.preprocessed_url);
        assert!(candidate.did_prepend_protocol);
    }

    #[test]
    fn rejects_filename_like_bare_domains_with_unknown_tlds() {
        let mut candidate = find_next_url_text_candidate("Edit plugins.php", 0).expect("candidate");
        assert!(!validate_url_text_candidate(&mut candidate, Some("https")));
    }

    #[test]
    fn rejects_authority_credentials() {
        let mut candidate = UrlTextCandidate {
            raw_url: "https://user@example.com/path".to_string(),
            preprocessed_url: "https://user@example.com/path".to_string(),
            starts_at: 6,
            length: 29,
            had_protocol: true,
            did_prepend_protocol: false,
        };
        assert!(!validate_url_text_candidate(&mut candidate, Some("https")));
    }

    #[test]
    fn rejects_bare_domains_without_base_protocol() {
        let mut candidate = find_next_url_text_candidate("Visit example.com", 0).expect("URL");
        assert!(!validate_url_text_candidate(&mut candidate, None));
    }

    #[test]
    fn rewrites_plain_text_literal_url_origins() {
        let mapping = "http://old.example\x1fhttps://new.example/base";
        assert_eq!(
            Some(
                "See https://new.example/base/posts/7 and https://new.example/base/meta."
                    .to_string()
            ),
            rewrite_plain_text_literal_urls(
                "See http://old.example/posts/7 and http://old.example/meta.",
                mapping,
            )
        );
    }

    #[test]
    fn rewrites_plain_text_literal_origins_case_insensitively() {
        let mapping = "http://old.example\x1fhttps://new.example";
        assert_eq!(
            Some("See https://new.example/posts/7.".to_string()),
            rewrite_plain_text_literal_urls("See HTTP://OLD.EXAMPLE/posts/7.", mapping)
        );
    }

    #[test]
    fn refuses_structured_text_literal_url_rewrites() {
        let mapping = "http://old.example\x1fhttps://new.example";
        assert_eq!(
            None,
            rewrite_plain_text_literal_urls("{\"url\":\"http://old.example/posts/7\"}", mapping)
        );
    }

    #[test]
    fn refuses_plain_text_literal_embedded_hosts() {
        let mapping = "http://old.example\x1fhttps://new.example";
        assert_eq!(
            None,
            rewrite_plain_text_literal_urls("See http://old.example.com/posts/7.", mapping)
        );
        assert_eq!(
            None,
            rewrite_plain_text_literal_urls("See xhttp://old.example/posts/7.", mapping)
        );
    }

    #[test]
    fn refuses_non_origin_literal_mappings() {
        assert_eq!(
            None,
            rewrite_plain_text_literal_urls(
                "See http://old.example/posts/7.",
                "http://old.example/posts\x1fhttps://new.example",
            )
        );
        assert_eq!(
            None,
            rewrite_plain_text_literal_urls(
                "See http://old.example/posts/7.",
                "http://old.example\x1fhttps://new.example?query=1",
            )
        );
    }
}
