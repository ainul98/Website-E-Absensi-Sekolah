<?php
declare(strict_types=1);

function api_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function api_body(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if (!is_string($raw) || strlen($raw) > 1048576) {
        api_response(['error' => 'Ukuran permintaan tidak valid.'], 413);
    }
    if ($raw === '') {
        return [];
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        api_response(['error' => 'Format JSON tidak valid.'], 400);
    }
    return $body;
}

function api_require_method(array $methods): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        api_response(['error' => 'Metode HTTP tidak diizinkan.'], 405);
    }
    return $method;
}

function api_check_csrf(array $body): void
{
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $body['csrf_token'] ?? '');
    if (!hash_equals(csrf_token(), $token)) {
        api_response(['error' => 'Sesi kedaluwarsa. Muat ulang halaman.'], 419);
    }
}

function api_required_string(array $data, string $key, int $maxLength): string
{
    $value = trim((string) ($data[$key] ?? ''));
    if ($value === '' || mb_strlen($value) > $maxLength) {
        api_response(['error' => "Kolom $key wajib diisi dan maksimal $maxLength karakter."], 422);
    }
    return $value;
}

function api_positive_id(mixed $value, string $label = 'ID'): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        api_response(['error' => "$label tidak valid."], 422);
    }
    return $id;
}

function api_require_permission(PDO $pdo, string $permission): array
{
    $user = current_user();
    if (!$user) {
        api_response(['error' => 'Silakan masuk terlebih dahulu.'], 401);
    }
    if (!has_permission($pdo, $user, $permission)) {
        api_response(['error' => 'Anda tidak memiliki izin untuk operasi ini.'], 403);
    }
    return $user;
}

function api_require_admin(PDO $pdo, string $permission): array
{
    $actor = api_require_permission($pdo, $permission);
    if ($actor['role'] !== 'admin') {
        api_response(['error' => 'Operasi ini hanya dapat dilakukan Admin.'], 403);
    }
    return $actor;
}

