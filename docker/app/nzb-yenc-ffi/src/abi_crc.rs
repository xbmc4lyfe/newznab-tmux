//! CRC-32 and reflected-field operations for the RapidYenc ABI.
//!
//! Reflected field elements use bit 31 for x^0. CRC values are finalized
//! (complemented); multiply and powers operate on uncomplemented field elements.
use std::ffi::{c_int, c_void};
use std::sync::OnceLock;
use std::sync::atomic::{AtomicPtr, Ordering};

const POLYNOMIAL: u32 = 0xedb8_8320;
const IDENTITY: u32 = 0x8000_0000;
const FIELD_PERIOD: u64 = u32::MAX as u64;

struct Kernel {
    isa: c_int,
    digest: unsafe fn(&[u8], u32) -> u32,
    multiply: unsafe extern "C" fn(u32, u32) -> u32,
}

fn kernel() -> &'static Kernel {
    static SELECTED: OnceLock<Kernel> = OnceLock::new();
    SELECTED.get_or_init(|| {
        #[cfg(target_arch = "aarch64")]
        if std::arch::is_aarch64_feature_detected!("crc") {
            if std::arch::is_aarch64_feature_detected!("pmull") {
                return Kernel {
                    isa: 0x48,
                    digest: arm_parallel_crc,
                    multiply: arm_multiply,
                };
            }
            return Kernel {
                isa: 8,
                digest: arm_crc,
                multiply: multiply_scalar,
            };
        }
        #[cfg(all(
            any(target_arch = "x86", target_arch = "x86_64"),
            target_feature = "sse2"
        ))]
        if std::arch::is_x86_feature_detected!("pclmulqdq")
            && std::arch::is_x86_feature_detected!("sse2")
            && std::arch::is_x86_feature_detected!("sse4.1")
            && std::arch::is_x86_feature_detected!("ssse3")
        {
            // Locked crc32fast 1.5.2 enables its wide paths on Rust >=1.89;
            // both supported project toolchains satisfy that build condition.
            // 0x440 identifies VPCLMUL, independent of AVX2/AVX512 vector width.
            let wide = std::arch::is_x86_feature_detected!("vpclmulqdq")
                && (std::arch::is_x86_feature_detected!("avx512f")
                    || std::arch::is_x86_feature_detected!("avx2"));
            return Kernel {
                isa: if wide { 0x440 } else { 0x340 },
                digest: x86_crc,
                multiply: multiply_scalar,
            };
        }
        Kernel {
            isa: 0,
            digest: portable_crc,
            multiply: multiply_scalar,
        }
    })
}

// Function addresses need no associated data: relaxed atomic publication is
// sufficient, and competing first callers select the same immutable kernel.
// Guarded C resolver entries preserve lazy initialization before crc_init,
// allowing hot callers to load and tail-call without a null branch or frame.
static MULTIPLY: AtomicPtr<()> = AtomicPtr::new(resolve_multiply as *mut ());

fn publish_utilities(selected: &Kernel) {
    MULTIPLY.store(selected.multiply as *mut (), Ordering::Relaxed);
    #[cfg(target_arch = "aarch64")]
    if selected.isa == 0x48 {
        COMBINE.store(arm_combine as *mut (), Ordering::Relaxed);
        ZEROS.store(arm_zeros as *mut (), Ordering::Relaxed);
        UNZERO.store(arm_unzero as *mut (), Ordering::Relaxed);
        POWER2.store(arm_power2 as *mut (), Ordering::Relaxed);
        POWER256.store(arm_power256 as *mut (), Ordering::Relaxed);
        return;
    }
    COMBINE.store(combine_scalar as *mut (), Ordering::Relaxed);
    ZEROS.store(zeros_scalar as *mut (), Ordering::Relaxed);
    UNZERO.store(unzero_scalar as *mut (), Ordering::Relaxed);
    POWER2.store(power2_scalar as *mut (), Ordering::Relaxed);
    POWER256.store(power256_scalar as *mut (), Ordering::Relaxed);
}

#[cold]
fn resolve_or(fallback: u32, resolver: impl FnOnce() -> u32 + std::panic::UnwindSafe) -> u32 {
    match std::panic::catch_unwind(resolver) {
        Ok(value) => value,
        Err(payload) => {
            // A custom payload may panic when dropped. Retain that exceptional
            // payload so its destructor cannot unwind across the C resolver.
            std::mem::forget(payload);
            fallback
        }
    }
}

