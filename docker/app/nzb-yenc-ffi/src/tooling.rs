//! Artifact checks shared by the Rust qualification and installation commands.

use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::ffi::{CStr, CString, c_char, c_int, c_void};
use std::path::{Path, PathBuf};

/// Current disposition for outer command reports only. Historical validation,
/// embedded receipts and claimed proof never authorize replacement adoption.
pub fn with_adoption_eligibility(mut report: Value) -> Result<Value, String> {
    report
        .as_object_mut()
        .ok_or("outer report must be a JSON object")?
        .insert(
            "adoption_eligibility".into(),
            json!({"eligible":false,"policy":"common-and-uncommon-p99-1.5x",
                "reason":"current-policy evidence unavailable"}),
        );
    Ok(report)
}

pub const RAPIDYENC_EXPORTS: &[&str] = &[
    "rapidyenc_version",
    "rapidyenc_encode_max_length",
    "rapidyenc_decode_init",
    "rapidyenc_decode",
    "rapidyenc_decode_ex",
    "rapidyenc_decode_incremental",
    "rapidyenc_decode_kernel",
    "rapidyenc_crc_init",
    "rapidyenc_crc",
    "rapidyenc_crc_combine",
    "rapidyenc_crc_zeros",
    "rapidyenc_crc_unzero",
    "rapidyenc_crc_multiply",
    "rapidyenc_crc_2pow",
    "rapidyenc_crc_256pow",
    "rapidyenc_crc_kernel",
];

pub const NNTMUX_EXPORTS: &[&str] = &[
    "nntmux_yenc_supports_split_escapes",
    "nntmux_par2_metadata_json",
    "nntmux_format_metadata_json",
    "nntmux_article_dates_v1_json",
    "nntmux_seven_zip_ranges_json",
    "nntmux_par2_source_ranges_json",
    "nntmux_yenc_framing_metadata_json",
    "nntmux_nntp_header_metadata_json",
    "nntmux_nntp_header_pair_comparison_json",
    "nntmux_nntp_body_measurement_json",
    "nntmux_nntp_lines_body_relationship_json",
    "nntmux_article_identity_v2_json",
    "nntmux_article_identity_xref_v1_json",
    "nntmux_article_identity_json",
    "nntmux_metadata_string_free",
    "nntmux_spot_check_sample_v1_json",
    "nntmux_header_filename_identity_v1",
    "nntmux_header_filename_identity_tables_v1",
    "nntmux_yenc_decode_span_v1",
    "nntmux_yenc_payload_kernel_v1",
    "nntmux_release_names_html",
    "nntmux_release_names_free",
    "nntmux_article_fingerprint_new",
    "nntmux_article_fingerprint_add",
    "nntmux_article_fingerprint_value",
    "nntmux_article_fingerprint_free",
];

#[cfg_attr(target_os = "linux", link(name = "dl"))]
unsafe extern "C" {
    fn dlopen(path: *const c_char, flags: c_int) -> *mut c_void;
    fn dlsym(handle: *mut c_void, symbol: *const c_char) -> *mut c_void;
    fn dlclose(handle: *mut c_void) -> c_int;
    fn dlerror() -> *const c_char;
}

pub struct Library {
    handle: *mut c_void,
    pub path: PathBuf,
    pub sha256: String,
    snapshot: LibrarySnapshot,
}

struct LibrarySnapshot {
    directory: PathBuf,
    file: PathBuf,
}

impl Drop for LibrarySnapshot {
    fn drop(&mut self) {
        let _ = std::fs::remove_file(&self.file);
        let _ = std::fs::remove_dir(&self.directory);
    }
}

