<?php
declare(strict_types=1);

function post_value(string $key, int $maxLength = 500): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException('Panjang salah satu isian melebihi batas.');
    }
    return $value;
}

function positive_id(string $key): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    if (!$value || $value < 1) {
        throw new InvalidArgumentException('ID data tidak valid.');
    }
    return $value;
}

function valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function handle_post(PDO $pdo, array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    verify_csrf($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $user = current_user();

    try {
        switch ($action) {
            case 'login':
                if ($user) {
                    redirect('dashboard');
                }
                $loginEmail = post_value('email', 190);
                if (login_throttled($pdo, $loginEmail)) {
                    flash('error', 'Terlalu banyak percobaan masuk. Coba lagi dalam 15 menit.');
                    redirect('login');
                }
                if (attempt_login($pdo, $loginEmail, (string) ($_POST['password'] ?? ''))) {
                    redirect('dashboard');
                }
                audit($pdo, null, 'login_failed', 'user', null, [
                    'email_hash' => hash('sha256', mb_strtolower($loginEmail)),
                ]);
                flash('error', 'Email atau kata sandi tidak sesuai, atau akun tidak aktif.');
                redirect('login');

            case 'logout':
                logout_user($pdo);
                redirect('login');

            case 'employee_save':
                require_permission($pdo, 'employees.manage');
                $id = isset($_POST['id']) && $_POST['id'] !== '' ? positive_id('id') : null;
                $nip = post_value('nip', 32);
                $name = post_value('full_name', 160);
                $email = mb_strtolower(post_value('email', 190));
                $position = post_value('position', 120);
                $type = post_value('employment_type', 20);
                if ($nip === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $position === '') {
                    throw new InvalidArgumentException('Lengkapi NIP, nama, email, dan jabatan dengan benar.');
                }
                if (!in_array($type, ['PNS', 'PPPK', 'Honorer', 'Lainnya'], true)) {
                    throw new InvalidArgumentException('Jenis kepegawaian tidak valid.');
                }
                $params = [
                    'nip' => $nip,
                    'full_name' => $name,
                    'email' => $email,
                    'phone' => post_value('phone', 32) ?: null,
                    'position' => $position,
                    'employment_type' => $type,
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ];
                $pdo->beginTransaction();
                if ($id) {
                    $params['id'] = $id;
                    $statement = $pdo->prepare(
                        'UPDATE employees SET nip=:nip, full_name=:full_name, email=:email, phone=:phone,
                         position=:position, employment_type=:employment_type, is_active=:is_active WHERE id=:id'
                    );
                    $statement->execute($params);
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO employees (nip, full_name, email, phone, position, employment_type, is_active)
                         VALUES (:nip, :full_name, :email, :phone, :position, :employment_type, :is_active)'
                    );
                    $statement->execute($params);
                    $id = (int) $pdo->lastInsertId();
                }
                if (!$params['is_active']) {
                    $pdo->prepare('UPDATE users SET is_active=0 WHERE employee_id=:id')->execute(['id' => $id]);
                    $pdo->prepare('UPDATE face_templates SET ciphertext="", nonce="", revoked_at=NOW() WHERE employee_id=:id')
                        ->execute(['id' => $id]);
                }
                audit($pdo, (int) $user['id'], $id ? 'save' : 'create', 'employee', (string) $id);
                $pdo->commit();
                flash('success', 'Data ASN berhasil disimpan.');
                redirect('employees');

            case 'employee_delete':
                require_permission($pdo, 'employees.manage');
                $id = positive_id('id');
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE employees SET is_active=0 WHERE id=:id')->execute(['id' => $id]);
                $pdo->prepare('UPDATE users SET is_active=0 WHERE employee_id=:id')->execute(['id' => $id]);
                $pdo->prepare('UPDATE face_templates SET ciphertext="", nonce="", revoked_at=NOW() WHERE employee_id=:id')
                    ->execute(['id' => $id]);
                audit($pdo, (int) $user['id'], 'deactivate', 'employee', (string) $id);
                $pdo->commit();
                flash('success', 'ASN dinonaktifkan; riwayat absensi tetap tersimpan.');
                redirect('employees');

            case 'user_save':
                require_permission($pdo, 'users.manage');
                $id = isset($_POST['id']) && $_POST['id'] !== '' ? positive_id('id') : null;
                $name = post_value('name', 160);
                $email = mb_strtolower(post_value('email', 190));
                $role = post_value('role', 32);
                $employeeId = ($_POST['employee_id'] ?? '') === '' ? null : positive_id('employee_id');
                $password = (string) ($_POST['password'] ?? '');
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Nama dan email harus diisi dengan benar.');
                }
                $roleQuery = $pdo->prepare('SELECT id FROM roles WHERE name=:name');
                $roleQuery->execute(['name' => $role]);
                $roleId = $roleQuery->fetchColumn();
                if (!$roleId || !in_array($role, ['admin', 'tendik', 'pendidik', 'principal'], true)) {
                    throw new InvalidArgumentException('Peran tidak valid.');
                }
                if ($role !== 'admin' && !$employeeId) {
                    throw new InvalidArgumentException('Akun Tendik, Pendidik, dan Kepala Sekolah harus terhubung ke data pegawai.');
                }
                if ($employeeId) {
                    $employeeQuery = $pdo->prepare('SELECT is_active FROM employees WHERE id=:id');
                    $employeeQuery->execute(['id' => $employeeId]);
                    if (!(int) $employeeQuery->fetchColumn()) {
                        throw new InvalidArgumentException('Pilih data pegawai aktif yang valid.');
                    }
                }
                $isActive = isset($_POST['is_active']);
                if ($id && (int) $user['id'] === $id && $role !== $user['role']) {
                    throw new InvalidArgumentException('Peran akun yang sedang digunakan tidak dapat diubah.');
                }
                if ($id && (int) $user['id'] === $id && !$isActive) {
                    throw new InvalidArgumentException('Akun yang sedang digunakan tidak dapat dinonaktifkan.');
                }
                if ($id) {
                    $existingRole = $pdo->prepare(
                        'SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id'
                    );
                    $existingRole->execute(['id' => $id]);
                    if ($existingRole->fetchColumn() === 'admin' && (!$isActive || $role !== 'admin')) {
                        $otherAdmins = $pdo->prepare(
                            'SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id
                             WHERE r.name="admin" AND u.is_active=1 AND u.id<>:id'
                        );
                        $otherAdmins->execute(['id' => $id]);
                        if ((int) $otherAdmins->fetchColumn() === 0) {
                            throw new InvalidArgumentException('Admin aktif terakhir tidak dapat dinonaktifkan atau diturunkan rolenya.');
                        }
                    }
                }
                if (!$id && strlen($password) < 12) {
                    throw new InvalidArgumentException('Kata sandi akun baru minimal 12 karakter.');
                }
                if ($id) {
                    $pdo->beginTransaction();
                    $sql = 'UPDATE users SET employee_id=:employee_id, role_id=:role_id, name=:name, email=:email,
                            is_active=:is_active';
                    $params = [
                        'employee_id' => $employeeId,
                        'role_id' => $roleId,
                        'name' => $name,
                        'email' => $email,
                        'is_active' => $isActive ? 1 : 0,
                        'id' => $id,
                    ];
                    if ($password !== '') {
                        if (strlen($password) < 12) {
                            throw new InvalidArgumentException('Kata sandi minimal 12 karakter.');
                        }
                        $sql .= ', password_hash=:password_hash';
                        $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    $sql .= ' WHERE id=:id';
                    $pdo->prepare($sql)->execute($params);
                    if (!isset($_POST['is_active']) && $employeeId) {
                        $pdo->prepare('UPDATE face_templates SET ciphertext="", nonce="", revoked_at=NOW() WHERE employee_id=:id')
                            ->execute(['id' => $employeeId]);
                    }
                } else {
                    $pdo->beginTransaction();
                    $statement = $pdo->prepare(
                        'INSERT INTO users (employee_id, role_id, name, email, password_hash, is_active)
                         VALUES (:employee_id, :role_id, :name, :email, :password_hash, :is_active)'
                    );
                    $statement->execute([
                        'employee_id' => $employeeId,
                        'role_id' => $roleId,
                        'name' => $name,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'is_active' => $isActive ? 1 : 0,
                    ]);
                    $id = (int) $pdo->lastInsertId();
                    if (!isset($_POST['is_active']) && $employeeId) {
                        $pdo->prepare('UPDATE face_templates SET ciphertext="", nonce="", revoked_at=NOW() WHERE employee_id=:id')
                            ->execute(['id' => $employeeId]);
                    }
                }
                audit($pdo, (int) $user['id'], 'save', 'user', (string) $id);
                $pdo->commit();
                flash('success', 'Akun pengguna berhasil disimpan.');
                redirect('users');

            case 'user_delete':
                require_permission($pdo, 'users.manage');
                $id = positive_id('id');
                if ($id === (int) $user['id']) {
                    throw new InvalidArgumentException('Akun yang sedang digunakan tidak dapat dinonaktifkan.');
                }
                $statement = $pdo->prepare('SELECT employee_id FROM users WHERE id=:id');
                $statement->execute(['id' => $id]);
                $account = $statement->fetch();
                if (!$account) {
                    throw new InvalidArgumentException('Akun pengguna tidak ditemukan.');
                }
                $statement = $pdo->prepare(
                    'SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id'
                );
                $statement->execute(['id' => $id]);
                if ($statement->fetchColumn() === 'admin') {
                    $otherAdmins = $pdo->prepare(
                        'SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id
                         WHERE r.name="admin" AND u.is_active=1 AND u.id<>:id'
                    );
                    $otherAdmins->execute(['id' => $id]);
                    if ((int) $otherAdmins->fetchColumn() === 0) {
                        throw new InvalidArgumentException('Admin aktif terakhir tidak dapat dinonaktifkan.');
                    }
                }
                $employeeId = $account['employee_id'];
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET is_active=0 WHERE id=:id')->execute(['id' => $id]);
                if ($employeeId) {
                    $pdo->prepare('UPDATE face_templates SET ciphertext="", nonce="", revoked_at=NOW() WHERE employee_id=:id')
                        ->execute(['id' => $employeeId]);
                }
                audit($pdo, (int) $user['id'], 'deactivate', 'user', (string) $id);
                $pdo->commit();
                flash('success', 'Akun dinonaktifkan.');
                redirect('users');

            case 'event_save':
                require_permission($pdo, 'calendar.manage');
                $id = isset($_POST['id']) && $_POST['id'] !== '' ? positive_id('id') : null;
                $title = post_value('title', 180);
                $type = post_value('event_type', 32);
                $start = post_value('start_date', 10);
                $end = post_value('end_date', 10);
                if ($title === '' || !valid_date($start) || !valid_date($end) || $end < $start) {
                    throw new InvalidArgumentException('Judul dan rentang tanggal kalender harus valid.');
                }
                if (!in_array($type, ['Hari Efektif', 'Libur', 'Kegiatan Sekolah', 'Rapat', 'Lainnya'], true)) {
                    throw new InvalidArgumentException('Jenis kegiatan tidak valid.');
                }
                $params = [
                    'title' => $title,
                    'event_type' => $type,
                    'start_date' => $start,
                    'end_date' => $end,
                    'description' => post_value('description', 2000) ?: null,
                ];
                if ($id) {
                    $params['id'] = $id;
                    $pdo->prepare(
                        'UPDATE education_events SET title=:title, event_type=:event_type, start_date=:start_date,
                         end_date=:end_date, description=:description WHERE id=:id'
                    )->execute($params);
                } else {
                    $params['created_by'] = $user['id'];
                    $pdo->prepare(
                        'INSERT INTO education_events (title, event_type, start_date, end_date, description, created_by)
                         VALUES (:title, :event_type, :start_date, :end_date, :description, :created_by)'
                    )->execute($params);
                    $id = (int) $pdo->lastInsertId();
                }
                audit($pdo, (int) $user['id'], 'save', 'education_event', (string) $id);
                flash('success', 'Agenda kalender berhasil disimpan.');
                redirect('calendar');

            case 'event_delete':
                require_permission($pdo, 'calendar.manage');
                $id = positive_id('id');
                $pdo->prepare('DELETE FROM education_events WHERE id=:id')->execute(['id' => $id]);
                audit($pdo, (int) $user['id'], 'delete', 'education_event', (string) $id);
                flash('success', 'Agenda kalender dihapus.');
                redirect('calendar');

            case 'attendance_manual':
                if (!$user || $user['role'] !== 'admin') {
                    http_response_code(403);
                    exit('Override absensi manual hanya dapat dilakukan Admin.');
                }
                require_permission($pdo, 'attendance.manual');
                $employeeId = positive_id('employee_id');
                $date = post_value('attendance_date', 10);
                $status = post_value('status', 20);
                if (!valid_date($date) || $date > date('Y-m-d')) {
                    throw new InvalidArgumentException('Tanggal absensi tidak valid.');
                }
                if (!in_array($status, ['hadir', 'izin', 'sakit', 'dinas_luar', 'alpa'], true)) {
                    throw new InvalidArgumentException('Status absensi tidak valid.');
                }
                $checkIn = post_value('check_in_at', 5);
                $checkOut = post_value('check_out_at', 5);
                foreach ([$checkIn, $checkOut] as $time) {
                    if ($time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                        throw new InvalidArgumentException('Format jam harus HH:MM.');
                    }
                }
                if ($checkIn !== '' && $checkOut !== '' && $checkOut <= $checkIn) {
                    throw new InvalidArgumentException('Jam pulang harus setelah jam masuk.');
                }
                $id = isset($_POST['id']) && $_POST['id'] !== '' ? positive_id('id') : null;
                $reason = post_value('note', 500);
                if ($reason === '') {
                    throw new InvalidArgumentException('Alasan override manual wajib diisi.');
                }
                $values = [
                    'employee_id' => $employeeId,
                    'attendance_date' => $date,
                    'check_in_at' => $checkIn === '' ? null : "$date $checkIn:00",
                    'check_out_at' => $checkOut === '' ? null : "$date $checkOut:00",
                    'status' => $status,
                    'note' => $reason,
                    'recorded_by' => $user['id'],
                ];
                $pdo->beginTransaction();
                if ($id) {
                    $existing = $pdo->prepare('SELECT id FROM attendance WHERE id=:id FOR UPDATE');
                    $existing->execute(['id' => $id]);
                    if (!$existing->fetchColumn()) {
                        $pdo->rollBack();
                        throw new InvalidArgumentException('Catatan absensi yang akan dikoreksi tidak ditemukan.');
                    }
                    $values['id'] = $id;
                    $pdo->prepare(
                        'UPDATE attendance SET employee_id=:employee_id, attendance_date=:attendance_date,
                         check_in_at=:check_in_at, check_out_at=:check_out_at, status=:status, note=:note,
                         method="manual", recorded_by=:recorded_by WHERE id=:id'
                    )->execute($values);
                } else {
                    $pdo->prepare(
                        'INSERT INTO attendance (employee_id, attendance_date, check_in_at, check_out_at, status, note, method, recorded_by)
                         VALUES (:employee_id, :attendance_date, :check_in_at, :check_out_at, :status, :note, "manual", :recorded_by)
                         ON DUPLICATE KEY UPDATE check_in_at=VALUES(check_in_at), check_out_at=VALUES(check_out_at),
                         status=VALUES(status), note=VALUES(note), method="manual", recorded_by=VALUES(recorded_by)'
                    )->execute($values);
                    $lookup = $pdo->prepare('SELECT id FROM attendance WHERE employee_id=:employee_id AND attendance_date=:date');
                    $lookup->execute(['employee_id' => $employeeId, 'date' => $date]);
                    $id = (int) $lookup->fetchColumn();
                }
                $pdo->prepare('DELETE FROM attendance_location_evidence WHERE attendance_id=:id')->execute(['id' => $id]);
                audit($pdo, (int) $user['id'], 'manual_entry', 'attendance', $id ? (string) $id : null, [
                    'employee_id' => $employeeId,
                    'date' => $date,
                    'reason' => $reason,
                ]);
                $pdo->commit();
                flash('success', 'Absensi manual berhasil disimpan.');
                redirect('attendance');

            case 'attendance_delete':
                if (!$user || $user['role'] !== 'admin') {
                    http_response_code(403);
                    exit('Penghapusan catatan absensi hanya dapat dilakukan Admin.');
                }
                require_permission($pdo, 'attendance.manual');
                $id = positive_id('id');
                $pdo->prepare('DELETE FROM attendance WHERE id=:id')->execute(['id' => $id]);
                audit($pdo, (int) $user['id'], 'delete', 'attendance', (string) $id);
                flash('success', 'Catatan absensi dihapus.');
                redirect('attendance');

            case 'account_password':
                $user = require_login();
                $current = (string) ($_POST['current_password'] ?? '');
                $new = (string) ($_POST['new_password'] ?? '');
                $confirm = (string) ($_POST['confirm_password'] ?? '');
                $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id=:id');
                $statement->execute(['id' => $user['id']]);
                $hash = $statement->fetchColumn();
                if (!$hash || !password_verify($current, $hash)) {
                    throw new InvalidArgumentException('Kata sandi saat ini tidak sesuai.');
                }
                if (strlen($new) < 12 || $new !== $confirm) {
                    throw new InvalidArgumentException('Kata sandi baru minimal 12 karakter dan harus sama dengan konfirmasi.');
                }
                $pdo->prepare('UPDATE users SET password_hash=:hash WHERE id=:id')->execute([
                    'hash' => password_hash($new, PASSWORD_DEFAULT),
                    'id' => $user['id'],
                ]);
                audit($pdo, (int) $user['id'], 'change_password', 'user', (string) $user['id']);
                flash('success', 'Kata sandi berhasil diubah.');
                redirect('account');

            case 'access_save':
                require_permission($pdo, 'access.manage');
                $roleId = positive_id('role_id');
                $roleQuery = $pdo->prepare('SELECT name FROM roles WHERE id=:id');
                $roleQuery->execute(['id' => $roleId]);
                $roleName = $roleQuery->fetchColumn();
                if (!$roleName || $roleName === 'admin') {
                    throw new InvalidArgumentException('Izin administrator inti tidak dapat diubah.');
                }
                $permissions = $pdo->query('SELECT id,name FROM permissions')->fetchAll();
                $selected = $_POST['permissions'] ?? [];
                if (!is_array($selected)) {
                    throw new InvalidArgumentException('Daftar izin tidak valid.');
                }
                $selected = array_map('strval', $selected);
                $pdo->beginTransaction();
                $update = $pdo->prepare(
                    'UPDATE role_permissions SET is_allowed=:allowed WHERE role_id=:role_id AND permission_id=:permission_id'
                );
                foreach ($permissions as $permission) {
                    $restrictedToAdmin = in_array($permission['name'], ['attendance.manual', 'location.manage'], true);
                    $update->execute([
                        'allowed' => in_array($permission['name'], $selected, true)
                            && !($restrictedToAdmin && $roleName !== 'admin') ? 1 : 0,
                        'role_id' => $roleId,
                        'permission_id' => $permission['id'],
                    ]);
                }
                audit($pdo, (int) $user['id'], 'update_permissions', 'role', (string) $roleId);
                $pdo->commit();
                flash('success', 'Izin akses role berhasil diperbarui.');
                redirect('access');

            case 'school_location_save':
                if (!$user || $user['role'] !== 'admin') {
                    http_response_code(403);
                    exit('Pengaturan lokasi sekolah hanya dapat dilakukan Admin.');
                }
                require_permission($pdo, 'location.manage');
                $latitude = filter_var(post_value('latitude', 32), FILTER_VALIDATE_FLOAT);
                $longitude = filter_var(post_value('longitude', 32), FILTER_VALIDATE_FLOAT);
                $radius = filter_var($_POST['geofence_radius_m'] ?? null, FILTER_VALIDATE_INT);
                $workStart = post_value('work_start_time', 5);
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $workStart)) {
                    throw new InvalidArgumentException('Jam masuk kerja harus berformat HH:MM.');
                }
                if ($latitude === false || $longitude === false
                    || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                    throw new InvalidArgumentException('Koordinat sekolah tidak valid.');
                }
                if ($radius === false || $radius < 10 || $radius > 5000) {
                    throw new InvalidArgumentException('Radius geofence harus antara 10 dan 5000 meter.');
                }
                $pdo->beginTransaction();
                $pdo->prepare(
                    'UPDATE school_settings SET latitude=:latitude, longitude=:longitude,
                     geofence_radius_m=:radius, work_start_time=:work_start, updated_by=:user_id WHERE id=1'
                )->execute([
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'radius' => $radius,
                    'work_start' => $workStart . ':00',
                    'user_id' => $user['id'],
                ]);
                audit($pdo, (int) $user['id'], 'update_geofence', 'school_settings', '1', ['radius_m' => $radius]);
                $pdo->commit();
                flash('success', 'Lokasi sekolah dan radius geofence berhasil disimpan.');
                redirect('location');

            case 'school_timezone_save':
                if (!$user || $user['role'] !== 'admin') {
                    http_response_code(403);
                    exit('Pengaturan zona waktu hanya dapat dilakukan Admin.');
                }
                require_permission($pdo, 'location.manage');
                $zone = post_value('timezone', 64);
                if (!valid_timezone($zone)) {
                    throw new InvalidArgumentException('Zona waktu tidak valid.');
                }
                $pdo->prepare('UPDATE school_settings SET timezone=:zone, updated_by=:user_id WHERE id=1')
                    ->execute(['zone' => $zone, 'user_id' => $user['id']]);
                date_default_timezone_set($zone);
                sync_mysql_timezone($pdo);
                audit($pdo, (int) $user['id'], 'update_timezone', 'school_settings', '1', ['timezone' => $zone]);
                flash('success', 'Zona waktu disimpan dan disinkronkan ke PHP serta MySQL.');
                redirect('location');

            default:
                throw new InvalidArgumentException('Aksi tidak dikenali.');
        }
    } catch (InvalidArgumentException $exception) {
        flash('error', $exception->getMessage());
        redirect((string) ($_POST['return_page'] ?? 'dashboard'));
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        $message = $exception->getCode() === '23000'
            ? 'Data bertabrakan dengan data yang sudah ada atau masih digunakan.'
            : 'Perubahan tidak dapat disimpan karena terjadi kesalahan database.';
        flash('error', $message);
        redirect((string) ($_POST['return_page'] ?? 'dashboard'));
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        flash('error', 'Layanan biometrik belum dikonfigurasi dengan benar.');
        redirect((string) ($_POST['return_page'] ?? 'dashboard'));
    }
}