#[cold]
extern "C" fn resolve_multiply(a: u32, b: u32) -> u32 {
    resolve_or(0, || {
        let selected = kernel();
        publish_utilities(selected);
        // SAFETY: kernel() checks the features of the selected function.
        unsafe { (selected.multiply)(a, b) }
    })
}

pub fn crc_init() {
    publish_utilities(kernel());
}

/// Selected ISA family, using the original header's numeric values.
pub fn crc_kernel() -> c_int {
    kernel().isa
}

#[cfg(all(
    any(target_arch = "x86", target_arch = "x86_64"),
    target_feature = "sse2"
))]
fn x86_crc(bytes: &[u8], seed: u32) -> u32 {
    let mut hasher = crc32fast::Hasher::new_with_initial(seed);
    hasher.update(bytes);
    hasher.finalize()
}

/// Compute a finalized CRC-32, continuing from a finalized seed.
///
/// # Safety
/// A nonnull source with a nonzero representable length must point to that
/// many readable bytes in one allocation. Null or unrepresentable inputs
/// return the seed without reading memory.
pub unsafe fn crc(source: *const c_void, length: usize, seed: u32) -> u32 {
    if source.is_null() || length == 0 || length > isize::MAX as usize {
        return seed;
    }
    // SAFETY: the caller guarantees readable storage after the checks above.
    let bytes = unsafe { std::slice::from_raw_parts(source.cast::<u8>(), length) };
    // SAFETY: the selector checks every required CPU feature.
    unsafe { (kernel().digest)(bytes, seed) }
}

const extern "C" fn multiply_scalar(mut a: u32, mut b: u32) -> u32 {
    let mut result = 0;
    let mut bit = 0;
    while bit < 32 {
        if b & IDENTITY != 0 {
            result ^= a;
        }
        a = (a >> 1) ^ (POLYNOMIAL & 0u32.wrapping_sub(a & 1));
        b <<= 1;
        bit += 1;
    }
    result
}

pub fn crc_multiply(a: u32, b: u32) -> u32 {
    let pointer = MULTIPLY.load(Ordering::Relaxed);
    // SAFETY: the cache always holds a function with this exact C signature:
    // initially a resolver containing all initialization in resolve_or, and
    // subsequently a selected non-panicking numeric handler.
    let multiply: unsafe extern "C" fn(u32, u32) -> u32 = unsafe { std::mem::transmute(pointer) };
    unsafe { multiply(a, b) }
}

const fn powers() -> [u32; 32] {
    let mut result = [0; 32];
    result[0] = IDENTITY >> 1;
    let mut i = 1;
    while i < 32 {
        result[i] = multiply_scalar(result[i - 1], result[i - 1]);
        i += 1;
    }
    result
}
const POWERS: [u32; 32] = powers();

const fn shift_tables() -> [[u32; 256]; 4] {
    let mut tables = [[IDENTITY; 256]; 4];
    let mut group = 0;
    while group < 4 {
        let mut value = 1usize;
        while value < 256 {
            let bit = value.trailing_zeros() as usize;
            tables[group][value] =
                multiply_scalar(tables[group][value & (value - 1)], POWERS[group * 8 + bit]);
            value += 1;
        }
        group += 1;
    }
    tables
}
const SHIFT_TABLES: [[u32; 256]; 4] = shift_tables();

fn shift_scalar(mut value: u32, exponent: u32) -> u32 {
    // A byte of exponent per lookup bounds the operation to four products.
    for (table, byte) in SHIFT_TABLES.iter().zip(exponent.to_le_bytes()) {
        if byte != 0 {
            value = multiply_scalar(value, table[usize::from(byte)]);
        }
    }
    value
}

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "aes,crc")]
#[inline]
unsafe fn arm_shift(mut value: u32, exponent: u32) -> u32 {
    // Keep all products in the selected feature context so they inline rather
    // than calling the multiplier indirectly for each nonzero exponent byte.
    for (table, byte) in SHIFT_TABLES.iter().zip(exponent.to_le_bytes()) {
        if byte != 0 {
            // SAFETY: this function requires both PMULL and CRC.
            value = unsafe { arm_multiply(value, table[usize::from(byte)]) };
        }
    }
    value
}

