CREATE TABLE IF NOT EXISTS `#__kx_packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(190) NOT NULL DEFAULT '',
  `description` TEXT NULL,
  `price_month` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `price_year` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `max_buildings` INT NOT NULL DEFAULT 0,
  `max_units` INT NOT NULL DEFAULT 0,
  `max_users` INT NOT NULL DEFAULT 1,
  `trial_days` INT NOT NULL DEFAULT 0,
  `features` TEXT NULL,
  `is_default` TINYINT NOT NULL DEFAULT 0,
  `highlight` TINYINT NOT NULL DEFAULT 0,
  `published` TINYINT NOT NULL DEFAULT 1,
  `ordering` INT NOT NULL DEFAULT 0,
  `created` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(190) NOT NULL DEFAULT '',
  `afm` VARCHAR(20) NOT NULL DEFAULT '',
  `doy` VARCHAR(100) NOT NULL DEFAULT '',
  `address` VARCHAR(255) NOT NULL DEFAULT '',
  `phone` VARCHAR(60) NOT NULL DEFAULT '',
  `email` VARCHAR(190) NOT NULL DEFAULT '',
  `website` VARCHAR(190) NOT NULL DEFAULT '',
  `logo` VARCHAR(255) NOT NULL DEFAULT '',
  `package_id` INT NOT NULL DEFAULT 0,
  `billing_cycle` VARCHAR(10) NOT NULL DEFAULT 'month',
  `status` VARCHAR(20) NOT NULL DEFAULT 'trial',
  `trial_ends` DATE NULL,
  `paid_until` DATE NULL,
  `settings` TEXT NULL,
  `notes` TEXT NULL,
  `created` DATETIME NULL,
  `modified` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_account_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'staff',
  `created` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user` (`user_id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_billing` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `package_id` INT NOT NULL DEFAULT 0,
  `kind` VARCHAR(20) NOT NULL DEFAULT 'payment',
  `cycle` VARCHAR(10) NOT NULL DEFAULT 'month',
  `period_from` DATE NULL,
  `period_to` DATE NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 24,
  `method` VARCHAR(40) NOT NULL DEFAULT '',
  `reference` VARCHAR(190) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'paid',
  `notes` TEXT NULL,
  `created` DATETIME NULL,
  `created_by` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_buildings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `title` VARCHAR(190) NOT NULL DEFAULT '',
  `street` VARCHAR(190) NOT NULL DEFAULT '',
  `street_no` VARCHAR(20) NOT NULL DEFAULT '',
  `area` VARCHAR(120) NOT NULL DEFAULT '',
  `city` VARCHAR(120) NOT NULL DEFAULT '',
  `postcode` VARCHAR(10) NOT NULL DEFAULT '',
  `floors` VARCHAR(20) NOT NULL DEFAULT '',
  `afm` VARCHAR(20) NOT NULL DEFAULT '',
  `iban` VARCHAR(40) NOT NULL DEFAULT '',
  `bank` VARCHAR(120) NOT NULL DEFAULT '',
  `beneficiary` VARCHAR(190) NOT NULL DEFAULT '',
  `manager_name` VARCHAR(190) NOT NULL DEFAULT '',
  `manager_phone` VARCHAR(60) NOT NULL DEFAULT '',
  `manager_mobile` VARCHAR(60) NOT NULL DEFAULT '',
  `manager_email` VARCHAR(190) NOT NULL DEFAULT '',
  `manager_address` VARCHAR(255) NOT NULL DEFAULT '',
  `manager_hours` VARCHAR(190) NOT NULL DEFAULT '',
  `rep_name` VARCHAR(190) NOT NULL DEFAULT '',
  `rep_phone` VARCHAR(60) NOT NULL DEFAULT '',
  `heating_type` VARCHAR(20) NOT NULL DEFAULT 'none',
  `heating_method` VARCHAR(20) NOT NULL DEFAULT 'mills',
  `heating_fixed_pct` DECIMAL(5,2) NOT NULL DEFAULT 30,
  `fuel_unit` VARCHAR(10) NOT NULL DEFAULT 'lt',
  `due_days` INT NOT NULL DEFAULT 10,
  `notes` TEXT NULL,
  `archived` TINYINT NOT NULL DEFAULT 0,
  `created` DATETIME NULL,
  `modified` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `building_id` INT NOT NULL,
  `code` VARCHAR(30) NOT NULL DEFAULT '',
  `title` VARCHAR(100) NOT NULL DEFAULT '',
  `kind` VARCHAR(20) NOT NULL DEFAULT 'custom',
  `ordering` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_building` (`building_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `building_id` INT NOT NULL,
  `code` VARCHAR(30) NOT NULL DEFAULT '',
  `floor` VARCHAR(20) NOT NULL DEFAULT '',
  `kind` VARCHAR(30) NOT NULL DEFAULT 'Διαμέρισμα',
  `area` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `owner_name` VARCHAR(190) NOT NULL DEFAULT '',
  `owner_phone` VARCHAR(60) NOT NULL DEFAULT '',
  `owner_email` VARCHAR(190) NOT NULL DEFAULT '',
  `owner_afm` VARCHAR(20) NOT NULL DEFAULT '',
  `tenant_name` VARCHAR(190) NOT NULL DEFAULT '',
  `tenant_phone` VARCHAR(60) NOT NULL DEFAULT '',
  `tenant_email` VARCHAR(190) NOT NULL DEFAULT '',
  `payer` VARCHAR(10) NOT NULL DEFAULT 'tenant',
  `heat_coef` DECIMAL(8,4) NOT NULL DEFAULT 1,
  `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `ordering` INT NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) NOT NULL DEFAULT '',
  `created` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_building` (`building_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_unit_mills` (
  `unit_id` INT NOT NULL,
  `key_id` INT NOT NULL,
  `mills` DECIMAL(10,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (`unit_id`, `key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_periods` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `building_id` INT NOT NULL,
  `year` SMALLINT NOT NULL,
  `month` TINYINT NOT NULL,
  `title` VARCHAR(100) NOT NULL DEFAULT '',
  `issue_date` DATE NULL,
  `due_date` DATE NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'draft',
  `notes` TEXT NULL,
  `heating` TEXT NULL,
  `snapshot` MEDIUMTEXT NULL,
  `issued_at` DATETIME NULL,
  `created` DATETIME NULL,
  `modified` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_building` (`building_id`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_expenses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `category` VARCHAR(20) NOT NULL DEFAULT 'general',
  `method` VARCHAR(20) NOT NULL DEFAULT 'key',
  `key_id` INT NOT NULL DEFAULT 0,
  `payer` VARCHAR(10) NOT NULL DEFAULT 'tenant',
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `unit_ids` TEXT NULL,
  `custom` TEXT NULL,
  `supplier` VARCHAR(190) NOT NULL DEFAULT '',
  `doc_no` VARCHAR(60) NOT NULL DEFAULT '',
  `doc_date` DATE NULL,
  `recurring` TINYINT NOT NULL DEFAULT 0,
  `ordering` INT NOT NULL DEFAULT 0,
  `created` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_period` (`period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_readings` (
  `period_id` INT NOT NULL,
  `unit_id` INT NOT NULL,
  `prev_value` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `curr_value` DECIMAL(12,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (`period_id`, `unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_charges` (
  `period_id` INT NOT NULL,
  `unit_id` INT NOT NULL,
  `tenant` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `owner` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `previous` DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`period_id`, `unit_id`),
  KEY `idx_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `building_id` INT NOT NULL,
  `unit_id` INT NOT NULL,
  `pay_date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `method` VARCHAR(20) NOT NULL DEFAULT 'cash',
  `receipt_no` INT NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) NOT NULL DEFAULT '',
  `created` DATETIME NULL,
  `created_by` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL DEFAULT 0,
  `title` VARCHAR(190) NOT NULL DEFAULT '',
  `type` VARCHAR(10) NOT NULL DEFAULT 'sheet',
  `layout` MEDIUMTEXT NULL,
  `background` VARCHAR(255) NOT NULL DEFAULT '',
  `is_default` TINYINT NOT NULL DEFAULT 0,
  `created` DATETIME NULL,
  `modified` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__kx_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL DEFAULT 0,
  `user_id` INT NOT NULL DEFAULT 0,
  `action` VARCHAR(60) NOT NULL DEFAULT '',
  `message` VARCHAR(500) NOT NULL DEFAULT '',
  `created` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
