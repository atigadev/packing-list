# Packing List & Pengiriman Internal (PWA)

Aplikasi gudang untuk packing barang ke koli (Dus, Box, Container, Palet, Karung), membuat surat jalan, scan muat ke kendaraan, dan menerima barang di cabang dengan Berita Acara Serah Terima (BAST). Semua langkah memakai scan QR / barcode, dan tiap langkah tercatat: siapa yang melakukan dan kapan.

## Menjalankan

1. Jalankan **Apache** dari XAMPP Control Panel. MySQL tidak diperlukan: database memakai SQLite di `data/packing.sqlite`.
2. Buka `http://localhost/packing-list/`, lalu login dengan **admin / admin123**. Anda akan diminta mengganti password saat login pertama.
3. Lalu atur:
   - **Menu › Pengaturan**: nama & alamat perusahaan (untuk kop dokumen).
   - **Menu › Cabang**: daftar cabang tujuan, lengkap dengan alamat & PIC.
   - **Menu › Pengguna**: akun untuk tiap petugas.

### Peran pengguna

| Peran | Hak akses |
|---|---|
| Admin | Semua, termasuk pengguna, cabang, pengaturan, backup, hapus barang |
| Gudang | Barang, packing, segel, surat jalan, scan muat, berangkat |
| Kurir / Sopir | Lihat surat jalan, scan muat, berangkatkan, ubah resi/data angkutan |
| Penerima Cabang | Hanya melihat data cabangnya; packing & retur dari cabangnya; menerima kiriman ke cabangnya |
| Koordinator Gudang (semua lokasi) | Semua pekerjaan Gudang, Kurir & Penerima di **semua gudang/cabang**: packing, surat jalan, scan muat, berangkat, terima, tindak lanjut selisih. Pilih **lokasi kerja** lewat nama di bilah atas (📍); paket baru & surat jalan mengikuti lokasi itu. Tidak bisa mengelola pengguna, cabang, pengaturan, backup |

Admin juga bisa berganti lokasi kerja dengan cara yang sama.

## Data barang (standar pergudangan)

Setiap barang dicatat dengan format berikut. Kode barang dibuat **otomatis** dari kategori, mis. `NET-0001`, `PRN-0002`.

| Kelompok | Kolom |
|---|---|
| Identitas | Kategori*, Nama barang*, Merk, Model/tipe, Spesifikasi |
| Pelacakan | **Per unit** (aset ber-serial): Serial number, No. aset (unik) — atau **Berdasarkan jumlah** (habis pakai): Stok, Satuan*, Stok minimum |
| Lokasi & kondisi | Gudang*, Rak/bin, Kondisi (Baru/Bekas/Rusak), Kelengkapan |
| Perolehan & garansi | Tanggal perolehan, Supplier, Nilai perolehan (Rp), No. PO/dokumen, Garansi s/d |
| Lain-lain | Keterangan, foto |

- **Kategori**: kelola di *Menu › Kategori Barang*. Kode kategori (2–5 huruf) menjadi awalan kode barang, dan terkunci setelah dipakai.
- **Input manual**: *Barang › Tambah Barang*. Untuk beberapa unit identik, isi *Jumlah unit*, lalu SN / no. aset tiap unit. Setiap unit mendapat kode sendiri, dan label QR bisa langsung dicetak.
- **Import massal**: *Barang › Import Excel › Unduh template*. Isi satu baris per barang lalu upload. Baris yang salah ditolak dengan nomor baris dan alasannya; baris lain tetap masuk.
- **Pencegahan duplikat**:
  - Serial number tidak boleh dobel untuk merk yang sama.
  - No. aset harus unik.
  - Barang habis pakai hanya boleh satu kode per gudang; tambah stoknya lewat Edit.
- **Stok minimum**: barang habis pakai yang stoknya ≤ minimum muncul di Beranda (*Stok di bawah minimum*) dan di filter daftar barang.

## Alur kerja

1. **Data & label barang**: input barang (manual / template Excel), lalu cetak label QR dan tempel ke tiap barang.
2. **Packing**: Paket › Baru. Scan barang ke dalam paket.
   - Barang dengan pelacakan **jumlah** (kabel, konektor) akan ditanya jumlahnya, dan sistem mengecek stok.
   - Sistem menolak kode yang tidak terdaftar, barang yang sedang dikirim, atau barang yang tercatat di lokasi lain.
