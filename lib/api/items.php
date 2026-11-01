<?php
// Database barang (format standar pergudangan) & master kategori.

require_once __DIR__ . '/../items_lib.php';

switch ($action) {
    case 'items':
        $q = '%' . param('q') . '%';
        $status = param('status', 'all');
        $sql = "SELECT i.*, b.name AS location_name, c.name AS category_name, c.code AS category_code,
                    (SELECT p.code FROM package_items pi JOIN packages p ON p.id = pi.package_id WHERE pi.item_id = i.id AND p.stage <> 'received' ORDER BY pi.id DESC LIMIT 1) AS package_code,
                    (SELECT COALESCE(SUM(pi.qty),0) FROM package_items pi JOIN packages p ON p.id = pi.package_id WHERE pi.item_id = i.id AND p.stage <> 'received') AS packed_qty
                FROM items i LEFT JOIN branches b ON b.id = i.location_branch_id LEFT JOIN categories c ON c.id = i.category_id
                WHERE (i.code LIKE :q OR i.nama LIKE :q OR i.merk LIKE :q OR i.model LIKE :q OR i.serial LIKE :q OR i.asset_no LIKE :q
                       OR i.spec LIKE :q OR i.bin_location LIKE :q OR i.supplier LIKE :q OR i.po_number LIKE :q)";
        $args = [':q' => $q];
        if ($loc = (int)param('location', 0)) { $sql .= ' AND i.location_branch_id = :loc'; $args[':loc'] = $loc; }
        if ($cat = (int)param('category', 0)) { $sql .= ' AND i.category_id = :cat'; $args[':cat'] = $cat; }
        if (in_array($status, ['ada', 'dikirim', 'hilang', 'dihapus'], true)) { $sql .= ' AND i.status = :st'; $args[':st'] = $status; }
        if ($status === 'lowstock') $sql .= " AND i.track = 'qty' AND i.min_stock > 0 AND i.stock <= i.min_stock";
        if ($status === 'rusak') $sql .= " AND i.cond = 'rusak'";
        $sql = "SELECT * FROM ($sql) x";
        if ($status === 'packed') $sql .= ' WHERE packed_qty > 0';
        if ($status === 'unpacked') $sql .= " WHERE packed_qty = 0 AND status = 'ada'";
        $st = db()->prepare($sql . ' ORDER BY category_code, code LIMIT 2000');
        $st->execute($args);
        out(['items' => $st->fetchAll()]);

    case 'item_save':
        need_role('gudang');
        $pdo = db();
        $id = (int)param('id', 0);
        $old = $id ? ($pdo->query('SELECT * FROM items WHERE id = ' . $id)->fetch() ?: fail('Barang tidak ditemukan.', 404)) : null;
        try {
            $f = normalize_item(input(), $old);
            if ($old) {
                check_item_duplicates($f, $id);
                $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($f)));
                $pdo->prepare("UPDATE items SET $sets, updated_at = datetime('now','localtime') WHERE id = ?")->execute(array_merge(array_values($f), [$id]));
                if ((int)$old['location_branch_id'] !== $f['location_branch_id'] || $old['bin_location'] !== $f['bin_location']) {
                    item_event($id, 'location', 'Lokasi diubah ke ' . branch_name($f['location_branch_id']) . ($f['bin_location'] ? ' rak ' . $f['bin_location'] : ''), null, null, $f['location_branch_id']);
                }
                if ($old['cond'] !== $f['cond']) item_event($id, 'condition', 'Kondisi diubah menjadi ' . ITEM_CONDITIONS[$f['cond']], null, null, $f['location_branch_id']);
                if ($old['track'] === 'qty' && (int)$old['stock'] !== $f['stock']) item_event($id, 'stock', "Stok disesuaikan {$old['stock']} → {$f['stock']} {$f['unit']}", null, null, $f['location_branch_id']);
                if (param('restore') && in_array($old['status'], ['hilang', 'dihapus'], true)) {
                    need_role('admin');
                    $pdo->prepare("UPDATE items SET status = 'ada' WHERE id = ?")->execute([$id]);
                    item_event($id, 'found', 'Ditemukan kembali di ' . branch_name($f['location_branch_id']) . ' (status dipulihkan admin)', null, null, $f['location_branch_id']);
                }
                out(['ok' => true, 'codes' => [$old['code']]]);
            }

            // Barang baru. Per unit: boleh banyak unit sekaligus (units = [{serial, asset_no}, ...])
            $units = param('units', []);
            if ($f['track'] === 'qty' || !is_array($units) || count($units) <= 1) $units = [['serial' => $f['serial'], 'asset_no' => $f['asset_no']]];
            if (count($units) > 200) fail('Maksimal 200 unit sekali simpan.');
            $pdo->beginTransaction();
            $codes = [];
            foreach ($units as $n => $u) {
                $row = $f;
                $row['serial'] = trim((string)($u['serial'] ?? ''));
                $row['asset_no'] = strtoupper(trim((string)($u['asset_no'] ?? '')));
                try {
                    $codes[] = create_item($row, 'input', count($units) === 1 ? (string)param('code') : '');
                } catch (InvalidArgumentException $e) {
                    fail((count($units) > 1 ? 'Unit ke-' . ($n + 1) . ': ' : '') . $e->getMessage());
                }
            }
            log_activity('item_new', null, $codes[0], count($codes) > 1 ? count($codes) . " barang baru {$codes[0]} s/d " . end($codes) : "Barang baru {$codes[0]}");
            $pdo->commit();
            out(['ok' => true, 'codes' => $codes]);
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }

    case 'item_delete':
        need_role('admin');
        $id = (int)param('id');
        if (packed_qty($id) > 0) fail('Barang masih ada di dalam paket. Keluarkan dulu dari paket.');
        $used = (int)db()->query('SELECT COUNT(*) FROM receipt_items WHERE item_id = ' . $id)->fetchColumn();
        if ($used) fail('Barang sudah pernah dikirim (tercatat di surat jalan), tidak bisa dihapus. Ubah statusnya bila perlu.');
        db()->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
        out(['ok' => true]);

    case 'import':
        need_role('gudang');
        if (empty($_FILES['file']['tmp_name'])) fail('Pilih file Excel (.xlsx) hasil isian template.');
        out(['ok' => true] + import_standard_xlsx($_FILES['file']['tmp_name'], (bool)param('code_from_notes')));

    case 'item_history':
        $item = find_item(param('code')) ?: fail('Barang tidak ditemukan.', 404);
        $st = db()->prepare('SELECT e.*, u.name AS user_name, b.name AS branch_name FROM item_events e LEFT JOIN users u ON u.id = e.user_id LEFT JOIN branches b ON b.id = e.branch_id WHERE e.item_id = ? ORDER BY e.id DESC');
        $st->execute([$item['id']]);
        $item['location_name'] = branch_name($item['location_branch_id'] ? (int)$item['location_branch_id'] : null);
        $cat = $item['category_id'] ? find_category($item['category_id']) : null;
        $item['category_name'] = $cat['name'] ?? $item['jenis'];
        $item['category_code'] = $cat['code'] ?? '';
        $item['packed_qty'] = packed_qty((int)$item['id']);
        out(['item' => $item, 'location' => item_location($item), 'events' => $st->fetchAll()]);

    case 'lookup':
        $code = param('code');
        if ($item = find_item($code)) out(['kind' => 'item', 'item' => $item, 'location' => item_location($item)]);
        if ($pkg = find_package($code)) out(['kind' => 'package'] + package_detail($pkg));
        if ($s = find_shipment($code)) out(['kind' => 'shipment', 'code' => $s['code']]);
        fail("Kode \"$code\" tidak ditemukan.", 404, ['unknown' => true]);

    // ---------- Master kategori ----------
    case 'categories':
        out(['categories' => db()->query('SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) AS item_count FROM categories c ORDER BY c.active DESC, c.name')->fetchAll()]);

    case 'category_save':
        need_role('gudang');
        $pdo = db();
        $id = (int)param('id', 0);
        $code = strtoupper(param('code'));
        $name = param('name');
        if (!preg_match('/^[A-Z0-9]{2,5}$/', $code)) fail('Kode kategori 2-5 huruf/angka, mis. NET, PRN, KBL.');
        if ($name === '') fail('Nama kategori wajib diisi.');
        $st = $pdo->prepare('SELECT id FROM categories WHERE code = ? AND id <> ?');
        $st->execute([$code, $id]);
        if ($st->fetchColumn()) fail("Kode kategori $code sudah dipakai.");
        if ($id) {
            $old = $pdo->query('SELECT * FROM categories WHERE id = ' . $id)->fetch() ?: fail('Kategori tidak ditemukan.', 404);
            $count = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE category_id = ' . $id)->fetchColumn();
            if ($count && strcasecmp($old['code'], $code) !== 0) fail("Kode tidak bisa diubah karena sudah dipakai $count barang (kode barang & label QR memakai awalan {$old['code']}).");
            $pdo->prepare('UPDATE categories SET code = ?, name = ?, active = ? WHERE id = ?')->execute([$code, $name, param('active', true) ? 1 : 0, $id]);
            $pdo->prepare('UPDATE items SET jenis = ? WHERE category_id = ?')->execute([$name, $id]);
        } else {
            $pdo->prepare('INSERT INTO categories (code, name) VALUES (?,?)')->execute([$code, $name]);
        }
        out(['ok' => true]);
}
