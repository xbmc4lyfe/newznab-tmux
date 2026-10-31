//! What gets copied: every source table, column and destination, as data.

/// How a source value is stored.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Kind {
    Int,
    Real,
    Text,
    Blob,
}

#[derive(Clone, Copy, Debug)]
pub struct Column {
    /// A column or expression in the source `SELECT`.
    pub source: &'static str,
    pub dest: &'static str,
    pub kind: Kind,
}

const fn int(source: &'static str, dest: &'static str) -> Column {
    Column {
        source,
        dest,
        kind: Kind::Int,
    }
}
const fn real(source: &'static str, dest: &'static str) -> Column {
    Column {
        source,
        dest,
        kind: Kind::Real,
    }
}
const fn text(source: &'static str, dest: &'static str) -> Column {
    Column {
        source,
        dest,
        kind: Kind::Text,
    }
}
const fn blob(source: &'static str, dest: &'static str) -> Column {
    Column {
        source,
        dest,
        kind: Kind::Blob,
    }
}

/// A table. The first column is the key that batches split on.
#[derive(Debug)]
pub struct Table {
    /// The `FROM` clause. Its rows are aliased `c`.
    pub from: &'static str,
    pub dest: &'static str,
    pub columns: &'static [Column],
    pub floor: SizeFloor,
}

/// How a table's rows are limited to releases at or above the size floor.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum SizeFloor {
    /// Copied whole.
    None,
    /// This is the releases table.
    OwnSize,
    /// The rows belong to a release.
    ReleaseSize,
}

impl Table {
    fn key(&self) -> &'static str {
        self.columns[0].source
    }

    fn source_columns(&self) -> String {
        self.columns
            .iter()
            .map(|c| c.source)
            .collect::<Vec<_>>()
            .join(", ")
    }

    fn dest_columns(&self) -> String {
        self.columns
            .iter()
            .map(|c| c.dest)
            .collect::<Vec<_>>()
            .join(", ")
    }

    fn release_join(&self) -> String {
        match self.floor {
            SizeFloor::ReleaseSize => format!(" JOIN releases fr ON fr.id = {}", self.key()),
            SizeFloor::None | SizeFloor::OwnSize => String::new(),
        }
    }

    fn floor_test(&self) -> Option<&'static str> {
        match self.floor {
            SizeFloor::None => None,
            SizeFloor::OwnSize => Some("c.size >= ?"),
            SizeFloor::ReleaseSize => Some("fr.size >= ?"),
        }
    }

    /// Whether the statements below bind the size floor.
    pub fn has_floor(&self) -> bool {
        self.floor != SizeFloor::None
    }

    /// Reads rows with a key in a range. Binds: low, high, and the floor if any.
    pub fn select_range(&self) -> String {
        let floor = self
            .floor_test()
            .map_or(String::new(), |test| format!(" AND {test}"));
        format!(
            "SELECT {} FROM {}{} WHERE {} BETWEEN ? AND ?{floor}",
            self.source_columns(),
            self.from,
            self.release_join(),
            self.key(),
        )
    }

    /// Counts everything this table would copy. Binds: the floor if any.
    pub fn select_count(&self) -> String {
        let floor = self
            .floor_test()
            .map_or(String::new(), |test| format!(" WHERE {test}"));
        format!(
            "SELECT COUNT(*) FROM {}{}{floor}",
            self.from,
            self.release_join()
        )
    }

    /// Fails on a missing table or column without reading any rows.
    pub fn select_probe(&self) -> String {
        format!(
            "SELECT {} FROM {}{} WHERE 1 = 0",
            self.source_columns(),
            self.from,
            self.release_join(),
        )
    }

    pub fn insert(&self) -> String {
        let marks = vec!["?"; self.columns.len()].join(", ");
        format!(
            "INSERT OR REPLACE INTO {} ({}) VALUES ({marks})",
            self.dest,
            self.dest_columns()
        )
    }

    /// Reads back from the catalog, for verification. Binds: low, high.
    pub fn dest_range(&self) -> String {
        format!(
            "SELECT {} FROM {} WHERE {} BETWEEN ?1 AND ?2",
            self.dest_columns(),
            self.dest,
            self.columns[0].dest,
        )
    }

    pub fn dest_count(&self) -> String {
        format!("SELECT COUNT(*) FROM {}", self.dest)
    }
}