impl Library {
    pub fn open(path: &Path) -> Result<Self, String> {
        let path = path
            .canonicalize()
            .map_err(|e| format!("{}: {e}", path.display()))?;
        if !path.is_file() {
            return Err("library must be a regular file".into());
        }
        let sha256 = file_sha256(&path)?;
        use std::sync::atomic::{AtomicUsize, Ordering};
        static NEXT: AtomicUsize = AtomicUsize::new(0);
        let stamp = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map_err(|e| e.to_string())?
            .as_nanos();
        let directory = std::env::temp_dir().join(format!(
            "nntmux-library-{}-{stamp}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        use std::os::unix::fs::DirBuilderExt;
        std::fs::DirBuilder::new()
            .mode(0o700)
            .create(&directory)
            .map_err(|e| e.to_string())?;
        let snapshot = LibrarySnapshot {
            file: directory.join(path.file_name().ok_or("library needs filename")?),
            directory,
        };
        std::fs::copy(&path, &snapshot.file).map_err(|e| e.to_string())?;
        if file_sha256(&snapshot.file)? != sha256 || file_sha256(&path)? != sha256 {
            return Err("library changed while copying frozen load artifact".into());
        }
        #[cfg(unix)]
        let bytes = {
            use std::os::unix::ffi::OsStrExt;
            snapshot.file.as_os_str().as_bytes()
        };
        let name = CString::new(bytes).map_err(|_| "library path contains NUL")?;
        // RTLD_NOW | RTLD_LOCAL: resolve this artifact without exporting its
        // symbols into the other artifact's namespace.
        #[cfg(target_os = "macos")]
        const LOCAL: c_int = 4;
        #[cfg(target_os = "linux")]
        const LOCAL: c_int = 0;
        let handle = unsafe { dlopen(name.as_ptr(), 2 | LOCAL) };
        if handle.is_null() {
            return Err(unsafe { loader_error() });
        }
        let library = Self {
            handle,
            path,
            sha256,
            snapshot,
        };
        library.unchanged()?;
        Ok(library)
    }

    /// The caller must provide the exact C signature and keep this handle alive.
    pub unsafe fn symbol<T: Copy>(&self, name: &str) -> Result<T, String> {
        if std::mem::size_of::<T>() != std::mem::size_of::<*mut c_void>() {
            return Err("symbol type has incompatible pointer size".into());
        }
        let name = CString::new(name).map_err(|_| "symbol name contains NUL")?;
        unsafe { dlerror() };
        let address = unsafe { dlsym(self.handle, name.as_ptr()) };
        let error = unsafe { dlerror() };
        if !error.is_null() || address.is_null() {
            return Err(format!("missing export {}", name.to_string_lossy()));
        }
        Ok(unsafe { std::mem::transmute_copy(&address) })
    }

    pub fn unchanged(&self) -> Result<(), String> {
        if file_sha256(&self.path)? != self.sha256
            || file_sha256(&self.snapshot.file)? != self.sha256
        {
            return Err("library path changed after artifact identity was frozen".into());
        }
        Ok(())
    }
}

impl Drop for Library {
    fn drop(&mut self) {
        unsafe { dlclose(self.handle) };
    }
}

unsafe fn loader_error() -> String {
    let error = unsafe { dlerror() };
    if error.is_null() {
        "unable to load library".into()
    } else {
        unsafe { CStr::from_ptr(error) }
            .to_string_lossy()
            .into_owned()
    }
}

pub fn file_sha256(path: &Path) -> Result<String, String> {
    let contents = std::fs::read(path).map_err(|e| format!("{}: {e}", path.display()))?;
    Ok(format!("{:x}", Sha256::digest(contents)))
}

type FingerprintNew = unsafe extern "C" fn() -> *mut c_void;
type FingerprintAdd = unsafe extern "C" fn(*mut c_void, *const u8, usize) -> c_int;
type FingerprintValue = unsafe extern "C" fn(*mut c_void, *mut u8) -> c_int;
type FingerprintFree = unsafe extern "C" fn(*mut c_void);
#[derive(Clone, Copy)]
pub(crate) struct FingerprintApi {
    new: FingerprintNew,
    add: FingerprintAdd,
    value: FingerprintValue,
    free: FingerprintFree,
}
impl FingerprintApi {
    pub(crate) unsafe fn load(library: &Library) -> Result<Self, String> {
        Ok(Self {
            new: unsafe { library.symbol("nntmux_article_fingerprint_new")? },
            add: unsafe { library.symbol("nntmux_article_fingerprint_add")? },
            value: unsafe { library.symbol("nntmux_article_fingerprint_value")? },
            free: unsafe { library.symbol("nntmux_article_fingerprint_free")? },
        })
    }
}
struct OwnedFingerprint(*mut c_void, FingerprintFree);
impl Drop for OwnedFingerprint {
    fn drop(&mut self) {
        unsafe { (self.1)(self.0) };
    }
}
pub(crate) const FINGERPRINT_OPERATION: &str = "nntmux_article_fingerprint_new";
pub(crate) fn fingerprint_fixture() -> Vec<u8> {
    [
        b" \t\"<a1@x>\"\r\n".as_slice(),
        b"' <b2@x> '",
        b"<a1@x>",
        b"b2@x",
        b"bad id",
        b"later@x",
    ]
    .join(&0)
}
// Frozen independent SHA-256 lane sums for a1@x and the unique set {a1@x,b2@x}.
// These are count/lane preimages, not a digest obtained from either library.
const FINGERPRINT_ONE: [u8; 40] = [
    0, 0, 0, 0, 0, 0, 0, 1, 202, 233, 151, 16, 56, 147, 46, 161, 74, 90, 226, 52, 253, 77, 4, 144,
    123, 143, 26, 166, 81, 255, 247, 125, 124, 88, 27, 74, 15, 144, 173, 99,
];
const FINGERPRINT_TWO: [u8; 40] = [
    0, 0, 0, 0, 0, 0, 0, 2, 1, 233, 2, 192, 245, 105, 215, 198, 63, 15, 243, 157, 204, 179, 246,
    154, 13, 1, 231, 45, 123, 176, 187, 171, 130, 168, 15, 124, 27, 98, 66, 165,
];
fn trace_status(trace: &mut Vec<u8>, status: c_int) {
    trace.extend_from_slice(&status.to_le_bytes());
}
fn expected_value(trace: &mut Vec<u8>, value: Option<&[u8; 40]>) {
    trace_status(trace, c_int::from(value.is_some()));
    trace.extend_from_slice(&[0xa5; 8]);
    trace.extend_from_slice(value.map_or([0xa5; 40].as_slice(), |value| value.as_slice()));
    trace.extend_from_slice(&[0xa5; 8]);
}
pub(crate) fn expected_fingerprint_trace() -> Vec<u8> {
    let mut trace = Vec::new();
    trace_status(&mut trace, 1);
    expected_value(&mut trace, None);
    for value in [
        &FINGERPRINT_ONE,
        &FINGERPRINT_TWO,
        &FINGERPRINT_TWO,
        &FINGERPRINT_TWO,
    ] {
        trace_status(&mut trace, 1);
        expected_value(&mut trace, Some(value));
    }
    for _ in 0..2 {
        trace_status(&mut trace, 0);
        expected_value(&mut trace, None);
    }
    trace_status(&mut trace, 1); // Exactly one owned free after the invalid-state probes.
    trace
}
pub(crate) unsafe fn fingerprint_trace(api: FingerprintApi, input: &[u8]) -> Vec<u8> {
    let mut trace = Vec::with_capacity(452);
    let pointer = unsafe { (api.new)() };
    trace_status(&mut trace, c_int::from(!pointer.is_null()));
    if pointer.is_null() {
        return trace;
    }
    let state = OwnedFingerprint(pointer, api.free);
    let value = |trace: &mut Vec<u8>| {
        let mut destination = [0xa5; 56];
        let status = unsafe { (api.value)(state.0, destination.as_mut_ptr().add(8)) };
        trace_status(trace, status);
        trace.extend_from_slice(&destination);
    };
    value(&mut trace);
    // The frozen workload contains six IDs; one extra entry is retained to make
    // excess input fail the exact receipt while keeping trace allocation bounded.
    for id in input.split(|byte| *byte == 0).take(7) {
        trace_status(&mut trace, unsafe {
            (api.add)(state.0, id.as_ptr(), id.len())
        });
        value(&mut trace);
    }
    drop(state);
    trace_status(&mut trace, 1);
    trace
}
fn verify_fingerprint(api: FingerprintApi) -> Result<(), String> {
    if unsafe { fingerprint_trace(api, &fingerprint_fixture()) } != expected_fingerprint_trace() {
        return Err(
            "fingerprint independent lifecycle/count/lane/status/canary check failed".into(),
        );
    }
    let mut output = [0xa5; 56];
    unsafe {
        if (api.add)(std::ptr::null_mut(), std::ptr::null(), 0) != 0
            || (api.value)(std::ptr::null_mut(), output.as_mut_ptr().add(8)) != 0
            || output != [0xa5; 56]
        {
            return Err("fingerprint null-state failure contract failed".into());
        }
        (api.free)(std::ptr::null_mut());
        let pointer = (api.new)();
        if pointer.is_null() {
            return Err("fingerprint constructor returned null".into());
        }
        let state = OwnedFingerprint(pointer, api.free);
        if (api.value)(state.0, std::ptr::null_mut()) != 0
            || (api.add)(state.0, std::ptr::null(), 1) != 0
            || (api.add)(state.0, b"a1@x".as_ptr(), 4) != 0
            || (api.value)(state.0, output.as_mut_ptr().add(8)) != 0
            || output != [0xa5; 56]
        {
            return Err("fingerprint null-input invalidation contract failed".into());
        }
    }
    Ok(())
}

pub fn verify_library(path: &Path, metadata_required: bool) -> Result<Value, String> {
    let library = Library::open(path)?;
    for name in RAPIDYENC_EXPORTS
        .iter()
        .chain(NNTMUX_EXPORTS.iter().filter(|_| metadata_required))
    {
        unsafe { library.symbol::<*mut c_void>(name)? };
    }
    if metadata_required {
        verify_fingerprint(unsafe { FingerprintApi::load(&library)? })?;
    }
    type Init = unsafe extern "C" fn();
    type Decode =
        unsafe extern "C" fn(c_int, *const c_void, *mut c_void, usize, *mut c_int) -> usize;
    type Crc = unsafe extern "C" fn(*const c_void, usize, u32) -> u32;
    let init: Init = unsafe { library.symbol("rapidyenc_decode_init")? };
    let crc_init: Init = unsafe { library.symbol("rapidyenc_crc_init")? };
    let decode: Decode = unsafe { library.symbol("rapidyenc_decode_ex")? };
    let crc: Crc = unsafe { library.symbol("rapidyenc_crc")? };
    unsafe {
        init();
        crc_init();
    }
    let input = b"klm=@=J=M=}..\x0b==";
    let mut output = [0xcc; 32];
    let count = unsafe {
        decode(
            0,
            input.as_ptr().cast(),
            output.as_mut_ptr().cast(),
            input.len(),
            std::ptr::null_mut(),
        )
    };
    let expected = b"ABC\xd6\xe0\xe3\x13\x04\x04\xe1\xd3";
    if count != expected.len()
        || &output[..expected.len()] != expected
        || output[input.len()..].iter().any(|v| *v != 0xcc)
    {
        return Err("decoder known-answer or capacity check failed".into());
    }
    if unsafe { crc(b"ABC".as_ptr().cast(), 3, 0) } != 0xa3830348 {
        return Err("CRC known-answer check failed".into());
    }
    type Number = unsafe extern "C" fn() -> c_int;
    let version: Number = unsafe { library.symbol("rapidyenc_version")? };
    let decoder_kernel: Number = unsafe { library.symbol("rapidyenc_decode_kernel")? };
    let crc_kernel: Number = unsafe { library.symbol("rapidyenc_crc_kernel")? };
    library.unchanged()?;
    Ok(json!({"path": library.path, "sha256": library.sha256,
        "version": unsafe { version() }, "decoder_kernel": unsafe { decoder_kernel() },
        "crc_kernel": unsafe { crc_kernel() }, "rapidyenc_exports": RAPIDYENC_EXPORTS,
        "metadata_exports": if metadata_required { NNTMUX_EXPORTS } else { &[] }}))
}

#[derive(Debug)]
pub struct Statistics {
    pub median: f64,
    pub p95: f64,
}

pub fn summarize(values: &[f64]) -> Result<Statistics, String> {
    if values.is_empty() || values.iter().any(|v| !v.is_finite() || *v <= 0.) {
        return Err("elapsed samples must be present, finite and positive".into());
    }
    let mut ordered = values.to_vec();
    ordered.sort_by(f64::total_cmp);
    let rank95 = (ordered.len() * 95).div_ceil(100);
    Ok(Statistics {
        median: ordered[ordered.len() / 2],
        p95: ordered[rank95 - 1],
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn outer_adoption_disposition_preserves_historical_fields_and_nested_receipts() {
        let historical = json!({
            "status":"passed", "protocol":"rust-paired-yenc-v4",
            "all_pairs":{"0":[{"ratios":{"median_ratio":0.9,"p95_ratio":1.0}}]},
            "gates":[{"primary_pass":true,"cold_lifecycle_pass":true}],
            "artifact":{"rapidyenc_exports":RAPIDYENC_EXPORTS,"metadata_exports":NNTMUX_EXPORTS},
            "receipt":{"status":"verified","adoption_eligibility":{"eligible":true}},
            "provenance":{"source":"original","sha256":"frozen"}
        });
        let mut report = with_adoption_eligibility(historical.clone()).unwrap();
        assert_eq!(
            report["adoption_eligibility"],
            json!({
                "eligible":false,
                "policy":"common-and-uncommon-p99-1.5x",
                "reason":"current-policy evidence unavailable"
            })
        );
        report
            .as_object_mut()
            .unwrap()
            .remove("adoption_eligibility");
        assert_eq!(report, historical);
    }

    #[test]
    fn outer_adoption_disposition_never_trusts_status_or_supplied_proof() {
        for status in ["passed", "verified", "failed_gates", "unknown"] {
            for proof in [
                None,
                Some(Value::Null),
                Some(json!({"eligible":true,"policy":"median-p95"})),
                Some(json!({"eligible":true,"policy":"common-and-uncommon-p99-1.5x"})),
                Some(json!("unknown")),
            ] {
                let mut historical = json!({"status":status});
                if let Some(proof) = proof {
                    historical["adoption_eligibility"] = proof;
                }
                let report = with_adoption_eligibility(historical).unwrap();
                assert_eq!(report["status"], status);
                assert_eq!(report["adoption_eligibility"]["eligible"], false);
                assert_eq!(
                    report["adoption_eligibility"]["policy"],
                    "common-and-uncommon-p99-1.5x"
                );
                assert_eq!(
                    report["adoption_eligibility"]["reason"],
                    "current-policy evidence unavailable"
                );
            }
        }
    }

    #[test]
    fn outer_adoption_disposition_is_idempotent() {
        let once = with_adoption_eligibility(json!({"status":"passed"})).unwrap();
        assert_eq!(with_adoption_eligibility(once.clone()).unwrap(), once);
    }

    #[test]
    fn outer_adoption_disposition_rejects_non_object_reports() {
        for report in [
            Value::Null,
            json!(true),
            json!(1),
            json!("passed"),
            json!([]),
        ] {
            assert_eq!(
                with_adoption_eligibility(report).unwrap_err(),
                "outer report must be a JSON object"
            );
        }
    }

    #[test]
    fn fingerprint_exports_are_required_by_shared_artifact_inventory() {
        for symbol in [
            "nntmux_article_fingerprint_new",
            "nntmux_article_fingerprint_add",
            "nntmux_article_fingerprint_value",
            "nntmux_article_fingerprint_free",
        ] {
            assert!(NNTMUX_EXPORTS.contains(&symbol), "missing {symbol}");
        }
        assert_eq!(NNTMUX_EXPORTS.len(), 26);
    }

    #[test]
    fn fingerprint_fixed_preimages_and_owned_cleanup_cover_success_and_failure() {
        use std::sync::atomic::{AtomicUsize, Ordering};
        static CREATED: AtomicUsize = AtomicUsize::new(0);
        static FREED: AtomicUsize = AtomicUsize::new(0);
        unsafe extern "C" fn new() -> *mut c_void {
            CREATED.fetch_add(1, Ordering::SeqCst);
            nzbyenc::nntmux_article_fingerprint_new()
        }
        unsafe extern "C" fn free(state: *mut c_void) {
            if !state.is_null() {
                FREED.fetch_add(1, Ordering::SeqCst);
            }
            unsafe { nzbyenc::nntmux_article_fingerprint_free(state) };
        }
        unsafe extern "C" fn bad_add(_: *mut c_void, _: *const u8, _: usize) -> c_int {
            2
        }
        unsafe extern "C" fn null_new() -> *mut c_void {
            std::ptr::null_mut()
        }
        let api = FingerprintApi {
            new,
            add: nzbyenc::nntmux_article_fingerprint_add,
            value: nzbyenc::nntmux_article_fingerprint_value,
            free,
        };
        assert!(verify_fingerprint(api).is_ok());
        assert_eq!(CREATED.load(Ordering::SeqCst), FREED.load(Ordering::SeqCst));
        assert!(
            verify_fingerprint(FingerprintApi {
                add: bad_add,
                ..api
            })
            .is_err()
        );
        assert_eq!(CREATED.load(Ordering::SeqCst), FREED.load(Ordering::SeqCst));
        let before = FREED.load(Ordering::SeqCst);
        assert!(
            verify_fingerprint(FingerprintApi {
                new: null_new,
                ..api
            })
            .is_err()
        );
        assert_eq!(FREED.load(Ordering::SeqCst), before);
        for (ids, expected) in [
            (vec!["a1@x"], FINGERPRINT_ONE),
            (vec!["a1@x", "b2@x"], FINGERPRINT_TWO),
        ] {
            let mut lanes = [0u32; 8];
            for id in &ids {
                for (lane, word) in lanes
                    .iter_mut()
                    .zip(Sha256::digest(id.as_bytes()).as_chunks::<4>().0.iter())
                {
                    *lane = lane.wrapping_add(u32::from_be_bytes(*word));
                }
            }
            assert_eq!(&expected[..8], &(ids.len() as u64).to_be_bytes());
            for (word, lane) in expected[8..].as_chunks::<4>().0.iter().zip(lanes) {
                assert_eq!(*word, lane.to_be_bytes());
            }
        }
    }
    #[test]
    #[ignore = "explicit artifact required: NNTMUX_BENCH_TEST_LIBRARY"]
    fn loaded_fingerprint_artifact_has_independent_lifecycle_behavior() {
        let artifact = std::env::var("NNTMUX_BENCH_TEST_LIBRARY").unwrap();
        let library = Library::open(Path::new(&artifact)).unwrap();
        verify_fingerprint(unsafe { FingerprintApi::load(&library).unwrap() }).unwrap();
    }
    #[test]
    #[ignore = "explicit candidate artifact required: NNTMUX_BENCH_TEST_LIBRARY"]
    fn loaded_candidate_artifact_satisfies_shared_installer_inventory() {
        let artifact = std::env::var("NNTMUX_BENCH_TEST_LIBRARY").unwrap();
        verify_library(Path::new(&artifact), true).unwrap();
    }

    #[test]
    fn service_calls_use_all_thirty_three_values() {
        let values: Vec<f64> = (1..=33).rev().map(f64::from).collect();
        let result = summarize(&values).unwrap();
        assert_eq!(result.median, 17.0);
        assert_eq!(result.p95, 32.0);
    }

    #[test]
    fn eleven_batches_use_the_declared_nearest_rank_tail() {
        let result = summarize(&[11., 1., 9., 4., 6., 2., 3., 5., 7., 8., 10.]).unwrap();
        assert_eq!(result.median, 6.0);
        assert_eq!(result.p95, 11.0);
    }

    #[test]
    fn missing_or_invalid_times_cannot_qualify() {
        for values in [
            vec![],
            vec![f64::NAN],
            vec![f64::INFINITY],
            vec![-1.],
            vec![0.],
        ] {
            assert!(summarize(&values).is_err());
        }
    }
}
