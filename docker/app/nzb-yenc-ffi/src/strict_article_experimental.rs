//! Opt-in PHP-strict article experiment; never changes the incumbent decoder.

#[repr(C)]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct StrictArticleResult {
    pub written: u64,
    pub crc32: u32,
    pub flags: u32,
}

const MAX_SOURCE: usize = 67_108_864;
const MAX_CONTROL_LINE: usize = 65_536;
const LINE: usize = 0;
const SIZE: usize = 1;
const NAME: usize = 2;
const PART: usize = 3;
const TOTAL: usize = 4;
const BEGIN: usize = 5;
const END: usize = 6;
const CRC: usize = 7;
const PCRC: usize = 8;
const KEYS: [&[u8]; 9] = [
    b"line", b"size", b"name", b"part", b"total", b"begin", b"end", b"crc32", b"pcrc32",
];

struct Fields<'a>([Option<&'a [u8]>; 9]);

// This is a deliberately narrower admission grammar than ArticleFrame::fields.
// In particular, unknown/duplicate fields and trailer name= must fall back.
fn fields(line: &[u8], allowed: u16, header: bool) -> Option<Fields<'_>> {
    let mut output = Fields([None; 9]);
    if !matches!(line.first(), Some(b' ' | b'\t')) {
        return None;
    }
    let mut offset = 0;
    while offset < line.len() {
        while matches!(line.get(offset), Some(b' ' | b'\t')) {
            offset += 1;
        }
        if offset == line.len() {
            break;
        }
        let equal = offset + line[offset..].iter().position(|b| *b == b'=')?;
        let key = KEYS
            .iter()
            .position(|key| line[offset..equal].eq_ignore_ascii_case(key))?;
        if allowed & (1 << key) == 0 || output.0[key].is_some() {
            return None;
        }
        let start = equal + 1;
        let end = if header && key == NAME {
            line.len()
        } else {
            start
                + line[start..]
                    .iter()
                    .position(|b| matches!(b, b' ' | b'\t'))
                    .unwrap_or(line.len() - start)
        };
        output.0[key] = Some(&line[start..end]);
        offset = end;
        if header && key == NAME {
            break;
        }
    }
    Some(output)
}

fn decimal(raw: &[u8], maximum: u64) -> Option<u64> {
    if raw.is_empty() {
        return None;
    }
    let mut value = 0u64;
    for byte in raw {
        if !byte.is_ascii_digit() {
            return None;
        }
        value = value.checked_mul(10)?.checked_add(u64::from(byte - b'0'))?;
        if value > maximum {
            return None;
        }
    }
    Some(value)
}

fn optional_number(fields: &Fields<'_>, key: usize, maximum: u64) -> Option<Option<u64>> {
    fields.0[key]
        .map(|raw| decimal(raw, maximum))
        .map_or(Some(None), |v| v.map(Some))
}

fn checksum(raw: &[u8]) -> Option<u32> {
    if raw.is_empty() || raw.len() > 8 {
        return None;
    }
    let mut value = 0;
    for byte in raw {
        let digit = match byte {
            b'0'..=b'9' => byte - b'0',
            b'a'..=b'f' => byte - b'a' + 10,
            b'A'..=b'F' => byte - b'A' + 10,
            _ => return None,
        };
        value = (value << 4) | u32::from(digit);
    }
    Some(value)
}

// The limit includes the physical newline. Multiple trailing CR bytes use PHP
// fallback, whose rtrim behavior is wider than this canonical admission.
fn control_line(source: &[u8], offset: usize) -> Option<(&[u8], usize, bool)> {
    let relative = source.get(offset..)?;
    let newline = relative.iter().position(|b| *b == b'\n');
    let (end, next) = newline.map_or((source.len(), source.len()), |n| {
        (offset + n, offset + n + 1)
    });
    if next - offset > MAX_CONTROL_LINE {
        return None;
    }
    let line = source[offset..end]
        .strip_suffix(b"\r")
        .unwrap_or(&source[offset..end]);
    if line.ends_with(b"\r") {
        return None;
    }
    Some((line, next, newline.is_some()))
}

