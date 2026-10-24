//! Byte-exact IRC string helpers. State, timestamps and database writes remain in PHP.
use crate::OutCursor;
use std::{ffi::c_char, slice};

fn ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 11 | 12)
}

// Use the platform's optimized byte search without adding a dependency.
unsafe extern "C" {
    fn memchr(s: *const u8, c: std::ffi::c_int, n: usize) -> *const u8;
}

#[inline]
fn find_byte(s: &[u8], byte: u8) -> Option<usize> {
    // Inline short-field searches: avoid an external call for every bot label.
    // The first high bit identifies the first zero byte even when subtraction
    // borrows into later bytes. All supported production targets are little endian.
    #[cfg(target_endian = "little")]
    {
        let mut i = 0;
        while i + 8 <= s.len().min(32) {
            let word = unsafe { std::ptr::read_unaligned(s.as_ptr().add(i).cast::<u64>()) }
                ^ (u64::from(byte) * 0x0101010101010101);
            let hits = word.wrapping_sub(0x0101010101010101) & !word & 0x8080808080808080;
            if hits != 0 {
                return Some(i + hits.trailing_zeros() as usize / 8);
            }
            i += 8;
        }
        if s.len() <= 32 {
            return s[i..].iter().position(|&b| b == byte).map(|p| i + p);
        }
    }
    let found = unsafe { memchr(s.as_ptr(), byte.into(), s.len()) };
    (!found.is_null()).then(|| unsafe { found.offset_from(s.as_ptr()) as usize })
}

fn control(b: u8) -> bool {
    matches!(b, 2 | 3 | 4 | 15 | 17 | 22 | 29 | 30 | 31)
}

// Jump over ordinary text in SIMD-sized blocks. No reads beyond the slice, including
// at an unaligned FFI address or a page boundary. Both supported host architectures
// provide these instructions as part of their baseline ISA.
fn next_control(s: &[u8]) -> usize {
    let mut i = 0;
    while i + 16 <= s.len() {
        #[cfg(target_arch = "aarch64")]
        let ordinary = unsafe {
            use std::arch::aarch64::*;
            vminvq_u8(vld1q_u8(s.as_ptr().add(i))) >= 32
        };
        #[cfg(target_arch = "x86_64")]
        let ordinary = unsafe {
            use std::arch::x86_64::*;
            let v = _mm_loadu_si128(s.as_ptr().add(i).cast());
            _mm_movemask_epi8(_mm_cmpeq_epi8(
                _mm_and_si128(v, _mm_set1_epi8(-32)),
                _mm_setzero_si128(),
            )) == 0
        };
        #[cfg(not(any(target_arch = "aarch64", target_arch = "x86_64")))]
        let ordinary = s[i..i + 16].iter().all(|&b| b >= 32);
        if !ordinary {
            if let Some(p) = s[i..i + 16].iter().position(|&b| control(b)) {
                return i + p;
            }
        }
        i += 16;
    }
    i + s[i..]
        .iter()
        .position(|&b| control(b))
        .unwrap_or(s.len() - i)
}

// Pass 1 writes directly to caller storage; pass 2 compacts that same storage.
// Safety: output has input.len() writable bytes and does not overlap input.
unsafe fn strip(input: &[u8], output: *mut u8) -> usize {
    let (mut read, mut written, mut has_pass2) = (0, 0, false);
    while read < input.len() {
        let end = read + next_control(&input[read..]);
        unsafe {
            std::ptr::copy_nonoverlapping(input.as_ptr().add(read), output.add(written), end - read)
        };
        written += end - read;
        read = end;
        if read == input.len() {
            break;
        }
        let b = input[read];
        read += 1;
        if b == 3 {
            let start = read;
            while read < input.len() && read - start < 2 && input[read].is_ascii_digit() {
                read += 1;
            }
            if read > start
                && input.get(read) == Some(&b',')
                && input.get(read + 1).is_some_and(u8::is_ascii_digit)
            {
                read += 2;
                if input.get(read).is_some_and(u8::is_ascii_digit) {
                    read += 1;
                }
            }
        } else {
            unsafe { *output.add(written) = b };
            written += 1;
            has_pass2 = true;
        }
    }
    if !has_pass2 {
        return written;
    }
    let pass1_len = written;
    read = 0;
    written = 0;
    while read < pass1_len {
        let bytes = unsafe { slice::from_raw_parts(output.add(read), pass1_len - read) };
        let end = read + next_control(bytes);
        unsafe { std::ptr::copy(output.add(read), output.add(written), end - read) };
        written += end - read;
        read = end;
        if read == pass1_len {
            break;
        }
        let b = unsafe { *output.add(read) };
        read += 1;
        if b == 4 {
            let bytes = unsafe { slice::from_raw_parts(output.add(read), pass1_len - read) };
            if bytes
                .get(..6)
                .is_some_and(|s| s.iter().all(u8::is_ascii_hexdigit))
            {
                read += 6;
                if bytes.get(6) == Some(&b',')
                    && bytes
                        .get(7..13)
                        .is_some_and(|s| s.iter().all(u8::is_ascii_hexdigit))
                {
                    read += 7;
                }
            }
        }
    }
    written
}

/// # Safety
/// Input must be readable for `input_len`, output writable for `out_cap`, without overlap.
/// Returns bytes written, -1 for invalid arguments, or negative input capacity needed.
/// Requiring input-sized scratch lets both passes run without any heap allocation.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_irc_strip_control_chars(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len > isize::MAX as usize
        || (input.is_null() && input_len != 0)
        || (out_buf.is_null() && out_cap != 0)
    {
        return -1;
    }
    if input_len == 0 {
        return 0;
    }
    if out_cap < input_len {
        return -(input_len as isize);
    }
    unsafe { strip(slice::from_raw_parts(input, input_len), out_buf) as isize }
}

fn ping(s: &[u8]) -> Option<&[u8]> {
    let mut i = 4;
    if !s.starts_with(b"PING") {
        return None;
    }
    while s.get(i).is_some_and(|&b| ws(b)) {
        i += 1;
    }
    if s.get(i) != Some(&b':') {
        return None;
    }
    let payload = &s[i + 1..];
    let payload = payload.strip_suffix(b"\n").unwrap_or(payload);
    (!payload.is_empty() && find_byte(payload, b'\n').is_none()).then_some(payload)
}

type Span = std::ops::Range<usize>;

