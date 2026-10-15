//! Canonical RapidYenc decoder ABI. Public-call quirks are kept outside SIMD tails.
//! State semantics follow the pinned CC0/Public Domain RapidYenc decoder:
//! https://github.com/animetosho/rapidyenc/blob/480bd7b5896f8b3edecc721d23f1384d767ffe2f/src/decoder.cc

#[cfg(test)]
mod tests {
    use super::*;

    fn ex(raw: i32, input: &[u8], initial: i32) -> (usize, i32, Vec<u8>) {
        let mut output = vec![0xcc; input.len() + 8];
        let mut state = initial;
        let written = unsafe {
            decode_ex(
                raw,
                input.as_ptr().cast(),
                output.as_mut_ptr().cast(),
                input.len(),
                &mut state,
            )
        };
        assert!(output[input.len()..].iter().all(|&b| b == 0xcc));
        (written, state, output)
    }

    #[test]
    fn raw_dot_unstuffing_and_nonraw_escape_state() {
        let (n, s, o) = ex(1, b"..klm", 0);
        assert_eq!((n, s), (4, 3));
        assert_eq!(&o[..n], &[4, 65, 66, 67]);
        let (n, s, o) = ex(0, b"\rklm", 1);
        assert_eq!((n, s), (4, 3));
        assert_eq!(&o[..n], &[0xa3, 65, 66, 67]);
        let (n, s, o) = ex(0, b"=\r\n", 3);
        assert_eq!((n, s), (1, 3));
        assert_eq!(o[0], 0xa3);
    }

    #[test]
    fn preserves_two_public_zero_return_writes() {
        for (input, state) in [(b"\r".as_slice(), 2), (b"\r\n".as_slice(), 0)] {
            let (n, s, o) = ex(1, input, 1);
            assert_eq!((n, s, o[0]), (0, state, 0xa3));
        }
    }

    #[test]
    fn canonical_corrections_are_independent_of_alignment() {
        for alignment in 0..64 {
            for raw in [0, 1] {
                let mut storage = vec![b'k'; alignment + 200];
                storage[alignment] = b'y';
                let (n, s, o) = ex(raw, &storage[alignment..], 6);
                assert_eq!((n, s, o[0]), (200, 3, 0x4f));
            }
            let mut storage = vec![b'k'; alignment + 200];
            storage[alignment] = b'\r';
            let (n, s, o) = ex(1, &storage[alignment..], 1);
            assert_eq!((n, s, o[0]), (200, 3, 0xa3));
            let mut storage = vec![b'k'; alignment + 191];
            storage.extend_from_slice(b"=\r\n");
            let (n, s, o) = ex(1, &storage[alignment..], 0);
            assert_eq!((n, s, o[191]), (192, 0, 0xa3));
        }
    }

    #[test]
    fn incremental_backward_overlap_continues_without_copy() {
        let mut data = *b"=Jklm!!!!";
        let base = data.as_mut_ptr();
        let mut src = base.cast_const().cast();
        let mut dst = base.cast();
        let mut state = 0;
        unsafe {
            let before = data;
            assert_eq!(decode_incremental(&mut src, &mut dst, 2, &mut state), 0);
            assert_eq!(&data[2..], &before[2..]);
            assert_eq!(
                (
                    src as usize - base as usize,
                    dst as usize - base as usize,
                    state
                ),
                (2, 1, 3)
            );
            let before = data;
            assert_eq!(decode_incremental(&mut src, &mut dst, 3, &mut state), 0);
            assert_eq!(&data[4..], &before[4..]);
            assert_eq!(
                (
                    src as usize - base as usize,
                    dst as usize - base as usize,
                    state
                ),
                (5, 4, 3)
            );
        }
        assert_eq!(&data[..4], &[0xe0, 65, 66, 67]);
        assert_eq!(&data[5..], b"!!!!");
    }

    #[test]
    fn incremental_ends_across_every_split_and_keeps_escaped_cr_output() {
        for (input, expected, end, consumed, state) in [
            (b"klm\r\n=yend".as_slice(), &[65, 66, 67][..], 1, 7, 3),
            (b"klm\r\n.\r\nrest".as_slice(), &[65, 66, 67][..], 2, 8, 0),
            (b"klm\r\n.=yend".as_slice(), &[65, 66, 67][..], 1, 8, 3),
            (
                b"=\r\n..klm".as_slice(),
                &[0xa3, 4, 65, 66, 67][..],
                0,
                8,
                3,
            ),
        ] {
            for split in 0..=consumed {
                let mut output = vec![0xccu8; input.len() + 8];
                let mut src = input.as_ptr().cast();
                let mut dst = output.as_mut_ptr().cast();
                let mut s = 0;
                let mut actual = unsafe { decode_incremental(&mut src, &mut dst, split, &mut s) };
                if actual == 0 {
                    actual = unsafe {
                        decode_incremental(&mut src, &mut dst, input.len() - split, &mut s)
                    };
                }
                assert_eq!(
                    (actual, src as usize - input.as_ptr() as usize, s),
                    (end, consumed, state),
                    "input {input:?} split {split}"
                );
                let written = dst as usize - output.as_ptr() as usize;
                assert_eq!(
                    &output[..written],
                    expected,
                    "input {input:?} split {split}"
                );
                assert!(output[written..].iter().all(|&b| b == 0xcc));
            }
        }
        for input in [b"\r".as_slice(), b"\r\n".as_slice()] {
            let mut out = [0xccu8; 8];
            let mut src = input.as_ptr().cast();
            let mut dst = out.as_mut_ptr().cast();
            let mut state = 1;
            unsafe {
                assert_eq!(
                    decode_incremental(&mut src, &mut dst, input.len(), &mut state),
                    0
                );
            }
            assert_eq!(dst as usize - out.as_ptr() as usize, 1);
            assert_eq!(out[0], 0xa3);
        }
    }

