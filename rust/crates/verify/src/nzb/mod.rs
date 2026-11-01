//! Published NZB storage. The bodies live in `release_nzb_contents`, written in the same
//! transaction as their release and fingerprint work, so there's no filesystem staging to settle.

pub mod document;
pub mod fingerprint;
pub mod store;

pub use document::document;
pub use fingerprint::{ArticleFingerprint, normalize_message_id, validated_message_id};
pub use store::{
    MAX_COMPRESSED_BYTES, MAX_DECODED_BYTES, ReleaseRef, compress, decode, delete, digest, exists,
    put_locked, read, read_bounded,
};
