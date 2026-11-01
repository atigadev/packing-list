<?php
// JSON API untuk aplikasi Packing List. Handler per modul ada di lib/api/*.php.
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/xlsx.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 400, array $extra = []): void
{
    if (db()->inTransaction()) db()->rollBack();
    out(array_merge(['error' => $message], $extra), $status);
}

function input(): array
{
    static $data = null;
    if ($data === null) {
        $json = json_decode(file_get_contents('php://input'), true);
        $data = is_array($json) ? $json : $_POST;
    }
    return $data;
}

function param(string $key, $default = '')
{
    $in = input();
    $v = $in[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function num_param(string $key): ?float
{
    $v = param($key, '');
    if ($v === '' || $v === null) return null;
    $v = (float)str_replace(',', '.', (string)$v);
    return $v > 0 ? $v : null;
}

function need_role(string ...$roles): void
{
    if (!has_role(...$roles)) fail('Anda tidak memiliki akses untuk aksi ini.', 403);
}

try {
    $action = $_GET['action'] ?? '';
    db();
    $PUBLIC = ['login', 'me', 'logout'];
    if (!in_array($action, $PUBLIC, true)) {
        if (!current_user()) fail('Silakan login terlebih dahulu.', 401, ['auth' => true]);
        if (!in_array($action, ['change_password', 'set_work_branch'], true)) session_write_close();
    }

    foreach (['auth', 'admin', 'items', 'packages', 'shipments', 'receive', 'followup', 'photos', 'reports'] as $module) {
        require __DIR__ . '/lib/api/' . $module . '.php';
    }
    fail('Aksi tidak dikenal.', 404);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    fail($e->getMessage(), 500);
}
