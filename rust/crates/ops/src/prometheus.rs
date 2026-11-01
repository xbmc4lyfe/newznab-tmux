//! Prometheus text exposition format 0.0.4, for pushing to the Pushgateway.

pub const CONTENT_TYPE: &str = "text/plain; version=0.0.4; charset=utf-8";

#[derive(Clone, Copy, Debug, PartialEq)]
pub enum Value {
    Int(i64),
    Float(f64),
}

impl From<i64> for Value {
    fn from(value: i64) -> Self {
        Self::Int(value)
    }
}

impl From<f64> for Value {
    fn from(value: f64) -> Self {
        Self::Float(value)
    }
}

/// One metric: its name, help text and samples. Every metric this project pushes is a gauge.
#[derive(Clone, Debug)]
pub struct Family {
    name: &'static str,
    help: &'static str,
    samples: Vec<(Vec<(&'static str, String)>, Value)>,
}

impl Family {
    pub fn gauge(name: &'static str, help: &'static str) -> Self {
        Self {
            name,
            help,
            samples: Vec::new(),
        }
    }

    pub fn with(mut self, value: impl Into<Value>) -> Self {
        self.samples.push((Vec::new(), value.into()));
        self
    }

    pub fn add(&mut self, value: impl Into<Value>, labels: &[(&'static str, &str)]) -> &mut Self {
        let labels = labels
            .iter()
            .map(|(name, value)| (*name, (*value).to_owned()))
            .collect();
        self.samples.push((labels, value.into()));
        self
    }
}

pub fn format(families: &[Family]) -> String {
    let mut out = String::new();
    for family in families.iter().filter(|family| !family.samples.is_empty()) {
        let help = family.help.replace('\\', "\\\\").replace('\n', "\\n");
        out.push_str(&format!("# HELP {} {help}\n", family.name));
        out.push_str(&format!("# TYPE {} gauge\n", family.name));
        for (labels, value) in &family.samples {
            out.push_str(family.name);
            out.push_str(&format_labels(labels));
            out.push(' ');
            out.push_str(&format_value(*value));
            out.push('\n');
        }
    }
    out
}

fn format_labels(labels: &[(&'static str, String)]) -> String {
    if labels.is_empty() {
        return String::new();
    }
    let pairs: Vec<String> = labels
        .iter()
        .map(|(name, value)| {
            let value = value
                .replace('\\', "\\\\")
                .replace('"', "\\\"")
                .replace('\n', "\\n");
            format!("{name}=\"{value}\"")
        })
        .collect();
    format!("{{{}}}", pairs.join(","))
}

fn format_value(value: Value) -> String {
    let value = match value {
        Value::Int(value) => return value.to_string(),
        Value::Float(value) => value,
    };
    if value.is_nan() {
        return "NaN".to_owned();
    }
    if value.is_infinite() {
        return if value > 0.0 { "+Inf" } else { "-Inf" }.to_owned();
    }
    let fixed = format!("{value:.6}");
    let trimmed = fixed.trim_end_matches('0').trim_end_matches('.');
    if trimmed == "-0" {
        "0".to_owned()
    } else {
        trimmed.to_owned()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn output_matches_the_php_formatter() {
        let mut values = Family::gauge("nntmux_values", "Float rendering.");
        values
            .add(1.0, &[("kind", "whole")])
            .add(0.1234567, &[("kind", "rounded")])
            .add(1234567.5, &[("kind", "large")])
            .add(-0.0000001, &[("kind", "negative zero")])
            .add(f64::NAN, &[("kind", "nan")])
            .add(f64::INFINITY, &[("kind", "inf")])
            .add(f64::NEG_INFINITY, &[("kind", "neg inf")]);
        let mut labels = Family::gauge("nntmux_labels", "Label escaping.");
        labels.add(
            2,
            &[
                ("a", "quote \" here"),
                ("b", "back \\ slash"),
                ("c", "new\nline"),
            ],
        );
        let families = [
            Family::gauge("nntmux_up", "Whether the NNTmux metrics exporter ran.").with(1),
            Family::gauge("nntmux_empty", "No samples, so no output."),
            Family::gauge("nntmux_help", "Help with a \\ backslash\nand a newline.").with(0.5),
            values,
            labels,
        ];
        assert_eq!(
            format(&families),
            include_str!("../tests/fixtures/metrics.txt")
        );
    }
}
