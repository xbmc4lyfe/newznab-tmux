//! NEON prefixes with canonical-safe framing and no incomplete escape. Public
//! state remains in abi_decoder; compaction only receives validated subsets.
use std::arch::aarch64::*;

#[repr(align(16))]
struct DirectIndexes([[u8; 16]; 32768]);

const fn direct_indexes() -> DirectIndexes {
    let mut table = [[0x80; 16]; 32768];
    let mut mask = 0;
    while mask < 32768 {
        let mut source = 0;
        let mut position = 0;
        while source < 15 {
            if mask & (1 << source) == 0 {
                table[mask][position] = source as u8;
                position += 1;
            }
            source += 1;
        }
        // Lane 15 is always appended. If its mask bit is set, it lies outside
        // the returned count and only changes permitted capacity-contained tail.
        table[mask][position] = 15;
        mask += 1;
    }
    DirectIndexes(table)
}
static DIRECT_INDEXES: DirectIndexes = direct_indexes();

#[inline(always)]
unsafe fn pairwise_add_bytes(left: uint8x16_t, right: uint8x16_t) -> uint8x16_t {
    let result;
    unsafe {
        std::arch::asm!(
            "addp {result:v}.16b, {left:v}.16b, {right:v}.16b",
            left = in(vreg) left,
            right = in(vreg) right,
            result = lateout(vreg) result,
            options(pure, nomem, nostack, preserves_flags),
        );
    }
    result
}

#[inline(always)]
unsafe fn kept_counts(packed: uint8x8_t) -> uint8x8_t {
    unsafe {
        let removed = vcnt_u8(packed);
        vsub_u8(vdup_n_u8(16), vpadd_u8(removed, removed))
    }
}

/// Compact with one aligned direct-index load, ignoring only the final mask
/// bit for indexes while preserving the exact count from all sixteen bits.
/// Called only inside the NEON prefix after its entire group is validated and
/// guaranteed consumed. Counts come from popcounts of the two mask bytes.
#[inline(always)]
unsafe fn compact_mask(decoded: uint8x16_t, mask: u16, count: usize, dst: *mut u8) -> usize {
    unsafe {
        if mask == 0 {
            vst1q_u8(dst, decoded);
            return 16;
        }
        let indexes = vld1q_u8(
            DIRECT_INDEXES
                .0
                .get_unchecked((mask & 0x7fff) as usize)
                .as_ptr(),
        );
        // Full-width stores end no later than this group's consumed source and
        // its input-length destination capacity, even for backward overlap.
        vst1q_u8(dst, vqtbl1q_u8(decoded, indexes));
        count
    }
}

/// A framing byte is safe to compact only when it cannot be an escaped value,
/// leave a raw state pending, unstuff a dot, or (in END mode) end an article.
/// Inspect only the newline lanes; all group bytes are still unread by stores.
#[target_feature(enable = "neon")]
#[inline]
unsafe fn safe_lines<const END: bool>(
    lines: uint8x16_t,
    src: *const u8,
    base: usize,
    group: usize,
) -> bool {
    unsafe {
        // Each matching byte becomes one nonzero nibble in a scalar mask.
        let mut mask = vget_lane_u64::<0>(vreinterpret_u64_u8(vshrn_n_u16::<4>(
            vreinterpretq_u16_u8(lines),
        )));
        while mask != 0 {
            let lane = mask.trailing_zeros() as usize / 4;
            mask &= !(15u64 << (lane * 4));
            let position = base + lane;
            if position + 1 == group {
                return false;
            }
            if position != 0 {
                let previous = src.add(position - 1).read();
                if previous == b'='
                    || (previous == b'\r'
                        && src.add(position).read() == b'\n'
                        && (src.add(position + 1).read() == b'.'
                            || (END && src.add(position + 1).read() == b'=')))
                {
                    return false;
                }
            }
        }
        true
    }
}

/// Request the exact raw check when a loaded newline is followed by a dot.
#[inline(always)]
unsafe fn newline_dot_candidates(
    bytes: uint8x16_t,
    next: uint8x16_t,
    lines: uint8x16_t,
) -> uint8x16_t {
    unsafe {
        vandq_u8(
            lines,
            vceqq_u8(vextq_u8::<1>(bytes, next), vdupq_n_u8(b'.')),
        )
    }
}

/// Joint raw64 negative certificate for one block of loaded bytes.
#[inline(always)]
unsafe fn joint_admission(
    bytes: uint8x16_t,
    next: uint8x16_t,
    control: uint8x16_t,
    previous_equals: uint8x16_t,
) -> uint8x16_t {
    unsafe {
        let next_dot = vceqq_u8(vextq_u8::<1>(bytes, next), vdupq_n_u8(b'.'));
        vandq_u8(control, vorrq_u8(previous_equals, next_dot))
    }
}

/// Secondary test oracle: detect complete CR LF control triples in loaded vectors.
/// Zero register padding cannot match LF, dot or '=' and never reads a suffix.
#[cfg(test)]
#[inline(always)]
unsafe fn framing_hazards<const END: bool>(bytes: uint8x16_t, next: uint8x16_t) -> uint8x16_t {
    unsafe {
        let cr = vceqq_u8(bytes, vdupq_n_u8(b'\r'));
        let lf = vceqq_u8(vextq_u8::<1>(bytes, next), vdupq_n_u8(b'\n'));
        let following = vextq_u8::<2>(bytes, next);
        let dot = vceqq_u8(following, vdupq_n_u8(b'.'));
        let control = if END {
            vorrq_u8(dot, vceqq_u8(following, vdupq_n_u8(b'=')))
        } else {
            dot
        };
        vandq_u8(vandq_u8(cr, lf), control)
    }
}