// The regex's lazy captures try delimiters from left to right. Whitespace runs
// are consumed greedily; only the message's trailing whitespace is excluded.
fn privmsg(s: &[u8]) -> Option<[Span; 3]> {
    if s.first() != Some(&b':') {
        return None;
    }
    let mut bang = 2;
    while bang < s.len() {
        bang += find_byte(&s[bang..], b'!')?;
        if find_byte(&s[1..bang], b'\n').is_some() {
            return None;
        }
        let mut host = bang + 2; // .+? requires at least one host byte.
        if s.get(bang + 1) == Some(&b'\n') {
            bang += 1;
            continue;
        }
        while host < s.len() {
            if ws(s[host]) {
                let mut command = host + 1;
                while s.get(command).is_some_and(|&b| ws(b)) {
                    command += 1;
                }
                if s.get(command..command + 7) == Some(b"PRIVMSG")
                    && s.get(command + 7).is_some_and(|&b| ws(b))
                {
                    let mut channel = command + 8;
                    while s.get(channel).is_some_and(|&b| ws(b)) {
                        channel += 1;
                    }
                    if s.get(channel) == Some(&b'#')
                        && s.get(channel + 1).is_some_and(|&b| b != b'\n')
                    {
                        let mut end = channel + 2;
                        while end < s.len() {
                            if ws(s[end]) {
                                let mut colon = end + 1;
                                while s.get(colon).is_some_and(|&b| ws(b)) {
                                    colon += 1;
                                }
                                if s.get(colon) == Some(&b':') {
                                    let mut message = colon + 1;
                                    while s.get(message).is_some_and(|&b| ws(b)) {
                                        message += 1;
                                    }
                                    if message == s.len() {
                                        while message > colon + 1 {
                                            message -= 1;
                                            if s[message] != b'\n' {
                                                break;
                                            }
                                        }
                                    }
                                    if message < s.len() && s[message] != b'\n' {
                                        let mut finish = s.len();
                                        while finish > message + 1 && ws(s[finish - 1]) {
                                            finish -= 1;
                                        }
                                        if find_byte(&s[message..finish], b'\n').is_none() {
                                            return Some([1..bang, channel..end, message..finish]);
                                        }
                                    }
                                }
                            }
                            if s[end] == b'\n' {
                                break;
                            }
                            end += 1;
                        }
                    }
                }
            }
            if s[host] == b'\n' {
                break;
            }
            host += 1;
        }
        bang += 1;
    }
    None
}

/// Classifies raw PING before stripping, otherwise strips and parses PRIVMSG.
/// Wire records: P\0payload or M\0nickname\0channel\0message; 0 means ignored.
/// NUL input returns -1 so PHP can preserve binary captures using its fallback.
/// # Safety
/// Input is readable, output writable, with no overlap. Scratch needs input_len + 4.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_irc_parse_inbound_line(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len > isize::MAX as usize - 4
        || (input.is_null() && input_len != 0)
        || (out_buf.is_null() && out_cap != 0)
    {
        return -1;
    }
    let s = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };
    if find_byte(s, 0).is_some() {
        return -1;
    }
    if let Some(payload) = ping(s) {
        let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
        out.extend(b"P\0");
        out.extend(payload);
        return out.finish();
    }
    if out_cap < input_len + 4 {
        return -((input_len + 4) as isize);
    }
    let len = unsafe { strip(s, out_buf.add(2)) };
    let spans = privmsg(unsafe { slice::from_raw_parts(out_buf.add(2), len) });
    let Some(spans) = spans else {
        return 0;
    };
    unsafe {
        *out_buf = b'M';
        *out_buf.add(1) = 0;
    }
    let mut written = 2;
    for (i, span) in spans.into_iter().enumerate() {
        unsafe {
            std::ptr::copy(
                out_buf.add(2 + span.start),
                out_buf.add(written),
                span.len(),
            )
        };
        written += span.len();
        if i < 2 {
            unsafe {
                *out_buf.add(written) = 0;
            }
            written += 1;
        }
    }
    written as isize
}

const LABELS: [&[u8]; 7] = [
    b"[DT: ", b"[TT: ", b"[SC: ", b"[CT: ", b"[RQ: ", b"[SZ: ", b"[FL: ",
];

fn prefix(s: &[u8], label: &[u8]) -> bool {
    s.starts_with(label)
        || s.get(..label.len())
            .is_some_and(|p| p.eq_ignore_ascii_case(label))
}

fn at_end(s: &[u8]) -> bool {
    s.is_empty() || s == b"\n"
}

fn optional_ws(s: &[u8]) -> impl Iterator<Item = &[u8]> {
    // PCRE's greedy \s? tries consuming one whitespace byte first, then backtracks.
    let skip = usize::from(s.first().is_some_and(|&b| ws(b)));
    [Some(&s[skip..]), (skip != 0).then_some(s)]
        .into_iter()
        .flatten()
}

fn spend(budget: &mut usize) -> Result<(), ()> {
    if *budget == 0 {
        return Err(());
    }
    *budget -= 1;
    Ok(())
}

fn closing_brackets<'a>(
    s: &'a [u8],
    budget: &mut usize,
) -> Result<impl Iterator<Item = usize> + 'a, ()> {
    let end = find_byte(s, b'\n').map_or(s.len(), |p| p + 1);
    if end > *budget {
        return Err(());
    }
    *budget -= end;
    let mut offset = 1.min(end);
    Ok(std::iter::from_fn(move || {
        let pos = offset + find_byte(&s[offset..end], b']')?;
        offset = pos + 1;
        Some(pos)
    }))
}

// First-closing-bracket path: no recursive calls on normal bot traffic. Ambiguous
// values (including embedded labels) retry the ordered legacy matcher.
fn fast_bot_fields<'a>(mut s: &'a [u8], fields: &mut [&'a [u8]; 11]) -> Option<bool> {
    if find_byte(s, b'\n').is_some_and(|p| p + 1 != s.len()) {
        return None;
    }
    for (i, label) in LABELS.iter().enumerate() {
        if !prefix(s, label) {
            return None;
        }
        let value = &s[label.len()..];
        let Some(end) = find_byte(value, b']') else {
            return if value.len() > 1_000_000 {
                None
            } else {
                Some(false)
            };
        };
        if end == 0 {
            return None;
        }
        fields[i + 1] = &value[..end];
        s = &value[end + 1..];
        if s.first().is_some_and(|&b| ws(b)) {
            s = &s[1..];
        }
    }
    if prefix(s, b"[FN: ") {
        let value = &s[5..];
        let Some(end) = find_byte(value, b']') else {
            return if value.len() > 1_000_000 {
                None
            } else {
                Some(false)
            };
        };
        if end == 0 {
            return None;
        }
        fields[8] = &value[..end];
        s = &value[end + 1..];
        if s.first().is_some_and(|&b| ws(b)) {
            s = &s[1..];
        }
    }
    nuke(s, fields, &mut 1_000_000)
        .ok()
        .filter(|&matched| matched)
}

