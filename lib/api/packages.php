<?php
// Paket / packing.

function package_fields(): array
{
    $handling = param('handling', []);
    if (is_string($handling)) $handling = explode(',', $handling);
    $handling = array_values(array_intersect((array)$handling, array_keys(HANDLING)));
    return [
        'name' => param('name'), 'destination' => param('destination'), 'notes' => param('notes'),
        'weight_kg' => num_param('weight_kg'), 'length_cm' => num_param('length_cm'), 'width_cm' => num_param('width_cm'), 'height_cm' => num_param('height_cm'),
        'receiver_name' => param('receiver_name'), 'receiver_phone' => param('receiver_phone'), 'handling' => implode(',', $handling),
    ];
}

function require_editable(array $pkg): void
{
    if ($pkg['stage'] !== 'packing' && $pkg['stage'] !== 'sealed') fail("Paket {$pkg['code']} sudah " . strtolower(PACKAGE_STAGES[$pkg['stage']]) . ', tidak bisa diubah.', 423);
    if ($pkg['status'] === 'sealed') fail("Paket {$pkg['code']} sudah disegel. Buka segel terlebih dahulu.", 423);
}

function require_branch_access(array $pkg): void
{
    $scope = branch_scope();
    if ($scope && (int)$pkg['branch_id'] !== $scope) fail('Paket ini milik cabang lain.', 403);
}

