<?php
// Data barang standar pergudangan: validasi, kode otomatis per kategori, input banyak unit, impor template Excel.

/** Kolom template impor (urutan = kolom di Excel). Tanda * wajib. */
const ITEM_TEMPLATE_COLUMNS = [
    'code' => 'Kode Barang',
    'category' => 'Kategori*',
    'nama' => 'Nama Barang*',
    'merk' => 'Merk',
    'model' => 'Model/Tipe',
    'spec' => 'Spesifikasi',
    'track' => 'Pelacakan (Unit/Jumlah)',
    'serial' => 'Serial Number',
    'asset_no' => 'No. Aset',
    'unit' => 'Satuan',
    'stock' => 'Stok',
    'min_stock' => 'Stok Minimum',
    'branch' => 'Gudang',
    'bin_location' => 'Rak/Bin',
    'cond' => 'Kondisi',
    'accessories' => 'Kelengkapan',
    'acquired_date' => 'Tgl Perolehan',
    'supplier' => 'Supplier',
    'acquired_value' => 'Nilai Perolehan',
    'po_number' => 'No. PO/Dokumen',
    'warranty_until' => 'Garansi s/d',
    'notes' => 'Keterangan',
];

function find_category($ref): ?array
{
    $ref = trim((string)$ref);
    if ($ref === '') return null;
    $st = db()->prepare('SELECT * FROM categories WHERE id = ? OR code = ? OR name = ? COLLATE NOCASE LIMIT 1');
    $st->execute([ctype_digit($ref) ? (int)$ref : -1, $ref, $ref]);
    return $st->fetch() ?: null;
}

function find_branch_ref($ref): ?array
{
    $ref = trim((string)$ref);
    if ($ref === '') return null;
    $st = db()->prepare('SELECT * FROM branches WHERE id = ? OR code = ? COLLATE NOCASE OR name = ? COLLATE NOCASE LIMIT 1');
    $st->execute([ctype_digit($ref) ? (int)$ref : -1, $ref, $ref]);
    return $st->fetch() ?: null;
}

/** Tanggal dari form / Excel (YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, atau nomor seri Excel). */
/** @return string|null|false  false = format tidak valid */
function parse_date($v)
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {
        return date('Y-m-d', strtotime('1899-12-30 +' . (int)$v . ' days'));
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : false;
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m)) return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : false;
    return false;
}

function parse_money($v): ?float
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (is_numeric($v)) return (float)$v;
    $v = preg_replace('/[^\d,.-]/', '', $v);      // buang "Rp", spasi
    $v = str_replace('.', '', $v);                 // pemisah ribuan
    $v = str_replace(',', '.', $v);                // desimal
    return is_numeric($v) ? (float)$v : null;
}

/**
 * Normalisasi & validasi data barang. Melempar InvalidArgumentException dengan pesan yang bisa ditampilkan.
 * $in memakai nama kolom database (category_id boleh berupa id / kode / nama kategori).
 */