    #[test]
    fn rejects_invalid_arguments_without_mutations() {
        let mut data = *b"klm!!!!!!!!!";
        let original = data;
        let base = data.as_mut_ptr();
        let mut state = 0;
        unsafe {
            assert_eq!(
                decode_ex(1, base.cast(), base.add(1).cast(), 3, &mut state),
                usize::MAX
            );
            assert_eq!(
                decode_ex(1, base.cast(), base.cast(), usize::MAX, &mut state),
                usize::MAX
            );
            state = 7;
            assert_eq!(
                decode_ex(1, base.cast(), base.cast(), 3, &mut state),
                usize::MAX
            );
            assert_eq!(
                decode_ex(1, std::ptr::null(), std::ptr::null_mut(), 0, &mut state),
                0
            );
            assert_eq!(state, 7);
            state = 0;
            assert_eq!(
                decode_ex(1, base.cast(), base.cast(), 3, base.cast()),
                usize::MAX
            );
            let mut src = base.cast_const().cast();
            let mut dst = base.add(1).cast();
            assert_eq!(decode_incremental(&mut src, &mut dst, 3, &mut state), 0);
            assert_eq!(
                (src as usize, dst as usize, state),
                (base as usize, base as usize + 1, 0)
            );
            let storage = (&mut src as *mut *const c_void).cast::<*mut c_void>();
            assert_eq!(decode_incremental(&mut src, storage, 3, &mut state), 0);
        }
        assert_eq!(data, original);
    }

    #[test]
    fn backward_overlap_snapshot_detects_writes_beyond_destination_capacity() {
        let destination_start = 16;
        let gap = 15;
        let length = 64;
        let source_start = destination_start + gap;
        let capacity_end = destination_start + length;
        let source_end = source_start + length;
        let mut storage = vec![0xcc; source_end + 16];
        storage[source_start..source_end].fill(b'k');
        let before = storage.clone();

        // Incidental writes inside input-length capacity remain permitted.
        let mut within_capacity = before.clone();
        within_capacity[capacity_end - 1] ^= 1;
        assert_eq!(&within_capacity[capacity_end..], &before[capacity_end..]);

        // Safely mutate allocated storage, without an out-of-bounds decoder
        // call: the old source-end canary misses this destination overflow.
        assert!(capacity_end < source_end);
        storage[capacity_end] ^= 1;
        assert_eq!(&storage[..destination_start], &before[..destination_start]);
        assert_eq!(&storage[source_end..], &before[source_end..]);
        assert_ne!(&storage[capacity_end..], &before[capacity_end..]);
    }

    #[test]
    fn every_state_and_special_vector_position_matches_scalar_with_overlap_guards() {
        // The scalar path is independently checked against frozen C++ receipts;
        // this matrix catches compaction, tail and state changes in SIMD kernels.
        for length in [
            15, 16, 17, 31, 32, 33, 63, 64, 65, 127, 128, 129, 199, 200, 201,
        ] {
            for position in 0..length {
                for special in [b'=', b'\r', b'\n', b'.', 0, 255] {
                    let mut input = vec![b'k'; length];
                    input[position] = special;
                    for initial in 0..7 {
                        for raw in [false, true] {
                            let mut reference = vec![0xcc; length + 16];
                            let mut expected_state = initial;
                            let expected = unsafe {
                                decode_noend(
                                    raw,
                                    input.as_ptr(),
                                    reference.as_mut_ptr(),
                                    length,
                                    &mut expected_state,
                                    false,
                                )
                            };
                            for gap in [0, 1, 15, 64] {
                                let mut storage = vec![0xcc; length + gap + 32];
                                storage[16 + gap..16 + gap + length].copy_from_slice(&input);
                                let before = storage.clone();
                                let mut state = initial;
                                let actual = unsafe {
                                    decode_noend(
                                        raw,
                                        storage.as_ptr().add(16 + gap),
                                        storage.as_mut_ptr().add(16),
                                        length,
                                        &mut state,
                                        true,
                                    )
                                };
                                assert_eq!(
                                    (actual, state),
                                    (expected, expected_state),
                                    "length={length} position={position} special={special} initial={initial} raw={raw} gap={gap}"
                                );
                                // Preserve the specified zero-return writes separately.
                                let meaningful =
                                    if expected == 0 && raw && initial == EQ && input[0] == b'\r' {
                                        1
                                    } else {
                                        expected
                                    };
                                assert_eq!(&storage[16..16 + meaningful], &reference[..meaningful]);
                                assert_eq!(&storage[16 + length..], &before[16 + length..]);
                                assert!(storage[..16].iter().all(|&b| b == 0xcc));
                                assert!(storage[16 + gap + length..].iter().all(|&b| b == 0xcc));
                            }
                        }
                        let mut reference = vec![0xcc; length + 16];
                        let mut expected_state = initial;
                        let expected = unsafe {
                            decode_end(
                                input.as_ptr(),
                                reference.as_mut_ptr(),
                                length,
                                &mut expected_state,
                                false,
                            )
                        };
                        let mut output = vec![0xcc; length + 16];
                        let mut state = initial;
                        let actual = unsafe {
                            decode_end(
                                input.as_ptr(),
                                output.as_mut_ptr(),
                                length,
                                &mut state,
                                true,
                            )
                        };
                        assert_eq!((actual, state), (expected, expected_state));
                        assert_eq!(&output[..actual.1], &reference[..expected.1]);
                        // The approved ABI permits incidental SIMD tail writes
                        // within input-length capacity; guards remain mandatory.
                        assert!(output[length..].iter().all(|&byte| byte == 0xcc));
                    }
                }
            }
        }
    }