3. **Segel**: isi berat, nomor segel fisik, dan **foto isi** sebelum ditutup. Lalu cetak **label koli**.
4. **Surat jalan**: Kirim › Surat Jalan baru.
   - Pilih tujuan, lalu kendaraan sendiri (plat, sopir) atau ekspedisi (nama, no. resi).
   - Scan label paket yang sudah disegel. Paket yang belum disegel ditolak.
5. **Scan muat**: scan tiap koli saat naik ke kendaraan. Koli yang bukan bagian surat jalan ditolak.
6. **Berangkatkan**, lalu cetak surat jalan untuk sopir. Status barang berubah menjadi *Dalam pengiriman*.
7. **Penerimaan di cabang** (Terima Barang):
   1. Scan label koli dan cek segel. Nomor segel dicocokkan, dan foto bisa diambil kalau rusak.
   2. Scan setiap barang di dalamnya.
   3. Tandai barang yang **rusak** atau **kelengkapannya kurang**.
   4. Tanda tangan di layar.

   Barang yang tidak discan otomatis dicatat **kurang/hilang**, lalu BAST dicetak. Lokasi barang pindah ke cabang tujuan.
   Saat membuka penerimaan, pilih metodenya:
   - **📦 Scan koli saja**: scan label & cek segel tiap koli. Isi koli yang tiba dicatat diterima baik, dan barang yang rusak tinggal diketuk untuk ditandai.
   - **📦 + 🔹 Koli beserta isi**: setiap barang di-scan.

   Metode yang dipakai tercatat di BAST.
8. **Tindak lanjut selisih** (Menu › Tindak Lanjut Selisih, atau tombol di halaman surat jalan). Setiap barang kurang / rusak / kelengkapan kurang / lebih wajib diputuskan:

   | Selisih | Pilihan | Akibat |
   |---|---|---|
   | Kurang | Ditemukan di tujuan | Lokasi pindah ke tujuan, status Tersedia |
   | | Tertinggal di gudang asal | Lokasi kembali ke asal (barang jumlah: stok dikembalikan) |
   | | Dikirim susulan | Ditutup **otomatis** saat barang diterima lewat surat jalan berikutnya ke tujuan yang sama |
   | | Klaim / dihapusbukukan | Nomor klaim & nilai wajib / kronologi wajib; status *Dihapusbukukan* |
   | Rusak / kelengkapan kurang | Retur servis, klaim, dilengkapi, diterima apa adanya | Dicatat |
   | Lebih | Disimpan di tujuan / dikembalikan ke asal | Dicatat |

   - Barang berstatus *Hilang* yang di-scan saat packing akan ditanya "ditemukan di sini?", lalu selisihnya tertutup otomatis.
   - Setelah semua selisih diputuskan, surat jalan menjadi **Selesai (selisih ditindaklanjuti)**. Hasil awalnya tetap tercatat di BAST.
   - Surat jalan yang bermasalah hanya karena segel rusak (isinya lengkap) ditutup dengan mengisi hasil pemeriksaan.
   - Dokumen **Berita Acara Selisih & Tindak Lanjut** bisa dicetak (A4 / thermal).
   - Admin dapat memulihkan barang hilang yang ditemukan kembali lewat form edit barang.
9. **Retur**: pilih jenis *Retur* saat membuat surat jalan (alasan wajib diisi). Alurnya sama dengan pengiriman biasa.
10. **Cek**: scan kode apa pun.
   - Paket: menampilkan seluruh isinya.
   - Barang: menampilkan lokasi & **riwayat perpindahan**.
   - Surat jalan: membuka pengirimannya.

### Status

- Paket: Dipacking → Disegel → Dimuat → Dalam Perjalanan → Diterima
- Surat jalan: Persiapan → Dalam Perjalanan → Diterima Lengkap / Diterima Sebagian (ada kurang) / Bermasalah (rusak, lebih, segel rusak)

## Cetak

Setiap dokumen bisa dicetak ke printer biasa (A4 / label) atau **thermal 80 mm**, dan langsung ke **printer thermal Bluetooth** dari HP:

- Label barang (QR), label koli (Koli n/N, tujuan, penerima, berat, segel, FRAGILE), packing list
- Surat jalan (daftar koli + rincian isi + tanda tangan)
- BAST (kondisi segel, hasil pemeriksaan barang, tanda tangan digital penerima)

Printer Bluetooth: pilih **RawBT** (aplikasi Android, bisa lewat HTTP) atau **Web Bluetooth** (printer BLE, butuh HTTPS). Lebar kertas 80 / 58 mm.

## Laporan & ekspor Excel

