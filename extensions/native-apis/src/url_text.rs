#![cfg_attr(not(feature = "php-extension"), allow(dead_code))]

#[cfg(feature = "php-extension")]
use ext_php_rs::prelude::*;

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct UrlTextCandidate {
    pub raw_url: String,
    pub starts_at: usize,
    pub length: usize,
    pub had_protocol: bool,
}

#[cfg(feature = "php-extension")]
#[php_class]
#[php(name = "WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor")]
pub struct NativeUrlInTextProcessor {
    text: String,
    bytes_already_parsed: usize,
    current: Option<UrlTextCandidate>,
    replacements: Vec<UrlTextReplacement>,
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
    pub fn __construct(text: String) -> Self {
        Self {
            text,
            bytes_already_parsed: 0,
            current: None,
            replacements: Vec::new(),
        }
    }

    pub fn next_url(&mut self) -> bool {
        self.current = None;

        let Some(candidate) = find_next_url_text_candidate(&self.text, self.bytes_already_parsed)
        else {
            return false;
        };

        self.bytes_already_parsed = candidate.starts_at + candidate.length;
        self.current = Some(candidate);
        true
    }

    pub fn get_raw_url(&self) -> Option<String> {
        self.current
            .as_ref()
            .map(|candidate| candidate.raw_url.clone())
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

    pub fn set_raw_url(&mut self, new_url: String) -> bool {
        let Some(candidate) = self.current.as_mut() else {
            return false;
        };

        self.replacements.push(UrlTextReplacement {
            start: candidate.starts_at,
            length: candidate.length,
            text: new_url.clone(),
        });
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
        starts_at: start,
        length: display_end - start,
        had_protocol: had_protocol || start + 2 <= bytes.len() && &bytes[start..start + 2] == b"//",
    })
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
        && tld.bytes().all(|byte| byte.is_ascii_alphanumeric())
        && host.split('.').all(is_valid_hostname_label)
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
    use super::find_next_url_text_candidate;

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
}