fn req_fields(req: &[u8]) -> (&[u8], &[u8]) {
    let Some(colon) = find_byte(req, b':') else {
        return (&[], &[]);
    };
    let group = &req[colon + 1..];
    let group = group.strip_suffix(b"\n").unwrap_or(group);
    if colon > 0
        && req[..colon].iter().all(u8::is_ascii_digit)
        && !group.is_empty()
        && find_byte(group, b'\n').is_none()
    {
        (&req[..colon], group)
    } else {
        (&[], &[])
    }
}

// Preserve the original case-sensitive switch, including its accepted-but-unmapped
// variants (NUKE, OLDNUKED, lowercase, etc.). Values mirror Predb::PRE_*.
fn nuke_status(nuked: &[u8]) -> &[u8] {
    match nuked {
        b"NUKED" => b"2",
        b"UNNUKED" => b"1",
        b"MODNUKED" => b"3",
        b"RENUKED" => b"4",
        b"OLDNUKE" => b"5",
        _ => b"",
    }
}

fn nuke<'a>(s: &'a [u8], fields: &mut [&'a [u8]; 11], budget: &mut usize) -> Result<bool, ()> {
    if at_end(s) {
        return Ok(true);
    }
    if s.first() != Some(&b'[') {
        return Ok(false);
    }
    for keyword in [
        b"NUKE".as_slice(),
        b"NUKED",
        b"UNNUKE",
        b"UNNUKED",
        b"MODNUKE",
        b"MODNUKED",
        b"RENUKE",
        b"RENUKED",
        b"OLDNUKE",
        b"OLDNUKED",
    ] {
        if prefix(&s[1..], keyword) && s.get(1 + keyword.len()..3 + keyword.len()) == Some(b": ") {
            let reason = &s[3 + keyword.len()..];
            for end in closing_brackets(reason, budget)? {
                if at_end(&reason[end + 1..]) {
                    fields[9] = &s[1..1 + keyword.len()];
                    fields[10] = &reason[..end];
                    return Ok(true);
                }
            }
        }
    }
    Ok(false)
}

fn tail<'a>(s: &'a [u8], fields: &mut [&'a [u8]; 11], budget: &mut usize) -> Result<bool, ()> {
    if prefix(s, b"[FN: ") {
        let value = &s[5..];
        for end in closing_brackets(value, budget)? {
            for rest in optional_ws(&value[end + 1..]) {
                if nuke(rest, fields, budget)? {
                    fields[8] = &value[..end];
                    return Ok(true);
                }
            }
        }
    }
    nuke(s, fields, budget)
}

fn bot_fields<'a>(
    s: &'a [u8],
    index: usize,
    fields: &mut [&'a [u8]; 11],
    budget: &mut usize,
) -> Result<bool, ()> {
    spend(budget)?;
    if !prefix(s, LABELS[index]) {
        return Ok(false);
    }
    let value = &s[LABELS[index].len()..];
    for end in closing_brackets(value, budget)? {
        for rest in optional_ws(&value[end + 1..]) {
            let matched = if index == 6 {
                tail(rest, fields, budget)?
            } else {
                bot_fields(rest, index + 1, fields, budget)?
            };
            if matched {
                fields[index + 1] = &value[..end];
                return Ok(true);
            }
        }
    }
    Ok(false)
}

// Small fields dominate bot announcements. Inline their bounded copies instead
// of dispatching through libc memcpy for each individual capture.
unsafe fn copy_field(s: &[u8], out: *mut u8) {
    unsafe {
        match s.len() {
            0 => {}
            1..=3 => {
                for (i, &b) in s.iter().enumerate() {
                    *out.add(i) = b;
                }
            }
            4..=7 => {
                std::ptr::write_unaligned(
                    out.cast::<u32>(),
                    std::ptr::read_unaligned(s.as_ptr().cast::<u32>()),
                );
                std::ptr::write_unaligned(
                    out.add(s.len() - 4).cast::<u32>(),
                    std::ptr::read_unaligned(s.as_ptr().add(s.len() - 4).cast::<u32>()),
                );
            }
            8..=15 => {
                std::ptr::write_unaligned(
                    out.cast::<u64>(),
                    std::ptr::read_unaligned(s.as_ptr().cast::<u64>()),
                );
                std::ptr::write_unaligned(
                    out.add(s.len() - 8).cast::<u64>(),
                    std::ptr::read_unaligned(s.as_ptr().add(s.len() - 8).cast::<u64>()),
                );
            }
            16..=32 => {
                std::ptr::write_unaligned(
                    out.cast::<u128>(),
                    std::ptr::read_unaligned(s.as_ptr().cast::<u128>()),
                );
                std::ptr::write_unaligned(
                    out.add(s.len() - 16).cast::<u128>(),
                    std::ptr::read_unaligned(s.as_ptr().add(s.len() - 16).cast::<u128>()),
                );
            }
            _ => std::ptr::copy_nonoverlapping(s.as_ptr(), out, s.len()),
        }
    }
}

/// Bot captures: `time\0title\0source\0category\0req\0size\0files\0req_id\0req_group`
/// followed by filename when present, then nuked/reason/nuke_status when present..
/// Empty optional fields denote absent captures. NUL inputs and excessive backtracking return -1
/// for the PHP fallback. Returns 0 for no match, or negative required output capacity.
/// # Safety
/// Input must be readable for `input_len`, output writable for `out_cap`, without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_irc_parse_bot_message(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    if input_len > isize::MAX as usize || (input.is_null() && input_len != 0) {
        return -1;
    }
    let s = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };
    if s.contains(&0) {
        return -1;
    }
    if s.len() < 5
        || ![b"NEW".as_slice(), b"UPD", b"NUK"]
            .iter()
            .any(|k| prefix(s, k))
        || s.get(3..5) != Some(b": ")
    {
        return 0;
    }
    let mut fields: [&[u8]; 11] = [&[]; 11];
    fields[0] = &s[..3];
    let matched = match fast_bot_fields(&s[5..], &mut fields) {
        Some(matched) => Ok(matched),
        None => {
            fields = [&[]; 11];
            bot_fields(&s[5..], 0, &mut fields, &mut 1_000_000)
        }
    };
    match matched {
        Err(()) => -1,
        Ok(false) => 0,
        Ok(true) => {
            let (req_id, req_group) = req_fields(fields[5]);
            let values = [
                fields[1],
                fields[2],
                fields[3],
                fields[4],
                fields[5],
                fields[6],
                fields[7],
                req_id,
                req_group,
                fields[8],
                fields[9],
                fields[10],
                nuke_status(fields[9]),
            ];
            let count = if !fields[9].is_empty() {
                13
            } else if !fields[8].is_empty() {
                10
            } else {
                9
            };
            let required = count - 1 + values[..count].iter().map(|s| s.len()).sum::<usize>();
            if out_buf.is_null() || out_cap < required {
                return -(required as isize);
            }
            let mut written = 0;
            for (i, field) in values[..count].iter().enumerate() {
                if i != 0 {
                    unsafe {
                        *out_buf.add(written) = 0;
                    }
                    written += 1;
                }
                unsafe {
                    copy_field(field, out_buf.add(written));
                }
                written += field.len();
            }
            written as isize
        }
    }
}

