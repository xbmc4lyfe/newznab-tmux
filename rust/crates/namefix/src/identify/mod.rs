//! Identify releases with obfuscated names: TV and movies at 4K or 1080p, from BluRay, remux or
//! WEB-DL. Anime is out.
//!
//! [`identify`] climbs a ladder of evidence, cheapest first: the release's own name, PreDB by name,
//! NZB file names, PAR2 file names, archive listings, files shared with named releases, then the
//! srrDB and stream fingerprint hooks. PreDB by size and time only gives srrDB candidates. The name rules are the ones
//! `crate::checkers` and `nntmux_predb::filename` already use.
//!
//! The result is a report. Nothing here renames a release or links it to PreDB. No rung looks at
//! how many files a post has, so a single 60 GB remux MKV passes like any other.
//!
//! - [`identify_release`] is for a caller with a MariaDB pool.
//! - [`catalog`] runs over the SQLite import, and backs the `nntmux-identify` binary.

pub mod catalog;
pub mod donor;
pub mod ladder;
pub mod local_predb;
pub mod mysql;
pub mod name;
pub mod nzb;

pub use donor::DonorIndex;
pub use ladder::{
    Context, Evidence, Flags, Hooks, Identification, NotConfigured, Outcome, SrrdbLookup,
    SrrdbQuery, StreamFingerprinter, StreamQuery, candidate_names, identify,
};
pub use mysql::identify_release;
pub use name::{Kind, ParsedName, Scope};
