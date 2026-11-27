# E-Absensi SLB

Aplikasi absensi ASN sekolah luar biasa dengan PHP native, CSS/JavaScript, dan MySQL 8. Tampilan mengambil palet eggshell/taupe dan komposisi tipografi dari file desain yang diberikan.

## Kebutuhan

- PHP 8.1+ dengan ekstensi `pdo_mysql`, `mbstring`, dan `openssl`
- MySQL 8+
- Apache 2.4 dengan `.htaccess` diizinkan (misalnya XAMPP)
- Browser modern; izin kamera/lokasi dan HTTPS diperlukan di luar `localhost`
- Koneksi internet untuk memuat pustaka/model `@vladmandic/face-api` dari jsDelivr saat halaman absensi dibuka

## Instalasi lokal (XAMPP)

1. Salin folder proyek ke `C:\xampp\htdocs\Absen`.
2. Jalankan Apache dan MySQL dari XAMPP.
3. Buka phpMyAdmin, lalu impor `DBabsensi.sql`. Skrip membuat database `DBabsensi`; sebelum menerapkan ulang pada instalasi berisi data, buat backup. Tabel bukti lokasi dan pengaturan geofence ditambahkan tanpa menghapus riwayat absensi.
4. Salin `config.example.php` menjadi `config.php`, lalu sesuaikan koneksi MySQL.
5. Buat kunci biometrik acak 32 byte dan simpan sebagai base64 pada `BIOMETRIC_KEY` di environment PHP. Contoh PowerShell untuk membuat nilai kunci:

   ```powershell
   $bytes = New-Object byte[] 32
   $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
   $rng.GetBytes($bytes)
   $rng.Dispose()
   [Convert]::ToBase64String($bytes)
   ```

   Tempel hasilnya ke konfigurasi lokal `config.php`, misalnya pada `app.biometric_key`. Simpan kunci secara terpisah dari backup database. Kehilangan atau penggantian kunci membuat template biometrik yang lama tidak dapat dibuka; hapus/cabut template dan daftarkan ulang setelah rotasi.
6. Buat akun administrator melalui terminal pada folder aplikasi:

   ```powershell
   C:\xampp\php\php.exe scripts\create_admin.php
   ```

   Skrip meminta nama, email, dan kata sandi. Kata sandi minimal 12 karakter; tidak ada kredensial bawaan.
7. Buka `http://localhost/Absen/` dan masuk dengan akun administrator. Atur titik pusat sekolah dan radius di menu **Lokasi sekolah** (radius awal 50 meter), lalu buat data pegawai dan akun yang tertaut melalui Manajemen Pengguna. Akun login dibuat oleh administrator; akun Tendik, Pendidik, dan Kepala Sekolah wajib ditautkan ke pegawai aktif. Pegawai menggunakan email dan kata sandi awal yang ditetapkan admin.

## Fitur

- Autentikasi berbasis sesi, password hashing, pengubahan kata sandi, perlindungan CSRF, dan kontrol akses berbasis role-permission yang tersimpan di database.
- CRUD profil pegawai dan akun pengguna; penonaktifan akun mempertahankan relasi/riwayat.
- Kalender pendidikan dan CRUD agenda.
- Absensi masuk/pulang menggunakan pencocokan template wajah serta geofence GPS sekolah; override manual hanya oleh Admin dengan alasan wajib dan audit log.
- Dashboard ringkasan, filter laporan, dan ekspor CSV.
- Antarmuka responsif dengan desain minimalis hangat.

## Peran

| Peran | Hak utama |
|---|---|
| Admin | Dashboard manajemen pengguna/akses/role, data pegawai, absensi, kalender, dan laporan |
| Tendik | Absensi pribadi masuk/pulang dan riwayat; kalender dan laporan sekolah |
| Pendidik | Absensi pribadi masuk/pulang, riwayat, dan kalender pendidikan |
| Kepala Sekolah | Absensi pribadi masuk/pulang, riwayat, dan kalender pendidikan |

## Endpoint API

Semua endpoint memakai session login yang sama dengan halaman web, mengembalikan JSON, dan memeriksa izin role dari tabel `role_permissions`. Endpoint tulis (`POST`, `PUT`, `PATCH`, `DELETE`) memerlukan header `X-CSRF-Token` dari meta tag halaman yang sedang login.

| Resource | Endpoint | Akses |
|---|---|---|
| Pengguna | `index.php?page=api&resource=users[&id=ID]` | Admin: `GET`, `POST`, `PUT`, `PATCH`, `DELETE` |
| Role | `index.php?page=api&resource=roles` | Admin: `GET`; role inti ditetapkan pada akun |
| Izin role | `index.php?page=api&resource=access&role_id=ID` | Admin: `GET`; `PUT` untuk mengirim `{ "role_id": ID, "permissions": ["attendance.self"] }` |
| Geofence sekolah | `index.php?page=api&resource=geofence` | Admin: `GET`, `PUT` dengan `{ "latitude": -6.2, "longitude": 106.8, "geofence_radius_m": 50 }` |
| Data pegawai | `index.php?page=api&resource=employees[&id=ID]` | Admin: `GET`, `POST`, `PUT`, `PATCH`, `DELETE` |
| Kalender pendidikan | `index.php?page=api&resource=calendar[&id=ID]` | Role yang diizinkan: `GET`, admin: kelola data |
| Absensi | `index.php?page=api&resource=attendance[&id=ID]` | Pegawai: riwayat pribadi; admin: kelola semua catatan. Entri manual memerlukan alasan dan hanya Admin |
| Laporan | `index.php?page=api&resource=reports&from=YYYY-MM-DD&to=YYYY-MM-DD` | Admin dan Tendik: `GET`; bukti lokasi hanya disertakan untuk Admin |

