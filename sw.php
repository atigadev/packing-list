<?php
// Service worker dengan versi otomatis: versi = hash isi file aplikasi.
// Setiap file aplikasi berubah, isi script ini ikut berubah sehingga browser
// mendeteksi update dan aplikasi menampilkan notifikasi "Versi baru tersedia".
$shell = array_merge(
    ['index.html', 'app.css', 'app.js', 'btprint.js', 'manifest.json'],
    array_map(function ($f) { return substr($f, strlen(__DIR__) + 1); }, glob(__DIR__ . '/js/*.js')),
    array_map(function ($f) { return substr($f, strlen(__DIR__) + 1); }, glob(__DIR__ . '/vendor/*.js')),
    array_map(function ($f) { return substr($f, strlen(__DIR__) + 1); }, glob(__DIR__ . '/icons/*.png'))
);
$shell = array_map(function ($f) { return str_replace('\\', '/', $f); }, $shell);

$hash = '';
$latest = 0;
foreach ($shell as $f) {
    $path = __DIR__ . '/' . $f;
    if (!is_file($path)) continue;
    $hash .= md5_file($path);
    $latest = max($latest, filemtime($path));
}
date_default_timezone_set('Asia/Jakarta');
$version = date('Ymd.Hi', $latest) . '-' . substr(md5($hash), 0, 6);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
?>
// Packing List — service worker versi <?= $version ?>

const VERSION = <?= json_encode($version) ?>;
const CACHE = 'packing-list-' + VERSION;
const SHELL = <?= json_encode(array_merge(['./'], array_map(function ($f) { return './' . $f; }, $shell)), JSON_UNESCAPED_SLASHES) ?>;

self.addEventListener('install', (e) => {
  // Unduh semua file versi baru (bypass HTTP cache). Tidak langsung aktif:
  // menunggu pengguna menekan "Perbarui" di notifikasi.
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL.map((u) => new Request(u, { cache: 'reload' })))));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('packing-list-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (e) => {
  if (e.data === 'SKIP_WAITING') self.skipWaiting();
  if (e.data === 'GET_VERSION' && e.ports[0]) e.ports[0].postMessage(VERSION);
});

// Ketuk notifikasi update -> buka / fokuskan aplikasi (banner "Perbarui" tampil di sana)
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      const scope = self.registration.scope;
      const win = list.find((c) => c.url.startsWith(scope));
      return win ? win.focus() : self.clients.openWindow(scope);
    })
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.endsWith('.php')) return; // data & halaman cetak selalu dari server

  // Cache-first: aplikasi tetap memakai versi terpasang sampai pengguna memperbarui
  e.respondWith(
    caches.open(CACHE).then((c) =>
      c.match(e.request, { ignoreSearch: true }).then((hit) => hit || fetch(e.request).catch(() => c.match('./index.html')))
    )
  );
});
