-- Enterprise Scorecard: the schema `php scripts/migrate.php` creates.
-- Generated 2026-09-24T09:09:35+00:00 from a database where the migrations had run.
-- FOR REVIEW. Run the migrator on the live server; do not paste this by hand,
-- or the ledger will not know the migrations ran and the next deploy will retry them.

CREATE TABLE `es_instruments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL COMMENT 'e.g. 1.0',
  `name` varchar(191) NOT NULL,
  `subtitle` varchar(191) DEFAULT NULL,
  `scale` varchar(50) NOT NULL DEFAULT '5 levels',
  `scoring_rule` varchar(50) NOT NULL DEFAULT 'mean' COMMENT 'level = band of the overall average; the floor is reported, not enforced',
  `rule_text` varchar(255) DEFAULT NULL COMMENT 'plain-language rule, printed in exports and the codebook',
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instruments_version` (`version`),
  KEY `instruments_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `es_rungs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `instrument_id` int(10) unsigned NOT NULL,
  `value` tinyint(3) unsigned NOT NULL COMMENT '1-5',
  `name` varchar(50) NOT NULL,
  `description` varchar(191) NOT NULL,
  `colour_hex` char(7) NOT NULL,
  `band_min` decimal(4,2) DEFAULT NULL COMMENT 'inclusive; null = open-ended below',
  `band_max` decimal(4,2) DEFAULT NULL COMMENT 'exclusive; null = open-ended above',
  PRIMARY KEY (`id`),
  UNIQUE KEY `rungs_instrument_value` (`instrument_id`,`value`),
  CONSTRAINT `es_rungs_instrument_fk` FOREIGN KEY (`instrument_id`) REFERENCES `es_instruments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `es_dimensions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `instrument_id` int(10) unsigned NOT NULL,
  `position` tinyint(3) unsigned NOT NULL,
  `code` char(3) NOT NULL COMMENT 'PIP GOV EXE ARC CUL WRK',
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `pressing_question` varchar(255) NOT NULL COMMENT 'the question an executive should be able to press on this dimension',
  `summary_line` varchar(255) NOT NULL COMMENT 'one line, shown in the hero list of what is measured',
  `failure_line` varchar(255) NOT NULL COMMENT 'what failing this dimension looks like',
  `guide_what` text DEFAULT NULL,
  `guide_why` text DEFAULT NULL,
  `guide_how` text DEFAULT NULL COMMENT 'contains one outbound link; rendered as trusted instrument HTML, never respondent input',
  `floor_narrative` text DEFAULT NULL COMMENT 'shown when this dimension is the weakest; {floor} is substituted',
  PRIMARY KEY (`id`),
  UNIQUE KEY `dimensions_instrument_code` (`instrument_id`,`code`),
  KEY `dimensions_position` (`instrument_id`,`position`),
  CONSTRAINT `es_dimensions_instrument_fk` FOREIGN KEY (`instrument_id`) REFERENCES `es_instruments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `es_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dimension_id` int(10) unsigned NOT NULL,
  `position` tinyint(3) unsigned NOT NULL COMMENT '1-5 within its dimension',
  `label` varchar(100) NOT NULL COMMENT 'short name, e.g. "Decision rights"',
  `question` text NOT NULL,
  `anchor_low_label` varchar(60) NOT NULL COMMENT 'the word printed under 1, e.g. "Rarely"',
  `anchor_low_text` varchar(255) NOT NULL COMMENT 'what a 1 means on this question',
  `anchor_high_label` varchar(60) NOT NULL COMMENT 'the word printed under 5',
  `anchor_high_text` varchar(255) NOT NULL COMMENT 'what a 5 means on this question',
  PRIMARY KEY (`id`),
  UNIQUE KEY `items_dimension_position` (`dimension_id`,`position`),
  CONSTRAINT `es_items_dimension_fk` FOREIGN KEY (`dimension_id`) REFERENCES `es_dimensions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `es_reference_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `instrument_id` int(10) unsigned NOT NULL,
  `group_key` varchar(40) NOT NULL COMMENT 'sectors | organisation_sizes | roles | tenures',
  `position` smallint(5) unsigned NOT NULL,
  `value` varchar(191) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_options_unique` (`instrument_id`,`group_key`,`value`),
  KEY `reference_options_group` (`instrument_id`,`group_key`,`position`),
  CONSTRAINT `es_reference_options_instrument_fk` FOREIGN KEY (`instrument_id`) REFERENCES `es_instruments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `es_submissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `public_token` char(32) NOT NULL COMMENT 'lets a respondent reopen their own result; no account needed',
  `instrument_id` int(10) unsigned NOT NULL COMMENT 'the version this was scored against, pinned forever',
  `organisation_id` int(10) unsigned DEFAULT NULL COMMENT 'platform-level organisation record',
  `respondent_name` varchar(120) NOT NULL COMMENT 'required; self-asserted and never verified',
  `respondent_email` varchar(254) NOT NULL COMMENT 'required; format-checked only',
  `respondent_phone` varchar(40) NOT NULL COMMENT 'required; 7-15 digits, format-checked only',
  `organisation_name` varchar(191) NOT NULL COMMENT 'as typed; organisations holds the deduplicated row',
  `organisation_size` varchar(50) NOT NULL COMMENT 'required; one of the instrument size bands',
  `role` varchar(100) DEFAULT NULL,
  `tenure` varchar(50) DEFAULT NULL,
  `sector` varchar(100) DEFAULT NULL COMMENT 'as this respondent reported it; the organisation row has its own',
  `assessment_year` smallint(5) unsigned NOT NULL,
  `systems_inventory` smallint(5) unsigned DEFAULT NULL COMMENT 'AI systems on an inventory the respondent has seen; blank means no such list exists',
  `agenda_meetings` smallint(5) unsigned DEFAULT NULL COMMENT 'executive meetings with AI as a DECISION item',
  `education_hours` decimal(5,1) DEFAULT NULL,
  `consent_agreed_at` datetime DEFAULT NULL COMMENT 'when the respondent accepted the terms shown before the questionnaire',
  `consent_terms_url` varchar(255) DEFAULT NULL COMMENT 'the terms they were shown, so a later revision cannot be read back onto an old consent',
  `status` enum('in_progress','complete') NOT NULL DEFAULT 'in_progress',
  `mean_score` decimal(4,2) DEFAULT NULL COMMENT 'the overall average; this is what sets the level',
  `level_value` tinyint(3) unsigned DEFAULT NULL COMMENT 'rung value derived from mean_score',
  `floor_score` decimal(4,2) DEFAULT NULL COMMENT 'the weakest dimension score; reported, not enforced',
  `floor_dimension_id` int(10) unsigned DEFAULT NULL,
  `is_synthetic` tinyint(1) NOT NULL DEFAULT 0,
  `archetype` varchar(40) DEFAULT NULL COMMENT 'synthetic records only',
  `seed_run_id` int(10) unsigned DEFAULT NULL,
  `ip_hash` char(64) DEFAULT NULL COMMENT 'hashed, for rate limiting only',
  `submitted_at` datetime DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL COMMENT 'when the respondent explicitly accepted this scorecard on the results page',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `submissions_public_token` (`public_token`),
  KEY `submissions_instrument_id` (`instrument_id`),
  KEY `submissions_organisation_id` (`organisation_id`),
  KEY `submissions_is_synthetic` (`is_synthetic`),
  KEY `submissions_year` (`assessment_year`),
  KEY `submissions_status` (`status`),
  KEY `submissions_floor_dimension_id` (`floor_dimension_id`),
  KEY `submissions_level_value` (`level_value`),
  KEY `submissions_submitted_at` (`submitted_at`),
  KEY `submissions_email` (`respondent_email`),
  KEY `es_submissions_seed_run_fk` (`seed_run_id`),
  CONSTRAINT `es_submissions_floor_dimension_fk` FOREIGN KEY (`floor_dimension_id`) REFERENCES `es_dimensions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `es_submissions_instrument_fk` FOREIGN KEY (`instrument_id`) REFERENCES `es_instruments` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `es_submissions_organisation_fk` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `es_submissions_seed_run_fk` FOREIGN KEY (`seed_run_id`) REFERENCES `es_seed_runs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6841 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `es_submission_responses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int(10) unsigned NOT NULL,
  `item_id` int(10) unsigned NOT NULL,
  `mark` tinyint(3) unsigned NOT NULL COMMENT '1-5',
  `answered_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `submission_responses_unique` (`submission_id`,`item_id`),
  KEY `submission_responses_item_id` (`item_id`),
  CONSTRAINT `es_submission_responses_item_fk` FOREIGN KEY (`item_id`) REFERENCES `es_items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `es_submission_responses_submission_fk` FOREIGN KEY (`submission_id`) REFERENCES `es_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=205117 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `es_submission_scores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int(10) unsigned NOT NULL,
  `dimension_id` int(10) unsigned NOT NULL,
  `score` decimal(4,2) NOT NULL COMMENT 'unweighted mean of the dimension''s answered items',
  `rung_value` tinyint(3) unsigned NOT NULL,
  `items_answered` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `submission_scores_unique` (`submission_id`,`dimension_id`),
  KEY `submission_scores_dimension_id` (`dimension_id`),
  CONSTRAINT `es_submission_scores_dimension_fk` FOREIGN KEY (`dimension_id`) REFERENCES `es_dimensions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `es_submission_scores_submission_fk` FOREIGN KEY (`submission_id`) REFERENCES `es_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41374 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `es_seed_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `seed` bigint(20) NOT NULL COMMENT 'PRNG seed; the run is reproducible from this plus options',
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'count, years, respondents, spread, archetype mix' CHECK (json_valid(`options`)),
  `records_created` smallint(5) unsigned NOT NULL DEFAULT 0,
  `organisations_created` smallint(5) unsigned NOT NULL DEFAULT 0,
  `instrument_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `seed_runs_created_by` (`created_by`),
  KEY `es_seed_runs_instrument_fk` (`instrument_id`),
  CONSTRAINT `es_seed_runs_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `es_seed_runs_instrument_fk` FOREIGN KEY (`instrument_id`) REFERENCES `es_instruments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=179 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