// WebSocket status codes: -1 incomplete, -2 reserved length bit, -3 over limit,
// -4 insufficient output capacity, -5 invalid arguments. Encode returns -1 on
// invalid arguments, otherwise the frame length (negative if capacity is short).
const WS_MAX_FRAME_BYTES: u64 = 4 * 1024 * 1024;

/// XOR directly into caller memory, handling arbitrary alignment and binary data.
unsafe fn ws_mask(payload: &[u8], mask: [u8; 4], out: *mut u8) {
    let word = u32::from_ne_bytes(mask) as u64;
    let repeated = word | (word << 32);
    let mut pos = 0;
    while payload.len() - pos >= 8 {
        unsafe {
            let value = std::ptr::read_unaligned(payload.as_ptr().add(pos).cast::<u64>());
            std::ptr::write_unaligned(out.add(pos).cast::<u64>(), value ^ repeated);
        }
        pos += 8;
    }
    while pos < payload.len() {
        unsafe { *out.add(pos) = payload[pos] ^ mask[pos & 3] };
        pos += 1;
    }
}

/// Encode a FIN client frame, including its four-byte mask. No heap allocations.
/// # Safety
/// Readable inputs and writable output must not overlap and have the stated sizes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_ws_encode_frame(
    opcode: u8,
    payload: *const c_char,
    payload_len: usize,
    mask: *const c_char,
    mask_len: usize,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (payload.is_null() && payload_len != 0)
        || mask.is_null()
        || mask_len != 4
        || (out_buf.is_null() && out_cap != 0)
        || payload_len > isize::MAX as usize - 14
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    let header = if payload_len < 126 {
        2
    } else if payload_len < 65536 {
        4
    } else {
        10
    };
    let needed = header + 4 + payload_len;
    if out_cap < needed || out_buf.is_null() {
        return -(needed as isize);
    }
    let data = if payload_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(payload.cast::<u8>(), payload_len) }
    };
    let mask = unsafe { std::ptr::read_unaligned(mask.cast::<[u8; 4]>()) };
    let out = out_buf.cast::<u8>();
    unsafe {
        *out = 0x80 | opcode;
        *out.add(1) = 0x80
            | if header == 2 {
                payload_len as u8
            } else if header == 4 {
                126
            } else {
                127
            };
        if header == 4 {
            std::ptr::copy_nonoverlapping(
                (payload_len as u16).to_be_bytes().as_ptr(),
                out.add(2),
                2,
            );
        } else if header == 10 {
            std::ptr::copy_nonoverlapping(
                (payload_len as u64).to_be_bytes().as_ptr(),
                out.add(2),
                8,
            );
        }
        std::ptr::copy_nonoverlapping(mask.as_ptr(), out.add(header), 4);
        ws_mask(data, mask, out.add(header + 4));
    }
    needed as isize
}

/// Decode the first frame, copying only its payload. Success returns payload length
/// (including zero). Metadata is five u32s: FIN, opcode, consumed, length low/high.
/// Oversize errors also populate length low/high; errors never copy a payload.
/// # Safety
/// Input/output must not overlap, meta_out must hold five aligned u32s, and all
/// buffers must be accessible for their stated sizes. Null output with zero cap is valid.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_ws_decode_frame(
    bytes: *const c_char,
    bytes_len: usize,
    meta_out: *mut u32,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (bytes.is_null() && bytes_len != 0)
        || meta_out.is_null()
        || (out_buf.is_null() && out_cap != 0)
        || bytes_len > isize::MAX as usize
    {
        return -5;
    }
    if bytes_len < 2 {
        return -1;
    }
    let data = unsafe { slice::from_raw_parts(bytes.cast::<u8>(), bytes_len) };
    let mut offset = 2;
    let length = match data[1] & 0x7f {
        126 => {
            if data.len() < 4 {
                return -1;
            }
            offset = 4;
            u16::from_be_bytes([data[2], data[3]]) as u64
        }
        127 => {
            if data.len() < 10 {
                return -1;
            }
            if data[2] & 0x80 != 0 {
                return -2;
            }
            offset = 10;
            u64::from_be_bytes(data[2..10].try_into().unwrap())
        }
        n => n as u64,
    };
    unsafe {
        *meta_out.add(3) = length as u32;
        *meta_out.add(4) = (length >> 32) as u32;
    }
    if length > WS_MAX_FRAME_BYTES {
        return -3;
    }
    let length = length as usize;
    let masked = data[1] & 0x80 != 0;
    let mask_offset = offset;
    if masked {
        offset += 4;
    }
    if data.len() < offset || data.len() - offset < length {
        return -1;
    }
    unsafe {
        *meta_out = u32::from(data[0] & 0x80 != 0);
        *meta_out.add(1) = (data[0] & 0x0f) as u32;
        *meta_out.add(2) = (offset + length) as u32;
    }
    if out_cap < length || (out_buf.is_null() && length != 0) {
        return -4;
    }
    if length != 0 {
        let payload = &data[offset..offset + length];
        unsafe {
            if masked {
                ws_mask(
                    payload,
                    data[mask_offset..mask_offset + 4].try_into().unwrap(),
                    out_buf.cast::<u8>(),
                );
            } else {
                std::ptr::copy_nonoverlapping(payload.as_ptr(), out_buf.cast::<u8>(), length);
            }
        }
    }
    length as isize
}

/// Scene announce fields: type\0title\0section\0reason\0files\0size.
/// 0 means no match, -1 requests PHP fallback; other negative values are required
/// capacity. Formats are 0 corruptnet, 1 zenet, 2 predatabase, 3 ngp, 4 rizon, 5 relay.
/// # Safety
/// Input readable and output writable for stated sizes, without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_irc_pre_announce_parse(
    line: *const c_char,
    line_len: usize,
    format: i32,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (line.is_null() && line_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || line_len > isize::MAX as usize / 2
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    unsafe {
        crate::predb_feeds::nntmux_predb_parse_irc_announce(
            line.cast(),
            line_len,
            format,
            out_buf.cast(),
            out_cap,
        )
    }
}

