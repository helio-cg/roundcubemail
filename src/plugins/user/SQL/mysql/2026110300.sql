CREATE TABLE IF NOT EXISTS `user_settings` (
 `user_id` int(10) UNSIGNED NOT NULL,
 `display_name` varchar(255) NOT NULL DEFAULT '',
 `colors` text DEFAULT NULL,
 `created` datetime NOT NULL DEFAULT '1000-01-01 00:00:00',
 `changed` datetime NOT NULL DEFAULT '1000-01-01 00:00:00',
 PRIMARY KEY(`user_id`)
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
