<?php
// Koneksi SQLite, skema & migrasi database. File database disimpan di folder data/.

const PACKAGE_TYPES = [
    'DUS' => 'Dus',
    'BOX' => 'Box',
    'CTR' => 'Container',
    'PLT' => 'Palet',
    'KRG' => 'Karung',
    'LNY' => 'Lainnya',
];

const ROLES = [
    'admin' => 'Admin',
    'gudang' => 'Gudang',
    'kurir' => 'Kurir / Sopir',
    'cabang' => 'Penerima Cabang',
    'koordinator' => 'Koordinator Gudang (semua lokasi)',
];

/** Peran yang bisa bekerja di semua gudang / cabang (memilih lokasi kerja). */
const MULTI_BRANCH_ROLES = ['admin', 'koordinator'];

/** Peran operasional yang juga dimiliki koordinator. */
const KOORDINATOR_ACTS_AS = ['gudang', 'cabang', 'kurir'];

const PACKAGE_STAGES = [
    'packing' => 'Dipacking',
    'sealed' => 'Disegel',
    'loaded' => 'Dimuat',
    'in_transit' => 'Dalam Perjalanan',
    'received' => 'Diterima',
];

const SHIPMENT_STATUS = [
    'draft' => 'Persiapan',
    'in_transit' => 'Dalam Perjalanan',
    'received' => 'Diterima Lengkap',
    'partial' => 'Diterima Sebagian',
    'problem' => 'Bermasalah',
    'resolved' => 'Selesai (selisih ditindaklanjuti)',
];

const ITEM_CONDITIONS = ['baru' => 'Baru', 'bekas' => 'Bekas', 'rusak' => 'Rusak'];
const ITEM_STATUS = ['ada' => 'Tersedia', 'dikirim' => 'Dalam pengiriman', 'hilang' => 'Hilang', 'dihapus' => 'Dihapusbukukan'];

// Tindak lanjut selisih penerimaan. "short" = barang jumlah yang diterima kurang dari kiriman.
const RESOLUTIONS = [
    'found_dest' => ['label' => 'Ditemukan di tujuan', 'for' => ['kurang', 'short']],
    'left_origin' => ['label' => 'Tertinggal di gudang asal', 'for' => ['kurang', 'short']],
    'resent' => ['label' => 'Dikirim susulan', 'for' => ['kurang', 'short']],
    'claim' => ['label' => 'Klaim ke ekspedisi / pihak terkait', 'for' => ['kurang', 'short', 'rusak', 'tidak_lengkap']],
    'writeoff' => ['label' => 'Hilang — dihapusbukukan', 'for' => ['kurang', 'short']],
    'retur' => ['label' => 'Diretur untuk servis / penggantian', 'for' => ['rusak', 'tidak_lengkap']],
    'complete' => ['label' => 'Kelengkapan sudah dilengkapi', 'for' => ['tidak_lengkap']],
    'accept' => ['label' => 'Diterima apa adanya', 'for' => ['rusak', 'tidak_lengkap']],
    'keep_dest' => ['label' => 'Diterima & disimpan di tujuan', 'for' => ['lebih']],
    'return_origin' => ['label' => 'Dikembalikan ke asal', 'for' => ['lebih']],
];

/** Baris penerimaan yang wajib ditindaklanjuti (alias tabel receipt_items = r). */
/** Kode baris barang manual di penerimaan / surat jalan: MANUAL-<id>. */
const MANUAL_PREFIX = 'MANUAL-';
/** JOIN nama barang manual untuk baris receipt_items (alias r -> pe). */
const MANUAL_JOIN_SQL = "LEFT JOIN package_extras pe ON r.item_id IS NULL AND r.code LIKE 'MANUAL-%' AND pe.id = CAST(SUBSTR(r.code, 8) AS INTEGER)";

const NEEDS_RESOLUTION_SQL = "(r.cond <> 'ok' OR r.short_qty > 0)";