/// IDs of 0 and empty or placeholder IMDb IDs meant "none" in the old schema.
pub static RELEASES: Table = Table {
    from: "releases c \
           LEFT JOIN videos v ON v.id = NULLIF(c.videos_id, 0) \
           LEFT JOIN tv_episodes e ON e.id = NULLIF(c.tv_episodes_id, 0) \
           LEFT JOIN movieinfo m ON m.id = NULLIF(c.movieinfo_id, 0)",
    dest: "releases",
    floor: SizeFloor::OwnSize,
    columns: &[
        int("c.id", "id"),
        text("c.guid", "guid"),
        text("c.name", "name"),
        text("c.searchname", "search_name"),
        int("c.size", "size_bytes"),
        int("c.totalpart", "total_parts"),
        real("c.completion", "completion"),
        int("c.groups_id", "group_id"),
        text("c.fromname", "poster"),
        int("UNIX_TIMESTAMP(c.postdate)", "posted_at"),
        int("UNIX_TIMESTAMP(c.adddate)", "added_at"),
        int("c.categories_id", "legacy_category"),
        int("NULLIF(c.predb_id, 0)", "predb_id"),
        int("c.passwordstatus", "password_status"),
        int("c.isrenamed", "is_renamed"),
        int("c.grabs", "grabs"),
        text("COALESCE(v.title, NULLIF(m.title, ''))", "media_title"),
        text("NULLIF(m.year, '')", "media_year"),
        text(
            "COALESCE(NULLIF(NULLIF(NULLIF(c.imdbid, ''), '0'), '0000000'), \
             NULLIF(NULLIF(NULLIF(m.imdbid, ''), '0'), '0000000'), \
             NULLIF(NULLIF(NULLIF(v.imdb, ''), '0'), '0000000'))",
            "imdb_id",
        ),
        int(
            "COALESCE(NULLIF(m.tmdbid, 0), NULLIF(v.tmdb, 0))",
            "tmdb_id",
        ),
        int("NULLIF(v.tvdb, 0)", "tvdb_id"),
        int("NULLIF(v.tvmaze, 0)", "tvmaze_id"),
        int(
            "COALESCE(NULLIF(m.traktid, 0), NULLIF(v.trakt, 0))",
            "trakt_id",
        ),
        int("NULLIF(v.tvrage, 0)", "tvrage_id"),
        int("e.series", "season"),
        int("e.episode", "episode"),
    ],
};

