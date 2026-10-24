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
    fn count(&self) -> usize {
        self.len + self.overflow_vec.len()
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

#[inline]
fn contains_subslice(haystack: &[u8], needle: &[u8]) -> bool {
    if needle.is_empty() {
        return true;
    }
    if needle.len() > haystack.len() {
        return false;
    }
    let first = needle[0];
    let rest = &needle[1..];
    let mut s = haystack;
    while s.len() >= needle.len() {
        let Some(pos) = find_byte(s, first) else {
            return false;
        };
        s = &s[pos..];
        if s.len() < needle.len() {
            return false;
        }
        if &s[1..needle.len()] == rest {
            return true;
        }
        s = &s[1..];
    }
    false
}

#[inline]
fn is_pcre_linebreak_byte(b: u8) -> bool {
    matches!(b, b'\n' | b'\r' | 0x0b | 0x0c | 0x85)
}

#[inline]
fn extract_peer_endpoint_slice(line: &[u8]) -> Option<&[u8]> {
    let trimmed = php_trim(line);
    if trimmed.is_empty() {
        return None;
    }
    let mut col = 0usize;
    let mut i = 0usize;
    loop {
        let start = i;
        while i < trimmed.len() && !ws(trimmed[i]) {
            i += 1;
        }
        if col == 4 {
            return Some(&trimmed[start..i]);
        }
        if i >= trimmed.len() {
            return None;
        }
        while i < trimmed.len() && ws(trimmed[i]) {
            i += 1;
        }
        col += 1;
    }
}

#[inline]
fn socket_line_has_peer_port_with_peer(
    line: &[u8],
    peer: Option<&[u8]>,
    port: &[u8],
) -> Option<bool> {
    if let Some(p) = peer {
        if let Some(colon_pos) = p.iter().rposition(|&b| b == b':') {
            let peer_port = &p[colon_pos + 1..];
            return Some(peer_port == port || (port == b"443" && peer_port == b"https"));
        }
    }
    if port.iter().any(|&b| ws(b)) {
        return None;
    }
    let min_tok_len = port.len() + 2;
    let mut i = 0usize;
    while i < line.len() {
        while i < line.len() && ws(line[i]) {
            i += 1;
        }
        let start = i;
        while i < line.len() && !ws(line[i]) {
            i += 1;
        }
        let tok = &line[start..i];
        if tok.len() >= min_tok_len
            && tok.ends_with(port)
            && tok[tok.len() - port.len() - 1] == b':'
        {
            return Some(true);
        }
    }
    Some(false)
}

#[inline]
fn peer_matches_address(peer: &[u8], ip: &[u8]) -> bool {
    if peer == ip {
        return true;
    }
    if peer.len() > ip.len() && peer.starts_with(ip) && peer[ip.len()] == b':' {
        return true;
    }
    peer.len() >= ip.len() + 3
        && peer[0] == b'['
        && &peer[1..1 + ip.len()] == ip
        && &peer[1 + ip.len()..1 + ip.len() + 2] == b"]:"
}

#[inline]
fn socket_line_matches_any_address_with_peer(
    line: &[u8],
    peer: Option<&[u8]>,
    addresses: &[&[u8]],
) -> bool {
    match peer {
        Some(p) => addresses.iter().any(|&ip| peer_matches_address(p, ip)),
        None => addresses.iter().any(|&ip| contains_subslice(line, ip)),
    }
}

fn collect_nul_slices<'a>(
    raw: &'a [u8],
    count: usize,
    stack: &mut [&'a [u8]; 32],
    overflow: &mut Vec<&'a [u8]>,
) -> Option<usize> {
    if count == 0 {
        return raw.is_empty().then_some(0);
    }
    let mut seen = 0usize;
    for part in raw.split(|&b| b == 0) {
        if seen < 32 {
            stack[seen] = part;
        } else {
            overflow.push(part);
        }
        seen += 1;
    }
    (seen == count).then_some(seen)
}

#[inline]
fn matches_any_collected(
    line: &[u8],
    peer: Option<&[u8]>,
    stack: &[&[u8]; 32],
    overflow: &[&[u8]],
    count: usize,
) -> bool {
    let stack_len = count.min(32);
    if socket_line_matches_any_address_with_peer(line, peer, &stack[..stack_len]) {
        return true;
    }
    !overflow.is_empty() && socket_line_matches_any_address_with_peer(line, peer, overflow)
}

/// Extracts column 4 (`$columns[4] ?? null` from `preg_split('/\s+/', trim($line))`) for `Tmux::extractPeerEndpoint`.
/// Returns `-1` when absent (`null`) or on invalid arguments, `0` when empty string, `> 0` bytes written,
/// or `-(needed as isize)` (`<= -2`) when `out_cap` is insufficient.
///
/// # Safety
/// `line` must be readable for `line_len` bytes and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_extract_peer_endpoint(
    line: *const c_char,
    line_len: usize,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (line.is_null() && line_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || line_len > isize::MAX as usize
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    let bytes = if line_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(line.cast::<u8>(), line_len) }
    };
    let Some(peer) = extract_peer_endpoint_slice(bytes) else {
        return -1;
    };
    if peer.is_empty() {
        return 0;
    }
    if out_buf.is_null() || out_cap < peer.len() {
        return -(peer.len().max(2) as isize);
    }
    unsafe {
        copy_field(peer, out_buf.cast::<u8>());
    }
    peer.len() as isize
}

/// Checks whether a socket snapshot line matches `port` (`Tmux::socketLineHasPeerPort`).
/// Returns `1` if matched, `0` if not matched, or `-1` on invalid arguments / declined input.
///
/// # Safety
/// `line` and `port` must be readable for their respective byte lengths when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_socket_line_has_peer_port(
    line: *const c_char,
    line_len: usize,
    port: *const c_char,
    port_len: usize,
) -> i32 {
    if (line.is_null() && line_len != 0) || (port.is_null() && port_len != 0) {
        return -1;
    }
    let line_bytes = if line_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(line.cast::<u8>(), line_len) }
    };
    let port_bytes = if port_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(port.cast::<u8>(), port_len) }
    };
    let peer = extract_peer_endpoint_slice(line_bytes);
    match socket_line_has_peer_port_with_peer(line_bytes, peer, port_bytes) {
        Some(matched) => i32::from(matched),
        None => -1,
    }
}

/// Checks whether a socket snapshot line matches any of `addr_count` NUL-separated addresses
/// (`Tmux::socketLineMatchesAnyAddress`). Returns `1` if matched, `0` if not, or `-1` on invalid input.
///
/// # Safety
/// `line`, `addresses`, and `port` must be readable for their respective byte lengths when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_socket_line_matches_any_address_nul(
    line: *const c_char,
    line_len: usize,
    addresses: *const c_char,
    addresses_len: usize,
    addr_count: usize,
    port: *const c_char,
    port_len: usize,
) -> i32 {
    if (line.is_null() && line_len != 0)
        || (addresses.is_null() && addresses_len != 0)
        || (port.is_null() && port_len != 0)
    {
        return -1;
    }
    if addr_count == 0 {
        return if addresses_len == 0 { 0 } else { -1 };
    }
    let line_bytes = if line_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(line.cast::<u8>(), line_len) }
    };
    let addr_bytes = if addresses_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(addresses.cast::<u8>(), addresses_len) }
    };
    let mut stack = [&[][..]; 32];
    let mut overflow = Vec::new();
    if collect_nul_slices(addr_bytes, addr_count, &mut stack, &mut overflow).is_none() {
        return -1;
    }
    let peer = extract_peer_endpoint_slice(line_bytes);
    i32::from(matches_any_collected(
        line_bytes,
        peer,
        &stack,
        &overflow,
        addr_count,
    ))
}

/// Evaluates `Tmux::getUSPConnections` over a complete `ss -nH` socket snapshot in a single zero-allocation pass.
/// Returns packed `((total as i64) << 32) | (active as i64)` (`>= 0`), or `-1` on invalid/declined input.
///
/// # Safety
/// All non-null pointers must be readable for their stated byte lengths.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_count_usp_connections(
    is_alternate: u8,
    port: *const c_char,
    port_len: usize,
    other_port: *const c_char,
    other_port_len: usize,
    ips: *const c_char,
    ips_len: usize,
    ips_count: usize,
    other_ips: *const c_char,
    other_ips_len: usize,
    other_ips_count: usize,
    snapshot: *const c_char,
    snapshot_len: usize,
) -> i64 {
    if (port.is_null() && port_len != 0)
        || (other_port.is_null() && other_port_len != 0)
        || (ips.is_null() && ips_len != 0)
        || (other_ips.is_null() && other_ips_len != 0)
        || (snapshot.is_null() && snapshot_len != 0)
        || is_alternate > 1
    {
        return -1;
    }
    let port_bytes = if port_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(port.cast::<u8>(), port_len) }
    };
    let other_port_bytes = if other_port_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(other_port.cast::<u8>(), other_port_len) }
    };
    let ips_bytes = if ips_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(ips.cast::<u8>(), ips_len) }
    };
    let other_ips_bytes = if other_ips_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(other_ips.cast::<u8>(), other_ips_len) }
    };
    let snap_bytes = if snapshot_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(snapshot.cast::<u8>(), snapshot_len) }
    };

    let mut ips_stack = [&[][..]; 32];
    let mut ips_overflow = Vec::new();
    if collect_nul_slices(ips_bytes, ips_count, &mut ips_stack, &mut ips_overflow).is_none() {
        return -1;
    }
    let mut other_stack = [&[][..]; 32];
    let mut other_overflow = Vec::new();
    if collect_nul_slices(
        other_ips_bytes,
        other_ips_count,
        &mut other_stack,
        &mut other_overflow,
    )
    .is_none()
    {
        return -1;
    }

    let has_port = !port_bytes.is_empty();
    let same_port_as_other = has_port && port_bytes == other_port_bytes;

    let mut port_lines_found = false;
    let mut pass1_total = 0u32;
    let mut pass1_active = 0u32;
    let mut pass2_total = 0u32;
    let mut pass2_active = 0u32;

    let mut pos = 0usize;
    while pos < snap_bytes.len() {
        let start = pos;
        while pos < snap_bytes.len() && !is_pcre_linebreak_byte(snap_bytes[pos]) {
            pos += 1;
        }
        let line = &snap_bytes[start..pos];
        if pos < snap_bytes.len() {
            if snap_bytes[pos] == b'\r' && snap_bytes.get(pos + 1) == Some(&b'\n') {
                pos += 2;
            } else {
                pos += 1;
            }
        }
        if php_trim(line).is_empty() {
            continue;
        }

        let peer = extract_peer_endpoint_slice(line);
        if has_port {
            let Some(has_peer_port) = socket_line_has_peer_port_with_peer(line, peer, port_bytes)
            else {
                return -1;
            };
            if has_peer_port {
                port_lines_found = true;
                let keep = if same_port_as_other {
                    if is_alternate != 0 {
                        ips_count > 0
                            && matches_any_collected(
                                line,
                                peer,
                                &ips_stack,
                                &ips_overflow,
                                ips_count,
                            )
                    } else {
                        other_ips_count == 0
                            || !matches_any_collected(
                                line,
                                peer,
                                &other_stack,
                                &other_overflow,
                                other_ips_count,
                            )
                    }
                } else {
                    true
                };
                if keep {
                    pass1_total += 1;
                    if contains_subslice(line, b"ESTAB") {
                        pass1_active += 1;
                    }
                }
            }
        }

        if !port_lines_found
            && ips_count > 0
            && matches_any_collected(line, peer, &ips_stack, &ips_overflow, ips_count)
        {
            pass2_total += 1;
            if contains_subslice(line, b"ESTAB") {
                pass2_active += 1;
            }
        }
    }

    if port_lines_found {
        ((pass1_total as i64) << 32) | (pass1_active as i64)
    } else if pass2_total > 0 {
        ((pass2_total as i64) << 32) | (pass2_active as i64)
    } else {
        0
    }
}

#[inline]
fn php_round_2(val: f64) -> f64 {
    if !val.is_finite() || val == 0.0 {
        return 0.0;
    }
    let f1 = 100.0_f64;
    let mut tmp = val * f1;
    let abs = val.abs();
    let precision_places = 14 - (abs.log10().floor() as i32);
    if precision_places > 2 && precision_places - 2 < 15 {
        let f2 = 10.0_f64.powi(precision_places - 2);
        let tmp2 = tmp * f2;
        tmp = (if tmp2 >= 0.0 {
            (tmp2 + 0.5).floor()
        } else {
            (tmp2 - 0.5).ceil()
        }) / f2;
    }
    tmp = if tmp >= 0.0 {
        (tmp + 0.5).floor()
    } else {
        (tmp - 0.5).ceil()
    };
    tmp / f1
}