    #[cfg(target_arch = "aarch64")]
    #[test]
    fn neon_prefix_compacts_safe_line_breaks_without_scalar_reentry() {
        for position in [1, 14, 15, 16, 30, 31, 32, 46, 47, 48, 60] {
            let mut input = [b'k'; 128];
            input[position] = b'\r';
            input[position + 1] = b'\n';
            input[70] = b'=';
            input[71] = b'J';
            let mut expected = [0xcc; 144];
            let mut state = NONE;
            let n = unsafe {
                decode_noend(
                    true,
                    input.as_ptr(),
                    expected.as_mut_ptr(),
                    128,
                    &mut state,
                    false,
                )
            };
            for gap in [0, 1, 15, 64] {
                let mut storage = vec![0xcc; 160 + gap];
                storage[16 + gap..144 + gap].copy_from_slice(&input);
                let before = storage.clone();
                let (read, written) = unsafe {
                    arm::prefix::<true, true>(
                        storage.as_ptr().add(16 + gap),
                        storage.as_mut_ptr().add(16),
                        128,
                    )
                };
                assert_eq!((read, written), (128, n), "position={position} gap={gap}");
                assert_eq!(&storage[16..16 + n], &expected[..n]);
                assert_eq!(&storage[144..], &before[144..]);
                assert!(storage[..16].iter().all(|&byte| byte == 0xcc));
                assert!(storage[144 + gap..].iter().all(|&byte| byte == 0xcc));
            }
        }
    }

    #[test]
    fn incremental_simd_compaction_preserves_end_markers_and_unconsumed_source() {
        for length in [15, 16, 17, 31, 32, 33, 63, 64, 65, 127, 128, 129, 255] {
            for ending in [
                b"\r\n=yend-rest".as_slice(),
                b"\r\n.\r\nrest",
                b"\r\n.=yend-rest",
            ] {
                let mut input = vec![b'k'; length];
                for position in (3..length.saturating_sub(1)).step_by(32) {
                    input[position] = b'=';
                    input[position + 1] = b'J';
                }
                input.extend_from_slice(ending);
                let original = input.clone();
                for initial in 0..7 {
                    let mut expected = vec![0xcc; input.len() + 16];
                    let mut expected_state = initial;
                    let reference = unsafe {
                        decode_end(
                            input.as_ptr(),
                            expected.as_mut_ptr(),
                            input.len(),
                            &mut expected_state,
                            false,
                        )
                    };
                    for alignment in 0..16 {
                        for gap in [0, 1, 15, 64] {
                            let start = 16 + alignment;
                            let source = start + gap;
                            let mut storage = vec![0xcc; source + input.len() + 16];
                            storage[source..source + input.len()].copy_from_slice(&input);
                            let before = storage.clone();
                            let mut state = initial;
                            let mut src = unsafe { storage.as_ptr().add(source).cast() };
                            let mut dst = unsafe { storage.as_mut_ptr().add(start).cast() };
                            let end = unsafe {
                                decode_incremental(&mut src, &mut dst, input.len(), &mut state)
                            };
                            let consumed = src as usize - storage.as_ptr() as usize - source;
                            let written = dst as usize - storage.as_ptr() as usize - start;
                            assert_eq!(
                                (consumed, written, end, state),
                                (reference.0, reference.1, reference.2, expected_state)
                            );
                            assert_eq!(&storage[start..start + written], &expected[..written]);
                            assert_eq!(
                                &storage[start + input.len()..],
                                &before[start + input.len()..]
                            );
                            assert_eq!(
                                &storage[source + consumed..source + input.len()],
                                &input[consumed..]
                            );
                            assert_eq!(&storage[source + length..source + input.len()], ending);
                            assert!(storage[..start].iter().all(|&byte| byte == 0xcc));
                            assert!(
                                storage[source + input.len()..]
                                    .iter()
                                    .all(|&byte| byte == 0xcc)
                            );
                        }
                    }
                }
                assert_eq!(input, original);
            }
        }
    }

