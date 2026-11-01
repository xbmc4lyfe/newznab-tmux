-- The seed baseline left out the two categories Laravel's migrations add, and the categorizer
-- assigns both. Existing databases already have them from those migrations.
INSERT IGNORE INTO `categories` (`id`, `title`, `root_categories_id`, `status`, `description`, `disablepreview`, `minsizetoformrelease`, `maxsizetoformrelease`) VALUES (6046,'VR',6000,1,NULL,0,0,0);
INSERT IGNORE INTO `categories` (`id`, `title`, `root_categories_id`, `status`, `description`, `disablepreview`, `minsizetoformrelease`, `maxsizetoformrelease`) VALUES (6047,'OnlyFans',6000,1,'OnlyFans releases',0,0,0);
