//! Frozen C++ receipts replayed through the actual Rust C ABI.

use crate::tooling::{Library, file_sha256, verify_library};
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use std::ffi::{c_int, c_void};
use std::path::Path;

type Decode = unsafe extern "C" fn(c_int, *const c_void, *mut c_void, usize, *mut c_int) -> usize;
type DecodeDefault = unsafe extern "C" fn(*const c_void, *mut c_void, usize) -> usize;
type Incremental =
    unsafe extern "C" fn(*mut *const c_void, *mut *mut c_void, usize, *mut c_int) -> c_int;
type Crc = unsafe extern "C" fn(*const c_void, usize, u32) -> u32;
type Combine = unsafe extern "C" fn(u32, u32, u64) -> u32;
type Shift = unsafe extern "C" fn(u32, u64) -> u32;
type Multiply = unsafe extern "C" fn(u32, u32) -> u32;
type Pow = unsafe extern "C" fn(i64) -> u32;
type BytePow = unsafe extern "C" fn(u64) -> u32;
type MaxLength = unsafe extern "C" fn(usize, c_int) -> usize;

// Frozen from the pinned, configured reference. Updating this requires a new
// reviewed reference receipt, never a candidate-generated expectation.
pub const FROZEN_RECEIPT_SHA256: &str =
    "d95e3d020dc604461fc03d039dd925077798c7686c000eb7b85ac619bf9044b6";
pub const FROZEN_REPLAY_CALLS: usize = 9_479_481;

struct Abi {
    _library: Library,
    decode: Decode,
    alias: DecodeDefault,
    incremental: Incremental,
    crc: Crc,
    combine: Combine,
    zeros: Shift,
    unzero: Shift,
    multiply: Multiply,
    pow: Pow,
    byte_pow: BytePow,
    max_length: MaxLength,
}

impl Abi {
    fn open(path: &Path) -> Result<Self, String> {
        let library = Library::open(path)?;
        type Init = unsafe extern "C" fn();
        unsafe {
            library.symbol::<Init>("rapidyenc_decode_init")?();
            library.symbol::<Init>("rapidyenc_crc_init")?();
            Ok(Self {
                decode: library.symbol("rapidyenc_decode_ex")?,
                alias: library.symbol("rapidyenc_decode")?,
                incremental: library.symbol("rapidyenc_decode_incremental")?,
                crc: library.symbol("rapidyenc_crc")?,
                combine: library.symbol("rapidyenc_crc_combine")?,
                zeros: library.symbol("rapidyenc_crc_zeros")?,
                unzero: library.symbol("rapidyenc_crc_unzero")?,
                multiply: library.symbol("rapidyenc_crc_multiply")?,
                pow: library.symbol("rapidyenc_crc_2pow")?,
                byte_pow: library.symbol("rapidyenc_crc_256pow")?,
                max_length: library.symbol("rapidyenc_encode_max_length")?,
                _library: library,
            })
        }
    }
}

#[derive(Serialize, Deserialize)]
pub struct Receipts {
    format: String,
    pointer_bits: usize,
    reference: Value,
    decode: Vec<DecodeReceipt>,
    incremental: Vec<IncrementalReceipt>,
    fields: Vec<FieldReceipt>,
    crc: Vec<CrcReceipt>,
    sizes: Vec<SizeReceipt>,
    corrections: Vec<Value>,
}

#[derive(Clone, Serialize, Deserialize, Debug, PartialEq)]
struct DecodeReceipt {
    input: Vec<u8>,
    raw: i32,
    initial: Option<i32>,
    returned: usize,
    output: Vec<u8>,
    state: Option<i32>,
    public_write: Option<u8>,
}

#[derive(Clone, Serialize, Deserialize, Debug, PartialEq)]
struct IncrementalReceipt {
    input: Vec<u8>,
    initial: Option<i32>,
    split: usize,
    end: i32,
    consumed: usize,
    output: Vec<u8>,
    state: Option<i32>,
}

#[derive(Serialize, Deserialize)]
struct FieldReceipt {
    operation: String,
    first: u32,
    second: u32,
    length: u64,
    exponent: i64,
    expected: u32,
}

#[derive(Serialize, Deserialize)]
struct CrcReceipt {
    input: Vec<u8>,
    seed: u32,
    expected: u32,
}

#[derive(Serialize, Deserialize)]
struct SizeReceipt {
    length: usize,
    line: i32,
    expected: usize,
}

#[derive(Serialize, Deserialize)]
struct CompactReceipts {
    format: String,
    blobs: Vec<Vec<u8>>,
    receipts: Value,
}