    #[test]
    fn incremental_simd_overlap_continues_after_compacted_chunk_without_copy() {
        let mut input = vec![b'k'; 80];
        input[5] = b'=';
        input[6] = b'J';
        input[33] = b'\r';
        input[34] = b'\n';
        let mut expected = vec![0xcc; input.len()];
        let mut expected_state = CRLF;
        let reference = unsafe {
            decode_end(
                input.as_ptr(),
                expected.as_mut_ptr(),
                input.len(),
                &mut expected_state,
                false,
            )
        };
        for gap in [0, 1, 15, 64] {
            let source = 16 + gap;
            let mut storage = vec![0xcc; source + input.len() + 16];
            storage[source..source + input.len()].copy_from_slice(&input);
            let before = storage.clone();
            let mut src = unsafe { storage.as_ptr().add(source).cast() };
            let mut dst = unsafe { storage.as_mut_ptr().add(16).cast() };
            let mut state = CRLF;
            unsafe {
                assert_eq!(decode_incremental(&mut src, &mut dst, 16, &mut state), 0);
            }
            assert_eq!(&storage[32..], &before[32..]);
            assert_eq!(src as usize - storage.as_ptr() as usize, source + 16);
            assert_eq!(&storage[source + 16..source + input.len()], &input[16..]);
            let second_capacity_end = dst as usize - storage.as_ptr() as usize + 64;
            let before_second = storage.clone();
            unsafe {
                assert_eq!(decode_incremental(&mut src, &mut dst, 64, &mut state), 0);
            }
            assert_eq!(
                &storage[second_capacity_end..],
                &before_second[second_capacity_end..]
            );
            assert_eq!(dst as usize - storage.as_ptr() as usize, 16 + reference.1);
            assert_eq!(&storage[16..16 + reference.1], &expected[..reference.1]);
            assert_eq!(state, expected_state);
            assert!(storage[..16].iter().all(|&byte| byte == 0xcc));
            assert!(
                storage[source + input.len()..]
                    .iter()
                    .all(|&byte| byte == 0xcc)
            );
        }
    }

    #[cfg(target_arch = "aarch64")]
    #[test]
    fn neon_nonraw_prefix_compacts_payload_framing_without_scalar_reentry() {
        for position in [0, 14, 15, 16, 30, 31, 46, 47, 48] {
            let mut input = [b'k'; 64];
            input[position..position + 7].copy_from_slice(b"\r\n.=yen");
            input[62] = b'\r';
            input[63] = b'\n';
            let mut expected = [0xcc; 80];
            let mut state = CRLF;
            let n = unsafe {
                decode_noend(
                    false,
                    input.as_ptr(),
                    expected.as_mut_ptr(),
                    64,
                    &mut state,
                    false,
                )
            };
            let mut output = [0xcc; 80];
            let actual =
                unsafe { arm::prefix::<false, false>(input.as_ptr(), output.as_mut_ptr(), 64) };
            assert_eq!(actual, (64, n), "position={position}");
            assert_eq!(&output[..n], &expected[..n]);
            assert!(output[64..].iter().all(|&byte| byte == 0xcc));
        }
    }

    #[test]
    fn nonraw_payload_framing_and_escaped_newlines_match_scalar_at_every_boundary() {
        for length in [15, 16, 17, 31, 32, 33, 63, 64, 65, 127, 128, 129, 199] {
            for special in [
                b"\r\n..".as_slice(),
                b"\r\n=yend",
                b"\r\n.=yend",
                b"=\r\n.",
                b"=\n\r.",
                b"==\r\n",
                b"\r\n=",
                b"\r\n.\r\nrest",
            ] {
                for position in 0..=length {
                    let mut input = vec![b'k'; length];
                    input.splice(position..position, special.iter().copied());
                    for initial in 0..7 {
                        let mut expected = vec![0xcc; input.len() + 16];
                        let mut expected_state = initial;
                        let n = unsafe {
                            decode_noend(
                                false,
                                input.as_ptr(),
                                expected.as_mut_ptr(),
                                input.len(),
                                &mut expected_state,
                                false,
                            )
                        };
                        for gap in [0, 1, 15, 64] {
                            let mut storage = vec![0xcc; input.len() + gap + 32];
                            storage[16 + gap..16 + gap + input.len()].copy_from_slice(&input);
                            let before = storage.clone();
                            let mut state = initial;
                            let actual = unsafe {
                                decode_ex(
                                    0,
                                    storage.as_ptr().add(16 + gap).cast(),
                                    storage.as_mut_ptr().add(16).cast(),
                                    input.len(),
                                    &mut state,
                                )
                            };
                            assert_eq!(
                                (actual, state),
                                (n, expected_state),
                                "length={length} position={position} special={special:?} initial={initial} gap={gap}"
                            );
                            assert_eq!(&storage[16..16 + n], &expected[..n]);
                            assert_eq!(&storage[16 + input.len()..], &before[16 + input.len()..]);
                            assert!(storage[..16].iter().all(|&byte| byte == 0xcc));
                            assert!(
                                storage[16 + gap + input.len()..]
                                    .iter()
                                    .all(|&byte| byte == 0xcc)
                            );
                        }
                        let (count, state, output) = ex(0, &input, initial);
                        assert_eq!((count, state), (n, expected_state));
                        assert_eq!(&output[..n], &expected[..n]);
                    }
                }
            }
        }
    }