fn control_at(source: &[u8], offset: usize, name: &[u8]) -> bool {
    source
        .get(offset..offset + name.len())
        .is_some_and(|raw| raw.eq_ignore_ascii_case(name))
        && source
            .get(offset + name.len())
            .is_none_or(|b| matches!(b, b' ' | b'\t' | b'\r' | b'\n'))
}

struct Multipart {
    part: u64,
    total: Option<u64>,
    begin: u64,
    end: u64,
    footer_part: Option<u64>,
    footer_total: Option<u64>,
}

struct Frame {
    payload_offset: usize,
    payload_length: usize,
    size: u64,
    footer_size: u64,
    multipart: Option<Multipart>,
    // Outer None means absent; Some(None) means present but invalid hex.
    crc: Option<Option<u32>>,
}

fn admit(source: &[u8], maximum: u64) -> Option<Frame> {
    if !control_at(source, 0, b"=ybegin") {
        return None;
    }
    let (line, mut offset, terminated) = control_line(source, 0)?;
    if !terminated {
        return None;
    }
    let header = fields(line.get(7..)?, 0b1_1111, true)?;
    header.0[LINE]?;
    header.0[NAME]?;
    let size = decimal(header.0[SIZE]?, maximum)?;
    let part = optional_number(&header, PART, maximum)?;
    let total = optional_number(&header, TOTAL, maximum)?;
    let range = if control_at(source, offset, b"=ypart") {
        let (line, next, terminated) = control_line(source, offset)?;
        if !terminated {
            return None;
        }
        let fields = fields(line.get(6..)?, (1 << BEGIN) | (1 << END), false)?;
        let begin = decimal(fields.0[BEGIN]?, maximum)?;
        let end = decimal(fields.0[END]?, maximum)?;
        offset = next;
        Some((begin, end))
    } else {
        None
    };
    if part.is_some() != range.is_some() {
        return None;
    }
    let payload_offset = offset;
    loop {
        if control_at(source, offset, b"=ybegin") {
            return None;
        }
        if control_at(source, offset, b"=yend") {
            break;
        }
        offset += source.get(offset..)?.iter().position(|b| *b == b'\n')? + 1;
    }
    let payload_length = offset - payload_offset;
    let (line, next, _) = control_line(source, offset)?;
    if next != source.len() {
        return None;
    }
    let footer = fields(
        line.get(5..)?,
        (1 << SIZE) | (1 << PART) | (1 << TOTAL) | (1 << CRC) | (1 << PCRC),
        false,
    )?;
    let footer_size = decimal(footer.0[SIZE]?, maximum)?;
    let footer_part = optional_number(&footer, PART, maximum)?;
    let footer_total = optional_number(&footer, TOTAL, maximum)?;
    let multipart = match (part, range) {
        (Some(part), Some((begin, end))) => {
            if footer_total.is_some() && total.is_none() {
                return None;
            }
            Some(Multipart {
                part,
                total,
                begin,
                end,
                footer_part,
                footer_total,
            })
        }
        (None, None) if footer_part.is_none() => None,
        _ => return None,
    };
    let crc = footer.0[if multipart.is_some() { PCRC } else { CRC }].map(checksum);
    Some(Frame {
        payload_offset,
        payload_length,
        size,
        footer_size,
        multipart,
        crc,
    })
}

fn range(address: usize, length: usize) -> Option<(usize, usize)> {
    Some((address, address.checked_add(length)?))
}

fn overlaps(a: (usize, usize), b: (usize, usize)) -> bool {
    a.0 < a.1 && b.0 < b.1 && a.0 < b.1 && b.0 < a.1
}

