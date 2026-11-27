<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/actions.php';
require __DIR__ . '/app/pages.php';
require __DIR__ . '/app/api.php';

refresh_current_user($pdo);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(self), geolocation=(self)');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

$page = (string) ($_GET['page'] ?? 'dashboard');
if ($page === 'api') {
    api_handle($pdo);
}
if ($page === 'face-api') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    handle_face_api($pdo, $config);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_permission($pdo, 'reports.view');
    $exportUser = current_user();
    $showLocation = $exportUser && $exportUser['role'] === 'admin';
    $from = isset($_GET['from']) && valid_date((string) $_GET['from']) ? (string) $_GET['from'] : date('Y-m-01');
    $to = isset($_GET['to']) && valid_date((string) $_GET['to']) ? (string) $_GET['to'] : date('Y-m-d');
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    $employeeId = filter_var($_GET['employee_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $sql = 'SELECT e.nip, e.full_name, e.position, a.attendance_date, a.check_in_at, a.check_out_at,
                   a.status, a.method, a.note';
    if ($showLocation) {
        $sql .= ',loc.check_in_latitude,loc.check_in_longitude,loc.check_in_accuracy_m,loc.check_in_distance_m,
                  loc.check_out_latitude,loc.check_out_longitude,loc.check_out_accuracy_m,loc.check_out_distance_m';
    }
    $sql .= ' FROM attendance a JOIN employees e ON e.id=a.employee_id';
    if ($showLocation) {
        $sql .= ' LEFT JOIN (
            SELECT attendance_id,
                   MAX(CASE WHEN event_type="check_in" THEN latitude END) AS check_in_latitude,
                   MAX(CASE WHEN event_type="check_in" THEN longitude END) AS check_in_longitude,
                   MAX(CASE WHEN event_type="check_in" THEN accuracy_m END) AS check_in_accuracy_m,
                   MAX(CASE WHEN event_type="check_in" THEN distance_m END) AS check_in_distance_m,
                   MAX(CASE WHEN event_type="check_out" THEN latitude END) AS check_out_latitude,
                   MAX(CASE WHEN event_type="check_out" THEN longitude END) AS check_out_longitude,
                   MAX(CASE WHEN event_type="check_out" THEN accuracy_m END) AS check_out_accuracy_m,
                   MAX(CASE WHEN event_type="check_out" THEN distance_m END) AS check_out_distance_m
            FROM attendance_location_evidence GROUP BY attendance_id
        ) loc ON loc.attendance_id=a.id';
    }
    $sql .= ' WHERE a.attendance_date BETWEEN :from_date AND :to_date';
    $params = ['from_date' => $from, 'to_date' => $to];
    if ($employeeId) {
        $sql .= ' AND e.id=:employee_id';
        $params['employee_id'] = $employeeId;
    }
    $sql .= ' ORDER BY a.attendance_date, e.full_name LIMIT 10000';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-absensi-' . $from . '-sampai-' . $to . '.csv"');
    $workStart = school_work_start($pdo);
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    $headers = ['NIP', 'Nama', 'Jabatan', 'Tanggal', 'Jam masuk', 'Jam pulang', 'Status', 'Metode', 'Catatan', 'Terlambat'];
    if ($showLocation) {
        $headers = array_merge($headers, ['Lokasi masuk (lat,long)', 'Akurasi masuk (m)', 'Jarak masuk (m)', 'Lokasi pulang (lat,long)', 'Akurasi pulang (m)', 'Jarak pulang (m)']);
    }
    fputcsv($output, $headers);
    while ($row = $statement->fetch()) {
        $values = [
            $row['nip'], $row['full_name'], $row['position'], $row['attendance_date'],
            $row['check_in_at'] ? date('H:i', strtotime($row['check_in_at'])) : '',
            $row['check_out_at'] ? date('H:i', strtotime($row['check_out_at'])) : '',
            $row['status'], $row['method'], $row['note'] ?? '',
            $row['status'] === 'hadir' && is_late($row, $workStart) ? 'Ya' : 'Tidak',
        ];
        if ($showLocation) {
            $values = array_merge($values, [
                $row['check_in_latitude'] === null ? '' : $row['check_in_latitude'] . ',' . $row['check_in_longitude'],
                $row['check_in_accuracy_m'] ?? '',
                $row['check_in_distance_m'] ?? '',
                $row['check_out_latitude'] === null ? '' : $row['check_out_latitude'] . ',' . $row['check_out_longitude'],
                $row['check_out_accuracy_m'] ?? '',
                $row['check_out_distance_m'] ?? '',
            ]);
        }
        foreach ($values as &$value) {
            $value = (string) $value;
            $looksLikeFormula = preg_match('/^[\s]*[=+@]/', $value)
                || (preg_match('/^[\s]*-/', $value) && !preg_match('/^\s*-?[\d.,\s]+$/', $value));
            if ($looksLikeFormula) {
                $value = "'" . $value;
            }
        }
        unset($value);
        fputcsv($output, $values);
    }
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post($pdo, $config);
}

