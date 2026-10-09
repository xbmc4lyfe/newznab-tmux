-- Curated starter groups for the docker/ stack: active for new headers and backfilled 3 days.
-- Re-runnable: existing groups are (re)activated without touching their article pointers.
INSERT INTO usenet_groups (name, backfill_target, active, backfill, description)
VALUES
    ('alt.binaries.teevee', 3, 1, 1, 'TV'),
    ('alt.binaries.moovee', 3, 1, 1, 'Movies'),
    ('alt.binaries.hdtv.x264', 3, 1, 1, 'HD TV'),
    ('alt.binaries.hdtv', 3, 1, 1, 'HD TV'),
    ('alt.binaries.boneless', 3, 1, 1, 'Misc'),
    ('alt.binaries.multimedia', 3, 1, 1, 'Multimedia'),
    ('alt.binaries.tv', 3, 1, 1, 'TV'),
    ('alt.binaries.x264', 3, 1, 1, 'x264'),
    ('alt.binaries.movies.divx', 3, 1, 1, 'Movies'),
    ('alt.binaries.movies.x264', 3, 1, 1, 'Movies'),
    ('alt.binaries.misc', 3, 1, 1, 'Misc'),
    ('alt.binaries.cores', 3, 1, 1, 'Misc'),
    ('alt.binaries.mom', 3, 1, 1, 'Misc'),
    ('alt.binaries.etc', 3, 1, 1, 'Misc'),
    ('alt.binaries.dvd', 3, 1, 1, 'DVD'),
    ('alt.binaries.bloaf', 3, 1, 1, 'Misc'),
    ('alt.binaries.ath', 3, 1, 1, 'Misc'),
    ('alt.binaries.sounds.lossless', 3, 1, 1, 'Music (lossless)'),
    ('alt.binaries.sounds.mp3.complete_cd', 3, 1, 1, 'Music (mp3)'),
    ('alt.binaries.e-book', 3, 1, 1, 'Books')
ON DUPLICATE KEY UPDATE active = 1, backfill = 1, backfill_target = VALUES(backfill_target);
