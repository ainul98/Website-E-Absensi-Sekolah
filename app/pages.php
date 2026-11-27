<?php
declare(strict_types=1);

function stat_card(string $label, string|int $value, string $hint, string $icon): void
{
    ?>
    <article class="stat-card">
        <div class="stat-card__top"><span><?= e($label) ?></span><span class="icon-badge"><?= e($icon) ?></span></div>
        <strong><?= e($value) ?></strong>
        <small><?= e($hint) ?></small>
    </article>
    <?php
}

function page_dashboard(PDO $pdo, array $user): void
{
    require_permission($pdo, 'dashboard.view');
    $today = date('Y-m-d');
    if ($user['role'] !== 'admin') {
        $attendance = null;
        if ($user['employee_id']) {
            $statement = $pdo->prepare(
                'SELECT * FROM attendance WHERE employee_id=:employee_id AND attendance_date=:attendance_date LIMIT 1'
            );
            $statement->execute([
                'employee_id' => $user['employee_id'],
                'attendance_date' => $today,
            ]);
            $attendance = $statement->fetch() ?: null;
            $statement = $pdo->prepare(
                'SELECT a.*, e.full_name, e.position FROM attendance a
                 JOIN employees e ON e.id=a.employee_id WHERE a.employee_id=:id
                 ORDER BY a.attendance_date DESC LIMIT 8'
            );
            $statement->execute(['id' => $user['employee_id']]);
            $latest = $statement->fetchAll();
        } else {
            $latest = [];
        }
        $statusLabel = $attendance
            ? str_replace('_', ' ', ucfirst($attendance['status']))
            : 'Belum tercatat';
        ?>
        <div class="page-heading">
            <div><p class="eyebrow"><?= e(format_date($today, 'l, d F Y')) ?></p><h1>Selamat datang, <?= e($user['name']) ?></h1>
                <p class="muted">Absensi harian Anda, tersimpan aman dan siap dilihat kembali.</p></div>
            <a class="button button--primary" href="index.php?page=attendance">Absensi masuk / pulang <span aria-hidden="true">→</span></a>
        </div>
        <section class="stats-grid stats-grid--compact">
            <?php stat_card('Status hari ini', $statusLabel, $attendance ? 'Kehadiran Anda' : 'Belum ada catatan hari ini', '01') ?>
            <?php stat_card('Jam masuk', $attendance && $attendance['check_in_at'] ? date('H:i', strtotime($attendance['check_in_at'])) : '—', 'Waktu check-in', '02') ?>
            <?php stat_card('Jam pulang', $attendance && $attendance['check_out_at'] ? date('H:i', strtotime($attendance['check_out_at'])) : '—', 'Waktu check-out', '03') ?>
            <?php stat_card('Riwayat', count($latest), 'Catatan absensi terbaru', '04') ?>
        </section>
        <section class="panel">
            <div class="panel-heading"><div><p class="eyebrow">Aktivitas pribadi</p><h2>Riwayat absensi</h2></div>
                <?php if (has_permission($pdo, $user, 'reports.view')): ?><a class="text-link" href="index.php?page=reports">Buka laporan →</a><?php endif; ?>
            </div>
            <?php attendance_table($latest, $user, false, false, school_work_start($pdo)); ?>
        </section>
        <section class="panel panel--accent">
            <p class="eyebrow">Kalender pendidikan</p><h2>Agenda sekolah</h2>
            <p class="muted">Periksa hari efektif, libur, dan agenda kegiatan sekolah.</p>
            <a class="text-link" href="index.php?page=calendar">Lihat kalender pendidikan →</a>
        </section>
        <?php
        return;
    }

    $totalEmployees = (int) $pdo->query('SELECT COUNT(*) FROM employees WHERE is_active=1')->fetchColumn();
    $todayPresent = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE attendance_date=:today AND status="hadir"');
    $todayPresent->execute(['today' => $today]);
    $present = (int) $todayPresent->fetchColumn();
    $lateEvents = $pdo->prepare('SELECT COUNT(*) FROM education_events WHERE start_date<=:event_end AND end_date>=:event_start');
    $lateEvents->execute(['event_start' => $today, 'event_end' => $today]);
    $eventsToday = (int) $lateEvents->fetchColumn();
    $usersCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn();
    $rolesCount = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
    $permissionsCount = (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
    if (has_permission($pdo, $user, 'attendance.view_all')) {
        $latest = $pdo->query(
            'SELECT a.*, e.full_name, e.position FROM attendance a
             JOIN employees e ON e.id=a.employee_id ORDER BY a.attendance_date DESC, a.updated_at DESC LIMIT 6'
        )->fetchAll();
    } elseif ($user['employee_id']) {
        $statement = $pdo->prepare(
            'SELECT a.*, e.full_name, e.position FROM attendance a
             JOIN employees e ON e.id=a.employee_id WHERE a.employee_id=:id
             ORDER BY a.attendance_date DESC, a.updated_at DESC LIMIT 6'
        );
        $statement->execute(['id' => $user['employee_id']]);
        $latest = $statement->fetchAll();
    } else {
        $latest = [];
    }
    $trend = $pdo->query(
        'SELECT attendance_date, COUNT(*) AS total FROM attendance
         WHERE attendance_date>=DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND status="hadir"
         GROUP BY attendance_date ORDER BY attendance_date'
    )->fetchAll();
    $trendByDate = [];
    foreach ($trend as $row) {
        $trendByDate[$row['attendance_date']] = (int) $row['total'];
    }
    ?>
    <div class="page-heading">
        <div><p class="eyebrow"><?= e(format_date($today, 'l, d F Y')) ?></p><h1>Selamat datang, <?= e($user['name']) ?></h1>
            <p class="muted">Kelola pengguna, hak akses, absensi, dan laporan sekolah dalam satu tempat.</p></div>
        <a class="button button--primary" href="index.php?page=users">Tambah pengguna <span aria-hidden="true">↗</span></a>
    </div>
    <section class="stats-grid">
        <?php stat_card('Pengguna', $usersCount, 'Akun aktif', '01') ?>
        <?php stat_card('Akses / izin', $permissionsCount, 'Dikelola per role', '02') ?>
        <?php stat_card('Role', $rolesCount, 'Kelompok pengguna', '03') ?>
        <?php stat_card('Hadir hari ini', $present . ' / ' . $totalEmployees, 'Pegawai aktif: ' . $totalEmployees, '04') ?>
    </section>
    <section class="quick-actions">
        <a class="quick-action" href="index.php?page=users"><span class="icon-badge">01</span><strong>Manajemen pengguna</strong><small>Buat akun dan tetapkan role.</small><b>→</b></a>
        <a class="quick-action" href="index.php?page=access"><span class="icon-badge">02</span><strong>Manajemen akses</strong><small>Atur izin akses tiap role.</small><b>→</b></a>
        <a class="quick-action" href="index.php?page=roles"><span class="icon-badge">03</span><strong>Manajemen role</strong><small>Tendik, pendidik, pimpinan.</small><b>→</b></a>
        <a class="quick-action" href="index.php?page=employees"><span class="icon-badge">04</span><strong>Data pegawai</strong><small>Kelola profil ASN sekolah.</small><b>→</b></a>
        <a class="quick-action" href="index.php?page=attendance"><span class="icon-badge">05</span><strong>Absensi</strong><small>Pantau catatan kehadiran.</small><b>→</b></a>
        <a class="quick-action" href="index.php?page=reports"><span class="icon-badge">06</span><strong>Laporan</strong><small>Filter dan ekspor rekap.</small><b>→</b></a>
        <a class="quick-action" href="index.php?page=location"><span class="icon-badge">07</span><strong>Lokasi sekolah</strong><small>Atur pusat dan radius GPS.</small><b>→</b></a>
    </section>
    <section class="dashboard-grid">
        <article class="panel">
            <div class="panel-heading"><div><p class="eyebrow">Aktivitas</p><h2>Kehadiran 7 hari</h2></div><span class="pill">Harian</span></div>
            <div class="chart-bars" role="img" aria-label="Grafik jumlah kehadiran selama tujuh hari">
                <?php for ($offset = 6; $offset >= 0; $offset--):
                    $date = date('Y-m-d', strtotime("-$offset days"));
                    $count = $trendByDate[$date] ?? 0;
                    $height = $totalEmployees > 0 ? max(5, (int) round($count / $totalEmployees * 100)) : 5;
                    ?>
                    <div class="chart-bar"><span class="chart-bar__value"><?= $count ?></span><div class="chart-bar__track"><i style="height:<?= $height ?>%"></i></div><small><?= e(format_date($date, 'D')) ?></small></div>
                <?php endfor; ?>
            </div>
            <div class="chart-footnote"><span class="legend-dot"></span>ASN hadir tercatat</div>
        </article>
        <article class="panel panel--accent">
            <p class="eyebrow">Kalender pendidikan</p><h2>Rencanakan dengan tenang.</h2>
            <p class="muted">Agenda, hari efektif, dan libur sekolah tersusun rapi untuk seluruh warga sekolah.</p>
            <a class="text-link" href="index.php?page=calendar">Lihat kalender <span aria-hidden="true">→</span></a>
            <div class="calendar-art" aria-hidden="true"><span>SLB</span><strong>Belajar<br>bersama.</strong><i>✳</i></div>
        </article>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow">Catatan terbaru</p><h2>Aktivitas absensi</h2></div><span class="pill"><?= $eventsToday ?> agenda hari ini</span></div>
        <?php attendance_table($latest, $user, false, false, school_work_start($pdo)); ?>
    </section>
    <?php
}

function page_employees(PDO $pdo): void
{
    require_permission($pdo, 'employees.manage');
    $edit = null;
    if (isset($_GET['edit'])) {
        $statement = $pdo->prepare('SELECT * FROM employees WHERE id=:id');
        $statement->execute(['id' => (int) $_GET['edit']]);
        $edit = $statement->fetch() ?: null;
    }
    $employees = $pdo->query('SELECT * FROM employees ORDER BY is_active DESC, full_name')->fetchAll();
    ?>
    <div class="page-heading"><div><p class="eyebrow">Direktori sekolah</p><h1>Data pegawai</h1><p class="muted">Kelola profil, jabatan, dan status kepegawaian.</p></div></div>
    <section class="content-grid content-grid--form">
        <article class="panel">
            <div class="panel-heading"><div><p class="eyebrow"><?= $edit ? 'Perbarui profil' : 'Data baru' ?></p><h2><?= $edit ? 'Edit ASN' : 'Tambah ASN' ?></h2></div></div>
            <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="employee_save"><input type="hidden" name="return_page" value="employees">
                <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
                <label>NIP<input name="nip" required maxlength="32" value="<?= e($edit['nip'] ?? '') ?>"></label>
                <label>Nama lengkap<input name="full_name" required maxlength="160" value="<?= e($edit['full_name'] ?? '') ?>"></label>
                <label>Email<input type="email" name="email" required maxlength="190" value="<?= e($edit['email'] ?? '') ?>"></label>
                <label>Nomor telepon<input name="phone" maxlength="32" value="<?= e($edit['phone'] ?? '') ?>"></label>
                <label>Jabatan<input name="position" required maxlength="120" value="<?= e($edit['position'] ?? '') ?>"></label>
                <label>Jenis kepegawaian<select name="employment_type"><?php foreach (['PNS','PPPK','Honorer','Lainnya'] as $type): ?><option <?= ($edit['employment_type'] ?? 'PNS') === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label>
                <label class="check-row"><input type="checkbox" name="is_active" value="1" <?= !isset($edit['is_active']) || $edit['is_active'] ? 'checked' : '' ?>> ASN aktif</label>
                <div class="form-actions"><button class="button button--primary" type="submit">Simpan data</button><?php if ($edit): ?><a class="button button--quiet" href="index.php?page=employees">Batal</a><?php endif; ?></div>
            </form>
        </article>
        <article class="panel panel--wide">
            <div class="panel-heading"><div><p class="eyebrow">Data kepegawaian</p><h2><?= count($employees) ?> ASN terdaftar</h2></div></div>
            <div class="table-wrap"><table><thead><tr><th>Nama / NIP</th><th>Jabatan</th><th>Jenis</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($employees as $employee): ?>
                <tr><td><strong><?= e($employee['full_name']) ?></strong><small><?= e($employee['nip']) ?></small></td><td><?= e($employee['position']) ?></td><td><?= e($employee['employment_type']) ?></td><td><span class="status <?= $employee['is_active'] ? 'status--ok' : 'status--muted' ?>"><?= $employee['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td><td class="table-actions"><a class="button button--small button--quiet" href="index.php?page=employees&edit=<?= (int) $employee['id'] ?>">Edit</a><?php if ($employee['is_active']): ?><form method="post" onsubmit="return confirm('Nonaktifkan ASN ini? Riwayat absensi tetap dipertahankan.')"><?= csrf_field() ?><input type="hidden" name="action" value="employee_delete"><input type="hidden" name="return_page" value="employees"><input type="hidden" name="id" value="<?= (int) $employee['id'] ?>"><button class="button button--small button--quiet" type="submit">Nonaktifkan</button></form><?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$employees): ?><tr><td colspan="5" class="empty-state">Belum ada data ASN. Tambahkan profil pertama melalui formulir.</td></tr><?php endif; ?>
            </tbody></table></div>
        </article>
    </section>
    <?php
}

function page_users(PDO $pdo): void
{
    require_permission($pdo, 'users.manage');
    $edit = null;
    if (isset($_GET['edit'])) {
        $statement = $pdo->prepare('SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id');
        $statement->execute(['id' => (int) $_GET['edit']]);
        $edit = $statement->fetch() ?: null;
    }
    $users = $pdo->query('SELECT u.*, r.name AS role, e.nip FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN employees e ON e.id=u.employee_id ORDER BY u.is_active DESC, u.name')->fetchAll();
    $employees = $pdo->query('SELECT id, nip, full_name FROM employees WHERE is_active=1 ORDER BY full_name')->fetchAll();
    ?>
    <div class="page-heading"><div><p class="eyebrow">Akses sistem</p><h1>Pengguna</h1><p class="muted">Atur akun, peran, dan hubungan akun ASN.</p></div></div>
    <section class="content-grid content-grid--form">
        <article class="panel">
            <div class="panel-heading"><div><p class="eyebrow"><?= $edit ? 'Ubah akses' : 'Akun baru' ?></p><h2><?= $edit ? 'Edit pengguna' : 'Tambah pengguna' ?></h2></div></div>
            <p class="muted">Buat profil pegawai terlebih dahulu, lalu hubungkan akun login dan tetapkan role. Akun non-admin wajib terhubung ke pegawai aktif.</p>
            <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="user_save"><input type="hidden" name="return_page" value="users"><input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
                <label>Nama lengkap<input name="name" required maxlength="160" value="<?= e($edit['name'] ?? '') ?>"></label>
                <label>Email<input name="email" required type="email" maxlength="190" value="<?= e($edit['email'] ?? '') ?>"></label>
                <label>Peran<select name="role" required><?php foreach (['admin','tendik','pendidik','principal'] as $role): ?><option value="<?= e($role) ?>" <?= ($edit['role'] ?? 'pendidik') === $role ? 'selected' : '' ?>><?= e(role_label($role)) ?></option><?php endforeach; ?></select></label>
                <label>Profil ASN terkait<select name="employee_id"><option value="">Tidak terhubung</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (string) ($edit['employee_id'] ?? '') === (string) $employee['id'] ? 'selected' : '' ?>><?= e($employee['full_name'] . ' · ' . $employee['nip']) ?></option><?php endforeach; ?></select></label>
                <label><?= $edit ? 'Kata sandi baru (opsional)' : 'Kata sandi awal (min. 12 karakter)' ?><input name="password" type="password" minlength="12" <?= $edit ? '' : 'required' ?> autocomplete="new-password"></label>
                <label class="check-row"><input type="checkbox" name="is_active" value="1" <?= !isset($edit['is_active']) || $edit['is_active'] ? 'checked' : '' ?>> Akun aktif</label>
                <div class="form-actions"><button class="button button--primary" type="submit">Simpan akun</button><?php if ($edit): ?><a class="button button--quiet" href="index.php?page=users">Batal</a><?php endif; ?></div>
            </form>
        </article>
        <article class="panel panel--wide"><div class="panel-heading"><div><p class="eyebrow">Akun dan hak akses</p><h2><?= count($users) ?> akun</h2></div></div>
            <div class="table-wrap"><table><thead><tr><th>Pengguna</th><th>Peran</th><th>ASN</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($users as $account): ?><tr><td><strong><?= e($account['name']) ?></strong><small><?= e($account['email']) ?></small></td><td><?= e(role_label($account['role'])) ?></td><td><?= e($account['nip'] ?? '—') ?></td><td><span class="status <?= $account['is_active'] ? 'status--ok' : 'status--muted' ?>"><?= $account['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td><td class="table-actions"><a class="button button--small button--quiet" href="index.php?page=users&edit=<?= (int) $account['id'] ?>">Edit</a><?php if ($account['is_active'] && (int) $account['id'] !== (int) current_user()['id']): ?><form method="post" onsubmit="return confirm('Nonaktifkan akun ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="user_delete"><input type="hidden" name="return_page" value="users"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><button class="button button--small button--quiet" type="submit">Nonaktifkan</button></form><?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </article>
    </section>
    <?php
}

function attendance_location_cell(array $row, string $prefix): string
{
    $latitude = $row[$prefix . '_latitude'] ?? null;
    $longitude = $row[$prefix . '_longitude'] ?? null;
    if ($latitude === null || $longitude === null) {
        return '—';
    }
    $accuracy = number_format((float) $row[$prefix . '_accuracy_m'], 0);
    $distance = number_format((float) $row[$prefix . '_distance_m'], 0);
    return number_format((float) $latitude, 5) . ', ' . number_format((float) $longitude, 5)
        . ' · ±' . $accuracy . ' m · ' . $distance . ' m dari sekolah';
}

function attendance_table(array $rows, array $user, bool $showActions, bool $showLocation = false, ?string $workStart = null): void
{
    ?>
    <div class="table-wrap"><table><thead><tr><th>ASN</th><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th>Status</th><th>Metode</th><?php if ($showLocation): ?><th>Lokasi masuk</th><th>Lokasi pulang</th><?php endif; ?><?php if ($showActions): ?><th></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><td><strong><?= e($row['full_name']) ?></strong><small><?= e($row['position'] ?? '') ?></small></td><td><?= e(format_date($row['attendance_date'])) ?></td><td><?= e($row['check_in_at'] ? date('H:i', strtotime($row['check_in_at'])) : '—') ?></td><td><?= e($row['check_out_at'] ? date('H:i', strtotime($row['check_out_at'])) : '—') ?></td><td><span class="status <?= $row['status'] === 'hadir' ? 'status--ok' : 'status--muted' ?>"><?= e(str_replace('_', ' ', ucfirst($row['status']))) ?></span><?php if ($workStart !== null && $row['status'] === 'hadir' && is_late($row, $workStart)): ?> <span class="pill">Terlambat</span><?php endif; ?></td><td><?= $row['method'] === 'face' ? 'Wajah' : 'Manual' ?></td><?php if ($showLocation): ?><td><?= e(attendance_location_cell($row, 'check_in')) ?></td><td><?= e(attendance_location_cell($row, 'check_out')) ?></td><?php endif; ?><?php if ($showActions): ?><td class="table-actions"><a class="button button--small button--quiet" href="index.php?page=attendance&edit=<?= (int) $row['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Hapus catatan ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="attendance_delete"><input type="hidden" name="return_page" value="attendance"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button type="submit" class="button button--small button--quiet">Hapus</button></form></td><?php endif; ?></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= ($showActions ? 7 : 6) + ($showLocation ? 2 : 0) ?>" class="empty-state">Belum ada catatan absensi pada periode ini.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php
}

function page_attendance(PDO $pdo, array $user): void
{
    require_permission($pdo, 'attendance.self');
    $canManage = $user['role'] === 'admin' && has_permission($pdo, $user, 'attendance.manual');
    $canViewAll = has_permission($pdo, $user, 'attendance.view_all');
    $edit = null;
    if ($canManage && isset($_GET['edit'])) {
        $statement = $pdo->prepare('SELECT * FROM attendance WHERE id=:id');
        $statement->execute(['id' => (int) $_GET['edit']]);
        $edit = $statement->fetch() ?: null;
    }
    if ($canViewAll) {
        $day = isset($_GET['date']) && valid_date((string) $_GET['date']) ? (string) $_GET['date'] : date('Y-m-d');
        $statement = $pdo->prepare(
            'SELECT a.*, e.full_name, e.position,
                    loc.check_in_latitude,loc.check_in_longitude,loc.check_in_accuracy_m,loc.check_in_distance_m,
                    loc.check_out_latitude,loc.check_out_longitude,loc.check_out_accuracy_m,loc.check_out_distance_m
             FROM attendance a JOIN employees e ON e.id=a.employee_id' . attendance_location_join_sql() . '
             WHERE a.attendance_date=:day ORDER BY a.check_in_at DESC, e.full_name'
        );
        $statement->execute(['day' => $day]);
        $rows = $statement->fetchAll();
    } elseif ($user['employee_id']) {
        $statement = $pdo->prepare(
            'SELECT a.*, e.full_name, e.position FROM attendance a JOIN employees e ON e.id=a.employee_id
             WHERE a.employee_id=:id ORDER BY a.attendance_date DESC LIMIT 20'
        );
        $statement->execute(['id' => $user['employee_id']]);
        $rows = $statement->fetchAll();
    } else {
        $rows = [];
    }
    $faceStatus = null;
    if ($user['employee_id']) {
        $statement = $pdo->prepare('SELECT consented_at, revoked_at FROM face_templates WHERE employee_id=:id');
        $statement->execute(['id' => $user['employee_id']]);
        $faceStatus = $statement->fetch() ?: null;
    }
    ?>
    <div class="page-heading"><div><p class="eyebrow"><?= e(format_date(date('Y-m-d'), 'l, d F Y')) ?></p><h1>Absensi</h1><p class="muted">Absen masuk dan pulang memerlukan verifikasi wajah dan lokasi GPS di dalam radius sekolah.</p></div></div>
    <?php if ($user['employee_id']):
        $statement = $pdo->prepare('SELECT check_in_at, check_out_at FROM attendance WHERE employee_id=:id AND attendance_date=:date');
        $statement->execute(['id' => $user['employee_id'], 'date' => date('Y-m-d')]);
        $todayRecord = $statement->fetch() ?: null;
        $registered = (bool) ($faceStatus && !$faceStatus['revoked_at']);
        $checkedIn = (bool) ($todayRecord['check_in_at'] ?? null);
        $checkedOut = (bool) ($todayRecord['check_out_at'] ?? null);
        $workStart = school_work_start($pdo);
        $lateToday = $checkedIn && is_late($todayRecord, $workStart);
        $canCheckIn = $registered && !$checkedIn;
        $canCheckOut = $registered && $checkedIn && !$checkedOut;
    ?>
    <section class="content-grid content-grid--face">
        <article class="panel face-panel">
            <div class="panel-heading"><div><p class="eyebrow">Absensi mandiri</p><h2>Absen masuk &amp; pulang</h2></div><span class="status <?= $registered ? 'status--ok' : 'status--muted' ?>"><?= $registered ? 'Wajah terdaftar' : 'Belum terdaftar' ?></span></div>
            <div class="today-status" aria-label="Status absensi hari ini">
                <div><small>Absen masuk</small><strong><?= $checkedIn ? e(date('H:i', strtotime($todayRecord['check_in_at']))) : '—' ?></strong><small><?= $lateToday ? 'Terlambat · batas ' . e(substr($workStart, 0, 5)) : 'Batas ' . e(substr($workStart, 0, 5)) ?></small></div>
                <div><small>Absen pulang</small><strong><?= $checkedOut ? e(date('H:i', strtotime($todayRecord['check_out_at']))) : '—' ?></strong><small><?= $checkedOut ? 'Tercatat' : 'Belum tercatat' ?></small></div>
                <div><small>Status hari ini</small><strong><?= $checkedOut ? 'Lengkap' : ($checkedIn ? 'Sedang bekerja' : 'Belum absen') ?></strong><small><?= e(format_date(date('Y-m-d'))) ?></small></div>
            </div>
            <div class="camera-frame"><video id="face-video" autoplay muted playsinline></video><div class="camera-placeholder" id="camera-placeholder"><span>◎</span><strong>Kamera belum aktif</strong><small>Izinkan akses kamera untuk memulai.</small></div></div>
            <ol class="face-steps" id="face-steps" aria-label="Tahapan absensi">
                <li data-step="camera" data-state="idle">Kamera</li>
                <li data-step="location" data-state="idle">Lokasi</li>
                <li data-step="blink" data-state="idle">Kedip</li>
                <li data-step="turn" data-state="idle">Tolehkan</li>
                <li data-step="verify" data-state="idle">Verifikasi</li>
            </ol>
            <div class="face-progress" id="face-progress" role="progressbar" aria-label="Kemajuan absensi" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i></i></div>
            <p class="face-hint" id="face-hint" aria-live="polite">Aktifkan kamera untuk memulai.</p>
            <p class="form-message" id="face-message" role="status" aria-live="polite"></p>
            <p class="model-status muted" id="model-status"></p>
            <div class="camera-actions">
                <button class="button button--quiet" type="button" data-camera-start>Aktifkan kamera</button>
                <button class="button button--primary" type="button" data-attend="check_in" data-allowed="<?= $canCheckIn ? '1' : '0' ?>" <?= $canCheckIn ? '' : 'disabled' ?>>Absen masuk</button>
                <button class="button button--primary" type="button" data-attend="check_out" data-allowed="<?= $canCheckOut ? '1' : '0' ?>" <?= $canCheckOut ? '' : 'disabled' ?>>Absen pulang</button>
            </div>
            <label class="consent-row"><input id="face-consent" type="checkbox"> Saya menyetujui pemrosesan template biometrik untuk verifikasi absensi dan memahami bahwa saya dapat mencabut persetujuan.</label>
            <div class="camera-actions">
                <button class="button button--quiet" type="button" data-face-enroll>Daftarkan / perbarui wajah</button>
                <?php if ($registered): ?><button class="button button--quiet" type="button" data-face-revoke>Cabut persetujuan</button><?php endif; ?>
            </div>
            <p class="notice notice--soft">Verifikasi memakai tiga tahap: lokasi GPS dalam radius sekolah, pemeriksaan keaktifan (kedip dan tolehan acak), lalu pencocokan wajah dari tiga bingkai. Ini menambah hambatan terhadap foto atau video, tetapi tidak menjamin keaslian perangkat. Tetap sediakan verifikasi manual.</p>
        </article>
        <article class="panel panel--accent"><p class="eyebrow">Privasi biometrik</p><h2>Anda tetap punya kendali.</h2><p class="muted">Template dienkripsi menggunakan kunci aplikasi, tidak digunakan untuk tujuan lain, dan dapat dicabut dari halaman ini. Gunakan HTTPS untuk penggunaan selain localhost.</p><div class="privacy-mark" aria-hidden="true">⌁</div></article>
    </section>
    <?php endif; ?>
    <?php if ($canManage): ?>
        <section class="panel">
            <div class="panel-heading"><div><p class="eyebrow">Petugas absensi</p><h2><?= $edit ? 'Edit absensi manual' : 'Catat absensi manual' ?></h2></div><span class="pill">Tersimpan di audit log</span></div>
            <form method="post" class="form-grid form-grid--inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="attendance_manual"><input type="hidden" name="return_page" value="attendance"><input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
                <label>ASN<select name="employee_id" required><option value="">Pilih ASN</option><?php foreach ($pdo->query('SELECT id,nip,full_name FROM employees ORDER BY is_active DESC,full_name') as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (string) ($edit['employee_id'] ?? '') === (string) $employee['id'] ? 'selected' : '' ?>><?= e($employee['full_name'] . ' · ' . $employee['nip']) ?><?= !$employee['is_active'] ? ' · nonaktif' : '' ?></option><?php endforeach; ?></select></label>
                <label>Tanggal<input type="date" name="attendance_date" value="<?= e($edit['attendance_date'] ?? date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></label>
                <label>Masuk<input type="time" name="check_in_at" value="<?= e($edit['check_in_at'] ? date('H:i', strtotime($edit['check_in_at'])) : '') ?>"></label><label>Pulang<input type="time" name="check_out_at" value="<?= e($edit['check_out_at'] ? date('H:i', strtotime($edit['check_out_at'])) : '') ?>"></label>
                <label>Status<select name="status"><?php foreach (['hadir'=>'Hadir','izin'=>'Izin','sakit'=>'Sakit','dinas_luar'=>'Dinas luar','alpa'=>'Alpa'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($edit['status'] ?? 'hadir') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label>Alasan override (wajib)<input name="note" maxlength="500" value="<?= e($edit['note'] ?? '') ?>" required></label><div class="form-actions"><button class="button button--primary" type="submit"><?= $edit ? 'Perbarui absensi' : 'Simpan absensi' ?></button><?php if ($edit): ?><a class="button button--quiet" href="index.php?page=attendance">Batal</a><?php endif; ?></div>
            </form>
        </section>
    <?php endif; ?>
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">Riwayat</p><h2><?= $canViewAll ? 'Absensi ' . e(format_date($day)) : 'Absensi saya' ?></h2></div><?php if ($canViewAll): ?><form method="get" class="month-picker"><input type="hidden" name="page" value="attendance"><input type="date" name="date" value="<?= e($day) ?>" max="<?= e(date('Y-m-d')) ?>"><button class="button button--small button--quiet">Tampilkan</button></form><?php endif; ?></div><?php attendance_table($rows, $user, $canManage, $user['role'] === 'admin' && $canViewAll, school_work_start($pdo)); ?></section>
    <?php
}

function page_calendar(PDO $pdo, array $user): void
{
    require_permission($pdo, 'calendar.view');
    $canManage = has_permission($pdo, $user, 'calendar.manage');
    $edit = null;
    if ($canManage && isset($_GET['edit'])) {
        $statement = $pdo->prepare('SELECT * FROM education_events WHERE id=:id');
        $statement->execute(['id' => (int) $_GET['edit']]);
        $edit = $statement->fetch() ?: null;
    }
    $requestedMonth = (string) ($_GET['month'] ?? '');
    $month = preg_match('/^\d{4}-\d{2}$/', $requestedMonth)
        && checkdate((int) substr($requestedMonth, 5, 2), 1, (int) substr($requestedMonth, 0, 4))
        ? $requestedMonth : date('Y-m');
    $monthStart = $month . '-01';
    $monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
    $eventQuery = $pdo->prepare(
        'SELECT ev.*, u.name AS creator FROM education_events ev JOIN users u ON u.id=ev.created_by
         WHERE ev.start_date<=:month_end AND ev.end_date>=:month_start ORDER BY ev.start_date DESC LIMIT 100'
    );
    $eventQuery->execute(['month_start' => $monthStart, 'month_end' => $monthEnd]);
    $events = $eventQuery->fetchAll();
    $eventsByDay = [];
    foreach ($events as $event) {
        $eventStart = max($monthStart, $event['start_date']);
        $eventEnd = min($monthEnd, $event['end_date']);
        for ($date = $eventStart; $date <= $eventEnd; $date = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')) {
            $eventsByDay[(int) substr($date, 8, 2)][] = $event;
        }
    }
    $daysInMonth = (int) (new DateTimeImmutable($monthStart))->format('t');
    $leadingDays = (int) (new DateTimeImmutable($monthStart))->format('N') - 1;
    ?>
    <div class="page-heading"><div><p class="eyebrow">Rencana sekolah</p><h1>Kalender pendidikan</h1><p class="muted">Hari efektif, libur, rapat, dan kegiatan sekolah SLB.</p></div></div>
    <section class="content-grid <?= $canManage ? 'content-grid--form' : '' ?>">
        <?php if ($canManage): ?><article class="panel"><div class="panel-heading"><div><p class="eyebrow"><?= $edit ? 'Perbarui agenda' : 'Agenda baru' ?></p><h2><?= $edit ? 'Edit kegiatan' : 'Tambah kegiatan' ?></h2></div></div>
            <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="event_save"><input type="hidden" name="return_page" value="calendar"><input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
                <label>Nama kegiatan<input name="title" maxlength="180" required value="<?= e($edit['title'] ?? '') ?>"></label>
                <label>Jenis<select name="event_type"><?php foreach (['Hari Efektif','Libur','Kegiatan Sekolah','Rapat','Lainnya'] as $type): ?><option <?= ($edit['event_type'] ?? 'Kegiatan Sekolah') === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label>
                <label>Tanggal mulai<input type="date" name="start_date" required value="<?= e($edit['start_date'] ?? '') ?>"></label>
                <label>Tanggal selesai<input type="date" name="end_date" required value="<?= e($edit['end_date'] ?? '') ?>"></label>
                <label class="form-grid__full">Deskripsi<textarea name="description" rows="3" maxlength="2000"><?= e($edit['description'] ?? '') ?></textarea></label>
                <div class="form-actions"><button class="button button--primary" type="submit">Simpan agenda</button><?php if ($edit): ?><a class="button button--quiet" href="index.php?page=calendar">Batal</a><?php endif; ?></div>
            </form>
        </article><?php endif; ?>
        <article class="panel panel--wide"><div class="panel-heading"><div><p class="eyebrow"><?= e(format_date($monthStart, 'F Y')) ?></p><h2>Agenda mendatang</h2></div><form method="get" class="month-picker"><input type="hidden" name="page" value="calendar"><input type="month" name="month" value="<?= e($month) ?>"><button class="button button--small button--quiet">Pilih</button></form></div>
            <div class="calendar-month" aria-label="Kalender bulan <?= e(format_date($monthStart, 'F Y')) ?>">
                <?php foreach (['Sen','Sel','Rab','Kam','Jum','Sab','Min'] as $weekday): ?><div class="calendar-weekday"><?= e($weekday) ?></div><?php endforeach; ?>
                <?php for ($blank = 0; $blank < $leadingDays; $blank++): ?><div class="calendar-day calendar-day--empty" aria-hidden="true"></div><?php endfor; ?>
                <?php for ($day = 1; $day <= $daysInMonth; $day++):
                    $dayKey = sprintf('%02d', $day);
                    $dayEvents = $eventsByDay[$day] ?? [];
                    $cellDate = $month . '-' . $dayKey;
                    ?>
                    <div class="calendar-day <?= $cellDate === date('Y-m-d') ? 'calendar-day--today' : '' ?>">
                        <span class="calendar-day__number"><?= $day ?></span>
                        <div class="calendar-day__events">
                            <?php foreach (array_slice($dayEvents, 0, 2) as $dayEvent): ?><span class="calendar-event" title="<?= e($dayEvent['title']) ?>"><?= e($dayEvent['title']) ?></span><?php endforeach; ?>
                            <?php if (count($dayEvents) > 2): ?><span class="calendar-event calendar-event--more">+<?= count($dayEvents) - 2 ?> agenda</span><?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="event-list">
                <?php foreach ($events as $event): ?>
                    <article class="event-item"><div class="event-date"><strong><?= e(date('d', strtotime($event['start_date']))) ?></strong><small><?= e(format_date($event['start_date'], 'M')) ?></small></div><div class="event-copy"><span class="pill"><?= e($event['event_type']) ?></span><h3><?= e($event['title']) ?></h3><p class="muted"><?= e(format_date($event['start_date'])) ?><?= $event['end_date'] !== $event['start_date'] ? ' — ' . e(format_date($event['end_date'])) : '' ?> · <?= e($event['description'] ?? 'Tidak ada catatan tambahan.') ?></p></div><?php if ($canManage): ?><div class="table-actions"><a class="button button--small button--quiet" href="index.php?page=calendar&edit=<?= (int) $event['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Hapus agenda ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="event_delete"><input type="hidden" name="return_page" value="calendar"><input type="hidden" name="id" value="<?= (int) $event['id'] ?>"><button class="button button--small button--quiet">Hapus</button></form></div><?php endif; ?></article>
                <?php endforeach; ?>
            </div>
            <?php if (!$events): ?><div class="empty-state">Belum ada agenda pada bulan ini.</div><?php endif; ?>
        </article>
    </section>
    <?php
}

function page_reports(PDO $pdo): void
{
    $currentUser = require_permission($pdo, 'reports.view');
    $from = isset($_GET['from']) && valid_date((string) $_GET['from']) ? (string) $_GET['from'] : date('Y-m-01');
    $to = isset($_GET['to']) && valid_date((string) $_GET['to']) ? (string) $_GET['to'] : date('Y-m-d');
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    $employeeId = filter_var($_GET['employee_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $employees = $pdo->query('SELECT id, full_name, nip FROM employees ORDER BY full_name')->fetchAll();
    $showLocation = $currentUser && $currentUser['role'] === 'admin';
    $sql = 'SELECT a.*, e.full_name, e.position';
    if ($showLocation) {
        $sql .= ',loc.check_in_latitude,loc.check_in_longitude,loc.check_in_accuracy_m,loc.check_in_distance_m,
                  loc.check_out_latitude,loc.check_out_longitude,loc.check_out_accuracy_m,loc.check_out_distance_m';
    }
    $sql .= ' FROM attendance a JOIN employees e ON e.id=a.employee_id';
    if ($showLocation) {
        $sql .= attendance_location_join_sql();
    }
    $sql .= ' WHERE a.attendance_date BETWEEN :from_date AND :to_date';
    $params = ['from_date' => $from, 'to_date' => $to];
    if ($employeeId) {
        $sql .= ' AND e.id=:employee_id';
        $params['employee_id'] = $employeeId;
    }
    $sql .= ' ORDER BY a.attendance_date DESC, e.full_name LIMIT 1000';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    $workStart = school_work_start($pdo);
    $late = count(array_filter($rows, fn($row) => $row['status'] === 'hadir' && is_late($row, $workStart)));
    $counts = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'dinas_luar' => 0, 'alpa' => 0];
    foreach ($rows as $row) {
        $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
    }
    ?>
    <div class="page-heading"><div><p class="eyebrow">Rekapitulasi</p><h1>Laporan absensi</h1><p class="muted">Filter dan ekspor rekap kehadiran ASN.</p></div><a class="button button--primary" href="<?= e('index.php?' . http_build_query(['page' => 'reports', 'from' => $from, 'to' => $to, 'employee_id' => $employeeId, 'export' => 'csv'])) ?>">Unduh CSV ↓</a></div>
    <section class="stats-grid stats-grid--compact">
        <?php stat_card('Hadir', $counts['hadir'], 'Catatan pada periode ini', '✓') ?>
        <?php stat_card('Izin / sakit', $counts['izin'] + $counts['sakit'], 'Perlu penyesuaian jadwal', '…') ?>
        <?php stat_card('Dinas luar', $counts['dinas_luar'], 'Tugas kedinasan', '↗') ?>
        <?php stat_card('Alpa', $counts['alpa'], 'Perlu tindak lanjut', '!') ?>
        <?php stat_card('Terlambat', $late, 'Masuk setelah ' . substr($workStart, 0, 5), '◷') ?>
    </section>
    <section class="panel"><form method="get" class="filter-form"><input type="hidden" name="page" value="reports"><label>Dari<input type="date" name="from" value="<?= e($from) ?>"></label><label>Sampai<input type="date" name="to" value="<?= e($to) ?>"></label><label>ASN<select name="employee_id"><option value="">Semua ASN</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= $employeeId === (int) $employee['id'] ? 'selected' : '' ?>><?= e($employee['full_name'] . ' · ' . $employee['nip']) ?></option><?php endforeach; ?></select></label><button class="button button--primary">Terapkan filter</button></form>
        <div class="panel-heading"><div><p class="eyebrow"><?= e(format_date($from)) ?> – <?= e(format_date($to)) ?></p><h2><?= count($rows) ?> catatan</h2></div></div>
        <?php attendance_table($rows, $currentUser, false, (bool) $showLocation, $workStart); ?>
    </section>
    <?php
}

function page_roles(PDO $pdo): void
{
    require_permission($pdo, 'roles.manage');
    $roles = $pdo->query(
        'SELECT r.id, r.name, r.label, COUNT(DISTINCT u.id) AS user_count,
                COUNT(DISTINCT CASE WHEN rp.is_allowed=1 THEN rp.permission_id END) AS permission_count
         FROM roles r
         LEFT JOIN users u ON u.role_id=r.id AND u.is_active=1
         LEFT JOIN role_permissions rp ON rp.role_id=r.id
         GROUP BY r.id, r.name, r.label
         ORDER BY FIELD(r.name, "admin", "tendik", "pendidik", "principal")'
    )->fetchAll();
    ?>
    <div class="page-heading"><div><p class="eyebrow">Manajemen akses</p><h1>Role pengguna</h1><p class="muted">Empat role sistem mengelompokkan akses ke fitur E-Absensi.</p></div><a class="button button--primary" href="index.php?page=access">Atur izin akses →</a></div>
    <section class="role-grid">
        <?php foreach ($roles as $role): ?>
            <article class="panel role-card">
                <div class="role-card__top"><span class="icon-badge"><?= e(mb_strtoupper(mb_substr($role['label'], 0, 1))) ?></span><span class="pill"><?= (int) $role['user_count'] ?> akun aktif</span></div>
                <p class="eyebrow"><?= e($role['name']) ?></p><h2><?= e($role['label']) ?></h2>
                <p class="muted"><?= (int) $role['permission_count'] ?> izin aktif dari katalog akses.</p>
                <a class="text-link" href="index.php?page=access&role_id=<?= (int) $role['id'] ?>">Tinjau akses →</a>
            </article>
        <?php endforeach; ?>
    </section>
    <div class="notice notice--soft">Role inti dipertahankan agar akun dan catatan absensi tetap konsisten. Admin menetapkan role akun dari halaman Manajemen Pengguna dan mengatur izin non-admin di Manajemen Akses.</div>
    <?php
}

function page_access(PDO $pdo): void
{
    require_permission($pdo, 'access.manage');
    $roles = $pdo->query(
        'SELECT id, name, label FROM roles
         ORDER BY FIELD(name, "admin", "tendik", "pendidik", "principal")'
    )->fetchAll();
    $selectedRoleId = filter_var($_GET['role_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
    if (!$selectedRoleId && $roles) {
        $selectedRoleId = (int) $roles[0]['id'];
    }
    $selectedRole = null;
    foreach ($roles as $role) {
        if ((int) $role['id'] === $selectedRoleId) {
            $selectedRole = $role;
            break;
        }
    }
    if (!$selectedRole) {
        http_response_code(404);
        echo '<section class="panel"><h1>Role tidak ditemukan</h1></section>';
        return;
    }
    $statement = $pdo->prepare(
        'SELECT p.id, p.name, p.label, p.group_name,
                CASE WHEN :role_name<>"admin" AND p.name IN ("attendance.manual","location.manage")
                     THEN 0 ELSE COALESCE(rp.is_allowed,0) END AS is_allowed
         FROM permissions p
         LEFT JOIN role_permissions rp ON rp.permission_id=p.id AND rp.role_id=:role_id
         ORDER BY p.group_name, p.id'
    );
    $statement->execute(['role_name' => $selectedRole['name'], 'role_id' => $selectedRoleId]);
    $permissions = $statement->fetchAll();
    $groups = [];
    foreach ($permissions as $permission) {
        $groups[$permission['group_name']][] = $permission;
    }
    ?>
    <div class="page-heading"><div><p class="eyebrow">Kendali akses berbasis role</p><h1>Manajemen akses</h1><p class="muted">Pilih akses hanya sesuai tanggung jawab pegawai.</p></div></div>
    <section class="panel access-panel">
        <div class="access-toolbar"><form method="get" class="role-picker"><input type="hidden" name="page" value="access"><label for="access-role">Role pengguna</label><select id="access-role" name="role_id" onchange="this.form.submit()"><?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>" <?= (int) $role['id'] === $selectedRoleId ? 'selected' : '' ?>><?= e($role['label']) ?></option><?php endforeach; ?></select></form><span class="pill"><?= (int) count($permissions) ?> izin terdaftar</span></div>
        <?php if ($selectedRole['name'] === 'admin'): ?>
            <div class="notice notice--soft">Role Admin memiliki akses penuh dan tidak dapat dibatasi dari halaman ini agar kendali sistem tetap dapat dipulihkan.</div>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="access_save"><input type="hidden" name="return_page" value="access"><input type="hidden" name="role_id" value="<?= $selectedRoleId ?>">
                <?php foreach ($groups as $group => $items): ?>
                    <fieldset class="permission-group"><legend><?= e($group) ?></legend>
                        <?php foreach ($items as $permission): ?>
                            <?php $adminOnly = in_array($permission['name'], ['attendance.manual', 'location.manage'], true); ?>
                            <label class="permission-row"><span><strong><?= e($permission['label']) ?></strong><small><?= e($permission['name']) ?><?= $adminOnly && $selectedRole['name'] !== 'admin' ? ' · khusus Admin' : '' ?></small></span><input type="checkbox" name="permissions[]" value="<?= e($permission['name']) ?>" <?= $adminOnly && $selectedRole['name'] !== 'admin' ? 'disabled' : '' ?> <?= $permission['is_allowed'] ? 'checked' : '' ?>></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
                <div class="form-actions"><button class="button button--primary" type="submit">Simpan izin <?= e($selectedRole['label']) ?></button><a class="button button--quiet" href="index.php?page=roles">Kelola role</a></div>
            </form>
        <?php endif; ?>
    </section>
    <?php
}

function page_location(PDO $pdo, array $user): void
{
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo '<section class="panel"><h1>Akses ditolak</h1><p class="muted">Pengaturan lokasi sekolah hanya tersedia untuk Admin.</p></section>';
        return;
    }
    require_permission($pdo, 'location.manage');
    $settings = $pdo->query(
        'SELECT s.latitude,s.longitude,s.geofence_radius_m,s.work_start_time,s.updated_at,u.name AS updated_by_name
         FROM school_settings s LEFT JOIN users u ON u.id=s.updated_by WHERE s.id=1'
    )->fetch() ?: ['latitude' => null, 'longitude' => null, 'geofence_radius_m' => 50, 'work_start_time' => '07:30:00', 'updated_at' => null, 'updated_by_name' => null];
    $configured = $settings['latitude'] !== null && $settings['longitude'] !== null;
    ?>
    <div class="page-heading"><div><p class="eyebrow">Keamanan absensi</p><h1>Lokasi sekolah</h1><p class="muted">Tetapkan titik pusat dan radius yang diizinkan untuk scan wajah.</p></div></div>
    <section class="panel settings-panel">
        <div class="panel-heading"><div><p class="eyebrow">Geofence</p><h2><?= $configured ? 'Lokasi telah diatur' : 'Lokasi belum diatur' ?></h2></div><span class="status <?= $configured ? 'status--ok' : 'status--muted' ?>"><?= $configured ? 'Aktif' : 'Perlu konfigurasi' ?></span></div>
        <p class="muted">Absensi mandiri memerlukan scan wajah dan GPS dalam radius ini. Akurasi GPS yang dilaporkan harus maksimal 50 m dan tidak boleh melebihi radius. Perangkat tidak dilacak di latar belakang; koordinat hanya disimpan bersama absensi yang berhasil.</p>
        <form method="post" class="form-grid form-grid--inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="school_location_save"><input type="hidden" name="return_page" value="location">
            <label>Latitude<input type="number" name="latitude" min="-90" max="90" step="0.0000001" value="<?= e($settings['latitude'] ?? '') ?>" required></label>
            <label>Longitude<input type="number" name="longitude" min="-180" max="180" step="0.0000001" value="<?= e($settings['longitude'] ?? '') ?>" required></label>
            <label>Radius geofence (meter)<input type="number" name="geofence_radius_m" min="10" max="5000" step="1" value="<?= e($settings['geofence_radius_m']) ?>" required></label>
            <label>Jam masuk kerja<input type="time" name="work_start_time" value="<?= e(substr((string) $settings['work_start_time'], 0, 5)) ?>" required></label>
            <div class="form-actions"><button class="button button--primary" type="submit">Simpan lokasi</button></div>
        </form>
        <div class="notice notice--soft">Ambil koordinat titik sekolah dari perangkat atau peta yang tepercaya. GPS browser dapat dipalsukan; gunakan verifikasi wajah dan tinjau catatan anomali secara berkala.</div>
        <?php if ($configured && $settings['updated_at']): ?><p class="muted settings-meta">Diperbarui <?= e($settings['updated_at']) ?><?= $settings['updated_by_name'] ? ' oleh ' . e($settings['updated_by_name']) : '' ?>.</p><?php endif; ?>
    </section>
    <?php
    $phpNow = time();
    $mysqlNow = (int) $pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    $mysqlOffset = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
    $drift = abs($mysqlNow - $phpNow);
    $currentZone = date_default_timezone_get();
    $zoneGroups = [];
    foreach (timezone_identifiers_list() as $zoneId) {
        $group = strstr($zoneId, '/', true) ?: 'Lainnya';
        $zoneGroups[$group][] = $zoneId;
    }
    ?>
    <section class="panel settings-panel">
        <div class="panel-heading"><div><p class="eyebrow">Zona waktu</p><h2>Sinkronisasi PHP &amp; MySQL</h2></div><span class="status <?= $drift <= 2 ? 'status--ok' : 'status--muted' ?>"><?= $drift <= 2 ? 'Sinkron' : 'Selisih ' . $drift . ' dtk' ?></span></div>
        <div class="clock-grid">
            <div><small>Zona aktif</small><strong><?= e($currentZone) ?></strong><small>UTC<?= e(date('P', $phpNow)) ?></small></div>
            <div><small>Waktu PHP</small><strong><?= e(date('d M Y H:i:s', $phpNow)) ?></strong><small>Dari zona aktif</small></div>
            <div><small>Waktu MySQL</small><strong><?= e(date('d M Y H:i:s', $mysqlNow)) ?></strong><small>Offset sesi <?= e($mysqlOffset) ?></small></div>
        </div>
        <form method="post" class="form-grid form-grid--inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="school_timezone_save"><input type="hidden" name="return_page" value="location">
            <label>Zona waktu sekolah<select name="timezone"><?php foreach ($zoneGroups as $group => $zones): ?><optgroup label="<?= e($group) ?>"><?php foreach ($zones as $zoneId): ?><option value="<?= e($zoneId) ?>" <?= $zoneId === $currentZone ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $zoneId)) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
            <div class="form-actions"><button class="button button--primary" type="submit">Simpan zona waktu</button></div>
        </form>
        <div class="notice notice--soft">Zona waktu diterapkan ke PHP dan MySQL sekaligus. Catatan yang sudah tersimpan tidak diubah; perubahan hanya berlaku untuk catatan baru.</div>
    </section>
    <?php
}

function page_account(): void
{
    ?>
    <div class="page-heading"><div><p class="eyebrow">Keamanan akun</p><h1>Akun saya</h1><p class="muted">Perbarui kata sandi secara berkala.</p></div></div>
    <section class="panel account-panel"><div class="panel-heading"><div><p class="eyebrow">Kredensial</p><h2>Ubah kata sandi</h2></div></div>
        <form method="post" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="action" value="account_password"><input type="hidden" name="return_page" value="account">
            <label>Kata sandi saat ini<input type="password" name="current_password" required autocomplete="current-password"></label>
            <label>Kata sandi baru<input type="password" name="new_password" minlength="12" required autocomplete="new-password"></label>
            <label>Ulangi kata sandi baru<input type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></label>
            <div class="form-actions"><button class="button button--primary" type="submit">Perbarui kata sandi</button></div>
        </form>
    </section>
    <?php
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function render_content(PDO $pdo, array $user, string $page): void
{
    switch ($page) {
        case 'dashboard': page_dashboard($pdo, $user); break;
        case 'employees': page_employees($pdo); break;
        case 'users': page_users($pdo); break;
        case 'attendance': page_attendance($pdo, $user); break;
        case 'calendar': page_calendar($pdo, $user); break;
        case 'reports': page_reports($pdo); break;
        case 'roles': page_roles($pdo); break;
        case 'access': page_access($pdo); break;
        case 'location': page_location($pdo, $user); break;
        case 'account': page_account(); break;
        default: http_response_code(404); echo '<section class="panel"><h1>Halaman tidak ditemukan</h1></section>';
    }
}