fn write_u64_ascii(mut val: u64, buf: &mut [u8; 24]) -> &[u8] {
    let mut i = buf.len();
    if val == 0 {
        i -= 1;
        buf[i] = b'0';
        return &buf[i..];
    }
    while val > 0 {
        i -= 1;
        buf[i] = b'0' + (val % 10) as u8;
        val /= 10;
    }
    &buf[i..]
}

/// Formats `Tmux::decodeSize(int|float $bytes): string`.
/// Returns bytes written (`> 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small,
/// or `-1` when non-finite or out of range so PHP can handle it.
///
/// # Safety
/// `out_buf` must be writable for `out_cap` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_decode_size(
    bytes: f64,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (out_buf.is_null() && out_cap != 0) || out_cap > isize::MAX as usize || !bytes.is_finite() {
        return -1;
    }
    if bytes == 0.0 {
        let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
        if bytes.is_sign_negative() {
            out.extend(b"-0 B");
        } else {
            out.extend(b"0 B");
        }
        return out.finish();
    }
    let neg = bytes < 0.0;
    let mut b = bytes.abs();
    const UNITS: [&[u8]; 7] = [b"B", b"KB", b"MB", b"GB", b"TB", b"PB", b"EB"];
    let mut idx = 0usize;
    while b >= 1024.0 && idx < UNITS.len() - 1 {
        b /= 1024.0;
        idx += 1;
    }
    if b >= 1.0e12 {
        return -1;
    }
    let rounded = php_round_2(b);
    let hundredths = (rounded * 100.0).round() as u64;
    let whole = hundredths / 100;
    let rem = (hundredths % 100) as u8;

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    if neg {
        out.push(b'-');
    }
    let mut num_buf = [0u8; 24];
    out.extend(write_u64_ascii(whole, &mut num_buf));
    if rem != 0 {
        out.push(b'.');
        if rem.is_multiple_of(10) {
            out.push(b'0' + rem / 10);
        } else {
            out.push(b'0' + rem / 10);
            out.push(b'0' + rem % 10);
        }
    }
    out.push(b' ');
    out.extend(UNITS[idx]);
    out.finish()
}

#[cfg(target_arch = "aarch64")]
#[inline]
fn count_nl_proc_neon_chunk(bytes: &[u8], base: usize, m: std::arch::aarch64::uint8x16_t) -> usize {
    use std::arch::aarch64::*;
    let mut count = 0usize;
    let (mut lo, mut hi) = unsafe {
        let u64s = vreinterpretq_u64_u8(m);
        (vgetq_lane_u64::<0>(u64s), vgetq_lane_u64::<1>(u64s))
    };
    while lo != 0 {
        let lane = (lo.trailing_zeros() >> 3) as usize;
        let j = base + lane;
        if bytes.get(j + 2..j + 10) == Some(b"rocessor") {
            count += 1;
        }
        lo &= !(0xFFu64 << (lane * 8));
    }
    while hi != 0 {
        let lane = (hi.trailing_zeros() >> 3) as usize;
        let j = base + 8 + lane;
        if bytes.get(j + 2..j + 10) == Some(b"rocessor") {
            count += 1;
        }
        hi &= !(0xFFu64 << (lane * 8));
    }
    count
}

#[inline]
fn count_newline_processor(bytes: &[u8]) -> usize {
    let mut count = 0usize;
    let mut i = 0usize;
    #[cfg(target_arch = "aarch64")]
    unsafe {
        use std::arch::aarch64::*;
        let nl = vdupq_n_u8(b'\n');
        let p = vdupq_n_u8(b'p');
        while i + 65 <= bytes.len() {
            let ptr = bytes.as_ptr().add(i);
            let m0 = vandq_u8(vceqq_u8(vld1q_u8(ptr), nl), vceqq_u8(vld1q_u8(ptr.add(1)), p));
            let m1 = vandq_u8(
                vceqq_u8(vld1q_u8(ptr.add(16)), nl),
                vceqq_u8(vld1q_u8(ptr.add(17)), p),
            );
            let m2 = vandq_u8(
                vceqq_u8(vld1q_u8(ptr.add(32)), nl),
                vceqq_u8(vld1q_u8(ptr.add(33)), p),
            );
            let m3 = vandq_u8(
                vceqq_u8(vld1q_u8(ptr.add(48)), nl),
                vceqq_u8(vld1q_u8(ptr.add(49)), p),
            );
            let any = vorrq_u8(vorrq_u8(m0, m1), vorrq_u8(m2, m3));
            if vmaxvq_u8(any) != 0 {
                if vmaxvq_u8(m0) != 0 {
                    count += count_nl_proc_neon_chunk(bytes, i, m0);
                }
                if vmaxvq_u8(m1) != 0 {
                    count += count_nl_proc_neon_chunk(bytes, i + 16, m1);
                }
                if vmaxvq_u8(m2) != 0 {
                    count += count_nl_proc_neon_chunk(bytes, i + 32, m2);
                }
                if vmaxvq_u8(m3) != 0 {
                    count += count_nl_proc_neon_chunk(bytes, i + 48, m3);
                }
            }
            i += 64;
        }
        while i + 17 <= bytes.len() {
            let ptr = bytes.as_ptr().add(i);
            let m0 = vandq_u8(vceqq_u8(vld1q_u8(ptr), nl), vceqq_u8(vld1q_u8(ptr.add(1)), p));
            if vmaxvq_u8(m0) != 0 {
                count += count_nl_proc_neon_chunk(bytes, i, m0);
            }
            i += 16;
        }
    }
    #[cfg(target_arch = "x86_64")]
    unsafe {
        use std::arch::x86_64::*;
        let nl = _mm_set1_epi8(b'\n' as i8);
        let p = _mm_set1_epi8(b'p' as i8);
        while i + 17 <= bytes.len() {
            let v_nl = _mm_loadu_si128(bytes.as_ptr().add(i).cast());
            let v_p = _mm_loadu_si128(bytes.as_ptr().add(i + 1).cast());
            let mut mask = _mm_movemask_epi8(_mm_and_si128(
                _mm_cmpeq_epi8(v_nl, nl),
                _mm_cmpeq_epi8(v_p, p),
            )) as u32;
            while mask != 0 {
                let lane = mask.trailing_zeros() as usize;
                let j = i + lane;
                if bytes.get(j + 2..j + 10) == Some(b"rocessor") {
                    count += 1;
                }
                mask &= mask - 1;
            }
            i += 16;
        }
    }
    while i + 10 <= bytes.len() {
        if bytes[i] == b'\n' && &bytes[i + 1..i + 10] == b"processor" {
            count += 1;
        }
        i += 1;
    }
    count
}

/// Counts `/^processor/m` matches in `/proc/cpuinfo` (`SystemMetricsService::getCpuCount`),
/// returning `count.max(1)` (`>= 1`) or `-1` on invalid arguments.
///
/// # Safety
/// `cpuinfo` must be readable for `cpuinfo_len` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_metrics_parse_cpu_count(
    cpuinfo: *const c_char,
    cpuinfo_len: usize,
) -> i32 {
    if cpuinfo.is_null() && cpuinfo_len != 0 {
        return -1;
    }
    let bytes = if cpuinfo_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(cpuinfo.cast::<u8>(), cpuinfo_len) }
    };
    let mut count = usize::from(bytes.starts_with(b"processor"));
    count += count_newline_processor(bytes);
    if count > i32::MAX as usize {
        return -1;
    }
    count.max(1) as i32
}

#[inline]
fn parse_ws_digits_u64(s: &[u8]) -> Result<Option<(u64, usize)>, ()> {
    let mut i = 0usize;
    while i < s.len() && ws(s[i]) {
        i += 1;
    }
    if i == 0 || i >= s.len() || !s[i].is_ascii_digit() {
        return Ok(None);
    }
    let start = i;
    while i < s.len() && s[i].is_ascii_digit() {
        i += 1;
    }
    let mut val = 0u64;
    for &b in &s[start..i] {
        val = val
            .checked_mul(10)
            .ok_or(())?
            .checked_add(u64::from(b - b'0'))
            .ok_or(())?;
    }
    Ok(Some((val, i)))
}

fn extract_labeled_u64(mut s: &[u8], label: &[u8]) -> Result<Option<(u64, usize)>, ()> {
    let mut base_offset = 0usize;
    if s.len() > label.len() && s.starts_with(label) {
        if let Some((val, consumed)) = parse_ws_digits_u64(&s[label.len()..])? {
            return Ok(Some((val, label.len() + consumed)));
        }
        s = &s[1..];
        base_offset = 1;
    }
    // Search on label[3] ('T' for MemTotal:, 'A' for MemAvailable:) using libc::memchr
    let anchor = label[3];
    while s.len() > label.len() {
        let search = &s[3..];
        let found = unsafe { memchr(search.as_ptr(), anchor.into(), search.len()) };
        if found.is_null() {
            return Ok(None);
        }
        let pos = unsafe { found.offset_from(search.as_ptr()) as usize };
        s = &s[pos..];
        base_offset += pos;
        if s.len() <= label.len() {
            return Ok(None);
        }
        if s.starts_with(label)
            && let Some((val, consumed)) = parse_ws_digits_u64(&s[label.len()..])?
        {
            return Ok(Some((val, base_offset + label.len() + consumed)));
        }
        s = &s[1..];
        base_offset += 1;
    }
    Ok(None)
}

/// Parses `MemTotal:\s+(\d+)` and `MemAvailable:\s+(\d+)` from `/proc/meminfo` (`SystemMetricsService::getRamUsage`),
/// computing `used`, `total`, and `percentage` rounded to 2 decimal places (`PHP_ROUND_HALF_UP`).
/// Returns `0` when either field is absent,
/// `1 | ((pct_hundredths as i64) << 1) | ((total_hundredths as i64) << 18) | ((used_hundredths as i64) << 40)` (`> 0`)
/// when both are present and within bit bounds, or `-1` on invalid arguments / `total_kb == 0` / negative used / overflow.
///
/// # Safety
/// `meminfo` must be readable for `meminfo_len` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_metrics_parse_meminfo(
    meminfo: *const c_char,
    meminfo_len: usize,
) -> i64 {
    if meminfo.is_null() && meminfo_len != 0 {
        return -1;
    }
    let bytes = if meminfo_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(meminfo.cast::<u8>(), meminfo_len) }
    };
    let (total, total_end) = match extract_labeled_u64(bytes, b"MemTotal:") {
        Ok(Some(v)) => v,
        Ok(None) => return 0,
        Err(()) => return -1,
    };
    // Fast path: in standard /proc/meminfo, MemAvailable follows shortly after MemTotal
    let available = match extract_labeled_u64(&bytes[total_end..], b"MemAvailable:") {
        Ok(Some((v, _))) => v,
        Ok(None) => match extract_labeled_u64(&bytes[..total_end], b"MemAvailable:") {
            Ok(Some((v, _))) => v,
            Ok(None) => return 0,
            Err(()) => return -1,
        },
        Err(()) => return -1,
    };
    if total == 0 || available > total {
        return -1;
    }
    let total_f = total as f64;
    let avail_f = available as f64;
    let used_f = total_f - avail_f;
    let used_gb = php_round_2(used_f / 1024.0 / 1024.0);
    let total_gb = php_round_2(total_f / 1024.0 / 1024.0);
    let pct = php_round_2((used_f / total_f) * 100.0);

    let used_h = (used_gb * 100.0).round() as u64;
    let total_h = (total_gb * 100.0).round() as u64;
    let pct_h = (pct * 100.0).round() as u64;
    if used_h > 0x3F_FFFF || total_h > 0x3F_FFFF || pct_h > 0x1_FFFF {
        return -1;
    }
    1 | ((pct_h as i64) << 1) | ((total_h as i64) << 18) | ((used_h as i64) << 40)
}

#[inline]
fn match_colon_digits(s: &[u8]) -> Option<(&[u8], usize)> {
    let mut i = 0usize;
    while i < s.len() && ws(s[i]) {
        i += 1;
    }
    if s.get(i) != Some(&b':') {
        return None;
    }
    i += 1;
    while i < s.len() && ws(s[i]) {
        i += 1;
    }
    let start = i;
    while i < s.len() && s[i].is_ascii_digit() {
        i += 1;
    }
    (i > start).then_some((&s[start..i], i))
}