fn power_scalar(exponent: u32) -> u32 {
    let mut value = IDENTITY;
    let mut first = true;
    for (table, byte) in SHIFT_TABLES.iter().zip(exponent.to_le_bytes()) {
        if byte != 0 {
            let factor = table[usize::from(byte)];
            value = if first {
                factor
            } else {
                multiply_scalar(value, factor)
            };
            first = false;
        }
    }
    value
}

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "aes,crc")]
#[inline]
unsafe fn arm_power(exponent: u32) -> u32 {
    // IDENTITY * the first factor is that factor: powers need at most three
    // products and a single nonzero exponent byte needs no product at all.
    let mut value = IDENTITY;
    let mut first = true;
    for (table, byte) in SHIFT_TABLES.iter().zip(exponent.to_le_bytes()) {
        if byte != 0 {
            let factor = table[usize::from(byte)];
            value = if first {
                factor
            } else {
                // SAFETY: this function requires both PMULL and CRC.
                unsafe { arm_multiply(value, factor) }
            };
            first = false;
        }
    }
    value
}

fn byte_exponent(length: u64) -> u32 {
    // Rotate the exponent modulo 2^32-1, without overflowing length * 8.
    ((length % FIELD_PERIOD) as u32).rotate_left(3)
}

// Each utility dispatches once, with exponent reduction and all products in
// the selected context. Resolver functions also cover calls before crc_init.
// The C-ABI leaf bodies allocate nothing: shifts have fixed valid bit counts,
// the modulus is a nonzero constant, and every table index is an exponent byte
// (0..=255) into an exact 256-entry array. They cannot panic for integer inputs;
// potentially panicking feature detection/OnceLock work stays in resolve_or.
macro_rules! utility {
    ($public:ident, $cache:ident, $resolver:ident, $scalar:ident, $arm:ident,
     ($($arg:ident: $ty:ty),*), $fallback:expr, $power:literal, $shift:ident, { $($body:tt)* }) => {
        static $cache: AtomicPtr<()> = AtomicPtr::new($resolver as *mut ());

        pub fn $public($($arg: $ty),*) -> u32 {
            let pointer = $cache.load(Ordering::Relaxed);
            // SAFETY: this cache always holds a C function with this exact
            // signature: the guarded resolver or a checked numeric handler.
            let selected: unsafe extern "C" fn($($ty),*) -> u32 =
                unsafe { std::mem::transmute(pointer) };
            unsafe { selected($($arg),*) }
        }

        #[cold]
        extern "C" fn $resolver($($arg: $ty),*) -> u32 {
            resolve_or($fallback, || {
                publish_utilities(kernel());
                $public($($arg),*)
            })
        }

        extern "C" fn $scalar($($arg: $ty),*) -> u32 {
            let $shift = |value, exponent| {
                if $power { power_scalar(exponent) } else { shift_scalar(value, exponent) }
            };
            $($body)*
        }

        #[cfg(target_arch = "aarch64")]
        #[target_feature(enable = "aes,crc")]
        unsafe extern "C" fn $arm($($arg: $ty),*) -> u32 {
            // SAFETY: this function requires both PMULL and CRC.
            let $shift = |value, exponent| unsafe {
                if $power { arm_power(exponent) } else { arm_shift(value, exponent) }
            };
            $($body)*
        }
    };
}

utility!(crc_2pow, POWER2, resolve_power2, power2_scalar, arm_power2,
(exponent: i64), 0, true, shift, {
    // unsigned_abs covers MIN; complement is field-period negation.
    let magnitude = (exponent.unsigned_abs() % FIELD_PERIOD) as u32;
    shift(IDENTITY, if exponent < 0 { !magnitude } else { magnitude })
});
utility!(crc_256pow, POWER256, resolve_power256, power256_scalar, arm_power256,
    (length: u64), 0, true, shift, { shift(IDENTITY, byte_exponent(length)) });
utility!(crc_combine, COMBINE, resolve_combine, combine_scalar, arm_combine,
(first: u32, second: u32, length: u64), 0, false, shift, {
    shift(first, byte_exponent(length)) ^ second
});
utility!(crc_zeros, ZEROS, resolve_zeros, zeros_scalar, arm_zeros,
    (value: u32, length: u64), value, false, shift, { !shift(!value, byte_exponent(length)) });
utility!(crc_unzero, UNZERO, resolve_unzero, unzero_scalar, arm_unzero,
    (value: u32, length: u64), value, false, shift, { !shift(!value, !byte_exponent(length)) });

