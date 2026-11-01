<?php
// Pengguna, cabang, pengaturan (khusus admin), log aktivitas.

switch ($action) {
    case 'users':
        need_role('admin');
        out(['users' => db()->query('SELECT u.id, u.username, u.name, u.role, u.branch_id, u.active, u.last_login, b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id = u.branch_id ORDER BY u.active DESC, u.name')->fetchAll()]);

    case 'user_save':
        need_role('admin');
        $pdo = db();
        $id = (int)param('id', 0);
        $username = strtolower(param('username'));
        $name = param('name');
        $role = param('role');
        $branch = (int)param('branch_id', 0) ?: null;
        $active = param('active', true) ? 1 : 0;
        $password = (string)param('password');
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) fail('Username 3-30 karakter: huruf kecil, angka, titik, minus, garis bawah.');
        if ($name === '') fail('Nama wajib diisi.');
        if (!isset(ROLES[$role])) fail('Peran tidak dikenal.');
        if ($role === 'cabang' && !$branch) fail('Pengguna cabang wajib memilih cabang.');
        $st = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
        $st->execute([$username, $id]);
        if ($st->fetchColumn()) fail("Username $username sudah dipakai.");

        if ($id) {
            // Jangan sampai tidak ada admin aktif
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?");
            $st->execute([$id]);
            if (($role !== 'admin' || !$active) && !(int)$st->fetchColumn()) fail('Harus ada minimal satu admin aktif.');
            $pdo->prepare('UPDATE users SET username=?, name=?, role=?, branch_id=?, active=? WHERE id=?')->execute([$username, $name, $role, $branch, $active, $id]);
            if ($password !== '') {
                if (strlen($password) < 6) fail('Password minimal 6 karakter.');
                $pdo->prepare('UPDATE users SET password_hash=?, must_change=1 WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            }
        } else {
            if (strlen($password) < 6) fail('Password minimal 6 karakter.');
            $pdo->prepare('INSERT INTO users (username, name, password_hash, role, branch_id, active, must_change) VALUES (?,?,?,?,?,?,1)')
                ->execute([$username, $name, password_hash($password, PASSWORD_DEFAULT), $role, $branch, $active]);
        }
        log_activity('user', null, null, "Pengguna $username disimpan");
        out(['ok' => true]);

    case 'branches':
        out(['branches' => db()->query('SELECT * FROM branches ORDER BY is_hq DESC, active DESC, name')->fetchAll()]);

    case 'branch_save':
        need_role('admin');
        $pdo = db();
        $id = (int)param('id', 0);
        $code = strtoupper(param('code'));
        $name = param('name');
        if (!preg_match('/^[A-Z0-9_-]{2,15}$/', $code)) fail('Kode cabang 2-15 karakter (huruf/angka).');
        if ($name === '') fail('Nama cabang wajib diisi.');
        $st = $pdo->prepare('SELECT id FROM branches WHERE code = ? AND id <> ?');
        $st->execute([$code, $id]);
        if ($st->fetchColumn()) fail("Kode cabang $code sudah dipakai.");
        $vals = [$code, $name, param('address'), param('pic_name'), param('pic_phone'), param('active', true) ? 1 : 0];
        if ($id) {
            $pdo->prepare('UPDATE branches SET code=?, name=?, address=?, pic_name=?, pic_phone=?, active=? WHERE id=?')->execute(array_merge($vals, [$id]));
        } else {
            $pdo->prepare('INSERT INTO branches (code, name, address, pic_name, pic_phone, active) VALUES (?,?,?,?,?,?)')->execute($vals);
        }
        log_activity('branch', null, null, "Cabang $code disimpan");
        out(['ok' => true]);

    case 'settings_save':
        need_role('admin');
        $up = db()->prepare('INSERT INTO settings (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $up->execute(['company_name', param('company_name')]);
        $up->execute(['company_address', param('company_address')]);
        $up->execute(['vol_divisor', (string)max(1000, (int)param('vol_divisor', 6000))]);
        $up->execute(['backup_keep', (string)max(1, min(365, (int)param('backup_keep', 14)))]);
        out(['ok' => true]);

    case 'backups':
        need_role('admin');
        $files = [];
        foreach (glob(data_dir() . '/backups/*.sqlite') as $f) $files[] = ['name' => basename($f), 'size' => filesize($f), 'time' => date('Y-m-d H:i', filemtime($f))];
        usort($files, function ($a, $b) { return strcmp($b['time'], $a['time']); });
        out(['backups' => $files]);

    case 'activity':
        $sql = 'SELECT a.*, u.name AS user_name FROM activity a LEFT JOIN users u ON u.id = a.user_id';
        $args = [];
        if ($code = param('code')) {
            $sql .= ' WHERE a.package = ? OR a.item = ? OR a.shipment = ?';
            $args = [$code, $code, $code];
        }
        $st = db()->prepare($sql . ' ORDER BY a.id DESC LIMIT 200');
        $st->execute($args);
        out(['activity' => $st->fetchAll()]);
}