/// Tables with rows belonging to a release.
pub static RELEASE_CHILDREN: &[&Table] = &[
    &Table {
        from: "releases_groups c",
        dest: "release_groups",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            int("c.groups_id", "group_id"),
        ],
    },
    &Table {
        from: "release_files c",
        dest: "release_files",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            text("c.name", "name"),
            int("c.size", "size_bytes"),
            text("c.crc32", "crc32"),
            int("c.passworded", "passworded"),
        ],
    },
    &Table {
        from: "release_nfos c",
        dest: "release_nfos",
        floor: SizeFloor::ReleaseSize,
        columns: &[int("c.releases_id", "release_id"), blob("c.nfo", "nfo")],
    },
    &Table {
        from: "release_nzb_passwords c",
        dest: "release_passwords",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            text("c.password", "password"),
        ],
    },
    &Table {
        from: "par_hashes c",
        dest: "par_hashes",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            text("c.hash", "hash16k"),
        ],
    },
    &Table {
        from: "par_file_hashes c",
        dest: "par_file_hashes",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            int("c.id", "id"),
            text("c.hash16k", "hash16k"),
            text("c.full_md5", "full_md5"),
            text("c.file_size", "file_size"),
        ],
    },
    &Table {
        from: "par2_descriptor_evidence c",
        dest: "par2_descriptors",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            int("c.id", "id"),
            text("c.packet_checksum", "packet_checksum"),
            text("c.recovery_set_id", "recovery_set_id"),
            text("c.file_id", "file_id"),
            text("c.full_md5", "full_md5"),
            text("c.hash16k", "hash16k"),
            text("c.file_size", "file_size"),
            text("c.raw_filename_base64", "raw_filename_base64"),
            int("c.descriptor_proof_version", "proof_version"),
            text("c.classification", "classification"),
            int("c.classification_version", "classification_version"),
            text("c.classification_provenance", "classification_provenance"),
        ],
    },
    &Table {
        from: "release_archive_naming_evidence c",
        dest: "archive_naming_evidence",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            text("c.source_sha256", "source_sha256"),
            int("c.proof_version", "proof_version"),
            text("c.scope", "scope"),
            text("c.episode_files", "episode_files"),
            int("UNIX_TIMESTAMP(c.updated_at)", "updated_at"),
        ],
    },
    &Table {
        from: "video_data c",
        dest: "media_video",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            text("c.containerformat", "container_format"),
            text("c.overallbitrate", "overall_bitrate"),
            text("c.videoduration", "duration"),
            text("c.videoformat", "video_format"),
            text("c.videocodec", "codec"),
            int("c.videowidth", "width"),
            int("c.videoheight", "height"),
            text("c.videoaspect", "aspect"),
            real("c.videoframerate", "frame_rate"),
            text("c.videolibrary", "library"),
        ],
    },
    &Table {
        from: "audio_data c",
        dest: "media_audio",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            int("c.id", "id"),
            int("c.audioid", "track_id"),
            text("c.audioformat", "format"),
            text("c.audiomode", "mode"),
            text("c.audiobitratemode", "bitrate_mode"),
            text("c.audiobitrate", "bitrate"),
            text("c.audiochannels", "channels"),
            text("c.audiosamplerate", "sample_rate"),
            text("c.audiolibrary", "library"),
            text("c.audiolanguage", "language"),
            text("c.audiotitle", "title"),
        ],
    },
    &Table {
        from: "release_subtitles c",
        dest: "media_subtitles",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            int("c.id", "id"),
            int("c.subsid", "track_id"),
            text("c.subslanguage", "language"),
        ],
    },
    &Table {
        from: "release_unique c",
        dest: "media_unique_ids",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            text("c.uniqueid", "unique_id"),
        ],
    },
    &Table {
        from: "media_infos c",
        dest: "media_infos",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            int("c.id", "id"),
            text("c.movie_name", "movie_name"),
            text("c.file_name", "file_name"),
            text("c.unique_id", "unique_id"),
        ],
    },
    &Table {
        from: "release_fingerprint_metadata c",
        dest: "fingerprint_metadata",
        floor: SizeFloor::ReleaseSize,
        columns: &[
            int("c.releases_id", "release_id"),
            text("c.status", "status"),
            text("c.metadata", "metadata"),
            text("c.last_error", "last_error"),
            int("UNIX_TIMESTAMP(c.collected_at)", "collected_at"),
        ],
    },
];

/// Copied whole, whatever the size floor.
pub static GROUPS: Table = Table {
    from: "usenet_groups c",
    dest: "usenet_groups",
    floor: SizeFloor::None,
    columns: &[int("c.id", "id"), text("c.name", "name")],
};

pub static PREDB: Table = Table {
    from: "predb c",
    dest: "predb",
    floor: SizeFloor::None,
    columns: &[
        int("c.id", "id"),
        text("c.title", "title"),
        text("c.nfo", "nfo"),
        text("c.size", "size"),
        text("c.category", "category"),
        int("UNIX_TIMESTAMP(c.predate)", "pre_at"),
        int("UNIX_TIMESTAMP(c.imported_at)", "imported_at"),
        text("c.source", "source"),
        int("c.requestid", "request_id"),
        int("c.groups_id", "group_id"),
        int("c.nuked", "nuked"),
        text("c.nukereason", "nuke_reason"),
        text("c.files", "files"),
        text("c.filename", "filename"),
    ],
};

/// Every table above, for probes and counts.
pub fn all_tables() -> Vec<&'static Table> {
    let mut tables = vec![&GROUPS, &PREDB, &RELEASES];
    tables.extend(RELEASE_CHILDREN.iter().copied());
    tables
}

/// Finds release IDs to batch on. Binds: last ID, size floor, limit.
pub const SELECT_RELEASE_PLAN: &str = "SELECT r.id, COALESCE(n.decoded_bytes, 0) FROM releases r \
     LEFT JOIN release_nzb_contents n ON n.releases_id = r.id \
     WHERE r.id > ? AND r.size >= ? ORDER BY r.id LIMIT ?";

/// Reads NZB bodies for a range. Binds: low, high, size floor.
pub const SELECT_NZBS: &str = "SELECT n.releases_id, n.guid, n.source_digest, n.decoded_bytes, \
     n.body FROM release_nzb_contents n JOIN releases r ON r.id = n.releases_id \
     WHERE n.releases_id BETWEEN ? AND ? AND r.size >= ?";

