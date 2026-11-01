<?php
// Fungsi domain bersama: barang, paket, pengiriman.

function find_item(string $code): ?array
{
    $st = db()->prepare('SELECT * FROM items WHERE code = ?');
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

function find_package(string $code): ?array
{
    $st = db()->prepare('SELECT * FROM packages WHERE code = ?');
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

function package_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM packages WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function find_shipment(string $code): ?array
{
    $st = db()->prepare('SELECT * FROM shipments WHERE code = ?');
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

function shipment_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM shipments WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function branch_by_id(?int $id): ?array
{
    if (!$id) return null;
    static $cache = [];
    if (!isset($cache[$id])) {
        $st = db()->prepare('SELECT * FROM branches WHERE id = ?');
        $st->execute([$id]);
        $cache[$id] = $st->fetch() ?: null;
    }
    return $cache[$id];
}

function branch_name(?int $id): string
{
    $b = branch_by_id($id);
    return $b ? $b['name'] : '-';
}

function next_code(string $table, string $prefix, int $digits = 4): string
{
    $st = db()->prepare("SELECT code FROM $table WHERE code LIKE ?");
    $st->execute([$prefix . '-%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (preg_match('/-(\d+)$/', $code, $m)) $max = max($max, (int)$m[1]);
    }
    return sprintf('%s-%0' . $digits . 'd', $prefix, $max + 1);
}

// ---------- Paket ----------

/** ID paket beserta seluruh sub-paketnya. */
function package_tree_ids(int $id): array
{
    $st = db()->prepare("
        WITH RECURSIVE tree(id) AS (
            SELECT ? UNION ALL SELECT p.id FROM packages p JOIN tree t ON p.parent_id = t.id
        ) SELECT id FROM tree
    ");
    $st->execute([$id]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function in_clause(array $ids): string
{
    return $ids ? implode(',', array_map('intval', $ids)) : '0';
}

/** Rantai induk paket, dari terluar ke paket itu sendiri. */
function package_path(array $pkg): array
{
    $path = [];
    $seen = [];
    while ($pkg && !isset($seen[$pkg['id']])) {
        $seen[$pkg['id']] = true;
        array_unshift($path, ['code' => $pkg['code'], 'type' => $pkg['type'], 'name' => $pkg['name']]);
        $pkg = $pkg['parent_id'] ? package_by_id((int)$pkg['parent_id']) : null;
    }
    return $path;
}

function package_root(array $pkg): array
{
    $seen = [];
    while ($pkg['parent_id'] && !isset($seen[$pkg['id']])) {
        $seen[$pkg['id']] = true;
        $pkg = package_by_id((int)$pkg['parent_id']);
    }
    return $pkg;
}

/** Jumlah unit barang termasuk yang ada di dalam sub-paket. */
function package_total_items(int $id): int
{
    return (int)db()->query('SELECT COALESCE(SUM(qty),0) FROM package_items WHERE package_id IN (' . in_clause(package_tree_ids($id)) . ')')->fetchColumn();
}

function is_descendant(int $candidateId, int $ancestorId): bool
{
    return $candidateId !== $ancestorId && in_array($candidateId, package_tree_ids($ancestorId), true);
}

/** Paket aktif (belum diterima) tempat barang serial berada. */
function item_active_package(int $itemId): ?array
{
    $st = db()->prepare("SELECT p.* FROM package_items pi JOIN packages p ON p.id = pi.package_id WHERE pi.item_id = ? AND p.stage <> 'received' ORDER BY pi.id DESC LIMIT 1");
    $st->execute([$itemId]);
    return $st->fetch() ?: null;
}

/** Total qty barang di paket-paket aktif. */
function packed_qty(int $itemId): int
{
    $st = db()->prepare("SELECT COALESCE(SUM(pi.qty),0) FROM package_items pi JOIN packages p ON p.id = pi.package_id WHERE pi.item_id = ? AND p.stage <> 'received'");
    $st->execute([$itemId]);
    return (int)$st->fetchColumn();
}

function item_location(array $item): ?array
{
    $pkg = item_active_package((int)$item['id']);
    if (!$pkg) return null;
    $ship = $pkg['shipment_id'] ? shipment_by_id((int)$pkg['shipment_id']) : null;
    $root = package_root($pkg);
    if (!$ship && $root['shipment_id']) $ship = shipment_by_id((int)$root['shipment_id']);
    return [
        'code' => $pkg['code'], 'type' => $pkg['type'], 'name' => $pkg['name'], 'status' => $pkg['status'], 'stage' => $pkg['stage'],
        'path' => package_path($pkg), 'shipment' => $ship ? $ship['code'] : null,
    ];
}

function vol_weight(array $pkg): ?float
{
    if (!$pkg['length_cm'] || !$pkg['width_cm'] || !$pkg['height_cm']) return null;
    $div = max(1, (float)setting('vol_divisor', 6000));
    return round($pkg['length_cm'] * $pkg['width_cm'] * $pkg['height_cm'] / $div, 2);
}

/** Paket masih bisa diubah isinya? */
function package_editable(array $pkg): bool
{
    return $pkg['status'] !== 'sealed' && $pkg['stage'] === 'packing';
}

function koli_label(array $pkg): ?string
{
    $root = package_root($pkg);
    if (!$root['shipment_id'] || !$root['koli_no']) return null;
    $total = (int)db()->query('SELECT COUNT(*) FROM packages WHERE shipment_id = ' . (int)$root['shipment_id'])->fetchColumn();
    return $root['koli_no'] . '/' . $total;
}

/** Barang manual (non-inventaris) langsung di dalam paket. */
function package_extras_list(int $packageId): array
{
    $st = db()->prepare('SELECT * FROM package_extras WHERE package_id = ? ORDER BY id');
    $st->execute([$packageId]);
    return $st->fetchAll();
}

/** Barang manual di paket beserta sub-paketnya, berbentuk seperti baris barang (kode MANUAL-<id>, tanpa barcode). */
function package_all_extras(int $packageId): array
{
    $rows = db()->query('SELECT e.*, p.code AS package_code FROM package_extras e JOIN packages p ON p.id = e.package_id WHERE e.package_id IN (' . in_clause(package_tree_ids($packageId)) . ') ORDER BY e.package_id, e.id')->fetchAll();
    return array_map(function ($e) {
        return ['item_id' => null, 'code' => MANUAL_PREFIX . $e['id'], 'nama' => $e['name'], 'jenis' => 'Barang manual', 'merk' => '', 'model' => '',
            'serial' => '', 'track' => 'qty', 'unit' => $e['unit'], 'accessories' => $e['note'], 'kategori' => '', 'qty' => (int)$e['qty'],
            'package_code' => $e['package_code'], 'package_id' => (int)$e['package_id'], 'is_manual' => 1, 'note' => $e['note']];
    }, $rows);
}

function package_items_list(int $packageId): array
{
    $st = db()->prepare('SELECT i.*, pi.qty, pi.added_at FROM package_items pi JOIN items i ON i.id = pi.item_id WHERE pi.package_id = ? ORDER BY pi.added_at DESC, i.code');
    $st->execute([$packageId]);
    return $st->fetchAll();
}

function package_info(array $pkg): array
{
    $pkg['type_label'] = PACKAGE_TYPES[$pkg['type']] ?? $pkg['type'];
    $pkg['stage_label'] = PACKAGE_STAGES[$pkg['stage']] ?? $pkg['stage'];
    $pkg['branch_name'] = branch_name($pkg['branch_id'] ? (int)$pkg['branch_id'] : null);
    $pkg['vol_weight'] = vol_weight($pkg);
    $pkg['handling_list'] = array_values(array_filter(explode(',', (string)$pkg['handling'])));
    $pkg['editable'] = package_editable($pkg);
    $root = package_root($pkg);
    $ship = $root['shipment_id'] ? shipment_by_id((int)$root['shipment_id']) : null;
    $pkg['shipment_code'] = $ship ? $ship['code'] : null;
    $pkg['shipment_dest'] = $ship ? branch_name((int)$ship['dest_branch_id']) : null;
    $pkg['koli'] = koli_label($pkg);
    $pkg['extras_count'] = (int)db()->query('SELECT COUNT(*) FROM package_extras WHERE package_id IN (' . in_clause(package_tree_ids((int)$pkg['id'])) . ')')->fetchColumn();
    $pkg['photo_count'] = (int)db()->query("SELECT COUNT(*) FROM photos WHERE ref_type = 'package' AND ref_code = " . db()->quote($pkg['code']))->fetchColumn();
    return $pkg;
}

function package_detail(array $pkg): array
{
    $st = db()->prepare('SELECT * FROM packages WHERE parent_id = ? ORDER BY code');
    $st->execute([$pkg['id']]);
    $children = [];
    foreach ($st->fetchAll() as $c) {
        $c['total_items'] = package_total_items((int)$c['id']);
        $children[] = $c;
    }
    $pkg = package_info($pkg);
    $pkg['path'] = package_path($pkg);
    $pkg['total_items'] = package_total_items((int)$pkg['id']);
    return ['package' => $pkg, 'items' => package_items_list((int)$pkg['id']), 'extras' => package_extras_list((int)$pkg['id']), 'children' => $children];
}

/** Isi paket bertingkat: barang langsung + sub-paket beserta isinya. */
function package_tree(array $pkg, int $depth = 0): array
{
    $st = db()->prepare('SELECT i.code, i.nama, i.jenis, i.merk, i.model, i.serial, i.track, i.unit, pi.qty, pi.added_at FROM package_items pi JOIN items i ON i.id = pi.item_id WHERE pi.package_id = ? ORDER BY CAST(i.kategori AS INTEGER), i.jenis, i.code');
    $st->execute([$pkg['id']]);
    $items = $st->fetchAll();

    $children = [];
    if ($depth < 10) {
        $st = db()->prepare('SELECT * FROM packages WHERE parent_id = ? ORDER BY code');
        $st->execute([$pkg['id']]);
        foreach ($st->fetchAll() as $c) $children[] = package_tree($c, $depth + 1);
    }

    $total = array_sum(array_column($items, 'qty'));
    foreach ($children as $c) $total += $c['total_items'];

    $info = package_info($pkg);
    return [
        'code' => $pkg['code'], 'type' => $pkg['type'], 'type_label' => $info['type_label'],
        'name' => $pkg['name'], 'destination' => $pkg['destination'], 'notes' => $pkg['notes'],
        'status' => $pkg['status'], 'stage' => $pkg['stage'], 'stage_label' => $info['stage_label'],
        'sealed_at' => $pkg['sealed_at'], 'created_at' => $pkg['created_at'], 'seal_no' => $pkg['seal_no'],
        'weight_kg' => $pkg['weight_kg'], 'vol_weight' => $info['vol_weight'], 'koli' => $info['koli'],
        'shipment_code' => $info['shipment_code'], 'shipment_dest' => $info['shipment_dest'],
        'receiver_name' => $pkg['receiver_name'], 'receiver_phone' => $pkg['receiver_phone'], 'handling_list' => $info['handling_list'],
        'items' => $items, 'extras' => package_extras_list((int)$pkg['id']), 'children' => $children, 'total_items' => $total,
    ];
}

/** Seluruh barang (rekursif) di dalam paket, dengan kode paket langsungnya. */
function package_all_items(int $packageId): array
{
    $st = db()->prepare("
        WITH RECURSIVE tree(id, depth) AS (
            SELECT ?, 0 UNION ALL SELECT p.id, t.depth + 1 FROM packages p JOIN tree t ON p.parent_id = t.id
        )
        SELECT i.id AS item_id, i.code, i.nama, i.jenis, i.merk, i.model, i.serial, i.track, i.unit, i.accessories, i.kategori,
               pi.qty, p.code AS package_code, p.id AS package_id
        FROM tree t JOIN packages p ON p.id = t.id
        JOIN package_items pi ON pi.package_id = p.id JOIN items i ON i.id = pi.item_id
        ORDER BY t.depth, p.code, CAST(i.kategori AS INTEGER), i.jenis, i.code
    ");
    $st->execute([$packageId]);
    return $st->fetchAll();
}

/** Ubah tahap paket beserta seluruh sub-paketnya. */
function set_package_stage(int $packageId, string $stage): void
{
    db()->exec('UPDATE packages SET stage = ' . db()->quote($stage) . ' WHERE id IN (' . in_clause(package_tree_ids($packageId)) . ')');
}

// ---------- Pengiriman ----------

function shipment_packages(int $shipmentId): array
{
    $st = db()->prepare('SELECT * FROM packages WHERE shipment_id = ? ORDER BY koli_no, id');
    $st->execute([$shipmentId]);
    return $st->fetchAll();
}

function renumber_koli(int $shipmentId): void
{
    $n = 0;
    foreach (shipment_packages($shipmentId) as $p) {
        db()->prepare('UPDATE packages SET koli_no = ? WHERE id = ?')->execute([++$n, $p['id']]);
    }
}

/** Seluruh barang yang seharusnya ada di pengiriman. */
function shipment_expected_items(int $shipmentId): array
{
    $rows = [];
    foreach (shipment_packages($shipmentId) as $p) {
        foreach (array_merge(package_all_items((int)$p['id']), package_all_extras((int)$p['id'])) as $i) {
            $i['koli_code'] = $p['code'];
            $i['koli_no'] = $p['koli_no'];
            $rows[] = $i;
        }
    }
    return $rows;
}

function shipment_info(array $s): array
{
    $s['status_label'] = SHIPMENT_STATUS[$s['status']] ?? $s['status'];
    // Hasil pemeriksaan saat diterima (tetap tampil di BAST walau selisih sudah ditindaklanjuti)
    $s['result_label'] = SHIPMENT_STATUS[$s['receive_result'] ?? '' ?: $s['status']] ?? $s['status_label'];
    $s['origin'] = branch_by_id((int)$s['origin_branch_id']);
    $s['dest'] = branch_by_id((int)$s['dest_branch_id']);
    $s['origin_name'] = $s['origin']['name'] ?? '-';
    $s['dest_name'] = $s['dest']['name'] ?? '-';
    $names = [];
    foreach (['created_by', 'departed_by', 'received_by'] as $f) {
        if ($s[$f]) {
            $st = db()->prepare('SELECT name FROM users WHERE id = ?');
            $st->execute([$s[$f]]);
            $names[$f . '_name'] = $st->fetchColumn() ?: null;
        } else {
            $names[$f . '_name'] = null;
        }
    }
    return array_merge($s, $names);
}

function shipment_detail(array $s): array
{
    $pdo = db();
    $s = shipment_info($s);
    $packages = [];
    $totalWeight = 0;
    $totalItems = 0;
    $arr = $pdo->prepare('SELECT * FROM package_arrivals WHERE shipment_id = ? AND package_id = ?');
    foreach (shipment_packages((int)$s['id']) as $p) {
        $p = package_info($p);
        $p['total_items'] = package_total_items((int)$p['id']);
        $arr->execute([$s['id'], $p['id']]);
        $p['arrival'] = $arr->fetch() ?: null;
        $totalWeight += (float)$p['weight_kg'];
        $totalItems += $p['total_items'];
        $packages[] = $p;
    }

    $st = $pdo->prepare('SELECT r.*, COALESCE(i.nama, pe.name) AS nama, COALESCE(i.jenis, CASE WHEN pe.id IS NOT NULL THEN \'Barang manual\' END) AS jenis, i.serial, COALESCE(i.track, CASE WHEN pe.id IS NOT NULL THEN \'qty\' END) AS track,
                                COALESCE(i.unit, pe.unit) AS unit, (pe.id IS NOT NULL) AS is_manual, p.code AS package_code, u.name AS resolved_by_name, ' . NEEDS_RESOLUTION_SQL . ' AS needs_resolution
                         FROM receipt_items r LEFT JOIN items i ON i.id = r.item_id ' . MANUAL_JOIN_SQL . ' LEFT JOIN packages p ON p.id = r.package_id LEFT JOIN users u ON u.id = r.resolved_by
                         WHERE r.shipment_id = ? ORDER BY r.scanned_at DESC');
    $st->execute([$s['id']]);
    $receipts = $st->fetchAll();
    $openIssues = 0;
    foreach ($receipts as &$r) {
        $r['needs_resolution'] = (int)$r['needs_resolution'];
        $r['resolution_label'] = $r['resolution'] ? (RESOLUTIONS[$r['resolution']]['label'] ?? $r['resolution']) : null;
        $r['resolution_options'] = $r['needs_resolution'] ? resolution_options($r) : [];
        if ($r['needs_resolution'] && !$r['resolution']) $openIssues++;
    }
    unset($r);

    $summary = array_fill_keys(array_keys(RECEIPT_CONDITIONS), 0);
    foreach ($receipts as $r) $summary[$r['cond']] += (int)$r['qty'];

    return [
        'shipment' => $s,
        'packages' => $packages,
        'total_weight' => round($totalWeight, 2),
        'total_items' => $totalItems,
        'loaded' => count(array_filter($packages, function ($p) { return $p['loaded_at']; })),
        'arrived' => count(array_filter($packages, function ($p) { return $p['arrival']; })),
        'receipts' => $receipts,
        'receipt_summary' => $summary,
        'open_issues' => $openIssues,
        'photo_count' => (int)$pdo->query("SELECT COUNT(*) FROM photos WHERE ref_type IN ('shipment','receipt') AND ref_code LIKE " . $pdo->quote($s['code'] . '%'))->fetchColumn(),
    ];
}

// ---------- Tindak lanjut selisih ----------

/** Jenis selisih baris penerimaan: kurang | short | rusak | tidak_lengkap | lebih | null. */
function receipt_issue(array $r): ?string
{
    if ($r['cond'] !== 'ok') return $r['cond'];
    return (int)$r['short_qty'] > 0 ? 'short' : null;
}

function resolution_options(array $r): array
{
    $issue = receipt_issue($r);
    $opts = [];
    foreach (RESOLUTIONS as $k => $v) if ($issue && in_array($issue, $v['for'], true)) $opts[$k] = $v['label'];
    return $opts;
}

/** Pengguna boleh menindaklanjuti: admin, atau petugas cabang asal / tujuan. */
function can_resolve(array $s): bool
{
    $u = current_user();
    if (!$u) return false;
    if (in_array($u['role'], MULTI_BRANCH_ROLES, true)) return true;
    if ($u['role'] === 'kurir') return false;
    return in_array((int)$u['branch_id'], [(int)$s['origin_branch_id'], (int)$s['dest_branch_id']], true);
}

/**
 * Terapkan keputusan tindak lanjut pada satu baris penerimaan, termasuk akibatnya
 * pada lokasi / status / stok barang. Tidak mengecek hak akses.
 */
function apply_resolution(array $s, array $r, string $resolution, string $note = '', string $ref = '', ?float $value = null, ?int $branchFound = null): void
{
    $pdo = db();
    $item = $r['item_id'] ? $pdo->query('SELECT * FROM items WHERE id = ' . (int)$r['item_id'])->fetch() : null;
    $missingQty = receipt_issue($r) === 'short' ? (int)$r['short_qty'] : (int)$r['qty'];
    $origin = (int)$s['origin_branch_id'];
    $dest = (int)$s['dest_branch_id'];
    $label = RESOLUTIONS[$resolution]['label'];

    if ($item) {
        $serial = $item['track'] !== 'qty';
        switch ($resolution) {
            case 'found_dest':
                $where = $branchFound ?: $dest;
                if ($serial) $pdo->prepare("UPDATE items SET status = 'ada', location_branch_id = ? WHERE id = ?")->execute([$where, $item['id']]);
                $label = 'Ditemukan di ' . branch_name($where);
                break;
            case 'left_origin':
                if ($serial) $pdo->prepare("UPDATE items SET status = 'ada', location_branch_id = ? WHERE id = ?")->execute([$origin, $item['id']]);
                else $pdo->prepare('UPDATE items SET stock = stock + ? WHERE id = ?')->execute([$missingQty, $item['id']]);
                $label = 'Tertinggal di ' . branch_name($origin) . ($serial ? '' : " ($missingQty {$item['unit']} kembali ke stok)");
                break;
            case 'writeoff':
                if ($serial) $pdo->prepare("UPDATE items SET status = 'dihapus' WHERE id = ?")->execute([$item['id']]);
                break;
        }
        item_event((int)$item['id'], 'followup', "Tindak lanjut {$s['code']}: $label" . ($ref ? " ($ref)" : '') . ($note ? " — $note" : ''), $r['package_code'] ?? null, $s['code'], $branchFound ?: $dest);
    }

    $pdo->prepare("UPDATE receipt_items SET resolution = ?, resolution_note = ?, resolution_ref = ?, resolution_value = ?, resolved_by = ?, resolved_at = datetime('now','localtime') WHERE id = ?")
        ->execute([$resolution, $note ?: null, $ref ?: null, $value, current_user_id(), $r['id']]);
    log_activity('followup', $r['package_code'] ?? null, $r['code'], "Selisih {$r['code']} ({$s['code']}): $label", $s['code']);
    close_if_resolved((int)$s['id']);
}

/** Tutup surat jalan bila semua selisih sudah ditindaklanjuti. */
function close_if_resolved(int $shipmentId, bool $force = false): bool
{
    $pdo = db();
    $s = shipment_by_id($shipmentId);
    if (!$s || !in_array($s['status'], ['partial', 'problem'], true)) return false;
    $open = (int)$pdo->query('SELECT COUNT(*) FROM receipt_items r WHERE r.shipment_id = ' . $shipmentId . ' AND ' . NEEDS_RESOLUTION_SQL . ' AND r.resolution IS NULL')->fetchColumn();
    if ($open) return false;
    // Tanpa baris selisih (mis. hanya segel rusak) harus ditutup manual
    $handled = (int)$pdo->query('SELECT COUNT(*) FROM receipt_items WHERE shipment_id = ' . $shipmentId . ' AND resolution IS NOT NULL')->fetchColumn();
    if (!$handled && !$force) return false;
    $pdo->prepare("UPDATE shipments SET status = 'resolved', resolved_at = datetime('now','localtime') WHERE id = ?")->execute([$shipmentId]);
    log_activity('resolved', null, null, "Selisih surat jalan {$s['code']} selesai ditindaklanjuti", $s['code']);
    return true;
}

/** Baris "kurang" yang masih terbuka untuk barang ini (terbaru). */
function open_missing_case(int $itemId): ?array
{
    $st = db()->prepare("SELECT r.*, s.code AS shipment_code FROM receipt_items r JOIN shipments s ON s.id = r.shipment_id
                         WHERE r.item_id = ? AND r.cond = 'kurang' AND r.resolution IS NULL ORDER BY r.id DESC LIMIT 1");
    $st->execute([$itemId]);
    return $st->fetch() ?: null;
}

/** Setelah surat jalan diterima: barang yang dulu "kurang" ke tujuan yang sama kini tiba -> dikirim susulan. */
function auto_resolve_resent(array $s): int
{
    $pdo = db();
    $st = $pdo->prepare("SELECT r.*, s.code AS shipment_code, s.id AS sid FROM receipt_items r JOIN shipments s ON s.id = r.shipment_id
                         WHERE r.item_id = ? AND (r.cond = 'kurang' OR r.short_qty > 0) AND r.resolution IS NULL AND s.id <> ? AND s.dest_branch_id = ?");
    $rows = $pdo->prepare("SELECT item_id FROM receipt_items WHERE shipment_id = ? AND item_id IS NOT NULL AND cond <> 'kurang'");
    $rows->execute([$s['id']]);
    $n = 0;
    foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
        $st->execute([$itemId, $s['id'], $s['dest_branch_id']]);
        foreach ($st->fetchAll() as $case) {
            apply_resolution(shipment_by_id((int)$case['sid']), $case, 'resent', 'Otomatis: diterima lewat ' . $s['code'], $s['code']);
            $n++;
        }
    }
    return $n;
}

function can_receive(array $s): bool
{
    $u = current_user();
    if (!$u) return false;
    if (in_array($u['role'], MULTI_BRANCH_ROLES, true)) return true;
    if ((int)$u['branch_id'] === (int)$s['dest_branch_id'] && in_array($u['role'], ['cabang', 'gudang'], true)) return true;
    return false;
}

/** Pengguna cabang hanya melihat data cabangnya. Mengembalikan ID cabang atau null (semua). */
function branch_scope(): ?int
{
    $u = current_user();
    return ($u && $u['role'] === 'cabang') ? (int)$u['branch_id'] : null;
}
