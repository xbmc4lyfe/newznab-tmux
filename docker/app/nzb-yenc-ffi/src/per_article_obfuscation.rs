//! Rust port of `HeaderParser::markPerArticleObfuscated()`, used through the guarded
//! `NativePerArticleObfuscation` wrapper with the PHP rule as fallback; parity and speed are
//! measured by scripts/benchmark-per-article-obfuscation.php. Every pattern is matched byte for byte with
//! the PCRE semantics PHP uses for these non-UTF patterns: `\s` is ASCII 0x09-0x0D and space,
//! `\d` is ASCII digits and `.` is any byte but `\n`.

use std::collections::HashMap;

const TOKEN_LENGTH: usize = 16;
const USENET_INDEX_POST: &[u8] = b"Usenet Index Post";

/// One article: its number and raw subject.
pub struct Article<'a> {
    pub number: i64,
    pub subject: &'a [u8],
}

struct Signature {
    /// Per distinct name: its first article number and part, and how many articles carry it.
    names: Vec<(i64, i64, u32)>,
    index: HashMap<Vec<u8>, usize>,
    articles: usize,
    total: i64,
}

/// Marked article numbers, distinct and ascending; `None` declines (an integer field
/// PHP would saturate), so the caller keeps the PHP result.
pub fn mark(articles: &[Article<'_>], min_articles: usize) -> Option<Vec<i64>> {
    if min_articles == 0 {
        return Some(Vec::new());
    }
    let mut signatures: Vec<Signature> = Vec::new();
    let mut by_key: HashMap<Vec<u8>, usize> = HashMap::new();
    // Reused buffers: lookups borrow them, and only a new key or name is copied.
    let mut key = Vec::new();
    let mut normalized = Vec::new();
    for article in articles {
        let Some((name, part, total)) = subject_parts(article.subject) else {
            continue;
        };
        let total_value = php_int(total)?;
        if total_value < 2 || !has_token(name) {
            continue;
        }
        let part_value = php_int(part)?;
        key.clear();
        normalized.clear();
        shape_and_normalized(name, &mut key, &mut normalized);
        key.push(0);
        key.extend_from_slice(total);
        key.push(0);
        if let Some((files, of)) = file_counter(name) {
            key.extend_from_slice(files);
            key.push(b'/');
            key.extend_from_slice(of);
        }
        let slot = match by_key.get(key.as_slice()) {
            Some(&slot) => slot,
            None => {
                signatures.push(Signature {
                    names: Vec::new(),
                    index: HashMap::new(),
                    articles: 0,
                    total: total_value,
                });
                by_key.insert(key.clone(), signatures.len() - 1);
                signatures.len() - 1
            }
        };
        let signature = &mut signatures[slot];
        let position = match signature.index.get(normalized.as_slice()) {
            Some(&position) => position,
            None => {
                signature.names.push((article.number, part_value, 0));
                signature
                    .index
                    .insert(normalized.clone(), signature.names.len() - 1);
                signature.names.len() - 1
            }
        };
        signature.names[position].2 += 1;
        signature.articles += 1;
    }

    let mut marked = Vec::new();
    let mut candidates: Vec<(i64, i64)> = Vec::new();
    let mut parts: Vec<i64> = Vec::new();
    for signature in &signatures {
        if signature.articles < min_articles || signature.names.len() * 10 < signature.articles * 9
        {
            continue;
        }
        candidates.clear();
        candidates.extend(
            signature
                .names
                .iter()
                .filter(|(_, part, count)| *count == 1 && *part != 1 && *part != signature.total)
                .map(|(number, part, _)| (*number, *part)),
        );
        // Keyed by number with the last write winning, as PHP's `$candidates[$number] = $part`.
        candidates.reverse();
        candidates.sort_by_key(|(number, _)| *number);
        candidates.dedup_by_key(|(number, _)| *number);
        parts.clear();
        parts.extend(candidates.iter().map(|(_, part)| *part));
        parts.sort_unstable();
        parts.dedup();
        if parts.len() < min_articles {
            continue;
        }
        marked.extend(candidates.iter().map(|(number, _)| *number));
    }
    marked.sort_unstable();
    marked.dedup();
    Some(marked)
}

fn is_space(byte: u8) -> bool {
    matches!(byte, b'\t' | b'\n' | 0x0b | 0x0c | b'\r' | b' ')
}

fn is_alnum(byte: u8) -> bool {
    byte.is_ascii_alphanumeric()
}

fn digits(bytes: &[u8], from: usize) -> usize {
    bytes[from..]
        .iter()
        .take_while(|byte| byte.is_ascii_digit())
        .count()
}

/// `^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)`: base name, part and total.
fn subject_parts(subject: &[u8]) -> Option<(&[u8], &[u8], &[u8])> {
    let lead = subject.iter().take_while(|byte| is_space(**byte)).count();
    for start in (0..=lead).rev() {
        if subject[start..].starts_with(USENET_INDEX_POST) {
            continue;
        }
        for end in start + 1..=subject.len() {
            if subject[end - 1] == b'\n' {
                break;
            }
            if let Some((part, total)) = part_tail(subject, end) {
                return Some((&subject[start..end], part, total));
            }
        }
    }
    None
}

/// `\s+\((\d+)\/(\d+)\)` at `at`.
fn part_tail(subject: &[u8], at: usize) -> Option<(&[u8], &[u8])> {
    let spaces = subject[at..]
        .iter()
        .take_while(|byte| is_space(**byte))
        .count();
    let mut cursor = at + spaces;
    if spaces == 0 || subject.get(cursor) != Some(&b'(') {
        return None;
    }
    cursor += 1;
    let part_length = digits(subject, cursor);
    if part_length == 0 || subject.get(cursor + part_length) != Some(&b'/') {
        return None;
    }
    let part = &subject[cursor..cursor + part_length];
    cursor += part_length + 1;
    let total_length = digits(subject, cursor);
    if total_length == 0 || subject.get(cursor + total_length) != Some(&b')') {
        return None;
    }
    Some((part, &subject[cursor..cursor + total_length]))
}

/// PHP's `(int)` of an ASCII digit string; `None` where PHP would saturate.
fn php_int(digits: &[u8]) -> Option<i64> {
    let significant = &digits[digits.iter().take_while(|byte| **byte == b'0').count()..];
    if significant.len() > 18 {
        return None;
    }
    Some(
        significant
            .iter()
            .fold(0_i64, |value, byte| value * 10 + i64::from(byte - b'0')),
    )
}

fn alnum_runs(name: &[u8]) -> impl Iterator<Item = (usize, usize)> + '_ {
    let mut index = 0;
    std::iter::from_fn(move || {
        while index < name.len() && !is_alnum(name[index]) {
            index += 1;
        }
        if index >= name.len() {
            return None;
        }
        let start = index;
        while index < name.len() && is_alnum(name[index]) {
            index += 1;
        }
        Some((start, index))
    })
}