function consume_face_liveness(mixed $proof): bool
{
    $challenge = $_SESSION['face_liveness_challenge'] ?? null;
    unset($_SESSION['face_liveness_challenge']);

    if (!is_array($proof)
        || !is_array($challenge)
        || !isset($challenge['id'], $challenge['steps'], $challenge['expires_at'])
        || !is_string($proof['challenge_id'] ?? null)
        || !is_array($proof['completed_steps'] ?? null)
        || $challenge['expires_at'] < time()) {
        return false;
    }

    return hash_equals($challenge['id'], $proof['challenge_id'])
        && array_values($proof['completed_steps']) === $challenge['steps'];
}

function handle_face_api(PDO $pdo, array $config): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Silakan masuk kembali.']);
        exit;
    }
    if (!has_permission($pdo, $user, 'attendance.self')) {
        http_response_code(403);
        echo json_encode(['error' => 'Akses ditolak.']);
        exit;
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        echo json_encode(['error' => 'Sesi kedaluwarsa. Muat ulang halaman.']);
        exit;
    }
    if (!$user['employee_id']) {
        http_response_code(422);
        echo json_encode(['error' => 'Akun belum terhubung ke data ASN.']);
        exit;
    }

    try {
        $body = file_get_contents('php://input', false, null, 0, 16385);
        if (!is_string($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'Permintaan tidak dapat dibaca.']);
            exit;
        }
        if (strlen($body) > 16384) {
            http_response_code(413);
            echo json_encode(['error' => 'Ukuran permintaan terlalu besar.']);
            exit;
        }
        $input = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($input)) {
            http_response_code(422);
            echo json_encode(['error' => 'Permintaan biometrik tidak valid.']);
            exit;
        }
        $employeeId = (int) $user['employee_id'];
        $operation = (string) ($input['operation'] ?? '');
        if ($operation === 'liveness_challenge') {
            $steps = random_int(0, 1) === 0
                ? ['blink', 'turn_head']
                : ['turn_head', 'blink'];
            $challengeId = bin2hex(random_bytes(32));
            $_SESSION['face_liveness_challenge'] = [
                'id' => $challengeId,
                'steps' => $steps,
                'expires_at' => time() + 90,
            ];
            echo json_encode([
                'challenge_id' => $challengeId,
                'steps' => $steps,
                'expires_in' => 90,
            ]);
            exit;
        }
        if ($operation === 'enroll') {
            if (!valid_descriptor($input['descriptor'] ?? null)) {
                http_response_code(422);
                echo json_encode(['error' => 'Data wajah tidak valid.']);
                exit;
            }
            if (($input['consent'] ?? false) !== true) {
                http_response_code(422);
                echo json_encode(['error' => 'Persetujuan biometrik harus diberikan.']);
                exit;
            }
            if (!consume_face_liveness($input['liveness'] ?? [])) {
                http_response_code(422);
                echo json_encode(['error' => 'Pemeriksaan keaktifan gagal atau kedaluwarsa. Ulangi tantangan.']);
                exit;
            }
            $template = encrypt_template($input['descriptor'], $config);
            $statement = $pdo->prepare(
                'INSERT INTO face_templates (employee_id, ciphertext, nonce, consented_at, revoked_at)
                 VALUES (:employee_id, :ciphertext, :nonce, NOW(), NULL)
                 ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext), nonce=VALUES(nonce),
                 consented_at=NOW(), revoked_at=NULL'
            );
            $statement->bindValue(':employee_id', $employeeId, PDO::PARAM_INT);
            $statement->bindValue(':ciphertext', $template['ciphertext'], PDO::PARAM_LOB);
            $statement->bindValue(':nonce', $template['nonce'], PDO::PARAM_LOB);
            $statement->execute();
            audit($pdo, (int) $user['id'], 'enroll_face', 'face_template', (string) $employeeId);
            echo json_encode(['ok' => true, 'message' => 'Tantangan keaktifan selesai. Wajah terdeteksi dan template tersimpan secara terenkripsi.']);
            exit;
        }
        if (($input['operation'] ?? '') === 'revoke') {
            $pdo->prepare('UPDATE face_templates SET ciphertext="", nonce="", revoked_at=NOW() WHERE employee_id=:id')
                ->execute(['id' => $employeeId]);
            audit($pdo, (int) $user['id'], 'revoke_face', 'face_template', (string) $employeeId);
            echo json_encode(['ok' => true, 'message' => 'Template biometrik dinonaktifkan.']);
            exit;
        }
        if ($operation === 'location_failure') {
            $reason = (string) ($input['reason'] ?? '');
            $allowedReasons = ['unsupported', 'permission_denied', 'position_unavailable', 'timeout'];
            audit($pdo, (int) $user['id'], 'location_failed', 'attendance', null, [
                'reason' => in_array($reason, $allowedReasons, true) ? $reason : 'unknown',
            ]);
            echo json_encode(['ok' => true]);
            exit;
        }
        if ($operation !== 'scan') {
            http_response_code(400);
            echo json_encode(['error' => 'Operasi tidak valid.']);
            exit;
        }
        $action = (string) ($input['action'] ?? '');
        if (!in_array($action, ['check_in', 'check_out'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Pilih absen masuk atau absen pulang.']);
            exit;
        }
        $earlyState = $pdo->prepare('SELECT id, check_in_at, check_out_at, TIMESTAMPDIFF(SECOND, check_in_at, NOW()) AS elapsed_s
            FROM attendance WHERE employee_id=:employee AND attendance_date=:date');
        $earlyState->execute(['employee' => $employeeId, 'date' => date('Y-m-d')]);
        $stateError = attendance_state_error($earlyState->fetch() ?: null, $action);
        if ($stateError !== null) {
            http_response_code(409);
            echo json_encode(['error' => $stateError]);
            exit;
        }
        if (!consume_face_liveness($input['liveness'] ?? [])) {
            http_response_code(422);
            echo json_encode(['error' => 'Pemeriksaan keaktifan gagal atau kedaluwarsa. Ulangi tantangan.']);
            exit;
        }
        if (!valid_descriptor($input['descriptor'] ?? null)) {
            http_response_code(422);
            echo json_encode(['error' => 'Data wajah tidak valid.']);
            exit;
        }

        $locationInput = $input['location'] ?? null;
        if (!is_array($locationInput)) {
            http_response_code(422);
            echo json_encode(['error' => 'Lokasi GPS wajib disertakan untuk absensi.']);
            exit;
        }
        $latitudeRaw = $locationInput['latitude'] ?? null;
        $longitudeRaw = $locationInput['longitude'] ?? null;
        $accuracyRaw = $locationInput['accuracy'] ?? null;
        $timestampRaw = $locationInput['timestamp'] ?? null;
        if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw) || !is_numeric($accuracyRaw)
            || !is_numeric($timestampRaw)) {
            http_response_code(422);
            echo json_encode(['error' => 'Koordinat dan waktu GPS tidak valid.']);
            exit;
        }
        $latitude = (float) $latitudeRaw;
        $longitude = (float) $longitudeRaw;
        $accuracy = (float) $accuracyRaw;
        $positionTimestamp = (float) $timestampRaw;
        if (!is_finite($latitude) || !is_finite($longitude) || !is_finite($accuracy)
            || !is_finite($positionTimestamp) || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180 || $accuracy <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Koordinat dan akurasi GPS berada di luar rentang yang valid.']);
            exit;
        }
        $nowMilliseconds = (int) floor(microtime(true) * 1000);
        if ($positionTimestamp < $nowMilliseconds - 120000 || $positionTimestamp > $nowMilliseconds + 30000) {
            http_response_code(422);
            echo json_encode(['error' => 'Lokasi GPS sudah kedaluwarsa. Aktifkan lokasi perangkat lalu coba lagi.']);
            exit;
        }
        $settings = $pdo->query('SELECT latitude,longitude,geofence_radius_m FROM school_settings WHERE id=1')->fetch();
        if (!$settings || $settings['latitude'] === null || $settings['longitude'] === null) {
            http_response_code(503);
            echo json_encode(['error' => 'Lokasi sekolah belum diatur. Hubungi Admin.']);
            exit;
        }
        $maxAccuracy = min(50, (int) $settings['geofence_radius_m']);
        if ($accuracy > $maxAccuracy) {
            audit($pdo, (int) $user['id'], 'location_rejected', 'attendance', null, [
                'reason' => 'accuracy_too_low',
                'accuracy_m' => round($accuracy, 1),
            ]);
            http_response_code(422);
            echo json_encode([
                'error' => 'Akurasi GPS harus ' . $maxAccuracy
                    . ' meter atau lebih baik. Pindah ke area terbuka lalu coba lagi.',
            ]);
            exit;
        }
        $distance = geodesic_distance_m(
            $latitude,
            $longitude,
            (float) $settings['latitude'],
            (float) $settings['longitude']
        );
        if ($distance > (int) $settings['geofence_radius_m']) {
            audit($pdo, (int) $user['id'], 'location_rejected', 'attendance', null, [
                'reason' => 'outside_geofence',
                'distance_m' => round($distance, 1),
                'accuracy_m' => round($accuracy, 1),
            ]);
            http_response_code(403);
            echo json_encode([
                'error' => 'Lokasi Anda berada di luar area absensi sekolah ('
                    . (int) $settings['geofence_radius_m'] . ' m). Absensi tidak dicatat.',
            ]);
            exit;
        }

        $statement = $pdo->prepare(
            'SELECT ciphertext, nonce FROM face_templates WHERE employee_id=:id AND revoked_at IS NULL'
        );
        $statement->execute(['id' => $employeeId]);
        $row = $statement->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Template wajah belum terdaftar atau telah dicabut.']);
            exit;
        }
        $stored = decrypt_template($row['ciphertext'], $row['nonce'], $config);
        $distance = descriptor_distance($input['descriptor'], $stored);
        if ($distance > 0.48) {
            http_response_code(403);
            echo json_encode(['error' => 'Wajah tidak cocok. Coba lagi atau gunakan prosedur absensi manual.']);
            exit;
        }
        $today = date('Y-m-d');
        $pdo->beginTransaction();
        $select = $pdo->prepare('SELECT id, check_in_at, check_out_at, TIMESTAMPDIFF(SECOND, check_in_at, NOW()) AS elapsed_s
            FROM attendance WHERE employee_id=:employee AND attendance_date=:date FOR UPDATE');
        $select->execute(['employee' => $employeeId, 'date' => $today]);
        $record = $select->fetch() ?: null;
        $stateError = attendance_state_error($record, $action);
        if ($stateError !== null) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => $stateError]);
            exit;
        }
        if ($action === 'check_in') {
            if ($record) {
                $attendanceId = (int) $record['id'];
                $pdo->prepare('UPDATE attendance SET check_in_at=NOW(), method="face", status="hadir" WHERE id=:id')
                    ->execute(['id' => $attendanceId]);
            } else {
                $pdo->prepare('INSERT INTO attendance (employee_id, attendance_date, check_in_at, method, status)
                    VALUES (:employee, :date, NOW(), "face", "hadir")')->execute(['employee' => $employeeId, 'date' => $today]);
                $attendanceId = (int) $pdo->lastInsertId();
            }
        } else {
            $attendanceId = (int) $record['id'];
            $pdo->prepare('UPDATE attendance SET check_out_at=NOW(), method="face" WHERE id=:id')
                ->execute(['id' => $attendanceId]);
        }
        $eventType = $action;
        $result = $action;
        $pdo->prepare(
            'INSERT INTO attendance_location_evidence
             (attendance_id,event_type,latitude,longitude,accuracy_m,distance_m,recorded_at)
             VALUES (:attendance_id,:event_type,:latitude,:longitude,:accuracy,:distance,NOW())
             ON DUPLICATE KEY UPDATE latitude=VALUES(latitude),longitude=VALUES(longitude),
             accuracy_m=VALUES(accuracy_m),distance_m=VALUES(distance_m),recorded_at=VALUES(recorded_at)'
        )->execute([
            'attendance_id' => $attendanceId,
            'event_type' => $eventType,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $accuracy,
            'distance' => $distance,
        ]);
        audit($pdo, (int) $user['id'], $result, 'attendance', (string) $attendanceId, [
            'distance' => round($distance, 4),
            'accuracy_m' => round($accuracy, 1),
        ]);
        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'result' => $result,
            'message' => $result === 'check_in' ? 'Absensi masuk berhasil dicatat.' : 'Absensi pulang berhasil dicatat.',
        ]);
        exit;
    } catch (JsonException | RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Pemrosesan biometrik gagal. Periksa konfigurasi dan coba lagi.']);
        exit;
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Catatan absensi tidak dapat disimpan.']);
        exit;
    }
}
