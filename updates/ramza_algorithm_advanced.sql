-- Ramza advanced recommendation layer
-- Additive upgrade for the existing Algorithm Control feature.
-- Run only after taking a backup and verifying the target installation.

CREATE TABLE IF NOT EXISTS `Ramza_AlgorithmTopics` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int unsigned NOT NULL DEFAULT 0,
  `slug` varchar(160) NOT NULL,
  `name` varchar(190) NOT NULL,
  `topic_type` varchar(24) NOT NULL DEFAULT 'topic',
  `language` varchar(16) NOT NULL DEFAULT 'und',
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `post_count` int unsigned NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ramza_algorithm_topic` (`slug`,`topic_type`,`language`),
  KEY `idx_ramza_algorithm_topic_parent` (`parent_id`,`status`),
  KEY `idx_ramza_algorithm_topic_count` (`status`,`post_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_AlgorithmTopicAliases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `topic_id` int unsigned NOT NULL,
  `alias` varchar(190) NOT NULL,
  `normalized_alias` varchar(190) NOT NULL,
  `language` varchar(16) NOT NULL DEFAULT 'und',
  `created_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ramza_algorithm_alias` (`normalized_alias`,`language`,`topic_id`),
  KEY `idx_ramza_algorithm_alias_topic` (`topic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_AlgorithmTopicRelations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `topic_id` int unsigned NOT NULL,
  `related_topic_id` int unsigned NOT NULL,
  `relation_type` varchar(24) NOT NULL DEFAULT 'related',
  `weight` decimal(7,4) NOT NULL DEFAULT 0.5000,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ramza_algorithm_relation` (`topic_id`,`related_topic_id`,`relation_type`),
  KEY `idx_ramza_algorithm_relation_reverse` (`related_topic_id`,`weight`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_AlgorithmPostTopics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int unsigned NOT NULL,
  `topic_id` int unsigned NOT NULL,
  `source` varchar(24) NOT NULL DEFAULT 'keyword',
  `confidence` decimal(7,4) NOT NULL DEFAULT 0.5000,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ramza_algorithm_post_topic` (`post_id`,`topic_id`),
  KEY `idx_ramza_algorithm_topic_posts` (`topic_id`,`confidence`,`post_id`),
  KEY `idx_ramza_algorithm_post_topics_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_AlgorithmUserInterests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `topic_id` int unsigned NOT NULL,
  `score` decimal(12,5) NOT NULL DEFAULT 0.00000,
  `positive_score` decimal(12,5) NOT NULL DEFAULT 0.00000,
  `negative_score` decimal(12,5) NOT NULL DEFAULT 0.00000,
  `event_count` int unsigned NOT NULL DEFAULT 0,
  `last_event_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ramza_algorithm_user_topic` (`user_id`,`topic_id`),
  KEY `idx_ramza_algorithm_user_interest` (`user_id`,`score`,`last_event_at`),
  KEY `idx_ramza_algorithm_topic_interest` (`topic_id`,`score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_AlgorithmEvents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `post_id` int unsigned NOT NULL DEFAULT 0,
  `topic_id` int unsigned NOT NULL DEFAULT 0,
  `event_type` varchar(32) NOT NULL,
  `strength` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `duration_ms` int unsigned NOT NULL DEFAULT 0,
  `surface` varchar(24) NOT NULL DEFAULT 'home',
  `session_key` char(40) NOT NULL DEFAULT '',
  `event_key` char(40) NOT NULL DEFAULT '',
  `metadata_json` text,
  `processed_at` int unsigned NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ramza_algorithm_event` (`user_id`,`event_key`),
  KEY `idx_ramza_algorithm_events_process` (`processed_at`,`id`),
  KEY `idx_ramza_algorithm_events_user_time` (`user_id`,`created_at`),
  KEY `idx_ramza_algorithm_events_post_time` (`post_id`,`created_at`),
  KEY `idx_ramza_algorithm_events_topic_time` (`topic_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_AlgorithmImpressions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `post_id` int unsigned NOT NULL,
  `surface` varchar(24) NOT NULL DEFAULT 'home',
  `session_key` char(40) NOT NULL DEFAULT '',
  `impression_count` smallint unsigned NOT NULL DEFAULT 1,
  `dwell_ms` int unsigned NOT NULL DEFAULT 0,
  `first_seen_at` int unsigned NOT NULL DEFAULT 0,
  `last_seen_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ramza_algorithm_impression` (`user_id`,`post_id`,`surface`,`session_key`),
  KEY `idx_ramza_algorithm_seen_user` (`user_id`,`last_seen_at`,`post_id`),
  KEY `idx_ramza_algorithm_seen_cleanup` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_AlgorithmBoosts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int unsigned NOT NULL DEFAULT 0,
  `topic_id` int unsigned NOT NULL DEFAULT 0,
  `boost_type` varchar(24) NOT NULL DEFAULT 'editorial',
  `weight` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `starts_at` int unsigned NOT NULL DEFAULT 0,
  `ends_at` int unsigned NOT NULL DEFAULT 0,
  `active` tinyint unsigned NOT NULL DEFAULT 1,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ramza_algorithm_boost_post` (`post_id`,`active`,`starts_at`,`ends_at`),
  KEY `idx_ramza_algorithm_boost_topic` (`topic_id`,`active`,`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ramza_AlgorithmCronStatus` (
  `job_name` varchar(64) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'idle',
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `processed_count` int unsigned NOT NULL DEFAULT 0,
  `message` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`job_name`),
  KEY `idx_ramza_algorithm_cron_finished` (`finished_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `Ramza_AlgorithmTopics`
  (`parent_id`,`slug`,`name`,`topic_type`,`language`,`status`,`created_at`,`updated_at`)
VALUES
  (0,'technology','Technology','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'artificial-intelligence','Artificial Intelligence','topic','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'electric-vehicles','Electric Vehicles','topic','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'tesla','Tesla','entity','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'elon-musk','Elon Musk','creator','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'spacex','SpaceX','entity','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'entrepreneurship','Entrepreneurship','topic','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'innovation','Innovation','topic','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'automobiles','Automobiles','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'dance','Dance','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'choreography','Choreography','topic','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'music','Music','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'comedy','Comedy','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'memes','Memes','topic','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'stand-up','Stand-up','topic','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'motivation','Motivation','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'attitude','Attitude','topic','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'lifestyle','Lifestyle','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'confidence','Confidence','topic','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'business','Business','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'education','Education','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'sports','Sports','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'travel','Travel','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'fashion','Fashion','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'food','Food','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'gaming','Gaming','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  (0,'news','News','category','und',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`status`=1,`updated_at`=VALUES(`updated_at`);

INSERT INTO `Ramza_AlgorithmTopicAliases` (`topic_id`,`alias`,`normalized_alias`,`language`,`created_at`)
SELECT `id`,'AI','ai','und',UNIX_TIMESTAMP() FROM `Ramza_AlgorithmTopics` WHERE `slug`='artificial-intelligence'
ON DUPLICATE KEY UPDATE `alias`=VALUES(`alias`);
INSERT INTO `Ramza_AlgorithmTopicAliases` (`topic_id`,`alias`,`normalized_alias`,`language`,`created_at`)
SELECT `id`,'EV','ev','und',UNIX_TIMESTAMP() FROM `Ramza_AlgorithmTopics` WHERE `slug`='electric-vehicles'
ON DUPLICATE KEY UPDATE `alias`=VALUES(`alias`);
INSERT INTO `Ramza_AlgorithmTopicAliases` (`topic_id`,`alias`,`normalized_alias`,`language`,`created_at`)
SELECT `id`,'Space X','space x','und',UNIX_TIMESTAMP() FROM `Ramza_AlgorithmTopics` WHERE `slug`='spacex'
ON DUPLICATE KEY UPDATE `alias`=VALUES(`alias`);
INSERT INTO `Ramza_AlgorithmTopicAliases` (`topic_id`,`alias`,`normalized_alias`,`language`,`created_at`)
SELECT `id`,'Tesla Motors','tesla motors','und',UNIX_TIMESTAMP() FROM `Ramza_AlgorithmTopics` WHERE `slug`='tesla'
ON DUPLICATE KEY UPDATE `alias`=VALUES(`alias`);
INSERT INTO `Ramza_AlgorithmTopicAliases` (`topic_id`,`alias`,`normalized_alias`,`language`,`created_at`)
SELECT `id`,'Tesla Car','tesla car','und',UNIX_TIMESTAMP() FROM `Ramza_AlgorithmTopics` WHERE `slug`='tesla'
ON DUPLICATE KEY UPDATE `alias`=VALUES(`alias`);
INSERT INTO `Ramza_AlgorithmTopicAliases` (`topic_id`,`alias`,`normalized_alias`,`language`,`created_at`)
SELECT `id`,'Funny','funny','und',UNIX_TIMESTAMP() FROM `Ramza_AlgorithmTopics` WHERE `slug`='comedy'
ON DUPLICATE KEY UPDATE `alias`=VALUES(`alias`);
INSERT INTO `Ramza_AlgorithmTopicAliases` (`topic_id`,`alias`,`normalized_alias`,`language`,`created_at`)
SELECT `id`,'Dancing','dancing','und',UNIX_TIMESTAMP() FROM `Ramza_AlgorithmTopics` WHERE `slug`='dance'
ON DUPLICATE KEY UPDATE `alias`=VALUES(`alias`);
INSERT INTO `Ramza_AlgorithmTopicAliases` (`topic_id`,`alias`,`normalized_alias`,`language`,`created_at`)
SELECT `id`,'Dance Video','dance video','und',UNIX_TIMESTAMP() FROM `Ramza_AlgorithmTopics` WHERE `slug`='dance'
ON DUPLICATE KEY UPDATE `alias`=VALUES(`alias`);
INSERT INTO `Ramza_AlgorithmTopicAliases` (`topic_id`,`alias`,`normalized_alias`,`language`,`created_at`)
SELECT `id`,'Motivational Attitude','motivational attitude','und',UNIX_TIMESTAMP() FROM `Ramza_AlgorithmTopics` WHERE `slug`='attitude'
ON DUPLICATE KEY UPDATE `alias`=VALUES(`alias`);

INSERT INTO `Ramza_AlgorithmTopicRelations` (`topic_id`,`related_topic_id`,`relation_type`,`weight`,`created_at`)
SELECT a.`id`,b.`id`,'related',0.9000,UNIX_TIMESTAMP()
FROM `Ramza_AlgorithmTopics` a, `Ramza_AlgorithmTopics` b
WHERE a.`slug`='elon-musk' AND b.`slug` IN ('tesla','spacex','technology')
ON DUPLICATE KEY UPDATE `weight`=VALUES(`weight`);
INSERT INTO `Ramza_AlgorithmTopicRelations` (`topic_id`,`related_topic_id`,`relation_type`,`weight`,`created_at`)
SELECT a.`id`,b.`id`,'related',0.8500,UNIX_TIMESTAMP()
FROM `Ramza_AlgorithmTopics` a, `Ramza_AlgorithmTopics` b
WHERE a.`slug`='tesla' AND b.`slug` IN ('electric-vehicles','technology','elon-musk')
ON DUPLICATE KEY UPDATE `weight`=VALUES(`weight`);
INSERT INTO `Ramza_AlgorithmTopicRelations` (`topic_id`,`related_topic_id`,`relation_type`,`weight`,`created_at`)
SELECT a.`id`,b.`id`,'related',0.7200,UNIX_TIMESTAMP()
FROM `Ramza_AlgorithmTopics` a, `Ramza_AlgorithmTopics` b
WHERE a.`slug`='attitude' AND b.`slug`='motivation'
ON DUPLICATE KEY UPDATE `weight`=VALUES(`weight`);

INSERT INTO `Ramza_AlgorithmTopicRelations` (`topic_id`,`related_topic_id`,`relation_type`,`weight`,`created_at`)
SELECT a.`id`,b.`id`,'related',0.7800,UNIX_TIMESTAMP()
FROM `Ramza_AlgorithmTopics` a, `Ramza_AlgorithmTopics` b
WHERE a.`slug`='elon-musk' AND b.`slug` IN ('entrepreneurship','innovation')
ON DUPLICATE KEY UPDATE `weight`=VALUES(`weight`);
INSERT INTO `Ramza_AlgorithmTopicRelations` (`topic_id`,`related_topic_id`,`relation_type`,`weight`,`created_at`)
SELECT a.`id`,b.`id`,'related',0.7500,UNIX_TIMESTAMP()
FROM `Ramza_AlgorithmTopics` a, `Ramza_AlgorithmTopics` b
WHERE a.`slug`='tesla' AND b.`slug`='automobiles'
ON DUPLICATE KEY UPDATE `weight`=VALUES(`weight`);
INSERT INTO `Ramza_AlgorithmTopicRelations` (`topic_id`,`related_topic_id`,`relation_type`,`weight`,`created_at`)
SELECT a.`id`,b.`id`,'related',0.7800,UNIX_TIMESTAMP()
FROM `Ramza_AlgorithmTopics` a, `Ramza_AlgorithmTopics` b
WHERE a.`slug`='dance' AND b.`slug` IN ('music','choreography')
ON DUPLICATE KEY UPDATE `weight`=VALUES(`weight`);
INSERT INTO `Ramza_AlgorithmTopicRelations` (`topic_id`,`related_topic_id`,`relation_type`,`weight`,`created_at`)
SELECT a.`id`,b.`id`,'related',0.8200,UNIX_TIMESTAMP()
FROM `Ramza_AlgorithmTopics` a, `Ramza_AlgorithmTopics` b
WHERE a.`slug`='comedy' AND b.`slug` IN ('memes','stand-up')
ON DUPLICATE KEY UPDATE `weight`=VALUES(`weight`);
INSERT INTO `Ramza_AlgorithmTopicRelations` (`topic_id`,`related_topic_id`,`relation_type`,`weight`,`created_at`)
SELECT a.`id`,b.`id`,'related',0.7800,UNIX_TIMESTAMP()
FROM `Ramza_AlgorithmTopics` a, `Ramza_AlgorithmTopics` b
WHERE a.`slug` IN ('attitude','motivation') AND b.`slug` IN ('attitude','motivation','lifestyle','confidence') AND a.`id`<>b.`id`
ON DUPLICATE KEY UPDATE `weight`=VALUES(`weight`);
INSERT INTO `Ramza_AlgorithmTopicRelations` (`topic_id`,`related_topic_id`,`relation_type`,`weight`,`created_at`)
SELECT a.`id`,b.`id`,'related',0.7000,UNIX_TIMESTAMP()
FROM `Ramza_AlgorithmTopics` a, `Ramza_AlgorithmTopics` b
WHERE a.`slug` IN ('spacex','electric-vehicles','artificial-intelligence') AND b.`slug` IN ('technology','innovation','elon-musk') AND a.`id`<>b.`id`
ON DUPLICATE KEY UPDATE `weight`=VALUES(`weight`);
