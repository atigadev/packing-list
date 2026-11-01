<?php
// Login & hak akses berbasis peran.

const SESSION_LIFETIME = 2592000; // 30 hari

function start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;
    $dir = data_dir() . '/sessions';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => $path,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('PLSESS');
    session_start();
}

function current_user(): ?array
{
    if (array_key_exists('CURRENT_USER', $GLOBALS)) return $GLOBALS['CURRENT_USER'];
    start_session();
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT u.id, u.username, u.name, u.role, u.branch_id, u.must_change, b.name AS branch_name, b.code AS branch_code, b.is_hq
                             FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.id = ? AND u.active = 1');
        $st->execute([$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
    }
    if ($user) {
        // Lokasi kerja: koordinator/admin bisa berpindah gudang; peran lain tetap di cabangnya
        $user['multi_branch'] = in_array($user['role'], MULTI_BRANCH_ROLES, true);
        $work = $user['multi_branch'] ? (int)($_SESSION['work_branch'] ?? 0) : 0;
        $wb = $work ? db()->query('SELECT id, name FROM branches WHERE active = 1 AND id = ' . $work)->fetch() : null;
        $user['work_branch_id'] = $wb ? (int)$wb['id'] : ($user['branch_id'] ? (int)$user['branch_id'] : null);
        $user['work_branch_name'] = $wb ? $wb['name'] : $user['branch_name'];
    }
    $GLOBALS['CURRENT_USER'] = $user;
    return $user;
}

function login_user(array $user): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    unset($GLOBALS['CURRENT_USER']);
    db()->prepare("UPDATE users SET last_login = datetime('now','localtime') WHERE id = ?")->execute([$user['id']]);
}

function logout_user(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
    $GLOBALS['CURRENT_USER'] = null;
}

function has_role(string ...$roles): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin' || in_array($u['role'], $roles, true)) return true;
    return $u['role'] === 'koordinator' && (bool)array_intersect($roles, KOORDINATOR_ACTS_AS);
}

function is_multi_branch(): bool
{
    $u = current_user();
    return $u && !empty($u['multi_branch']);
}

/** ID cabang pengguna, atau ID gudang pusat jika pengguna tidak terikat cabang. */
function user_branch_id(): int
{
    $u = current_user();
    if ($u && $u['work_branch_id']) return (int)$u['work_branch_id'];
    return hq_branch_id();
}

function hq_branch_id(): int
{
    static $id = null;
    if ($id === null) $id = (int)db()->query('SELECT id FROM branches WHERE is_hq = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 1;
    return $id;
}

/** Untuk halaman non-API (cetak, ekspor, foto): arahkan ke aplikasi jika belum login. */
function require_page_login(string ...$roles): array
{
    $u = current_user();
    if (!$u) {
        header('Location: index.html');
        exit;
    }
    if ($roles && !has_role(...$roles)) {
        http_response_code(403);
        exit('Anda tidak memiliki akses ke halaman ini.');
    }
    session_write_close();
    return $u;
}