const RECEIPT_CONDITIONS = [
    'ok' => 'Baik',
    'rusak' => 'Rusak',
    'tidak_lengkap' => 'Kelengkapan kurang',
    'kurang' => 'Tidak ada / kurang',
    'lebih' => 'Lebih / tidak terdaftar',
];

const HANDLING = ['fragile' => 'FRAGILE / Mudah pecah', 'up' => 'Sisi ini di atas', 'nostack' => 'Jangan ditumpuk', 'dry' => 'Jaga tetap kering'];

const SCHEMA_VERSION = 5;

// Kategori awal (bisa diubah di Menu › Kategori). Kode = awalan kode barang, mis. NET-0001.
const DEFAULT_CATEGORIES = [
    'KOM' => 'Komputer & Laptop',
    'PRN' => 'Printer',
    'SCN' => 'Scanner & Barcode Reader',
    'NET' => 'Perangkat Jaringan',
    'CAM' => 'CCTV & Kamera',
    'DSP' => 'Display & Proyektor',
    'PWR' => 'UPS & Kelistrikan',
    'ACC' => 'Aksesoris & Adaptor',
    'KBL' => 'Kabel & Konektor',
    'TLS' => 'Peralatan / Tools',
    'LNY' => 'Lainnya',
];

const UNITS = ['unit', 'pcs', 'set', 'box', 'pack', 'roll', 'meter', 'lusin', 'rim', 'liter', 'kg'];

const RECEIVE_MODES = [
    'koli' => 'Per koli (scan label koli & cek segel, isi dianggap lengkap)',
    'item' => 'Per barang (setiap isi koli discan)',
];

date_default_timezone_set('Asia/Jakarta');

// Konfigurasi per server (opsional, tidak masuk git). Lihat config.example.php.
if (is_file(__DIR__ . '/../config.php')) require_once __DIR__ . '/../config.php';