    #[test]
    fn every_nonzero_raw_value_preserves_framing_and_public_escape_quirks() {
        for raw in [1, -1, 2, 7, i32::MIN, i32::MAX] {
            for input in [
                b"\r".as_slice(),
                b"\r\n",
                b"\r\n..klm",
                b"\r\n=yend",
                b"=\r\n..klm",
            ] {
                for state in 0..7 {
                    let reference = ex(1, input, state);
                    let actual = ex(raw, input, state);
                    assert_eq!(actual, reference, "raw={raw} input={input:?} state={state}");
                }
            }
        }
    }

    #[test]
    fn ordinary_multi_special_blocks_preserve_every_state_overlap_and_unread_suffix() {
        for length in [16, 17, 31, 32, 33, 63, 64, 65, 127, 128, 129] {
            let pattern = b"k=Jk=Lkk=Nkk\r\nkk";
            let mut input: Vec<u8> = (0..length)
                .map(|index| pattern[index % pattern.len()])
                .collect();
            input.extend_from_slice(b"\r\n.=yend-unread");
            for initial in 0..7 {
                for raw in [false, true] {
                    let mut expected = vec![0xcc; input.len() + 16];
                    let mut expected_state = initial;
                    let n = unsafe {
                        decode_noend(
                            raw,
                            input.as_ptr(),
                            expected.as_mut_ptr(),
                            input.len(),
                            &mut expected_state,
                            false,
                        )
                    };
                    for alignment in 0..16 {
                        for gap in [0, 1, 15, 64] {
                            let start = 16 + alignment;
                            let source = start + gap;
                            let mut storage = vec![0xcc; source + input.len() + 16];
                            storage[source..source + input.len()].copy_from_slice(&input);
                            let before = storage.clone();
                            let mut state = initial;
                            let actual = unsafe {
                                decode_ex(
                                    i32::from(raw),
                                    storage.as_ptr().add(source).cast(),
                                    storage.as_mut_ptr().add(start).cast(),
                                    input.len(),
                                    &mut state,
                                )
                            };
                            assert_eq!((actual, state), (n, expected_state));
                            assert_eq!(&storage[start..start + n], &expected[..n]);
                            assert_eq!(
                                &storage[start + input.len()..],
                                &before[start + input.len()..]
                            );
                            assert!(storage[..start].iter().all(|&byte| byte == 0xcc));
                            assert!(
                                storage[source + input.len()..]
                                    .iter()
                                    .all(|&byte| byte == 0xcc)
                            );
                        }
                    }
                }
                let mut expected = vec![0xcc; input.len() + 16];
                let mut expected_state = initial;
                let reference = unsafe {
                    decode_end(
                        input.as_ptr(),
                        expected.as_mut_ptr(),
                        input.len(),
                        &mut expected_state,
                        false,
                    )
                };
                for alignment in 0..16 {
                    for gap in [0, 1, 15, 64] {
                        let start = 16 + alignment;
                        let source = start + gap;
                        let mut storage = vec![0xcc; source + input.len() + 16];
                        storage[source..source + input.len()].copy_from_slice(&input);
                        let before = storage.clone();
                        let mut state = initial;
                        let mut src = unsafe { storage.as_ptr().add(source).cast() };
                        let mut dst = unsafe { storage.as_mut_ptr().add(start).cast() };
                        let end = unsafe {
                            decode_incremental(&mut src, &mut dst, input.len(), &mut state)
                        };
                        let consumed = src as usize - storage.as_ptr() as usize - source;
                        let written = dst as usize - storage.as_ptr() as usize - start;
                        assert_eq!(
                            (consumed, written, end, state),
                            (reference.0, reference.1, reference.2, expected_state)
                        );
                        assert_eq!(&storage[start..start + written], &expected[..written]);
                        assert_eq!(
                            &storage[start + input.len()..],
                            &before[start + input.len()..]
                        );
                        assert_eq!(
                            &storage[source + consumed..source + input.len()],
                            &input[consumed..]
                        );
                        assert!(storage[..start].iter().all(|&byte| byte == 0xcc));
                        assert!(
                            storage[source + input.len()..]
                                .iter()
                                .all(|&byte| byte == 0xcc)
                        );
                    }
                }
            }
        }
    }