function normalize_item(array $in, ?array $old = null): array
{
    $g = function ($k) use ($in) { return isset($in[$k]) && !is_array($in[$k]) ? trim((string)$in[$k]) : ''; };

    $cat = find_category($in['category_id'] ?? $in['category'] ?? '');
    if (!$cat) throw new InvalidArgumentException('Kategori wajib dipilih / tidak dikenal.');
    $nama = $g('nama');
    if ($nama === '') throw new InvalidArgumentException('Nama barang wajib diisi.');

    $trackIn = strtolower($g('track'));
    $track = in_array($trackIn, ['qty', 'jumlah'], true) ? 'qty' : 'serial';
    if ($old) $track = $old['track']; // jenis pelacakan tidak diubah setelah dibuat

    $unit = strtolower($g('unit')) ?: ($track === 'qty' ? '' : 'unit');
    if ($unit === '') throw new InvalidArgumentException('Satuan wajib diisi untuk barang berbasis jumlah.');

    $branchRef = $in['location_branch_id'] ?? $in['branch'] ?? '';
    $branch = $branchRef !== '' && $branchRef !== null ? find_branch_ref($branchRef) : branch_by_id(user_branch_id());
    if (!$branch) throw new InvalidArgumentException('Gudang tidak dikenal: ' . $branchRef);

    $condIn = strtolower($g('cond'));
    $cond = isset(ITEM_CONDITIONS[$condIn]) ? $condIn : (array_search(ucfirst($condIn), ITEM_CONDITIONS, true) ?: 'baru');

    $dates = [];
    foreach (['acquired_date' => 'Tanggal perolehan', 'warranty_until' => 'Tanggal garansi'] as $k => $label) {
        $dates[$k] = parse_date($g($k));
        if ($dates[$k] === false) throw new InvalidArgumentException("$label tidak valid (pakai format DD/MM/YYYY).");
    }
    $value = parse_money($g('acquired_value'));
    if ($value !== null && $value < 0) throw new InvalidArgumentException('Nilai perolehan tidak boleh negatif.');

    return [
        'category_id' => (int)$cat['id'],
        'jenis' => $cat['name'],      // kolom lama tetap diisi agar ringkasan & laporan lama konsisten
        'kategori' => $cat['code'],
        'nama' => $nama,
        'merk' => $g('merk'),
        'model' => $g('model'),
        'spec' => $g('spec'),
        'serial' => $track === 'serial' ? $g('serial') : '',
        'asset_no' => $track === 'serial' ? strtoupper($g('asset_no')) : '',
        'track' => $track,
        'unit' => $unit,
        'stock' => $track === 'qty' ? max(0, (int)$g('stock')) : 1,
        'min_stock' => $track === 'qty' ? max(0, (int)$g('min_stock')) : 0,
        'location_branch_id' => (int)$branch['id'],
        'bin_location' => strtoupper($g('bin_location')),
        'cond' => $cond,
        'accessories' => $g('accessories'),
        'acquired_date' => $dates['acquired_date'],
        'supplier' => $g('supplier'),
        'acquired_value' => $value,
        'po_number' => $g('po_number'),
        'warranty_until' => $dates['warranty_until'],
        'notes' => $g('notes'),
    ];
}

/** Cek duplikat serial number (merk sama) & nomor aset. */
function check_item_duplicates(array $f, int $exceptId = 0): void
{
    if ($f['serial'] !== '') {
        $st = db()->prepare('SELECT code FROM items WHERE serial = ? COLLATE NOCASE AND merk = ? COLLATE NOCASE AND id <> ?');
        $st->execute([$f['serial'], $f['merk'], $exceptId]);
        if ($c = $st->fetchColumn()) throw new InvalidArgumentException("Serial number {$f['serial']} sudah terdaftar di barang $c.");
    }
    if ($f['track'] === 'qty') {
        // Barang habis pakai: satu kode per nama+merk+model per gudang (tambah stok, bukan barang baru)
        $st = db()->prepare("SELECT code FROM items WHERE track = 'qty' AND nama = ? COLLATE NOCASE AND merk = ? COLLATE NOCASE AND model = ? COLLATE NOCASE AND location_branch_id = ? AND id <> ?");
        $st->execute([$f['nama'], $f['merk'], $f['model'], $f['location_branch_id'], $exceptId]);
        if ($c = $st->fetchColumn()) throw new InvalidArgumentException("{$f['nama']} sudah terdaftar sebagai $c di gudang ini. Sesuaikan stoknya lewat Edit barang.");
    }
    if ($f['asset_no'] !== '') {
        $st = db()->prepare('SELECT code FROM items WHERE asset_no = ? AND id <> ?');
        $st->execute([$f['asset_no'], $exceptId]);
        if ($c = $st->fetchColumn()) throw new InvalidArgumentException("No. aset {$f['asset_no']} sudah dipakai barang $c.");
    }
}