#[inline]
fn match_colon_rest_of_line(s: &[u8]) -> Option<&[u8]> {
    let mut i = 0usize;
    while i < s.len() && ws(s[i]) {
        i += 1;
    }
    if s.get(i) != Some(&b':') {
        return None;
    }
    let val_slice = &s[i + 1..];
    let mut ws_end = 0usize;
    while ws_end < val_slice.len() && ws(val_slice[ws_end]) {
        ws_end += 1;
    }
    if ws_end < val_slice.len() {
        let line_end =
            ws_end + find_byte(&val_slice[ws_end..], b'\n').unwrap_or(val_slice.len() - ws_end);
        return Some(&val_slice[ws_end..line_end]);
    }
    let mut back = val_slice.len();
    while back > 0 {
        back -= 1;
        if val_slice[back] != b'\n'
            && (back + 1 == val_slice.len() || val_slice[back + 1] == b'\n')
        {
            return Some(&val_slice[back..back + 1]);
        }
    }
    None
}

#[allow(clippy::too_many_arguments)]
#[inline]
fn inspect_cpuinfo_line_at<'a>(
    bytes: &'a [u8],
    pos: usize,
    found_cores: &mut bool,
    cores: &mut u64,
    threads: &mut u64,
    found_model: &mut bool,
    model: &mut &'a [u8],
    phys_seen: &mut SeenCidrs<'a>,
    next_phys_min_pos: &mut usize,
) -> Result<(), ()> {
    let rest = &bytes[pos..];
    match rest.first() {
        Some(&b'p') => {
            if rest.starts_with(b"processor") {
                *threads += 1;
            } else if pos >= *next_phys_min_pos
                && rest.starts_with(b"physical id")
                && let Some((digits, consumed)) = match_colon_digits(&rest[11..])
            {
                phys_seen.insert(digits);
                *next_phys_min_pos = pos + 11 + consumed;
            }
        }
        Some(&b'c') => {
            if !*found_cores
                && rest.starts_with(b"cpu cores")
                && let Some((digits, _)) = match_colon_digits(&rest[9..])
            {
                *found_cores = true;
                let mut val = 0u64;
                for &b in digits {
                    val = val
                        .checked_mul(10)
                        .and_then(|v| v.checked_add(u64::from(b - b'0')))
                        .ok_or(())?;
                }
                *cores = val;
            }
        }
        Some(&b'm') => {
            if !*found_model
                && rest.starts_with(b"model name")
                && let Some(captured) = match_colon_rest_of_line(&rest[10..])
            {
                *found_model = true;
                if captured != b"0" {
                    *model = php_trim(captured);
                }
            }
        }
        _ => {}
    }
    Ok(())
}

/// Parses `/proc/cpuinfo` (`SystemMetricsService::getCpuInfo`) in a single SIMD-accelerated pass,
/// writing `"{cores}\0{threads}\0{model}"` into `out_buf`.
/// Returns bytes written (`> 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small,
/// or `-1` on invalid arguments / overflow / embedded NUL in model.
///
/// # Safety
/// `cpuinfo` must be readable for `cpuinfo_len` bytes and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_metrics_parse_cpu_info(
    cpuinfo: *const c_char,
    cpuinfo_len: usize,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (cpuinfo.is_null() && cpuinfo_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || cpuinfo_len > isize::MAX as usize
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    let bytes = if cpuinfo_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(cpuinfo.cast::<u8>(), cpuinfo_len) }
    };

    let mut found_cores = false;
    let mut cores = 0u64;
    let mut threads = 0u64;
    let mut found_model = false;
    let mut model: &[u8] = b"Unknown";
    let mut phys_seen = SeenCidrs::new();
    let mut next_phys_min_pos = 0usize;

    if !bytes.is_empty()
        && inspect_cpuinfo_line_at(
            bytes,
            0,
            &mut found_cores,
            &mut cores,
            &mut threads,
            &mut found_model,
            &mut model,
            &mut phys_seen,
            &mut next_phys_min_pos,
        )
        .is_err()
    {
        return -1;
    }

    let mut i = 0usize;
    while i + 22 <= bytes.len() {
        if found_cores && found_model && cores > 0 {
            threads += count_newline_processor(&bytes[i..]) as u64;
            i = bytes.len();
            break;
        }
        #[cfg(target_arch = "aarch64")]
        unsafe {
            use std::arch::aarch64::*;
            let ptr = bytes.as_ptr().add(i);
            let is_nl = vceqq_u8(vld1q_u8(ptr), vdupq_n_u8(b'\n'));
            let v1 = vld1q_u8(ptr.add(1));
            let v5 = vld1q_u8(ptr.add(5));
            let m = if found_model {
                vandq_u8(
                    is_nl,
                    vorrq_u8(vceqq_u8(v1, vdupq_n_u8(b'p')), vceqq_u8(v5, vdupq_n_u8(b'c'))),
                )
            } else {
                vandq_u8(
                    is_nl,
                    vorrq_u8(
                        vceqq_u8(v1, vdupq_n_u8(b'p')),
                        vorrq_u8(
                            vceqq_u8(v5, vdupq_n_u8(b'c')),
                            vceqq_u8(v1, vdupq_n_u8(b'm')),
                        ),
                    ),
                )
            };
            if vmaxvq_u8(m) != 0 {
                let u64s = vreinterpretq_u64_u8(m);
                let mut lo = vgetq_lane_u64::<0>(u64s);
                while lo != 0 {
                    let lane = (lo.trailing_zeros() >> 3) as usize;
                    if inspect_cpuinfo_line_at(
                        bytes,
                        i + lane + 1,
                        &mut found_cores,
                        &mut cores,
                        &mut threads,
                        &mut found_model,
                        &mut model,
                        &mut phys_seen,
                        &mut next_phys_min_pos,
                    )
                    .is_err()
                    {
                        return -1;
                    }
                    lo &= !(0xFFu64 << (lane * 8));
                }
                let mut hi = vgetq_lane_u64::<1>(u64s);
                while hi != 0 {
                    let lane = (hi.trailing_zeros() >> 3) as usize;
                    if inspect_cpuinfo_line_at(
                        bytes,
                        i + 8 + lane + 1,
                        &mut found_cores,
                        &mut cores,
                        &mut threads,
                        &mut found_model,
                        &mut model,
                        &mut phys_seen,
                        &mut next_phys_min_pos,
                    )
                    .is_err()
                    {
                        return -1;
                    }
                    hi &= !(0xFFu64 << (lane * 8));
                }
            }
            i += 16;
            continue;
        }
        #[cfg(target_arch = "x86_64")]
        unsafe {
            use std::arch::x86_64::*;
            let ptr = bytes.as_ptr().add(i);
            let is_nl = _mm_cmpeq_epi8(_mm_loadu_si128(ptr.cast()), _mm_set1_epi8(b'\n' as i8));
            let v1 = _mm_loadu_si128(ptr.add(1).cast());
            let v5 = _mm_loadu_si128(ptr.add(5).cast());
            let m = if found_model {
                _mm_and_si128(
                    is_nl,
                    _mm_or_si128(
                        _mm_cmpeq_epi8(v1, _mm_set1_epi8(b'p' as i8)),
                        _mm_cmpeq_epi8(v5, _mm_set1_epi8(b'c' as i8)),
                    ),
                )
            } else {
                _mm_and_si128(
                    is_nl,
                    _mm_or_si128(
                        _mm_cmpeq_epi8(v1, _mm_set1_epi8(b'p' as i8)),
                        _mm_or_si128(
                            _mm_cmpeq_epi8(v5, _mm_set1_epi8(b'c' as i8)),
                            _mm_cmpeq_epi8(v1, _mm_set1_epi8(b'm' as i8)),
                        ),
                    ),
                )
            };
            let mut mask = _mm_movemask_epi8(m) as u32;
            while mask != 0 {
                let lane = mask.trailing_zeros() as usize;
                if inspect_cpuinfo_line_at(
                    bytes,
                    i + lane + 1,
                    &mut found_cores,
                    &mut cores,
                    &mut threads,
                    &mut found_model,
                    &mut model,
                    &mut phys_seen,
                    &mut next_phys_min_pos,
                )
                .is_err()
                {
                    return -1;
                }
                mask &= mask - 1;
            }
            i += 16;
            continue;
        }
        #[allow(unreachable_code)]
        {
            for j in i..i + 16 {
                if bytes[j] == b'\n'
                    && matches!(bytes[j + 1], b'p' | b'c' | b'm')
                    && inspect_cpuinfo_line_at(
                        bytes,
                        j + 1,
                        &mut found_cores,
                        &mut cores,
                        &mut threads,
                        &mut found_model,
                        &mut model,
                        &mut phys_seen,
                        &mut next_phys_min_pos,
                    )
                    .is_err()
                {
                    return -1;
                }
            }
            i += 16;
        }
    }

    while i + 1 < bytes.len() {
        if bytes[i] == b'\n'
            && matches!(bytes[i + 1], b'p' | b'c' | b'm')
            && inspect_cpuinfo_line_at(
                bytes,
                i + 1,
                &mut found_cores,
                &mut cores,
                &mut threads,
                &mut found_model,
                &mut model,
                &mut phys_seen,
                &mut next_phys_min_pos,
            )
            .is_err()
        {
            return -1;
        }
        i += 1;
    }

    if cores == 0 {
        let unique_phys = phys_seen.count().max(1) as u64;
        cores = threads / unique_phys;
    }
    if find_byte(model, 0).is_some() {
        return -1;
    }

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    let mut num_buf = [0u8; 24];
    out.extend(write_u64_ascii(cores, &mut num_buf));
    out.push(0);
    out.extend(write_u64_ascii(threads, &mut num_buf));
    out.push(0);
    out.extend(model);
    out.finish()
}

#[inline]
fn extract_redis_db_keys(mut s: &[u8]) -> Option<i64> {
    if s.len() >= 6 && s.starts_with(b"keys=") && s[5].is_ascii_digit() {
        let mut end = 6usize;
        while end < s.len() && s[end].is_ascii_digit() {
            end += 1;
        }
        let mut val = 0i64;
        for &b in &s[5..end] {
            val = val.checked_mul(10)?.checked_add(i64::from(b - b'0'))?;
        }
        return Some(val);
    }
    while s.len() >= 6 {
        let Some(pos) = find_byte(s, b'k') else {
            return Some(0);
        };
        s = &s[pos..];
        if s.len() < 6 {
            return Some(0);
        }
        if s.starts_with(b"keys=") && s[5].is_ascii_digit() {
            let mut end = 6usize;
            while end < s.len() && s[end].is_ascii_digit() {
                end += 1;
            }
            let mut val = 0i64;
            for &b in &s[5..end] {
                val = val.checked_mul(10)?.checked_add(i64::from(b - b'0'))?;
            }
            return Some(val);
        }
        s = &s[1..];
    }
    Some(0)
}

/// Sums `keys=(\d+)` across `count` NUL-separated Redis keyspace `db0`..`db15` strings (`RedisMonitorService::parseRedisInfo`).
/// Returns total keys (`>= 0`), or `-1` on invalid arguments / segment count mismatch / integer overflow.
///
/// # Safety
/// `input` must be readable for `input_len` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_redis_sum_db_keys_nul(
    input: *const c_char,
    input_len: usize,
    count: usize,
) -> i64 {
    if input.is_null() && input_len != 0 {
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
    let mut seen = 0usize;
    let mut total = 0i64;
    for part in bytes.split(|&b| b == 0) {
        seen += 1;
        let Some(keys) = extract_redis_db_keys(part) else {
            return -1;
        };
        let Some(next) = total.checked_add(keys) else {
            return -1;
        };
        total = next;
    }
    if seen != count {
        return -1;
    }
    total
}

/// Computes `RedisMonitorService::parseRedisInfo` hit rate:
/// `$totalOps = $hits + $misses; $totalOps > 0 ? round(($hits * 100) / $totalOps, 2) : 0.0`.
/// Returns `-1.0` on integer overflow.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_redis_hit_rate(hits: i64, misses: i64) -> f64 {
    let Some(total_ops) = hits.checked_add(misses) else {
        return -1.0;
    };
    if total_ops <= 0 {
        return 0.0;
    }
    let Some(scaled) = hits.checked_mul(100) else {
        return -1.0;
    };
    php_round_2((scaled as f64) / (total_ops as f64))
}

