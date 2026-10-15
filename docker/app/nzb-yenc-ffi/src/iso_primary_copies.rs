//! Bounded raw-byte relationships between accepted primary descriptor recordings.
//!
//! ECMA-119 sixth edition 7.7.2 allows repeated PVD recordings. Equality here
//! includes reserved and application-use bytes; it is not a conformance test.

use super::{IsoDescriptorSequence, hex};
use serde::Serialize;
use sha2::{Digest, Sha256};

const DESCRIPTOR_BYTES: usize = 2048;
const DETAIL_LIMIT: usize = 64;

#[derive(Debug, Serialize)]
pub struct PrimaryDescriptorCopies {
    pub status: &'static str,
    pub basis: &'static str,
    pub offset_basis: &'static str,
    pub image_origin_known: bool,
    pub whole_image_validated: bool,
    pub sequence_status: &'static str,
    pub sampled_descriptor_sequence_terminated: bool,
    pub reference_descriptor_offset: Option<usize>,
    pub observed_descriptor_count: usize,
    pub repeat_comparison_count: usize,
    pub matching_reference_count: usize,
    pub differing_reference_count: usize,
    pub all_observed_copies_equal: Option<bool>,
    pub first_difference: Option<ByteDifference>,
    pub detail_limit: usize,
    pub details_omitted_count: usize,
    pub copies: Vec<PrimaryDescriptorCopy>,
}

#[derive(Debug, Serialize)]
pub struct PrimaryDescriptorCopy {
    pub descriptor_sequence_index: usize,
    pub sector: usize,
    pub offset: usize,
    pub end_offset_exclusive: usize,
    pub bytes_hashed: usize,
    pub raw_sha256: String,
    pub is_reference: bool,
    pub matches_reference: bool,
    pub first_difference: Option<ByteDifference>,
}

#[derive(Clone, Debug, Serialize)]
pub struct ByteDifference {
    pub descriptor_relative_offset: usize,
    pub reference_sample_offset: usize,
    pub differing_sample_offset: usize,
    pub reference_byte: u8,
    pub differing_byte: u8,
}

pub(super) fn parse(data: &[u8], sequence: &IsoDescriptorSequence) -> PrimaryDescriptorCopies {
    let mut result = PrimaryDescriptorCopies {
        status: "no_primary_descriptor_observed",
        basis: "exact recorded bytes of complete supported type-1 descriptors in the bounded observed sequence; includes reserved and application-use bytes; not ISO conformance, interpreted-field agreement, whole-file identity or uploader attribution",
        offset_basis: sequence.offset_basis,
        image_origin_known: false,
        whole_image_validated: false,
        sequence_status: sequence.status,
        sampled_descriptor_sequence_terminated: sequence.status == "terminated",
        reference_descriptor_offset: None,
        observed_descriptor_count: 0,
        repeat_comparison_count: 0,
        matching_reference_count: 0,
        differing_reference_count: 0,
        all_observed_copies_equal: None,
        first_difference: None,
        detail_limit: DETAIL_LIMIT,
        details_omitted_count: 0,
        copies: Vec::new(),
    };
    let mut reference: Option<&[u8]> = None;
    // The scanner stops after 240 sectors, at a malformed/unsupported record,
    // or at the first terminator. Never search elsewhere for more PVDs.
    for (index, observation) in sequence.descriptors.iter().enumerate() {
        if observation.descriptor_type != 1
            || !observation.standard_identifier_valid
            || !observation.type_version_supported
        {
            continue;
        }
        let Some(end) = observation.offset.checked_add(DESCRIPTOR_BYTES) else {
            continue;
        };
        let Some(bytes) = data.get(observation.offset..end) else {
            continue;
        };
        let is_reference = reference.is_none();
        let first = reference.get_or_insert(bytes);
        let reference_offset = *result
            .reference_descriptor_offset
            .get_or_insert(observation.offset);
        let difference = first
            .iter()
            .zip(bytes)
            .position(|(left, right)| left != right)
            .map(|relative| ByteDifference {
                descriptor_relative_offset: relative,
                reference_sample_offset: reference_offset + relative,
                differing_sample_offset: observation.offset + relative,
                reference_byte: first[relative],
                differing_byte: bytes[relative],
            });
        let matches_reference = difference.is_none();
        result.observed_descriptor_count += 1;
        if matches_reference {
            result.matching_reference_count += 1;
        } else {
            result.differing_reference_count += 1;
            if result.first_difference.is_none() {
                result.first_difference = difference.clone();
            }
        }
        // Compare every accepted descriptor, but bound both hashing and JSON.
        if result.copies.len() < DETAIL_LIMIT {
            result.copies.push(PrimaryDescriptorCopy {
                descriptor_sequence_index: index,
                sector: observation.sector,
                offset: observation.offset,
                end_offset_exclusive: end,
                bytes_hashed: DESCRIPTOR_BYTES,
                raw_sha256: hex(&Sha256::digest(bytes)),
                is_reference,
                matches_reference,
                first_difference: difference,
            });
        } else {
            result.details_omitted_count += 1;
        }
    }
    result.repeat_comparison_count = result.observed_descriptor_count.saturating_sub(1);
    result.all_observed_copies_equal =
        (result.observed_descriptor_count > 1).then_some(result.differing_reference_count == 0);
    result.status = match result.all_observed_copies_equal {
        Some(true) => "observed_copies_agree",
        Some(false) => "observed_copies_differ",
        None if result.observed_descriptor_count == 1 => "single_primary_descriptor_observed",
        None => "no_primary_descriptor_observed",
    };
    result
}
