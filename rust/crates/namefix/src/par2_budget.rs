//! Payload budgets: a PAR2 recovery set can only describe a release whose non-PAR2 payload can
//! hold its files, so a candidate that can't is dropped without reading further.

use crate::par2_accumulator::{AccumulatorError, PacketAccumulator, SetFile};

/// `Par2FileHashEvidence::sizeDecimal` for a string: canonical unsigned decimal up to
/// 18446744073709551615, else `None`.
pub fn size_decimal(size: &str) -> Option<String> {
    let valid = !size.is_empty()
        && size.len() <= 20
        && size.bytes().all(|b| b.is_ascii_digit())
        && (size == "0" || !size.starts_with('0'));
    if !valid || (size.len() == 20 && size > "18446744073709551615") {
        return None;
    }
    Some(size.to_owned())
}

/// `Par2Processor::setFitsPayloadBudget`.
pub fn set_fits_payload_budget(files: &[SetFile], max_payload_bytes: Option<i64>) -> bool {
    let Some(max) = max_payload_bytes else {
        return true;
    };
    if max <= 0 {
        return false;
    }
    let mut remaining = max;
    for file in files {
        if file.size_decimal.is_none() && file.size.is_none() && file.name.is_none() {
            continue;
        }
        let decimal = file
            .size_decimal
            .clone()
            .or_else(|| file.size.map(|size| size.to_string()));
        let Some(decimal) = decimal.as_deref().and_then(size_decimal) else {
            return false;
        };
        let Ok(bytes) = decimal.parse::<i64>() else {
            return false;
        };
        if bytes > remaining {
            return false;
        }
        remaining -= bytes;
    }
    true
}

/// `Par2Processor::exceedsPayloadBudget`: whether a candidate has already proved it cannot
/// describe this release's payload.
pub fn exceeds_payload_budget(
    inventory: &PacketAccumulator,
    max_payload_bytes: Option<i64>,
) -> Result<bool, AccumulatorError> {
    let Some(max) = max_payload_bytes else {
        return Ok(false);
    };
    if max <= 0 {
        return Ok(inventory.has_packets()
            || inventory.skip_remaining() > 0
            || !inventory.tail_is_empty());
    }
    let sets = inventory.files_by_set()?;
    if sets.is_empty() {
        return Ok(false);
    }
    let mut resolved = false;
    for (_, files) in &sets {
        if files
            .iter()
            .any(|f| f.name.is_some() || f.size.is_some() || f.size_decimal.is_some())
        {
            resolved = true;
        }
        if set_fits_payload_budget(files, Some(max)) {
            return Ok(false);
        }
    }
    Ok(resolved)
}