fn pack(receipts: &Receipts) -> Result<Vec<u8>, String> {
    let mut value = serde_json::to_value(receipts).map_err(|e| e.to_string())?;
    let mut indices = std::collections::BTreeMap::<Vec<u8>, usize>::new();
    let mut blobs = vec![];
    for (class, columns) in [
        ("decode", &["input", "output"][..]),
        ("incremental", &["input", "output"][..]),
        ("crc", &["input"][..]),
    ] {
        for receipt in value[class].as_array_mut().ok_or("missing receipt class")? {
            for column in columns {
                let bytes: Vec<u8> = serde_json::from_value(std::mem::take(&mut receipt[*column]))
                    .map_err(|e| e.to_string())?;
                let index = *indices.entry(bytes.clone()).or_insert_with(|| {
                    let index = blobs.len();
                    blobs.push(bytes);
                    index
                });
                receipt[*column] = json!(index);
            }
        }
    }
    serde_json::to_vec(&CompactReceipts {
        format: "nntmux-rapidyenc-compact-v1".into(),
        blobs,
        receipts: value,
    })
    .map_err(|e| e.to_string())
}

fn unpack(bytes: &[u8]) -> Result<Receipts, String> {
    let mut compact: CompactReceipts = serde_json::from_slice(bytes).map_err(|e| e.to_string())?;
    if compact.format != "nntmux-rapidyenc-compact-v1"
        || compact
            .blobs
            .iter()
            .any(|blob| blob.len() > 16 * 1024 * 1024)
        || compact.blobs.iter().map(Vec::len).sum::<usize>() > 64 * 1024 * 1024
    {
        return Err("invalid compact receipt format or byte table".into());
    }
    for (class, columns) in [
        ("decode", &["input", "output"][..]),
        ("incremental", &["input", "output"][..]),
        ("crc", &["input"][..]),
    ] {
        for receipt in compact.receipts[class]
            .as_array_mut()
            .ok_or("missing receipt class")?
        {
            for column in columns {
                let index = receipt[*column]
                    .as_u64()
                    .and_then(|v| usize::try_from(v).ok())
                    .ok_or("invalid byte-table index")?;
                let blob = compact
                    .blobs
                    .get(index)
                    .ok_or("byte-table index out of bounds")?;
                receipt[*column] = serde_json::to_value(blob).map_err(|e| e.to_string())?;
            }
        }
    }
    serde_json::from_value(compact.receipts).map_err(|e| e.to_string())
}

fn validate_incremental(receipt: &IncrementalReceipt) -> Result<(), String> {
    if receipt.input.len() > 16 * 1024 * 1024
        || receipt.split > receipt.input.len()
        || receipt.consumed > receipt.input.len()
        || receipt.output.len() > receipt.consumed
        || !(0..=2).contains(&receipt.end)
        || receipt
            .initial
            .is_some_and(|state| !(0..7).contains(&state))
        || receipt.state.is_some_and(|state| !(0..7).contains(&state))
        || receipt.initial.is_some() != receipt.state.is_some()
    {
        return Err("invalid incremental receipt bounds or state".into());
    }
    Ok(())
}

fn aligned(input: &[u8], alignment: usize) -> (Vec<u8>, usize) {
    let mut storage = vec![0xc7; input.len() + 256];
    let address = storage.as_ptr() as usize;
    let offset = ((address + 63) & !63) - address + alignment + 64;
    storage[offset..offset + input.len()].copy_from_slice(input);
    (storage, offset)
}

fn decode_call(
    abi: &Abi,
    input: &[u8],
    raw: i32,
    initial: Option<i32>,
    alignment: usize,
    alias: bool,
    backward: Option<usize>,
) -> Result<DecodeReceipt, String> {
    let (mut source, offset) = aligned(input, alignment);
    let before = source.clone();
    let mut output = vec![0xc7; input.len() + 128];
    let output_offset = match backward {
        Some(distance) => offset
            .checked_sub(distance)
            .ok_or("invalid backward offset")?,
        None => 64,
    };
    let destination = match backward {
        Some(_) => unsafe { source.as_mut_ptr().add(output_offset) },
        None => unsafe { output.as_mut_ptr().add(output_offset) },
    };
    let mut state = initial.unwrap_or(0);
    let state_ptr = if initial.is_some() {
        &mut state
    } else {
        std::ptr::null_mut()
    };
    let returned = unsafe {
        if alias {
            (abi.alias)(
                source.as_ptr().add(offset).cast(),
                destination.cast(),
                input.len(),
            )
        } else {
            (abi.decode)(
                raw,
                source.as_ptr().add(offset).cast(),
                destination.cast(),
                input.len(),
                state_ptr,
            )
        }
    };
    let guards = if backward.is_some() {
        source[..output_offset].iter().all(|v| *v == 0xc7)
            && source[output_offset + input.len()..] == before[output_offset + input.len()..]
    } else {
        output[..64].iter().all(|v| *v == 0xc7)
            && output[64 + input.len()..].iter().all(|v| *v == 0xc7)
            && source == before
    };
    if returned > input.len() || !guards {
        return Err(format!(
            "invalid length/capacity, raw={raw}, state={initial:?}, input={input:?}, alignment={alignment}"
        ));
    }
    let public_write = (raw != 0 && initial == Some(1) && (input == b"\r" || input == b"\r\n"))
        .then(|| unsafe { *destination });
    Ok(DecodeReceipt {
        input: input.to_vec(),
        raw,
        initial,
        returned,
        output: unsafe { std::slice::from_raw_parts(destination, returned) }.to_vec(),
        state: initial.map(|_| state),
        public_write,
    })
}