/// The unchanged common escape-hazard gate rejects escaped CR/LF. A final
/// newline still needs scalar handling, even when later caller bytes exist.
#[cfg(test)]
#[inline(always)]
unsafe fn safe_lines16<const END: bool>(bytes: uint8x16_t, lines: uint8x16_t) -> bool {
    unsafe {
        if vmaxvq_u8(lines) == 0 {
            return true;
        }
        vgetq_lane_u8::<15>(lines) == 0
            && vmaxvq_u8(framing_hazards::<END>(bytes, vdupq_n_u8(0))) == 0
    }
}

/// Test-only v11 barrier: preserve three vectors while making framing depend on the
/// completed first-half hazard mask. The assembly comment emits no instruction;
/// inout operands describe the register dependency without changing any bytes.
#[cfg(test)]
#[inline(always)]
unsafe fn framing_schedule_barrier(
    mut first: uint8x16_t,
    mut c: uint8x16_t,
    mut d: uint8x16_t,
) -> (uint8x16_t, uint8x16_t, uint8x16_t) {
    unsafe {
        std::arch::asm!(
            "// {first:v} {c:v} {d:v}",
            first = inout(vreg) first,
            c = inout(vreg) c,
            d = inout(vreg) d,
            options(nomem, nostack, preserves_flags),
        );
    }
    (first, c, d)
}

#[cfg(test)]
#[inline(always)]
unsafe fn safe_lines64<const END: bool>(
    a: uint8x16_t,
    b: uint8x16_t,
    c: uint8x16_t,
    d: uint8x16_t,
    last_lines: uint8x16_t,
) -> bool {
    unsafe {
        if vgetq_lane_u8::<15>(last_lines) != 0 {
            return false;
        }
        let first = vorrq_u8(framing_hazards::<END>(a, b), framing_hazards::<END>(b, c));
        let (first, c, d) = framing_schedule_barrier(first, c, d);
        vmaxvq_u8(vorrq_u8(
            first,
            vorrq_u8(
                framing_hazards::<END>(c, d),
                framing_hazards::<END>(d, vdupq_n_u8(0)),
            ),
        )) == 0
    }
}

