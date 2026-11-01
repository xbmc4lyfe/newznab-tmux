//! When a task is due, in the terms Laravel's scheduler used. All times are UTC, the app's zone.

use chrono::{DateTime, Datelike, Timelike, Utc, Weekday};

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Cadence {
    /// On minutes divisible by this: `everyMinute`, `everyFiveMinutes` and so on.
    EveryMinutes(u32),
    Hourly,
    Daily {
        hour: u32,
        minute: u32,
    },
    /// At minute 0 of both hours.
    TwiceDaily(u32, u32),
    Weekly {
        day: Weekday,
        hour: u32,
        minute: u32,
    },
    /// Midnight on the first of the month.
    Monthly,
}

impl Cadence {
    pub const MIDNIGHT: Self = Self::Daily { hour: 0, minute: 0 };

    pub fn due(self, at: DateTime<Utc>) -> bool {
        let (hour, minute) = (at.hour(), at.minute());
        match self {
            Self::EveryMinutes(every) => minute.is_multiple_of(every),
            Self::Hourly => minute == 0,
            Self::Daily {
                hour: want_hour,
                minute: want_minute,
            } => hour == want_hour && minute == want_minute,
            Self::TwiceDaily(first, second) => minute == 0 && (hour == first || hour == second),
            Self::Weekly {
                day,
                hour: want_hour,
                minute: want_minute,
            } => at.weekday() == day && hour == want_hour && minute == want_minute,
            Self::Monthly => at.day() == 1 && hour == 0 && minute == 0,
        }
    }

    /// The cron expression Laravel gave the same schedule, which the parity test compares.
    pub fn cron(self) -> String {
        match self {
            Self::EveryMinutes(1) => "* * * * *".to_owned(),
            Self::EveryMinutes(every) => format!("*/{every} * * * *"),
            Self::Hourly => "0 * * * *".to_owned(),
            Self::Daily { hour, minute } => format!("{minute} {hour} * * *"),
            Self::TwiceDaily(first, second) => format!("0 {first},{second} * * *"),
            Self::Weekly { day, hour, minute } => {
                format!("{minute} {hour} * * {}", day.num_days_from_sunday())
            }
            Self::Monthly => "0 0 1 * *".to_owned(),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::TimeZone;

    fn at(day: u32, hour: u32, minute: u32) -> DateTime<Utc> {
        // 2026-11-01 is a Sunday.
        Utc.with_ymd_and_hms(2026, 11, day, hour, minute, 0)
            .single()
            .expect("valid date")
    }

    #[test]
    fn every_minutes_follow_the_clock() {
        assert!(Cadence::EveryMinutes(1).due(at(2, 3, 7)));
        assert!(Cadence::EveryMinutes(5).due(at(2, 3, 55)));
        assert!(!Cadence::EveryMinutes(5).due(at(2, 3, 56)));
        assert!(Cadence::EveryMinutes(15).due(at(2, 3, 0)));
    }

    #[test]
    fn calendar_cadences_fire_once() {
        assert!(Cadence::Hourly.due(at(2, 3, 0)));
        assert!(!Cadence::Hourly.due(at(2, 3, 1)));
        assert!(Cadence::MIDNIGHT.due(at(2, 0, 0)));
        assert!(!Cadence::MIDNIGHT.due(at(2, 1, 0)));
        let twice = Cadence::TwiceDaily(1, 13);
        assert!(twice.due(at(2, 1, 0)) && twice.due(at(2, 13, 0)));
        assert!(!twice.due(at(2, 2, 0)) && !twice.due(at(2, 13, 1)));
        let weekly = Cadence::Weekly {
            day: Weekday::Mon,
            hour: 4,
            minute: 0,
        };
        assert!(weekly.due(at(2, 4, 0)));
        assert!(!weekly.due(at(1, 4, 0)));
        assert!(Cadence::Monthly.due(at(1, 0, 0)));
        assert!(!Cadence::Monthly.due(at(2, 0, 0)));
    }
}