fn has_token(name: &[u8]) -> bool {
    alnum_runs(name).any(|(start, end)| end - start >= TOKEN_LENGTH)
}

#[cfg(test)]
fn shape(name: &[u8]) -> Vec<u8> {
    let (mut shape, mut normalized) = (Vec::new(), Vec::new());
    shape_and_normalized(name, &mut shape, &mut normalized);
    shape
}

#[cfg(test)]
fn normalized_name(name: &[u8]) -> Vec<u8> {
    let (mut shape, mut normalized) = (Vec::new(), Vec::new());
    shape_and_normalized(name, &mut shape, &mut normalized);
    normalized
}

/// One pass over the name: the shape (alphanumeric runs collapsed to `x`) and the normalized
/// name (digits dropped except inside alphanumeric runs of token length).
fn shape_and_normalized(name: &[u8], shape: &mut Vec<u8>, normalized: &mut Vec<u8>) {
    let mut cursor = 0;
    for (start, end) in alnum_runs(name) {
        shape.extend_from_slice(&name[cursor..start]);
        normalized.extend_from_slice(&name[cursor..start]);
        shape.push(b'x');
        let run = &name[start..end];
        if run.len() >= TOKEN_LENGTH {
            normalized.extend_from_slice(run);
        } else {
            normalized.extend(run.iter().filter(|byte| !byte.is_ascii_digit()));
        }
        cursor = end;
    }
    shape.extend_from_slice(&name[cursor..]);
    normalized.extend_from_slice(&name[cursor..]);
}