/// Formats `RedisMonitorService::formatBytes(int $bytes): string`.
/// Returns bytes written (`> 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small,
/// or `-1` on invalid arguments / out-of-range input (`|bytes| >= 2^52`).
///
/// # Safety
/// `out_buf` must be writable for `out_cap` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_redis_format_bytes(
    bytes: i64,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (out_buf.is_null() && out_cap != 0)
        || out_cap > isize::MAX as usize
        || bytes <= -(1i64 << 52)
        || bytes >= (1i64 << 52)
    {
        return -1;
    }
    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    let mut num_buf = [0u8; 24];
    if bytes < 1024 {
        if bytes < 0 {
            out.push(b'-');
            out.extend(write_u64_ascii((-bytes) as u64, &mut num_buf));
        } else {
            out.extend(write_u64_ascii(bytes as u64, &mut num_buf));
        }
        out.extend(b" B");
        return out.finish();
    }
    let (divisor, unit): (f64, &[u8]) = if bytes >= 1_073_741_824 {
        (1_073_741_824.0, b" GB")
    } else if bytes >= 1_048_576 {
        (1_048_576.0, b" MB")
    } else {
        (1_024.0, b" KB")
    };
    let val = (bytes as f64) / divisor;
    let hundredths = (val * 100.0).round_ties_even() as u64;
    let whole = hundredths / 100;
    let rem = (hundredths % 100) as u8;
    out.extend(write_u64_ascii(whole, &mut num_buf));
    out.push(b'.');
    out.push(b'0' + rem / 10);
    out.push(b'0' + rem % 10);
    out.extend(unit);
    out.finish()
}

#[inline]
fn is_ascii_word(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

#[inline]
fn match_colon_space_digits_word_end(s: &[u8]) -> Option<&[u8]> {
    if !s.starts_with(b": ") {
        return None;
    }
    let rest = &s[2..];
    let mut end = 0usize;
    while end < rest.len() && rest[end].is_ascii_digit() {
        end += 1;
    }
    if end == 0 {
        return None;
    }
    if end < rest.len() && is_ascii_word(rest[end]) {
        return None;
    }
    Some(&rest[..end])
}

#[inline]
fn match_first_colon_space_digits_word_end(s: &[u8]) -> Option<&[u8]> {
    let colon = find_byte(s, b':')?;
    match_colon_space_digits_word_end(&s[colon..])
}

/// Extracts the 5 server status metrics (`Threads`, `Opens`, `Tables`, `Slow`, `QPS`) for
/// `TmuxOutput::_getQueries()` in a single pass, writing `"{threads}\0{opens}\0{tables}\0{slow}\0{qps}"`
/// into `out_buf`. Declines with `-1` if `info` contains NUL, `\n`, or non-ASCII bytes (`>= 0x80`).
/// Returns bytes written (`>= 4`), `-(needed as isize)` (`<= -4`) when `out_cap` is too small,
/// or `-1` on invalid arguments / declined input.
///
/// # Safety
/// `info` must be readable for `info_len` bytes and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_output_extract_server_info(
    info: *const c_char,
    info_len: usize,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (info.is_null() && info_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || info_len > isize::MAX as usize
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    let s = if info_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(info.cast::<u8>(), info_len) }
    };
    let mut threads: Option<&[u8]> = None;
    let mut opens: Option<&[u8]> = None;
    let mut tables: Option<&[u8]> = None;
    let mut slow: Option<&[u8]> = None;
    let mut qps: Option<&[u8]> = None;

    let mut prev_word = false;
    for i in 0..s.len() {
        let b = s[i];
        if b == 0 || b == b'\n' || b >= 0x80 {
            return -1;
        }
        let cur_word = is_ascii_word(b);
        if !prev_word && cur_word {
            match b {
                b'T' => {
                    if s[i..].starts_with(b"Threads")
                        && let Some(digits) = match_colon_space_digits_word_end(&s[i + 7..])
                    {
                        threads = Some(digits);
                    }
                }
                b'O' => {
                    if s[i..].starts_with(b"Opens")
                        && let Some(digits) = match_first_colon_space_digits_word_end(&s[i + 5..])
                    {
                        opens = Some(digits);
                    }
                }
                b't' => {
                    if i > 0
                        && s[i - 1] == b' '
                        && s[i..].starts_with(b"tables")
                        && let Some(digits) = match_colon_space_digits_word_end(&s[i + 6..])
                    {
                        tables = Some(digits);
                    }
                }
                b'S' => {
                    if s[i..].starts_with(b"Slow")
                        && let Some(digits) = match_first_colon_space_digits_word_end(&s[i + 4..])
                    {
                        slow = Some(digits);
                    }
                }
                b'Q' => {
                    if s[i..].starts_with(b"Queries")
                        && let Some(digits) = match_first_colon_space_digits_word_end(&s[i + 7..])
                    {
                        qps = Some(digits);
                    }
                }
                _ => {}
            }
        }
        prev_word = cur_word;
    }

    let fields = [
        threads.unwrap_or(s),
        opens.unwrap_or(s),
        tables.unwrap_or(s),
        slow.unwrap_or(s),
        qps.unwrap_or(s),
    ];
    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    for (idx, field) in fields.iter().enumerate() {
        if idx > 0 {
            out.push(0);
        }
        out.extend(field);
    }
    out.finish()
}

/// Computes `TmuxOutput::percent(int $part, int $total): int`:
/// `$total > 0 ? (int) floor(($part / $total) * 100) : 0`.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_tmux_output_percent(part: i64, total: i64) -> i64 {
    if total <= 0 {
        return 0;
    }
    (((part as f64) / (total as f64)) * 100.0).floor() as i64
}

#[inline]
fn write_i64_number_format(val: i64, out: &mut OutCursor) {
    let mut num_buf = [0u8; 24];
    let u = if val < 0 {
        out.push(b'-');
        val.unsigned_abs()
    } else {
        val as u64
    };
    let digits = write_u64_ascii(u, &mut num_buf);
    for (idx, &b) in digits.iter().enumerate() {
        if idx > 0 && (digits.len() - idx).is_multiple_of(3) {
            out.push(b',');
        }
        out.push(b);
    }
}

#[inline]
fn write_i64_plain(val: i64, out: &mut OutCursor) {
    let mut num_buf = [0u8; 24];
    let u = if val < 0 {
        out.push(b'-');
        val.unsigned_abs()
    } else {
        val as u64
    };
    out.extend(write_u64_ascii(u, &mut num_buf));
}

/// Formats `TmuxOutput::decodeCountRow` cells `"{number_format(in_process)}({diff})\0{number_format(in_database)}({percent}%)"`.
/// Returns bytes written (`>= 9`), `-(needed as isize)` (`<= -9`) when `out_cap` is too small,
/// or `-1` on invalid arguments / embedded NUL in `diff` / `|count| >= 2^52`.
///
/// # Safety
/// `diff` must be readable for `diff_len` bytes and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_output_decode_count_row(
    in_process: i64,
    diff: *const c_char,
    diff_len: usize,
    in_database: i64,
    percent: i64,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (diff.is_null() && diff_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || diff_len > isize::MAX as usize
        || out_cap > isize::MAX as usize
        || in_process <= -(1i64 << 52)
        || in_process >= (1i64 << 52)
        || in_database <= -(1i64 << 52)
        || in_database >= (1i64 << 52)
    {
        return -1;
    }
    let diff_bytes = if diff_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(diff.cast::<u8>(), diff_len) }
    };
    if find_byte(diff_bytes, 0).is_some() {
        return -1;
    }

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    write_i64_number_format(in_process, &mut out);
    out.push(b'(');
    out.extend(diff_bytes);
    out.push(b')');
    out.push(0);
    write_i64_number_format(in_database, &mut out);
    out.push(b'(');
    write_i64_plain(percent, &mut out);
    out.extend(b"%)");
    out.finish()
}

/// Returns newline-separated pane role names for `TmuxPaneManager::getPaneNames(int $sequential, bool $runIrcScraper)`.
/// Returns bytes written (`> 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small,
/// or `-1` on invalid arguments.
///
/// # Safety
/// `out_buf` must be writable for `out_cap` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_pane_get_names(
    sequential: i32,
    include_irc: u8,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (out_buf.is_null() && out_cap != 0) || out_cap > isize::MAX as usize || include_irc > 1 {
        return -1;
    }
    const FULL: [&[u8]; 10] = [
        b"monitor",
        b"binaries",
        b"backfill",
        b"releases",
        b"fix_names",
        b"remove_crap",
        b"post_additional",
        b"post_movies",
        b"post_tv",
        b"post_metadata",
    ];
    const BASIC: [&[u8]; 8] = [
        b"monitor",
        b"releases",
        b"fix_names",
        b"remove_crap",
        b"post_additional",
        b"post_movies",
        b"post_tv",
        b"post_metadata",
    ];
    const STRIPPED: [&[u8]; 4] = [b"monitor", b"sequential", b"fix_names", b"post_metadata"];

    let base: &[&[u8]] = match sequential {
        1 => &BASIC,
        2 => &STRIPPED,
        _ => &FULL,
    };

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    for (idx, name) in base.iter().enumerate() {
        if idx > 0 {
            out.push(b'\n');
        }
        out.extend(name);
    }
    if include_irc != 0 {
        out.push(b'\n');
        out.extend(b"irc_scraper");
    }
    out.finish()
}

/// Builds the NUL-separated `respawn-pane` argv list for `TmuxPaneManager::buildRespawnCommand`.
/// Returns bytes written (`> 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small,
/// or `-1` on invalid arguments / NUL segment count mismatch.
///
/// # Safety
/// All non-null input pointers must be readable for their stated lengths, and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_pane_build_respawn_argv_nul(
    base_args: *const c_char,
    base_args_len: usize,
    base_count: usize,
    kill_flag: u8,
    target: *const c_char,
    target_len: usize,
    directory: *const c_char,
    directory_len: usize,
    env_pairs: *const c_char,
    env_pairs_len: usize,
    env_pair_count: usize,
    worker_args: *const c_char,
    worker_args_len: usize,
    worker_count: usize,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (base_args.is_null() && base_args_len != 0)
        || (target.is_null() && target_len != 0)
        || (directory.is_null() && directory_len != 0)
        || (env_pairs.is_null() && env_pairs_len != 0)
        || (worker_args.is_null() && worker_args_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || out_cap > isize::MAX as usize
        || kill_flag > 1
        || base_count == 0
        || worker_count == 0
    {
        return -1;
    }
    let base_bytes = if base_args_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(base_args.cast::<u8>(), base_args_len) }
    };
    let target_bytes = if target_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(target.cast::<u8>(), target_len) }
    };
    let dir_bytes = if directory_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(directory.cast::<u8>(), directory_len) }
    };
    let env_bytes = if env_pairs_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(env_pairs.cast::<u8>(), env_pairs_len) }
    };
    let worker_bytes = if worker_args_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(worker_args.cast::<u8>(), worker_args_len) }
    };

    if find_byte(target_bytes, 0).is_some() || find_byte(dir_bytes, 0).is_some() {
        return -1;
    }
    if base_bytes.iter().filter(|&&b| b == 0).count().checked_add(1) != Some(base_count) {
        return -1;
    }
    if worker_bytes
        .iter()
        .filter(|&&b| b == 0)
        .count()
        .checked_add(1)
        != Some(worker_count)
    {
        return -1;
    }
    if env_pair_count == 0 {
        if !env_bytes.is_empty() {
            return -1;
        }
    } else {
        let Some(expected_env_items) = env_pair_count.checked_mul(2) else {
            return -1;
        };
        if env_bytes.iter().filter(|&&b| b == 0).count().checked_add(1)
            != Some(expected_env_items)
        {
            return -1;
        }
    }

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    out.extend(base_bytes);
    if kill_flag == 1 {
        out.extend(b"\0-k");
    }
    out.extend(b"\0-t\0");
    out.extend(target_bytes);
    out.extend(b"\0-c\0");
    out.extend(dir_bytes);

    if env_pair_count > 0 {
        let mut iter = env_bytes.split(|&b| b == 0);
        for _ in 0..env_pair_count {
            let Some(name) = iter.next() else {
                return -1;
            };
            let Some(val) = iter.next() else {
                return -1;
            };
            out.extend(b"\0-e\0");
            out.extend(name);
            out.push(b'=');
            out.extend(val);
        }
    }

    for arg in worker_bytes.split(|&b| b == 0) {
        out.push(0);
        if let Some(stem) = arg.strip_suffix(b";") {
            out.extend(stem);
            out.extend(b"\\;");
        } else {
            out.extend(arg);
        }
    }
    out.finish()
}

