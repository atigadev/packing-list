<?php
// Contoh konfigurasi server. Salin menjadi config.php (di folder yang sama dengan index.html) lalu sesuaikan.
// config.php tidak masuk git, jadi tidak tertimpa saat deploy.

// Folder data (database SQLite, backup, foto, sesi). Disarankan di LUAR public_html di server produksi,
// mis. /home/USERNAME/packing-data. Folder harus bisa ditulis oleh PHP.
// Jika tidak diisi, dipakai folder data/ di dalam aplikasi (diblokir dari web oleh data/.htaccess).
// define('PACKING_DATA_DIR', '/home/USERNAME/packing-data');
