//! Types shared by every stage: pipeline stages now, release and post records as they arrive.

/// A pipeline stage, and the value of the `stage` metric label.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
pub enum Stage {
    Watch,
    Harvest,
    Assemble,
    Identify,
    Classify,
    MatchIds,
    Store,
    Serve,
}

impl Stage {
    pub const ALL: [Stage; 8] = [
        Stage::Watch,
        Stage::Harvest,
        Stage::Assemble,
        Stage::Identify,
        Stage::Classify,
        Stage::MatchIds,
        Stage::Store,
        Stage::Serve,
    ];

    pub const fn as_str(self) -> &'static str {
        match self {
            Stage::Watch => "watch",
            Stage::Harvest => "harvest",
            Stage::Assemble => "assemble",
            Stage::Identify => "identify",
            Stage::Classify => "classify",
            Stage::MatchIds => "match_ids",
            Stage::Store => "store",
            Stage::Serve => "serve",
        }
    }
}