/// Formats `TmuxPaneManager::buildLogTeeSuffix(string $path, string $pane, string $date, bool $writeLogs)`.
/// Returns bytes written (`>= 0`, `0` when `write_logs == 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small,
/// or `-1` on invalid arguments / embedded NUL.
///
/// # Safety
/// All non-null input pointers must be readable for their stated lengths, and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_pane_build_log_tee_suffix(
    path: *const c_char,
    path_len: usize,
    pane: *const c_char,
    pane_len: usize,
    date: *const c_char,
    date_len: usize,
    write_logs: u8,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (path.is_null() && path_len != 0)
        || (pane.is_null() && pane_len != 0)
        || (date.is_null() && date_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || out_cap > isize::MAX as usize
        || write_logs > 1
    {
        return -1;
    }
    let path_bytes = if path_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(path.cast::<u8>(), path_len) }
    };
    let pane_bytes = if pane_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(pane.cast::<u8>(), pane_len) }
    };
    let date_bytes = if date_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(date.cast::<u8>(), date_len) }
    };
    if find_byte(path_bytes, 0).is_some()
        || find_byte(pane_bytes, 0).is_some()
        || find_byte(date_bytes, 0).is_some()
    {
        return -1;
    }
    if write_logs == 0 {
        return 0;
    }

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    out.extend(b"2>&1 | tee -a ");
    out.extend(path_bytes);
    out.push(b'/');
    out.extend(pane_bytes);
    out.push(b'-');
    out.extend(date_bytes);
    out.extend(b".log");
    out.finish()
}

#[inline]
fn write_posix_shell_arg(arg: &[u8], out: &mut OutCursor) {
    out.push(b'\'');
    for &b in arg {
        if b == b'\'' {
            out.extend(b"'\\''");
        } else {
            out.push(b);
        }
    }
    out.push(b'\'');
}

/// Formats `TmuxPaneManager::getSequentialCommand` (`nice -n{niceness} '{php_bin}' '{artisan}' {subcommand}[ {log_suffix}]`).
/// Declines with `-1` if any input contains NUL or non-ASCII bytes (`>= 0x80` in `php_bin` / `artisan` where `escapeshellarg` is locale-dependent).
/// Returns bytes written (`> 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small, or `-1` on invalid arguments.
///
/// # Safety
/// All non-null input pointers must be readable for their stated lengths, and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_pane_get_sequential_command(
    php_bin: *const c_char,
    php_bin_len: usize,
    artisan: *const c_char,
    artisan_len: usize,
    niceness: i32,
    subcommand: *const c_char,
    subcommand_len: usize,
    log_suffix: *const c_char,
    log_suffix_len: usize,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (php_bin.is_null() && php_bin_len != 0)
        || (artisan.is_null() && artisan_len != 0)
        || (subcommand.is_null() && subcommand_len != 0)
        || (log_suffix.is_null() && log_suffix_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    let php_bytes = if php_bin_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(php_bin.cast::<u8>(), php_bin_len) }
    };
    let artisan_bytes = if artisan_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(artisan.cast::<u8>(), artisan_len) }
    };
    let sub_bytes = if subcommand_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(subcommand.cast::<u8>(), subcommand_len) }
    };
    let log_bytes = if log_suffix_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(log_suffix.cast::<u8>(), log_suffix_len) }
    };

    if php_bytes.iter().any(|&b| b == 0 || b >= 0x80)
        || artisan_bytes.iter().any(|&b| b == 0 || b >= 0x80)
        || find_byte(sub_bytes, 0).is_some()
        || find_byte(log_bytes, 0).is_some()
    {
        return -1;
    }

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    out.extend(b"nice -n");
    write_i64_plain(i64::from(niceness), &mut out);
    out.push(b' ');
    write_posix_shell_arg(php_bytes, &mut out);
    out.push(b' ');
    write_posix_shell_arg(artisan_bytes, &mut out);
    out.push(b' ');
    out.extend(sub_bytes);
    if !log_bytes.is_empty() {
        out.push(b' ');
        out.extend(log_bytes);
    }
    out.finish()
}

/// Computes `TmuxMonitorService::incrementRunCount(int $iterations): int` (`iterations + 1`),
/// returning `-1` on negative input or signed 64-bit integer overflow.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_tmux_monitor_increment_run_count(iterations: i64) -> i64 {
    if iterations < 0 {
        return -1;
    }
    iterations.checked_add(1).unwrap_or(-1)
}

/// Formats `count` comma-grouped integer diffs (`number_format(now_vals[i] - start_vals[i])`) separated by NUL
/// for `TmuxMonitorService::updateDiffs`.
/// Returns bytes written (`>= 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small,
/// or `-1` on invalid arguments / integer overflow / `|diff| >= 2^52`.
///
/// # Safety
/// `now_vals` and `start_vals` must be readable for `count` `i64` elements when `count > 0`, and `out_buf` writable for `out_cap` bytes.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_monitor_update_diffs_nul(
    now_vals: *const i64,
    start_vals: *const i64,
    count: usize,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if ((now_vals.is_null() || start_vals.is_null()) && count != 0)
        || (out_buf.is_null() && out_cap != 0)
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    if count == 0 {
        return 0;
    }
    let now_slice = unsafe { slice::from_raw_parts(now_vals, count) };
    let start_slice = unsafe { slice::from_raw_parts(start_vals, count) };

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    for i in 0..count {
        let Some(diff) = now_slice[i].checked_sub(start_slice[i]) else {
            return -1;
        };
        if diff <= -(1i64 << 52) || diff >= (1i64 << 52) {
            return -1;
        }
        if i > 0 {
            out.push(0);
        }
        write_i64_number_format(diff, &mut out);
    }
    out.finish()
}

#[inline]
fn write_percent_02d(num: i64, den: i64, out: &mut OutCursor) {
    if den <= 0 {
        return;
    }
    let pct = (((num as f64) / (den as f64)) * 100.0).floor() as i64;
    if (0..10).contains(&pct) {
        out.push(b'0');
        out.push(b'0' + (pct as u8));
    } else {
        write_i64_plain(pct, out);
    }
}

/// Computes `TmuxMonitorService::calculateProcessingStats`:
/// writes `work + process_sum` to `*total_work_out` and writes 11 NUL-separated percentage tokens
/// (`tv\0movies\0audio\0books\0console\0pc\0xxx\0misc\0nfo\0predb_matched\0renamed`, where empty token means integer `0`)
/// into `out_buf`.
/// Returns bytes written (`>= 10`), `-(needed as isize)` (`<= -10`) when `out_cap` is too small,
/// or `-1` on invalid arguments / negative counts / integer overflow.
///
/// # Safety
/// `total_work_out` must be a valid writable pointer, and `out_buf` writable for `out_cap` bytes when non-null.
#[allow(clippy::too_many_arguments)]
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_monitor_calc_processing_stats(
    work: i64,
    process_sum: i64,
    tv: i64,
    movies: i64,
    audio: i64,
    books: i64,
    console: i64,
    pc: i64,
    xxx: i64,
    misc: i64,
    nfo: i64,
    processnfo: i64,
    predb_matched: i64,
    predb: i64,
    renamed: i64,
    processrenames: i64,
    total_work_out: *mut i64,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if total_work_out.is_null()
        || (out_buf.is_null() && out_cap != 0)
        || out_cap > isize::MAX as usize
    {
        return -1;
    }
    let all_inputs = [
        work,
        process_sum,
        tv,
        movies,
        audio,
        books,
        console,
        pc,
        xxx,
        misc,
        nfo,
        processnfo,
        predb_matched,
        predb,
        renamed,
        processrenames,
    ];
    if all_inputs.iter().any(|&v| !(0..(1i64 << 52)).contains(&v)) {
        return -1;
    }

    let Some(total_work) = work.checked_add(process_sum) else {
        return -1;
    };
    let categories = [tv, movies, audio, books, console, pc, xxx, misc];
    let mut total_cat = 0i64;
    for &c in &categories {
        let Some(next) = total_cat.checked_add(c) else {
            return -1;
        };
        total_cat = next;
    }
    let Some(nfo_total) = nfo.checked_add(processnfo) else {
        return -1;
    };
    let Some(rename_total) = renamed.checked_add(processrenames) else {
        return -1;
    };

    unsafe {
        *total_work_out = total_work;
    }

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    for (idx, &val) in categories.iter().enumerate() {
        if idx > 0 {
            out.push(0);
        }
        write_percent_02d(val, total_cat, &mut out);
    }
    out.push(0);
    write_percent_02d(nfo, nfo_total, &mut out);
    out.push(0);
    write_percent_02d(predb_matched, predb, &mut out);
    out.push(0);
    write_percent_02d(renamed, rename_total, &mut out);
    out.finish()
}

/// Evaluates `TmuxMonitorService::shouldRunPane` for a pane task name (`binaries`, `backfill`,
/// `releases`, `sequential`, `fixnames`, `removecrap`, `ppadditional`, `tv`/`nonamazon`, `movies`, `amazon`, `scraper`).
/// Returns `1` (true), `0` (false), or `-1` on invalid arguments.
///
/// # Safety
/// `pane`, `fix_crap_opt`, and `fix_crap` must be readable for their stated lengths when non-null.
#[allow(clippy::too_many_arguments)]
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_monitor_should_run_pane(
    pane: *const c_char,
    pane_len: usize,
    binaries_run: i64,
    backfill: i64,
    releases_run: i64,
    fix_names: i64,
    fix_crap_opt: *const c_char,
    fix_crap_opt_len: usize,
    fix_crap: *const c_char,
    fix_crap_len: usize,
    post: i64,
    post_non: i64,
    post_amazon: i64,
    processtvrage: i64,
    processanime: i64,
    processmovies: i64,
    processbooks: i64,
    processmusic: i64,
    processgames: i64,
    run_ircscraper: i64,
    work_available: i64,
    processnfo: i64,
    processrenames: i64,
    count_processtv: i64,
    count_processanime: i64,
    count_processmovies: i64,
    count_processbooks: i64,
    count_processmusic: i64,
    count_processconsole: i64,
    count_processgames: i64,
    kill_pp: u8,
    kill_coll: u8,
) -> i32 {
    if (pane.is_null() && pane_len != 0)
        || (fix_crap_opt.is_null() && fix_crap_opt_len != 0)
        || (fix_crap.is_null() && fix_crap_len != 0)
        || kill_pp > 1
        || kill_coll > 1
    {
        return -1;
    }
    let pane_bytes = if pane_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(pane.cast::<u8>(), pane_len) }
    };
    let opt_bytes = if fix_crap_opt_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(fix_crap_opt.cast::<u8>(), fix_crap_opt_len) }
    };
    let crap_bytes = if fix_crap_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(fix_crap.cast::<u8>(), fix_crap_len) }
    };

    let should_run = match pane_bytes {
        b"binaries" => binaries_run == 1 && kill_pp == 0,
        b"backfill" => (backfill == 1 || backfill == 4) && kill_coll == 0 && kill_pp == 0,
        b"releases" => releases_run != 0,
        b"sequential" => true,
        b"fixnames" => fix_names == 1 && processrenames > 0,
        b"removecrap" => {
            if opt_bytes == b"All" {
                true
            } else if opt_bytes == b"Custom" {
                crap_bytes
                    .split(|&b| b == b',')
                    .any(|part| {
                        let trimmed = php_trim(part);
                        !trimmed.is_empty() && trimmed != b"0"
                    })
            } else {
                false
            }
        }
        b"ppadditional" => {
            let has_work = work_available > 0;
            let has_nfo = processnfo > 0;
            match post {
                1 => has_work,
                2 => has_nfo,
                3 => has_work || has_nfo,
                _ => false,
            }
        }
        b"tv" | b"nonamazon" => {
            post_non > 0
                && ((processtvrage > 0 && count_processtv > 0)
                    || (processanime > 0 && count_processanime > 0))
        }
        b"movies" => post_non > 0 && processmovies > 0 && count_processmovies > 0,
        b"amazon" => {
            post_amazon > 0
                && ((processbooks > 0 && count_processbooks > 0)
                    || (processmusic > 0 && count_processmusic > 0)
                    || (processgames > 0
                        && (count_processconsole > 0 || count_processgames > 0)))
        }
        b"scraper" => run_ircscraper == 1,
        _ => false,
    };

    i32::from(should_run)
}

