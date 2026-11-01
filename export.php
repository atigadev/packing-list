<?php
// Ekspor laporan ke Excel (.xlsx).
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/xlsx_writer.php';

require_page_login();
$action = '';
require __DIR__ . '/lib/api/reports.php'; // fungsi laporan

$type = $_GET['type'] ?? '';
$scope = branch_scope();
$x = new XlsxWriter();
$f = function ($v) { return $v === null || $v === '' ? null : (float)$v; };

switch ($type) {
    case 'items':
        $rows = [['Kode Barang', 'Kategori', 'Nama Barang', 'Merk', 'Model/Tipe', 'Spesifikasi', 'Pelacakan', 'Serial Number', 'No. Aset', 'Stok', 'Stok Minimum', 'Satuan',
            'Gudang', 'Rak/Bin', 'Kondisi', 'Status', 'Paket Aktif', 'Kelengkapan', 'Tgl Perolehan', 'Supplier', 'Nilai Perolehan', 'No. PO/Dokumen', 'Garansi s/d', 'Keterangan']];
        $st = db()->query("SELECT i.*, b.name AS loc, c.name AS cat,
                               (SELECT p.code FROM package_items pi JOIN packages p ON p.id = pi.package_id WHERE pi.item_id = i.id AND p.stage <> 'received' LIMIT 1) AS pkg
                           FROM items i LEFT JOIN branches b ON b.id = i.location_branch_id LEFT JOIN categories c ON c.id = i.category_id ORDER BY i.kategori, i.code");
        foreach ($st as $i) {
            $rows[] = [$i['code'], $i['cat'] ?: $i['jenis'], $i['nama'], $i['merk'], $i['model'], $i['spec'], $i['track'] === 'qty' ? 'Jumlah' : 'Unit', $i['serial'], $i['asset_no'],
                (int)$i['stock'], $i['track'] === 'qty' ? (int)$i['min_stock'] : null, $i['unit'], $i['loc'], $i['bin_location'], ITEM_CONDITIONS[$i['cond']] ?? $i['cond'],
                ITEM_STATUS[$i['status']] ?? $i['status'], $i['pkg'], $i['accessories'], $i['acquired_date'], $i['supplier'], $f($i['acquired_value']), $i['po_number'], $i['warranty_until'], $i['notes']];
        }
        $x->addSheet('Barang', $rows, [14, 20, 30, 12, 16, 26, 9, 16, 14, 7, 9, 8, 20, 10, 9, 16, 12, 22, 12, 18, 14, 16, 12, 24]);
        $name = 'barang-' . date('Ymd');
        break;

    case 'template':
        // Template impor barang standar + daftar kategori & gudang sebagai acuan
        require_once __DIR__ . '/lib/items_lib.php';
        $rows = [array_values(ITEM_TEMPLATE_COLUMNS)];
        $rows[] = ['', 'NET', 'Contoh: Switch 8 Port', 'TP-Link', 'TL-SG1008D', '8 port gigabit, unmanaged', 'Unit', 'SN123456789', 'AST-2026-0001', 'unit', '', '',
            'PUSAT', 'A-01-02', 'Baru', 'Adaptor', '15/10/2026', 'PT Contoh Supplier', '350000', 'PO-2026-001', '15/10/2027', 'Baris contoh — hapus atau biarkan (baris berawalan "Contoh" diabaikan)'];
        $rows[] = ['KBL-RJ45', 'KBL', 'Contoh: Konektor RJ45 Cat6', 'AMP', '', '', 'Jumlah', '', '', 'pcs', '500', '100', 'PUSAT', 'B-02-01', 'Baru', '', '', '', '', '', '', ''];
        $x->addSheet('Barang', $rows, [16, 10, 30, 12, 16, 26, 12, 16, 15, 8, 7, 10, 12, 10, 9, 18, 13, 20, 13, 16, 12, 30]);
        $cats = [['Kode Kategori', 'Nama Kategori']];
        foreach (db()->query('SELECT code, name FROM categories WHERE active = 1 ORDER BY code') as $c) $cats[] = [$c['code'], $c['name']];
        $x->addSheet('Kategori', $cats, [14, 30]);
        $br = [['Kode Gudang', 'Nama Gudang / Cabang']];
        foreach (db()->query('SELECT code, name FROM branches WHERE active = 1 ORDER BY is_hq DESC, name') as $b) $br[] = [$b['code'], $b['name']];
        $x->addSheet('Gudang', $br, [14, 30]);
        $x->addSheet('Petunjuk', [
            ['Petunjuk pengisian'],
            ['1. Isi sheet "Barang" mulai baris ke-2. Satu baris = satu barang.'],
            ['   Kode Barang: isi bila barang sudah punya kode / label sendiri (harus unik). Kosongkan agar sistem membuat kode otomatis (mis. NET-0001).'],
            ['2. Kategori*: isi kode atau nama kategori dari sheet "Kategori". Nama Barang* wajib.'],
            ['3. Pelacakan: "Unit" untuk barang ber-serial / aset (1 baris per unit), "Jumlah" untuk barang habis pakai (isi Stok, Satuan, Stok Minimum).'],
            ['4. Gudang: kode atau nama dari sheet "Gudang". Kosong = gudang lokasi kerja pengimpor.'],
            ['5. Kondisi: Baru / Bekas / Rusak. Tanggal: DD/MM/YYYY. Nilai Perolehan: angka tanpa titik/koma, mis. 350000.'],
            ['6. No. Aset harus unik. Serial number tidak boleh dobel untuk merk yang sama.'],
            ['7. Baris yang Nama Barang-nya diawali "Contoh" diabaikan.'],
        ], [110]);
        $name = 'template-data-barang';
        break;

    case 'shipments':
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-d');
        $rows = [['No. Surat Jalan', 'Jenis', 'Asal', 'Tujuan', 'Status', 'Pengangkutan', 'Sopir / Ekspedisi', 'No. Resi', 'Koli', 'Barang', 'Berat (kg)', 'Dibuat', 'Berangkat', 'Diterima', 'Penerima']];
        $sql = "SELECT s.* FROM shipments s WHERE date(s.created_at) BETWEEN ? AND ?";
        $args = [$from, $to];
        if ($scope) { $sql .= ' AND (s.origin_branch_id = ? OR s.dest_branch_id = ?)'; $args[] = $scope; $args[] = $scope; }
        $st = db()->prepare($sql . ' ORDER BY s.id');
        $st->execute($args);
        foreach ($st->fetchAll() as $s) {
            $d = shipment_detail($s);
            $s = $d['shipment'];
            $rows[] = [$s['code'], $s['kind'] === 'retur' ? 'Retur' : 'Kirim', $s['origin_name'], $s['dest_name'], $s['status_label'],
                $s['mode'] === 'ekspedisi' ? 'Ekspedisi' : trim($s['vehicle'] . ' ' . $s['plate']), $s['mode'] === 'ekspedisi' ? $s['courier'] : $s['driver'], $s['tracking_no'],
                count($d['packages']), $d['total_items'], $d['total_weight'], $s['created_at'], $s['departed_at'], $s['received_at'], $s['receiver_name']];
        }
        $x->addSheet('Pengiriman', $rows, [16, 7, 18, 18, 16, 16, 18, 14, 6, 8, 9, 17, 17, 17, 16]);
        $name = "pengiriman-$from-$to";
        break;

    case 'discrepancies':
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-d');
        $rows = [['No. Surat Jalan', 'Diterima', 'Asal', 'Tujuan', 'Kode Barang', 'Nama Item', 'SN', 'Paket', 'Qty', 'Selisih', 'Catatan', 'Tindak Lanjut', 'No. Dokumen / Klaim', 'Nilai Klaim', 'Keterangan Tindak Lanjut', 'Oleh', 'Tanggal']];
        foreach (report_discrepancies($from, $to, $scope) as $r) {
            $rows[] = [$r['shipment_code'], $r['received_at'], $r['origin_name'], $r['dest_name'], $r['code'], $r['nama'] ?: $r['jenis'], $r['serial'], $r['package_code'], (int)$r['qty'], $r['cond_label'], $r['note'],
                $r['resolution_label'] ?: 'BELUM', $r['resolution_ref'], $f($r['resolution_value']), $r['resolution_note'], $r['resolved_by_name'], $r['resolved_at']];
        }
        $x->addSheet('Selisih Penerimaan', $rows, [16, 17, 18, 18, 22, 32, 10, 12, 6, 20, 28, 26, 18, 12, 30, 16, 17]);
        $name = "selisih-$from-$to";
        break;

    case 'recap':
        $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
        $rows = [['Cabang Tujuan', 'Jenis', 'Pengiriman', 'Koli', 'Barang (unit)', 'Berat (kg)', 'Diterima Lengkap', 'Bermasalah / Sebagian', 'Dalam Perjalanan']];
        foreach (report_recap($month, $scope) as $r) {
            $rows[] = [$r['branch'], $r['kind'] === 'retur' ? 'Retur' : 'Kirim', (int)$r['shipments'], (int)$r['koli'], (int)$r['items'], $f($r['weight']), (int)$r['ok'], (int)$r['issue'], (int)$r['transit']];
        }
        $x->addSheet('Rekap ' . $month, $rows, [24, 8, 11, 7, 12, 10, 15, 20, 16]);
        $name = "rekap-$month";
        break;

    case 'shipment':
        $s = find_shipment($_GET['code'] ?? '');
        if (!$s) { http_response_code(404); exit('Surat jalan tidak ditemukan.'); }
        $d = shipment_detail($s);
        $koli = [['Koli', 'Kode Paket', 'Jenis', 'Nama', 'Barang', 'Berat (kg)', 'P (cm)', 'L (cm)', 'T (cm)', 'No. Segel', 'Dimuat', 'Tiba', 'Segel Saat Tiba']];
        foreach ($d['packages'] as $p) {
            $a = $p['arrival'];
            $koli[] = [(int)$p['koli_no'], $p['code'], $p['type_label'], $p['name'], $p['total_items'], $f($p['weight_kg']), $f($p['length_cm']), $f($p['width_cm']), $f($p['height_cm']), $p['seal_no'], $p['loaded_at'],
                $a['arrived_at'] ?? null, $a ? ($a['seal_ok'] === null ? 'Tidak dicek' : ((int)$a['seal_ok'] ? 'Utuh' : 'Rusak')) : null];
        }
        $isi = [['Koli', 'Paket', 'Kode Barang', 'Nama Item', 'SN', 'Qty', 'Satuan', 'Kelengkapan']];
        foreach (shipment_expected_items((int)$s['id']) as $i) {
            $isi[] = [(int)$i['koli_no'], $i['package_code'], $i['code'], $i['nama'] ?: $i['jenis'], $i['serial'], (int)$i['qty'], $i['unit'], $i['accessories']];
        }
        $x->addSheet('Koli', $koli, [6, 12, 10, 18, 8, 9, 7, 7, 7, 12, 17, 17, 14]);
        $x->addSheet('Isi', $isi, [6, 12, 22, 34, 10, 6, 8, 24]);
        if ($d['receipts']) {
            $rec = [['Kode Barang', 'Nama Item', 'Paket', 'Qty', 'Kondisi', 'Catatan', 'Waktu Scan']];
            foreach ($d['receipts'] as $r) $rec[] = [$r['code'], $r['nama'] ?: $r['jenis'], $r['package_code'], (int)$r['qty'], RECEIPT_CONDITIONS[$r['cond']] ?? $r['cond'], $r['note'], $r['scanned_at']];
            $x->addSheet('Penerimaan', $rec, [22, 34, 12, 6, 20, 32, 17]);
        }
        $name = 'surat-jalan-' . $s['code'];
        break;

    default:
        http_response_code(400);
        exit('Jenis ekspor tidak dikenal.');
}

$x->download(preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '.xlsx');
