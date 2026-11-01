<?php
// Pengiriman / surat jalan: koli, scan muat, keberangkatan.

function shipment_fields(): array
{
    return [
        'mode' => param('mode') === 'ekspedisi' ? 'ekspedisi' : 'internal',
        'vehicle' => param('vehicle'), 'plate' => strtoupper(param('plate')), 'driver' => param('driver'), 'driver_phone' => param('driver_phone'),
        'courier' => param('courier'), 'tracking_no' => param('tracking_no'),
        'depart_plan' => param('depart_plan') ?: null, 'eta' => param('eta') ?: null,
        'reason' => param('reason'), 'notes' => param('notes'),
    ];
}

function load_shipment(): array
{
    $s = find_shipment(param('shipment') ?: param('code'));
    if (!$s) fail('Surat jalan tidak ditemukan.', 404);
    $scope = branch_scope();
    if ($scope && (int)$s['origin_branch_id'] !== $scope && (int)$s['dest_branch_id'] !== $scope) fail('Surat jalan ini bukan untuk cabang Anda.', 403);
    return $s;
}

function require_draft(array $s): void
{
    if ($s['status'] !== 'draft') fail('Surat jalan sudah ' . strtolower(SHIPMENT_STATUS[$s['status']]) . ', tidak bisa diubah.', 423);
}