fn incremental_call(
    abi: &Abi,
    input: &[u8],
    initial: Option<i32>,
    split: usize,
    alignment: usize,
    in_place: bool,
) -> Result<IncrementalReceipt, String> {
    if split > input.len() {
        return Err("split exceeds input length".into());
    }
    let (mut source, offset) = aligned(input, alignment);
    let source_before = source.clone();
    let mut output = vec![0xc7; input.len() + 128];
    let base_source = unsafe { source.as_mut_ptr().add(offset) };
    let base_output = if in_place {
        base_source
    } else {
        unsafe { output.as_mut_ptr().add(64) }
    };
    let mut src = base_source.cast_const().cast::<c_void>();
    let mut dest = base_output.cast::<c_void>();
    let mut state = initial.unwrap_or(0);
    let state_ptr = if initial.is_some() {
        &mut state
    } else {
        std::ptr::null_mut()
    };
    let mut end = unsafe { (abi.incremental)(&mut src, &mut dest, split, state_ptr) };
    let first_consumed = (src as usize)
        .checked_sub(base_source as usize)
        .ok_or("backwards source pointer")?;
    let first_written = (dest as usize)
        .checked_sub(base_output as usize)
        .ok_or("backwards destination pointer")?;
    if first_consumed > split
        || first_written > split
        || !(0..=2).contains(&end)
        || (end == 0 && first_consumed != split)
    {
        return Err("invalid first incremental result; continuation not called".into());
    }
    if end == 0 && split < input.len() {
        end = unsafe { (abi.incremental)(&mut src, &mut dest, input.len() - split, state_ptr) };
    }
    let consumed = (src as usize)
        .checked_sub(base_source as usize)
        .ok_or("backwards source pointer")?;
    let written = (dest as usize)
        .checked_sub(base_output as usize)
        .ok_or("backwards destination pointer")?;
    if consumed > input.len() || written > consumed || !(0..=2).contains(&end) {
        return Err("invalid incremental pointer or end result".into());
    }
    let output_bytes = unsafe { std::slice::from_raw_parts(base_output, written) }.to_vec();
    if in_place {
        if source[..offset].iter().any(|v| *v != 0xc7)
            || source[offset + input.len()..].iter().any(|v| *v != 0xc7)
            || source[offset + consumed..offset + input.len()] != input[consumed..]
        {
            return Err("in-place continuation changed guard/unread input".into());
        }
    } else if source != source_before
        || output[..64].iter().any(|v| *v != 0xc7)
        || output[64 + input.len()..].iter().any(|v| *v != 0xc7)
    {
        return Err("incremental destination capacity exceeded".into());
    }
    Ok(IncrementalReceipt {
        input: input.to_vec(),
        initial,
        split,
        end,
        consumed,
        output: output_bytes,
        state: initial.map(|_| state),
    })
}

fn inputs() -> Vec<Vec<u8>> {
    let alphabet = b"k=\r\n.y@";
    let mut all = vec![vec![]];
    let mut layer = vec![vec![]];
    for _ in 0..3 {
        let mut next = Vec::new();
        for prefix in &layer {
            for byte in alphabet {
                let mut input = prefix.clone();
                input.push(*byte);
                next.push(input);
            }
        }
        all.extend(next.iter().cloned());
        layer = next;
    }
    for byte in 0..=255 {
        all.push(vec![byte]);
        all.push(vec![b'=', byte]);
    }
    all.sort();
    all.dedup();
    all
}