/// `[[(\s](\d{1,5})(\/|[\s_]of[\s_]|-)(\d{1,5})[])[\s$:]` (case-insensitive): first match.
fn file_counter(name: &[u8]) -> Option<(&[u8], &[u8])> {
    for open in 0..name.len() {
        let byte = name[open];
        if byte != b'[' && byte != b'(' && !is_space(byte) {
            continue;
        }
        let first = open + 1;
        let first_length = digits(name, first);
        if !(1..=5).contains(&first_length) {
            continue;
        }
        let separator = first + first_length;
        let second = match name.get(separator) {
            Some(b'/') | Some(b'-') => separator + 1,
            Some(&byte)
                if (is_space(byte) || byte == b'_')
                    && name.len() >= separator + 4
                    && name[separator + 1..separator + 3].eq_ignore_ascii_case(b"of")
                    && (is_space(name[separator + 3]) || name[separator + 3] == b'_') =>
            {
                separator + 4
            }
            _ => continue,
        };
        let second_length = digits(name, second);
        if !(1..=5).contains(&second_length) {
            continue;
        }
        match name.get(second + second_length) {
            Some(&close)
                if matches!(close, b']' | b')' | b'[' | b'$' | b':') || is_space(close) =>
            {
                return Some((
                    &name[first..separator],
                    &name[second..second + second_length],
                ));
            }
            _ => continue,
        }
    }
    None
}

#[cfg(test)]
mod tests {
    use super::*;

    fn articles(subjects: &[(i64, String)]) -> Vec<Article<'_>> {
        subjects
            .iter()
            .map(|(number, subject)| Article {
                number: *number,
                subject: subject.as_bytes(),
            })
            .collect()
    }

    fn token(seed: u64) -> String {
        let alphabet = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
        let mut state = seed
            .wrapping_mul(6364136223846793005)
            .wrapping_add(1442695040888963407);
        (0..32)
            .map(|_| {
                state = state
                    .wrapping_mul(6364136223846793005)
                    .wrapping_add(1442695040888963407);
                alphabet[(state >> 33) as usize % alphabet.len()] as char
            })
            .collect()
    }

    #[test]
    fn per_article_obfuscation_marks_random_names_but_not_first_or_last_parts() {
        let subjects: Vec<(i64, String)> = (0..40)
            .map(|i| {
                (
                    1000 + i,
                    format!("[40/62] \"{}\" yEnc ({}/687)", token(i as u64), i + 1),
                )
            })
            .collect();
        let marked = mark(&articles(&subjects), 16).unwrap();
        assert_eq!(marked.len(), 39);
        assert!(!marked.contains(&1000));
    }

    #[test]
    fn per_article_obfuscation_keeps_repeating_and_round_robin_names() {
        let repeating: Vec<(i64, String)> = (0..40)
            .map(|i| {
                (
                    i,
                    format!(
                        "[40/62] \"{}\" yEnc ({}/687)",
                        token((i / 2) as u64),
                        i % 2 + 2
                    ),
                )
            })
            .collect();
        assert!(mark(&articles(&repeating), 16).unwrap().is_empty());
        let round_robin: Vec<(i64, String)> = (0..20)
            .map(|i| (i, format!("\"{}\" yEnc (2/100)", token(900 + i as u64))))
            .collect();
        assert!(mark(&articles(&round_robin), 16).unwrap().is_empty());
    }

    #[test]
    fn per_article_obfuscation_matches_php_patterns() {
        assert_eq!(
            subject_parts(b"  name.bin (3/9) yEnc"),
            Some((&b"name.bin"[..], &b"3"[..], &b"9"[..]))
        );
        assert_eq!(subject_parts(b"Usenet Index Post (1/2)"), None);
        assert_eq!(
            subject_parts(b" Usenet Index Post (1/2)"),
            Some((&b" Usenet Index Post"[..], &b"1"[..], &b"2"[..]))
        );
        assert_eq!(subject_parts(b"a\nb (1/2)"), None);
        assert_eq!(file_counter(b"x [03/62] y"), Some((&b"03"[..], &b"62"[..])));
        assert_eq!(
            file_counter(b"x (3 OF 62) y"),
            Some((&b"3"[..], &b"62"[..]))
        );
        assert_eq!(file_counter(b"x [123456/62] y"), None);
        assert_eq!(shape(b"[40/62] \"abc.def\""), b"[x/x] \"x.x\"".to_vec());
        assert_eq!(
            normalized_name(b"abcdefghijklmno7.part07.rar"),
            b"abcdefghijklmno7.part.rar".to_vec()
        );
        assert_eq!(
            normalized_name(b"short7.part07.rar"),
            b"short.part.rar".to_vec()
        );
        assert_eq!(php_int(b"0000000000000000000000002"), Some(2));
        assert_eq!(php_int(b"9999999999999999999"), None);
    }
}