    #[test]
    fn dense_escape_pairs_all_values_and_alignment_tails_match_scalar() {
        for value in 0..=255u8 {
            for tail in 0..65 {
                let mut input = vec![b'='; 256 + tail];
                for pair in input.as_chunks_mut::<2>().0 {
                    pair[1] = value;
                }
                for initial in 0..7 {
                    for raw in [false, true] {
                        let mut expected = vec![0xcc; input.len() + 16];
                        let mut expected_state = initial;
                        let n = unsafe {
                            decode_noend(
                                raw,
                                input.as_ptr(),
                                expected.as_mut_ptr(),
                                input.len(),
                                &mut expected_state,
                                false,
                            )
                        };
                        let (m, state, actual) = ex(i32::from(raw), &input, initial);
                        assert_eq!(
                            (m, state),
                            (n, expected_state),
                            "value={value} tail={tail} initial={initial} raw={raw}"
                        );
                        assert_eq!(&actual[..m], &expected[..n]);
                    }
                }
            }
        }
    }

    #[test]
    fn incremental_interleaved_states_and_empty_calls_are_independent() {
        let mut a_state = CRLF;
        let mut b_state = CRLF;
        let mut a = [0xccu8; 16];
        let mut b = [0xccu8; 16];
        let mut ap = a.as_mut_ptr().cast();
        let mut bp = b.as_mut_ptr().cast();
        let mut a_input = b"=J\r\n=".as_ptr().cast();
        let mut b_input = b"..klm\r".as_ptr().cast();
        unsafe {
            assert_eq!(
                decode_incremental(&mut a_input, &mut ap, 5, &mut a_state),
                0
            );
            assert_eq!(a_state, CRLFEQ);
            assert_eq!(
                decode_incremental(&mut b_input, &mut bp, 6, &mut b_state),
                0
            );
            assert_eq!(b_state, CR);
            let saved = (a_input, ap, a_state);
            assert_eq!(
                decode_incremental(&mut a_input, &mut ap, 0, &mut a_state),
                0
            );
            assert_eq!((a_input, ap, a_state), saved);
            a_input = b"yend".as_ptr().cast();
            b_input = b"\n.\r\n".as_ptr().cast();
            assert_eq!(
                decode_incremental(&mut a_input, &mut ap, 4, &mut a_state),
                1
            );
            assert_eq!(
                decode_incremental(&mut b_input, &mut bp, 4, &mut b_state),
                2
            );
        }
        assert_eq!(&a[..1], &[0xe0]);
        assert_eq!(&b[..4], &[4, 65, 66, 67]);
    }

    #[test]
    fn all_initial_states_have_literal_one_byte_semantics() {
        for initial in 0..7 {
            let (n, state, out) = ex(1, b"y", initial);
            assert_eq!(
                (n, state, out[0]),
                (1, NONE, if initial == EQ { 0x0f } else { 0x4f })
            );
            for input in [b"y".as_slice(), b"\n".as_slice()] {
                let mut output = [0xccu8; 8];
                let mut src = input.as_ptr().cast();
                let mut dst = output.as_mut_ptr().cast();
                let mut state = initial;
                let end = unsafe { decode_incremental(&mut src, &mut dst, 1, &mut state) };
                let expected = if input[0] == b'y' {
                    match initial {
                        CRLFEQ => (1, NONE, 0, 0xcc),
                        EQ => (0, NONE, 1, 0x0f),
                        _ => (0, NONE, 1, 0x4f),
                    }
                } else {
                    match initial {
                        CR => (0, CRLF, 0, 0xcc),
                        CRLFDTCR => (2, CRLF, 0, 0xcc),
                        EQ | CRLFEQ => (0, NONE, 1, 0xa0),
                        _ => (0, NONE, 0, 0xcc),
                    }
                };
                assert_eq!(
                    (
                        end,
                        state,
                        dst as usize - output.as_ptr() as usize,
                        output[0]
                    ),
                    expected,
                    "initial={initial} input={input:?}"
                );
                assert_eq!(src as usize - input.as_ptr() as usize, 1);
            }
        }
        decode_init();
        let mut output = [0xccu8; 8];
        let n = unsafe { decode(b"..klm".as_ptr().cast(), output.as_mut_ptr().cast(), 5) };
        assert_eq!(&output[..n], &[4, 65, 66, 67]);
    }

    #[test]
    fn encoded_capacity_keeps_size_t_wrapping_and_signed_conversion() {
        assert_eq!(encode_max_length(0, 128), 66);
        assert_eq!(encode_max_length(64, 128), 196);
        assert_eq!(encode_max_length(64, 1), 450);
        assert_eq!(encode_max_length(64, -1), 194);
        assert_eq!(encode_max_length(64, 0), usize::MAX);
        assert_eq!(encode_max_length(usize::MAX, -1), 64);
        assert_eq!(
            encode_max_length(usize::MAX, 128),
            ((usize::MAX >> 6) * 2).wrapping_add(64)
        );
    }
}

use std::ffi::{c_int, c_void};
use std::mem::{align_of, size_of};

#[cfg(target_arch = "aarch64")]
#[path = "abi_decoder_arm.rs"]
mod arm;
#[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
#[path = "abi_decoder_x86.rs"]
mod x86;

const CRLF: c_int = 0;
const EQ: c_int = 1;
const CR: c_int = 2;
const NONE: c_int = 3;
const CRLFDT: c_int = 4;
const CRLFDTCR: c_int = 5;
const CRLFEQ: c_int = 6;