/// Builds NUL-separated `select-layout -t {session}:{window} tiled` (`cmd_kind == 0`) or
/// `kill-window -t {session}:{window}` (`cmd_kind == 1`) argv for `TmuxSessionManager`.
/// Returns bytes written (`> 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small,
/// or `-1` on invalid arguments / NUL segment count mismatch.
///
/// # Safety
/// All non-null input pointers must be readable for their stated lengths, and `out_buf` writable for `out_cap` bytes without overlap.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_session_build_window_cmd_nul(
    base_args: *const c_char,
    base_args_len: usize,
    base_count: usize,
    cmd_kind: u8,
    session_target: *const c_char,
    session_target_len: usize,
    window: *const c_char,
    window_len: usize,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (base_args.is_null() && base_args_len != 0)
        || (session_target.is_null() && session_target_len != 0)
        || (window.is_null() && window_len != 0)
        || (out_buf.is_null() && out_cap != 0)
        || out_cap > isize::MAX as usize
        || cmd_kind > 1
        || base_count == 0
        || base_args_len == 0
        || session_target_len == 0
        || window_len == 0
    {
        return -1;
    }
    let base_bytes = if base_args_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(base_args.cast::<u8>(), base_args_len) }
    };
    let session_bytes = if session_target_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(session_target.cast::<u8>(), session_target_len) }
    };
    let window_bytes = if window_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(window.cast::<u8>(), window_len) }
    };

    if find_byte(session_bytes, 0).is_some() || find_byte(window_bytes, 0).is_some() {
        return -1;
    }
    if base_bytes.iter().filter(|&&b| b == 0).count().checked_add(1) != Some(base_count) {
        return -1;
    }

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    out.extend(base_bytes);
    if cmd_kind == 0 {
        out.extend(b"\0select-layout\0-t\0");
        out.extend(session_bytes);
        out.push(b':');
        out.extend(window_bytes);
        out.extend(b"\0tiled");
    } else {
        out.extend(b"\0kill-window\0-t\0");
        out.extend(session_bytes);
        out.push(b':');
        out.extend(window_bytes);
    }
    out.finish()
}

/// Returns newline-separated split setup rows `"{window}\t{direction}\t{target_role}\t{percentage}\t{role}"`
/// for `TmuxSessionManager::getSplitSetup(int $sequentialMode)`.
/// Returns bytes written (`> 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small,
/// or `-1` on invalid arguments.
///
/// # Safety
/// `out_buf` must be writable for `out_cap` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_session_get_split_setup(
    sequential_mode: i32,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (out_buf.is_null() && out_cap != 0) || out_cap > isize::MAX as usize {
        return -1;
    }
    const FULL: &[u8] = b"0\th\tmonitor\t67\tbinaries\n\
0\tv\tbinaries\t67\tbackfill\n\
0\tv\tbackfill\t50\treleases\n\
1\th\tfix_names\t50\tremove_crap\n\
2\th\tpost_additional\t50\tpost_movies\n\
2\tv\tpost_additional\t67\tpost_tv\n\
2\tv\tpost_tv\t50\tpost_metadata";

    const BASIC: &[u8] = b"0\th\tmonitor\t67\treleases\n\
1\th\tfix_names\t50\tremove_crap\n\
2\th\tpost_additional\t50\tpost_movies\n\
2\tv\tpost_additional\t67\tpost_tv\n\
2\tv\tpost_tv\t50\tpost_metadata";

    const STRIPPED: &[u8] = b"0\th\tmonitor\t67\tsequential\n\
1\th\tfix_names\t50\tpost_metadata";

    let payload = match sequential_mode {
        1 => BASIC,
        2 => STRIPPED,
        _ => FULL,
    };

    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    out.extend(payload);
    out.finish()
}

/// Formats `RedisMonitor::formatDuration(int $seconds): string` (`"{d}d {h}h {m}m {s}s"` / `"{h}h {m}m {s}s"` / `"{m}m {s}s"` / `"{s}s"`).
/// Returns bytes written (`> 0`), `-(needed as isize)` (`<= -2`) when `out_cap` is too small,
/// or `-1` on invalid arguments.
///
/// # Safety
/// `out_buf` must be writable for `out_cap` bytes when non-null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_redis_format_duration(
    seconds: i64,
    out_buf: *mut c_char,
    out_cap: usize,
) -> isize {
    if (out_buf.is_null() && out_cap != 0) || out_cap > isize::MAX as usize {
        return -1;
    }
    let mut out = unsafe { OutCursor::new(out_buf.cast::<u8>(), out_cap) };
    let u = if seconds < 0 {
        out.push(b'-');
        seconds.unsigned_abs()
    } else {
        seconds as u64
    };
    let days = u / 86_400;
    let hours = (u % 86_400) / 3_600;
    let mins = (u % 3_600) / 60;
    let secs = u % 60;

    let mut num_buf = [0u8; 24];
    if days > 0 {
        out.extend(write_u64_ascii(days, &mut num_buf));
        out.extend(b"d ");
        out.extend(write_u64_ascii(hours, &mut num_buf));
        out.extend(b"h ");
        out.extend(write_u64_ascii(mins, &mut num_buf));
        out.extend(b"m ");
        out.extend(write_u64_ascii(secs, &mut num_buf));
        out.push(b's');
    } else if hours > 0 {
        out.extend(write_u64_ascii(hours, &mut num_buf));
        out.extend(b"h ");
        out.extend(write_u64_ascii(mins, &mut num_buf));
        out.extend(b"m ");
        out.extend(write_u64_ascii(secs, &mut num_buf));
        out.push(b's');
    } else if mins > 0 {
        out.extend(write_u64_ascii(mins, &mut num_buf));
        out.extend(b"m ");
        out.extend(write_u64_ascii(secs, &mut num_buf));
        out.push(b's');
    } else {
        out.extend(write_u64_ascii(secs, &mut num_buf));
        out.push(b's');
    }
    out.finish()
}

// A length-prefixed byte protocol keeps arbitrary command bytes, including NULs,
// separate without interpreting shell syntax or database policy.
fn runner_fields(mut input: &[u8]) -> Option<Vec<&[u8]>> {
    let mut fields = Vec::new();
    while !input.is_empty() {
        let size = u32::from_be_bytes(input.get(..4)?.try_into().ok()?) as usize;
        input = &input[4..];
        fields.push(input.get(..size)?);
        input = &input[size..];
    }
    Some(fields)
}

fn runner_join(parts: &[&[u8]]) -> Vec<u8> {
    let mut out = Vec::new();
    for part in parts {
        out.extend_from_slice(part);
    }
    out
}

fn runner_list(fields: &[Vec<u8>]) -> Option<Vec<u8>> {
    let mut out = Vec::new();
    for field in fields {
        out.extend_from_slice(&u32::try_from(field.len()).ok()?.to_be_bytes());
        out.extend_from_slice(field);
    }
    Some(out)
}

fn runner_output_tail(input: &[u8]) -> Option<Vec<u8>> {
    let mut stripped = Vec::with_capacity(input.len());
    let mut i = 0;
    while i < input.len() {
        if input[i..].starts_with(b"\x1b[") {
            let mut end = i + 2;
            while input
                .get(end)
                .is_some_and(|b| b.is_ascii_digit() || *b == b';')
            {
                end += 1;
            }
            if input.get(end) == Some(&b'm') {
                i = end + 1;
                continue;
            }
        }
        stripped.push(input[i]);
        i += 1;
    }
    let trimmed = std::str::from_utf8(php_trim(&stripped)).ok()?;
    let offset = trimmed.char_indices().rev().nth(399).map_or(0, |(i, _)| i);
    Some(trimmed.as_bytes()[offset..].to_vec())
}

const RUNNER_TRAPS: &[u8] = b"trap 'exit 130' INT; trap 'exit 143' TERM; trap 'exit 129' HUP; ";

fn runner_transform(mode: u32, f: &[&[u8]]) -> Option<Vec<u8>> {
    let result = match mode {
        0 if f.len() == 4 && matches!(f[3], b"0" | b"1") => {
            let mut out = runner_join(&[
                RUNNER_TRAPS,
                b"nntmux_status=0; ",
                f[1],
                b"( ",
                f[0],
                b" ); nntmux_status=$?; ",
                f[1],
            ]);
            if f[3] == b"1" {
                out.extend(runner_join(&[b"date +\"%Y-%m-%d %T\"; ", f[2], b"; "]));
            }
            out.extend_from_slice(b"exit \"$nntmux_status\"");
            out
        }
        1 if !f.is_empty() => {
            let mut out = runner_join(&[RUNNER_TRAPS, b"nntmux_batch_status=0; "]);
            for command in &f[1..] {
                out.extend(runner_join(&[f[0], b"( ", command, b" ); nntmux_child_status=$?; if [ \"$nntmux_batch_status\" -eq 0 ]; then nntmux_batch_status=$nntmux_child_status; fi; "]));
            }
            out.extend_from_slice(b"exit \"$nntmux_batch_status\"");
            out
        }
        2 if f.len() == 2 => runner_join(&[
            b"nntmux_draining=$(",
            f[0],
            b" 2>/dev/null); if [ \"$nntmux_draining\" = \"1\" ]; then exit \"$",
            f[1],
            b"\"; fi; ",
        ]),
        3 if f.len() == 7 && matches!(f[0], b"0" | b"1") => {
            if f[0] == b"1" {
                runner_join(&[
                    b"nice -n", f[1], b" ", f[2], b" ", f[3], b" ", f[4], b" ", f[5], b" ", f[6],
                ])
            } else {
                runner_join(&[b"sleep ", f[4]])
            }
        }
        4 if f.len() == 3 => runner_join(&[f[0], b"/", f[1], b"-", f[2], b".log"]),
        5 if f.len() == 8 && f[1..6].iter().all(|v| matches!(*v, b"0" | b"1")) => {
            let (rename, full, predbft, scheduled, full_config) = (
                f[1] == b"1",
                f[2] == b"1",
                f[3] == b"1",
                f[4] == b"1",
                f[5] == b"1",
            );
            let mut levels = Vec::new();
            if rename {
                levels.extend([3, 5, 9, 11, 13, 15, 17, 19]);
            }
            if full {
                levels.extend([4, 6, 10, 12, 14, 16, 18, 20]);
            }
            if !scheduled {
                if rename {
                    levels.push(7);
                }
                if full || (rename && full_config) {
                    levels.push(8);
                }
            }
            let mut commands = Vec::new();
            for level in levels {
                commands.push(runner_join(&[
                    f[0],
                    b" releases:fix-names ",
                    level.to_string().as_bytes(),
                    b" --update --category=",
                    f[6],
                    b" --set-status",
                    f[7],
                    b" --show",
                ]));
            }
            if predbft {
                commands.push(runner_join(&[
                    f[0],
                    b" multiprocessing:fixrelnames predbft",
                ]));
            }
            runner_list(&commands)?
        }
        6 if f.len() == 2 => {
            if f[0] == b"sqlite" {
                runner_join(&[b"substr(", f[1], b", 1, 1)"])
            } else {
                runner_join(&[b"LEFT(", f[1], b", 1)"])
            }
        }
        7 if f.len() == 2 => runner_join(&[
            b"\n            SELECT DISTINCT ",
            f[0],
            b" AS id\n            FROM releases\n            WHERE ",
            f[1],
            b"\n            LIMIT 16",
        ]),
        8 if f.len() == 2 && matches!(f[1], b"0" | b"1") => {
            let lookup = std::str::from_utf8(f[0]).ok()?.parse::<i64>().ok()?;
            if lookup <= 0 {
                b"-1".to_vec()
            } else if f[1] == b"1" {
                b"2".to_vec()
            } else {
                f[0].to_vec()
            }
        }
        9 if f.len() == 3 => {
            let args = php_trim(f[2]);
            let mut parts = Vec::new();
            let mut rest = args;
            while let Some(i) = rest.windows(2).position(|w| w == b"  ") {
                parts.push(&rest[..i]);
                rest = &rest[i + 2..];
            }
            parts.push(rest);
            let command = parts.remove(0);
            let part = |i: usize, default: &'static [u8]| -> Vec<u8> {
                parts.get(i).copied().unwrap_or(default).to_vec()
            };
            let mut worker: Vec<Vec<u8>> = match command {
                b"backfill" => vec![b"backfill:group".to_vec(), part(0, b""), part(1, b"1")],
                b"backfill_all_quantity" => vec![
                    b"backfill:group".to_vec(),
                    part(0, b""),
                    b"1".to_vec(),
                    part(1, b""),
                ],
                b"backfill_all_quick" => vec![
                    b"backfill:group".to_vec(),
                    part(0, b""),
                    b"1".to_vec(),
                    b"10000".to_vec(),
                ],
                b"get_range" => vec![
                    b"articles:get-range".to_vec(),
                    part(0, b""),
                    part(1, b""),
                    part(2, b"0"),
                    part(3, b"0"),
                ],
                b"part_repair" => vec![b"binaries:part-repair".to_vec(), part(0, b"")],
                b"update_group_headers" => vec![b"group:update-headers".to_vec(), part(0, b"")],
                b"update_per_group" => vec![b"group:update-all".to_vec(), part(0, b"")],
                b"releases" => {
                    let mut v = vec![b"releases:process".to_vec()];
                    v.extend(parts.iter().map(|p| p.to_vec()));
                    v
                }
                b"pp_additional" | b"pp_nfo" | b"pp_movie" | b"pp_tv" => {
                    let kind: &[u8] = match command {
                        b"pp_additional" => b"additional",
                        b"pp_nfo" => b"nfo",
                        b"pp_movie" => b"movie",
                        _ => b"tv",
                    };
                    let mut v = vec![b"postprocess:guid".to_vec(), kind.to_vec()];
                    v.extend(parts.iter().map(|p| p.to_vec()));
                    v
                }
                _ => return None,
            };
            worker.insert(0, f[1].to_vec());
            worker.insert(0, f[0].to_vec());
            runner_list(&worker)?
        }
        10 if f.len() == 3 && matches!(f[0], b"0" | b"1") => {
            if f[0] == b"1" {
                Vec::new()
            } else {
                let stderr = runner_output_tail(f[1])?;
                let stdout = runner_output_tail(f[2])?;
                let mut out = Vec::new();
                if !stderr.is_empty() {
                    out.extend_from_slice(b"stderr: ");
                    out.extend(stderr);
                }
                if !stdout.is_empty() {
                    out.extend_from_slice(b"\nstdout: ");
                    out.extend(stdout);
                }
                php_trim(&out).to_vec()
            }
        }
        11 if f.len() == 5 && matches!(f[1], b"0" | b"1") => {
            if f[1] == b"1" {
                if php_trim(f[2])
                    .split(|b| *b == b'\n')
                    .any(|line| line == f[0])
                {
                    b"1".to_vec()
                } else {
                    b"0".to_vec()
                }
            } else {
                let error = php_trim(f[3]);
                if f[4] == b"1"
                    && (error.starts_with(b"no server running on ")
                        || (error.starts_with(b"error connecting to ")
                            && error.ends_with(b"(No such file or directory)")))
                {
                    b"0".to_vec()
                } else {
                    b"2".to_vec()
                }
            }
        }
        12 if f.len() == 1 => {
            std::str::from_utf8(f[0]).ok()?.parse::<i64>().ok()?;
            f[0].to_vec()
        }
        13 if f.len() == 1 => Vec::new(),
        14 if f.is_empty() => b"15".to_vec(),
        _ => return None,
    };
    Some(result)
}

