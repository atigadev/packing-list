# Deploy ke Produksi (cPanel via Git)

Aplikasi berupa PHP + SQLite. Tidak perlu MySQL, Composer, atau build step.

## Kebutuhan hosting

- PHP **7.4 atau lebih baru** (disarankan 8.1+), dengan ekstensi `pdo_sqlite`, `zip`, `gd`, dan `mbstring`. Cek atau aktifkan di cPanel › *Select PHP Version* › *Extensions*.
- Fitur **Git™ Version Control** dan **rsync** (tersedia di cPanel standar).
- **SSL / HTTPS**: AutoSSL atau Let's Encrypt. Wajib, karena kamera, PWA, dan notifikasi tidak jalan tanpa HTTPS.

## 1. Hubungkan repo GitHub ke cPanel

Repo: `https://github.com/atigadev/packing-list.git`

**Kalau repo private**, buat SSH key dulu:
1. cPanel › *SSH Access* › *Manage SSH Keys* › *Generate a New Key* (tanpa passphrase), lalu **Authorize**.
2. Salin *public key*-nya ke GitHub › repo › *Settings* › *Deploy keys* › *Add deploy key* (cukup read-only).
3. Saat clone di cPanel, gunakan URL SSH: `git@github.com:atigadev/packing-list.git`.

**Clone di cPanel** › *Git Version Control* › *Create*:
- *Clone a Repository*: aktif.
- *Clone URL*: URL repo di atas.
- *Repository Path*: `repositories/packing-list`. Ini folder repo, **bukan** folder web.
- *Repository Name*: `packing-list`.

## 2. Atur lokasi aplikasi

Buka file `.cpanel.yml`, lalu sesuaikan `DEPLOYPATH`:

| Alamat aplikasi | DEPLOYPATH |
|---|---|
| `https://domain.com/packing-list/` | `$HOME/public_html/packing-list` (default) |
| `https://gudang.domain.com/` (subdomain) | folder dokumen subdomain, mis. `$HOME/gudang.domain.com` |

Commit dan push perubahan bila Anda mengubahnya.

## 3. Deploy

cPanel › *Git Version Control* › *Manage* › tab *Pull or Deploy*:
1. **Update from Remote**: mengambil commit terbaru dari GitHub.
2. **Deploy HEAD Commit**: menyalin file ke `DEPLOYPATH`.

Proses deploy **tidak menyentuh** folder `data/` (database, foto, backup) dan `config.php` di server, jadi data produksi aman setiap kali update.

## 4. Pengaturan pertama di server

1. **(Disarankan) Simpan data di luar `public_html`**:
   - Buat folder, mis. `/home/USERNAME/packing-data`, lewat *File Manager*.
   - Salin `config.example.php` menjadi `config.php` di folder aplikasi.
   - Aktifkan baris `define('PACKING_DATA_DIR', '/home/USERNAME/packing-data');`.

   Kalau dilewati, data disimpan di `data/` di dalam aplikasi. Folder itu sudah diblokir dari web oleh `data/.htaccess`.
2. **Paksa HTTPS**: setelah SSL aktif, buka `.htaccess` dan hapus tanda `#` pada blok *Paksa HTTPS*. File ini ikut tertimpa saat deploy, jadi lebih praktis ubah di repo lalu push.
3. **Login pertama**: buka aplikasi, login **admin / admin123**, lalu **langsung ganti password** (aplikasi akan memaksa).
4. **Isi data dasar**:
   - Menu › *Pengaturan*: nama & alamat perusahaan.
   - Menu › *Cabang*: daftar gudang/cabang.
   - Menu › *Kategori*.
   - Menu › *Pengguna*.
5. **Data barang**: Barang › *Import Excel* (template standar), atau tambah manual.

### Memindahkan data dari komputer lokal (opsional)

Kalau ingin memakai data yang sudah ada di XAMPP:
1. Di aplikasi lokal: Menu › *Pengaturan & Backup* › **Backup sekarang**. File `.sqlite` akan terunduh.
2. Upload file itu ke folder data server, lalu ganti namanya menjadi `packing.sqlite`.
3. Foto & tanda tangan ada di `data/uploads/` lokal. Upload juga ke folder data server (`uploads/`).

Struktur database diperbarui otomatis saat aplikasi pertama kali dibuka, dan backup dibuat sebelum setiap migrasi.

## Update berikutnya

1. Di komputer developer: commit lalu `git push`.
2. Di cPanel › *Git Version Control* › *Manage*: **Update from Remote**, lalu **Deploy HEAD Commit**.
3. HP pengguna otomatis menampilkan banner **"Versi baru tersedia"**.

## Backup produksi

- **Otomatis harian**: tersimpan di `<folder data>/backups/`, dengan jumlah hari yang diatur di Menu › Pengaturan.
- **Manual**: Menu › *Pengaturan & Backup* › Backup sekarang. Simpan juga di luar server.
- **Backup penuh**: aktifkan juga backup akun cPanel (*Backup* / JetBackup) bila tersedia.

## Pemecahan masalah

| Gejala | Penyebab / solusi |
|---|---|
| Halaman putih / error 500 | Cek versi PHP dan ekstensi `pdo_sqlite`, `zip`, `gd`, `mbstring`. Lihat *Errors* di cPanel. |
| "unable to open database file" | Folder data tidak bisa ditulis. Set permission folder data menjadi 755 (atau 775). |
| Kamera tidak bisa dibuka | Aplikasi belum diakses lewat `https://`. |
| Banner update tidak muncul | Sertifikat SSL tidak valid. Pastikan AutoSSL aktif untuk domain tersebut. |
| Deploy gagal di cPanel | Pastikan working tree bersih (*Update from Remote* dulu), dan file `.cpanel.yml` ada di root repo. |
