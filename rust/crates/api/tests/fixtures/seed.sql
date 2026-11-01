-- Rows every API golden case starts from. Applied by record.py to the PHP reference database and
-- by tests/golden.rs to each test database. Only rows the migrations don't seed live here.
DELETE FROM model_has_roles;
DELETE FROM model_has_permissions;
DELETE FROM users;
INSERT INTO users (id, username, email, password, roles_id, api_token, verified, email_verified_at, rate_limit, can_post, grabs) VALUES
  (1, 'alice', 'alice@example.test', 'x', 1, 'tokenalice', 1, '2026-01-01 00:00:00', 60, 0, 3),
  (2, 'unverified', 'unverified@example.test', 'x', 1, 'tokenunverified', 0, NULL, 60, 0, 0),
  (3, 'disabled', 'disabled@example.test', 'x', 3, 'tokendisabled', 1, '2026-01-01 00:00:00', 60, 0, 0),
  (4, 'poster', 'poster@example.test', 'x', 2, 'tokenposter', 1, '2026-01-01 00:00:00', 2, 1, 0),
  (5, 'deleted', 'deleted@example.test', 'x', 1, 'tokendeleted', 1, '2026-01-01 00:00:00', 60, 0, 0);
UPDATE users SET deleted_at = '2026-01-02 00:00:00' WHERE id = 5;
INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES
  (1, 'App\\Models\\User', 1), (1, 'App\\Models\\User', 2), (3, 'App\\Models\\User', 3),
  (2, 'App\\Models\\User', 4), (1, 'App\\Models\\User', 5);
INSERT INTO model_has_permissions (permission_id, model_type, model_id)
  SELECT id, 'App\\Models\\User', 1 FROM permissions WHERE name IN ('view console', 'view movies', 'view audio', 'view pc', 'view tv', 'view books', 'view other');
INSERT INTO model_has_permissions (permission_id, model_type, model_id)
  SELECT id, 'App\\Models\\User', 4 FROM permissions WHERE name LIKE 'view %';
DELETE FROM releases;
DELETE FROM usenet_groups WHERE id >= 9000 OR name IN ('alt.binaries.teevee', 'alt.binaries.moovee');
UPDATE usenet_groups SET active = 0;
INSERT INTO usenet_groups (id, name, description, active, last_updated) VALUES
  (9001, 'alt.binaries.teevee', 'TV & "more" <here>', 1, '2026-02-03 04:05:06'),
  (9002, 'alt.binaries.moovee', NULL, 1, NULL);
TRUNCATE user_requests;
TRUNCATE user_downloads;
DELETE FROM release_informs;
DELETE FROM release_nfos;
DELETE FROM release_nzb_contents;
UPDATE ingestion_configurations SET grab_status = 1, categorize_web_dl = 0, show_passworded_releases = 0;
DELETE FROM users_releases;
DELETE FROM releases;
DELETE FROM tv_episodes;
DELETE FROM tv_info;
DELETE FROM videos;
DELETE FROM movieinfo;
DELETE FROM musicinfo;
DELETE FROM consoleinfo;
DELETE FROM bookinfo;
DELETE FROM anidb_info;
DELETE FROM user_movies;
DELETE FROM user_series;
INSERT INTO videos (id, type, title, countries_id, started, imdb, tmdb, trakt, tvdb, tvmaze, tvrage, source) VALUES
  (70, 0, 'Some Show', 'US', '2020-01-01 00:00:00', '0123456', 71, 72, 73, 74, 75, 1);
INSERT INTO tv_info (videos_id, summary, publisher, localzone, image) VALUES (70, 'A show.', 'Net', 'UTC', 1);
INSERT INTO tv_episodes (id, videos_id, series, episode, se_complete, title, firstaired, summary) VALUES
  (700, 70, 2, 3, 'S02E03', 'The Episode', '2026-03-04', 'Things happen.');
INSERT INTO movieinfo (id, imdbid, tmdbid, traktid, title, tagline, rating, rtrating, plot, year, genre, type, director, actors, language, cover, backdrop, trailer) VALUES
  (80, '1234567', 81, 82, 'A Movie', 'Tag', '7.5', '90', 'Plot & <stuff>', '2024', 'Drama', 'movie', 'Dir', 'Act One, Act Two', 'en', 1, 0, '');
INSERT INTO musicinfo (id, title, url, artist, publisher, releasedate, review, year, genres_id, tracks, cover) VALUES
  (90, 'An Album', 'https://music.example.test/a', 'An Artist', 'A Label', '2021-02-03 00:00:00', 'Fine.', '2021', NULL, 'One | Two', 0);