#[target_feature(enable = "neon")]
pub(super) unsafe fn prefix<const RAW: bool, const END: bool>(
    src: *const u8,
    dst: *mut u8,
    len: usize,
) -> (usize, usize) {
    // Classify once per line-sized prefix so dense streams do not pay for
    // ordinary-byte comparisons before every interleaved load.
    unsafe {
        let bytes = vld1q_u8(src);
        let even = vandq_u16(vreinterpretq_u16_u8(bytes), vdupq_n_u16(255));
        if vminvq_u16(vceqq_u16(even, vdupq_n_u16(u16::from(b'=')))) == u16::MAX {
            return dense_prefix(src, dst, len);
        }
    }
    let mut read = 0;
    let mut written = 0;
    unsafe {
        let offset = vdupq_n_u8(42);
        let marker = vdupq_n_u8(b'=');
        let newline_lookup =
            vld1q_u8([0u8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 0, 0, 255, 0, 0].as_ptr());
        while len - read >= 64 {
            let a = vld1q_u8(src.add(read));
            let b = vld1q_u8(src.add(read + 16));
            let c = vld1q_u8(src.add(read + 32));
            let d = vld1q_u8(src.add(read + 48));
            let eq_a = vceqq_u8(a, marker);
            let eq_b = vceqq_u8(b, marker);
            let eq_c = vceqq_u8(c, marker);
            let eq_d = vceqq_u8(d, marker);
            let control_a = vqtbx1q_u8(eq_a, newline_lookup, a);
            let control_b = vqtbx1q_u8(eq_b, newline_lookup, b);
            let control_c = vqtbx1q_u8(eq_c, newline_lookup, c);
            let control_d = vqtbx1q_u8(eq_d, newline_lookup, d);
            let specials = vorrq_u8(
                vorrq_u8(control_a, control_b),
                vorrq_u8(control_c, control_d),
            );
            if vmaxvq_u8(specials) == 0 {
                vst1q_u8(dst.add(written), vsubq_u8(a, offset));
                vst1q_u8(dst.add(written + 16), vsubq_u8(b, offset));
                vst1q_u8(dst.add(written + 32), vsubq_u8(c, offset));
                vst1q_u8(dst.add(written + 48), vsubq_u8(d, offset));
                read += 64;
                written += 64;
                continue;
            }
            // Raw non-END can reject all final controls together before
            // producing the negative certificate or performing exact checks.
            if RAW && !END {
                if vgetq_lane_u8::<15>(control_d) != 0 {
                    break;
                }
            } else if vgetq_lane_u8::<15>(d) == b'=' {
                break;
            }
            let prev_a = vextq_u8(vdupq_n_u8(0), eq_a, 15);
            let prev_b = vextq_u8(eq_a, eq_b, 15);
            let prev_c = vextq_u8(eq_b, eq_c, 15);
            let prev_d = vextq_u8(eq_c, eq_d, 15);
            let exact_admission = if RAW && !END {
                let joint = vorrq_u8(
                    vorrq_u8(
                        joint_admission(a, b, control_a, prev_a),
                        joint_admission(b, c, control_b, prev_b),
                    ),
                    vorrq_u8(
                        joint_admission(c, d, control_c, prev_c),
                        joint_admission(d, vdupq_n_u8(0), control_d, prev_d),
                    ),
                );
                vmaxvq_u8(joint) != 0
            } else {
                true
            };
            if exact_admission {
                // Dense runs use the classified dense prefix. Keep their extra
                // interleaved loads out of the sparse ordinary compaction loop.
                let line_a = vqtbl1q_u8(newline_lookup, a);
                let line_b = vqtbl1q_u8(newline_lookup, b);
                let line_c = vqtbl1q_u8(newline_lookup, c);
                let line_d = vqtbl1q_u8(newline_lookup, d);
                let lines = vorrq_u8(vorrq_u8(line_a, line_b), vorrq_u8(line_c, line_d));
                // Adjacent markers and escaped newline values need canonical
                // scalar handling. Reject the complete group before any stores.
                let hazards = vorrq_u8(
                    vorrq_u8(vandq_u8(control_a, prev_a), vandq_u8(control_b, prev_b)),
                    vorrq_u8(vandq_u8(control_c, prev_c), vandq_u8(control_d, prev_d)),
                );
                if vmaxvq_u8(hazards) != 0 {
                    break;
                }
                if RAW && !END && vmaxvq_u8(lines) != 0 {
                    // A final newline needs canonical handling even with zero
                    // padding. A complete CR LF dot always has a newline-dot pair;
                    // only positive candidates need the exact scalar check.
                    if vgetq_lane_u8::<15>(line_d) != 0 {
                        break;
                    }
                    let candidates = vorrq_u8(
                        vorrq_u8(
                            newline_dot_candidates(a, b, line_a),
                            newline_dot_candidates(b, c, line_b),
                        ),
                        vorrq_u8(
                            newline_dot_candidates(c, d, line_c),
                            newline_dot_candidates(d, vdupq_n_u8(0), line_d),
                        ),
                    );
                    if vmaxvq_u8(candidates) != 0
                        && (!safe_lines::<false>(line_a, src.add(read), 0, 64)
                            || !safe_lines::<false>(line_b, src.add(read), 16, 64)
                            || !safe_lines::<false>(line_c, src.add(read), 32, 64)
                            || !safe_lines::<false>(line_d, src.add(read), 48, 64))
                    {
                        break;
                    }
                }
                if RAW
                    && END
                    && vmaxvq_u8(lines) != 0
                    && (!safe_lines::<END>(line_a, src.add(read), 0, 64)
                        || !safe_lines::<END>(line_b, src.add(read), 16, 64)
                        || !safe_lines::<END>(line_c, src.add(read), 32, 64)
                        || !safe_lines::<END>(line_d, src.add(read), 48, 64))
                {
                    break;
                }
            }
            let weights =
                vld1q_u8([1u8, 2, 4, 8, 16, 32, 64, 128, 1, 2, 4, 8, 16, 32, 64, 128].as_ptr());
            // Pack all four removal masks together; lane popcounts supply both
            // half counts without per-block scalar compactor calls or spills.
            let halves = pairwise_add_bytes(
                pairwise_add_bytes(vandq_u8(control_a, weights), vandq_u8(control_b, weights)),
                pairwise_add_bytes(vandq_u8(control_c, weights), vandq_u8(control_d, weights)),
            );
            let packed = vget_low_u8(pairwise_add_bytes(halves, halves));
            let mask = vget_lane_u64::<0>(vreinterpret_u64_u8(packed));
            let counts = kept_counts(packed);
            let escape_offset = vdupq_n_u8(64);
            written += compact_mask(
                vsubq_u8(a, vaddq_u8(offset, vandq_u8(prev_a, escape_offset))),
                mask as u16,
                vget_lane_u8::<0>(counts) as usize,
                dst.add(written),
            );
            written += compact_mask(
                vsubq_u8(b, vaddq_u8(offset, vandq_u8(prev_b, escape_offset))),
                (mask >> 16) as u16,
                vget_lane_u8::<1>(counts) as usize,
                dst.add(written),
            );
            written += compact_mask(
                vsubq_u8(c, vaddq_u8(offset, vandq_u8(prev_c, escape_offset))),
                (mask >> 32) as u16,
                vget_lane_u8::<2>(counts) as usize,
                dst.add(written),
            );
            written += compact_mask(
                vsubq_u8(d, vaddq_u8(offset, vandq_u8(prev_d, escape_offset))),
                (mask >> 48) as u16,
                vget_lane_u8::<3>(counts) as usize,
                dst.add(written),
            );
            read += 64;
        }
        while len - read >= 16 {
            let bytes = vld1q_u8(src.add(read));
            let lines = vqtbl1q_u8(newline_lookup, bytes);
            let equals = vceqq_u8(bytes, marker);
            if vgetq_lane_u8::<15>(bytes) == b'=' {
                break;
            }
            let previous = vextq_u8(vdupq_n_u8(0), equals, 15);
            let special = vorrq_u8(equals, lines);
            if vmaxvq_u8(vandq_u8(special, previous)) != 0
                || (RAW && END && !safe_lines::<END>(lines, src.add(read), 0, 16))
            {
                break;
            }
            if RAW && !END && vmaxvq_u8(lines) != 0 {
                if vgetq_lane_u8::<15>(lines) != 0 {
                    break;
                }
                if vmaxvq_u8(newline_dot_candidates(bytes, vdupq_n_u8(0), lines)) != 0
                    && !safe_lines::<false>(lines, src.add(read), 0, 16)
                {
                    break;
                }
            }
            let weights =
                vld1q_u8([1u8, 2, 4, 8, 16, 32, 64, 128, 1, 2, 4, 8, 16, 32, 64, 128].as_ptr());
            let pairs = vpaddq_u8(vandq_u8(special, weights), vandq_u8(special, weights));
            let quads = vpaddq_u8(pairs, pairs);
            let packed = vget_low_u8(vpaddq_u8(quads, quads));
            let counts = kept_counts(packed);
            written += compact_mask(
                vsubq_u8(bytes, vaddq_u8(offset, vandq_u8(previous, vdupq_n_u8(64)))),
                vget_lane_u16::<0>(vreinterpret_u16_u8(packed)),
                vget_lane_u8::<0>(counts) as usize,
                dst.add(written),
            );
            read += 16;
        }
    }
    (read, written)
}