function data_dir(): string
{
    $dir = getenv('PACKING_DATA') ?: (defined('PACKING_DATA_DIR') ? PACKING_DATA_DIR : __DIR__ . '/../data');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $pdo = new PDO('sqlite:' . data_dir() . '/packing.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    // Skema dasar (versi 0)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS items (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            code       TEXT NOT NULL UNIQUE COLLATE NOCASE,
            jenis      TEXT,
            kategori   TEXT,
            merk       TEXT,
            model      TEXT,
            serial     TEXT,
            nama       TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS packages (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            code        TEXT NOT NULL UNIQUE COLLATE NOCASE,
            type        TEXT NOT NULL,
            name        TEXT,
            destination TEXT,
            notes       TEXT,
            parent_id   INTEGER REFERENCES packages(id) ON DELETE SET NULL,
            status      TEXT NOT NULL DEFAULT 'open',
            created_at  TEXT DEFAULT (datetime('now','localtime')),
            sealed_at   TEXT
        );
        CREATE TABLE IF NOT EXISTS package_items (
            package_id INTEGER NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
            item_id    INTEGER NOT NULL UNIQUE REFERENCES items(id) ON DELETE CASCADE,
            added_at   TEXT DEFAULT (datetime('now','localtime')),
            PRIMARY KEY (package_id, item_id)
        );
        CREATE TABLE IF NOT EXISTS activity (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            action     TEXT NOT NULL,
            package    TEXT,
            item       TEXT,
            message    TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
    ");

    migrate($pdo);
    auto_backup($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= SCHEMA_VERSION) return;

    // Cadangkan database sebelum migrasi jika sudah berisi data
    if ((int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn() > 0) {
        backup_to(data_dir() . '/backups/pre-migrate-v' . $version . '-' . date('Ymd-His') . '.sqlite');
    }

    $pdo->beginTransaction();
    try {
        if ($version < 1) migrate_v1($pdo);
        if ($version < 2) migrate_v2($pdo);
        if ($version < 3) migrate_v3($pdo);
        if ($version < 4) migrate_v4($pdo);
        if ($version < 5) migrate_v5($pdo);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
}

/** v5: barang manual / non-inventaris di dalam paket (tidak masuk pencatatan gudang). */
function migrate_v5(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE package_extras (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            package_id INTEGER NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
            name       TEXT NOT NULL,
            qty        INTEGER NOT NULL DEFAULT 1,
            unit       TEXT NOT NULL DEFAULT 'pcs',
            note       TEXT,
            added_by   INTEGER REFERENCES users(id),
            added_at   TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE INDEX idx_package_extras ON package_extras(package_id);
    ");
}

/** v4: data barang standar pergudangan (master kategori, identitas aset, rak/bin, perolehan, stok minimum). */
function migrate_v4(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE categories (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            code       TEXT NOT NULL UNIQUE COLLATE NOCASE,
            name       TEXT NOT NULL,
            active     INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
        ALTER TABLE items ADD COLUMN category_id INTEGER REFERENCES categories(id);
        ALTER TABLE items ADD COLUMN spec TEXT;
        ALTER TABLE items ADD COLUMN asset_no TEXT COLLATE NOCASE;
        ALTER TABLE items ADD COLUMN bin_location TEXT;
        ALTER TABLE items ADD COLUMN acquired_date TEXT;
        ALTER TABLE items ADD COLUMN supplier TEXT;
        ALTER TABLE items ADD COLUMN acquired_value REAL;
        ALTER TABLE items ADD COLUMN po_number TEXT;
        ALTER TABLE items ADD COLUMN warranty_until TEXT;
        ALTER TABLE items ADD COLUMN min_stock INTEGER NOT NULL DEFAULT 0;
        ALTER TABLE items ADD COLUMN notes TEXT;
        CREATE UNIQUE INDEX idx_items_asset_no ON items(asset_no) WHERE asset_no IS NOT NULL AND asset_no <> '';
        CREATE INDEX idx_items_category ON items(category_id);
    ");
    $ins = $pdo->prepare('INSERT INTO categories (code, name) VALUES (?,?)');
    foreach (DEFAULT_CATEGORIES as $code => $name) $ins->execute([$code, $name]);
}

/** v3: tindak lanjut selisih penerimaan. */
function migrate_v3(PDO $pdo): void
{
    $pdo->exec("
        ALTER TABLE receipt_items ADD COLUMN short_qty INTEGER NOT NULL DEFAULT 0;
        ALTER TABLE receipt_items ADD COLUMN resolution TEXT;
        ALTER TABLE receipt_items ADD COLUMN resolution_note TEXT;
        ALTER TABLE receipt_items ADD COLUMN resolution_ref TEXT;
        ALTER TABLE receipt_items ADD COLUMN resolution_value REAL;
        ALTER TABLE receipt_items ADD COLUMN resolved_by INTEGER REFERENCES users(id);
        ALTER TABLE receipt_items ADD COLUMN resolved_at TEXT;
        ALTER TABLE shipments ADD COLUMN receive_result TEXT;
        ALTER TABLE shipments ADD COLUMN resolved_at TEXT;
        UPDATE shipments SET receive_result = status WHERE status IN ('received','partial','problem');
    ");

    // Kekurangan jumlah yang dulu hanya dicatat di catatan ("Kurang 5 pcs dari 50")
    $upd = $pdo->prepare('UPDATE receipt_items SET short_qty = ? WHERE id = ?');
    foreach ($pdo->query("SELECT id, note FROM receipt_items WHERE cond = 'ok' AND note LIKE '%Kurang % dari %'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (preg_match('/Kurang (\d+) /', $r['note'], $m)) $upd->execute([(int)$m[1], $r['id']]);
    }

    // Barang "kurang" yang ternyata sudah diterima lewat surat jalan berikutnya ke tujuan yang sama -> dikirim susulan
    $open = $pdo->query("SELECT r.id, r.item_id, s.dest_branch_id, s.received_at FROM receipt_items r JOIN shipments s ON s.id = r.shipment_id
                         WHERE r.cond = 'kurang' AND r.item_id IS NOT NULL AND r.resolution IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $later = $pdo->prepare("SELECT s.code, s.received_at FROM receipt_items r JOIN shipments s ON s.id = r.shipment_id
                            WHERE r.item_id = ? AND r.cond <> 'kurang' AND s.dest_branch_id = ? AND s.received_at > ? ORDER BY s.received_at LIMIT 1");
    $res = $pdo->prepare("UPDATE receipt_items SET resolution = 'resent', resolution_ref = ?, resolution_note = ?, resolved_at = ? WHERE id = ?");
    foreach ($open as $o) {
        $later->execute([$o['item_id'], $o['dest_branch_id'], $o['received_at']]);
        if ($l = $later->fetch(PDO::FETCH_ASSOC)) $res->execute([$l['code'], 'Otomatis: diterima lewat ' . $l['code'], $l['received_at'], $o['id']]);
    }

    // Surat jalan yang semua selisihnya sudah ditindaklanjuti -> selesai
    $pdo->exec("UPDATE shipments SET status = 'resolved',
                    resolved_at = (SELECT MAX(r.resolved_at) FROM receipt_items r WHERE r.shipment_id = shipments.id)
                WHERE status IN ('partial','problem')
                  AND EXISTS (SELECT 1 FROM receipt_items r WHERE r.shipment_id = shipments.id AND r.resolution IS NOT NULL)
                  AND NOT EXISTS (SELECT 1 FROM receipt_items r WHERE r.shipment_id = shipments.id AND " . NEEDS_RESOLUTION_SQL . " AND r.resolution IS NULL)");
}

/** v2: metode penerimaan surat jalan (per koli / per barang). */
function migrate_v2(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE shipments ADD COLUMN receive_mode TEXT");
}

function migrate_v1(PDO $pdo): void
{
    $now = "DEFAULT (datetime('now','localtime'))";
    $pdo->exec("
        CREATE TABLE branches (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            code       TEXT NOT NULL UNIQUE COLLATE NOCASE,
            name       TEXT NOT NULL,
            address    TEXT,
            pic_name   TEXT,
            pic_phone  TEXT,
            is_hq      INTEGER NOT NULL DEFAULT 0,
            active     INTEGER NOT NULL DEFAULT 1,
            created_at TEXT $now
        );
        INSERT INTO branches (code, name, is_hq) VALUES ('PUSAT', 'Gudang Pusat', 1);

        CREATE TABLE users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
            name          TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role          TEXT NOT NULL,
            branch_id     INTEGER REFERENCES branches(id),
            active        INTEGER NOT NULL DEFAULT 1,
            must_change   INTEGER NOT NULL DEFAULT 0,
            last_login    TEXT,
            created_at    TEXT $now
        );

        CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);

        CREATE TABLE shipments (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            code             TEXT NOT NULL UNIQUE COLLATE NOCASE,
            kind             TEXT NOT NULL DEFAULT 'kirim',
            origin_branch_id INTEGER NOT NULL REFERENCES branches(id),
            dest_branch_id   INTEGER NOT NULL REFERENCES branches(id),
            mode             TEXT NOT NULL DEFAULT 'internal',
            vehicle          TEXT,
            plate            TEXT,
            driver           TEXT,
            driver_phone     TEXT,
            courier          TEXT,
            tracking_no      TEXT,
            depart_plan      TEXT,
            eta              TEXT,
            reason           TEXT,
            notes            TEXT,
            status           TEXT NOT NULL DEFAULT 'draft',
            created_by       INTEGER REFERENCES users(id),
            created_at       TEXT $now,
            departed_at      TEXT,
            departed_by      INTEGER REFERENCES users(id),
            received_at      TEXT,
            received_by      INTEGER REFERENCES users(id),
            receiver_name    TEXT,
            receive_notes    TEXT,
            signature_path   TEXT
        );

        CREATE TABLE package_arrivals (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            shipment_id     INTEGER NOT NULL REFERENCES shipments(id) ON DELETE CASCADE,
            package_id      INTEGER NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
            seal_ok         INTEGER,
            seal_no_checked TEXT,
            note            TEXT,
            user_id         INTEGER REFERENCES users(id),
            arrived_at      TEXT $now,
            UNIQUE (shipment_id, package_id)
        );

        CREATE TABLE receipt_items (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            shipment_id INTEGER NOT NULL REFERENCES shipments(id) ON DELETE CASCADE,
            package_id  INTEGER REFERENCES packages(id) ON DELETE SET NULL,
            item_id     INTEGER REFERENCES items(id) ON DELETE SET NULL,
            code        TEXT NOT NULL COLLATE NOCASE,
            qty         INTEGER NOT NULL DEFAULT 1,
            cond        TEXT NOT NULL DEFAULT 'ok',
            note        TEXT,
            user_id     INTEGER REFERENCES users(id),
            scanned_at  TEXT $now,
            UNIQUE (shipment_id, code)
        );

        CREATE TABLE photos (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            ref_type   TEXT NOT NULL,
            ref_code   TEXT NOT NULL COLLATE NOCASE,
            path       TEXT NOT NULL,
            caption    TEXT,
            user_id    INTEGER REFERENCES users(id),
            created_at TEXT $now
        );
        CREATE INDEX idx_photos_ref ON photos(ref_type, ref_code);

        CREATE TABLE item_events (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id       INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
            event         TEXT NOT NULL,
            detail        TEXT,
            package_code  TEXT,
            shipment_code TEXT,
            branch_id     INTEGER REFERENCES branches(id),
            user_id       INTEGER REFERENCES users(id),
            created_at    TEXT $now
        );
        CREATE INDEX idx_item_events ON item_events(item_id);

        ALTER TABLE items ADD COLUMN track TEXT NOT NULL DEFAULT 'serial';
        ALTER TABLE items ADD COLUMN unit TEXT NOT NULL DEFAULT 'unit';
        ALTER TABLE items ADD COLUMN stock INTEGER NOT NULL DEFAULT 1;
        ALTER TABLE items ADD COLUMN cond TEXT NOT NULL DEFAULT 'baru';
        ALTER TABLE items ADD COLUMN accessories TEXT;
        ALTER TABLE items ADD COLUMN location_branch_id INTEGER REFERENCES branches(id);
        ALTER TABLE items ADD COLUMN status TEXT NOT NULL DEFAULT 'ada';
        UPDATE items SET location_branch_id = 1;

        ALTER TABLE packages ADD COLUMN branch_id INTEGER REFERENCES branches(id);
        ALTER TABLE packages ADD COLUMN stage TEXT NOT NULL DEFAULT 'packing';
        ALTER TABLE packages ADD COLUMN shipment_id INTEGER REFERENCES shipments(id);
        ALTER TABLE packages ADD COLUMN koli_no INTEGER;
        ALTER TABLE packages ADD COLUMN loaded_at TEXT;
        ALTER TABLE packages ADD COLUMN weight_kg REAL;
        ALTER TABLE packages ADD COLUMN length_cm REAL;
        ALTER TABLE packages ADD COLUMN width_cm REAL;
        ALTER TABLE packages ADD COLUMN height_cm REAL;
        ALTER TABLE packages ADD COLUMN seal_no TEXT;
        ALTER TABLE packages ADD COLUMN receiver_name TEXT;
        ALTER TABLE packages ADD COLUMN receiver_phone TEXT;
        ALTER TABLE packages ADD COLUMN handling TEXT;
        ALTER TABLE packages ADD COLUMN created_by INTEGER REFERENCES users(id);
        UPDATE packages SET branch_id = 1, stage = CASE status WHEN 'sealed' THEN 'sealed' ELSE 'packing' END;

        -- package_items dibangun ulang: mendukung barang berdasarkan jumlah (qty)
        CREATE TABLE package_items_new (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            package_id INTEGER NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
            item_id    INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
            qty        INTEGER NOT NULL DEFAULT 1,
            added_at   TEXT $now,
            added_by   INTEGER REFERENCES users(id),
            UNIQUE (package_id, item_id)
        );
        INSERT INTO package_items_new (package_id, item_id, qty, added_at) SELECT package_id, item_id, 1, added_at FROM package_items;
        DROP TABLE package_items;
        ALTER TABLE package_items_new RENAME TO package_items;
        CREATE INDEX idx_package_items_item ON package_items(item_id);

        ALTER TABLE activity ADD COLUMN user_id INTEGER REFERENCES users(id);
        ALTER TABLE activity ADD COLUMN shipment TEXT;

        INSERT INTO item_events (item_id, event, detail, package_code, branch_id, created_at)
            SELECT pi.item_id, 'packed', 'Masuk paket ' || p.code, p.code, 1, pi.added_at
            FROM package_items pi JOIN packages p ON p.id = pi.package_id;
    ");

    $pdo->prepare('INSERT INTO users (username, name, password_hash, role, branch_id, must_change) VALUES (?,?,?,?,?,1)')
        ->execute(['admin', 'Administrator', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 1]);

    $ins = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?,?)');
    foreach (['company_name' => 'Perusahaan', 'company_address' => '', 'vol_divisor' => '6000', 'backup_keep' => '14'] as $k => $v) {
        $ins->execute([$k, $v]);
    }
}

// ---------- Pengaturan ----------
function settings_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT key, value FROM settings') as $r) $cache[$r['key']] = $r['value'];
    }
    return $cache;
}

function setting(string $key, $default = '')
{
    return settings_all()[$key] ?? $default;
}

// ---------- Backup ----------
function backup_to(string $file): void
{
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
    if (is_file($file)) unlink($file);
    db_raw()->exec('VACUUM INTO ' . db_raw()->quote($file));
}

/** Koneksi tanpa migrasi (dipakai untuk backup sebelum migrasi). */
function db_raw(): PDO
{
    static $raw = null;
    if (!$raw) {
        $raw = new PDO('sqlite:' . data_dir() . '/packing.sqlite');
        $raw->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $raw->exec('PRAGMA busy_timeout = 5000');
    }
    return $raw;
}

/** Backup otomatis harian, disimpan sebanyak N hari (pengaturan backup_keep). */
function auto_backup(PDO $pdo): void
{
    $dir = data_dir() . '/backups';
    $file = $dir . '/auto-' . date('Ymd') . '.sqlite';
    if (is_file($file)) return;
    try {
        backup_to($file);
        $keep = max(1, (int)setting('backup_keep', 14));
        $files = glob($dir . '/auto-*.sqlite');
        rsort($files);
        foreach (array_slice($files, $keep) as $old) @unlink($old);
    } catch (Throwable $e) {
        // backup gagal tidak boleh menghentikan aplikasi
    }
}

// ---------- Log ----------
function current_user_id(): ?int
{
    return $GLOBALS['CURRENT_USER']['id'] ?? null;
}

function log_activity(string $action, ?string $package, ?string $item, string $message, ?string $shipment = null): void
{
    db()->prepare('INSERT INTO activity (action, package, item, message, shipment, user_id) VALUES (?,?,?,?,?,?)')
        ->execute([$action, $package, $item, $message, $shipment, current_user_id()]);
}

function item_event(int $itemId, string $event, string $detail, ?string $package = null, ?string $shipment = null, ?int $branchId = null): void
{
    db()->prepare('INSERT INTO item_events (item_id, event, detail, package_code, shipment_code, branch_id, user_id) VALUES (?,?,?,?,?,?,?)')
        ->execute([$itemId, $event, $detail, $package, $shipment, $branchId, current_user_id()]);
}
