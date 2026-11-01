//! Renders a verified manifest as the NZB document stored for its release.

use std::fmt::Write;

use anyhow::{Result, bail};
use chrono::NaiveDateTime;

use crate::manifest::{Manifest, valid_passwords};

const NZB_NAMESPACE: &str = "http://www.newzbin.com/DTD/2003/nzb";

/// libxml's escaping of an attribute value, as `XMLWriter::writeAttribute` applies it.
fn attribute(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for c in value.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\t' => out.push_str("&#9;"),
            '\n' => out.push_str("&#10;"),
            '\r' => out.push_str("&#13;"),
            c => out.push(c),
        }
    }
    out
}

/// libxml's escaping of element text, as `XMLWriter::text` applies it.
fn text(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for c in value.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '\r' => out.push_str("&#13;"),
            c => out.push(c),
        }
    }
    out
}

/// The Unix time a stored post date names; the app runs in UTC. An unparsable date writes an empty
/// attribute, as `(string) strtotime()` did.
fn post_time(date: Option<&str>) -> String {
    date.and_then(|value| NaiveDateTime::parse_from_str(value, "%Y-%m-%d %H:%M:%S").ok())
        .map(|parsed| parsed.and_utc().timestamp().to_string())
        .unwrap_or_default()
}

/// The inventory count written to the head: a mode and how many files a publisher promised.
fn head_inventory(manifest: &Manifest, verified_file_count: i64) -> i64 {
    let emitted = manifest
        .files
        .iter()
        .filter(|file| !file.segments.is_empty())
        .count() as i64;
    manifest
        .expected_files
        .max(verified_file_count)
        .max(emitted)
}

/// `verified_file_count` is the number of distinct names the spot check read (0 when none).
pub fn document(manifest: &Manifest, verified_file_count: i64) -> Result<String> {
    let passwords = manifest.passwords();
    if !valid_passwords(passwords) {
        bail!("Invalid verified NZB password metadata.");
    }
    let spot_check = manifest.verification_mode.as_deref() == Some("spot_check");
    let inventory = head_inventory(manifest, verified_file_count);
    let mut xml = String::with_capacity(4096);
    xml.push_str("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    write!(xml, "<nzb xmlns=\"{NZB_NAMESPACE}\">")?;
    if inventory > 0 || spot_check || !passwords.is_empty() {
        xml.push_str("<head>");
        if spot_check {
            if inventory > 0 {
                write!(
                    xml,
                    "<meta type=\"nntmux:declared-file-count\">{inventory}</meta>"
                )?;
            }
            xml.push_str("<meta type=\"nntmux:verification-mode\">spot-check</meta>");
        } else if inventory > 0 {
            write!(
                xml,
                "<meta type=\"nntmux:verified-file-count\">{inventory}</meta>"
            )?;
        }
        for password in passwords {
            write!(xml, "<meta type=\"password\">{}</meta>", text(password))?;
        }
        xml.push_str("</head>");
    }
    let poster = attribute(&manifest.fromname);
    let date = post_time(manifest.date.as_deref());
    let mut groups = String::new();
    for group in &manifest.groups {
        write!(groups, "<group>{}</group>", text(group))?;
    }
    for file in &manifest.files {
        if file.segments.is_empty() {
            continue;
        }
        let subject = attribute(&format!("{} (1/{})", file.subject, file.totalparts));
        write!(
            xml,
            "<file poster=\"{poster}\" date=\"{date}\" subject=\"{subject}\"><groups>{groups}</groups><segments>"
        )?;
        for segment in &file.segments {
            write!(
                xml,
                "<segment number=\"{}\" bytes=\"{}\">{}</segment>",
                segment.number,
                segment.bytes,
                text(segment.messageid.trim_matches(['<', '>']))
            )?;
        }
        xml.push_str("</segments></file>");
    }
    xml.push_str("</nzb>\n");
    Ok(xml)
}