const fn byte_table() -> [u32; 256] {
    let mut table = [0; 256];
    let mut i = 0;
    while i < 256 {
        let mut value = i as u32;
        let mut bit = 0;
        while bit < 8 {
            value = (value >> 1) ^ (POLYNOMIAL & 0u32.wrapping_sub(value & 1));
            bit += 1;
        }
        table[i] = value;
        i += 1;
    }
    table
}
const BYTE_TABLE: [u32; 256] = byte_table();

const fn slice_tables() -> [[u32; 256]; 8] {
    let mut tables = [[0; 256]; 8];
    tables[0] = BYTE_TABLE;
    let mut slice = 1;
    while slice < 8 {
        let mut byte = 0;
        while byte < 256 {
            let prior = tables[slice - 1][byte];
            tables[slice][byte] = (prior >> 8) ^ BYTE_TABLE[(prior & 255) as usize];
            byte += 1;
        }
        slice += 1;
    }
    tables
}
const SLICE_TABLES: [[u32; 256]; 8] = slice_tables();

fn portable_crc(bytes: &[u8], seed: u32) -> u32 {
    let mut value = !seed;
    let (chunks, tail) = bytes.as_chunks::<8>();
    for bytes in chunks {
        let low = value ^ u32::from_le_bytes([bytes[0], bytes[1], bytes[2], bytes[3]]);
        value = SLICE_TABLES[7][(low & 255) as usize]
            ^ SLICE_TABLES[6][((low >> 8) & 255) as usize]
            ^ SLICE_TABLES[5][((low >> 16) & 255) as usize]
            ^ SLICE_TABLES[4][(low >> 24) as usize]
            ^ SLICE_TABLES[3][usize::from(bytes[4])]
            ^ SLICE_TABLES[2][usize::from(bytes[5])]
            ^ SLICE_TABLES[1][usize::from(bytes[6])]
            ^ SLICE_TABLES[0][usize::from(bytes[7])];
    }
    for &byte in tail {
        value = (value >> 8) ^ BYTE_TABLE[((value as u8) ^ byte) as usize];
    }
    !value
}

#[cfg(test)]
mod tests {
    use super::*;

    // Independent model: ordinary polynomial coefficients, long division by
    // x^32 + x^26 + ... + 1, then convert to/from the ABI's reflected bits.
    pub(super) fn polynomial_product(a: u32, b: u32) -> u32 {
        let mut product = 0u64;
        for bit in 0..32 {
            if b & (1 << bit) != 0 {
                product ^= (a as u64) << bit;
            }
        }
        for bit in (32..64).rev() {
            if product & (1u64 << bit) != 0 {
                product ^= 0x1_04c1_1db7u64 << (bit - 32);
            }
        }
        product as u32
    }

    fn polynomial_power(mut base: u32, mut exponent: u128) -> u32 {
        let mut result = 1;
        while exponent != 0 {
            if exponent & 1 != 0 {
                result = polynomial_product(result, base);
            }
            base = polynomial_product(base, base);
            exponent >>= 1;
        }
        result.reverse_bits()
    }

    pub(super) fn bitwise_crc(bytes: &[u8], seed: u32) -> u32 {
        let mut value = !seed;
        for &byte in bytes {
            value ^= u32::from(byte);
            for _ in 0..8 {
                value = if value & 1 == 1 {
                    (value >> 1) ^ 0xedb8_8320
                } else {
                    value >> 1
                };
            }
        }
        !value
    }

    fn digest(bytes: &[u8], seed: u32) -> u32 {
        unsafe { crc(bytes.as_ptr().cast(), bytes.len(), seed) }
    }

    #[test]
    fn crc_known_answers_and_seeded_continuation() {
        assert_eq!(digest(b"ABC", 0), 0xa383_0348);
        assert_eq!(digest(b"123456789", 0), 0xcbf4_3926);
        assert_eq!(
            digest(b"DEF", digest(b"ABC", 0x1234_5678)),
            bitwise_crc(b"ABCDEF", 0x1234_5678)
        );
    }

    #[test]
    fn field_product_preserves_reflected_identity_and_polynomial_reduction() {
        for a in [0, 1, 0x8000_0000, 0x4000_0000, 0xffff_ffff, 0x1234_5678] {
            for b in [0, 1, 0x8000_0000, 0x4000_0000, 0xffff_ffff, 0x9abc_def0] {
                assert_eq!(
                    crc_multiply(a, b),
                    polynomial_product(a.reverse_bits(), b.reverse_bits()).reverse_bits()
                );
            }
        }
    }