pub fn freeze(reference: &Path, destination: &Path) -> Result<Value, String> {
    let mut identity = verify_library(reference, false)?;
    let abi = Abi::open(reference)?;
    if identity["sha256"].as_str() != Some(abi._library.sha256.as_str()) {
        return Err("reference changed between verification and receipt generation".into());
    }
    let mut tails = vec![];
    for alignment in [0, 1, 63] {
        let input = b"=J".repeat(100);
        let (source, offset) = aligned(&input, alignment);
        let mut output = vec![0xc7; input.len() + 128];
        let written = unsafe {
            (abi.decode)(
                0,
                source.as_ptr().add(offset).cast(),
                output.as_mut_ptr().add(64).cast(),
                input.len(),
                std::ptr::null_mut(),
            )
        };
        if written > input.len()
            || output[..64].iter().any(|v| *v != 0xc7)
            || output[64 + input.len()..].iter().any(|v| *v != 0xc7)
        {
            return Err("reference incidental-tail probe exceeded capacity".into());
        }
        tails.push(json!({"input":input,"alignment":alignment,"returned":written,"within_capacity_tail":&output[64+written..64+input.len()],"outside_capacity_guard_intact":true,"comparison_policy":"record only; meaningful extent and explicit public writes govern parity"}));
    }
    identity["destination_tail_diagnostics"] = json!(tails);
    let mut receipts = Receipts {
        format: "nntmux-rapidyenc-abi-v1".into(),
        pointer_bits: usize::BITS as usize,
        reference: identity,
        decode: vec![],
        incremental: vec![],
        fields: vec![],
        crc: vec![],
        sizes: vec![],
        corrections: vec![],
    };
    for input in inputs() {
        for initial in (0..7).map(Some).chain(std::iter::once(None)) {
            for raw in [0, 1] {
                receipts
                    .decode
                    .push(decode_call(&abi, &input, raw, initial, 1, false, None)?);
            }
            for split in 0..=input.len() {
                receipts
                    .incremental
                    .push(incremental_call(&abi, &input, initial, split, 1, false)?);
            }
        }
    }
    // Retain long artifact-level cases in addition to the exhaustive short
    // matrix. A single legal escape moves across vector and tail boundaries.
    for length in [
        15, 16, 17, 31, 32, 33, 63, 64, 65, 127, 128, 129, 199, 200, 201, 255, 256, 257, 1024, 4096,
    ] {
        let mut families = vec![vec![b'k'; length]];
        let mut positions = vec![
            0,
            1,
            7,
            14,
            15,
            16,
            30,
            31,
            32,
            62,
            63,
            64,
            126,
            127,
            length - 2,
        ];
        positions.retain(|position| *position + 1 < length);
        positions.sort();
        positions.dedup();
        for position in positions {
            let mut input = vec![b'k'; length];
            input[position] = b'=';
            input[position + 1] = b'J';
            families.push(input);
        }
        for input in families {
            for initial in (0..7).map(Some).chain(std::iter::once(None)) {
                for raw in [0, 1] {
                    receipts
                        .decode
                        .push(decode_call(&abi, &input, raw, initial, 1, false, None)?);
                }
                for split in [0, 1, length / 2, length - 1, length] {
                    receipts
                        .incremental
                        .push(incremental_call(&abi, &input, initial, split, 1, false)?);
                }
            }
        }
    }
    // Every position in a full 64-byte SIMD window, nearby lengths and final
    // tails retain actual line/dot/termination transitions through both APIs.
    for length in [127, 128, 129, 193] {
        for special in [
            b"\r\n".as_slice(),
            b".",
            b"=\r\n",
            b"\r\n..",
            b"\r\n=yend",
            b"\r\n.\r\n",
        ] {
            let mut positions: Vec<usize> = (0..64).collect();
            positions.push(length - special.len());
            for position in positions {
                let mut input = vec![b'k'; length];
                input[position..position + special.len()].copy_from_slice(special);
                for initial in (0..7).map(Some).chain(std::iter::once(None)) {
                    for raw in [0, 1] {
                        receipts
                            .decode
                            .push(decode_call(&abi, &input, raw, initial, 1, false, None)?);
                    }
                    for split in [0, position, position + 1, position + special.len(), length] {
                        receipts
                            .incremental
                            .push(incremental_call(&abi, &input, initial, split, 1, false)?);
                    }
                }
            }
        }
    }
    for (input, raw, initial, expected_output, expected_state) in [
        (
            [b"y".as_slice(), &[b'k'; 199]].concat(),
            0,
            6,
            [vec![0x4f], vec![b'A'; 199]].concat(),
            3,
        ),
        (
            [b"y".as_slice(), &[b'k'; 199]].concat(),
            1,
            6,
            [vec![0x4f], vec![b'A'; 199]].concat(),
            3,
        ),
        (
            [b"\r".as_slice(), &[b'k'; 199]].concat(),
            1,
            1,
            [vec![0xa3], vec![b'A'; 199]].concat(),
            3,
        ),
        (
            [vec![b'k'; 191], b"=\r\n".to_vec()].concat(),
            1,
            0,
            [vec![b'A'; 191], vec![0xa3]].concat(),
            0,
        ),
    ] {
        let observations: Vec<_> = [0, 1, 63]
            .into_iter()
            .map(|a| decode_call(&abi, &input, raw, Some(initial), a, false, None))
            .collect::<Result<_, _>>()?;
        receipts.corrections.push(json!({"reason":"approved whole-public-call scalar semantics", "reference_alignments":[0,1,63], "observations":observations}));
        receipts.decode.push(DecodeReceipt {
            input,
            raw,
            initial: Some(initial),
            returned: expected_output.len(),
            output: expected_output,
            state: Some(expected_state),
            public_write: None,
        });
    }
    // Extend only the three approved discrepancy causes around SIMD widths.
    // Literal canonical bytes/state are derived from the approved scalar rule,
    // never from candidate output. Reference observations remain in the ledger.
    for length in [
        15, 16, 17, 31, 32, 33, 63, 64, 65, 127, 128, 129, 190, 191, 192, 193, 199, 200, 201, 255,
        256, 257,
    ] {
        for (ledger, input, raw, initial, output, state) in [
            (
                0,
                [vec![b'y'], vec![b'k'; length - 1]].concat(),
                0,
                6,
                [vec![0x4f], vec![b'A'; length - 1]].concat(),
                3,
            ),
            (
                1,
                [vec![b'y'], vec![b'k'; length - 1]].concat(),
                1,
                6,
                [vec![0x4f], vec![b'A'; length - 1]].concat(),
                3,
            ),
            (
                2,
                [vec![b'\r'], vec![b'k'; length - 1]].concat(),
                1,
                1,
                [vec![0xa3], vec![b'A'; length - 1]].concat(),
                3,
            ),
            (
                3,
                [vec![b'k'; length], b"=\r\n".to_vec()].concat(),
                1,
                0,
                [vec![b'A'; length], vec![0xa3]].concat(),
                0,
            ),
        ] {
            let observations: [DecodeReceipt; 3] = [0, 1, 63].map(|alignment| {
                decode_call(&abi, &input, raw, Some(initial), alignment, false, None)
                    .expect("bounded approved correction probe")
            });
            let canonical = DecodeReceipt {
                input,
                raw,
                initial: Some(initial),
                returned: output.len(),
                output,
                state: Some(state),
                public_write: None,
            };
            let entry = json!({"length_parameter":length,"reference_alignments":[0,1,63],"observations":observations,"canonical":canonical});
            let row = &mut receipts.corrections[ledger];
            if row.get("boundary_variants").is_none() {
                row["boundary_variants"] = json!([]);
            }
            row["boundary_variants"].as_array_mut().unwrap().push(entry);
            receipts.decode.push(canonical);
        }
    }
    for input in [
        b"..klm".to_vec(),
        b"klm\r\n..klm\r\n.\r\n".to_vec(),
        b"=Jklm".to_vec(),
        b"\r\n=yend size=3".to_vec(),
    ] {
        for initial in [Some(0), Some(3), None] {
            for raw in [0, 1, -1, i32::MIN, i32::MAX] {
                receipts
                    .decode
                    .push(decode_call(&abi, &input, raw, initial, 1, false, None)?);
            }
            for split in 0..=input.len() {
                receipts
                    .incremental
                    .push(incremental_call(&abi, &input, initial, split, 1, false)?);
            }
        }
    }
    let mut random = 582139u64;
    for index in 0..256 {
        random ^= random << 13;
        random ^= random >> 7;
        random ^= random << 17;
        let first = random as u32;
        let second = (random >> 32) as u32;
        let length = [0, 1, 8, u64::MAX, 1u64 << 63, 1u64 << 32, random][index % 7];
        let exponent = [0, 1, -1, i64::MAX, i64::MIN + 1, random as i64][index % 6];
        for operation in ["combine", "zeros", "unzero", "multiply", "2pow", "256pow"] {
            let mut receipt = FieldReceipt {
                operation: operation.into(),
                first,
                second,
                length,
                exponent,
                expected: 0,
            };
            receipt.expected = field_call(&abi, &receipt);
            receipts.fields.push(receipt);
        }
    }
    receipts.fields.push(FieldReceipt {
        operation: "2pow".into(),
        first: 0,
        second: 0,
        length: 0,
        exponent: i64::MIN,
        expected: 1_387_617_849,
    });
    for length in [
        0, 1, 2, 3, 16, 31, 32, 63, 64, 65, 128, 129, 1024, 4095, 4096, 4097,
    ] {
        let input: Vec<u8> = (0..length).map(|i| (i * 73 + 29) as u8).collect();
        for seed in [0, 1, u32::MAX, 0x12345678] {
            let expected = unsafe { (abi.crc)(input.as_ptr().cast(), input.len(), seed) };
            receipts.crc.push(CrcReceipt {
                input: input.clone(),
                seed,
                expected,
            });
        }
    }
    for length in [
        0,
        1,
        2,
        65,
        65536,
        usize::MAX / 2,
        usize::MAX / 2 + 1,
        usize::MAX,
    ] {
        for line in [1, 2, 128, 255, -1, i32::MIN, i32::MAX] {
            receipts.sizes.push(SizeReceipt {
                length,
                line,
                expected: unsafe { (abi.max_length)(length, line) },
            });
        }
    }
    // A SIMD scalar tail can manufacture the preserved EQ+CRLF early return
    // inside a larger public call. Classify these exact k^N+tail inputs only
    // under the already approved third cause, preserving every observation.
    let prefix_models: std::collections::BTreeMap<_, _> = receipts
        .decode
        .iter()
        .filter(|receipt| receipt.input == b"k")
        .map(|receipt| ((receipt.raw, receipt.initial), receipt.clone()))
        .collect();
    for receipt in &mut receipts.decode {
        if receipt.raw == 0 || !receipt.input.ends_with(b"=\r\n") {
            continue;
        }
        let prefix_length = receipt.input.len() - 3;
        if prefix_length == 0
            || !receipt.input[..prefix_length]
                .iter()
                .all(|byte| *byte == b'k')
        {
            continue;
        }
        let short = prefix_models
            .get(&(receipt.raw, receipt.initial))
            .ok_or("missing independently frozen short prefix")?;
        if short.returned != 1
            || short.output.len() != 1
            || short.state != receipt.initial.map(|_| 3)
        {
            return Err("short prefix does not establish ordinary continuation state".into());
        }
        let mut output = vec![b'A'; prefix_length];
        output[0] = short.output[0];
        output.push(0xa3);
        let canonical = DecodeReceipt {
            input: receipt.input.clone(),
            raw: receipt.raw,
            initial: receipt.initial,
            returned: output.len(),
            output,
            state: receipt.initial.map(|_| 0),
            public_write: None,
        };
        if *receipt != canonical {
            let observations: Vec<_> = [0, 1, 63]
                .into_iter()
                .map(|alignment| {
                    decode_call(
                        &abi,
                        &receipt.input,
                        receipt.raw,
                        receipt.initial,
                        alignment,
                        false,
                        None,
                    )
                })
                .collect::<Result<_, _>>()?;
            let row = &mut receipts.corrections[3];
            if row.get("related_tail_cases").is_none() {
                row["related_tail_cases"] = json!([]);
            }
            row["related_tail_cases"].as_array_mut().unwrap().push(json!({"cause":"same approved public-call tail early-return cause","reference_alignments":[0,1,63],"observations":observations,"canonical":canonical}));
            *receipt = canonical;
        }
    }
    let encoded = pack(&receipts)?;
    abi._library.unchanged()?;
    use std::io::Write;
    let mut file = std::fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(destination)
        .map_err(|e| e.to_string())?;
    file.write_all(&encoded).map_err(|e| e.to_string())?;
    file.sync_all().map_err(|e| e.to_string())?;
    Ok(
        json!({"receipt_sha256":file_sha256(destination)?, "decode_cases":receipts.decode.len(),
        "incremental_cases":receipts.incremental.len(), "field_cases":receipts.fields.len(),
        "crc_cases":receipts.crc.len(), "size_cases":receipts.sizes.len(), "corrected_cases":receipts.corrections.len(),
        "expected_replay_calls":receipts.decode.len()*192+receipts.decode.iter().filter(|r|r.initial.is_none() && r.raw==1).count()+receipts.incremental.len()*12+receipts.fields.len()+receipts.crc.len()*64+receipts.sizes.len()}),
    )
}

