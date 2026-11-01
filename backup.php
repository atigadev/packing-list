<?php
// Unduh backup database (khusus admin). ?file=nama dari folder backups, atau tanpa parameter = backup saat ini.
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';

require_page_login('admin');

if (!empty($_GET['file'])) {
    $file = data_dir() . '/backups/' . basename($_GET['file']);
    if (!preg_match('/\.sqlite$/', $file) || !is_file($file)) {
        http_response_code(404);
        exit('File backup tidak ditemukan.');
    }
    $tmp = null;
    $name = basename($file);
} else {
    $tmp = data_dir() . '/backups/manual-' . date('Ymd-His') . '.sqlite';
    backup_to($tmp);
    $file = $tmp;
    $name = 'packing-list-' . date('Ymd-His') . '.sqlite';
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store');
readfile($file);