Menu › Laporan berisi:
- pengiriman yang belum diterima (dengan jumlah hari);
- selisih penerimaan (kurang/rusak/lebih) per periode;
- rekap bulanan per cabang (pengiriman, koli, barang, berat).

Ekspor Excel tersedia untuk: database barang & lokasi, daftar pengiriman, selisih, rekap, dan detail per surat jalan.

## Scan offline

Kalau sinyal hilang saat scan packing, scan muat, atau penerimaan, hasil scan disimpan di HP (badge **⏳ n antre**) dan dikirim otomatis saat online. Scan yang ditolak server tampil di **Menu › Antrean Offline**.

## HTTPS untuk HP (kamera, notifikasi, Bluetooth langsung)

Browser hanya mengizinkan kamera dan fitur PWA penuh lewat HTTPS. Untuk jaringan kantor:

1. Klik kanan `tools\buat-sertifikat-https.bat` → *Run as administrator*. IP komputer terdeteksi otomatis, atau jalankan `buat-sertifikat-https.bat 192.168.1.10`. Sertifikat lama Apache dibackup dulu.
2. Restart Apache.
3. Di tiap HP:
   1. Buka `http://IP-SERVER/packing-list/sertifikat-ca.crt`.
   2. Pasang sertifikatnya lewat *Setelan › Keamanan › Enkripsi & kredensial › Instal sertifikat › Sertifikat CA*.
4. Buka `https://IP-SERVER/packing-list/`, lalu pilih *Instal aplikasi* di menu Chrome.

Agar cabang di kota lain bisa menerima barang lewat aplikasi, server harus dapat diakses dari luar kantor: pindahkan ke hosting yang punya domain + SSL (cukup PHP 7.4+, tanpa MySQL), atau pakai VPN kantor. Simpan `tools\ssl\ca.key` baik-baik dan jangan dibagikan.

## Backup

- **Otomatis:** dibuat setiap hari di `data/backups/` dan disimpan selama N hari (atur di Pengaturan).
- **Manual:** *Menu › Pengaturan & Backup › Backup sekarang*. Simpan salinannya di luar komputer server.
- **Pemulihan:** hentikan Apache, lalu ganti `data/packing.sqlite` dengan file backup.

## Update sistem

Service worker (`sw.php`) menghitung versi otomatis dari isi file aplikasi. Setiap ada perubahan, HP menampilkan banner **"Versi baru tersedia"**. Pengecekan dilakukan saat aplikasi dibuka, saat kembali ke aplikasi, dan tiap 1 menit.

## Struktur

| File | Fungsi |
|---|---|
| `index.html`, `app.js`, `app.css` | Inti PWA: login, scanner, foto, tanda tangan, dialog cetak, router, update |
| `js/*.js` | Halaman: paket & cek, barang & riwayat, pengiriman, penerimaan, admin, laporan, antrean offline |
| `btprint.js` | Cetak ESC/POS ke printer thermal Bluetooth |
| `api.php` + `lib/api/*.php` | API JSON per modul (auth, admin, items, packages, shipments, receive, followup, photos, reports) |
| `lib/db.php` | Skema & migrasi database (otomatis, dengan backup sebelum migrasi) |
| `lib/auth.php`, `lib/helpers.php` | Login & hak akses, logika paket/pengiriman |
| `print.php` | Label, packing list, surat jalan, BAST (A4 / thermal) |
| `export.php`, `lib/xlsx_writer.php` | Ekspor Excel |
| `photo.php`, `backup.php` | Foto bukti & tanda tangan (wajib login), unduh backup (admin) |
| `sw.php`, `manifest.json`, `icons/` | PWA, cache offline, deteksi update |
| `tools/buat-sertifikat-https.bat` | Sertifikat HTTPS lokal untuk Apache |
| `data/` | Database, backup, foto, sesi (diblokir dari akses web) |

## Batasan

- **Stok barang jumlah:** dikelola di gudang asal. Jumlah yang diterima cabang tercatat di BAST, tetapi belum menjadi stok per cabang.
- **Hak akses cabang:** pengguna cabang hanya melihat paket & surat jalan cabangnya. Daftar barang tetap bisa dilihat semua pengguna.

## Deploy produksi (cPanel)

Lihat [DEPLOY.md](DEPLOY.md): hubungkan repo GitHub ke *Git Version Control* cPanel, lalu deploy otomatis lewat `.cpanel.yml`. Konfigurasi khusus server (mis. lokasi folder data) disimpan di `config.php` (contoh: `config.example.php`).
