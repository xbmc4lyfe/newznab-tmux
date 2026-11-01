//! Name extraction from file names and NFO text (`Extractors/FileNameExtractor.php` and
//! `Extractors/NfoNameExtractor.php`).

pub mod file;
pub mod nfo;

pub use file::extract_from_file;
pub use nfo::{PredbCandidates, extract_from_nfo, identity_text, predb_candidates};
