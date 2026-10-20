use crate::{
    breaker,
    database::{self, Claim},
    manifest::{self, File, Sample},
    nntp::{Article, Client, Provider},
    yenc,
};
use anyhow::Result;
use chrono::{DateTime, Utc};
use md5::{Digest, Md5};
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use sqlx::MySqlPool;
use std::{
    collections::{BTreeMap, BTreeSet},
    time::Instant,
};

#[derive(Debug, Serialize, Deserialize)]
pub struct Collected {
    pub status: String,
    pub data: Value,
    /// Where one job's wall time went; logged by the worker, never stored in compact-v1.
    #[serde(skip)]
    pub timings: Timings,
}
/// Wall-clock milliseconds spent in each phase of one collection.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Timings {
    /// Waiting for a shared provider-connection budget row.
    pub slot_ms: u64,
    /// TCP connect, TLS handshake, greeting and AUTHINFO.
    pub connect_ms: u64,
    /// CAPABILITIES and LIST OVERVIEW.FMT.
    pub profile_ms: u64,
    /// Sequential ARTICLE retrievals.
    pub articles_ms: u64,
    /// Provider connections opened for this release.
    pub connections_opened: u32,
}
fn elapsed_ms(since: Instant) -> u64 {
    u64::try_from(since.elapsed().as_millis()).unwrap_or(u64::MAX)
}
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct ProviderEvidence {
    pub account: String,
    pub host: String,
    pub port: String,
    pub tls: bool,
    pub implementation: Option<String>,
    pub profile: String,
    #[serde(default)]
    pub skipped_reason: Option<String>,
    pub articles: BTreeMap<String, std::result::Result<Article, String>>,
}
pub async fn run(
    pool: &MySqlPool,
    claim: &Claim,
    worker: &str,
    providers: &[Provider],
    alternate_percent: u32,
    timeout: u64,
) -> Result<Collected> {
    let xml = database::decode_nzb(claim)?;
    let files = manifest::parse(&xml)?;
    let (samples, _) = manifest::samples(&files);
    let mut evidence = vec![];
    let mut timings = Timings::default();
    for p in providers {
        if p.account == "alternate"
            && crc32fast::hash(claim.release_id.to_string().as_bytes()) % 100
                >= alternate_percent.min(100)
        {
            continue;
        }
        let decision = if p.account == "alternate" {
            Some(breaker::begin(pool, &p.account).await?)
        } else {
            None
        };
        if let Some(skipped_reason) = decision.as_ref().and_then(|d| d.skipped_reason.clone()) {
            evidence.push(ProviderEvidence {
                account: p.account.clone(),
                host: p.host.clone(),
                port: p.port.to_string(),
                tls: p.tls,
                implementation: None,
                profile: "NntpProviderUnavailableException".into(),
                skipped_reason: Some(skipped_reason),
                articles: samples
                    .iter()
                    .map(|s| {
                        (
                            s.messageid.clone(),
                            Err("NntpProviderUnavailableException".into()),
                        )
                    })
                    .collect(),
            });
            continue;
        }
        let phase = Instant::now();
        database::slot(pool, claim, &p.account, worker).await?;
        timings.slot_ms += elapsed_ms(phase);
        let phase = Instant::now();
        let mut client = Client::connect(p, timeout).await.ok();
        timings.connect_ms += elapsed_ms(phase);
        timings.connections_opened += 1;
        let mut entries = BTreeMap::new();
        let mut implementation = None;
        let mut profile = "ok".to_string();
        let mut unavailable = false;
        let phase = Instant::now();
        if let Some(connection) = client.as_mut() {
            if connection.profile().await.is_err() {
                profile = "ProfileResponseInvalid".into();
                if let Some(decision) = decision.as_ref() {
                    breaker::release_probe(pool, &p.account, decision).await?;
                }
            } else if let Some(decision) = decision.as_ref() {
                breaker::reachable(pool, &p.account, decision).await?;
            }
            implementation = connection.implementation.clone();
        } else {
            profile = "NntpProviderUnavailableException".into();
            if decision.is_some() {
                breaker::unavailable(pool, &p.account).await?;
                unavailable = true;
            }
        }
        timings.profile_ms += elapsed_ms(phase);
        let phase = Instant::now();
        for sample in &samples {
            if !unavailable && client.as_ref().is_none_or(Client::poisoned) {
                drop(client.take());
                let reconnect = Instant::now();
                client = Client::connect(p, timeout).await.ok();
                timings.connect_ms += elapsed_ms(reconnect);
                timings.connections_opened += 1;
                if client.is_none() && decision.is_some() {
                    breaker::unavailable(pool, &p.account).await?;
                    unavailable = true;
                }
            }
            let article = if unavailable {
                Err("NntpProviderUnavailableException".into())
            } else if let Some(connection) = client.as_mut() {
                connection
                    .article(&sample.messageid)
                    .await
                    .map_err(|error| {
                        if error.to_string().contains("NntpArticleMissingException") {
                            "NntpArticleMissingException".to_string()
                        } else {
                            "RuntimeException".to_string()
                        }
                    })
            } else {
                Err("NntpProviderUnavailableException".into())
            };
            entries.insert(sample.messageid.clone(), article);
        }
        timings.articles_ms += elapsed_ms(phase);
        evidence.push(ProviderEvidence {
            account: p.account.clone(),
            host: p.host.clone(),
            port: p.port.to_string(),
            tls: p.tls,
            implementation,
            profile,
            skipped_reason: None,
            articles: entries,
        });
    }
    let mut collected = analyze(&files, &evidence);
    collected.timings = timings;
    Ok(collected)
}
fn headers(article: &Article) -> BTreeMap<String, String> {
    let mut out: BTreeMap<String, String> = BTreeMap::new();
    let mut previous = None;
    for line in &article.headers {
        let content = line.trim_end_matches(['\r', '\n']);
        if content.starts_with([' ', '\t']) {
            if let Some(key) = previous.as_ref()
                && let Some(value) = out.get_mut(key)
            {
                *value = format!("{} {}", value.trim_end(), content.trim());
            }
            continue;
        }
        if let Some((name, value)) = content.split_once(':') {
            let key = name.trim().to_lowercase();
            if key.is_empty() {
                continue;
            }
            previous = Some(key.clone());
            out.entry(key)
                .and_modify(|old| *old = format!("{old}, {}", value.trim()))
                .or_insert(value.trim().to_string());
        }
    }
    out
}
fn add_error(errors: &mut BTreeMap<(String, String), u64>, account: &str, kind: &str) {
    *errors.entry((account.into(), kind.into())).or_default() += 1;
}
fn article_identity(
    sample: &Sample,
    article: &Article,
    h: &BTreeMap<String, String>,
) -> Option<&'static str> {
    let want = format!("<{}>", sample.messageid);
    let Some(value) = h.get("message-id").filter(|v| !v.trim().is_empty()) else {
        return Some("ArticleIdentityUnconfirmed");
    };
    if value.trim() != want {
        return Some("ArticleIdentityMismatch");
    }
    if let Some((_, tail)) = article.response.split_once('<')
        && let Some((id, _)) = tail.split_once('>')
        && format!("<{id}>") != want
    {
        return Some("ArticleIdentityMismatch");
    }
    None
}
pub fn analyze(files: &[File], evidence: &[ProviderEvidence]) -> Collected {
    let (samples, omitted) = manifest::samples(files);
    let mut errors = BTreeMap::new();
    let mut providers = vec![];
    let mut articles:Vec<Value>=samples.iter().map(|s|json!({"file_index":s.file_index,"segment":s.segment_number,"role":s.role,"provider_results":{},"yenc":null})).collect();
    let mut prefixes = BTreeMap::new();
    let mut software = BTreeSet::new();
    let mut par2 = None;
    let mut par2_fallback = None;
    let invalid = files.iter().filter(|f| f.invalid).count();
    if invalid > 0 {
        add_error(&mut errors, "nzb", "NzbManifestInvalid");
        errors.insert(("nzb".into(), "NzbManifestInvalid".into()), invalid as u64);
    }
    if invalid == 0 && total_bytes(files).is_none() {
        add_error(&mut errors, "nzb", "NzbManifestInvalid");
    }
    for p in evidence {
        let mut timings = vec![];
        let mut found = 0;
        let mut missing = 0;
        let mut failed = 0;
        if p.profile != "ok" && p.skipped_reason.is_none() {
            add_error(&mut errors, &p.account, &p.profile);
        }
        for (i, sample) in samples.iter().enumerate() {
            let entry = p.articles.get(&sample.messageid);
            match entry {
                Some(Ok(article)) => {
                    found += 1;
                    timings.push(article.ms);
                    articles[i]["provider_results"][&p.account] =
                        json!({"code":222,"bytes":article.body.len(),"ms":article.ms});
                    let h = headers(article);
                    if let Some(kind) = article_identity(sample, article, &h) {
                        add_error(&mut errors, &p.account, kind);
                        continue;
                    }
                    let a = yenc::analyze(&article.body);
                    if a.summary.is_some()
                        && if a.multipart {
                            a.part != Some(sample.segment_number)
                                || a.total.is_some_and(|n| {
                                    n != files[sample.file_index].segments.len() as i64
                                })
                        } else {
                            files[sample.file_index].segments.len() != 1
                        }
                    {
                        add_error(&mut errors, &p.account, "YencPartMismatch");
                        continue;
                    }
                    for kind in &a.errors {
                        add_error(&mut errors, &p.account, kind);
                    }
                    if a.errors.is_empty() {
                        if sample.segment_number == 1
                            && let Some(md5) = a.prefix_md5
                        {
                            prefixes.entry(sample.file_index).or_insert(md5);
                        }
                        if par2.is_none()
                            && sample.kind == "par2"
                            && let Some(summary) = par2_summary(&a.decoded, files)
                        {
                            if summary["files"].as_array().is_some_and(|f| !f.is_empty()) {
                                par2 = Some(summary);
                            } else if !summary["set_id"].is_null() && par2_fallback.is_none() {
                                par2_fallback = Some(summary);
                            }
                        }
                    }
                    if articles[i]["yenc"].is_null() {
                        articles[i]["yenc"] = a.summary.unwrap_or(Value::Null);
                        for name in ["user-agent", "x-newsreader", "x-poster"] {
                            if let Some(value) = h.get(name) {
                                for candidate in ["Nyuu", "ngPost", "Postie"] {
                                    if value
                                        .to_ascii_lowercase()
                                        .contains(&candidate.to_ascii_lowercase())
                                    {
                                        software.insert(candidate);
                                    }
                                }
                            }
                        }
                    }
                }
                _ => {
                    let kind = entry
                        .and_then(|e| e.as_ref().err())
                        .map(String::as_str)
                        .unwrap_or("RuntimeException");
                    if kind == "NntpArticleMissingException" {
                        missing += 1;
                    } else {
                        failed += 1;
                    }
                    add_error(&mut errors, &p.account, kind);
                    articles[i]["provider_results"][&p.account] =
                        json!({"code":null,"bytes":null,"ms":null});
                }
            }
        }
        timings.sort_unstable();
        let n = timings.len();
        providers.push(json!({"account":p.account,"host":p.host,"role":p.account,"tls":p.tls,"port":p.port,"profile":p.profile,"skipped_reason":p.skipped_reason,"implementation":p.implementation,"articles":{"requested":samples.len(),"found":found,"missing":missing,"errors":failed},"retrieval_ms":{"p50":n.checked_sub(1).map(|n|timings[n/2]),"max":timings.last(),"total":timings.iter().sum::<u64>()}}));
    }
    let complete = !samples.is_empty()
        && errors.is_empty()
        && providers
            .first()
            .is_some_and(|p| p["articles"]["found"].as_u64() == Some(samples.len() as u64));
    let all_groups: BTreeSet<_> = files
        .iter()
        .flat_map(|f| f.groups.iter().cloned())
        .collect();
    let groups: Vec<_> = all_groups
        .iter()
        .take(20)
        .map(|s| manifest::bounded(s))
        .collect();
    let mut posters: Vec<(String, u64)> = vec![];
    let mut kinds: BTreeMap<String, u64> = BTreeMap::new();
    for file in files {
        *kinds.entry(file.kind.clone()).or_default() += 1;
        if !file.poster.is_empty() {
            if let Some(p) = posters.iter_mut().find(|p| p.0 == file.poster) {
                p.1 += 1;
            } else {
                posters.push((file.poster.clone(), 1));
            }
        }
    }
    posters.sort_by_key(|p| std::cmp::Reverse(p.1));
    let rows:Vec<_>=files.iter().take(200).map(|f|json!({"file_index":f.index,"name":manifest::bounded(manifest::filename(&f.subject)),"size":f.advertised_bytes,"first16k_md5":prefixes.get(&f.index),"format_hint":f.format_hint})).collect();
    let dates: Vec<_> = files
        .iter()
        .filter_map(|f| f.date.filter(|d| *d > 0))
        .collect();
    let posting=dates.iter().min().zip(dates.iter().max()).map(|(&first,&last)|json!({"first_utc":utc_date(first),"last_utc":utc_date(last),"span_s":last-first}));
    let par = par2.or(par2_fallback);
    let truncated = files.len() > 200
        || all_groups.len() > 20
        || par
            .as_ref()
            .and_then(|p| p["file_count"].as_u64())
            .is_some_and(|n| n > 200);
    let error_rows:Vec<_>=errors.into_iter().map(|((account,error_type),count)|json!({"account":account,"error_type":error_type,"count":count})).collect();
    let mut data = json!({"schema_version":1,"format":"compact-v1","collector":{"engine":"rust","version":1},"nzb":{"file_count":files.len(),"segment_count":files.iter().map(|f|f.segments.len()).sum::<usize>(),"total_bytes":total_bytes(files),"groups":groups,"poster":posters.first().map(|p|manifest::bounded(&p.0)),"kinds":kinds},"samples":{"limit":12,"selected":samples.len(),"omitted":omitted},"providers":providers,"articles":articles,"files":rows,"par2":par,"posting":posting,"uploader_software_candidates":software.into_iter().collect::<Vec<_>>(),"errors":error_rows,"truncated":truncated});
    budget(&mut data);
    Collected {
        status: if complete { "complete" } else { "partial" }.into(),
        data,
        timings: Timings::default(),
    }
}
fn total_bytes(files: &[File]) -> Option<i64> {
    files
        .iter()
        .try_fold(0i64, |sum, f| sum.checked_add(f.advertised_bytes?))
}
fn utc_date(t: i64) -> Option<String> {
    DateTime::<Utc>::from_timestamp(t, 0).map(|d| d.format("%Y-%m-%dT%H:%M:%SZ").to_string())
}
fn json_php_length(v: &Value) -> usize {
    let s = serde_json::to_string(v).unwrap();
    s.chars()
        .map(|c| {
            if !c.is_ascii() {
                c.len_utf16() * 6
            } else if c == '/' {
                2
            } else {
                1
            }
        })
        .sum()
}
fn budget(data: &mut Value) {
    loop {
        let mut candidate = data.clone();
        for p in candidate["providers"].as_array_mut().unwrap() {
            p["retrieval_ms"] = json!({"p50":i64::MAX,"max":i64::MAX,"total":i64::MAX});
        }
        for a in candidate["articles"].as_array_mut().unwrap() {
            for p in a["provider_results"].as_object_mut().unwrap().values_mut() {
                p["ms"] = json!(i64::MAX);
            }
        }
        if json_php_length(&candidate) <= 20480 {
            break;
        }
        data["truncated"] = json!(true);
        let files = data["files"].as_array().unwrap().len();
        let parfiles = data["par2"]["files"].as_array().map_or(0, Vec::len);
        if files > 0 || parfiles > 0 {
            let a = if files >= parfiles {
                data["files"].as_array_mut().unwrap()
            } else {
                data["par2"]["files"].as_array_mut().unwrap()
            };
            a.truncate(a.len() / 2);
        } else if data["nzb"]["groups"]
            .as_array()
            .is_some_and(|a| !a.is_empty())
        {
            data["nzb"]["groups"] = json!([]);
        } else if data["articles"]
            .as_array()
            .unwrap()
            .iter()
            .any(|a| !a["yenc"]["name"].is_null())
        {
            for a in data["articles"].as_array_mut().unwrap() {
                if a["yenc"].is_object() {
                    a["yenc"]["name"] = Value::Null;
                }
            }
        } else if !data["articles"].as_array().unwrap().is_empty() {
            let a = data["articles"].as_array_mut().unwrap();
            a.truncate(a.len() / 2);
        } else {
            break;
        }
    }
}
fn par2_summary(bytes: &[u8], files: &[File]) -> Option<Value> {
    let mut offset = 0usize;
    let mut sets = BTreeSet::new();
    let mut definitions: BTreeMap<(String, String), Vec<u8>> = BTreeMap::new();
    let mut descriptions: BTreeMap<String, Value> = BTreeMap::new();
    let mut source_names: BTreeMap<String, String> = BTreeMap::new();
    let mut main: Option<(u64, Vec<String>)> = None;
    let mut coherent = true;
    let mut recovery_packets: Vec<(u32, Vec<u8>)> = vec![];
    while offset + 64 <= bytes.len() {
        if &bytes[offset..offset + 8] != b"PAR2\0PKT" {
            offset += 1;
            continue;
        }
        let n = u64::from_le_bytes(bytes[offset + 8..offset + 16].try_into().ok()?) as usize;
        if n < 64 || !n.is_multiple_of(4) || n > bytes.len() - offset {
            coherent = false;
            break;
        }
        let packet = &bytes[offset..offset + n];
        offset += n;
        if Md5::digest(&packet[32..]).as_slice() != &packet[16..32] {
            continue;
        }
        let set = hex::encode(&packet[32..48]);
        sets.insert(set.clone());
        let kind = &packet[48..64];
        let kind = &kind[..kind.iter().rposition(|b| *b != 0).map_or(0, |i| i + 1)];
        let payload = &packet[64..];
        if kind == b"PAR 2.0\0Main" {
            let key = (set, "main".into());
            if definitions.get(&key).is_some_and(|p| p != payload) {
                coherent = false;
            }
            definitions.insert(key, payload.to_vec());
            if payload.len() < 12 || !(payload.len() - 12).is_multiple_of(16) {
                coherent = false;
                continue;
            }
            let block = u64::from_le_bytes(payload[..8].try_into().ok()?);
            let count = u32::from_le_bytes(payload[8..12].try_into().ok()?) as usize;
            let ids: Vec<_> = payload[12..]
                .as_chunks::<16>()
                .0
                .iter()
                .map(hex::encode)
                .collect();
            if block == 0
                || block > i64::MAX as u64
                || block % 4 != 0
                || count > ids.len()
                || ids.len() > 32768
                || ids.iter().collect::<BTreeSet<_>>().len() != ids.len()
            {
                coherent = false;
            } else {
                main.get_or_insert((block, ids));
            }
        } else if kind == b"PAR 2.0\0FileDesc" {
            if payload.len() < 57 {
                coherent = false;
                continue;
            }
            let id = hex::encode(&payload[..16]);
            let key = (set, id.clone());
            if definitions.get(&key).is_some_and(|p| p != payload) {
                coherent = false;
            }
            definitions.insert(key, payload.to_vec());
            let size = u64::from_le_bytes(payload[48..56].try_into().ok()?);
            let namefield = &payload[56..];
            let end = namefield
                .iter()
                .position(|b| *b == 0)
                .unwrap_or(namefield.len());
            let name = &namefield[..end];
            if name.is_empty()
                || !name.is_ascii()
                || size > i64::MAX as u64
                || namefield.len() - end != (4 - name.len() % 4) % 4
                || namefield[end..].iter().any(|b| *b != 0)
            {
                coherent = false;
                continue;
            }
            let mut derived = Md5::new();
            derived.update(&payload[32..48]);
            derived.update(&payload[48..56]);
            derived.update(name);
            if hex::encode(derived.finalize()) != id
                || (size <= 16384 && payload[16..32] != payload[32..48])
            {
                coherent = false;
            }
            source_names
                .entry(id.clone())
                .or_insert_with(|| String::from_utf8_lossy(name).into_owned());
            descriptions.entry(id).or_insert_with(||json!({"name":manifest::bounded(&String::from_utf8_lossy(name)),"size":size,"md5":hex::encode(&payload[16..32]),"md5_16k":hex::encode(&payload[32..48])}));
        } else if kind == b"PAR 2.0\0RecvSlic" && payload.len() >= 4 {
            let mut exponent = u32::from_le_bytes(payload[..4].try_into().ok()?);
            if exponent > 65535 {
                continue;
            }
            if exponent == 65535 {
                exponent = 0;
            }
            recovery_packets.push((exponent, payload[4..].to_vec()));
        }
    }
    if sets.len() != 1 {
        return None;
    }
    let set = sets.first().unwrap();
    let fallback = || json!({"set_id":set,"block_size":null,"recovery_blocks":null,"file_count":0,"files":[],"nzb_matches":0});
    let Some((block, ids)) = main else {
        return Some(fallback());
    };
    if source_names.values().collect::<BTreeSet<_>>().len() != source_names.len() {
        coherent = false;
    }
    if !coherent
        || ids.iter().any(|id| !descriptions.contains_key(id))
        || descriptions.keys().any(|id| !ids.contains(id))
    {
        return Some(fallback());
    }
    let mut rows: Vec<_> = descriptions.into_values().collect();
    rows.sort_by_key(|v| {
        (
            v["name"].as_str().unwrap_or("").to_string(),
            v["md5"].as_str().unwrap_or("").to_string(),
        )
    });
    let names: BTreeSet<_> = files
        .iter()
        .map(|f| manifest::filename(&f.subject))
        .collect();
    let matches = source_names
        .values()
        .filter(|name| names.contains(name.as_str()))
        .count();
    let count = rows.len();
    rows.truncate(200);
    let mut recovery: BTreeMap<u32, Vec<u8>> = BTreeMap::new();
    let mut recovery_conflict = false;
    for (exponent, payload) in recovery_packets {
        if payload.len() as u64 != block {
            continue;
        }
        if recovery.get(&exponent).is_some_and(|old| old != &payload) {
            recovery_conflict = true;
        }
        recovery.entry(exponent).or_insert(payload);
    }
    let recovery_blocks = if recovery_conflict {
        None
    } else {
        Some(recovery.len())
    };
    Some(
        json!({"set_id":set,"block_size":block,"recovery_blocks":recovery_blocks,"file_count":count,"files":rows,"nzb_matches":matches}),
    )
}