/// NUL-separated input with a required trailing NUL (including an empty last line).
/// One newline-terminated record per line; empty means no match, '?' means PHP fallback.
/// CR/LF inputs cannot match, so record delimiters cannot appear in accepted fields.
/// # Safety
/// Input readable and output writable for stated sizes, without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_irc_pre_announce_parse_batch_nul(
    lines: *const c_char,
    lines_len: usize,
    format: i32,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (lines.is_null() && lines_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || lines_len > isize::MAX as usize / 2
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    if lines_len != 0 && unsafe { *lines.add(lines_len - 1) } != 0 {
        return -1;
    }
    unsafe {
        crate::predb_feeds::nntmux_predb_parse_irc_announce_batch_nul(
            lines.cast(),
            lines_len,
            format,
            out_buf.cast(),
            out_cap,
        )
    }
}

#[inline]
fn is_php_trim_byte(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b)
}

#[inline]
fn php_trim(mut s: &[u8]) -> &[u8] {
    while let Some(&first) = s.first() {
        if is_php_trim_byte(first) {
            s = &s[1..];
        } else {
            break;
        }
    }
    while let Some(&last) = s.last() {
        if is_php_trim_byte(last) {
            s = &s[..s.len() - 1];
        } else {
            break;
        }
    }
    s
}

#[inline]
fn is_valid_ipv4(s: &[u8]) -> bool {
    if s.len() < 7 || s.len() > 15 {
        return false;
    }
    let mut octets = 0usize;
    let mut i = 0usize;
    while i < s.len() {
        let start = i;
        let mut val = 0u16;
        while i < s.len() && s[i] != b'.' {
            let b = s[i];
            if !b.is_ascii_digit() {
                return false;
            }
            val = val * 10 + u16::from(b - b'0');
            i += 1;
            if i - start > 3 {
                return false;
            }
        }
        let len = i - start;
        if len == 0 || (len > 1 && s[start] == b'0') || val > 255 {
            return false;
        }
        octets += 1;
        if i < s.len() {
            if octets == 4 || i + 1 == s.len() {
                return false;
            }
            i += 1;
        }
    }
    octets == 4
}

#[inline]
fn count_ipv6_groups(part: &[u8]) -> Option<usize> {
    if part.is_empty() {
        return None;
    }
    let mut groups = 0usize;
    let mut i = 0usize;
    while i < part.len() {
        let start = i;
        while i < part.len() && part[i] != b':' {
            if !part[i].is_ascii_hexdigit() {
                return None;
            }
            i += 1;
            if i - start > 4 {
                return None;
            }
        }
        if i == start {
            return None;
        }
        groups += 1;
        if i < part.len() {
            if i + 1 == part.len() {
                return None;
            }
            i += 1;
        }
    }
    Some(groups)
}

#[inline]
fn validate_ipv6_groups(s: &[u8], target_groups: usize) -> bool {
    let mut double_colon = None;
    let mut i = 0usize;
    while i + 1 < s.len() {
        if s[i] == b':' && s[i + 1] == b':' {
            double_colon = Some(i);
            break;
        }
        i += 1;
    }
    match double_colon {
        None => count_ipv6_groups(s) == Some(target_groups),
        Some(pos) => {
            let left = &s[..pos];
            let right = &s[pos + 2..];
            let left_groups = if left.is_empty() {
                0
            } else {
                match count_ipv6_groups(left) {
                    Some(n) => n,
                    None => return false,
                }
            };
            let right_groups = if right.is_empty() {
                0
            } else {
                match count_ipv6_groups(right) {
                    Some(n) => n,
                    None => return false,
                }
            };
            left_groups + right_groups < target_groups
        }
    }
}

#[inline]
fn is_valid_ipv6(s: &[u8]) -> bool {
    if s.len() < 2 || find_byte(s, b':').is_none() {
        return false;
    }
    if find_byte(s, b'.').is_some() {
        let Some(last_colon) = s.iter().rposition(|&b| b == b':') else {
            return false;
        };
        if last_colon == 0 || !is_valid_ipv4(&s[last_colon + 1..]) {
            return false;
        }
        let prefix = if s[last_colon - 1] == b':' {
            &s[..=last_colon]
        } else {
            &s[..last_colon]
        };
        return validate_ipv6_groups(prefix, 6);
    }
    validate_ipv6_groups(s, 8)
}

#[inline]
fn is_valid_cidr(cidr: &[u8]) -> Option<bool> {
    let Some(slash) = find_byte(cidr, b'/') else {
        return Some(false);
    };
    let address = &cidr[..slash];
    let prefix = &cidr[slash + 1..];
    if prefix.is_empty() || !prefix.iter().all(u8::is_ascii_digit) {
        return Some(false);
    }
    let is_ipv4 = is_valid_ipv4(address);
    let is_ipv6 = !is_ipv4 && is_valid_ipv6(address);
    if !is_ipv4 && !is_ipv6 {
        return Some(false);
    }
    let max_prefix: u16 = if is_ipv4 { 32 } else { 128 };
    let significant = match prefix.iter().position(|&b| b != b'0') {
        Some(idx) => &prefix[idx..],
        None => return Some(true),
    };
    if significant.len() > 18 {
        return None;
    }
    if significant.len() > 3 {
        return Some(false);
    }
    let mut val = 0u16;
    for &b in significant {
        val = val * 10 + u16::from(b - b'0');
    }
    Some(val <= max_prefix)
}

struct SeenCidrs<'a> {
    stack: [&'a [u8]; 32],
    len: usize,
    overflow_set: Option<std::collections::HashSet<&'a [u8]>>,
    overflow_vec: Vec<&'a [u8]>,
}

impl<'a> SeenCidrs<'a> {
    #[inline]
    fn new() -> Self {
        Self {
            stack: [&[]; 32],
            len: 0,
            overflow_set: None,
            overflow_vec: Vec::new(),
        }
    }

    #[inline]
    fn insert(&mut self, item: &'a [u8]) -> bool {
        if let Some(set) = &mut self.overflow_set {
            if set.insert(item) {
                self.overflow_vec.push(item);
                return true;
            }
            return false;
        }
        for i in 0..self.len {
            if self.stack[i] == item {
                return false;
            }
        }
        if self.len < 32 {
            self.stack[self.len] = item;
            self.len += 1;
            return true;
        }
        let mut set = std::collections::HashSet::with_capacity(64);
        for i in 0..32 {
            set.insert(self.stack[i]);
        }
        set.insert(item);
        self.overflow_set = Some(set);
        self.overflow_vec.push(item);
        true
    }

    #[inline]
    fn write_lines(&self, out: &mut OutCursor<'_>) {
        for i in 0..self.len {
            if i > 0 {
                out.push(b'\n');
            }
            out.extend(self.stack[i]);
        }
        for item in &self.overflow_vec {
            out.push(b'\n');
            out.extend(item);
        }
    }
}