    #[test]
    fn powers_match_independent_polynomials_at_signed_and_unsigned_boundaries() {
        for exponent in [
            i64::MIN,
            i64::MIN + 1,
            -4294967296,
            -4294967295,
            -33,
            -1,
            0,
            1,
            31,
            32,
            4294967295,
            4294967296,
            i64::MAX,
        ] {
            let base = if exponent < 0 { 0x8260_8edb } else { 2 };
            // Unsigned magnitude defines MIN without any signed negation.
            let expected = polynomial_power(base, u128::from(exponent.unsigned_abs()));
            assert_eq!(crc_2pow(exponent), expected, "exponent {exponent}");
        }
        for length in [0, 1, 7, 32, u32::MAX as u64, 1 << 32, 1 << 63, u64::MAX] {
            assert_eq!(
                crc_256pow(length),
                polynomial_power(2, u128::from(length) * 8),
                "length {length}"
            );
        }
    }

    #[test]
    fn combine_zero_and_unzero_obey_digest_and_huge_length_identities() {
        assert_eq!(
            crc_combine(digest(b"ABC", 0), digest(b"DEF", 0), 3),
            bitwise_crc(b"ABCDEF", 0)
        );
        for seed in [0, 1, 0x8000_0000, 0xffff_ffff, 0x1234_5678] {
            for length in [0, 1, 7, 32, 511] {
                assert_eq!(
                    crc_zeros(seed, length),
                    bitwise_crc(&vec![0; length as usize], seed)
                );
            }
            for length in [0, 1, u32::MAX as u64, 1 << 32, 1 << 63, u64::MAX] {
                let factor = polynomial_power(2, u128::from(length) * 8);
                let expected = !polynomial_product((!seed).reverse_bits(), factor.reverse_bits())
                    .reverse_bits();
                assert_eq!(crc_zeros(seed, length), expected);
                assert_eq!(crc_unzero(crc_zeros(seed, length), length), seed);
                assert_eq!(crc_zeros(crc_unzero(seed, length), length), seed);
                assert_eq!(
                    crc_combine(seed, 0x7654_3210, length),
                    polynomial_product(seed.reverse_bits(), factor.reverse_bits()).reverse_bits()
                        ^ 0x7654_3210
                );
            }
        }
        assert_eq!(
            crc_multiply(crc_2pow(i64::MIN), crc_2pow(i64::MAX)),
            crc_2pow(-1)
        );
    }

    #[test]
    fn portable_and_dispatched_crc_match_bitwise_at_every_short_length() {
        let data: Vec<u8> = (0..(512 + 64))
            .map(|i| ((i * 173 + i / 11) & 255) as u8)
            .collect();
        for offset in 0..64 {
            for length in 0..512 {
                let bytes = &data[offset..offset + length];
                let seed = (offset * 100003 + length * 101) as u32;
                let expected = bitwise_crc(bytes, seed);
                assert_eq!(portable_crc(bytes, seed), expected);
                assert_eq!(digest(bytes, seed), expected);
            }
        }
    }

    #[test]
    fn specialized_shifts_match_independent_products_for_each_exponent_byte() {
        for group in 0..4 {
            for byte in 0..256u32 {
                let exponent = byte << (group * 8);
                let factor = polynomial_power(2, u128::from(exponent));
                for value in [0, 1, IDENTITY, u32::MAX, 0x1234_5678] {
                    let expected = polynomial_product(value.reverse_bits(), factor.reverse_bits())
                        .reverse_bits();
                    assert_eq!(shift_scalar(value, exponent), expected);
                    assert_eq!(crc_multiply(value, crc_2pow(i64::from(exponent))), expected);
                    #[cfg(target_arch = "aarch64")]
                    if std::arch::is_aarch64_feature_detected!("crc")
                        && std::arch::is_aarch64_feature_detected!("pmull")
                    {
                        assert_eq!(unsafe { arm_shift(value, exponent) }, expected);
                    }
                }
            }
        }
    }