Role inti: `admin`, `tendik`, `pendidik`, dan `principal` (label antarmuka: Kepala Sekolah). Saat skema diterapkan ulang ke database versi lama, role `operator` dipetakan ke Tendik dan `asn` dipetakan ke Pendidik tanpa menghapus akun atau riwayat. Endpoint role tidak menghapus role inti agar relasi akun tetap konsisten; kewenangan tiap role non-admin dapat diatur di Manajemen Akses.

## Catatan privasi dan keamanan

- Sistem menyimpan template numerik wajah dengan enkripsi terautentikasi AES-256-GCM; foto mentah tidak disimpan. Pendaftaran memerlukan persetujuan; mencabut persetujuan menghapus ciphertext template.
- Koordinat sekolah/radius geofence dikelola Admin. GPS diminta hanya saat scan dimulai, harus berakurasi maksimal 50 meter dan tidak melebihi radius, dan check-in/check-out mandiri ditolak jika GPS tidak tersedia atau titik berada di luar radius. Tidak ada pelacakan latar belakang.
- Koordinat dan akurasi disimpan hanya untuk scan absensi yang berhasil, terpisah untuk masuk dan pulang; bukti lokasi hanya tersedia bagi Admin pada halaman absensi/laporan dan ekspor. Percobaan yang ditolak tidak menyimpan koordinat mentah, tetapi alasan penolakan, jarak, dan akurasi dapat dicatat pada audit log.
- Kamera diproses di browser. Descriptor dikirim ke server aplikasi untuk pencocokan terhadap template milik akun yang sedang masuk; library/model pengenalan dimuat dari jsDelivr dan gambar kamera tidak dikirim ke CDN.
- Pendaftaran dan scan wajah memakai challenge-response aktif: pengguna diminta berkedip dan memutar kepala dalam urutan acak. Pemeriksaan landmark berjalan di browser dan server memvalidasi challenge sekali pakai, tetapi ini hanya menambah hambatan terhadap foto atau video statis; JavaScript/perangkat/kamera yang dimodifikasi masih dapat memalsukan hasil. Ini bukan PAD tersertifikasi dan belum diuji terhadap standar presentasi serangan. Untuk keputusan absensi yang berisiko, sediakan verifikasi manual, audit anomali, serta evaluasi vendor atau perangkat PAD khusus. GPS browser juga dapat dipalsukan; gunakan HTTPS dan terapkan masa retensi lokasi sesuai kebijakan sekolah.
- Kunci biometrik wajib dirahasiakan dan dicadangkan terpisah. Jangan simpan kunci atau `config.php` di repositori. Enkripsi template tidak menggantikan kontrol akses, retensi data, persetujuan, atau penilaian hukum sekolah.
- Akun yang dinonaktifkan tidak dapat masuk. Menonaktifkan akun terkait atau profil ASN menghapus ciphertext template wajah; riwayat persetujuan/audit tetap tersedia sesuai prosedur retensi.
- Kamera dan GPS memerlukan secure context (`localhost` atau HTTPS); jangan gunakan HTTP pada jaringan publik.
- Batasi akses direktori proyek dan backup database. Pastikan Apache menolak akses langsung ke berkas konfigurasi dan SQL.

## Catatan implementasi

Model AI dilayani oleh CDN dengan versi pustaka tetap. Penggunaan lokal tanpa internet memerlukan penyimpanan pustaka dan model yang berlisensi di `public/assets/models/` serta penyesuaian CSP dan URL pada `public/assets/app.js`.

## Catatan revisi

- **Kunci biometrik:** `config.php` tidak lagi memuat kunci. Isi `BIOMETRIC_KEY` melalui environment atau `app.biometric_key`. Kunci lama yang sebelumnya tertanam di ZIP dianggap terbuka: buat kunci baru. Jika ingin mempertahankan template wajah yang sudah terdaftar, gunakan kembali kunci lama; jika tidak, daftarkan ulang wajah setelah mengganti kunci.
- **Zona waktu:** default berubah menjadi `Asia/Makassar`. Ubah `APP_TIMEZONE` bila sekolah berada di zona lain.
- **Jam masuk kerja:** diatur admin di menu Lokasi sekolah (default 07:30). Absensi setelah jam ini ditandai *Terlambat* di tabel, laporan, dan CSV.
- **Migrasi database lama:** jalankan `migrations/002_jam_masuk.sql` sekali pada database yang sudah ada. Instalasi baru cukup mengimpor `DBabsensi.sql`.
- **Pembatasan login:** lima kegagalan untuk satu email atau 20 kegagalan dari satu IP dalam 15 menit akan memblokir percobaan masuk sementara.
- **Absensi pulang via wajah:** hanya diterima minimal 5 menit setelah jam masuk.
- **Filter tanggal:** halaman Absensi untuk admin dapat menampilkan catatan tanggal tertentu.

## Catatan revisi 3

- **Absen masuk dan pulang:** tombol terpisah. Pulang hanya tersedia setelah masuk, dan minimal 5 menit setelahnya. Status hari ini ditampilkan di panel.
- **Migrasi 003 (database lama):** jalankan `migrations/003_zona_waktu.sql` sekali. Instalasi baru memuatnya dari `DBabsensi.sql`.
- **Zona waktu:** admin mengatur zona di menu Lokasi sekolah. Zona yang tersimpan di database dibaca saat aplikasi dimuat, lalu disinkronkan ke PHP dan MySQL. Halaman tersebut menampilkan status sinkron.
- **Deteksi wajah:** panduan posisi dan cahaya real-time, pemeriksaan kedip dan tolehan dengan indikator tahap, dan verifikasi memakai tiga bingkai yang dirata-rata.