pub fn decode_init() {
    let _ = decode_kernel();
}

pub fn decode_kernel() -> c_int {
    #[cfg(target_arch = "aarch64")]
    {
        return 0x1000;
    }
    #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
    {
        if is_x86_feature_detected!("ssse3") {
            return 0x200;
        }
    }
    #[allow(unreachable_code)]
    0
}

pub fn encode_max_length(length: usize, line_size: c_int) -> usize {
    if line_size == 0 {
        return usize::MAX;
    }
    let doubled = length.wrapping_mul(2);
    let lines = if line_size == 128 {
        length >> 6
    } else {
        doubled / line_size as usize
    };
    doubled.wrapping_add(66).wrapping_add(lines.wrapping_mul(2))
}

fn range(address: usize, length: usize) -> Option<(usize, usize)> {
    if address == 0 || length > isize::MAX as usize {
        return None;
    }
    Some((address, address.checked_add(length)?))
}
fn overlaps(a: (usize, usize), b: (usize, usize)) -> bool {
    a.0 < b.1 && b.0 < a.1
}
fn object<T>(pointer: *const T) -> Option<(usize, usize)> {
    if !(pointer as usize).is_multiple_of(align_of::<T>()) {
        return None;
    }
    range(pointer as usize, size_of::<T>())
}
fn buffers(
    source: *const c_void,
    destination: *mut c_void,
    length: usize,
) -> Option<[(usize, usize); 2]> {
    let src = range(source as usize, length)?;
    let dst = range(destination as usize, length)?;
    if dst.0 > src.0 && overlaps(src, dst) {
        return None;
    }
    Some([src, dst])
}
unsafe fn validated_state(state: *mut c_int, data: &[(usize, usize)]) -> Option<c_int> {
    if state.is_null() {
        return Some(CRLF);
    }
    let storage = object(state)?;
    if data.iter().any(|&part| overlaps(storage, part)) {
        return None;
    }
    let value = unsafe { state.read() };
    (CRLF..=CRLFEQ).contains(&value).then_some(value)
}

/// Decode a raw payload with the default CRLF state.
///
/// # Safety
/// Caller supplies valid readable/writable regions of `length` bytes. The
/// destination may overlap only at or behind source. State storage is separate.
pub unsafe fn decode(source: *const c_void, destination: *mut c_void, length: usize) -> usize {
    unsafe { decode_ex(1, source, destination, length, std::ptr::null_mut()) }
}

/// Like `decode`, with optional aligned state storage.
///
/// # Safety
/// Caller supplies valid buffers as required by `decode`, and state points to
/// a valid integer when present. Invalid arguments are
/// rejected before writes; actual allocation validity remains the caller's job.
pub unsafe fn decode_ex(
    raw: c_int,
    source: *const c_void,
    destination: *mut c_void,
    length: usize,
    state: *mut c_int,
) -> usize {
    if length == 0 {
        return 0;
    }
    let Some(data) = buffers(source, destination, length) else {
        return usize::MAX;
    };
    let Some(mut current) = (unsafe { validated_state(state, &data) }) else {
        return usize::MAX;
    };
    let written = unsafe {
        decode_noend(
            raw != 0,
            source.cast(),
            destination.cast(),
            length,
            &mut current,
            true,
        )
    };
    if !state.is_null() {
        unsafe { state.write(current) };
    }
    written
}

/// Decode until an article/control ending and advance both caller pointers.
///
/// # Safety
/// Pointer-storage objects must be valid, aligned, distinct and separate from state
/// and both data ranges. Data itself supports destination-at-or-behind overlap.
pub unsafe fn decode_incremental(
    source: *mut *const c_void,
    destination: *mut *mut c_void,
    length: usize,
    state: *mut c_int,
) -> c_int {
    if length == 0 {
        return 0;
    }
    let Some(src_storage) = object(source) else {
        return 0;
    };
    let Some(dst_storage) = object(destination) else {
        return 0;
    };
    if overlaps(src_storage, dst_storage) {
        return 0;
    }
    let state_storage = if state.is_null() {
        None
    } else {
        let Some(storage) = object(state) else {
            return 0;
        };
        if overlaps(storage, src_storage) || overlaps(storage, dst_storage) {
            return 0;
        }
        Some(storage)
    };
    let src = unsafe { source.read() };
    let dst = unsafe { destination.read() };
    let Some(data) = buffers(src, dst, length) else {
        return 0;
    };
    if data.iter().any(|&part| {
        overlaps(part, src_storage)
            || overlaps(part, dst_storage)
            || state_storage.is_some_and(|s| overlaps(part, s))
    }) {
        return 0;
    }
    let Some(mut current) = (unsafe { validated_state(state, &data) }) else {
        return 0;
    };
    let (read, written, end) =
        unsafe { decode_end(src.cast(), dst.cast(), length, &mut current, true) };
    unsafe {
        source.write(src.cast::<u8>().add(read).cast());
        destination.write(dst.cast::<u8>().add(written).cast());
        if !state.is_null() {
            state.write(current);
        }
    }
    end
}