    #[test]
    fn power_specializations_match_independent_model_for_sparse_and_dense_exponents() {
        let mut exponents = vec![
            0,
            1,
            0xff,
            0x100,
            0xff00,
            0x10000,
            0x1000000,
            0xdead_beef,
            u32::MAX,
        ];
        let mut random = 0x5e4f_19a2u32;
        for _ in 0..256 {
            random = random.wrapping_mul(1664525).wrapping_add(1013904223);
            exponents.push(random);
        }
        for exponent in exponents {
            let expected = polynomial_power(2, u128::from(exponent));
            assert_eq!(power_scalar(exponent), expected);
            assert_eq!(crc_2pow(i64::from(exponent)), expected);
            #[cfg(target_arch = "aarch64")]
            if std::arch::is_aarch64_feature_detected!("crc")
                && std::arch::is_aarch64_feature_detected!("pmull")
            {
                assert_eq!(unsafe { arm_power(exponent) }, expected);
            }
        }
    }

    #[test]
    fn cold_resolution_panics_preserve_all_six_failure_sentinels() {
        for (operation, fallback) in [
            ("multiply", 0),
            ("combine", 0),
            ("2pow", 0),
            ("256pow", 0),
            ("zeros", 0x1234_5678),
            ("unzero", 0x9abc_def0),
        ] {
            let outcome = std::panic::catch_unwind(|| {
                resolve_or(fallback, || {
                    panic!("simulated {operation} resolution failure")
                })
            });
            assert_eq!(outcome.ok(), Some(fallback), "{operation}");
        }
    }

    #[test]
    fn cold_resolution_contains_panicking_payload_destructors() {
        struct PanickingDrop;
        impl Drop for PanickingDrop {
            fn drop(&mut self) {
                panic!("payload destructor panicked");
            }
        }
        for fallback in [0, 0x1234_5678, 0x9abc_def0] {
            let outcome = std::panic::catch_unwind(|| {
                resolve_or(fallback, || std::panic::panic_any(PanickingDrop))
            });
            assert_eq!(outcome.ok(), Some(fallback));
        }
    }

    #[test]
    fn exported_utilities_resolve_guarded_entries_in_fresh_processes() {
        const CHILD_OPERATION: &str = "NNTMUX_CRC_TEST_COLD_OPERATION";
        let Ok(operation) = std::env::var(CHILD_OPERATION) else {
            for operation in ["multiply", "combine", "zeros", "unzero", "2pow", "256pow"] {
                let output = std::process::Command::new(std::env::current_exe().unwrap())
                    .args([
                        "--exact",
                        "abi_crc::tests::exported_utilities_resolve_guarded_entries_in_fresh_processes",
                        "--nocapture",
                    ])
                    .env(CHILD_OPERATION, operation)
                    .output()
                    .unwrap();
                assert!(
                    output.status.success(),
                    "{operation}: {}{}",
                    String::from_utf8_lossy(&output.stdout),
                    String::from_utf8_lossy(&output.stderr)
                );
            }
            return;
        };
        // Each child has fresh library statics and calls exactly one actual
        // exported utility before initialization. Resolver entries must always
        // have the cache's C signature; no null value is safe on the hot path.
        for (cache, resolver) in [
            (&MULTIPLY, resolve_multiply as *mut ()),
            (&COMBINE, resolve_combine as *mut ()),
            (&ZEROS, resolve_zeros as *mut ()),
            (&UNZERO, resolve_unzero as *mut ()),
            (&POWER2, resolve_power2 as *mut ()),
            (&POWER256, resolve_power256 as *mut ()),
        ] {
            assert_eq!(cache.load(Ordering::Relaxed), resolver);
        }
        let first = 0x1234_5678u32;
        let second = 0x9abc_def0u32;
        let length = u64::MAX - 12345;
        let product =
            |a: u32, b: u32| polynomial_product(a.reverse_bits(), b.reverse_bits()).reverse_bits();
        let factor = polynomial_power(2, u128::from(length) * 8);
        let (actual, expected) = match operation.as_str() {
            "multiply" => (
                crate::rapidyenc_crc_multiply(first, second),
                product(first, second),
            ),
            "combine" => (
                crate::rapidyenc_crc_combine(first, second, length),
                product(first, factor) ^ second,
            ),
            "zeros" => (
                crate::rapidyenc_crc_zeros(first, length),
                !product(!first, factor),
            ),
            "unzero" => (
                crate::rapidyenc_crc_unzero(first, length),
                !product(
                    !first,
                    polynomial_power(0x8260_8edb, u128::from(length) * 8),
                ),
            ),
            "2pow" => (
                crate::rapidyenc_crc_2pow(i64::MIN),
                polynomial_power(0x8260_8edb, u128::from(i64::MIN.unsigned_abs())),
            ),
            "256pow" => (crate::rapidyenc_crc_256pow(length), factor),
            _ => panic!("unknown cold operation {operation}"),
        };
        assert_eq!(actual, expected, "{operation}");
        for cache in [&MULTIPLY, &COMBINE, &ZEROS, &UNZERO, &POWER2, &POWER256] {
            assert!(!cache.load(Ordering::Relaxed).is_null());
        }
        assert_eq!(crate::rapidyenc_crc_kernel(), kernel().isa);
    }