switch ($action) {
    case 'shipments':
        $q = '%' . param('q') . '%';
        $status = param('status', 'all');
        $sql = "SELECT s.*, o.name AS origin_name, d.name AS dest_name,
                    (SELECT COUNT(*) FROM packages WHERE shipment_id = s.id) AS koli
                FROM shipments s JOIN branches o ON o.id = s.origin_branch_id JOIN branches d ON d.id = s.dest_branch_id
                WHERE (s.code LIKE :q OR s.tracking_no LIKE :q OR s.plate LIKE :q OR s.driver LIKE :q OR d.name LIKE :q)";
        $args = [':q' => $q];
        if ($status === 'open') $sql .= " AND s.status IN ('draft','in_transit')";
        elseif ($status === 'done') $sql .= " AND s.status IN ('received','partial','problem','resolved')";
        elseif (isset(SHIPMENT_STATUS[$status])) { $sql .= ' AND s.status = :st'; $args[':st'] = $status; }
        if (param('incoming')) { $sql .= ' AND s.dest_branch_id = :me'; $args[':me'] = user_branch_id(); }
        if ($scope = branch_scope()) { $sql .= ' AND (s.origin_branch_id = :sc OR s.dest_branch_id = :sc)'; $args[':sc'] = $scope; }
        $st = db()->prepare($sql . ' ORDER BY s.id DESC LIMIT 300');
        $st->execute($args);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) $r['status_label'] = SHIPMENT_STATUS[$r['status']] ?? $r['status'];
        out(['shipments' => $rows]);

    case 'shipment_create':
        need_role('gudang', 'cabang');
        $kind = param('kind') === 'retur' ? 'retur' : 'kirim';
        $origin = (int)param('origin_branch_id', 0) ?: user_branch_id();
        if (branch_scope()) $origin = branch_scope();
        $dest = (int)param('dest_branch_id', 0);
        if (!$dest || !branch_by_id($dest)) fail('Pilih cabang tujuan.');
        if ($dest === $origin) fail('Cabang tujuan tidak boleh sama dengan asal.');
        if ($kind === 'retur' && param('reason') === '') fail('Alasan retur wajib diisi.');
        $code = next_code('shipments', ($kind === 'retur' ? 'RTR' : 'SJ') . '-' . date('ym'));
        $f = shipment_fields();
        db()->prepare('INSERT INTO shipments (code, kind, origin_branch_id, dest_branch_id, mode, vehicle, plate, driver, driver_phone, courier, tracking_no, depart_plan, eta, reason, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge([$code, $kind, $origin, $dest], array_values($f), [current_user_id()]));
        log_activity('shipment_new', null, null, ($kind === 'retur' ? 'Retur ' : 'Surat jalan ') . "$code dibuat ke " . branch_name($dest), $code);
        out(shipment_detail(find_shipment($code)));

    case 'shipment_update':
        need_role('gudang', 'cabang', 'kurir');
        $s = load_shipment();
        if (!in_array($s['status'], ['draft', 'in_transit'], true)) fail('Surat jalan sudah diterima, tidak bisa diubah.', 423);
        $f = shipment_fields();
        $dest = (int)param('dest_branch_id', 0) ?: (int)$s['dest_branch_id'];
        if ($s['status'] !== 'draft') $dest = (int)$s['dest_branch_id'];
        if ($dest === (int)$s['origin_branch_id']) fail('Cabang tujuan tidak boleh sama dengan asal.');
        db()->prepare('UPDATE shipments SET dest_branch_id=?, mode=?, vehicle=?, plate=?, driver=?, driver_phone=?, courier=?, tracking_no=?, depart_plan=?, eta=?, reason=?, notes=? WHERE id=?')
            ->execute(array_merge([$dest], array_values($f), [$s['id']]));
        log_activity('shipment_update', null, null, "Data surat jalan {$s['code']} diperbarui", $s['code']);
        out(shipment_detail(find_shipment($s['code'])));

    case 'shipment_delete':
        need_role('gudang', 'cabang');
        $s = load_shipment();
        require_draft($s);
        db()->prepare("UPDATE packages SET shipment_id = NULL, koli_no = NULL, loaded_at = NULL, stage = 'sealed' WHERE shipment_id = ?")->execute([$s['id']]);
        db()->prepare('DELETE FROM shipments WHERE id = ?')->execute([$s['id']]);
        log_activity('shipment_delete', null, null, "Surat jalan {$s['code']} dihapus", $s['code']);
        out(['ok' => true]);

    case 'shipment':
        $s = load_shipment();
        $d = shipment_detail($s);
        if (param('expected')) $d['expected'] = shipment_expected_items((int)$s['id']);
        $d['can_receive'] = $s['status'] === 'in_transit' && can_receive($s);
        $d['can_resolve'] = can_resolve($s);
        out($d);

    case 'shipment_add_package':
        need_role('gudang', 'cabang');
        $s = load_shipment();
        require_draft($s);
        $pkg = find_package(param('code'));
        if (!$pkg) fail('Kode "' . param('code') . '" bukan kode paket.', 404);
        if ((int)$pkg['shipment_id'] === (int)$s['id']) fail("{$pkg['code']} sudah ada di surat jalan ini.", 409, ['duplicate' => true]);
        if ($pkg['shipment_id']) fail("{$pkg['code']} sudah terdaftar di surat jalan lain.", 409);
        if ($pkg['parent_id']) {
            $root = package_root($pkg);
            fail("{$pkg['code']} berada di dalam {$root['code']}. Masukkan paket terluarnya ({$root['code']}).", 409);
        }
        if ($pkg['stage'] !== 'sealed') fail("{$pkg['code']} belum disegel. Segel paket sebelum dimasukkan ke surat jalan.", 409);
        if ((int)$pkg['branch_id'] !== (int)$s['origin_branch_id']) fail("{$pkg['code']} dipacking di " . branch_name((int)$pkg['branch_id']) . ', bukan di ' . branch_name((int)$s['origin_branch_id']) . '.', 409);
        if (package_total_items((int)$pkg['id']) === 0 && !param('allow_empty')) fail("{$pkg['code']} kosong.", 409, ['empty' => true]);
        $koli = (int)db()->query('SELECT COALESCE(MAX(koli_no),0) FROM packages WHERE shipment_id = ' . (int)$s['id'])->fetchColumn() + 1;
        db()->prepare('UPDATE packages SET shipment_id = ?, koli_no = ? WHERE id = ?')->execute([$s['id'], $koli, $pkg['id']]);
        log_activity('shipment_add', $pkg['code'], null, "Koli $koli: {$pkg['code']} masuk surat jalan {$s['code']}", $s['code']);
        out(['ok' => true, 'package_code' => $pkg['code']] + shipment_detail(find_shipment($s['code'])));

    case 'shipment_remove_package':
        need_role('gudang', 'cabang');
        $s = load_shipment();
        require_draft($s);
        $pkg = find_package(param('code'));
        if (!$pkg || (int)$pkg['shipment_id'] !== (int)$s['id']) fail('Paket tidak ada di surat jalan ini.', 404);
        db()->prepare("UPDATE packages SET shipment_id = NULL, koli_no = NULL, loaded_at = NULL WHERE id = ?")->execute([$pkg['id']]);
        set_package_stage((int)$pkg['id'], 'sealed');
        renumber_koli((int)$s['id']);
        log_activity('shipment_remove', $pkg['code'], null, "{$pkg['code']} dikeluarkan dari surat jalan {$s['code']}", $s['code']);
        out(shipment_detail(find_shipment($s['code'])));

    case 'shipment_load':
        // Scan muat: memastikan setiap koli benar-benar naik ke kendaraan
        need_role('gudang', 'cabang', 'kurir');
        $s = load_shipment();
        require_draft($s);
        $pkg = find_package(param('code'));
        if (!$pkg) fail('Kode "' . param('code') . '" bukan kode paket.', 404);
        if ($pkg['parent_id']) {
            $root = package_root($pkg);
            fail("{$pkg['code']} ada di dalam {$root['code']}. Scan label {$root['code']}.", 409);
        }
        if ((int)$pkg['shipment_id'] !== (int)$s['id']) fail("{$pkg['code']} BUKAN bagian surat jalan ini! Jangan dimuat.", 409, ['wrong' => true]);
        if ($pkg['loaded_at']) fail("Koli {$pkg['koli_no']} ({$pkg['code']}) sudah discan muat.", 409, ['duplicate' => true]);
        db()->prepare("UPDATE packages SET loaded_at = datetime('now','localtime') WHERE id = ?")->execute([$pkg['id']]);
        set_package_stage((int)$pkg['id'], 'loaded');
        log_activity('load', $pkg['code'], null, "Koli {$pkg['koli_no']} ({$pkg['code']}) dimuat", $s['code']);
        out(['ok' => true, 'package_code' => $pkg['code'], 'koli_no' => $pkg['koli_no']] + shipment_detail(find_shipment($s['code'])));

    case 'shipment_unload':
        need_role('gudang', 'cabang', 'kurir');
        $s = load_shipment();
        require_draft($s);
        $pkg = find_package(param('code'));
        if (!$pkg || (int)$pkg['shipment_id'] !== (int)$s['id']) fail('Paket tidak ada di surat jalan ini.', 404);
        db()->prepare('UPDATE packages SET loaded_at = NULL WHERE id = ?')->execute([$pkg['id']]);
        set_package_stage((int)$pkg['id'], 'sealed');
        out(shipment_detail(find_shipment($s['code'])));

    case 'shipment_depart':
        need_role('gudang', 'cabang', 'kurir');
        $pdo = db();
        $s = load_shipment();
        require_draft($s);
        $packages = shipment_packages((int)$s['id']);
        if (!$packages) fail('Surat jalan belum berisi koli.');
        $notLoaded = array_filter($packages, function ($p) { return !$p['loaded_at']; });
        if ($notLoaded && !param('force')) {
            fail(count($notLoaded) . ' koli belum discan muat: ' . implode(', ', array_column($notLoaded, 'code')), 409, ['not_loaded' => array_column($notLoaded, 'code')]);
        }
        if ($s['mode'] === 'internal' && ($s['driver'] === '' || $s['driver'] === null)) fail('Isi nama sopir terlebih dahulu.');
        if ($s['mode'] === 'ekspedisi' && ($s['courier'] === '' || $s['courier'] === null)) fail('Isi nama ekspedisi terlebih dahulu.');

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE shipments SET status = 'in_transit', departed_at = datetime('now','localtime'), departed_by = ? WHERE id = ?")->execute([current_user_id(), $s['id']]);
        $dest = branch_name((int)$s['dest_branch_id']);
        foreach ($packages as $p) {
            set_package_stage((int)$p['id'], 'in_transit');
            foreach (package_all_items((int)$p['id']) as $i) {
                if ($i['track'] === 'qty') {
                    $pdo->prepare('UPDATE items SET stock = MAX(0, stock - ?) WHERE id = ?')->execute([$i['qty'], $i['item_id']]);
                    item_event((int)$i['item_id'], 'shipped', "Dikirim {$i['qty']} {$i['unit']} ke $dest", $i['package_code'], $s['code'], (int)$s['origin_branch_id']);
                } else {
                    $pdo->prepare("UPDATE items SET status = 'dikirim' WHERE id = ?")->execute([$i['item_id']]);
                    item_event((int)$i['item_id'], 'shipped', "Dikirim ke $dest", $i['package_code'], $s['code'], (int)$s['origin_branch_id']);
                }
            }
        }
        log_activity('depart', null, null, "Surat jalan {$s['code']} berangkat ke $dest" . ($notLoaded ? ' (' . count($notLoaded) . ' koli tanpa scan muat)' : ''), $s['code']);
        $pdo->commit();
        out(shipment_detail(find_shipment($s['code'])));
}
