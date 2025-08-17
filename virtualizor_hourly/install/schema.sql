-- Virtualizor Hourly - initial schema
CREATE TABLE IF NOT EXISTS `mod_vz_hourly_state` (
  `service_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `last_bw_used_gb` DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
  `last_check_ts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_status` ENUM('active','suspended','unknown') NOT NULL DEFAULT 'unknown'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mod_vz_hourly_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `vpsid` INT UNSIGNED NULL,
  `delta_gb` DECIMAL(12,6) NOT NULL,
  `price_per_gb` DECIMAL(12,6) NOT NULL,
  `debit_amount` DECIMAL(12,6) NOT NULL,
  `client_credit_after` DECIMAL(12,6) NULL,
  `checked_at` DATETIME NOT NULL,
  `note` VARCHAR(255) NULL,
  KEY `sid_time` (`service_id`,`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mod_vz_hourly_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `vpsid` INT UNSIGNED NOT NULL,
  `snap_ts` INT UNSIGNED NOT NULL,
  `used_bandwidth_gb` DECIMAL(12,6) NOT NULL,
  KEY `sid_time` (`service_id`,`snap_ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