#[cfg(test)]
mod tests {
    use super::*;
    fn packet(kind: &[u8], payload: &[u8]) -> Vec<u8> {
        let mut out = b"PAR2\0PKT".to_vec();
        out.extend(((64 + payload.len()) as u64).to_le_bytes());
        out.extend([0u8; 16]);
        out.extend([1u8; 16]);
        let mut name = [0u8; 16];
        name[..kind.len()].copy_from_slice(kind);
        out.extend(name);
        out.extend(payload);
        let digest = Md5::digest(&out[32..]);
        out[16..32].copy_from_slice(&digest);
        out
    }
    fn description(name: &str, seed: u8) -> (Vec<u8>, Vec<u8>) {
        let hash = [seed; 16];
        let size = 4u64.to_le_bytes();
        let mut h = Md5::new();
        h.update(hash);
        h.update(size);
        h.update(name.as_bytes());
        let id = h.finalize().to_vec();
        let mut payload = id.clone();
        payload.extend(hash);
        payload.extend(hash);
        payload.extend(size);
        payload.extend(name.as_bytes());
        while !payload.len().is_multiple_of(4) {
            payload.push(0);
        }
        (id, packet(b"PAR 2.0\0FileDesc", &payload))
    }
    fn main_packet(ids: &[Vec<u8>], kind: &[u8]) -> Vec<u8> {
        let mut payload = 4u64.to_le_bytes().to_vec();
        payload.extend((ids.len() as u32).to_le_bytes());
        for id in ids {
            payload.extend(id);
        }
        packet(kind, &payload)
    }
    #[test]
    fn packet_type_must_match_exactly() {
        let (id, description) = description("x.bin", 2);
        let mut body = main_packet(&[id], b"PAR 2.0\0MainX");
        body.extend(description);
        assert_eq!(par2_summary(&body, &[]).unwrap()["files"], json!([]));
    }
    #[test]
    fn duplicate_filenames_with_different_ids_are_not_coherent() {
        let (a, da) = description("x.bin", 2);
        let (b, db) = description("x.bin", 3);
        let mut body = main_packet(&[a, b], b"PAR 2.0\0Main");
        body.extend(da);
        body.extend(db);
        assert_eq!(par2_summary(&body, &[]).unwrap()["files"], json!([]));
    }
    #[test]
    fn names_match_before_output_truncation() {
        let name = "a".repeat(130);
        let (id, description) = description(&name, 2);
        let mut body = main_packet(&[id], b"PAR 2.0\0Main");
        body.extend(description);
        let files = vec![File {
            subject: format!("\"{name}\""),
            ..File::default()
        }];
        let summary = par2_summary(&body, &files).unwrap();
        assert_eq!(summary["nzb_matches"], 1);
        assert_eq!(summary["files"][0]["name"].as_str().unwrap().len(), 120);
    }
    #[test]
    fn invalid_recovery_length_does_not_override_valid_slice() {
        let (id, description) = description("x.bin", 2);
        let mut body = main_packet(&[id], b"PAR 2.0\0Main");
        body.extend(description);
        let mut valid = 0u32.to_le_bytes().to_vec();
        valid.extend([1u8; 4]);
        body.extend(packet(b"PAR 2.0\0RecvSlic", &valid));
        let mut invalid = 0u32.to_le_bytes().to_vec();
        invalid.extend([2u8; 8]);
        body.extend(packet(b"PAR 2.0\0RecvSlic", &invalid));
        assert_eq!(par2_summary(&body, &[]).unwrap()["recovery_blocks"], 1);
    }
}
