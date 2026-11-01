<?php
// Penerimaan di cabang tujuan & Berita Acara Serah Terima (BAST).

function load_receivable(): array
{
    $s = find_shipment(param('shipment'));
    if (!$s) fail('Surat jalan tidak ditemukan.', 404);
    if ($s['status'] !== 'in_transit') fail('Surat jalan ' . strtolower(SHIPMENT_STATUS[$s['status']] ?? $s['status']) . ', tidak dalam tahap penerimaan.', 423);
    if (!can_receive($s)) fail('Hanya petugas ' . branch_name((int)$s['dest_branch_id']) . ' yang dapat menerima pengiriman ini.', 403);
    return $s;
}

function mark_arrival(array $s, array $pkg, ?int $sealOk, ?string $sealNo, ?string $note): void
{
    db()->prepare("INSERT INTO package_arrivals (shipment_id, package_id, seal_ok, seal_no_checked, note, user_id) VALUES (?,?,?,?,?,?)
                   ON CONFLICT(shipment_id, package_id) DO UPDATE SET seal_ok = COALESCE(excluded.seal_ok, seal_ok), seal_no_checked = COALESCE(excluded.seal_no_checked, seal_no_checked), note = COALESCE(excluded.note, note)")
        ->execute([$s['id'], $pkg['id'], $sealOk, $sealNo, $note, current_user_id()]);
}

function expected_map(int $shipmentId): array
{
    // Barang qty yang sama di beberapa koli digabung per kode
    $m = [];
    foreach (shipment_expected_items($shipmentId) as $e) {
        $k = strtolower($e["code"]);
        if (isset($m[$k])) $m[$k]["qty"] += $e["qty"]; else $m[$k] = $e;
    }
    return array_values($m);
}

function find_expected(array $expected, string $code): ?array
{
    foreach ($expected as $e) if (strcasecmp($e['code'], $code) === 0) return $e;
    return null;
}

switch ($action) {
    case 'receive_package':
        $s = load_receivable();
        $pkg = find_package(param('code'));
        if (!$pkg) fail('Kode paket tidak ditemukan.', 404);
        $root = package_root($pkg);
        if ((int)$root['shipment_id'] !== (int)$s['id']) fail("{$pkg['code']} bukan bagian surat jalan ini.", 409, ['wrong' => true]);
        $sealOk = param('seal_ok', '') === '' ? null : (param('seal_ok') ? 1 : 0);
        mark_arrival($s, $root, $sealOk, param('seal_no_checked') ?: null, param('note') ?: null);
        log_activity('arrive', $root['code'], null, "Koli {$root['koli_no']} ({$root['code']}) tiba" . ($sealOk === 0 ? ' — SEGEL RUSAK' : ''), $s['code']);
        out(['ok' => true, 'package_code' => $root['code']] + shipment_detail(find_shipment($s['code'])));

    case 'receive_item':
        $s = load_receivable();
        $code = param('code');
        if ($code === '') fail('Kode kosong.');
        $pdo = db();

        // Kode paket -> catat kedatangan koli
        if ($pkg = find_package($code)) {
            $root = package_root($pkg);
            if ((int)$root['shipment_id'] !== (int)$s['id']) fail("Paket {$pkg['code']} bukan bagian surat jalan ini.", 409, ['wrong' => true]);
            out(['ok' => true, 'kind' => 'package', 'package' => package_info($root), 'arrived' => (bool)$pdo->query('SELECT 1 FROM package_arrivals WHERE shipment_id = ' . (int)$s['id'] . ' AND package_id = ' . (int)$root['id'])->fetchColumn()]);
        }

        $st = $pdo->prepare('SELECT * FROM receipt_items WHERE shipment_id = ? AND code = ?');
        $st->execute([$s['id'], $code]);
        if ($dup = $st->fetch()) fail("$code sudah discan (" . RECEIPT_CONDITIONS[$dup['cond']] . ').', 409, ['duplicate' => true]);

        $expected = find_expected(expected_map((int)$s['id']), $code);
        $cond = isset(RECEIPT_CONDITIONS[param('cond')]) ? param('cond') : 'ok';
        if ($expected) {
            if ($expected['track'] === 'qty' && param('qty', '') === '') fail("Masukkan jumlah " . (!empty($expected['is_manual']) ? $expected['nama'] : $expected['code']) . " yang diterima.", 409, ['need_qty' => true, 'item' => $expected, 'expected_qty' => (int)$expected['qty']]);
            $qty = $expected['track'] === 'qty' ? max(0, (int)param('qty')) : 1;
            $koli = find_package($expected['koli_code']);
            mark_arrival($s, $koli, null, null, null);
            $pdo->prepare('INSERT INTO receipt_items (shipment_id, package_id, item_id, code, qty, cond, note, user_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$s['id'], $expected['package_id'], $expected['item_id'], $expected['code'], $qty, $cond, param('note') ?: null, current_user_id()]);
            $short = $expected['track'] === 'qty' && $qty < (int)$expected['qty'];
            out(['ok' => true, 'kind' => 'item', 'expected' => true, 'item' => $expected, 'qty_short' => $short] + shipment_detail(find_shipment($s['code'])));
        }

        // Tidak tercatat di surat jalan
        $item = find_item($code);
        if (!$item) fail("Kode \"$code\" tidak terdaftar di database.", 404, ['unknown' => true]);
        $pdo->prepare("INSERT INTO receipt_items (shipment_id, item_id, code, qty, cond, note, user_id) VALUES (?,?,?,?, 'lebih', ?, ?)")
            ->execute([$s['id'], $item['id'], $item['code'], max(1, (int)param('qty', 1)), param('note') ?: 'Tidak tercatat di surat jalan', current_user_id()]);
        log_activity('receive_extra', null, $item['code'], "{$item['code']} diterima tapi tidak tercatat di surat jalan", $s['code']);
        out(['ok' => true, 'kind' => 'item', 'expected' => false, 'item' => $item] + shipment_detail(find_shipment($s['code'])));

    case 'receive_item_update':
        $s = load_receivable();
        $cond = param('cond');
        if (!isset(RECEIPT_CONDITIONS[$cond]) || $cond === 'kurang') fail('Kondisi tidak valid.');
        $st = db()->prepare('SELECT * FROM receipt_items WHERE shipment_id = ? AND code = ?');
        $st->execute([$s['id'], param('code')]);
        $r = $st->fetch() ?: fail('Barang belum discan.', 404);
        if ($r['cond'] === 'lebih') $cond = 'lebih';
        $qty = param('qty', '') === '' ? (int)$r['qty'] : max(0, (int)param('qty'));
        db()->prepare('UPDATE receipt_items SET cond = ?, note = ?, qty = ? WHERE id = ?')->execute([$cond, param('note') ?: null, $qty, $r['id']]);
        out(shipment_detail(find_shipment($s['code'])));

    case 'receive_item_delete':
        $s = load_receivable();
        db()->prepare('DELETE FROM receipt_items WHERE shipment_id = ? AND code = ?')->execute([$s['id'], param('code')]);
        out(shipment_detail(find_shipment($s['code'])));

    case 'receive_finish':
        $s = load_receivable();
        $pdo = db();
        $receiver = param('receiver_name');
        if ($receiver === '') fail('Nama penerima wajib diisi.');
        $sig = (string)param('signature');
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $sig, $m)) fail('Tanda tangan penerima wajib diisi.');
        $png = base64_decode($m[1]);
        if (!$png || strlen($png) > 2000000 || @imagecreatefromstring($png) === false) fail('Tanda tangan tidak valid.');

        $dir = data_dir() . '/uploads/signatures';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $sigPath = 'signatures/' . preg_replace('/[^A-Za-z0-9-]/', '', $s['code']) . '-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents(data_dir() . '/uploads/' . $sigPath, $png);

        $dest = (int)$s['dest_branch_id'];
        $destName = branch_name($dest);
        $expected = expected_map((int)$s['id']);

        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM receipt_items WHERE shipment_id = ?');
        $st->execute([$s['id']]);
        $received = [];
        foreach ($st->fetchAll() as $r) $received[strtolower($r['code'])] = $r;

        // Mode per koli: isi koli yang tiba (dan belum discan) dicatat diterima baik
        $mode = param('mode') === 'koli' ? 'koli' : 'item';
        if ($mode === 'koli') {
            $arrivedIds = array_map('intval', $pdo->query('SELECT package_id FROM package_arrivals WHERE shipment_id = ' . (int)$s['id'])->fetchAll(PDO::FETCH_COLUMN));
            $insOk = $pdo->prepare("INSERT INTO receipt_items (shipment_id, package_id, item_id, code, qty, cond, note, user_id) VALUES (?,?,?,?,?, 'ok', 'Diterima per koli (isi tidak discan)', ?)");
            foreach ($expected as $e) {
                $k = strtolower($e['code']);
                if (isset($received[$k])) continue;
                $koli = find_package($e['koli_code']);
                if (!$koli || !in_array((int)$koli['id'], $arrivedIds, true)) continue;
                $insOk->execute([$s['id'], $e['package_id'], $e['item_id'], $e['code'], $e['qty'], current_user_id()]);
                $received[$k] = ['cond' => 'ok', 'item_id' => $e['item_id'], 'qty' => $e['qty'], 'code' => $e['code']];
            }
        }

        // Barang yang tidak discan dianggap kurang
        $insMissing = $pdo->prepare("INSERT INTO receipt_items (shipment_id, package_id, item_id, code, qty, cond, note, user_id) VALUES (?,?,?,?,?, 'kurang', 'Tidak ditemukan saat penerimaan', ?)");
        foreach ($expected as $e) {
            if (!isset($received[strtolower($e['code'])])) {
                $insMissing->execute([$s['id'], $e['package_id'], $e['item_id'], $e['code'], $e['qty'], current_user_id()]);
                $received[strtolower($e['code'])] = ['cond' => 'kurang', 'item_id' => $e['item_id'], 'qty' => $e['qty'], 'code' => $e['code']];
            }
        }

        $hasMissing = $hasProblem = false;
        foreach ($received as $r) {
            $itemId = (int)$r['item_id'];
            $exp = find_expected($expected, $r['code']);
            if (!$itemId) {
                // Barang manual (non-inventaris): hanya menentukan status, tanpa stok / lokasi / riwayat barang
                if ($r['cond'] === 'kurang') $hasMissing = true;
                elseif ($r['cond'] !== 'ok') $hasProblem = true;
                if ($exp && $r['cond'] !== 'kurang' && (int)$r['qty'] < (int)$exp['qty']) {
                    $hasMissing = true;
                    $short = (int)$exp['qty'] - (int)$r['qty'];
                    $pdo->prepare("UPDATE receipt_items SET short_qty = ?, note = TRIM(COALESCE(note, '') || ' Kurang ' || ? || ' ' || ? || ' dari ' || ?) WHERE shipment_id = ? AND code = ?")
                        ->execute([$short, $short, $exp['unit'], $exp['qty'], $s['id'], $r['code']]);
                }
                continue;
            }
            $item = $pdo->query('SELECT * FROM items WHERE id = ' . $itemId)->fetch();
            if ($r['cond'] === 'kurang') {
                $hasMissing = true;
                if ($item['track'] === 'serial') $pdo->prepare("UPDATE items SET status = 'hilang' WHERE id = ?")->execute([$itemId]);
                item_event($itemId, 'missing', "Tidak diterima di $destName (" . ($r['qty'] ?? 1) . ' ' . $item['unit'] . ')', $exp['package_code'] ?? null, $s['code'], $dest);
                continue;
            }
            if ($r['cond'] !== 'ok') $hasProblem = true;
            if ($exp && $item['track'] === 'qty' && (int)$r['qty'] < (int)$exp['qty']) {
                $hasMissing = true;
                $short = (int)$exp['qty'] - (int)$r['qty'];
                $pdo->prepare("UPDATE receipt_items SET short_qty = ?, note = TRIM(COALESCE(note, '') || ' Kurang ' || ? || ' ' || ? || ' dari ' || ?) WHERE shipment_id = ? AND code = ?")
                    ->execute([$short, $short, $item['unit'], $exp['qty'], $s['id'], $r['code']]);
            }
            if ($item['track'] === 'serial') {
                $cond = $r['cond'] === 'rusak' ? 'rusak' : ($item['cond'] === 'baru' ? 'baru' : $item['cond']);
                $pdo->prepare("UPDATE items SET status = 'ada', location_branch_id = ?, cond = ? WHERE id = ?")->execute([$dest, $cond, $itemId]);
            }
            item_event($itemId, 'received', "Diterima di $destName — " . RECEIPT_CONDITIONS[$r['cond']] . ($item['track'] === 'qty' ? " ({$r['qty']} {$item['unit']})" : ''), $exp['package_code'] ?? null, $s['code'], $dest);
        }

        $packages = shipment_packages((int)$s['id']);
        $arrivals = $pdo->query('SELECT package_id, seal_ok FROM package_arrivals WHERE shipment_id = ' . (int)$s['id'])->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($packages as $p) {
            if (!array_key_exists($p['id'], $arrivals)) $hasMissing = true;
            elseif ($arrivals[$p['id']] !== null && (int)$arrivals[$p['id']] === 0) $hasProblem = true;
            set_package_stage((int)$p['id'], 'received');
        }

        $status = $hasMissing ? 'partial' : ($hasProblem ? 'problem' : 'received');
        $pdo->prepare("UPDATE shipments SET status = ?, receive_result = ?, received_at = datetime('now','localtime'), received_by = ?, receiver_name = ?, receive_notes = ?, signature_path = ?, receive_mode = ? WHERE id = ?")
            ->execute([$status, $status, current_user_id(), $receiver, param('notes') ?: null, $sigPath, $mode, $s['id']]);
        log_activity('received', null, null, "Surat jalan {$s['code']} diterima oleh $receiver (" . ($mode === 'koli' ? 'per koli' : 'per barang') . ') — ' . SHIPMENT_STATUS[$status], $s['code']);
        // Barang yang dulu tercatat kurang di surat jalan lain dan kini tiba -> dikirim susulan
        $autoResolved = auto_resolve_resent(find_shipment($s['code']));
        $pdo->commit();
        $d = shipment_detail(find_shipment($s['code']));
        $d['auto_resolved'] = $autoResolved;
        out($d);
}