/// Decode complete blocks that cannot contain line transitions, consuming no
/// pending escape. Helpers load each block before any overlapping output store.
#[inline]
unsafe fn bulk<const RAW: bool, const END: bool>(
    source: *const u8,
    destination: *mut u8,
    length: usize,
    simd: bool,
) -> (usize, usize) {
    if !simd || length < 16 {
        return (0, 0);
    }
    #[cfg(target_arch = "aarch64")]
    {
        return unsafe { arm::prefix::<RAW, END>(source, destination, length) };
    }
    #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
    if is_x86_feature_detected!("ssse3") {
        return unsafe { x86::prefix(source, destination, length) };
    }
    #[allow(unreachable_code)]
    (0, 0)
}

unsafe fn decode_noend(
    raw: bool,
    src: *const u8,
    dst: *mut u8,
    len: usize,
    state: &mut c_int,
    simd: bool,
) -> usize {
    if raw {
        unsafe { decode_noend_mode::<true>(src, dst, len, state, simd) }
    } else {
        unsafe { decode_noend_mode::<false>(src, dst, len, state, simd) }
    }
}

unsafe fn decode_noend_mode<const RAW: bool>(
    src: *const u8,
    dst: *mut u8,
    len: usize,
    state: &mut c_int,
    simd: bool,
) -> usize {
    let mut read = 0;
    let mut written = 0;
    unsafe {
        // Only the public initial EQ state causes these two zero-length
        // returns. Never re-enter this prologue for a SIMD prefix or tail.
        if *state == EQ {
            let byte = src.read();
            dst.write(byte.wrapping_sub(106));
            written = 1;
            read = 1;
            *state = NONE;
            if RAW && byte == b'\r' {
                *state = CR;
                if read == len {
                    return 0;
                }
            }
        }
        if RAW && *state == CR && src.add(read).read() == b'\n' {
            read += 1;
            *state = CRLF;
            if read == len {
                return 0;
            }
        }
        if RAW && *state == CRLF && read < len && src.add(read).read() == b'.' {
            read += 1;
        }
        *state = NONE;
        while read < len {
            let (consumed, produced) =
                bulk::<RAW, false>(src.add(read), dst.add(written), len - read, simd);
            read += consumed;
            written += produced;
            if read == len {
                break;
            }
            let byte = src.add(read).read();
            match byte {
                b'=' => {
                    read += 1;
                    if read == len {
                        *state = EQ;
                        break;
                    }
                    let escaped = src.add(read).read();
                    dst.add(written).write(escaped.wrapping_sub(106));
                    written += 1;
                    // Raw escaped CR is also framing; nonraw consumes it once.
                    if !RAW || escaped != b'\r' {
                        read += 1;
                    }
                }
                b'\r' => {
                    read += 1;
                    if RAW {
                        if read == len {
                            *state = CR;
                            break;
                        }
                        if src.add(read).read() == b'\n' {
                            read += 1;
                            if read == len {
                                *state = CRLF;
                                break;
                            }
                            if src.add(read).read() == b'.' {
                                read += 1;
                            }
                        }
                    }
                }
                b'\n' => read += 1,
                _ => {
                    dst.add(written).write(byte.wrapping_sub(42));
                    read += 1;
                    written += 1;
                }
            }
        }
    }
    written
}

unsafe fn decode_end(
    src: *const u8,
    dst: *mut u8,
    len: usize,
    state: &mut c_int,
    simd: bool,
) -> (usize, usize, c_int) {
    let mut read = 0;
    let mut written = 0;
    unsafe {
        while read < len {
            // Every non-NONE state examines its first byte before SIMD enters.
            if *state == NONE {
                let (consumed, produced) =
                    bulk::<true, true>(src.add(read), dst.add(written), len - read, simd);
                read += consumed;
                written += produced;
                if read == len {
                    break;
                }
            }
            let byte = src.add(read).read();
            match *state {
                CRLFEQ if byte == b'y' => {
                    *state = NONE;
                    return (read + 1, written, 1);
                }
                EQ | CRLFEQ => {
                    dst.add(written).write(byte.wrapping_sub(106));
                    written += 1;
                    read += 1;
                    *state = if byte == b'\r' { CR } else { NONE };
                    continue;
                }
                CR if byte == b'\n' => {
                    read += 1;
                    *state = CRLF;
                    continue;
                }
                CRLF if byte == b'.' => {
                    read += 1;
                    *state = CRLFDT;
                    continue;
                }
                CRLF | CRLFDT if byte == b'=' => {
                    read += 1;
                    *state = CRLFEQ;
                    continue;
                }
                CRLFDT if byte == b'\r' => {
                    read += 1;
                    *state = CRLFDTCR;
                    continue;
                }
                CRLFDTCR if byte == b'\n' => {
                    *state = CRLF;
                    return (read + 1, written, 2);
                }
                _ => {}
            }
            read += 1;
            *state = match byte {
                b'=' => EQ,
                b'\r' => CR,
                b'\n' => NONE,
                _ => {
                    dst.add(written).write(byte.wrapping_sub(42));
                    written += 1;
                    NONE
                }
            };
        }
    }
    (read, written, 0)
}
