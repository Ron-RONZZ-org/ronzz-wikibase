-- AUTO-GENERATED — do not edit by hand.
-- Source: extensions/EmbeddableContent/includes/Spec/DatasetSchema.php
-- Regenerate: php extensions/EmbeddableContent/maintenance/emitDatasetContract.php

CREATE TABLE IF NOT EXISTS `wb_dataset_datasets` (
  `dataset_id` VARCHAR(191) NOT NULL,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT NULL,
  `source_page` VARCHAR(255) NULL,
  `source_item` VARCHAR(32) NULL,
  `doi` VARCHAR(255) NULL,
  `year` SMALLINT NULL,
  `licence` VARCHAR(32) NULL,
  `licence_label` VARCHAR(255) NULL,
  `rights_basis` VARCHAR(32) NULL,
  `rights_basis_label` VARCHAR(255) NULL,
  `access_tier` VARCHAR(20) NOT NULL DEFAULT 'public',
  `curator` VARCHAR(255) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `manifest_version` VARCHAR(20) NOT NULL,
  `manifest_hash` CHAR(64) NULL,
  `manifest_revision` INT UNSIGNED NULL,
  `search_text` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`dataset_id`),
  KEY `i_access_tier` (`access_tier`),
  KEY `i_status` (`status`),
  KEY `i_year` (`year`),
  FULLTEXT KEY `ft_search_text` (`search_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wb_dataset_data_tables` (
  `table_id` VARCHAR(191) NOT NULL,
  `dataset_id` VARCHAR(191) NOT NULL,
  `table_number` VARCHAR(32) NOT NULL,
  `caption` TEXT NOT NULL,
  `page` VARCHAR(64) NOT NULL,
  `page_start` INT NULL,
  `page_end` INT NULL,
  `table_bbox` VARCHAR(100) NULL,
  `caption_bbox` VARCHAR(100) NULL,
  `source_index` INT NULL,
  `pdf_file` VARCHAR(255) NULL,
  `pdf_sha1` CHAR(40) NULL,
  `pdf_revision` INT UNSIGNED NULL,
  `source_file_access` VARCHAR(20) NULL,
  `extractor` VARCHAR(100) NULL,
  `extractor_version` VARCHAR(50) NULL,
  `method` VARCHAR(20) NOT NULL DEFAULT 'manual',
  `confidence` DOUBLE NULL,
  `review_status` VARCHAR(20) NOT NULL DEFAULT 'unreviewed',
  `reviewer` VARCHAR(255) NULL,
  `review_date` DATE NULL,
  `notes` TEXT NULL,
  `search_text` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`table_id`),
  KEY `i_dataset_id` (`dataset_id`),
  KEY `i_review_status` (`review_status`),
  FULLTEXT KEY `ft_search_text` (`search_text`),
  CONSTRAINT `fk_data_tables_dataset_id` FOREIGN KEY (`dataset_id`) REFERENCES `wb_dataset_datasets` (`dataset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wb_dataset_panels` (
  `panel_id` VARCHAR(191) NOT NULL,
  `table_id` VARCHAR(191) NOT NULL,
  `panel_key` VARCHAR(64) NOT NULL,
  `panel_label` VARCHAR(255) NULL,
  `panel_order` INT NOT NULL DEFAULT 0,
  `description` TEXT NULL,
  PRIMARY KEY (`panel_id`),
  UNIQUE KEY `u_table_id_panel_key` (`table_id`, `panel_key`),
  CONSTRAINT `fk_panels_table_id` FOREIGN KEY (`table_id`) REFERENCES `wb_dataset_data_tables` (`table_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wb_dataset_data_columns` (
  `column_id` VARCHAR(191) NOT NULL,
  `table_id` VARCHAR(191) NOT NULL,
  `panel_key` VARCHAR(64) NOT NULL DEFAULT '',
  `column_key` VARCHAR(64) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `unit` VARCHAR(64) NULL,
  `datatype` VARCHAR(20) NOT NULL,
  `role` VARCHAR(20) NOT NULL,
  `missing_token` VARCHAR(32) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `column_parent_key` VARCHAR(64) NULL,
  `column_level` INT NOT NULL DEFAULT 0,
  `search_text` TEXT NULL,
  PRIMARY KEY (`column_id`),
  UNIQUE KEY `u_table_id_panel_key_column_key` (`table_id`, `panel_key`, `column_key`),
  KEY `i_table_id_panel_key` (`table_id`, `panel_key`),
  FULLTEXT KEY `ft_search_text` (`search_text`),
  CONSTRAINT `fk_data_columns_table_id` FOREIGN KEY (`table_id`) REFERENCES `wb_dataset_data_tables` (`table_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wb_dataset_observations` (
  `obs_id` VARCHAR(191) NOT NULL,
  `table_id` VARCHAR(191) NOT NULL,
  `panel_key` VARCHAR(64) NOT NULL DEFAULT '',
  `row_index` INT NOT NULL DEFAULT 0,
  `row_key` VARCHAR(191) NOT NULL,
  `row_label` VARCHAR(500) NOT NULL,
  `row_path` VARCHAR(1000) NULL,
  `row_parent_key` VARCHAR(191) NULL,
  `row_depth` INT NOT NULL DEFAULT 0,
  `column_key` VARCHAR(64) NOT NULL,
  `value_float` DOUBLE NULL,
  `value_int` BIGINT NULL,
  `value_text` TEXT NULL,
  `value_date` DATE NULL,
  `flag_significant` TINYINT(1) NOT NULL DEFAULT 0,
  `flag_note` VARCHAR(255) NULL,
  `footnote_keys` VARCHAR(500) NULL,
  `source_page` VARCHAR(64) NULL,
  `cell_bbox` VARCHAR(100) NULL,
  `search_text` TEXT NULL,
  PRIMARY KEY (`obs_id`),
  KEY `i_table_id_panel_key_row_index_column_key` (`table_id`, `panel_key`, `row_index`, `column_key`),
  KEY `i_table_id_column_key` (`table_id`, `column_key`),
  KEY `i_table_id_row_key` (`table_id`, `row_key`),
  FULLTEXT KEY `ft_search_text` (`search_text`),
  CONSTRAINT `fk_observations_table_id` FOREIGN KEY (`table_id`) REFERENCES `wb_dataset_data_tables` (`table_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wb_dataset_footnotes` (
  `footnote_id` VARCHAR(191) NOT NULL,
  `table_id` VARCHAR(191) NOT NULL,
  `footnote_key` VARCHAR(64) NOT NULL,
  `marker_raw` VARCHAR(32) NULL,
  `text` TEXT NOT NULL,
  `scope` VARCHAR(20) NOT NULL,
  `language` VARCHAR(20) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NULL,
  `search_text` TEXT NULL,
  PRIMARY KEY (`footnote_id`),
  UNIQUE KEY `u_table_id_footnote_key` (`table_id`, `footnote_key`),
  FULLTEXT KEY `ft_search_text` (`search_text`),
  CONSTRAINT `fk_footnotes_table_id` FOREIGN KEY (`table_id`) REFERENCES `wb_dataset_data_tables` (`table_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wb_dataset_extraction_runs` (
  `run_id` VARCHAR(191) NOT NULL,
  `table_id` VARCHAR(191) NOT NULL,
  `tool` VARCHAR(100) NULL,
  `tool_version` VARCHAR(50) NULL,
  `model` VARCHAR(100) NULL,
  `prompt_version` VARCHAR(50) NULL,
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `status` VARCHAR(20) NULL,
  `confidence` DOUBLE NULL,
  `report_json` LONGTEXT NULL,
  `diff_json` LONGTEXT NULL,
  PRIMARY KEY (`run_id`),
  KEY `i_table_id` (`table_id`),
  CONSTRAINT `fk_extraction_runs_table_id` FOREIGN KEY (`table_id`) REFERENCES `wb_dataset_data_tables` (`table_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

