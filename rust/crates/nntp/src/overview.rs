use std::sync::Arc;

/// One XOVER row, with fields named by the server's overview format.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct OverviewRow {
    keys: Arc<[String]>,
    values: Vec<Vec<u8>>,
}

impl OverviewRow {
    pub(crate) fn new(keys: Arc<[String]>, values: Vec<Vec<u8>>) -> Self {
        Self { keys, values }
    }

    pub fn get(&self, key: &str) -> Option<&[u8]> {
        let position = self.keys.iter().position(|name| name == key)?;
        self.values.get(position).map(Vec::as_slice)
    }

    /// Every field the row carries, in the server's order.
    pub fn fields(&self) -> impl Iterator<Item = (&str, &[u8])> {
        self.keys
            .iter()
            .map(String::as_str)
            .zip(self.values.iter().map(Vec::as_slice))
    }
}

/// `LIST OVERVIEW.FMT` lines: a trailing `:full` means values repeat their header name.
/// Later duplicates update the flag but keep the first position.
pub(crate) fn parse_format(lines: &[Vec<u8>]) -> Vec<(String, bool)> {
    let mut format: Vec<(String, bool)> = Vec::new();
    for line in lines {
        let is_full = line.len() >= 5 && line[line.len() - 5..].eq_ignore_ascii_case(b":full");
        let name = if is_full {
            &line[..line.len() - 5]
        } else {
            &line[..line.len().saturating_sub(1)]
        };
        set(
            &mut format,
            String::from_utf8_lossy(name).into_owned(),
            is_full,
        );
    }
    format
}

fn set(format: &mut Vec<(String, bool)>, name: String, full: bool) {
    match format.iter_mut().find(|(existing, _)| *existing == name) {
        Some(entry) => entry.1 = full,
        None => format.push((name, full)),
    }
}

/// The format `get_overview` uses: `Number` first, then the server's fields. It's cached in
/// this form too, and `xover_keys` won't add `Number` twice.
pub(crate) fn numbered_format(mut format: Vec<(String, bool)>) -> Vec<(String, bool)> {
    if format.first().is_none_or(|(name, _)| name != "Number") {
        format.retain(|(name, _)| name != "Number");
        format.insert(0, ("Number".to_string(), false));
    }
    format
}

/// Field names for `getXOVER`: `Number` first and never twice.
pub(crate) fn xover_keys(format: &[(String, bool)]) -> Vec<String> {
    let mut keys = vec!["Number".to_string()];
    keys.extend(
        format
            .iter()
            .map(|(name, _)| name)
            .filter(|name| *name != "Number")
            .cloned(),
    );
    keys
}

/// A `getXOVER` row: fields past the format are dropped, missing ones stay absent, and the
/// `Xref: ` prefix is cut by position.
pub(crate) fn xover_row(keys: &Arc<[String]>, line: &[u8]) -> OverviewRow {
    let xref = keys.iter().position(|key| key == "Xref");
    let values = line
        .split(|byte| *byte == b'\t')
        .take(keys.len())
        .enumerate()
        .map(|(index, value)| {
            if Some(index) == xref && value.len() > 5 {
                value[6.min(value.len())..].to_vec()
            } else {
                value.to_vec()
            }
        })
        .collect();
    OverviewRow::new(Arc::clone(keys), values)
}

/// A `getOverview` row: every format field is present, and `:full` values lose their
/// `Name:` prefix and following blanks.
pub(crate) fn named_row(
    keys: &Arc<[String]>,
    format: &[(String, bool)],
    line: &[u8],
) -> OverviewRow {
    let mut parts = line.split(|byte| *byte == b'\t');
    let values = format
        .iter()
        .map(|(_, full)| {
            let value = parts.next().unwrap_or_default();
            if !full {
                return value.to_vec();
            }
            let after = value
                .iter()
                .position(|byte| *byte == b':')
                .map_or(0, |colon| colon + 1);
            let rest = &value[after..];
            let blanks = rest
                .iter()
                .take_while(|byte| matches!(byte, b' ' | b'\t'))
                .count();
            rest[blanks..].to_vec()
        })
        .collect();
    OverviewRow::new(Arc::clone(keys), values)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn lines(items: &[&str]) -> Vec<Vec<u8>> {
        items.iter().map(|item| item.as_bytes().to_vec()).collect()
    }

    #[test]
    fn strips_the_field_marker_the_way_the_client_did() {
        let format = parse_format(&lines(&["Subject:", ":bytes", "Xref:full", "XREF:FULL"]));
        assert_eq!(
            format,
            vec![
                ("Subject".to_string(), false),
                (":byte".to_string(), false),
                ("Xref".to_string(), true),
                ("XREF".to_string(), true),
            ]
        );
    }

    #[test]
    fn xover_rows_stay_aligned_when_number_is_already_cached() {
        let format = numbered_format(parse_format(&lines(&[
            "Subject:",
            "From:",
            "Date:",
            "Message-ID:",
            "References:",
            ":bytes",
            ":lines",
            "Xref:full",
        ])));
        let keys: Arc<[String]> = xover_keys(&format).into();
        let row = xover_row(
            &keys,
            b"7\tsub\tfrom\tdate\t<id>\t\t100\t5\tXref: host a:1\textra",
        );
        assert_eq!(row.get("Number"), Some(&b"7"[..]));
        assert_eq!(row.get("Subject"), Some(&b"sub"[..]));
        assert_eq!(row.get("Xref"), Some(&b"host a:1"[..]));
        assert_eq!(row.fields().count(), 9);
    }

    #[test]
    fn a_short_xref_value_is_left_alone() {
        let keys: Arc<[String]> = vec!["Number".to_string(), "Xref".to_string()].into();
        assert_eq!(
            xover_row(&keys, b"1\tXref:").get("Xref"),
            Some(&b"Xref:"[..])
        );
        assert_eq!(xover_row(&keys, b"1\tXref: ").get("Xref"), Some(&b""[..]));
    }

    #[test]
    fn named_rows_fill_missing_fields_and_clean_full_values() {
        let format = vec![
            ("Number".to_string(), false),
            ("Xref".to_string(), true),
            ("Other".to_string(), false),
        ];
        let keys: Arc<[String]> = format
            .iter()
            .map(|(name, _)| name.clone())
            .collect::<Vec<_>>()
            .into();
        let row = named_row(&keys, &format, b"1\tXref: host a:1");
        assert_eq!(row.get("Xref"), Some(&b"host a:1"[..]));
        assert_eq!(row.get("Other"), Some(&b""[..]));
    }
}