/** Buat barang baru dengan kode otomatis <KODE KATEGORI>-0001. Mengembalikan kode. */
/** Validasi kode barang yang sudah ada (diisi manual / dari Excel). */
function validate_item_code(string $code): string
{
    $code = preg_replace('/\s+/', ' ', trim($code));
    if ($code === '') return '';
    if (stripos($code, MANUAL_PREFIX) === 0) throw new InvalidArgumentException("Awalan " . MANUAL_PREFIX . " dipakai sistem untuk barang manual.");
    if (mb_strlen($code) > 60) throw new InvalidArgumentException("Kode barang terlalu panjang (maks. 60 karakter): $code");
    if (find_item($code)) throw new InvalidArgumentException("Kode barang $code sudah dipakai.");
    if (find_package($code) || find_shipment($code)) throw new InvalidArgumentException("Kode $code bentrok dengan kode paket / surat jalan.");
    return $code;
}

/** Buat barang baru. Kode: $code bila diisi, selain itu otomatis <KODE KATEGORI>-0001. */
function create_item(array $f, string $source = 'input', string $code = ''): string
{
    $pdo = db();
    check_item_duplicates($f);
    $cat = find_category($f['category_id']);
    $code = validate_item_code($code) ?: next_code('items', strtoupper($cat['code']));
    $cols = array_keys($f);
    $pdo->prepare('INSERT INTO items (code, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(',?', count($cols)) . ')')
        ->execute(array_merge([$code], array_values($f)));
    $id = (int)$pdo->lastInsertId();
    $qty = $f['track'] === 'qty' ? " ({$f['stock']} {$f['unit']})" : '';
    item_event($id, 'created', ($source === 'import' ? 'Diimport dari Excel' : 'Didaftarkan') . ' di ' . branch_name($f['location_branch_id']) . ($f['bin_location'] ? ' rak ' . $f['bin_location'] : '') . $qty, null, null, $f['location_branch_id']);
    return $code;
}

/** Impor template Excel standar. Setiap baris = barang baru (kode dibuat otomatis). */
function import_standard_xlsx(string $path, bool $codeFromNotes = false): array
{
    $rows = xlsx_rows($path);
    // Cari baris header: baris yang memuat "Nama Barang"
    $cols = null;
    $start = 0;
    $wanted = [];
    foreach (ITEM_TEMPLATE_COLUMNS as $k => $label) $wanted[$k] = strtolower(rtrim($label, '*'));
    foreach ($rows as $i => $cells) {
        $found = [];
        foreach ($cells as $col => $val) {
            $v = strtolower(rtrim(trim($val), '*'));
            foreach ($wanted as $k => $label) if ($v === $label) $found[$k] = $col;
        }
        if (isset($found['nama'], $found['category'])) { $cols = $found; $start = $i + 1; break; }
    }
    if (!$cols) throw new RuntimeException('Header template tidak dikenali. Unduh template standar dari menu Import, lalu isi mulai baris ke-2.');

    $pdo = db();
    $created = [];
    $errors = [];
    foreach (array_slice($rows, $start, null, true) as $idx => $cells) {
        $in = [];
        foreach ($cols as $k => $col) $in[$k] = $cells[$col] ?? '';
        if (trim(implode('', $in)) === '') continue;            // baris kosong
        if (stripos($in['nama'], 'contoh') === 0) continue;      // baris contoh pada template
        $excelRow = $idx + 1;
        try {
            $pdo->exec('SAVEPOINT row_import');
            $code = trim((string)($in['code'] ?? ''));
            if ($codeFromNotes && $code === '') {
                // Kode barang lama dicatat di kolom Keterangan
                $code = trim((string)$in['notes']);
                $in['notes'] = '';
            }
            $code = create_item(normalize_item($in), 'import', $code);
            $pdo->exec('RELEASE row_import');
            $created[] = $code;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK TO row_import');
            $pdo->exec('RELEASE row_import');
            $errors[] = "Baris $excelRow: " . ($e instanceof InvalidArgumentException ? $e->getMessage() : 'Gagal disimpan (' . $e->getMessage() . ')');
        }
    }
    if ($created) log_activity('import', null, null, 'Import Excel: ' . count($created) . ' barang baru');
    return ['inserted' => count($created), 'errors' => $errors, 'codes' => $created];
}