switch ($action) {
    case 'packages':
        $q = '%' . param('q') . '%';
        $status = param('status', 'active');
        $sql = "SELECT p.*, pp.code AS parent_code, s.code AS shipment_code, b.name AS branch_name,
                    (SELECT COALESCE(SUM(qty),0) FROM package_items WHERE package_id = p.id) AS item_count,
                    (SELECT COUNT(*) FROM packages c WHERE c.parent_id = p.id) AS child_count
                FROM packages p LEFT JOIN packages pp ON pp.id = p.parent_id LEFT JOIN shipments s ON s.id = p.shipment_id
                LEFT JOIN branches b ON b.id = p.branch_id
                WHERE (p.code LIKE :q OR p.name LIKE :q OR p.destination LIKE :q OR p.seal_no LIKE :q)";
        $args = [':q' => $q];
        if ($status === 'active') $sql .= " AND p.stage <> 'received'";
        elseif ($status === 'open') $sql .= " AND p.status = 'open' AND p.stage = 'packing'";
        elseif ($status === 'sealed') $sql .= " AND p.stage = 'sealed' AND p.shipment_id IS NULL AND p.parent_id IS NULL";
        elseif (isset(PACKAGE_STAGES[$status])) { $sql .= ' AND p.stage = :stage'; $args[':stage'] = $status; }
        if ($scope = branch_scope()) { $sql .= ' AND p.branch_id = :br'; $args[':br'] = $scope; }
        $st = db()->prepare($sql . ' ORDER BY p.id DESC LIMIT 500');
        $st->execute($args);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['type_label'] = PACKAGE_TYPES[$r['type']] ?? $r['type'];
            $r['stage_label'] = PACKAGE_STAGES[$r['stage']] ?? $r['stage'];
            $r['total_items'] = $r['child_count'] ? package_total_items((int)$r['id']) : (int)$r['item_count'];
        }
        out(['packages' => $rows]);

    case 'package_create':
        need_role('gudang', 'cabang');
        $type = strtoupper(param('type', 'DUS'));
        if (!isset(PACKAGE_TYPES[$type])) fail('Jenis packing tidak dikenal.');
        $code = next_code('packages', $type);
        $f = package_fields();
        // Koordinator / admin boleh memilih gudang tempat paket dibuat
        $branch = user_branch_id();
        if (is_multi_branch() && (int)param('branch_id', 0)) {
            $b = branch_by_id((int)param('branch_id'));
            if (!$b || !$b['active']) fail('Gudang tidak ditemukan.');
            $branch = (int)$b['id'];
        }
        db()->prepare('INSERT INTO packages (code, type, name, destination, notes, weight_kg, length_cm, width_cm, height_cm, receiver_name, receiver_phone, handling, branch_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge([$code, $type], array_values($f), [$branch, current_user_id()]));
        log_activity('package_new', $code, null, 'Paket ' . PACKAGE_TYPES[$type] . " $code dibuat");
        out(package_detail(find_package($code)));

    case 'package_update':
        need_role('gudang', 'cabang');
        $pkg = find_package(param('code')) ?: fail('Paket tidak ditemukan.', 404);
        require_branch_access($pkg);
        if (in_array($pkg['stage'], ['in_transit', 'received'], true)) fail('Paket sudah dikirim, data tidak bisa diubah.', 423);
        $f = package_fields();
        db()->prepare('UPDATE packages SET name=?, destination=?, notes=?, weight_kg=?, length_cm=?, width_cm=?, height_cm=?, receiver_name=?, receiver_phone=?, handling=? WHERE id=?')
            ->execute(array_merge(array_values($f), [$pkg['id']]));
        out(package_detail(find_package($pkg['code'])));

    case 'package_delete':
        need_role('gudang', 'cabang');
        $pkg = find_package(param('code')) ?: fail('Paket tidak ditemukan.', 404);
        require_branch_access($pkg);
        require_editable($pkg);
        if ($pkg['shipment_id']) fail('Paket masih terdaftar di surat jalan. Keluarkan dulu dari surat jalan.');
        $pdo = db();
        foreach (package_items_list((int)$pkg['id']) as $i) item_event((int)$i['id'], 'unpacked', "Dikeluarkan karena paket {$pkg['code']} dihapus", $pkg['code']);
        $pdo->prepare('UPDATE packages SET parent_id = ? WHERE parent_id = ?')->execute([$pkg['parent_id'], $pkg['id']]);
        $pdo->prepare('DELETE FROM packages WHERE id = ?')->execute([$pkg['id']]);
        log_activity('package_delete', $pkg['code'], null, "Paket {$pkg['code']} dihapus");
        out(['ok' => true]);

    case 'package':
        $pkg = find_package(param('code')) ?: fail('Paket tidak ditemukan.', 404);
        out(package_detail($pkg));

    case 'package_seal':
        need_role('gudang', 'cabang');
        $pkg = find_package(param('code')) ?: fail('Paket tidak ditemukan.', 404);
        require_branch_access($pkg);
        $seal = (bool)param('sealed', true);
        if ($seal) {
            if ($pkg['stage'] !== 'packing') fail('Paket tidak dalam tahap packing.');
            db()->prepare("UPDATE packages SET status='sealed', stage='sealed', seal_no=?, sealed_at=datetime('now','localtime') WHERE id=?")
                ->execute([param('seal_no') ?: null, $pkg['id']]);
            log_activity('seal', $pkg['code'], null, "Paket {$pkg['code']} disegel" . (param('seal_no') ? ' (segel no. ' . param('seal_no') . ')' : ''));
        } else {
            if ($pkg['stage'] !== 'sealed') fail('Paket sudah ' . strtolower(PACKAGE_STAGES[$pkg['stage']]) . ', segel tidak bisa dibuka.', 423);
            $root = package_root($pkg);
            if ($root['shipment_id']) fail('Paket terdaftar di surat jalan. Keluarkan dari surat jalan sebelum membuka segel.', 423);
            db()->prepare("UPDATE packages SET status='open', stage='packing', seal_no=NULL, sealed_at=NULL WHERE id=?")->execute([$pkg['id']]);
            log_activity('unseal', $pkg['code'], null, "Paket {$pkg['code']} dibuka segelnya");
        }
        out(package_detail(find_package($pkg['code'])));

    case 'package_add':
        need_role('gudang', 'cabang');
        $pdo = db();
        $pkg = find_package(param('package')) ?: fail('Paket tidak ditemukan.', 404);
        require_branch_access($pkg);
        require_editable($pkg);
        $code = param('code');
        $move = (bool)param('move', false);
        $forceLocation = (bool)param('force_location', false);
        if ($code === '') fail('Kode kosong.');

        if ($item = find_item($code)) {
            if ($item['status'] === 'dikirim') fail("{$item['code']} sedang dalam pengiriman.", 409);
            if ($item['status'] === 'dihapus') fail("{$item['code']} sudah dihapusbukukan (hilang). Ubah statusnya di database barang bila ditemukan kembali.", 409);
            if ($item['status'] === 'hilang') {
                $case = open_missing_case((int)$item['id']);
                if (!param('found')) {
                    fail("{$item['code']} tercatat HILANG" . ($case ? " pada surat jalan {$case['shipment_code']}" : '') . '.', 409, ['missing_case' => true, 'shipment' => $case['shipment_code'] ?? null, 'item' => $item]);
                }
                // Barang ditemukan di cabang tempat paket ini dibuat -> tutup selisihnya
                $pdo->beginTransaction();
                if ($case) {
                    $cs = find_shipment($case['shipment_code']);
                    $here = (int)$pkg['branch_id'];
                    $res = $here === (int)$cs['origin_branch_id'] ? 'left_origin' : 'found_dest';
                    apply_resolution($cs, $case, $res, 'Ditemukan saat packing ke ' . $pkg['code'], '', null, $res === 'found_dest' ? $here : null);
                }
                $pdo->prepare("UPDATE items SET status = 'ada', location_branch_id = ? WHERE id = ?")->execute([$pkg['branch_id'], $item['id']]);
                $pdo->commit();
                $item = find_item($item['code']);
                $forceLocation = true;
            }
            if (!$forceLocation && (int)$item['location_branch_id'] !== (int)$pkg['branch_id']) {
                fail("{$item['code']} tercatat berada di " . branch_name((int)$item['location_branch_id']) . ', bukan di ' . branch_name((int)$pkg['branch_id']) . '.', 409, ['location_conflict' => true, 'item' => $item]);
            }
            $movedFrom = null;
            $pdo->beginTransaction();
            if ($item['track'] === 'qty') {
                $available = (int)$item['stock'] - packed_qty((int)$item['id']);
                if (param('qty', '') === '') fail("Masukkan jumlah {$item['code']}.", 409, ['need_qty' => true, 'item' => $item, 'available' => $available]);
                $qty = max(1, (int)param('qty', 1));
                if ($qty > $available) fail("Stok {$item['code']} yang tersedia hanya $available {$item['unit']}.", 409, ['stock' => $available]);
                $pdo->prepare('INSERT INTO package_items (package_id, item_id, qty, added_by) VALUES (?,?,?,?) ON CONFLICT(package_id, item_id) DO UPDATE SET qty = qty + excluded.qty')
                    ->execute([$pkg['id'], $item['id'], $qty, current_user_id()]);
                $detail = "Masuk paket {$pkg['code']} ($qty {$item['unit']})";
            } else {
                $cur = item_active_package((int)$item['id']);
                if ($cur && (int)$cur['id'] === (int)$pkg['id']) fail("{$item['code']} sudah ada di paket ini.", 409, ['duplicate' => true, 'item' => $item]);
                if ($cur && !$move) fail("{$item['code']} sudah ada di paket {$cur['code']}.", 409, ['conflict' => true, 'from' => $cur['code'], 'item' => $item]);
                if ($cur) {
                    if (!package_editable($cur)) fail("Paket asal {$cur['code']} sudah disegel/dikirim. Buka segel paket asal terlebih dahulu.", 423);
                    $pdo->prepare('DELETE FROM package_items WHERE package_id = ? AND item_id = ?')->execute([$cur['id'], $item['id']]);
                    $movedFrom = $cur['code'];
                }
                $pdo->prepare('INSERT INTO package_items (package_id, item_id, qty, added_by) VALUES (?,?,1,?)')->execute([$pkg['id'], $item['id'], current_user_id()]);
                $detail = ($movedFrom ? "Dipindah dari $movedFrom ke " : 'Masuk paket ') . $pkg['code'];
            }
            item_event((int)$item['id'], 'packed', $detail, $pkg['code'], null, (int)$pkg['branch_id']);
            log_activity('add', $pkg['code'], $item['code'], $detail);
            $pdo->commit();
            out(['ok' => true, 'kind' => 'item', 'item' => $item, 'moved_from' => $movedFrom] + package_detail(find_package($pkg['code'])));
        }

        if ($child = find_package($code)) {
            if ((int)$child['id'] === (int)$pkg['id']) fail('Paket tidak bisa dimasukkan ke dirinya sendiri.');
            if (is_descendant((int)$pkg['id'], (int)$child['id'])) fail("{$pkg['code']} berada di dalam {$child['code']}, tidak bisa dibalik.");
            if ((int)$child['parent_id'] === (int)$pkg['id']) fail("{$child['code']} sudah ada di paket ini.", 409, ['duplicate' => true]);
            if ($child['shipment_id']) fail("{$child['code']} sudah terdaftar di surat jalan.", 409);
            if (!in_array($child['stage'], ['packing', 'sealed'], true)) fail("{$child['code']} sudah " . strtolower(PACKAGE_STAGES[$child['stage']]) . '.', 409);
            if ((int)$child['branch_id'] !== (int)$pkg['branch_id']) fail("{$child['code']} milik cabang lain.", 409);
            if ($child['parent_id'] && !$move) {
                $parent = package_by_id((int)$child['parent_id']);
                fail("{$child['code']} sudah ada di paket {$parent['code']}.", 409, ['conflict' => true, 'from' => $parent['code']]);
            }
            db()->prepare('UPDATE packages SET parent_id = ? WHERE id = ?')->execute([$pkg['id'], $child['id']]);
            log_activity('nest', $pkg['code'], $child['code'], "Paket {$child['code']} masuk ke {$pkg['code']}");
            out(['ok' => true, 'kind' => 'package', 'child' => $child] + package_detail(find_package($pkg['code'])));
        }

        fail("Kode \"$code\" tidak terdaftar di database barang.", 404, ['unknown' => true]);

    case 'package_extra_save':
        // Barang manual / non-inventaris: tanpa kode barang & barcode, tidak masuk stok / lokasi gudang
        need_role('gudang', 'cabang');
        $pkg = find_package(param('package')) ?: fail('Paket tidak ditemukan.', 404);
        require_branch_access($pkg);
        require_editable($pkg);
        $name = param('name');
        $qty = (int)param('qty', 1);
        if ($name === '') fail('Nama barang wajib diisi.');
        if ($qty < 1) fail('Jumlah minimal 1.');
        $unit = strtolower(param('unit')) ?: 'pcs';
        $id = (int)param('id', 0);
        if ($id) {
            $st = db()->prepare('UPDATE package_extras SET name = ?, qty = ?, unit = ?, note = ? WHERE id = ? AND package_id = ?');
            $st->execute([$name, $qty, $unit, param('note') ?: null, $id, $pkg['id']]);
            log_activity('extra', $pkg['code'], null, "Barang manual diubah di {$pkg['code']}: $name ($qty $unit)");
        } else {
            db()->prepare('INSERT INTO package_extras (package_id, name, qty, unit, note, added_by) VALUES (?,?,?,?,?,?)')
                ->execute([$pkg['id'], $name, $qty, $unit, param('note') ?: null, current_user_id()]);
            log_activity('extra', $pkg['code'], null, "Barang manual masuk {$pkg['code']}: $name ($qty $unit)");
        }
        out(package_detail(find_package($pkg['code'])));

    case 'package_extra_delete':
        need_role('gudang', 'cabang');
        $pkg = find_package(param('package')) ?: fail('Paket tidak ditemukan.', 404);
        require_branch_access($pkg);
        require_editable($pkg);
        $st = db()->prepare('SELECT name FROM package_extras WHERE id = ? AND package_id = ?');
        $st->execute([(int)param('id'), $pkg['id']]);
        $name = $st->fetchColumn() ?: fail('Barang manual tidak ditemukan.', 404);
        db()->prepare('DELETE FROM package_extras WHERE id = ?')->execute([(int)param('id')]);
        log_activity('extra', $pkg['code'], null, "Barang manual dikeluarkan dari {$pkg['code']}: $name");
        out(package_detail(find_package($pkg['code'])));

    case 'package_remove':
        need_role('gudang', 'cabang');
        $pkg = find_package(param('package')) ?: fail('Paket tidak ditemukan.', 404);
        require_branch_access($pkg);
        require_editable($pkg);
        $code = param('code');
        if ($item = find_item($code)) {
            db()->prepare('DELETE FROM package_items WHERE package_id = ? AND item_id = ?')->execute([$pkg['id'], $item['id']]);
            item_event((int)$item['id'], 'unpacked', "Dikeluarkan dari {$pkg['code']}", $pkg['code']);
        } elseif ($child = find_package($code)) {
            db()->prepare('UPDATE packages SET parent_id = NULL WHERE id = ? AND parent_id = ?')->execute([$child['id'], $pkg['id']]);
        }
        log_activity('remove', $pkg['code'], $code, "Dikeluarkan dari {$pkg['code']}");
        out(package_detail(find_package($pkg['code'])));

    case 'package_tree':
        // Halaman Cek Paket. Kode barang -> tampilkan paket tempat barang itu berada.
        $code = param('code');
        $scannedItem = null;
        $pkg = find_package($code);
        if (!$pkg && ($item = find_item($code))) {
            $cur = item_active_package((int)$item['id']);
            if (!$cur) out(['kind' => 'item', 'code' => $item['code']]);
            $scannedItem = $item['code'];
            $pkg = $cur;
        }
        if (!$pkg && ($s = find_shipment($code))) out(['kind' => 'shipment', 'code' => $s['code']]);
        if (!$pkg) fail("Kode \"$code\" tidak ditemukan.", 404);
        out(['kind' => 'package', 'tree' => package_tree($pkg), 'path' => package_path($pkg), 'scanned_item' => $scannedItem]);

    case 'package_print':
        $pkg = find_package(param('code')) ?: fail('Paket tidak ditemukan.', 404);
        $st = db()->prepare('SELECT code FROM packages WHERE parent_id = ? ORDER BY code');
        $st->execute([$pkg['id']]);
        $info = package_info($pkg);
        $root = package_root($pkg);
        $ship = $root['shipment_id'] ? shipment_info(shipment_by_id((int)$root['shipment_id'])) : null;
        out([
            'package' => $info,
            'items' => package_all_items((int)$pkg['id']),
            'children' => $st->fetchAll(PDO::FETCH_COLUMN),
            'extras' => package_all_extras((int)$pkg['id']),
            'shipment' => $ship ? ['code' => $ship['code'], 'origin_name' => $ship['origin_name'], 'dest_name' => $ship['dest_name'], 'dest_address' => $ship['dest']['address'] ?? '', 'dest_pic' => $ship['dest']['pic_name'] ?? '', 'dest_phone' => $ship['dest']['pic_phone'] ?? ''] : null,
            'company' => setting('company_name'),
            'handling_labels' => HANDLING,
            'now' => db()->query("SELECT strftime('%d-%m-%Y %H:%M', 'now', 'localtime')")->fetchColumn(),
        ]);
}