fn field_call(abi: &Abi, receipt: &FieldReceipt) -> u32 {
    unsafe {
        match receipt.operation.as_str() {
            "combine" => (abi.combine)(receipt.first, receipt.second, receipt.length),
            "zeros" => (abi.zeros)(receipt.first, receipt.length),
            "unzero" => (abi.unzero)(receipt.first, receipt.length),
            "multiply" => (abi.multiply)(receipt.first, receipt.second),
            "2pow" => (abi.pow)(receipt.exponent),
            "256pow" => (abi.byte_pow)(receipt.length),
            _ => unreachable!("validated field operation"),
        }
    }
}

pub fn check(candidate: &Path, source: &Path) -> Result<Value, String> {
    if std::fs::metadata(source).map_err(|e| e.to_string())?.len() > 64 * 1024 * 1024 {
        return Err("receipt file exceeds 64 MiB".into());
    }
    use std::io::Read;
    let mut bytes = vec![];
    std::fs::File::open(source)
        .map_err(|e| e.to_string())?
        .take(64 * 1024 * 1024 + 1)
        .read_to_end(&mut bytes)
        .map_err(|e| e.to_string())?;
    use sha2::{Digest, Sha256};
    let receipt_sha256 = format!("{:x}", Sha256::digest(&bytes));
    if bytes.len() > 64 * 1024 * 1024 || receipt_sha256 != FROZEN_RECEIPT_SHA256 {
        return Err("receipt hash differs from reviewed frozen reference corpus".into());
    }
    let receipts = unpack(&bytes)?;
    if receipts.format != "nntmux-rapidyenc-abi-v1" || receipts.pointer_bits != usize::BITS as usize
    {
        return Err("unsupported receipt format or pointer width".into());
    }
    if receipts.decode.is_empty()
        || receipts.incremental.is_empty()
        || receipts.fields.is_empty()
        || receipts.crc.is_empty()
        || receipts.sizes.is_empty()
    {
        return Err("required receipt class is empty".into());
    }
    for receipt in &receipts.incremental {
        validate_incremental(receipt)?;
    }
    for receipt in &receipts.decode {
        if receipt.input.len() > 16 * 1024 * 1024
            || receipt.returned != receipt.output.len()
            || receipt.returned > receipt.input.len()
            || receipt
                .initial
                .is_some_and(|state| !(0..7).contains(&state))
            || receipt.state.is_some_and(|state| !(0..7).contains(&state))
            || receipt.initial.is_some() != receipt.state.is_some()
        {
            return Err("invalid decoder receipt bounds or state".into());
        }
    }
    if receipts
        .crc
        .iter()
        .any(|receipt| receipt.input.len() > 16 * 1024 * 1024)
        || receipts.corrections.len() != 4
        || receipts.sizes.iter().any(|receipt| receipt.line == 0)
    {
        return Err("invalid CRC, correction ledger or size receipt".into());
    }
    let identity = verify_library(candidate, true)?;
    let abi = Abi::open(candidate)?;
    if identity["sha256"].as_str() != Some(abi._library.sha256.as_str()) {
        return Err("candidate changed between verification and ABI replay".into());
    }
    let mut calls = 0;
    for expected in &receipts.decode {
        for alignment in 0..64 {
            for backward in [None, Some(0), Some(1)] {
                let actual = decode_call(
                    &abi,
                    &expected.input,
                    expected.raw,
                    expected.initial,
                    alignment,
                    false,
                    backward,
                )?;
                if &actual != expected {
                    return Err(format!(
                        "decode mismatch at alignment {alignment}: expected {expected:?}; actual {actual:?}"
                    ));
                }
                calls += 1;
            }
        }
        if expected.initial.is_none() && expected.raw == 1 {
            let actual = decode_call(&abi, &expected.input, 1, None, 1, true, None)?;
            if &actual != expected {
                return Err(format!("decode alias mismatch: {expected:?}"));
            }
            calls += 1;
        }
    }
    for expected in &receipts.incremental {
        for alignment in [0, 1, 7, 15, 31, 63] {
            for in_place in [false, true] {
                let actual = incremental_call(
                    &abi,
                    &expected.input,
                    expected.initial,
                    expected.split,
                    alignment,
                    in_place,
                )?;
                if &actual != expected {
                    return Err(format!(
                        "incremental mismatch, alignment={alignment}, in_place={in_place}: expected {expected:?}; actual {actual:?}"
                    ));
                }
                calls += 1;
            }
        }
    }
    for expected in &receipts.fields {
        if !["combine", "zeros", "unzero", "multiply", "2pow", "256pow"]
            .contains(&expected.operation.as_str())
        {
            return Err("unknown field operation".into());
        }
        if field_call(&abi, expected) != expected.expected {
            return Err(format!("field operation {} mismatch", expected.operation));
        }
        calls += 1;
    }
    for expected in &receipts.crc {
        for alignment in 0..64 {
            let (storage, offset) = aligned(&expected.input, alignment);
            let actual = unsafe {
                (abi.crc)(
                    storage.as_ptr().add(offset).cast(),
                    expected.input.len(),
                    expected.seed,
                )
            };
            if actual != expected.expected {
                return Err(format!(
                    "CRC mismatch length={} seed={} alignment={alignment}",
                    expected.input.len(),
                    expected.seed
                ));
            }
            calls += 1;
        }
    }
    for expected in &receipts.sizes {
        if unsafe { (abi.max_length)(expected.length, expected.line) } != expected.expected {
            return Err("encoded capacity arithmetic mismatch".into());
        }
        calls += 1;
    }
    extension_checks(&abi)?;
    abi._library.unchanged()?;
    if calls != FROZEN_REPLAY_CALLS {
        return Err("replay coverage differs from reviewed corpus protocol".into());
    }
    Ok(
        json!({"status":"ok", "artifact":identity, "receipt_sha256":receipt_sha256, "calls":calls,
        "scope":"all sixteen actual RapidYenc exports, native metadata export inventory, approved canonical exceptions"}),
    )
}

