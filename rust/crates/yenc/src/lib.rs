//! yEnc decoding and encoding with CRC checks.
//!
//! [`decode`] is the strict path: it rejects any declared size, range or checksum
//! that disagrees with the payload. [`decode_lenient`] and [`decode_with_frame`]
//! recover what they can, because real articles often carry broken trailers.
//! [`analyze`] reports the same checks without failing, for fingerprinting.
//!
//! Owner: ws-formats. See `rust/WORKSTREAMS.md`.

mod analysis;
pub mod decoder;
#[cfg(target_arch = "aarch64")]
mod decoder_arm;
#[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
mod decoder_x86;
mod frame;
pub mod framing;
mod service;

pub use analysis::{Analysis, analyze};
pub use frame::{ArticleFrame, Fields};
pub use service::{
    YencError, YencMetadata, YencPart, crc32, decode, decode_lenient, decode_with_frame, encode,
    extract_metadata, has_validated_integrity, is_yenc_encoded,
};