INSERT INTO consoleinfo (id, title, url, publisher, genres_id, releasedate, review, cover) VALUES
  (91, 'A Game', 'https://games.example.test/a', 'A Studio', NULL, '2022-02-03 00:00:00', 'Fun.', 0);
INSERT INTO bookinfo (id, title, author, publisher, genre, cover) VALUES (92, 'A Book', 'An Author', 'A Press', '', 0);
INSERT INTO anidb_info (anidbid, anilist_id, updated) VALUES (93, 94, '2026-01-01 00:00:00');
INSERT INTO releases (id, name, searchname, totalpart, groups_id, size, postdate, adddate, guid, leftguid, fromname, completion, categories_id, videos_id, tv_episodes_id, imdbid, musicinfo_id, consoleinfo_id, gamesinfo_id, bookinfo_id, anidbid, movieinfo_id, predb_id, grabs, comments, passwordstatus, nfostatus, nzbstatus, articles_found, articles_expected, files_found, files_expected) VALUES
  (1, 'movie.raw', 'A.Movie.2024.1080p.BluRay-GRP', 40, 9002, 5368709120, '2026-05-01 10:00:00', '2026-05-01 12:00:00', 'aaaa000000000000000000000000000000000001', 'a', 'poster@example.test (Poster)', 100, 2040, 0, 0, '1234567', NULL, NULL, 0, NULL, NULL, 80, 5, 3, 2, 0, 1, 1, NULL, NULL, NULL, NULL),
  (2, 'show.raw', 'Some.Show.S02E03.720p.WEB-GRP & <more>', 12, 9001, 734003200, '2026-05-02 10:00:00', '2026-05-02 12:00:00', 'bbbb000000000000000000000000000000000002', 'b', 'tv@example.test', 100, 5040, 70, 700, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 1, NULL, NULL, NULL, NULL),
  (3, 'album.raw', 'An.Artist-An.Album-2021-FLAC', 15, 9001, 314572800, '2026-05-03 10:00:00', '2026-05-03 12:00:00', 'cccc000000000000000000000000000000000003', 'c', 'music@example.test', 100, 3040, 0, 0, NULL, 90, NULL, 0, NULL, NULL, NULL, 0, 1, 0, 1, 0, 1, NULL, NULL, NULL, NULL),
  (4, 'game.raw', 'A.Game.PS4-GRP', 90, 9001, 21474836480, '2026-05-04 10:00:00', '2026-05-04 12:00:00', 'dddd000000000000000000000000000000000004', 'd', 'game@example.test', 100, 1180, 0, 0, NULL, NULL, 91, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 1, NULL, NULL, NULL, NULL),
  (5, 'book.raw', 'An.Author-A.Book.EPUB', 1, 9001, 1048576, '2026-05-05 10:00:00', '2026-05-05 12:00:00', 'eeee000000000000000000000000000000000005', 'e', 'book@example.test', 100, 7020, 0, 0, NULL, NULL, NULL, 0, 92, NULL, NULL, 0, 0, 0, 0, 0, 1, NULL, NULL, NULL, NULL),
  (6, 'anime.raw', 'An.Anime.E01.1080p-GRP', 8, 9001, 1073741824, '2026-05-06 10:00:00', '2026-05-06 12:00:00', 'ffff000000000000000000000000000000000006', 'f', 'anime@example.test', 100, 5070, 0, 0, NULL, NULL, NULL, 0, NULL, 93, NULL, 0, 0, 0, 0, 0, 1, NULL, NULL, NULL, NULL),
  (7, 'adult.raw', 'Adult.Release.XXX-GRP', 8, 9001, 1073741824, '2026-05-07 10:00:00', '2026-05-07 12:00:00', '7777000000000000000000000000000000000007', '7', 'x@example.test', 100, 6040, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 1, NULL, NULL, NULL, NULL),
  (8, 'partial.raw', 'Partial.Release.2026-GRP', 10, 9001, 1000, '2026-05-08 10:00:00', '2026-05-08 12:00:00', '8888000000000000000000000000000000000008', '8', 'p@example.test', 80, 2040, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 1, 80, 100, 8, 10),
  (9, 'pw.raw', 'Passworded.Release-GRP', 10, 9001, 1000, '2026-05-09 10:00:00', '2026-05-09 12:00:00', '9999000000000000000000000000000000000009', '9', 'p@example.test', 100, 10, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 1, 0, 1, NULL, NULL, NULL, NULL);
DELETE FROM registration_periods;
UPDATE registration_configurations SET status = 0;
