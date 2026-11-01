<?php
// Menampilkan foto bukti / tanda tangan (hanya untuk pengguna yang login).
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';

require_page_login();

if (isset($_GET['sig'])) {
    $st = db()->prepare('SELECT signature_path FROM shipments WHERE code = ?');
    $st->execute([$_GET['sig']]);
    $path = $st->fetchColumn();
} else {
    $st = db()->prepare('SELECT path FROM photos WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $path = $st->fetchColumn();
}

$file = $path ? realpath(data_dir() . '/uploads/' . $path) : false;
$root = realpath(data_dir() . '/uploads');
if (!$file || !$root || strpos($file, $root) !== 0 || !is_file($file)) {
    http_response_code(404);
    exit('Foto tidak ditemukan.');
}

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($file);