fn extension_checks(abi: &Abi) -> Result<(), String> {
    let mut storage = *b"klmzzzzz";
    let original = storage;
    let mut state = 7;
    let invalid = unsafe {
        (abi.decode)(
            0,
            storage.as_ptr().cast(),
            storage.as_mut_ptr().cast(),
            3,
            &mut state,
        )
    };
    if invalid != usize::MAX || storage != original || state != 7 {
        return Err("invalid-state validation wrote data/state".into());
    }
    state = 0;
    let invalid = unsafe {
        (abi.decode)(
            0,
            storage.as_ptr().cast(),
            storage.as_mut_ptr().add(1).cast(),
            3,
            &mut state,
        )
    };
    if invalid != usize::MAX || storage != original || state != 0 {
        return Err("forward overlap was not rejected before writes".into());
    }
    let invalid = unsafe {
        (abi.decode)(
            0,
            std::ptr::null(),
            storage.as_mut_ptr().cast(),
            3,
            &mut state,
        )
    };
    if invalid != usize::MAX || storage != original {
        return Err("null source was not rejected before writes".into());
    }
    let invalid = unsafe { (abi.decode)(0, std::ptr::null(), std::ptr::null_mut(), 0, &mut state) };
    if invalid != 0 || state != 0 {
        return Err("empty decode changed state".into());
    }
    if unsafe { (abi.crc)(std::ptr::null(), usize::MAX, 0x13579bdf) } != 0x13579bdf {
        return Err("invalid CRC changed seed".into());
    }
    if unsafe { (abi.max_length)(1, 0) } != usize::MAX {
        return Err("zero line capacity not rejected".into());
    }
    let mut source = storage.as_ptr().cast::<c_void>();
    let pointer = &mut source as *mut *const c_void;
    let end = unsafe { (abi.incremental)(pointer, pointer.cast(), 3, &mut state) };
    if end != 0 || source != storage.as_ptr().cast() || storage != original || state != 0 {
        return Err("aliased incremental pointer storage changed state/data".into());
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn compact_receipts_share_bytes_without_dropping_cases() {
        let receipt = DecodeReceipt {
            input: b"klm".to_vec(),
            raw: 0,
            initial: None,
            returned: 3,
            output: b"ABC".to_vec(),
            state: None,
            public_write: None,
        };
        let receipts = Receipts {
            format: "nntmux-rapidyenc-abi-v1".into(),
            pointer_bits: usize::BITS as usize,
            reference: json!({}),
            decode: vec![receipt.clone(), receipt],
            incremental: vec![],
            fields: vec![],
            crc: vec![],
            sizes: vec![],
            corrections: vec![],
        };
        let bytes = pack(&receipts).unwrap();
        let mut compact: CompactReceipts = serde_json::from_slice(&bytes).unwrap();
        assert_eq!(compact.blobs.len(), 2);
        assert_eq!(unpack(&bytes).unwrap().decode, receipts.decode);
        compact.receipts["decode"][0]["input"] = json!(999);
        assert!(unpack(&serde_json::to_vec(&compact).unwrap()).is_err());
    }

    #[test]
    fn malformed_incremental_receipts_are_rejected_before_foreign_calls() {
        let mut receipt = IncrementalReceipt {
            input: b"klm".to_vec(),
            initial: Some(0),
            split: 4,
            end: 0,
            consumed: 3,
            output: b"ABC".to_vec(),
            state: Some(3),
        };
        assert!(validate_incremental(&receipt).is_err());
        receipt.split = 2;
        assert!(validate_incremental(&receipt).is_ok());
        receipt.consumed = 4;
        assert!(validate_incremental(&receipt).is_err());
        receipt.consumed = 3;
        receipt.initial = Some(7);
        assert!(validate_incremental(&receipt).is_err());
    }

    #[test]
    fn fixture_alphabet_retains_all_escaped_byte_values() {
        let inputs = inputs();
        for byte in 0..=255 {
            assert!(inputs.contains(&vec![b'=', byte]));
        }
        assert!(inputs.contains(&b"\r\n.".to_vec()));
        assert!(inputs.contains(&b"=\r\n".to_vec()));
    }
}