/// Has the source hash each stored NZB for a range. Binds: low, high, size floor.
pub const SELECT_NZB_HASHES: &str = "SELECT n.releases_id, n.source_digest, n.decoded_bytes, \
     SHA2(n.body, 256) FROM release_nzb_contents n JOIN releases r ON r.id = n.releases_id \
     WHERE n.releases_id BETWEEN ? AND ? AND r.size >= ?";

/// Counts the NZBs an import should copy. Binds: size floor.
pub const SELECT_NZB_COUNT: &str = "SELECT COUNT(*) FROM release_nzb_contents n \
     JOIN releases r ON r.id = n.releases_id WHERE r.size >= ?";

/// Row count and total bytes of releases at or above the floor. Binds: size floor.
pub const SELECT_RELEASE_TOTALS: &str =
    "SELECT COUNT(*), CAST(COALESCE(SUM(size), 0) AS UNSIGNED) FROM releases WHERE size >= ?";

/// Next batch of a table walked in key order. Binds: last key, limit.
pub fn select_keyset(table: &Table) -> String {
    format!(
        "SELECT {} FROM {} WHERE {key} > ? ORDER BY {key} LIMIT ?",
        table.source_columns(),
        table.from,
        key = table.key(),
    )
}

#[cfg(test)]
mod tests {
    use std::collections::HashMap;

    use indexer_store::Catalog;

    use super::*;

    fn catalog() -> (tempfile::TempDir, Catalog) {
        let dir = tempfile::tempdir().unwrap();
        let catalog = Catalog::open(&dir.path().join("catalog.db")).unwrap();
        (dir, catalog)
    }

    fn declared_types(catalog: &Catalog, table: &str) -> HashMap<String, String> {
        let mut stmt = catalog
            .conn()
            .prepare(&format!(
                "SELECT name, type FROM pragma_table_info('{table}')"
            ))
            .unwrap();
        let rows = stmt
            .query_map([], |row| Ok((row.get(0)?, row.get(1)?)))
            .unwrap();
        rows.map(Result::unwrap).collect()
    }

    #[test]
    fn every_generated_source_statement_only_reads() {
        let mut statements = vec![
            SELECT_RELEASE_PLAN.to_owned(),
            SELECT_NZBS.to_owned(),
            SELECT_NZB_HASHES.to_owned(),
            SELECT_NZB_COUNT.to_owned(),
            SELECT_RELEASE_TOTALS.to_owned(),
        ];
        for table in all_tables() {
            statements.extend([
                table.select_range(),
                table.select_count(),
                table.select_probe(),
                select_keyset(table),
            ]);
        }
        for statement in statements {
            crate::source::assert_read_only(&statement).unwrap();
        }
    }

    #[test]
    fn specs_match_the_catalog_schema_exactly() {
        let (_dir, catalog) = catalog();
        for table in all_tables() {
            catalog.conn().prepare(&table.insert()).unwrap();
            catalog.conn().prepare(&table.dest_range()).unwrap();
            let declared = declared_types(&catalog, table.dest);
            assert_eq!(
                declared.len(),
                table.columns.len(),
                "columns of {}",
                table.dest
            );
            for column in table.columns {
                let expected = match column.kind {
                    Kind::Int => "INTEGER",
                    Kind::Real => "REAL",
                    Kind::Text => "TEXT",
                    Kind::Blob => "BLOB",
                };
                assert_eq!(
                    declared[column.dest], expected,
                    "{}.{}",
                    table.dest, column.dest
                );
            }
        }
    }

    #[test]
    fn child_tables_batch_on_the_release_id() {
        for table in RELEASE_CHILDREN {
            assert_eq!(table.floor, SizeFloor::ReleaseSize);
            assert_eq!(table.columns[0].source, "c.releases_id", "{}", table.dest);
            assert_eq!(table.columns[0].dest, "release_id", "{}", table.dest);
        }
    }

    #[test]
    fn range_select_applies_the_size_floor_only_when_asked() {
        let child = RELEASE_CHILDREN[0].select_range();
        assert!(
            child.contains("JOIN releases fr ON fr.id = c.releases_id"),
            "{child}"
        );
        assert!(
            child.ends_with("BETWEEN ? AND ? AND fr.size >= ?"),
            "{child}"
        );
        assert!(
            RELEASES
                .select_range()
                .ends_with("c.id BETWEEN ? AND ? AND c.size >= ?")
        );
        assert!(PREDB.select_range().ends_with("c.id BETWEEN ? AND ?"));
    }
}