#[target_feature(enable = "neon")]
#[inline(never)]
unsafe fn dense_prefix(src: *const u8, dst: *mut u8, len: usize) -> (usize, usize) {
    let mut read = 0;
    let mut written = 0;
    unsafe {
        let marker = vdupq_n_u8(b'=');
        let offset = vdupq_n_u8(106);
        let lookup = vld1q_u8([0u8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 0, 0, 255, 0, 0].as_ptr());
        while len - read >= 64 {
            let first = vld2q_u8(src.add(read));
            let second = vld2q_u8(src.add(read + 32));
            let markers = vandq_u8(vceqq_u8(first.0, marker), vceqq_u8(second.0, marker));
            let invalid = vorrq_u8(
                vmvnq_u8(markers),
                vorrq_u8(vqtbl1q_u8(lookup, first.1), vqtbl1q_u8(lookup, second.1)),
            );
            if vmaxvq_u8(invalid) != 0 {
                break;
            }
            vst1q_u8(dst.add(written), vsubq_u8(first.1, offset));
            vst1q_u8(dst.add(written + 16), vsubq_u8(second.1, offset));
            read += 64;
            written += 32;
        }
        while len - read >= 32 {
            let pairs = vld2q_u8(src.add(read));
            let invalid = vorrq_u8(
                vmvnq_u8(vceqq_u8(pairs.0, marker)),
                vqtbl1q_u8(lookup, pairs.1),
            );
            if vmaxvq_u8(invalid) != 0 {
                break;
            }
            vst1q_u8(dst.add(written), vsubq_u8(pairs.1, offset));
            read += 32;
            written += 16;
        }
        // A rejected group returns control to the canonical state machine.
        // It consumes at least one byte before trying another vector prefix.
    }
    (read, written)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn tbx_controls_match_scalar_old_masks_and_unused_bic_identity_in_every_lane() {
        // Wrong TBX fallback, lookup index or lane must fail an independent
        // scalar classification, including inputs with distinct mixed lanes.
        for lane in 0..16 {
            for byte in 0..=u8::MAX {
                let mut input = std::array::from_fn::<_, 16, _>(|i| {
                    byte.wrapping_add((i as u8).wrapping_mul(17))
                });
                input[lane] = byte;
                let input = std::hint::black_box(input);
                let mut controls = [0; 16];
                let mut old = [0; 16];
                let mut bic = [0; 16];
                let mut lines = [0; 16];
                unsafe {
                    let bytes = vld1q_u8(input.as_ptr());
                    let eq = vceqq_u8(bytes, vdupq_n_u8(b'='));
                    let lookup =
                        vld1q_u8([0u8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 0, 0, 255, 0, 0].as_ptr());
                    let control = vqtbx1q_u8(eq, lookup, bytes);
                    let line = vqtbl1q_u8(lookup, bytes);
                    vst1q_u8(controls.as_mut_ptr(), control);
                    vst1q_u8(old.as_mut_ptr(), vorrq_u8(eq, line));
                    vst1q_u8(bic.as_mut_ptr(), vbicq_u8(control, eq));
                    vst1q_u8(lines.as_mut_ptr(), line);
                }
                let expected = input.map(|x| {
                    if matches!(x, b'=' | b'\r' | b'\n') {
                        255
                    } else {
                        0
                    }
                });
                assert_eq!(controls, expected, "lane={lane} byte={byte}");
                assert_eq!(controls, old, "old lane={lane} byte={byte}");
                assert_eq!(bic, lines, "unused BIC lane={lane} byte={byte}");

                // Exercise the real sparse64 consumer in each block. Nonraw
                // admission removes newlines and the escape marker; the byte
                // after a marker receives the independent escape subtraction.
                for block in 0..4 {
                    let position = block * 16 + lane;
                    if position == 63 && byte == b'=' {
                        continue;
                    }
                    let mut bytes = [b'k'; 64];
                    bytes[position] = byte;
                    let mut expected = Vec::new();
                    for (i, &x) in bytes.iter().enumerate() {
                        if matches!(x, b'=' | b'\r' | b'\n') {
                            continue;
                        }
                        let offset = if i == position + 1 && byte == b'=' {
                            106
                        } else {
                            42
                        };
                        expected.push(x.wrapping_sub(offset));
                    }
                    let mut actual = [0xcc; 64];
                    let (read, written) =
                        unsafe { prefix::<false, false>(bytes.as_ptr(), actual.as_mut_ptr(), 64) };
                    assert_eq!(read, 64, "position={position} byte={byte}");
                    assert_eq!(written, expected.len());
                    assert_eq!(
                        &actual[..written],
                        expected,
                        "position={position} byte={byte}"
                    );
                }
            }
        }
    }

    #[test]
    fn pairwise_helper_matches_wrapping_scalar_every_pair_position_and_source_order() {
        // Swapped sources, lane permutation, duplicate halves, saturation and
        // widening all fail the scalar wrapping sum of the actual helper.
        for position in 0..8 {
            for first in 0..=u8::MAX {
                for second in 0..=u8::MAX {
                    let mut left = std::array::from_fn::<_, 16, _>(|i| (i as u8).wrapping_mul(13));
                    let mut right = std::array::from_fn::<_, 16, _>(|i| {
                        255u8.wrapping_sub((i as u8).wrapping_mul(19))
                    });
                    left[position * 2] = first;
                    left[position * 2 + 1] = second;
                    right[position * 2] = first.wrapping_add(73);
                    right[position * 2 + 1] = second.wrapping_add(151);
                    for (a, b) in [(left, right), (right, left), (left, left), (right, right)] {
                        let (a, b) = std::hint::black_box((a, b));
                        let expected = std::array::from_fn::<_, 16, _>(|i| {
                            let source = if i < 8 { a } else { b };
                            let lane = (i % 8) * 2;
                            source[lane].wrapping_add(source[lane + 1])
                        });
                        let mut actual = [0; 16];
                        unsafe {
                            vst1q_u8(
                                actual.as_mut_ptr(),
                                pairwise_add_bytes(vld1q_u8(a.as_ptr()), vld1q_u8(b.as_ptr())),
                            );
                        }
                        assert_eq!(
                            actual, expected,
                            "position={position} first={first} second={second}"
                        );
                    }
                }
            }
        }
    }

    #[test]
    fn newline_dot_screen_requests_exact_fallback_for_false_candidates() {
        // Bare LF-dot and CR-dot must request exact checking, which accepts
        // them; treating the necessary condition as a rejection is incorrect.
        for width in [16, 64] {
            for position in 0..width - 1 {
                for newline in *b"\r\n" {
                    let mut bytes = vec![b'k'; width];
                    bytes[position] = newline;
                    bytes[position + 1] = b'.';
                    let mut candidates = 0;
                    unsafe {
                        for base in (0..width).step_by(16) {
                            let block = vld1q_u8(bytes.as_ptr().add(base));
                            let next = if base + 16 < width {
                                vld1q_u8(bytes.as_ptr().add(base + 16))
                            } else {
                                vdupq_n_u8(0)
                            };
                            let lines = vorrq_u8(
                                vceqq_u8(block, vdupq_n_u8(b'\r')),
                                vceqq_u8(block, vdupq_n_u8(b'\n')),
                            );
                            candidates |= vmaxvq_u8(newline_dot_candidates(block, next, lines));
                            assert!(safe_lines::<false>(lines, bytes.as_ptr(), base, width));
                        }
                        let mut output = [0xcc; 64];
                        assert_eq!(
                            prefix::<true, false>(bytes.as_ptr(), output.as_mut_ptr(), width).0,
                            width,
                        );
                    }
                    assert_ne!(
                        candidates, 0,
                        "width={width} position={position} newline={newline}"
                    );
                }
            }
        }
    }

    #[test]
    fn framing_barrier_preserves_distinct_vectors_and_every_lane() {
        // Any register swap, lane shuffle or byte change in the actual barrier
        // must fail these independent byte-for-byte expectations.
        let patterns = [
            [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            [
                255, 238, 221, 204, 187, 170, 153, 136, 119, 102, 85, 68, 51, 34, 17, 0,
            ],
            [
                13, 10, 46, 61, 128, 0, 255, 42, 64, 16, 32, 48, 80, 96, 112, 127,
            ],
        ];
        for rotation in 0..3 {
            for byte in 0..=u8::MAX {
                let mut expected = [
                    patterns[rotation],
                    patterns[(rotation + 1) % 3],
                    patterns[(rotation + 2) % 3],
                ];
                expected[0][usize::from(byte) % 16] = byte;
                expected[1][(usize::from(byte) + 5) % 16] = byte.wrapping_add(73);
                expected[2][(usize::from(byte) + 11) % 16] = byte.wrapping_add(151);
                let input = std::hint::black_box(expected);
                let mut actual = [[0; 16]; 3];
                unsafe {
                    let (first, c, d) = framing_schedule_barrier(
                        vld1q_u8(input[0].as_ptr()),
                        vld1q_u8(input[1].as_ptr()),
                        vld1q_u8(input[2].as_ptr()),
                    );
                    vst1q_u8(actual[0].as_mut_ptr(), first);
                    vst1q_u8(actual[1].as_mut_ptr(), c);
                    vst1q_u8(actual[2].as_mut_ptr(), d);
                }
                assert_eq!(actual, expected, "rotation={rotation} byte={byte}");
            }
        }
    }

    unsafe fn vector_raw_union<const END: bool>(bytes: &[u8]) -> bool {
        unsafe {
            let lookup = vld1q_u8([0u8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 0, 0, 255, 0, 0].as_ptr());
            let a = vld1q_u8(bytes.as_ptr());
            let raw_safe = if bytes.len() == 16 {
                safe_lines16::<END>(a, vqtbl1q_u8(lookup, a))
            } else {
                assert_eq!(bytes.len(), 64);
                let b = vld1q_u8(bytes.as_ptr().add(16));
                let c = vld1q_u8(bytes.as_ptr().add(32));
                let d = vld1q_u8(bytes.as_ptr().add(48));
                safe_lines64::<END>(a, b, c, d, vqtbl1q_u8(lookup, d))
            };
            // Full-group acceptance is observable through the real prefix:
            // a rejected 64-byte group may then admit individual 16-byte tails.
            let mut output = [0xcc; 64];
            let (read, _) = prefix::<true, END>(bytes.as_ptr(), output.as_mut_ptr(), bytes.len());
            raw_safe && read == bytes.len()
        }
    }

    fn literal_raw_union(bytes: &[u8], end: bool) -> bool {
        !matches!(bytes.last(), Some(b'=' | b'\r' | b'\n'))
            && !bytes
                .windows(2)
                .any(|pair| pair[0] == b'=' && matches!(pair[1], b'=' | b'\r' | b'\n'))
            && !bytes
                .windows(3)
                .any(|triple| triple == b"\r\n." || (end && triple == b"\r\n="))
    }

    fn assert_raw_union(bytes: &[u8]) {
        for end in [false, true] {
            let actual = unsafe {
                if end {
                    vector_raw_union::<true>(bytes)
                } else {
                    vector_raw_union::<false>(bytes)
                }
            };
            assert_eq!(
                actual,
                literal_raw_union(bytes, end),
                "width={} END={end} {bytes:?}",
                bytes.len()
            );
            // Test production admission independently of the vector helper:
            // END uses the original scalar guard; non-END screens before fallback.
            let mut output = [0xcc; 64];
            let (read, _) = unsafe {
                if end {
                    prefix::<true, true>(bytes.as_ptr(), output.as_mut_ptr(), bytes.len())
                } else {
                    prefix::<true, false>(bytes.as_ptr(), output.as_mut_ptr(), bytes.len())
                }
            };
            assert_eq!(
                read == bytes.len(),
                literal_raw_union(bytes, end),
                "production width={} END={end} {bytes:?}",
                bytes.len()
            );
        }
    }

    unsafe fn actual_joint_certificate(bytes: &[u8; 64]) -> ([u8; 16], bool) {
        unsafe {
            let lookup = vld1q_u8([0u8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 0, 0, 255, 0, 0].as_ptr());
            let marker = vdupq_n_u8(b'=');
            let zero = vdupq_n_u8(0);
            let a = vld1q_u8(bytes.as_ptr());
            let b = vld1q_u8(bytes.as_ptr().add(16));
            let c = vld1q_u8(bytes.as_ptr().add(32));
            let d = vld1q_u8(bytes.as_ptr().add(48));
            let ea = vceqq_u8(a, marker);
            let eb = vceqq_u8(b, marker);
            let ec = vceqq_u8(c, marker);
            let ed = vceqq_u8(d, marker);
            let ca = vqtbx1q_u8(ea, lookup, a);
            let cb = vqtbx1q_u8(eb, lookup, b);
            let cc = vqtbx1q_u8(ec, lookup, c);
            let cd = vqtbx1q_u8(ed, lookup, d);
            let certificate = vorrq_u8(
                vorrq_u8(
                    joint_admission(a, b, ca, vextq_u8(zero, ea, 15)),
                    joint_admission(b, c, cb, vextq_u8(ea, eb, 15)),
                ),
                vorrq_u8(
                    joint_admission(c, d, cc, vextq_u8(eb, ec, 15)),
                    joint_admission(d, zero, cd, vextq_u8(ec, ed, 15)),
                ),
            );
            let mut lanes = [0; 16];
            vst1q_u8(lanes.as_mut_ptr(), certificate);
            (lanes, vgetq_lane_u8::<15>(cd) == 0)
        }
    }

    #[test]
    fn joint_certificate_matches_literal_all_byte_pairs_at_every_adjacent_position() {
        let background: [u8; 64] = std::array::from_fn(|lane| 128 + lane as u8);
        for position in 0..63 {
            for left in 0..=255u8 {
                for right in 0..=255u8 {
                    let mut bytes = background;
                    bytes[position] = left;
                    bytes[position + 1] = right;
                    let (actual, final_safe) = unsafe { actual_joint_certificate(&bytes) };
                    // Literal pair rules place their signal at the special byte.
                    let mut expected = [0u8; 16];
                    if left == b'=' && matches!(right, b'=' | b'\r' | b'\n') {
                        expected[(position + 1) % 16] = 255;
                    }
                    if matches!(left, b'=' | b'\r' | b'\n') && right == b'.' {
                        expected[position % 16] = 255;
                    }
                    assert_eq!(actual, expected, "position={position} pair={left},{right}");
                    if actual.iter().all(|&lane| lane == 0) && final_safe {
                        assert!(
                            literal_raw_union(&bytes, false),
                            "negative position={position} pair={left},{right}"
                        );
                    }
                }
            }
        }
        for byte in 0..=255u8 {
            let mut bytes = background;
            bytes[63] = byte;
            let (_, final_safe) = unsafe { actual_joint_certificate(&bytes) };
            assert_eq!(
                final_safe,
                !matches!(byte, b'=' | b'\r' | b'\n'),
                "last={byte}"
            );
            assert_raw_union(&bytes);
        }
    }

    #[test]
    fn joint_positive_valid_pairs_are_consumed_with_literal_scalar_output() {
        for position in 0..63 {
            for pair in [b"=.", b"\n.", b"\r."] {
                let mut bytes: [u8; 64] = std::array::from_fn(|lane| 128 + lane as u8);
                bytes[position..position + 2].copy_from_slice(pair);
                assert!(literal_raw_union(&bytes, false));
                assert!(
                    unsafe { actual_joint_certificate(&bytes) }
                        .0
                        .iter()
                        .any(|&lane| lane != 0)
                );
                let mut expected = Vec::new();
                let mut escaped = false;
                for byte in bytes {
                    if escaped {
                        expected.push(byte.wrapping_sub(106));
                        escaped = false;
                    } else if byte == b'=' {
                        escaped = true;
                    } else if !matches!(byte, b'\r' | b'\n') {
                        expected.push(byte.wrapping_sub(42));
                    }
                }
                let mut actual = [0xcc; 64];
                let (read, written) =
                    unsafe { prefix::<true, false>(bytes.as_ptr(), actual.as_mut_ptr(), 64) };
                assert_eq!(read, 64, "position={position} pair={pair:?}");
                assert_eq!(
                    &actual[..written],
                    &expected,
                    "position={position} pair={pair:?}"
                );
            }
        }
        for position in 0..63 {
            for fragment in [b"\r\n.".as_slice(), b"==", b"=\r", b"=\n", b"=\r\n."] {
                if position + fragment.len() <= 64 {
                    let mut bytes = [b'k'; 64];
                    bytes[position..position + fragment.len()].copy_from_slice(fragment);
                    assert_raw_union(&bytes);
                }
            }
        }
    }

    #[test]
    fn raw_union_matches_literal_every_neighborhood_crossing_and_fragment() {
        let alphabet = [b'k', b'=', b'\r', b'\n', b'.', 0, 255];
        for width in [16, 64] {
            for position in 0..width {
                for a in alphabet {
                    for b in alphabet {
                        for c in alphabet {
                            let mut bytes = vec![b'k'; width];
                            for (offset, value) in [a, b, c].into_iter().enumerate() {
                                if position + offset < width {
                                    bytes[position + offset] = value;
                                }
                            }
                            assert_raw_union(&bytes);
                        }
                    }
                }
            }
        }
    }

    #[test]
    fn raw_union_simultaneous_common_framing_hazards_and_no_line_groups() {
        for width in [16, 64] {
            for position in 0..width - 2 {
                for escaped in *b"=\r\n" {
                    for control in *b".=" {
                        let mut bytes = vec![b'k'; width];
                        bytes[..3].copy_from_slice(&[b'\r', b'\n', control]);
                        bytes[position] = b'=';
                        bytes[position + 1] = escaped;
                        assert_raw_union(&bytes);
                        bytes.fill(b'k');
                        bytes[position..position + 3].copy_from_slice(&[b'\r', b'\n', control]);
                        bytes[width - 2] = b'=';
                        bytes[width - 1] = escaped;
                        assert_raw_union(&bytes);
                    }
                }
            }
            for position in 0..width {
                for byte in *b"k=." {
                    let mut bytes = vec![b'k'; width];
                    bytes[position] = byte;
                    assert_raw_union(&bytes);
                }
            }
        }
    }

    #[test]
    fn raw_union_ignores_outside_predecessors_and_following_suffixes() {
        for width in [16, 64] {
            for previous in *b"=\r\nk" {
                for suffix in [b"\n.=".as_slice(), b"\n=k", b"kkk"] {
                    for final_byte in *b"k\r\n" {
                        let mut storage = vec![b'k'; width + 4];
                        storage[0] = previous;
                        storage[1] = b'\n';
                        storage[width] = final_byte;
                        storage[width + 1..].copy_from_slice(suffix);
                        assert_raw_union(&storage[1..width + 1]);
                    }
                }
            }
        }
    }

    #[test]
    fn raw_common_preceding_equals_rejects_before_stores_and_preserves_unread() {
        for width in [16, 64] {
            for position in 0..width - 1 {
                for special in *b"=\r\n" {
                    let mut input = vec![b'k'; width];
                    input[position] = b'=';
                    input[position + 1] = special;
                    for end in [false, true] {
                        for gap in [0, 1, 15, 64] {
                            let source = 16 + gap;
                            let mut storage = vec![0xcc; source + width + 16];
                            storage[source..source + width].copy_from_slice(&input);
                            let before = storage.clone();
                            let (read, written) = unsafe {
                                if end {
                                    prefix::<true, true>(
                                        storage.as_ptr().add(source),
                                        storage.as_mut_ptr().add(16),
                                        width,
                                    )
                                } else {
                                    prefix::<true, false>(
                                        storage.as_ptr().add(source),
                                        storage.as_mut_ptr().add(16),
                                        width,
                                    )
                                }
                            };
                            assert!(read <= position, "width={width} position={position}");
                            assert_eq!(read, written);
                            assert!(storage[16..16 + written].iter().all(|&byte| byte == b'A'));
                            assert_eq!(&storage[16 + width..], &before[16 + width..]);
                            assert_eq!(&storage[source + read..source + width], &input[read..]);
                            assert!(storage[..16].iter().all(|&byte| byte == 0xcc));
                            assert!(storage[source + width..].iter().all(|&byte| byte == 0xcc));
                        }
                    }
                }
            }
        }
    }

    #[test]
    fn direct_table_rows_match_independent_lower_fifteen_survivors() {
        assert_eq!(DIRECT_INDEXES.0.as_ptr() as usize % 16, 0);
        for (mask, indexes) in DIRECT_INDEXES.0.iter().enumerate() {
            let expected: Vec<u8> = (0..15)
                .filter(|lane| mask & (1 << lane) == 0)
                .map(|lane| lane as u8)
                .chain(std::iter::once(15))
                .collect();
            assert_eq!(
                &indexes[..expected.len()],
                expected.as_slice(),
                "mask={mask}"
            );
            assert!(indexes[expected.len()..].iter().all(|&index| index == 0x80));
        }
    }

    #[test]
    fn all_four_packed_counts_include_the_top_bit_without_cross_lane_carry() {
        for varying_lane in 0..4 {
            for mask in 0..=u16::MAX {
                let mut masks = [0u16, u16::MAX, 0x8000, 0x7fff];
                masks[varying_lane] = mask;
                let mut bytes = [0u8; 8];
                for (lane, mask) in masks.iter().enumerate() {
                    bytes[lane * 2..lane * 2 + 2].copy_from_slice(&mask.to_le_bytes());
                }
                let mut actual = [0u8; 8];
                unsafe { vst1_u8(actual.as_mut_ptr(), kept_counts(vld1_u8(bytes.as_ptr()))) };
                for lane in 0..4 {
                    let expected = 16 - masks[lane].count_ones() as u8;
                    assert_eq!(
                        actual[lane], expected,
                        "varying_lane={varying_lane} mask={mask}"
                    );
                    assert_eq!(actual[lane + 4], expected);
                }
            }
        }
    }

    #[test]
    fn every_mask_in_each_group_block_preserves_packing_and_output_order() {
        for varying_lane in 0..4 {
            for mask in 0..=u16::MAX {
                let mut masks = [0u16, u16::MAX, 0x8000, 0x7fff];
                masks[varying_lane] = mask;
                let input: [u8; 64] = std::array::from_fn(|position| {
                    if masks[position / 16] & (1 << (position % 16)) != 0 {
                        b'\r'
                    } else {
                        106 + position as u8
                    }
                });
                let expected: Vec<u8> = input
                    .iter()
                    .copied()
                    .filter(|&byte| byte != b'\r')
                    .map(|byte| byte - 42)
                    .collect();
                let mut output = [0xcc; 96];
                let actual = unsafe {
                    prefix::<false, false>(input.as_ptr(), output.as_mut_ptr().add(16), 64)
                };
                assert_eq!(
                    actual,
                    (64, expected.len()),
                    "lane={varying_lane} mask={mask}"
                );
                assert_eq!(&output[16..16 + actual.1], expected.as_slice());
                assert!(output[..16].iter().all(|&byte| byte == 0xcc));
                assert!(output[80..].iter().all(|&byte| byte == 0xcc));
            }
        }
    }

    #[test]
    fn zero_survivor_groups_preserve_capacity_and_the_unread_suffix() {
        let mut input = vec![b'k'; 129];
        for (position, byte) in input[..64].iter_mut().enumerate() {
            *byte = if position % 2 == 0 { b'\r' } else { b'\n' };
        }
        input[128] = b'=';
        for gap in [0, 1, 15, 64] {
            let source = 16 + gap;
            let mut storage = vec![0xcc; source + input.len() + 16];
            storage[source..source + input.len()].copy_from_slice(&input);
            let before = storage.clone();
            let (read, written) = unsafe {
                prefix::<false, false>(
                    storage.as_ptr().add(source),
                    storage.as_mut_ptr().add(16),
                    input.len(),
                )
            };
            assert_eq!((read, written), (128, 64));
            assert!(storage[16..80].iter().all(|&byte| byte == b'A'));
            assert_eq!(&storage[16 + input.len()..], &before[16 + input.len()..]);
            assert_eq!(&storage[source + read..source + input.len()], b"=");
            assert!(storage[..16].iter().all(|&byte| byte == 0xcc));
            assert!(
                storage[source + input.len()..]
                    .iter()
                    .all(|&byte| byte == 0xcc)
            );
        }
    }

    #[test]
    fn every_compaction_mask_matches_scalar_indexes_and_capacity_guards() {
        let bytes: [u8; 16] = std::array::from_fn(|index| index as u8);
        for mask in 0..=u16::MAX {
            let expected: Vec<u8> = bytes
                .iter()
                .copied()
                .enumerate()
                .filter_map(|(index, byte)| (mask & (1 << index) == 0).then_some(byte))
                .collect();
            let mut output = [0xcc; 48];
            let count = unsafe {
                compact_mask(
                    vld1q_u8(bytes.as_ptr()),
                    mask,
                    expected.len(),
                    output.as_mut_ptr().add(16),
                )
            };
            assert_eq!(count, expected.len(), "mask={mask}");
            assert_eq!(&output[16..16 + count], expected.as_slice(), "mask={mask}");
            assert!(output[..16].iter().all(|&byte| byte == 0xcc));
            assert!(output[32..].iter().all(|&byte| byte == 0xcc));
        }
    }
}