#[inline]
fn normalize_cidr_token<'a>(raw: &'a [u8], allow_proxy_tokens: bool) -> Result<Option<&'a [u8]>, ()> {
    let trimmed = php_trim(raw);
    if trimmed.is_empty() || trimmed.first() == Some(&b'#') {
        return Ok(None);
    }
    if allow_proxy_tokens {
        if trimmed == b"REMOTE_ADDR" {
            return Ok(Some(b"REMOTE_ADDR"));
        }
        if trimmed == b"PRIVATE_SUBNETS" || trimmed == b"private_ranges" {
            return Ok(Some(b"PRIVATE_SUBNETS"));
        }
    }
    match is_valid_cidr(trimmed) {
        Some(true) => Ok(Some(trimmed)),
        Some(false) | None => Err(()),
    }
}

/// Validates a single CIDR string (`CloudflareIpRangeService::isValidCidr`).
/// Returns `1` if valid IPv4/IPv6 CIDR, `0` if invalid, or `-1` when declined to PHP fallback.
///
/// # Safety
/// `cidr` must be readable for `cidr_len` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_cloudflare_is_valid_cidr(
    cidr: *const c_char,
    cidr_len: usize,
) -> i32 {
    if cidr.is_null() && cidr_len != 0 {
        return -1;
    }
    let bytes = if cidr_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(cidr.cast::<u8>(), cidr_len) }
    };
    match is_valid_cidr(bytes) {
        Some(true) => 1,
        Some(false) => 0,
        None => -1,
    }
}

/// Parses a newline-delimited Cloudflare CIDR response body (`CloudflareIpRangeService::parseRangesFromBody`),
/// trimming, skipping blank/comment lines, validating CIDRs, and deduplicating in first-seen order.
/// Returns `0` for empty result, `> 0` bytes written (`\n`-separated), `-(required as isize)` (`<= -4`)
/// when `out_cap` is insufficient, or `-1` on invalid CIDR / invalid arguments so PHP can throw its exception.
///
/// # Safety
/// `body` must be readable for `body_len` bytes and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_cloudflare_parse_cidr_body(
    body: *const c_char,
    body_len: usize,
    allow_proxy_tokens: u8,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (body.is_null() && body_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || body_len > isize::MAX as usize
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    let bytes = if body_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(body.cast::<u8>(), body_len) }
    };
    let allow_tokens = allow_proxy_tokens != 0;
    let mut seen = SeenCidrs::new();
    for line in bytes.split(|&b| b == b'\r' || b == b'\n') {
        match normalize_cidr_token(line, allow_tokens) {
            Ok(Some(canon)) => {
                seen.insert(canon);
            }
            Ok(None) => {}
            Err(()) => return -1,
        }
    }
    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    seen.write_lines(&mut out);
    out.finish()
}

/// Normalizes and deduplicates `count` NUL-separated CIDR/proxy-token entries (`CloudflareIpRangeService::normalizeRanges`).
/// Returns `0` for empty result, `> 0` bytes written (`\n`-separated), `-(required as isize)` (`<= -4`)
/// when `out_cap` is insufficient, or `-1` on invalid entry / embedded NUL so PHP can fall back and throw.
///
/// # Safety
/// `input` must be readable for `input_len` bytes and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_cloudflare_normalize_cidrs_nul(
    input: *const c_char,
    input_len: usize,
    count: usize,
    allow_proxy_tokens: u8,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (input.is_null() && input_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || input_len > isize::MAX as usize
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    if count == 0 {
        return if input_len == 0 { 0 } else { -1 };
    }
    let bytes = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input.cast::<u8>(), input_len) }
    };
    let allow_tokens = allow_proxy_tokens != 0;
    let mut seen = SeenCidrs::new();
    let mut seg_count = 0usize;
    for raw in bytes.split(|&b| b == 0) {
        seg_count += 1;
        match normalize_cidr_token(raw, allow_tokens) {
            Ok(Some(canon)) => {
                seen.insert(canon);
            }
            Ok(None) => {}
            Err(()) => return -1,
        }
    }
    if seg_count != count {
        return -1;
    }
    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    seen.write_lines(&mut out);
    out.finish()
}

#[inline]
fn contains_ascii_case_insensitive(haystack: &[u8], needle: &[u8]) -> bool {
    if needle.is_empty() || needle.len() > haystack.len() {
        return false;
    }
    let first_lo = needle[0].to_ascii_lowercase();
    let first_hi = needle[0].to_ascii_uppercase();
    let rest = &needle[1..];
    let end = haystack.len() - needle.len() + 1;
    let mut i = 0usize;
    while i < end {
        let b = haystack[i];
        if (b == first_lo || b == first_hi)
            && haystack[i + 1..i + needle.len()].eq_ignore_ascii_case(rest)
        {
            return true;
        }
        i += 1;
    }
    false
}

/// Matches `haystack` against `patterns` using ASCII case-insensitive substring search (`IndexerProxyDetector`).
/// - `mode = 0`: `patterns` is a raw configuration string split on `\r`, `\n`, or `,`, trimmed via `php_trim`,
///   and filtered with PHP `array_filter` rules (skipping `""` and `"0"`).
/// - `mode = 1`: `patterns` is a NUL-separated list of `count` pattern strings; each non-empty pattern (`!= ""`)
///   is tested in order. If the number of NUL-separated segments does not equal `count`, returns `-1`.
/// Returns `1` if any pattern matches, `0` if none match, or `-1` on invalid arguments.
///
/// # Safety
/// `haystack` and `patterns` must be readable for their respective byte lengths when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_proxy_detector_match_referer(
    haystack: *const c_char,
    haystack_len: usize,
    patterns: *const c_char,
    patterns_len: usize,
    count: usize,
    mode: u8,
) -> i32 {
    if (haystack.is_null() && haystack_len != 0)
        || (patterns.is_null() && patterns_len != 0)
        || mode > 1
    {
        return -1;
    }
    let hay = if haystack_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(haystack.cast::<u8>(), haystack_len) }
    };
    let pat_bytes = if patterns_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(patterns.cast::<u8>(), patterns_len) }
    };
    if mode == 0 {
        if hay.is_empty() || pat_bytes.is_empty() {
            return 0;
        }
        for raw in pat_bytes.split(|&b| matches!(b, b'\r' | b'\n' | b',')) {
            let trimmed = php_trim(raw);
            if !trimmed.is_empty()
                && trimmed != b"0"
                && contains_ascii_case_insensitive(hay, trimmed)
            {
                return 1;
            }
        }
        0
    } else {
        if count == 0 {
            return if patterns_len == 0 { 0 } else { -1 };
        }
        let mut seen = 0usize;
        let mut matched = false;
        for pat in pat_bytes.split(|&b| b == 0) {
            seen += 1;
            if !matched && !hay.is_empty() && !pat.is_empty() && contains_ascii_case_insensitive(hay, pat) {
                matched = true;
            }
        }
        if seen != count {
            return -1;
        }
        i32::from(matched)
    }
}

