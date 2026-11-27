CREATE DATABASE IF NOT EXISTS `DBabsensi`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `DBabsensi`;

CREATE TABLE IF NOT EXISTS `roles` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(32) NOT NULL,
  `label` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB;

INSERT INTO `roles` (`name`, `label`) VALUES
  ('admin', 'Administrator'),
  ('tendik', 'Tendik'),
  ('pendidik', 'Pendidik'),
  ('principal', 'Kepala Sekolah')
ON DUPLICATE KEY UPDATE `label`=VALUES(`label`);

CREATE TABLE IF NOT EXISTS `employees` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nip` VARCHAR(32) NOT NULL,
  `full_name` VARCHAR(160) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(32) DEFAULT NULL,
  `position` VARCHAR(120) NOT NULL,
  `employment_type` ENUM('PNS','PPPK','Honorer','Lainnya') NOT NULL DEFAULT 'PNS',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employees_nip` (`nip`),
  UNIQUE KEY `uq_employees_email` (`email`),
  KEY `idx_employees_active_name` (`is_active`, `full_name`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` BIGINT UNSIGNED DEFAULT NULL,
  `role_id` TINYINT UNSIGNED NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_employee` (`employee_id`),
  KEY `idx_users_role_active` (`role_id`, `is_active`),
  CONSTRAINT `fk_users_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB;

UPDATE `users` u
JOIN `roles` old_role ON old_role.`id`=u.`role_id`
JOIN `roles` new_role ON new_role.`name`=CASE old_role.`name`
  WHEN 'operator' THEN 'tendik'
  WHEN 'asn' THEN 'pendidik'
  ELSE old_role.`name`
END
SET u.`role_id`=new_role.`id`
WHERE old_role.`name` IN ('operator','asn');

DELETE FROM `roles` WHERE `name` IN ('operator','asn');

CREATE TABLE IF NOT EXISTS `permissions` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `group_name` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_name` (`name`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` TINYINT UNSIGNED NOT NULL,
  `permission_id` SMALLINT UNSIGNED NOT NULL,
  `is_allowed` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`role_id`, `permission_id`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO `permissions` (`name`, `label`, `group_name`) VALUES
  ('dashboard.view', 'Melihat dashboard', 'Dashboard'),
  ('attendance.self', 'Absensi dan riwayat sendiri', 'Absensi'),
  ('attendance.view_all', 'Melihat absensi seluruh pegawai', 'Absensi'),
  ('attendance.manual', 'Mencatat/mengoreksi absensi manual', 'Absensi'),
  ('location.manage', 'Mengatur lokasi sekolah dan geofence', 'Lokasi sekolah'),
  ('reports.view', 'Melihat dan mengunduh laporan', 'Laporan'),
  ('employees.manage', 'Mengelola data pegawai', 'Data pegawai'),
  ('calendar.view', 'Melihat kalender pendidikan', 'Kalender pendidikan'),
  ('calendar.manage', 'Mengelola kalender pendidikan', 'Kalender pendidikan'),
  ('users.manage', 'Mengelola akun pengguna', 'Manajemen akses'),
  ('roles.manage', 'Melihat dan mengelola role', 'Manajemen akses'),
  ('access.manage', 'Mengatur izin akses per role', 'Manajemen akses')
ON DUPLICATE KEY UPDATE `label`=VALUES(`label`), `group_name`=VALUES(`group_name`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `is_allowed`)
SELECT r.`id`, p.`id`,
  CASE
    WHEN r.`name`='admin' THEN 1
    WHEN r.`name`='tendik' AND p.`name` IN ('dashboard.view','attendance.self','reports.view','calendar.view') THEN 1
    WHEN r.`name` IN ('pendidik','principal') AND p.`name` IN ('dashboard.view','attendance.self','calendar.view') THEN 1
    ELSE 0
  END
FROM `roles` r
CROSS JOIN `permissions` p;

CREATE TABLE IF NOT EXISTS `education_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(180) NOT NULL,
  `event_type` ENUM('Hari Efektif','Libur','Kegiatan Sekolah','Rapat','Lainnya') NOT NULL DEFAULT 'Kegiatan Sekolah',
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_education_dates` (`start_date`, `end_date`),
  CONSTRAINT `fk_education_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `attendance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` BIGINT UNSIGNED NOT NULL,
  `attendance_date` DATE NOT NULL,
  `check_in_at` DATETIME DEFAULT NULL,
  `check_out_at` DATETIME DEFAULT NULL,
  `method` ENUM('face','manual') NOT NULL DEFAULT 'face',
  `status` ENUM('hadir','izin','sakit','dinas_luar','alpa') NOT NULL DEFAULT 'hadir',
  `note` VARCHAR(500) DEFAULT NULL,
  `recorded_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_employee_date` (`employee_id`, `attendance_date`),
  KEY `idx_attendance_date_status` (`attendance_date`, `status`),
  CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_attendance_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `school_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `geofence_radius_m` SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  `work_start_time` TIME NOT NULL DEFAULT '07:30:00',
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Makassar',
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_school_settings_singleton` CHECK (`id`=1),
  CONSTRAINT `chk_school_settings_radius` CHECK (`geofence_radius_m` BETWEEN 10 AND 5000),
  CONSTRAINT `fk_school_settings_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO `school_settings` (`id`, `geofence_radius_m`) VALUES (1, 50);

CREATE TABLE IF NOT EXISTS `attendance_location_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attendance_id` BIGINT UNSIGNED NOT NULL,
  `event_type` ENUM('check_in','check_out') NOT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `accuracy_m` DECIMAL(10,2) NOT NULL,
  `distance_m` DECIMAL(10,2) NOT NULL,
  `recorded_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_location_event` (`attendance_id`, `event_type`),
  CONSTRAINT `fk_attendance_location_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `face_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` BIGINT UNSIGNED NOT NULL,
  `ciphertext` MEDIUMBLOB NOT NULL,
  `nonce` VARBINARY(12) NOT NULL,
  `consented_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_face_template_employee` (`employee_id`),
  CONSTRAINT `fk_face_template_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `entity` VARCHAR(80) NOT NULL,
  `entity_id` VARCHAR(80) DEFAULT NULL,
  `details` JSON DEFAULT NULL,
  `ip_address` VARBINARY(16) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;
