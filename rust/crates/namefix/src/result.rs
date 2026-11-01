//! `Data/NameFixResult.php`, without the fields the callers never read (`preDbId` is always 0
//! at construction and `metadata` is always empty).

/// A proposed release name and how it was found.
#[derive(Clone, Debug, PartialEq)]
pub struct NameFixResult {
    pub new_name: String,
    pub method: String,
    pub checker_name: String,
    pub confidence: f64,
}

impl NameFixResult {
    pub fn new(
        new_name: impl Into<String>,
        method: impl Into<String>,
        checker_name: impl Into<String>,
        confidence: f64,
    ) -> Self {
        Self {
            new_name: new_name.into(),
            method: method.into(),
            checker_name: checker_name.into(),
            confidence,
        }
    }

    /// `NameFixResult::fromMatch` with its default confidence of 1.0, as the file and NFO
    /// extractors use it.
    pub fn from_match(
        new_name: impl Into<String>,
        method: impl Into<String>,
        checker_name: impl Into<String>,
    ) -> Self {
        Self::new(new_name, method, checker_name, 1.0)
    }

    /// `getFormattedMethod`: `"TV: Sports"`.
    pub fn formatted_method(&self) -> String {
        format!("{}: {}", self.checker_name, self.method)
    }
}