    #[test]
    fn concurrent_initialization_and_utilities_preserve_kernel_and_field_results() {
        std::thread::scope(|scope| {
            for worker in 0..16u32 {
                scope.spawn(move || {
                    for iteration in 0..128u32 {
                        let a = worker.wrapping_mul(0x9e37_79b9).wrapping_add(iteration);
                        let b = a.rotate_left(13) ^ 0xa5a5_5a5a;
                        assert_eq!(
                            crc_multiply(a, b),
                            polynomial_product(a.reverse_bits(), b.reverse_bits()).reverse_bits()
                        );
                        let exponent = a ^ b;
                        assert_eq!(
                            crc_multiply(a, crc_2pow(i64::from(exponent))),
                            shift_scalar(a, exponent)
                        );
                        let length = u64::from(exponent) << 32 | u64::from(a);
                        assert_eq!(crc_unzero(crc_zeros(a, length), length), a);
                        assert_eq!(
                            crc_combine(a, b, length),
                            crc_multiply(a, crc_256pow(length)) ^ b
                        );
                        crc_init();
                        assert_eq!(crc_kernel(), kernel().isa);
                    }
                });
            }
        });
    }

    #[test]
    fn invalid_crc_buffers_preserve_seed_without_reading() {
        for seed in [0, 0x1234_5678, u32::MAX] {
            unsafe {
                assert_eq!(crc(std::ptr::null(), 0, seed), seed);
                assert_eq!(crc(std::ptr::null(), 1, seed), seed);
                assert_eq!(crc(std::ptr::dangling::<u8>().cast(), 0, seed), seed);
                assert_eq!(
                    crc(
                        std::ptr::dangling::<u8>().cast(),
                        isize::MAX as usize + 1,
                        seed
                    ),
                    seed
                );
            }
        }
    }
}

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "crc")]
unsafe fn arm_crc(bytes: &[u8], seed: u32) -> u32 {
    use std::arch::aarch64::{__crc32b, __crc32d};
    let mut value = !seed;
    let (chunks, tail) = bytes.as_chunks::<64>();
    for chunk in chunks {
        for offset in (0..64).step_by(8) {
            // SAFETY: this exact 64-byte chunk contains each unaligned word.
            let word = unsafe { chunk.as_ptr().add(offset).cast::<u64>().read_unaligned() };
            value = __crc32d(value, u64::from_le(word));
        }
    }
    let (words, tail) = tail.as_chunks::<8>();
    for word in words {
        // SAFETY: this exact 8-byte chunk contains the unaligned word.
        value = __crc32d(
            value,
            u64::from_le(unsafe { word.as_ptr().cast::<u64>().read_unaligned() }),
        );
    }
    for &byte in tail {
        value = __crc32b(value, byte);
    }
    !value
}

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "aes,crc")]
unsafe fn arm_parallel_crc(bytes: &[u8], seed: u32) -> u32 {
    use std::arch::aarch64::__crc32d;
    // Four independent chains hide the hardware CRC instruction's latency.
    // Each chain spans 1024 bytes; the other chains start from raw zero.
    const STRIDE: usize = 1024;
    const FACTOR: u32 = POWERS[13]; // x^(1024 * 8)
    const FACTOR2: u32 = multiply_scalar(FACTOR, FACTOR);
    const FACTOR3: u32 = multiply_scalar(FACTOR2, FACTOR);
    let mut value = !seed;
    let (blocks, tail) = bytes.as_chunks::<{ STRIDE * 4 }>();
    for block in blocks {
        let mut a = value;
        let mut b = 0;
        let mut c = 0;
        let mut d = 0;
        for offset in (0..STRIDE).step_by(8) {
            // SAFETY: the block contains four complete STRIDE-byte lanes.
            unsafe {
                let ptr = block.as_ptr().add(offset);
                a = __crc32d(a, u64::from_le(ptr.cast::<u64>().read_unaligned()));
                b = __crc32d(
                    b,
                    u64::from_le(ptr.add(STRIDE).cast::<u64>().read_unaligned()),
                );
                c = __crc32d(
                    c,
                    u64::from_le(ptr.add(STRIDE * 2).cast::<u64>().read_unaligned()),
                );
                d = __crc32d(
                    d,
                    u64::from_le(ptr.add(STRIDE * 3).cast::<u64>().read_unaligned()),
                );
            }
        }
        // SAFETY: this function requires both CRC and PMULL.
        value = unsafe {
            arm_multiply(a, FACTOR3) ^ arm_multiply(b, FACTOR2) ^ arm_multiply(c, FACTOR) ^ d
        };
    }
    // SAFETY: CRC is enabled and the remainder is a valid slice.
    unsafe { arm_crc(tail, !value) }
}