/// # Safety
/// Input must be readable for `input_len` bytes and output writable for `out_cap`,
/// with no overlap between those regions.
/// Returns -1 for invalid input, negative required capacity (at least -2) when
/// the output is too small, and the bytes written on success. No panic crosses FFI.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_tmux_runner_transform(
    mode: u32,
    input: *const c_char,
    input_len: usize,
    out: *mut c_char,
    out_cap: usize,
) -> isize {
    std::panic::catch_unwind(|| {
        if input_len > isize::MAX as usize
            || out_cap > isize::MAX as usize
            || (input.is_null() && input_len != 0)
            || (out.is_null() && out_cap != 0)
        {
            return -1;
        }
        let input = if input_len == 0 {
            &[]
        } else {
            unsafe { slice::from_raw_parts(input.cast::<u8>(), input_len) }
        };
        let Some(fields) = runner_fields(input) else {
            return -1;
        };
        let Some(result) = runner_transform(mode, &fields) else {
            return -1;
        };
        if result.len() > isize::MAX as usize {
            return -1;
        }
        if result.len() > out_cap {
            return -(result.len().max(2) as isize);
        }
        if !result.is_empty() {
            unsafe {
                std::ptr::copy_nonoverlapping(result.as_ptr(), out.cast::<u8>(), result.len())
            };
        }
        result.len() as isize
    })
    .unwrap_or(-1)
}