function api_user_save(PDO $pdo, array $body, ?int $id, array $actor): array
{
    $isNew = $id === null;
    $name = api_required_string($body, 'name', 160);
    $email = mb_strtolower(api_required_string($body, 'email', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_response(['error' => 'Format email tidak valid.'], 422);
    }
    $role = api_required_string($body, 'role', 32);
    $roleQuery = $pdo->prepare('SELECT id FROM roles WHERE name=:name');
    $roleQuery->execute(['name' => $role]);
    $roleId = $roleQuery->fetchColumn();
    if (!$roleId) {
        api_response(['error' => 'Role tidak ditemukan.'], 422);
    }
    $employeeId = empty($body['employee_id']) ? null : api_positive_id($body['employee_id'], 'ID pegawai');
    if ($role !== 'admin' && !$employeeId) {
        api_response(['error' => 'Akun Tendik, Pendidik, dan Kepala Sekolah harus terhubung ke data pegawai.'], 422);
    }
    if ($employeeId) {
        $employeeQuery = $pdo->prepare('SELECT is_active FROM employees WHERE id=:id');
        $employeeQuery->execute(['id' => $employeeId]);
        if (!(int) $employeeQuery->fetchColumn()) {
            api_response(['error' => 'Pilih data pegawai aktif yang valid.'], 422);
        }
    }
    $active = !array_key_exists('is_active', $body) || filter_var($body['is_active'], FILTER_VALIDATE_BOOL);
    $password = (string) ($body['password'] ?? '');

    if ($id && (int) $actor['id'] === $id && $role !== $actor['role']) {
        api_response(['error' => 'Role akun yang sedang digunakan tidak dapat diubah.'], 422);
    }
    if ($id && (int) $actor['id'] === $id && !$active) {
        api_response(['error' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan.'], 422);
    }
    if ($id) {
        $existingRole = $pdo->prepare('SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id');
        $existingRole->execute(['id' => $id]);
        $existingName = $existingRole->fetchColumn();
        if ($existingName === false) {
            api_response(['error' => 'Akun pengguna tidak ditemukan.'], 404);
        }
        if ($existingName === 'admin' && (!$active || $role !== 'admin')) {
            $otherAdmins = $pdo->prepare(
                'SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id
                 WHERE r.name="admin" AND u.is_active=1 AND u.id<>:id'
            );
            $otherAdmins->execute(['id' => $id]);
            if ((int) $otherAdmins->fetchColumn() === 0) {
                api_response(['error' => 'Admin aktif terakhir tidak dapat dinonaktifkan atau diturunkan rolenya.'], 422);
            }
        }
    }
    if (!$id && strlen($password) < 12) {
        api_response(['error' => 'Kata sandi akun baru minimal 12 karakter.'], 422);
    }
    if ($id && $password !== '' && strlen($password) < 12) {
        api_response(['error' => 'Kata sandi minimal 12 karakter.'], 422);
    }

    $pdo->beginTransaction();
    if ($id) {
        $params = [
            'employee_id' => $employeeId,
            'role_id' => $roleId,
            'name' => $name,
            'email' => $email,
            'is_active' => $active ? 1 : 0,
            'id' => $id,
        ];
        $sql = 'UPDATE users SET employee_id=:employee_id, role_id=:role_id, name=:name,
                email=:email, is_active=:is_active';
        if ($password !== '') {
            $sql .= ', password_hash=:password_hash';
            $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id=:id';
        $pdo->prepare($sql)->execute($params);
    } else {
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
            'is_active' => $active ? 1 : 0,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    if (!$active && $employeeId) {
        $pdo->prepare('UPDATE face_templates SET ciphertext="", nonce="", revoked_at=NOW() WHERE employee_id=:id')
            ->execute(['id' => $employeeId]);
    }
    audit($pdo, (int) $actor['id'], $isNew ? 'api_create' : 'api_save', 'user', (string) $id);
    $pdo->commit();
    return ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role, 'is_active' => $active];
}

function api_employee_save(PDO $pdo, array $body, ?int $id, array $actor): array
{
    $nip = api_required_string($body, 'nip', 32);
    $name = api_required_string($body, 'full_name', 160);
    $email = mb_strtolower(api_required_string($body, 'email', 190));
    $position = api_required_string($body, 'position', 120);
    $type = (string) ($body['employment_type'] ?? 'PNS');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($type, ['PNS', 'PPPK', 'Honorer', 'Lainnya'], true)) {
        api_response(['error' => 'Email atau jenis kepegawaian tidak valid.'], 422);
    }
    $active = !array_key_exists('is_active', $body) || filter_var($body['is_active'], FILTER_VALIDATE_BOOL);
    $params = [
        'nip' => $nip,
        'full_name' => $name,
        'email' => $email,
        'phone' => trim((string) ($body['phone'] ?? '')) ?: null,
        'position' => $position,
        'employment_type' => $type,
        'is_active' => $active ? 1 : 0,
    ];
    $pdo->beginTransaction();
    if ($id) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE id=:id');
        $exists->execute(['id' => $id]);
        if (!(int) $exists->fetchColumn()) {
            api_response(['error' => 'Data pegawai tidak ditemukan.'], 404);
        }
        $params['id'] = $id;
        $pdo->prepare(
            'UPDATE employees SET nip=:nip, full_name=:full_name, email=:email, phone=:phone,
             position=:position, employment_type=:employment_type, is_active=:is_active WHERE id=:id'
        )->execute($params);
    } else {
        $pdo->prepare(
            'INSERT INTO employees (nip, full_name, email, phone, position, employment_type, is_active)
             VALUES (:nip, :full_name, :email, :phone, :position, :employment_type, :is_active)'
        )->execute($params);
        $id = (int) $pdo->lastInsertId();
    }
    if (!$active) {
        $pdo->prepare('UPDATE users SET is_active=0 WHERE employee_id=:id')->execute(['id' => $id]);
        $pdo->prepare('UPDATE face_templates SET ciphertext="", nonce="", revoked_at=NOW() WHERE employee_id=:id')
            ->execute(['id' => $id]);
    }
    audit($pdo, (int) $actor['id'], 'api_save', 'employee', (string) $id);
    $pdo->commit();
    return ['id' => $id, 'nip' => $nip, 'full_name' => $name, 'email' => $email, 'position' => $position, 'is_active' => $active];
}

function api_event_save(PDO $pdo, array $body, ?int $id, array $actor): array
{
    $title = api_required_string($body, 'title', 180);
    $type = (string) ($body['event_type'] ?? 'Kegiatan Sekolah');
    $start = (string) ($body['start_date'] ?? '');
    $end = (string) ($body['end_date'] ?? '');
    if (!in_array($type, ['Hari Efektif', 'Libur', 'Kegiatan Sekolah', 'Rapat', 'Lainnya'], true)
        || !valid_date($start) || !valid_date($end) || $end < $start) {
        api_response(['error' => 'Jenis kegiatan atau rentang tanggal tidak valid.'], 422);
    }
    $params = [
        'title' => $title,
        'event_type' => $type,
        'start_date' => $start,
        'end_date' => $end,
        'description' => trim((string) ($body['description'] ?? '')) ?: null,
    ];
    $pdo->beginTransaction();
    if ($id) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM education_events WHERE id=:id');
        $exists->execute(['id' => $id]);
        if (!(int) $exists->fetchColumn()) {
            api_response(['error' => 'Agenda kalender tidak ditemukan.'], 404);
        }
        $params['id'] = $id;
        $pdo->prepare(
            'UPDATE education_events SET title=:title, event_type=:event_type, start_date=:start_date,
             end_date=:end_date, description=:description WHERE id=:id'
        )->execute($params);
    } else {
        $params['created_by'] = $actor['id'];
        $pdo->prepare(
            'INSERT INTO education_events (title,event_type,start_date,end_date,description,created_by)
             VALUES (:title,:event_type,:start_date,:end_date,:description,:created_by)'
        )->execute($params);
        $id = (int) $pdo->lastInsertId();
    }
    audit($pdo, (int) $actor['id'], 'api_save', 'education_event', (string) $id);
    $pdo->commit();
    return ['id' => $id, 'title' => $title, 'event_type' => $type, 'start_date' => $start, 'end_date' => $end];
}

function api_attendance_save(PDO $pdo, array $body, array $actor, ?int $id): array
{
    if ($actor['role'] !== 'admin') {
        api_response(['error' => 'Override absensi manual hanya dapat dilakukan Admin.'], 403);
    }
    $employeeId = api_positive_id($body['employee_id'] ?? null, 'ID pegawai');
    $date = (string) ($body['attendance_date'] ?? '');
    $status = (string) ($body['status'] ?? 'hadir');
    $checkIn = (string) ($body['check_in_at'] ?? '');
    $checkOut = (string) ($body['check_out_at'] ?? '');
    if (!valid_date($date) || $date > date('Y-m-d')
        || !in_array($status, ['hadir', 'izin', 'sakit', 'dinas_luar', 'alpa'], true)) {
        api_response(['error' => 'Tanggal atau status absensi tidak valid.'], 422);
    }
    foreach ([$checkIn, $checkOut] as $time) {
        if ($time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            api_response(['error' => 'Format jam harus HH:MM.'], 422);
        }
    }
    if ($checkIn !== '' && $checkOut !== '' && $checkOut <= $checkIn) {
        api_response(['error' => 'Jam pulang harus setelah jam masuk.'], 422);
    }
    $reason = api_required_string($body, 'note', 500);
    $pdo->beginTransaction();
    $values = [
        'employee_id' => $employeeId,
        'attendance_date' => $date,
        'check_in_at' => $checkIn === '' ? null : "$date $checkIn:00",
        'check_out_at' => $checkOut === '' ? null : "$date $checkOut:00",
        'status' => $status,
        'note' => $reason,
        'recorded_by' => $actor['id'],
    ];
    if ($id) {
        $existing = $pdo->prepare('SELECT id FROM attendance WHERE id=:id FOR UPDATE');
        $existing->execute(['id' => $id]);
        if (!$existing->fetchColumn()) {
            $pdo->rollBack();
            api_response(['error' => 'Catatan absensi yang akan dikoreksi tidak ditemukan.'], 404);
        }
        $values['id'] = $id;
        $pdo->prepare(
            'UPDATE attendance SET employee_id=:employee_id,attendance_date=:attendance_date,
             check_in_at=:check_in_at,check_out_at=:check_out_at,status=:status,note=:note,
             method="manual",recorded_by=:recorded_by WHERE id=:id'
        )->execute($values);
        $attendanceId = $id;
    } else {
        $pdo->prepare(
            'INSERT INTO attendance (employee_id,attendance_date,check_in_at,check_out_at,status,note,method,recorded_by)
             VALUES (:employee_id,:attendance_date,:check_in_at,:check_out_at,:status,:note,"manual",:recorded_by)
             ON DUPLICATE KEY UPDATE check_in_at=VALUES(check_in_at),check_out_at=VALUES(check_out_at),
             status=VALUES(status),note=VALUES(note),method="manual",recorded_by=VALUES(recorded_by)'
        )->execute($values);
        $lookup = $pdo->prepare('SELECT id FROM attendance WHERE employee_id=:employee_id AND attendance_date=:date');
        $lookup->execute(['employee_id' => $employeeId, 'date' => $date]);
        $attendanceId = (int) $lookup->fetchColumn();
    }
    $pdo->prepare('DELETE FROM attendance_location_evidence WHERE attendance_id=:id')->execute(['id' => $attendanceId]);
    audit($pdo, (int) $actor['id'], 'api_manual_entry', 'attendance', (string) $attendanceId, [
        'employee_id' => $employeeId,
        'date' => $date,
        'reason' => $reason,
    ]);
    $pdo->commit();
    return ['employee_id' => $employeeId, 'attendance_date' => $date, 'status' => $status];
}

function api_handle(PDO $pdo): never
{
    $resource = (string) ($_GET['resource'] ?? '');
    $method = api_require_method(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
    $body = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? api_body() : [];
    if ($method !== 'GET') {
        api_check_csrf($body);
    }
    $user = current_user();
    if (!$user) {
        api_response(['error' => 'Silakan masuk terlebih dahulu.'], 401);
    }
    $id = isset($_GET['id']) ? api_positive_id($_GET['id']) : null;

    try {
        switch ($resource) {
            case 'geofence':
                $actor = api_require_admin($pdo, 'location.manage');
                if ($method === 'GET') {
                    $settings = $pdo->query(
                        'SELECT latitude,longitude,geofence_radius_m,updated_at FROM school_settings WHERE id=1'
                    )->fetch();
                    if (!$settings) {
                        api_response(['error' => 'Pengaturan lokasi sekolah belum tersedia.'], 503);
                    }
                    api_response(['data' => $settings]);
                }
                if ($method !== 'PUT') {
                    header('Allow: GET, PUT');
                    api_response(['error' => 'Geofence hanya mendukung GET dan PUT.'], 405);
                }
                $latitudeRaw = $body['latitude'] ?? null;
                $longitudeRaw = $body['longitude'] ?? null;
                $radius = filter_var($body['geofence_radius_m'] ?? null, FILTER_VALIDATE_INT);
                if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)
                    || !is_finite((float) $latitudeRaw) || !is_finite((float) $longitudeRaw)
                    || (float) $latitudeRaw < -90 || (float) $latitudeRaw > 90
                    || (float) $longitudeRaw < -180 || (float) $longitudeRaw > 180) {
                    api_response(['error' => 'Koordinat sekolah tidak valid.'], 422);
                }
                if ($radius === false || $radius < 10 || $radius > 5000) {
                    api_response(['error' => 'Radius geofence harus antara 10 dan 5000 meter.'], 422);
                }
                $pdo->beginTransaction();
                $pdo->prepare(
                    'UPDATE school_settings SET latitude=:latitude,longitude=:longitude,
                     geofence_radius_m=:radius,updated_by=:updated_by WHERE id=1'
                )->execute([
                    'latitude' => (float) $latitudeRaw,
                    'longitude' => (float) $longitudeRaw,
                    'radius' => $radius,
                    'updated_by' => $actor['id'],
                ]);
                audit($pdo, (int) $actor['id'], 'api_update_geofence', 'school_settings', '1', ['radius_m' => $radius]);
                $pdo->commit();
                api_response(['message' => 'Geofence sekolah berhasil diperbarui.']);

            case 'users':
                $actor = api_require_permission($pdo, 'users.manage');
                if ($method === 'GET') {
                    $sql = 'SELECT u.id,u.employee_id,u.name,u.email,u.is_active,u.last_login_at,r.name AS role,e.nip
                            FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN employees e ON e.id=u.employee_id';
                    if ($id) {
                        $statement = $pdo->prepare($sql . ' WHERE u.id=:id');
                        $statement->execute(['id' => $id]);
                        $rows = $statement->fetchAll();
                    } else {
                        $rows = $pdo->query($sql . ' ORDER BY u.name')->fetchAll();
                    }
                    if ($id && !$rows) {
                        api_response(['error' => 'Akun pengguna tidak ditemukan.'], 404);
                    }
                    api_response(['data' => $rows]);
                }
                if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
                    if (in_array($method, ['PUT', 'PATCH'], true) && !$id) {
                        api_response(['error' => 'ID akun wajib diisi untuk pembaruan.'], 422);
                    }
                    api_response(['data' => api_user_save($pdo, $body, $id, $actor)], $method === 'POST' ? 201 : 200);
                }
                if (!$id || $id === (int) $actor['id']) {
                    api_response(['error' => 'ID akun tidak valid atau Anda tidak dapat menonaktifkan akun sendiri.'], 422);
                }
                $statement = $pdo->prepare('SELECT employee_id FROM users WHERE id=:id');
                $statement->execute(['id' => $id]);
                $account = $statement->fetch();
                if (!$account) {
                    api_response(['error' => 'Akun pengguna tidak ditemukan.'], 404);
                }
                $statement = $pdo->prepare('SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id');
                $statement->execute(['id' => $id]);
                if ($statement->fetchColumn() === 'admin') {
                    $otherAdmins = $pdo->prepare(
                        'SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id
                         WHERE r.name="admin" AND u.is_active=1 AND u.id<>:id'
                    );
                    $otherAdmins->execute(['id' => $id]);
                    if ((int) $otherAdmins->fetchColumn() === 0) {
                        api_response(['error' => 'Admin aktif terakhir tidak dapat dinonaktifkan.'], 422);
                    }
                }
                $employeeId = $account['employee_id'];
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET is_active=0 WHERE id=:id')->execute(['id' => $id]);
                if ($employeeId) {
                    $pdo->prepare('UPDATE face_templates SET ciphertext="", nonce="", revoked_at=NOW() WHERE employee_id=:id')
                        ->execute(['id' => $employeeId]);
                }
                audit($pdo, (int) $actor['id'], 'api_deactivate', 'user', (string) $id);
                $pdo->commit();
                api_response(['message' => 'Akun dinonaktifkan.']);

            case 'roles':
                api_require_permission($pdo, 'roles.manage');
                if ($method !== 'GET') {
                    api_response(['error' => 'Role inti hanya dapat dikelola melalui penetapan akun dan izin akses.'], 405);
                }
                api_response(['data' => $pdo->query(
                    'SELECT r.id,r.name,r.label,COUNT(DISTINCT u.id) AS active_users
                     FROM roles r LEFT JOIN users u ON u.role_id=r.id AND u.is_active=1
                     GROUP BY r.id ORDER BY FIELD(r.name,"admin","tendik","pendidik","principal")'
                )->fetchAll()]);

            case 'access':
                $actor = api_require_permission($pdo, 'access.manage');
                if ($method === 'GET') {
                    $roleId = api_positive_id($_GET['role_id'] ?? null, 'ID role');
                    $query = $pdo->prepare(
                        'SELECT r.name AS role,p.id AS permission_id,p.name,p.label,p.group_name,
                                CASE WHEN r.name<>"admin" AND p.name IN ("attendance.manual","location.manage")
                                     THEN 0 ELSE COALESCE(rp.is_allowed,0) END AS is_allowed
                         FROM permissions p CROSS JOIN roles r
                         LEFT JOIN role_permissions rp ON rp.permission_id=p.id AND rp.role_id=r.id
                         WHERE r.id=:role_id ORDER BY p.group_name,p.id'
                    );
                    $query->execute(['role_id' => $roleId]);
                    api_response(['data' => $query->fetchAll()]);
                }
                $roleId = api_positive_id($body['role_id'] ?? null, 'ID role');
                $roleQuery = $pdo->prepare('SELECT name FROM roles WHERE id=:id');
                $roleQuery->execute(['id' => $roleId]);
                $roleName = $roleQuery->fetchColumn();
                if (!$roleName || $roleName === 'admin' || !is_array($body['permissions'] ?? null)) {
                    api_response(['error' => 'Role atau daftar izin tidak valid. Akses admin tidak dapat diubah.'], 422);
                }
                $permissions = $pdo->query('SELECT id,name FROM permissions')->fetchAll();
                $selected = array_map('strval', $body['permissions']);
                $pdo->beginTransaction();
                $update = $pdo->prepare('UPDATE role_permissions SET is_allowed=:allowed WHERE role_id=:role_id AND permission_id=:permission_id');
                foreach ($permissions as $permission) {
                    $restrictedToAdmin = in_array($permission['name'], ['attendance.manual', 'location.manage'], true);
                    $update->execute([
                        'allowed' => in_array($permission['name'], $selected, true)
                            && !($restrictedToAdmin && $roleName !== 'admin') ? 1 : 0,
                        'role_id' => $roleId,
                        'permission_id' => $permission['id'],
                    ]);
                }
                audit($pdo, (int) $actor['id'], 'api_update_permissions', 'role', (string) $roleId);
                $pdo->commit();
                api_response(['message' => 'Izin role diperbarui.']);

            case 'employees':
                $actor = api_require_permission($pdo, 'employees.manage');
                if ($method === 'GET') {
                    if ($id) {
                        $statement = $pdo->prepare('SELECT * FROM employees WHERE id=:id');
                        $statement->execute(['id' => $id]);
                        $rows = $statement->fetchAll();
                        if (!$rows) {
                            api_response(['error' => 'Data pegawai tidak ditemukan.'], 404);
                        }
                        api_response(['data' => $rows]);
                    }
                    api_response(['data' => $pdo->query('SELECT * FROM employees ORDER BY full_name')->fetchAll()]);
                }
                if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
                    if (in_array($method, ['PUT', 'PATCH'], true) && !$id) {
                        api_response(['error' => 'ID pegawai wajib diisi untuk pembaruan.'], 422);
                    }
                    api_response(['data' => api_employee_save($pdo, $body, $id, $actor)], $method === 'POST' ? 201 : 200);
                }
                if (!$id) {
                    api_response(['error' => 'ID pegawai wajib diisi.'], 422);
                }
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE employees SET is_active=0 WHERE id=:id')->execute(['id' => $id]);
                $pdo->prepare('UPDATE users SET is_active=0 WHERE employee_id=:id')->execute(['id' => $id]);
                $pdo->prepare('UPDATE face_templates SET ciphertext="",nonce="",revoked_at=NOW() WHERE employee_id=:id')->execute(['id' => $id]);
                audit($pdo, (int) $actor['id'], 'api_deactivate', 'employee', (string) $id);
                $pdo->commit();
                api_response(['message' => 'Pegawai dinonaktifkan.']);

            case 'calendar':
                $permission = $method === 'GET' ? 'calendar.view' : 'calendar.manage';
                $actor = api_require_permission($pdo, $permission);
                if ($method === 'GET') {
                    $statement = $pdo->prepare(
                        'SELECT * FROM education_events WHERE end_date>=:from_date AND start_date<=:to_date ORDER BY start_date LIMIT 500'
                    );
                    $from = (string) ($_GET['from'] ?? date('Y-m-01'));
                    $to = (string) ($_GET['to'] ?? date('Y-m-t'));
                    if (!valid_date($from) || !valid_date($to) || $from > $to) {
                        api_response(['error' => 'Rentang tanggal tidak valid.'], 422);
                    }
                    $statement->execute(['from_date' => $from, 'to_date' => $to]);
                    $events = $statement->fetchAll();
                    if ($id) {
                        $events = array_values(array_filter($events, static fn(array $event): bool => (int) $event['id'] === $id));
                        if (!$events) {
                            api_response(['error' => 'Agenda kalender tidak ditemukan pada rentang tanggal ini.'], 404);
                        }
                    }
                    api_response(['data' => $events]);
                }
                if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                    if (in_array($method, ['PUT', 'PATCH'], true) && !$id) {
                        api_response(['error' => 'ID agenda wajib diisi untuk pembaruan.'], 422);
                    }
                    api_response(['data' => api_event_save($pdo, $body, $id, $actor)], $method === 'POST' ? 201 : 200);
                }
                if (!$id) {
                    api_response(['error' => 'ID agenda wajib diisi.'], 422);
                }
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM education_events WHERE id=:id')->execute(['id' => $id]);
                audit($pdo, (int) $actor['id'], 'api_delete', 'education_event', (string) $id);
                $pdo->commit();
                api_response(['message' => 'Agenda dihapus.']);

            case 'attendance':
                if ($method === 'GET') {
                    $canViewAll = has_permission($pdo, $user, 'attendance.view_all');
                    if (!$canViewAll && !has_permission($pdo, $user, 'attendance.self')) {
                        api_response(['error' => 'Anda tidak memiliki izin melihat absensi.'], 403);
                    }
                    $from = (string) ($_GET['from'] ?? date('Y-m-01'));
                    $to = (string) ($_GET['to'] ?? date('Y-m-d'));
                    if (!valid_date($from) || !valid_date($to) || $from > $to) {
                        api_response(['error' => 'Rentang tanggal tidak valid.'], 422);
                    }
                    $sql = 'SELECT a.*,e.full_name,e.nip,e.position';
                    if ($user['role'] === 'admin') {
                        $sql .= ',loc.check_in_latitude,loc.check_in_longitude,loc.check_in_accuracy_m,loc.check_in_distance_m,
                                  loc.check_out_latitude,loc.check_out_longitude,loc.check_out_accuracy_m,loc.check_out_distance_m';
                    }
                    $sql .= ' FROM attendance a JOIN employees e ON e.id=a.employee_id';
                    if ($user['role'] === 'admin') {
                        $sql .= attendance_location_join_sql();
                    }
                    $sql .= ' WHERE a.attendance_date BETWEEN :from_date AND :to_date';
                    $params = ['from_date' => $from, 'to_date' => $to];
                    if (!$canViewAll) {
                        if (!$user['employee_id']) {
                            api_response(['data' => []]);
                        }
                        $sql .= ' AND a.employee_id=:employee_id';
                        $params['employee_id'] = $user['employee_id'];
                    }
                    if ($id) {
                        $sql .= ' AND a.id=:id';
                        $params['id'] = $id;
                    }
                    $sql .= ' ORDER BY a.attendance_date DESC,e.full_name LIMIT 1000';
                    $statement = $pdo->prepare($sql);
                    $statement->execute($params);
                    $rows = $statement->fetchAll();
                    if ($id && !$rows) {
                        api_response(['error' => 'Catatan absensi tidak ditemukan.'], 404);
                    }
                    api_response(['data' => $rows]);
                }
                if ($user['role'] !== 'admin') {
                    api_response(['error' => 'Override absensi manual hanya dapat dilakukan Admin.'], 403);
                }
                $actor = api_require_permission($pdo, 'attendance.manual');
                if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                    if (in_array($method, ['PUT', 'PATCH'], true) && !$id) {
                        api_response(['error' => 'ID catatan absensi wajib diisi untuk pembaruan.'], 422);
                    }
                    api_response(['data' => api_attendance_save($pdo, $body, $actor, $method === 'POST' ? null : $id)], $method === 'POST' ? 201 : 200);
                }
                if (!$id) {
                    api_response(['error' => 'ID catatan absensi wajib diisi.'], 422);
                }
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM attendance WHERE id=:id')->execute(['id' => $id]);
                audit($pdo, (int) $actor['id'], 'api_delete', 'attendance', (string) $id);
                $pdo->commit();
                api_response(['message' => 'Catatan absensi dihapus.']);

            case 'reports':
                $reportUser = api_require_permission($pdo, 'reports.view');
                if ($method !== 'GET') {
                    api_response(['error' => 'Laporan hanya mendukung GET.'], 405);
                }
                $from = (string) ($_GET['from'] ?? date('Y-m-01'));
                $to = (string) ($_GET['to'] ?? date('Y-m-d'));
                if (!valid_date($from) || !valid_date($to) || $from > $to) {
                    api_response(['error' => 'Rentang tanggal tidak valid.'], 422);
                }
                $sql = 'SELECT e.nip,e.full_name,e.position,a.attendance_date,a.check_in_at,a.check_out_at,a.status,a.method,a.note';
                if ($reportUser['role'] === 'admin') {
                    $sql .= ',loc.check_in_latitude,loc.check_in_longitude,loc.check_in_accuracy_m,loc.check_in_distance_m,
                              loc.check_out_latitude,loc.check_out_longitude,loc.check_out_accuracy_m,loc.check_out_distance_m';
                }
                $sql .= ' FROM attendance a JOIN employees e ON e.id=a.employee_id';
                if ($reportUser['role'] === 'admin') {
                    $sql .= attendance_location_join_sql();
                }
                $sql .= ' WHERE a.attendance_date BETWEEN :from_date AND :to_date
                          ORDER BY a.attendance_date DESC,e.full_name LIMIT 5000';
                $statement = $pdo->prepare($sql);
                $statement->execute(['from_date' => $from, 'to_date' => $to]);
                api_response(['data' => $statement->fetchAll(), 'from' => $from, 'to' => $to]);

            default:
                api_response(['error' => 'Resource API tidak ditemukan.'], 404);
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        api_response([
            'error' => $exception->getCode() === '23000'
                ? 'Data bertabrakan dengan data yang sudah ada atau masih digunakan.'
                : 'Operasi database tidak dapat diselesaikan.',
        ], $exception->getCode() === '23000' ? 409 : 500);
    }
}
