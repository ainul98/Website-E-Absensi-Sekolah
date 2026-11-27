-- Migrasi 002: jam masuk kerja untuk penanda keterlambatan.
-- Jalankan SEKALI pada database yang sudah ada sebelum revisi ini.
-- Instalasi baru tidak perlu menjalankan berkas ini (kolom sudah ada di DBabsensi.sql).
USE `DBabsensi`;
ALTER TABLE `school_settings`
  ADD COLUMN `work_start_time` TIME NOT NULL DEFAULT '07:30:00' AFTER `geofence_radius_m`;