pub(super) unsafe fn decode(
    source: *const u8,
    source_length: usize,
    destination: *mut u8,
    destination_capacity: usize,
    php_integer_max: u64,
    result: *mut StrictArticleResult,
) -> u32 {
    if ![i32::MAX as u64, i64::MAX as u64].contains(&php_integer_max)
        || source_length > isize::MAX as usize
        || destination_capacity > isize::MAX as usize
        || destination_capacity < source_length
        || result.is_null()
        || (source_length != 0 && source.is_null())
        || (destination_capacity != 0 && destination.is_null())
    {
        return 2;
    }
    let Some(source_range) = range(source as usize, source_length) else {
        return 2;
    };
    let Some(destination_range) = range(destination as usize, destination_capacity) else {
        return 2;
    };
    let Some(result_range) = range(result as usize, std::mem::size_of::<StrictArticleResult>())
    else {
        return 2;
    };
    if overlaps(source_range, destination_range)
        || overlaps(source_range, result_range)
        || overlaps(destination_range, result_range)
    {
        return 2;
    }
    if source_length > MAX_SOURCE {
        return 1;
    }
    // SAFETY: argument validation rejects null nonempty input and overlong
    // slices; the ABI caller supplies readable storage. Empty null is allowed.
    let input = if source_length == 0 {
        &[]
    } else {
        unsafe { std::slice::from_raw_parts(source, source_length) }
    };
    let Some(frame) = admit(input, php_integer_max) else {
        return 1;
    };
    let payload = &input[frame.payload_offset..frame.payload_offset + frame.payload_length];
    let dangling = payload
        .iter()
        .rev()
        .take_while(|b| matches!(b, b'=' | b'\r' | b'\n'))
        .filter(|b| **b == b'=')
        .count()
        % 2
        == 1;
    let mut output = StrictArticleResult {
        written: 0,
        crc32: 0,
        flags: u32::from(frame.multipart.is_some()),
    };
    if dangling {
        // SAFETY: result is writable and disjoint; unaligned storage is valid.
        unsafe {
            result.write_unaligned(output);
        }
        return 3;
    }
    // No source slices are used after this point. Destination is disjoint and
    // has capacity for the complete article, hence also for its payload.
    let written = unsafe {
        super::payload_decoder::decode_into(
            source.add(frame.payload_offset),
            destination,
            frame.payload_length,
        )
    };
    output.written = written as u64;
    if frame.crc.is_some() {
        let decoded = if written == 0 {
            &[]
        } else {
            unsafe { std::slice::from_raw_parts(destination, written) }
        };
        output.crc32 = crc32fast::hash(decoded);
        output.flags |= 2;
    }
    let status = if let Some(part) = &frame.multipart {
        if part.part < 1
            || part.footer_part.is_some_and(|n| n != part.part)
            || part.begin < 1
            || part.end < part.begin
            || part.end > frame.size
            || part.total.is_some_and(|n| part.part > n)
            || part.footer_total.is_some_and(|n| Some(n) != part.total)
        {
            4
        } else if frame.crc.is_none() {
            5
        } else if part.end - part.begin + 1 != frame.footer_size
            || part.end - part.begin + 1 != output.written
        {
            6
        } else if frame.crc != Some(Some(output.crc32)) {
            7
        } else {
            0
        }
    } else if frame.size != frame.footer_size || frame.size != output.written {
        6
    } else if frame.crc.is_some() && frame.crc != Some(Some(output.crc32)) {
        7
    } else {
        0
    };
    // SAFETY: validated disjoint result storage receives one complete record.
    unsafe {
        result.write_unaligned(output);
    }
    status
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::nntmux_yenc_decode_strict_article_v1 as call;
    const SENTINEL: StrictArticleResult = StrictArticleResult {
        written: 0xa5a5_a5a5_a5a5_a5a5,
        crc32: 0xa5a5_a5a5,
        flags: 0xa5a5_a5a5,
    };
    const SINGLE: &[u8] =
        b"=ybegin line=128 size=3 name=fixture file.bin\r\nklm\r\n=yend size=3 crc32=a3830348";
    const MULTI: &[u8] = b"=ybegin part=2 total=3 line=128 size=9 name=x\r\n=ypart begin=4 end=6\r\nklm\r\n=yend size=3 pcrc32=a3830348";

    fn check(source: &[u8], status: u32, expected: &[u8], crc: u32, flags: u32) {
        let original = source.to_vec();
        let mut output = vec![0xa5; source.len() + 32];
        let mut result = SENTINEL;
        let actual = unsafe {
            call(
                source.as_ptr().cast(),
                source.len(),
                output.as_mut_ptr().add(16).cast(),
                source.len(),
                i64::MAX as u64,
                &mut result,
            )
        };
        assert_eq!(
            actual,
            status,
            "source: {:?}",
            String::from_utf8_lossy(source)
        );
        if status == 1 || status == 2 {
            assert_eq!(result, SENTINEL);
            assert!(output.iter().all(|b| *b == 0xa5));
        } else {
            assert_eq!(
                result,
                StrictArticleResult {
                    written: expected.len() as u64,
                    crc32: crc,
                    flags
                }
            );
            assert_eq!(&output[16..16 + expected.len()], expected);
            assert!(
                output[..16]
                    .iter()
                    .chain(output[16 + expected.len()..].iter())
                    .all(|b| *b == 0xa5)
            );
        }
        assert_eq!(source, original);
    }

    #[test]
    fn literal_single_empty_and_optional_checksum() {
        assert_eq!(std::mem::size_of::<StrictArticleResult>(), 16);
        check(SINGLE, 0, b"ABC", 0xa3830348, 2);
        check(
            b"=ybegin line= size=0 name=\n=yend size=0 crc32=0",
            0,
            b"",
            0,
            2,
        );
        check(
            b"=YBEGIN SIZE=0003 LiNe=not-numeric NAME=x size=999\nklm\n=YEND SIZE=003\n",
            0,
            b"ABC",
            0,
            0,
        );
        check(
            b"=ybegin line=0 size=3 total=0 name=x\nklm\n=yend size=3",
            0,
            b"ABC",
            0,
            0,
        );
    }

    #[test]
    fn literal_multipart_missing_optional_footer_part_and_ignored_whole_crc() {
        check(MULTI, 0, b"ABC", 0xa3830348, 3);
        check(b"=ybegin part=2 total=3 line=128 size=9 name=x\n=ypart begin=4 end=6\nklm\n=yend size=3 part=2 total=3 crc32=bad pcrc32=A3830348\r\n", 0, b"ABC", 0xa3830348, 3);
        check(b"=ybegin part=1 line=x size=3 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=1 pcrc32=a3830348", 0, b"ABC", 0xa3830348, 3);
    }

    #[test]
    fn literal_split_and_adjacent_escape_values() {
        check(
            b"=ybegin line=128 size=4 name=x\r\nk=\r\n@lm\r\n=yend size=4 crc32=a672946b",
            0,
            b"A\xd6BC",
            0xa672946b,
            2,
        );
        check(
            b"=ybegin line=128 size=3 name=x\n====kl\n=yend size=4",
            6,
            b"\xd3\xd3AB",
            0,
            0,
        );
        check(b"=ybegin part=1 total=1 line=128 size=4 name=x\n=ypart begin=1 end=4\nk=\r\n@lm\n=yend size=4 pcrc32=a672946b", 0, b"A\xd6BC", 0xa672946b, 3);
    }

    #[test]
    fn dangling_precedes_range_size_and_crc_without_output() {
        check(b"=ybegin part=0 total=0 line=1 size=1 name=x\n=ypart begin=0 end=2\nklm=\r\n=yend size=99 pcrc32=bad", 3, b"", 0, 1);
        check(
            b"=ybegin line=1 size=1 name=x\n=\n==\r\n=yend size=1 crc32=bad",
            3,
            b"",
            0,
            0,
        );
    }

    #[test]
    fn multipart_error_precedence_and_actual_prefix() {
        check(
            b"=ybegin part=0 line=128 size=3 name=x\n=ypart begin=1 end=3\nklm\n=yend size=99",
            4,
            b"ABC",
            0,
            1,
        );
        check(b"=ybegin part=1 total=1 line=128 size=3 name=x\n=ypart begin=1 end=3\nklm\n=yend size=99 crc32=bad", 5, b"ABC", 0, 1);
        check(b"=ybegin part=1 total=1 line=128 size=3 name=x\n=ypart begin=1 end=3\nklm\n=yend size=99 pcrc32=bad", 6, b"ABC", 0xa3830348, 3);
        check(b"=ybegin part=2 total=1 line=128 size=3 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 pcrc32=bad", 4, b"ABC", 0xa3830348, 3);
        check(b"=ybegin part=1 total=1 line=128 size=3 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=2 pcrc32=a3830348", 4, b"ABC", 0xa3830348, 3);
    }

    #[test]
    fn size_precedes_crc_and_crc_tokens_are_optional_but_exact() {
        check(
            b"=ybegin line=1 size=2 name=x\nklm\n=yend size=3 crc32=bad",
            6,
            b"ABC",
            0xa3830348,
            2,
        );
        for token in ["bad", "deadbeef", "", "a38303480", "0xa3830348"] {
            let source = format!("=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32={token}");
            check(source.as_bytes(), 7, b"ABC", 0xa3830348, 2);
        }
    }

    #[test]
    fn unsupported_grammar_does_not_write_any_output() {
        for source in [
            b"not yenc".as_slice(),
            b"=ybegin line=1 size=3 size=3 name=x\nklm\n=yend size=3",
            b"=ybegin line=1 size=3 unknown=1 name=x\nklm\n=yend size=3",
            b"=ybegin line=1 size=3 name=x\nklm\n=yend size=3 name=ignored crc32=deadbeef",
            b"=ybegin line=1 size=x name=x\n=\n=yend size=3",
            b"=ybegin line=1 size=3 name=x\nklm\n=yend size=3 crc32=a3830348\nsuffix",
            b"=ybegin part=1 line=1 size=3 name=x\nklm\n=yend size=3 pcrc32=a3830348",
            b"=ybegin line=1 size=3 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3",
            b"=ybegin part=1 line=1 size=3 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 total=1 pcrc32=a3830348",
        ] { check(source, 1, b"", 0, 0); }
    }

    #[test]
    fn earlier_and_interior_controls_select_fallback_before_writes() {
        for source in [
            b"=ybegin line=1 size=3 name=x\n=ybegin line=1 size=3 name=y\nklm\n=yend size=3".as_slice(),
            b"=ybegin line=1 size=3 name=x\n=yend size=3\nklm\n=yend size=3",
            b"=ybegin line=1 size=3 name=x\nklm\n=yend size=3\n=ybegin line=1 size=0 name=y\n=yend size=0",
            b"preamble\n=ybegin line=1 size=3 name=x\nklm\n=yend size=3",
        ] { check(source, 1, b"", 0, 0); }
        check(
            b"=ybegin line=1 size=5 name=x\n=yendx\n=yend size=5",
            0,
            b"\x0f;D:N",
            0,
            0,
        );
    }

    #[test]
    fn numeric_limits_and_leading_zeroes_are_php_bounded() {
        let source = b"=ybegin line=1 size=2147483648 name=x\nklm\n=yend size=3";
        let mut out = vec![0xa5; source.len()];
        let mut result = SENTINEL;
        assert_eq!(
            unsafe {
                call(
                    source.as_ptr().cast(),
                    source.len(),
                    out.as_mut_ptr().cast(),
                    out.len(),
                    i32::MAX as u64,
                    &mut result,
                )
            },
            1
        );
        assert_eq!(result, SENTINEL);
        assert!(out.iter().all(|b| *b == 0xa5));
        check(
            b"=ybegin line=1 size=9223372036854775808 name=x\nklm\n=yend size=3",
            1,
            b"",
            0,
            0,
        );
        check(
            b"=ybegin line=1 size=00000000000000000000000003 name=x\nklm\n=yend size=0003",
            0,
            b"ABC",
            0,
            0,
        );
        check(
            b"=ybegin line=1 size=9223372036854775807 name=x\nklm\n=yend size=3",
            6,
            b"ABC",
            0,
            0,
        );
    }

    #[test]
    fn control_budgets_and_source_budget_do_not_authorize_allocations() {
        let header = b"=ybegin line=1 size=3 name=";
        let mut source = header.to_vec();
        source.resize(MAX_CONTROL_LINE - 1, b'x');
        source.extend_from_slice(b"\nklm\n=yend size=3");
        check(&source, 0, b"ABC", 0, 0);
        source.insert(MAX_CONTROL_LINE - 1, b'x');
        check(&source, 1, b"", 0, 0);
        let mut trailer = b"=ybegin line=1 size=3 name=x\nklm\n=yend size=3".to_vec();
        trailer.extend(std::iter::repeat_n(b' ', MAX_CONTROL_LINE));
        check(&trailer, 1, b"", 0, 0);
        // No readable allocation is required for an input rejected by the
        // source-size gate: it must run before constructing any input slice.
        let mut result = SENTINEL;
        assert_eq!(
            unsafe {
                call(
                    0x1000usize as *const _,
                    MAX_SOURCE + 1,
                    0x1000_0000usize as *mut _,
                    MAX_SOURCE + 1,
                    i64::MAX as u64,
                    &mut result,
                )
            },
            1
        );
        assert_eq!(result, SENTINEL);
    }

    #[test]
    fn unaligned_result_and_all_unused_storage_are_preserved() {
        let mut result_bytes = [0xa5; 34];
        let result = unsafe {
            result_bytes
                .as_mut_ptr()
                .add(1)
                .cast::<StrictArticleResult>()
        };
        let mut output = vec![0xa5; SINGLE.len() + 32];
        assert_eq!(
            unsafe {
                call(
                    SINGLE.as_ptr().cast(),
                    SINGLE.len(),
                    output.as_mut_ptr().add(16).cast(),
                    SINGLE.len(),
                    i64::MAX as u64,
                    result,
                )
            },
            0
        );
        assert_eq!(
            unsafe { result.read_unaligned() },
            StrictArticleResult {
                written: 3,
                crc32: 0xa3830348,
                flags: 2
            }
        );
        assert_eq!(&output[16..19], b"ABC");
        assert!(
            output[..16]
                .iter()
                .chain(output[19..].iter())
                .all(|b| *b == 0xa5)
        );
        assert_eq!(result_bytes[0], 0xa5);
        assert!(result_bytes[17..].iter().all(|b| *b == 0xa5));
    }

    #[test]
    fn all_current_strict_fixture_shapes_use_supported_status() {
        for size in [4096, 524_288, 1_048_576, 8_388_608] {
            for heavy in [false, true] {
                let data: Vec<u8> = (0..size)
                    .map(|i| {
                        if heavy {
                            [0xd6, 0xe0, 0xe3, 0x13][i % 4]
                        } else {
                            i as u8
                        }
                    })
                    .collect();
                // Independent scalar writer mirrors the benchmark framing;
                // expected decoded bytes are generated independently above.
                let mut source =
                    format!("=ybegin line=128 size={size} name=fixture file.bin\r\n").into_bytes();
                let mut column = 0;
                for byte in &data {
                    let encoded = byte.wrapping_add(42);
                    if [0, 10, 13, 61].contains(&encoded) {
                        source.extend_from_slice(&[b'=', encoded.wrapping_add(64)]);
                        column += 2;
                    } else {
                        source.push(encoded);
                        column += 1;
                    }
                    if column >= 128 {
                        source.extend_from_slice(b"\r\n");
                        column = 0;
                    }
                }
                if column != 0 {
                    source.extend_from_slice(b"\r\n");
                }
                let crc = crc32fast::hash(&data);
                source.extend_from_slice(format!("=yend size={size} crc32={crc:08x}").as_bytes());
                check(&source, 0, &data, crc, 2);
            }
        }
    }

    #[test]
    fn invalid_pointer_capacity_alias_and_integer_contracts_are_untouched() {
        let mut storage = [0xa5u8; 256];
        let before = storage;
        let mut result = SENTINEL;
        for (src, len, dst, cap, max, res) in [
            (
                std::ptr::null(),
                1,
                storage.as_mut_ptr(),
                1,
                i64::MAX as u64,
                &mut result as *mut _,
            ),
            (
                SINGLE.as_ptr(),
                SINGLE.len(),
                std::ptr::null_mut(),
                SINGLE.len(),
                i64::MAX as u64,
                &mut result,
            ),
            (
                SINGLE.as_ptr(),
                SINGLE.len(),
                storage.as_mut_ptr(),
                SINGLE.len() - 1,
                i64::MAX as u64,
                &mut result,
            ),
            (
                SINGLE.as_ptr(),
                SINGLE.len(),
                storage.as_mut_ptr(),
                SINGLE.len(),
                0,
                &mut result,
            ),
            (
                SINGLE.as_ptr(),
                SINGLE.len(),
                storage.as_mut_ptr(),
                SINGLE.len(),
                i64::MAX as u64,
                std::ptr::null_mut(),
            ),
            (
                storage.as_ptr(),
                SINGLE.len(),
                storage.as_mut_ptr(),
                SINGLE.len(),
                i64::MAX as u64,
                &mut result,
            ),
            (
                SINGLE.as_ptr(),
                SINGLE.len(),
                storage.as_mut_ptr(),
                SINGLE.len(),
                i64::MAX as u64,
                storage.as_mut_ptr().cast(),
            ),
            (
                usize::MAX as *const u8,
                1,
                storage.as_mut_ptr(),
                1,
                i64::MAX as u64,
                &mut result,
            ),
            (
                SINGLE.as_ptr(),
                SINGLE.len(),
                usize::MAX as *mut u8,
                SINGLE.len(),
                i64::MAX as u64,
                &mut result,
            ),
            (
                SINGLE.as_ptr(),
                SINGLE.len(),
                storage.as_mut_ptr(),
                SINGLE.len(),
                i64::MAX as u64,
                usize::MAX as *mut _,
            ),
            (
                SINGLE.as_ptr(),
                SINGLE.len(),
                storage.as_mut_ptr(),
                usize::MAX,
                i64::MAX as u64,
                &mut result,
            ),
            (
                SINGLE.as_ptr(),
                SINGLE.len(),
                storage.as_mut_ptr(),
                SINGLE.len(),
                i64::MAX as u64,
                SINGLE.as_ptr().cast_mut().cast(),
            ),
            (
                storage.as_ptr(),
                SINGLE.len(),
                unsafe { storage.as_mut_ptr().add(1) },
                SINGLE.len(),
                i64::MAX as u64,
                &mut result,
            ),
            (
                unsafe { storage.as_ptr().add(1) },
                SINGLE.len(),
                storage.as_mut_ptr(),
                SINGLE.len(),
                i64::MAX as u64,
                &mut result,
            ),
        ] {
            assert_eq!(
                unsafe { call(src.cast(), len, dst.cast(), cap, max, res) },
                2
            );
            assert_eq!(storage, before);
            assert_eq!(result, SENTINEL);
        }
        assert_eq!(
            unsafe {
                call(
                    std::ptr::null(),
                    0,
                    std::ptr::null_mut(),
                    0,
                    i64::MAX as u64,
                    &mut result,
                )
            },
            1
        );
        assert_eq!(result, SENTINEL);
    }
}
