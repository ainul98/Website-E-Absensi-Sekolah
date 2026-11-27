-- Migrasi 003: zona waktu sekolah yang disimpan di database (disinkronkan ke PHP dan MySQL).
-- Jalankan SEKALI pada database yang sudah ada. Instalasi baru memuatnya dari DBabsensi.sql.
USE `DBabsensi`;
ALTER TABLE `school_settings`
  ADD COLUMN `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Makassar' AFTER `work_start_time`;