/// Builds `proxy_detect:` + `:`-joined parts where any part containing `:` or with `len > 40`
/// is replaced by its 32-byte lowercase hex MD5 (`IndexerProxyDetector::key`).
/// Returns bytes written (`> 0`), `-(required as isize)` if `out_cap` is too small, or `-1` on invalid input.
///
/// # Safety
/// `input` must be readable for `input_len` bytes and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_proxy_detector_cache_key_nul(
    input: *const c_char,
    input_len: usize,
    count: usize,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (input.is_null() && input_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || input_len > isize::MAX as usize
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    if count == 0 {
        if input_len != 0 {
            return -1;
        }
        let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
        out.extend(b"proxy_detect:");
        return out.finish();
    }
    let bytes: &[u8] = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input.cast::<u8>(), input_len) }
    };
    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    out.extend(b"proxy_detect:");
    let mut seen = 0usize;
    for (idx, part) in bytes.split(|&b| b == 0).enumerate() {
        seen += 1;
        if idx > 0 {
            out.push(b':');
        }
        if part.len() > 40 || find_byte(part, b':').is_some() {
            let hex = crate::md5_hex_slice(part);
            out.extend(&hex);
        } else {
            out.extend(part);
        }
    }
    if seen != count {
        return -1;
    }
    out.finish()
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn websocket_swar_boundaries_and_capacity_guards() {
        let mask = [0, 0x81, 0xfe, 0x7f];
        for len in 0..260 {
            let payload: Vec<u8> = (0..len).map(|i| (i * 73) as u8).collect();
            let mut frame = vec![0xa5u8; len + 20];
            let mut out = vec![0xa5u8; len + 2];
            let mut meta = [0u32; 5];
            unsafe {
                let written = nntmux_ws_encode_frame(
                    2,
                    payload.as_ptr().cast(),
                    len,
                    mask.as_ptr().cast(),
                    4,
                    frame.as_mut_ptr().add(1).cast(),
                    len + 14,
                );
                assert!(written > 0);
                let frame_end = 1 + written as usize;
                assert_eq!(frame[0], 0xa5);
                assert!(frame[frame_end..].iter().all(|&b| b == 0xa5));
                let header = if len < 126 { 2 } else { 4 };
                for i in 0..len {
                    assert_eq!(frame[1 + header + 4 + i], payload[i] ^ mask[i & 3]);
                }
                assert_eq!(
                    nntmux_ws_decode_frame(
                        frame.as_ptr().add(1).cast(),
                        written as usize,
                        meta.as_mut_ptr(),
                        out.as_mut_ptr().add(1).cast(),
                        len
                    ),
                    len as isize
                );
                assert_eq!(&out[1..1 + len], &payload);
                assert_eq!(out[0], 0xa5);
                assert_eq!(out[len + 1], 0xa5);
                assert_eq!(meta, [1, 2, written as u32, len as u32, 0]);
                for end in 0..written as usize {
                    assert_eq!(
                        nntmux_ws_decode_frame(
                            frame.as_ptr().add(1).cast(),
                            end,
                            meta.as_mut_ptr(),
                            out.as_mut_ptr().cast(),
                            out.len()
                        ),
                        -1
                    );
                }
                assert_eq!(
                    nntmux_ws_encode_frame(
                        2,
                        payload.as_ptr().cast(),
                        len,
                        mask.as_ptr().cast(),
                        4,
                        std::ptr::null_mut(),
                        0
                    ),
                    -written
                );
                if len != 0 {
                    out.fill(0xa5);
                    assert_eq!(
                        nntmux_ws_decode_frame(
                            frame.as_ptr().add(1).cast(),
                            written as usize,
                            meta.as_mut_ptr(),
                            out.as_mut_ptr().cast(),
                            len - 1
                        ),
                        -4
                    );
                    assert!(out.iter().all(|&b| b == 0xa5));
                }
            }
        }
    }

    #[test]
    fn websocket_invalid_arguments_and_length_errors() {
        let mut meta = [0u32; 5];
        unsafe {
            assert_eq!(
                nntmux_ws_decode_frame(
                    std::ptr::null(),
                    0,
                    meta.as_mut_ptr(),
                    std::ptr::null_mut(),
                    0
                ),
                -1
            );
            assert_eq!(
                nntmux_ws_decode_frame(
                    std::ptr::null(),
                    1,
                    meta.as_mut_ptr(),
                    std::ptr::null_mut(),
                    0
                ),
                -5
            );
            let mut frame: [u8; 10] = [0x81, 127, 0x80, 0, 0, 0, 0, 0, 0, 0];
            assert_eq!(
                nntmux_ws_decode_frame(
                    frame.as_ptr().cast(),
                    10,
                    meta.as_mut_ptr(),
                    std::ptr::null_mut(),
                    0
                ),
                -2
            );
            frame[2] = 0;
            frame[7] = 0x40;
            frame[9] = 1;
            assert_eq!(
                nntmux_ws_decode_frame(
                    frame.as_ptr().cast(),
                    10,
                    meta.as_mut_ptr(),
                    std::ptr::null_mut(),
                    0
                ),
                -3
            );
            assert_eq!(meta[3], 4194305);
            frame[9] = 0;
            assert_eq!(
                nntmux_ws_decode_frame(
                    frame.as_ptr().cast(),
                    10,
                    meta.as_mut_ptr(),
                    std::ptr::null_mut(),
                    0
                ),
                -1
            );
            assert_eq!(
                nntmux_irc_pre_announce_parse(std::ptr::null(), 1, 0, std::ptr::null_mut(), 0),
                -1
            );
            assert_eq!(
                nntmux_irc_pre_announce_parse_batch_nul(
                    b"x".as_ptr().cast(),
                    1,
                    0,
                    std::ptr::null_mut(),
                    0
                ),
                -1
            );
            let mut out = [0u8; 8];
            assert_eq!(
                nntmux_irc_pre_announce_parse_batch_nul(
                    b"\0\0".as_ptr().cast(),
                    2,
                    0,
                    out.as_mut_ptr().cast(),
                    8
                ),
                2
            );
            assert_eq!(&out[..2], b"\n\n");
        }
    }

    #[test]
    fn unaligned_search_and_small_copies_stay_within_bounds() {
        let mut input = [0u8; 128];
        for (i, b) in input.iter_mut().enumerate() {
            *b = (i * 73) as u8;
        }
        for offset in 0..16 {
            for len in 0..80 {
                let bytes = &input[offset..offset + len];
                for byte in 0..=255 {
                    assert_eq!(
                        find_byte(bytes, byte),
                        bytes.iter().position(|&b| b == byte)
                    );
                }
                let mut out = [0xa5; 128];
                unsafe {
                    copy_field(bytes, out.as_mut_ptr().add(offset));
                }
                assert_eq!(&out[offset..offset + len], bytes);
                assert!(out[..offset].iter().all(|&b| b == 0xa5));
                assert!(out[offset + len..].iter().all(|&b| b == 0xa5));
            }
        }
    }

    #[test]
    fn inbound_records_and_scratch_guards() {
        let mut out = [0xa5; 128];
        let line = b":bot!user@host PRIVMSG #pre :\x0312release\x03";
        unsafe {
            let written = nntmux_irc_parse_inbound_line(
                line.as_ptr(),
                line.len(),
                out.as_mut_ptr(),
                out.len(),
            );
            assert_eq!(&out[..written as usize], b"M\0bot\0#pre\0release");
            assert!(out[line.len() + 2..].iter().all(|&b| b == 0xa5));
            assert_eq!(
                nntmux_irc_parse_inbound_line(line.as_ptr(), line.len(), std::ptr::null_mut(), 0),
                -((line.len() + 4) as isize)
            );
            assert_eq!(
                nntmux_irc_parse_inbound_line(std::ptr::null(), 1, out.as_mut_ptr(), out.len()),
                -1
            );
            assert_eq!(
                nntmux_irc_parse_inbound_line(line.as_ptr(), line.len(), std::ptr::null_mut(), 128),
                -1
            );
            let ping = b"PING :server\n";
            let written = nntmux_irc_parse_inbound_line(
                ping.as_ptr(),
                ping.len(),
                out.as_mut_ptr(),
                out.len(),
            );
            assert_eq!(&out[..written as usize], b"P\0server");
        }
    }

    #[test]
    fn buffer_contract_and_null_arguments() {
        let s = b"\x0312text\x04abcdef,123456! ";
        let mut out = [0u8; 32];
        unsafe {
            assert_eq!(
                nntmux_irc_strip_control_chars(s.as_ptr(), s.len(), out.as_mut_ptr(), 1),
                -(s.len() as isize)
            );
            assert_eq!(
                nntmux_irc_strip_control_chars(s.as_ptr(), s.len(), out.as_mut_ptr(), out.len()),
                6
            );
            assert_eq!(&out[..6], b"text! ");
            assert_eq!(
                nntmux_irc_strip_control_chars(std::ptr::null(), 1, out.as_mut_ptr(), 32),
                -1
            );
            assert_eq!(
                nntmux_irc_strip_control_chars(std::ptr::null(), 0, out.as_mut_ptr(), 32),
                0
            );
            assert_eq!(
                nntmux_irc_parse_bot_message(std::ptr::null(), 1, out.as_mut_ptr(), 32),
                -1
            );
        }
    }

    #[test]
    fn cloudflare_cidr_validation_and_normalization_contracts() {
        for valid in [
            b"173.245.48.0/20".as_slice(),
            b"0.0.0.0/0",
            b"255.255.255.255/32",
            b"192.0.2.1/08",
            b"::/0",
            b"::1/128",
            b"2400:cb00::/32",
            b"::ffff:192.0.2.1/96",
            b"1:2:3:4:5:6:192.0.2.1/128",
            b"1:2:3:4:5::192.0.2.1/64",
        ] {
            unsafe {
                assert_eq!(
                    nntmux_cloudflare_is_valid_cidr(valid.as_ptr().cast(), valid.len()),
                    1,
                    "expected valid: {:?}",
                    std::str::from_utf8(valid)
                );
            }
        }
        for invalid in [
            b"".as_slice(),
            b"173.245.48.0",
            b"173.245.48.0/",
            b"173.245.48.0/33",
            b"173.245.48.0/-1",
            b"01.2.3.4/24",
            b"256.0.0.1/24",
            b"2400:cb00::/129",
            b"1:2:3:4:5:6::192.0.2.1/64",
            b"1:2:3:4:5:6:7::8/64",
            b"fe80::1%eth0/64",
        ] {
            unsafe {
                assert_eq!(
                    nntmux_cloudflare_is_valid_cidr(invalid.as_ptr().cast(), invalid.len()),
                    0,
                    "expected invalid: {:?}",
                    std::str::from_utf8(invalid)
                );
            }
        }
        let body = b" # comment\r\n 173.245.48.0/20 \n104.16.0.0/13\r173.245.48.0/20\n2400:cb00::/32\n";
        let mut out = [0u8; 128];
        unsafe {
            let written = nntmux_cloudflare_parse_cidr_body(
                body.as_ptr().cast(),
                body.len(),
                0,
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert!(written > 0);
            assert_eq!(
                &out[..written as usize],
                b"173.245.48.0/20\n104.16.0.0/13\n2400:cb00::/32"
            );
            assert_eq!(
                nntmux_cloudflare_parse_cidr_body(
                    body.as_ptr().cast(),
                    body.len(),
                    0,
                    out.as_mut_ptr().cast(),
                    4
                ),
                -written
            );
            let nul = b" private_ranges \0REMOTE_ADDR\0PRIVATE_SUBNETS\010.0.0.0/8";
            let written2 = nntmux_cloudflare_normalize_cidrs_nul(
                nul.as_ptr().cast(),
                nul.len(),
                4,
                1,
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert!(written2 > 0);
            assert_eq!(
                &out[..written2 as usize],
                b"PRIVATE_SUBNETS\nREMOTE_ADDR\n10.0.0.0/8"
            );
        }
    }

    #[test]
    fn proxy_detector_referer_and_cache_key_contracts() {
        let referer = b"http://Prowlarr.local:9696/1/api";
        let spec = b" hydra , 0 , \r\n prowlarr ";
        unsafe {
            assert_eq!(
                nntmux_proxy_detector_match_referer(
                    referer.as_ptr().cast(),
                    referer.len(),
                    spec.as_ptr().cast(),
                    spec.len(),
                    0,
                    0
                ),
                1
            );
            let nul_pats = b"hydra\0prowlarr";
            assert_eq!(
                nntmux_proxy_detector_match_referer(
                    referer.as_ptr().cast(),
                    referer.len(),
                    nul_pats.as_ptr().cast(),
                    nul_pats.len(),
                    2,
                    1
                ),
                1
            );
            let key_in = b"ip\02001:db8::1\0indexer_ua";
            let mut out = [0u8; 128];
            let written = nntmux_proxy_detector_cache_key_nul(
                key_in.as_ptr().cast(),
                key_in.len(),
                3,
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert!(written > 0);
            assert_eq!(
                &out[..written as usize],
                b"proxy_detect:ip:1d64d10fff2ff210518623b74a8c1696:indexer_ua"
            );
        }
    }
}