/// Returns the follow-up eligibility deadline without touching worker state.
#[unsafe(no_mangle)]
pub extern "C" fn nntmux_runner_follow_up_deadline(started_at: f64, interval: i64) -> f64 {
    std::panic::catch_unwind(|| started_at + interval as f64).unwrap_or(f64::NAN)
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

    #[test]
    fn tmux_socket_metrics_and_redis_parser_contracts() {
        let mut out = [0u8; 128];
        let line = b"ESTAB 0 0 10.0.0.2:41001 185.90.196.65:563";
        unsafe {
            let written = nntmux_tmux_extract_peer_endpoint(
                line.as_ptr().cast(),
                line.len(),
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert_eq!(written, 17);
            assert_eq!(&out[..17], b"185.90.196.65:563");
            assert_eq!(
                nntmux_tmux_socket_line_has_peer_port(
                    line.as_ptr().cast(),
                    line.len(),
                    b"563".as_ptr().cast(),
                    3
                ),
                1
            );
            let addrs = b"185.90.196.65\0185.90.196.97";
            assert_eq!(
                nntmux_tmux_socket_line_matches_any_address_nul(
                    line.as_ptr().cast(),
                    line.len(),
                    addrs.as_ptr().cast(),
                    addrs.len(),
                    2,
                    b"563".as_ptr().cast(),
                    3
                ),
                1
            );

            let snapshot = b"ESTAB 0 0 10.0.0.2:41001 185.90.196.65:563\nESTAB 0 0 10.0.0.2:41002 185.90.196.97:563\nTIME-WAIT 0 0 10.0.0.2:41004 85.12.62.251:563\nESTAB 0 0 10.0.0.2:41005 198.51.100.40:563\nCLOSE-WAIT 0 0 10.0.0.2:41007 198.51.100.41:563\n";
            let other_addrs = b"198.51.100.40\0198.51.100.41";
            let packed_primary = nntmux_tmux_count_usp_connections(
                0,
                b"563".as_ptr().cast(),
                3,
                b"563".as_ptr().cast(),
                3,
                addrs.as_ptr().cast(),
                addrs.len(),
                2,
                other_addrs.as_ptr().cast(),
                other_addrs.len(),
                2,
                snapshot.as_ptr().cast(),
                snapshot.len(),
            );
            assert_eq!(packed_primary >> 32, 3);
            assert_eq!(packed_primary & 0xFFFF_FFFF, 2);

            let packed_alt = nntmux_tmux_count_usp_connections(
                1,
                b"563".as_ptr().cast(),
                3,
                b"563".as_ptr().cast(),
                3,
                other_addrs.as_ptr().cast(),
                other_addrs.len(),
                2,
                addrs.as_ptr().cast(),
                addrs.len(),
                2,
                snapshot.as_ptr().cast(),
                snapshot.len(),
            );
            assert_eq!(packed_alt >> 32, 2);
            assert_eq!(packed_alt & 0xFFFF_FFFF, 1);

            let sz_len = nntmux_tmux_decode_size(1536.0, out.as_mut_ptr().cast(), out.len());
            assert_eq!(&out[..sz_len as usize], b"1.5 KB");

            let cpuinfo = b"processor\t: 0\nmodel name\t: AMD EPYC 7763\ncpu cores\t: 8\n\nprocessor\t: 1\n";
            assert_eq!(
                nntmux_metrics_parse_cpu_count(cpuinfo.as_ptr().cast(), cpuinfo.len()),
                2
            );
            let info_len = nntmux_metrics_parse_cpu_info(
                cpuinfo.as_ptr().cast(),
                cpuinfo.len(),
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert_eq!(&out[..info_len as usize], b"8\02\0AMD EPYC 7763");

            let meminfo = b"MemTotal:       32768000 kB\nMemFree:         4000000 kB\nMemAvailable:   16384000 kB\n";
            let packed_mem = nntmux_metrics_parse_meminfo(meminfo.as_ptr().cast(), meminfo.len());
            assert_eq!(packed_mem >> 40, 1563);
            assert_eq!((packed_mem >> 18) & 0x3F_FFFF, 3125);
            assert_eq!((packed_mem >> 1) & 0x1_FFFF, 5000);

            let redis_dbs = b"keys=125430,expires=10\0keys=8420,expires=0\0keys=150,expires=1";
            assert_eq!(
                nntmux_redis_sum_db_keys_nul(redis_dbs.as_ptr().cast(), redis_dbs.len(), 3),
                134_000
            );
            assert_eq!(nntmux_redis_hit_rate(40_000_000, 5_000_000), 88.89);

            let fb_len = nntmux_redis_format_bytes(1_572_864, out.as_mut_ptr().cast(), out.len());
            assert_eq!(&out[..fb_len as usize], b"1.50 MB");
        }
    }

    #[test]
    fn tmux_pane_monitor_and_redis_duration_contracts() {
        let mut out = [0u8; 512];
        unsafe {
            let srv = b"Uptime: 12345  Threads: 4  Questions: 987654  Slow queries: 2  Opens: 512  Flush tables: 1  Open tables: 256  Queries per second avg: 80.004";
            let srv_len = nntmux_tmux_output_extract_server_info(
                srv.as_ptr().cast(),
                srv.len(),
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert!(srv_len > 0);
            assert_eq!(&out[..srv_len as usize], b"4\0512\0256\02\080");

            assert_eq!(nntmux_tmux_output_percent(25, 100), 25);
            assert_eq!(nntmux_tmux_output_percent(50, 0), 0);

            let row_len = nntmux_tmux_output_decode_count_row(
                1234,
                b"+12".as_ptr().cast(),
                3,
                56789,
                42,
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert!(row_len > 0);
            assert_eq!(&out[..row_len as usize], b"1,234(+12)\056,789(42%)");

            let names_len =
                nntmux_tmux_pane_get_names(2, 1, out.as_mut_ptr().cast(), out.len());
            assert!(names_len > 0);
            assert_eq!(
                &out[..names_len as usize],
                b"monitor\nsequential\nfix_names\npost_metadata\nirc_scraper"
            );

            let base_args = b"tmux\0-u\0respawn-pane";
            let env_pairs = b"REDIS_HOST\0127.0.0.1";
            let worker_args = b"php\0artisan;";
            let argv_len = nntmux_tmux_pane_build_respawn_argv_nul(
                base_args.as_ptr().cast(),
                base_args.len(),
                3,
                1,
                b"%42".as_ptr().cast(),
                3,
                b"/app".as_ptr().cast(),
                4,
                env_pairs.as_ptr().cast(),
                env_pairs.len(),
                1,
                worker_args.as_ptr().cast(),
                worker_args.len(),
                2,
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert!(argv_len > 0);
            assert_eq!(
                &out[..argv_len as usize],
                b"tmux\0-u\0respawn-pane\0-k\0-t\0%42\0-c\0/app\0-e\0REDIS_HOST=127.0.0.1\0php\0artisan\\;"
            );

            let tee_len = nntmux_tmux_pane_build_log_tee_suffix(
                b"/var/log".as_ptr().cast(),
                8,
                b"monitor".as_ptr().cast(),
                7,
                b"2026_10_24".as_ptr().cast(),
                10,
                1,
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert_eq!(
                &out[..tee_len as usize],
                b"2>&1 | tee -a /var/log/monitor-2026_10_24.log"
            );

            let seq_len = nntmux_tmux_pane_get_sequential_command(
                b"/usr/bin/php".as_ptr().cast(),
                12,
                b"/app/artisan".as_ptr().cast(),
                12,
                2,
                b"multiprocessing:update-per-group".as_ptr().cast(),
                32,
                b"".as_ptr().cast(),
                0,
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert_eq!(
                &out[..seq_len as usize],
                b"nice -n2 '/usr/bin/php' '/app/artisan' multiprocessing:update-per-group"
            );

            assert_eq!(nntmux_tmux_monitor_increment_run_count(9), 10);

            let now_vals = [1000i64, 50, 0];
            let start_vals = [200i64, 75, 0];
            let diffs_len = nntmux_tmux_monitor_update_diffs_nul(
                now_vals.as_ptr(),
                start_vals.as_ptr(),
                3,
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert_eq!(&out[..diffs_len as usize], b"800\0-25\00");

            let mut total_work = 0i64;
            let stats_len = nntmux_tmux_monitor_calc_processing_stats(
                10,
                25,
                50,
                30,
                20,
                0,
                0,
                0,
                0,
                0,
                9,
                1,
                5,
                10,
                0,
                0,
                &mut total_work,
                out.as_mut_ptr().cast(),
                out.len(),
            );
            assert_eq!(total_work, 35);
            assert_eq!(
                &out[..stats_len as usize],
                b"50\030\020\000\000\000\000\000\090\050\0"
            );

            let dur_len = nntmux_redis_format_duration(90_061, out.as_mut_ptr().cast(), out.len());
            assert_eq!(&out[..dur_len as usize], b"1d 1h 1m 1s");

            assert_eq!(
                nntmux_tmux_session_build_window_cmd_nul(
                    std::ptr::null(),
                    0,
                    1,
                    0,
                    b"$1".as_ptr().cast(),
                    2,
                    b"2".as_ptr().cast(),
                    1,
                    out.as_mut_ptr().cast(),
                    out.len(),
                ),
                -1
            );
            assert_eq!(
                nntmux_tmux_pane_build_respawn_argv_nul(
                    b"tmux".as_ptr().cast(),
                    4,
                    1,
                    0,
                    b"%1".as_ptr().cast(),
                    2,
                    b"/app".as_ptr().cast(),
                    4,
                    b"A\0B".as_ptr().cast(),
                    3,
                    usize::MAX,
                    b"sh".as_ptr().cast(),
                    2,
                    1,
                    out.as_mut_ptr().cast(),
                    out.len(),
                ),
                -1
            );
        }
    }
}

#[cfg(test)]
mod runner_candidate_tests {
    use super::*;

    fn fields(values: &[&[u8]]) -> Vec<u8> {
        let mut packed = Vec::new();
        for value in values {
            packed.extend_from_slice(&(value.len() as u32).to_be_bytes());
            packed.extend_from_slice(value);
        }
        packed
    }

    fn transform(mode: u32, values: &[&[u8]]) -> Vec<u8> {
        let input = fields(values);
        let mut output = vec![0xa5; 16384];
        let result = unsafe {
            nntmux_tmux_runner_transform(
                mode,
                input.as_ptr().cast(),
                input.len(),
                output.as_mut_ptr().cast(),
                output.len(),
            )
        };
        assert!(result >= 0, "mode {mode} failed");
        assert!(output[result as usize..].iter().all(|b| *b == 0xa5));
        output.truncate(result as usize);
        output
    }

    #[test]
    fn tmux_runner_shell_transform_contracts() {
        assert_eq!(transform(0,&[b"a\0b",b"GUARD; ",b"sleep 7",b"1"]),runner_join(&[RUNNER_TRAPS,b"nntmux_status=0; GUARD; ( a\0b ); nntmux_status=$?; GUARD; date +\"%Y-%m-%d %T\"; sleep 7; exit \"$nntmux_status\""]));
        assert_eq!(
            transform(0, &[b"false", b"", b"ignored", b"0"]),
            runner_join(&[
                RUNNER_TRAPS,
                b"nntmux_status=0; ( false ); nntmux_status=$?; exit \"$nntmux_status\""
            ])
        );
        assert_eq!(transform(1,&[b"G",b"a",b"b"]),runner_join(&[RUNNER_TRAPS,b"nntmux_batch_status=0; G( a ); nntmux_child_status=$?; if [ \"$nntmux_batch_status\" -eq 0 ]; then nntmux_batch_status=$nntmux_child_status; fi; G( b ); nntmux_child_status=$?; if [ \"$nntmux_batch_status\" -eq 0 ]; then nntmux_batch_status=$nntmux_child_status; fi; exit \"$nntmux_batch_status\""]));
        assert_eq!(transform(2,&[b"'tmux' '-u'",b"status"]),b"nntmux_draining=$('tmux' '-u' 2>/dev/null); if [ \"$nntmux_draining\" = \"1\" ]; then exit \"$status\"; fi; ");
        assert_eq!(
            transform(
                3,
                &[
                    b"1",
                    b"-2",
                    b"'php'",
                    b"'script'",
                    b"10",
                    b"'session'",
                    b"'socket'"
                ]
            ),
            b"nice -n-2 'php' 'script' 10 'session' 'socket'"
        );
        assert_eq!(
            transform(3, &[b"0", b"", b"", b"", b"0", b"", b""]),
            b"sleep 0"
        );
        assert_eq!(
            transform(4, &[b"/tmp", b"monitor", b"2026_10_24"]),
            b"/tmp/monitor-2026_10_24.log"
        );
    }

    #[test]
    fn tmux_runner_fix_names_and_query_contracts() {
        for rename in [b"0".as_slice(), b"1"] {
            for full in [b"0".as_slice(), b"1"] {
                for scheduled in [b"0".as_slice(), b"1"] {
                    for full_config in [b"0".as_slice(), b"1"] {
                        let result = transform(
                            5,
                            &[
                                b"php artisan",
                                rename,
                                full,
                                b"1",
                                scheduled,
                                full_config,
                                b"'x'",
                                b" --limit=5",
                            ],
                        );
                        let parsed = runner_fields(&result).unwrap();
                        let mut levels = Vec::new();
                        if rename == b"1" {
                            levels.extend([3, 5, 9, 11, 13, 15, 17, 19]);
                        }
                        if full == b"1" {
                            levels.extend([4, 6, 10, 12, 14, 16, 18, 20]);
                        }
                        if scheduled == b"0" {
                            if rename == b"1" {
                                levels.push(7);
                            }
                            if full == b"1" || (rename == b"1" && full_config == b"1") {
                                levels.push(8);
                            }
                        }
                        assert_eq!(parsed.len(), levels.len() + 1);
                        for (command, level) in parsed.iter().zip(levels) {
                            assert_eq!(*command,format!("php artisan releases:fix-names {level} --update --category='x' --set-status --limit=5 --show").as_bytes());
                        }
                        assert_eq!(
                            *parsed.last().unwrap(),
                            b"php artisan multiprocessing:fixrelnames predbft"
                        );
                    }
                }
            }
        }
        assert_eq!(
            transform(6, &[b"sqlite", b"releases.guid"]),
            b"substr(releases.guid, 1, 1)"
        );
        assert_eq!(transform(6, &[b"mysql", b"guid"]), b"LEFT(guid, 1)");
        assert_eq!(transform(7,&[b"LEFT(guid, 1)",b"x = 1"]),b"\n            SELECT DISTINCT LEFT(guid, 1) AS id\n            FROM releases\n            WHERE x = 1\n            LIMIT 16");
        for (mode, renamed, expected) in [
            (b"-8".as_slice(), b"1".as_slice(), b"-1".as_slice()),
            (b"0", b"0", b"-1"),
            (b"1", b"1", b"2"),
            (b"3", b"0", b"3"),
        ] {
            assert_eq!(transform(8, &[mode, renamed]), expected);
        }
    }

    #[test]
    fn base_runner_command_tail_and_constant_contracts() {
        for (args, expected) in [
            (
                b"backfill  group".as_slice(),
                vec![b"backfill:group".as_slice(), b"group", b"1"],
            ),
            (
                b"backfill_all_quantity  g  25",
                vec![b"backfill:group", b"g", b"1", b"25"],
            ),
            (
                b"backfill_all_quick  g",
                vec![b"backfill:group", b"g", b"1", b"10000"],
            ),
            (
                b"get_range  g  10",
                vec![b"articles:get-range", b"g", b"10", b"0", b"0"],
            ),
            (b"part_repair", vec![b"binaries:part-repair", b""]),
            (
                b"releases  a\0b    last",
                vec![b"releases:process", b"a\0b", b"", b"last"],
            ),
            (
                b"update_group_headers  g",
                vec![b"group:update-headers", b"g"],
            ),
            (b"update_per_group  g", vec![b"group:update-all", b"g"]),
            (
                b"pp_additional  a",
                vec![b"postprocess:guid", b"additional", b"a"],
            ),
            (b"pp_nfo  b", vec![b"postprocess:guid", b"nfo", b"b"]),
            (b"pp_movie  c", vec![b"postprocess:guid", b"movie", b"c"]),
            (b"\0\tpp_tv  d \r\n", vec![b"postprocess:guid", b"tv", b"d"]),
        ] {
            let result = transform(9, &[b"php", b"artisan", args]);
            let mut wanted = vec![b"php".as_slice(), b"artisan"];
            wanted.extend(expected);
            assert_eq!(runner_fields(&result).unwrap(), wanted);
        }
        assert_eq!(
            transform(10, &[b"0", b"\x1b[1;31m failed \x1b[m \n", b" done \n"]),
            b"stderr: failed\nstdout: done"
        );
        let unicode = "é".repeat(401);
        assert_eq!(
            transform(10, &[b"0", b"", unicode.as_bytes()]),
            format!("stdout: {}", "é".repeat(400)).as_bytes()
        );
        assert_eq!(transform(10, &[b"1", b"ignore", b"ignore"]), b"");
        assert!(runner_transform(10, &[b"0", b"\xff", b""]).is_none());
        assert_eq!(transform(12, &[b"-7"]), b"-7");
        assert_eq!(transform(13, &[b"worker"]), b"");
        assert_eq!(transform(14, &[]), b"15");
        assert_eq!(nntmux_runner_follow_up_deadline(10.25, 60), 70.25);
        assert_eq!(nntmux_runner_follow_up_deadline(10.25, 0), 10.25);
    }

    #[test]
    fn stop_session_absence_contracts() {
        for (session, success, output, error, code, expected) in [
            (
                b"nntmux".as_slice(),
                b"1".as_slice(),
                b"other\nnntmux\n".as_slice(),
                b"".as_slice(),
                b"".as_slice(),
                b"1".as_slice(),
            ),
            (b"nntmux", b"1", b"other\n", b"", b"", b"0"),
            (b"", b"1", b"", b"", b"", b"1"),
            (
                b"nntmux",
                b"0",
                b"",
                b" no server running on /tmp/socket \n",
                b"1",
                b"0",
            ),
            (
                b"nntmux",
                b"0",
                b"",
                b"error connecting to /tmp/socket (No such file or directory)",
                b"1",
                b"0",
            ),
            (
                b"nntmux",
                b"0",
                b"",
                b"no server running on /tmp/socket",
                b"2",
                b"2",
            ),
            (b"nntmux", b"0", b"", b"permission denied", b"1", b"2"),
            (
                b"nntmux",
                b"0",
                b"",
                b"error connecting to socket (Permission denied)",
                b"1",
                b"2",
            ),
        ] {
            assert_eq!(
                transform(11, &[session, success, output, error, code]),
                expected
            );
        }
    }

    #[test]
    fn runner_transform_rejects_bad_lengths_and_reports_capacity() {
        let input = fields(&[b"sqlite", b"guid"]);
        let mut output = [0xa5; 64];
        unsafe {
            assert_eq!(
                nntmux_tmux_runner_transform(
                    6,
                    input.as_ptr().cast(),
                    input.len(),
                    output.as_mut_ptr().cast(),
                    1
                ),
                -18
            );
            assert_eq!(
                nntmux_tmux_runner_transform(
                    6,
                    std::ptr::null(),
                    1,
                    output.as_mut_ptr().cast(),
                    64
                ),
                -1
            );
            assert_eq!(
                nntmux_tmux_runner_transform(
                    6,
                    input.as_ptr().cast(),
                    usize::MAX,
                    output.as_mut_ptr().cast(),
                    64
                ),
                -1
            );
            assert_eq!(
                nntmux_tmux_runner_transform(
                    6,
                    input.as_ptr().cast(),
                    input.len(),
                    output.as_mut_ptr().cast(),
                    usize::MAX
                ),
                -1
            );
            assert_eq!(
                nntmux_tmux_runner_transform(
                    6,
                    input.as_ptr().cast(),
                    input.len(),
                    std::ptr::null_mut(),
                    1
                ),
                -1
            );
            assert_eq!(
                nntmux_tmux_runner_transform(
                    6,
                    input.as_ptr().cast(),
                    input.len() - 1,
                    output.as_mut_ptr().cast(),
                    64
                ),
                -1
            );
            assert_eq!(
                nntmux_tmux_runner_transform(
                    99,
                    input.as_ptr().cast(),
                    input.len(),
                    output.as_mut_ptr().cast(),
                    64
                ),
                -1
            );
            assert_eq!(
                nntmux_tmux_runner_transform(14, std::ptr::null(), 0, std::ptr::null_mut(), 0),
                -2
            );
            let one = fields(&[b"1", b"0"]);
            assert_eq!(
                nntmux_tmux_runner_transform(
                    8,
                    one.as_ptr().cast(),
                    one.len(),
                    std::ptr::null_mut(),
                    0
                ),
                -2
            );
            let empty = fields(&[b"any"]);
            assert_eq!(
                nntmux_tmux_runner_transform(
                    13,
                    empty.as_ptr().cast(),
                    empty.len(),
                    std::ptr::null_mut(),
                    0
                ),
                0
            );
        }
        assert!(output.iter().all(|b| *b == 0xa5));
    }
}
