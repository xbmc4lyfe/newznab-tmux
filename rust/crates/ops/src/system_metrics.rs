//! `metrics:collect`: the CPU and RAM history behind the admin dashboard.

use std::fs;

use anyhow::Result;
use chrono::Utc;
use sqlx::MySqlPool;
use tracing::warn;

/// The one-minute load average as a percentage of the CPU count.
pub fn cpu_percent(loadavg: &str, cpuinfo: &str) -> Option<f64> {
    let load: f64 = loadavg.split_whitespace().next()?.parse().ok()?;
    let cpus = cpuinfo
        .lines()
        .filter(|line| line.starts_with("processor"))
        .count()
        .max(1);
    Some(round2(load / cpus as f64 * 100.0))
}

pub fn ram_percent(meminfo: &str) -> Option<f64> {
    let total = meminfo_kb(meminfo, "MemTotal:")?;
    let available = meminfo_kb(meminfo, "MemAvailable:")?;
    (total > 0.0).then(|| round2((total - available) / total * 100.0))
}

fn meminfo_kb(meminfo: &str, key: &str) -> Option<f64> {
    meminfo
        .lines()
        .find_map(|line| line.strip_prefix(key))?
        .split_whitespace()
        .next()?
        .parse()
        .ok()
}

fn round2(value: f64) -> f64 {
    (value * 100.0).round() / 100.0
}

/// A host that can't report a value records 0, so a gap in `/proc` never stops collection.
pub async fn collect(db: &MySqlPool) -> Result<()> {
    let cpu = read_cpu().unwrap_or_else(|| {
        warn!("could not read the CPU load");
        0.0
    });
    let ram = fs::read_to_string("/proc/meminfo")
        .ok()
        .as_deref()
        .and_then(ram_percent)
        .unwrap_or_else(|| {
            warn!("could not read the memory use");
            0.0
        });
    let now = Utc::now().naive_utc();
    for (metric, value) in [("cpu", cpu), ("ram", ram)] {
        sqlx::query(
            "INSERT INTO system_metrics (metric_type, value, recorded_at, created_at, updated_at) \
             VALUES (?, ?, ?, NOW(), NOW())",
        )
        .bind(metric)
        .bind(value)
        .bind(now)
        .execute(db)
        .await?;
    }
    Ok(())
}

fn read_cpu() -> Option<f64> {
    let loadavg = fs::read_to_string("/proc/loadavg").ok()?;
    let cpuinfo = fs::read_to_string("/proc/cpuinfo").ok()?;
    cpu_percent(&loadavg, &cpuinfo)
}

/// Keeps the last 60 days.
pub async fn cleanup(db: &MySqlPool) -> Result<u64> {
    Ok(
        sqlx::query("DELETE FROM system_metrics WHERE recorded_at < NOW() - INTERVAL 60 DAY")
            .execute(db)
            .await?
            .rows_affected(),
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn cpu_is_load_over_core_count() {
        let cpuinfo = "processor\t: 0\nmodel name\t: x\nprocessor\t: 1\n";
        assert_eq!(cpu_percent("1.50 0.2 0.1 1/2 3", cpuinfo), Some(75.0));
        assert_eq!(cpu_percent("1.235 0 0", ""), Some(123.5));
        assert_eq!(cpu_percent("", cpuinfo), None);
    }

    #[test]
    fn ram_uses_available_memory() {
        let meminfo = "MemTotal:  1000 kB\nMemFree: 1 kB\nMemAvailable:   333 kB\n";
        assert_eq!(ram_percent(meminfo), Some(66.7));
        assert_eq!(ram_percent("MemTotal: 1000 kB\n"), None);
    }
}