$user = current_user();
if ($page === 'login') {
    if ($user) {
        redirect('dashboard');
    }
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <script src="public/assets/theme.js"></script>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Masuk · E-Absensi SLB</title>
        
        <link rel="stylesheet" href="public/assets/app.css"><script defer src="public/assets/app.js"></script>
    </head>
    <body class="login-page">
        <main class="login-layout">
            <section class="login-intro"><a class="brand brand--large" href="index.php"><span class="brand-mark">e</span><span>E-Absensi<small>SLB · Kehadiran ASN</small></span></a>
                <div><p class="eyebrow">Ruang kerja sekolah</p><h1>Hadir sepenuh hati,<br><em>melayani dengan pasti.</em></h1><p class="muted">Satu ruang yang tenang untuk kehadiran, kalender, dan perjalanan sekolah kita.</p></div>
                <div class="login-decoration" aria-hidden="true"><span>SLB</span><strong>Setiap<br>kehadiran<br>berarti.</strong><i>✳</i></div>
                <small class="login-foot">Sistem informasi absensi ASN sekolah luar biasa</small>
            </section>
            <section class="login-form-wrap"><div class="login-form-card">
                <button class="theme-toggle" type="button" data-theme-toggle aria-pressed="false"><span class="theme-toggle__icon" aria-hidden="true">&#9790;</span><span class="theme-toggle__label">Mode gelap</span></button>
                <p class="eyebrow">Selamat datang kembali</p><h2>Masuk ke akun Anda</h2><p class="muted">Gunakan email dan kata sandi yang terdaftar.</p>
                <?php foreach (take_flashes() as $message): ?><div class="flash flash--<?= e($message['type']) ?>" role="alert"><?= e($message['message']) ?></div><?php endforeach; ?>
                <form method="post" class="form-grid login-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="login">
                    <label>Email<input type="email" name="email" autocomplete="username" required autofocus></label>
                    <label>Kata sandi<input type="password" name="password" autocomplete="current-password" required></label>
                    <button class="button button--primary button--full" type="submit">Masuk <span aria-hidden="true">→</span></button>
                </form>
                <p class="login-help">Hubungi administrator sekolah bila Anda memerlukan akses.</p>
            </div></section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if (!$user) {
    redirect('login');
}
$allowedPages = ['dashboard', 'employees', 'users', 'attendance', 'calendar', 'reports', 'roles', 'access', 'location', 'account'];
if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'dashboard';
}
$navigation = [
    'dashboard' => ['Ringkasan', '◫', 'dashboard.view'],
    'attendance' => ['Absensi', '◷', 'attendance.self'],
    'calendar' => ['Kalender pendidikan', '▦', 'calendar.view'],
    'reports' => ['Laporan', '▤', 'reports.view'],
    'employees' => ['Data pegawai', '♙', 'employees.manage'],
    'users' => ['Pengguna', '♧', 'users.manage'],
    'roles' => ['Manajemen role', '◈', 'roles.manage'],
    'access' => ['Manajemen akses', '⚙', 'access.manage'],
    'location' => ['Lokasi sekolah', '⌖', 'location.manage'],
];
$flashes = take_flashes();
?>
<!doctype html>
<html lang="id">
<head>
    <script src="public/assets/theme.js"></script>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e(page_title($page)) ?> · E-Absensi SLB</title>
    <link rel="stylesheet" href="public/assets/app.css">
    <?php if ($page === 'attendance' && $user['employee_id']): ?><script defer src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/dist/face-api.js"></script><?php endif; ?>
    
    <script defer src="public/assets/app.js"></script>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar" id="app-sidebar">
            <a class="brand" href="index.php?page=dashboard"><span class="brand-mark">e</span><span>E-Absensi<small>SLB · ASN</small></span></a>
            <div class="school-switcher"><span class="school-avatar">S</span><span><strong>SLB Sekolah</strong><small>Panel sekolah</small></span><span class="switcher-dots">···</span></div>
            <nav class="main-nav" aria-label="Navigasi utama">
                <small class="nav-label">MENU UTAMA</small>
                <?php foreach ($navigation as $key => [$label, $icon, $permission]): if (!has_permission($pdo, $user, $permission)) continue; ?>
                    <a class="nav-link <?= $page === $key ? 'is-active' : '' ?>" href="index.php?page=<?= e($key) ?>"><span class="nav-icon"><?= e($icon) ?></span><?= e($label) ?><?php if ($key === 'attendance'): ?><span class="nav-live"></span><?php endif; ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom"><button class="theme-toggle theme-toggle--sidebar" type="button" data-theme-toggle aria-pressed="false"><span class="theme-toggle__icon" aria-hidden="true">&#9790;</span><span class="theme-toggle__label">Mode gelap</span></button><a class="nav-link <?= $page === 'account' ? 'is-active' : '' ?>" href="index.php?page=account"><span class="nav-icon">⚙</span>Akun saya</a>
                <div class="user-chip"><span class="user-avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span><span class="user-chip__text"><strong><?= e($user['name']) ?></strong><small><?= e(role_label($user['role'])) ?></small></span><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="icon-button icon-button--logout" title="Keluar" aria-label="Keluar" type="submit">Log out</button></form></div>
            </div>
        </aside>
        <main class="main-content">
            <header class="topbar"><button class="menu-toggle" type="button" aria-label="Buka navigasi" data-menu-toggle>☰</button><div class="breadcrumb"><span>Beranda</span><i>/</i><strong><?= e(page_title($page)) ?></strong></div><div class="topbar-meta"><span class="topbar-date"><?= e(format_date(date('Y-m-d'), 'd M Y')) ?></span><span class="topbar-avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span></div></header>
            <div class="page-container">
                <?php foreach ($flashes as $message): ?><div class="flash flash--<?= e($message['type']) ?>" role="alert"><?= e($message['message']) ?><button type="button" aria-label="Tutup" data-dismiss>×</button></div><?php endforeach; ?>
                <?php render_content($pdo, $user, $page); ?>
                <footer class="app-footer"><span>© <?= date('Y') ?> E-Absensi SLB</span><span>Dibuat untuk mendukung layanan pendidikan inklusif <i>✳</i></span></footer>
            </div>
        </main>
    </div>
</body>
</html>