#[cfg(target_arch = "aarch64")]
#[target_feature(enable = "aes,crc")]
#[inline]
unsafe extern "C" fn arm_multiply(a: u32, b: u32) -> u32 {
    let result: u32;
    // FMOV into an S register zeros its upper bits, constructing the same
    // 64-bit polynomial operands as zero-extending each u32 before PMULL.
    // The 32x32 product fits in 63 bits. CRC32W reduces its shifted low word;
    // XORing the upper reflected coefficients completes the field product.
    //
    // The product is early-clobber and cannot alias either input or result.
    // The result is written only after both inputs have been consumed; v0/v1
    // are explicit clobbers. These register-only instructions access no memory,
    // use no stack, and none writes NZCV flags. Feature checks precede dispatch.
    unsafe {
        std::arch::asm!(
            "fmov s0, {a:w}",
            "fmov s1, {b:w}",
            "pmull v0.1q, v0.1d, v1.1d",
            "fmov {product:x}, d0",
            "lsl {result:w}, {product:w}, #1",
            "crc32w {result:w}, wzr, {result:w}",
            "lsr {product:x}, {product:x}, #31",
            "eor {result:w}, {result:w}, {product:w}",
            a = in(reg) a,
            b = in(reg) b,
            product = out(reg) _,
            result = lateout(reg) result,
            out("v0") _,
            out("v1") _,
            options(pure, nomem, nostack, preserves_flags),
        );
    }
    result
}

#[cfg(all(test, target_arch = "aarch64"))]
mod arm_tests {
    use super::*;

    #[test]
    fn accelerated_kernels_match_bitwise_crc_across_alignments_blocks_and_tails() {
        let data: Vec<u8> = (0..(32768 + 64))
            .map(|i| ((i * 173 + i / 11) & 255) as u8)
            .collect();
        if !std::arch::is_aarch64_feature_detected!("crc") {
            return;
        }
        for offset in 0..64 {
            for len in [
                0, 1, 2, 3, 4, 7, 8, 15, 16, 31, 32, 63, 64, 65, 127, 128, 255, 256, 1023, 1024,
                4095, 4096, 4097, 8191, 8192, 16384, 32768,
            ] {
                let bytes = &data[offset..offset + len];
                for seed in [0, 0x1234_5678, u32::MAX] {
                    let expected = tests::bitwise_crc(bytes, seed);
                    unsafe {
                        assert_eq!(
                            arm_crc(bytes, seed),
                            expected,
                            "CRC len={len} offset={offset}"
                        );
                        if std::arch::is_aarch64_feature_detected!("pmull") {
                            assert_eq!(
                                arm_parallel_crc(bytes, seed),
                                expected,
                                "parallel len={len} offset={offset}"
                            );
                        }
                    }
                }
            }
        }
    }

    #[test]
    fn accelerated_multiply_matches_independent_polynomial_model() {
        if !std::arch::is_aarch64_feature_detected!("crc")
            || !std::arch::is_aarch64_feature_detected!("pmull")
        {
            return;
        }
        let mut random = 0x5e4f_19a2u32;
        for a in [0, 1, 0x8000_0000, u32::MAX, 0x1234_5678] {
            for _ in 0..1000 {
                random = random.wrapping_mul(1664525).wrapping_add(1013904223);
                assert_eq!(
                    unsafe { arm_multiply(a, random) },
                    tests::polynomial_product(a.reverse_bits(), random.reverse_bits())
                        .reverse_bits()
                );
            }
        }
    }
}
