<?php
// Login, logout, sesi pengguna.

function boot_payload(): array
{
    $u = current_user();
    $branches = db()->query('SELECT id, code, name, address, pic_name, pic_phone, is_hq, active FROM branches ORDER BY is_hq DESC, name')->fetchAll();
    $s = settings_all();
    return [
        'user' => $u,
        'settings' => ['company_name' => $s['company_name'] ?? '', 'company_address' => $s['company_address'] ?? '', 'vol_divisor' => (float)($s['vol_divisor'] ?? 6000), 'backup_keep' => (int)($s['backup_keep'] ?? 14)],
        'branches' => $branches,
        'types' => PACKAGE_TYPES,
        'roles' => ROLES,
        'stages' => PACKAGE_STAGES,
        'shipment_status' => SHIPMENT_STATUS,
        'item_conditions' => ITEM_CONDITIONS,
        'item_status' => ITEM_STATUS,
        'receipt_conditions' => RECEIPT_CONDITIONS,
        'handling' => HANDLING,
        'receive_modes' => RECEIVE_MODES,
        'categories' => db()->query('SELECT id, code, name, active FROM categories ORDER BY name')->fetchAll(),
        'units' => UNITS,
        'resolutions' => array_map(function ($r) { return $r['label']; }, RESOLUTIONS),
    ];
}

switch ($action) {
    case 'me':
        if (!current_user()) fail('Silakan login terlebih dahulu.', 401, ['auth' => true]);
        session_write_close();
        out(boot_payload());

    case 'login':
        $st = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
        $st->execute([param('username')]);
        $user = $st->fetch();
        if (!$user || !password_verify((string)param('password'), $user['password_hash'])) {
            sleep(1); // memperlambat tebakan password
            fail('Username atau password salah.', 401);
        }
        login_user($user);
        $GLOBALS['CURRENT_USER'] = null;
        unset($GLOBALS['CURRENT_USER']);
        current_user();
        log_activity('login', null, null, "{$user['name']} login");
        out(boot_payload());

    case 'logout':
        logout_user();
        out(['ok' => true]);

    case 'set_work_branch':
        if (!is_multi_branch()) fail('Peran Anda terikat pada satu cabang.', 403);
        $id = (int)param('branch_id');
        $st = db()->prepare('SELECT name FROM branches WHERE id = ? AND active = 1');
        $st->execute([$id]);
        $name = $st->fetchColumn();
        if (!$name) fail('Cabang / gudang tidak ditemukan.');
        $_SESSION['work_branch'] = $id;
        session_write_close();
        unset($GLOBALS['CURRENT_USER']);
        log_activity('work_branch', null, null, current_user()['name'] . ' pindah lokasi kerja ke ' . $name);
        out(boot_payload());

    case 'change_password':
        $u = current_user();
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        if (!password_verify((string)param('old_password'), $st->fetchColumn())) fail('Password lama salah.');
        $new = (string)param('new_password');
        if (strlen($new) < 6) fail('Password baru minimal 6 karakter.');
        db()->prepare('UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        out(['ok' => true]);
}
