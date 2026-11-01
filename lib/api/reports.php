<?php
// Dashboard & laporan.

function report_discrepancies(string $from, string $to, ?int $scope): array
{
    $sql = "SELECT s.code AS shipment_code, s.received_at, o.name AS origin_name, d.name AS dest_name, r.code, r.qty, r.cond, r.note, r.short_qty,
                   r.resolution, r.resolution_note, r.resolution_ref, r.resolution_value, r.resolved_at, ru.name AS resolved_by_name,
                   COALESCE(i.nama, pe.name) AS nama, i.jenis, i.serial, COALESCE(i.unit, pe.unit) AS unit, (pe.id IS NOT NULL) AS is_manual, p.code AS package_code
            FROM receipt_items r JOIN shipments s ON s.id = r.shipment_id
            JOIN branches o ON o.id = s.origin_branch_id JOIN branches d ON d.id = s.dest_branch_id
            LEFT JOIN items i ON i.id = r.item_id " . MANUAL_JOIN_SQL . " LEFT JOIN packages p ON p.id = r.package_id LEFT JOIN users ru ON ru.id = r.resolved_by
            WHERE " . NEEDS_RESOLUTION_SQL . " AND s.received_at IS NOT NULL AND date(s.received_at) BETWEEN ? AND ?";
    $args = [$from, $to];
    if ($scope) { $sql .= ' AND (s.origin_branch_id = ? OR s.dest_branch_id = ?)'; $args[] = $scope; $args[] = $scope; }
    $st = db()->prepare($sql . ' ORDER BY s.received_at DESC, s.code, r.code');
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['cond_label'] = $r['cond'] === 'ok' ? 'Jumlah kurang ' . $r['short_qty'] . ' ' . $r['unit'] : (RECEIPT_CONDITIONS[$r['cond']] ?? $r['cond']);
        $r['resolution_label'] = $r['resolution'] ? RESOLUTIONS[$r['resolution']]['label'] : null;
    }
    return $rows;
}

function report_recap(string $month, ?int $scope): array
{
    $sql = "SELECT d.id, d.name AS branch, s.kind,
                   COUNT(DISTINCT s.id) AS shipments,
                   (SELECT COUNT(*) FROM packages p JOIN shipments s2 ON s2.id = p.shipment_id WHERE s2.dest_branch_id = d.id AND s2.kind = s.kind AND strftime('%Y-%m', s2.departed_at) = :m) AS koli,
                   (SELECT COALESCE(SUM(p.weight_kg),0) FROM packages p JOIN shipments s2 ON s2.id = p.shipment_id WHERE s2.dest_branch_id = d.id AND s2.kind = s.kind AND strftime('%Y-%m', s2.departed_at) = :m) AS weight,
                   SUM(CASE WHEN COALESCE(s.receive_result, s.status) = 'received' THEN 1 ELSE 0 END) AS ok,
                   SUM(CASE WHEN COALESCE(s.receive_result, s.status) IN ('partial','problem') THEN 1 ELSE 0 END) AS issue,
                   SUM(CASE WHEN s.status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
                   SUM(CASE WHEN s.status = 'in_transit' THEN 1 ELSE 0 END) AS transit
            FROM shipments s JOIN branches d ON d.id = s.dest_branch_id
            WHERE s.departed_at IS NOT NULL AND strftime('%Y-%m', s.departed_at) = :m";
    $args = [':m' => $month];
    if ($scope) { $sql .= ' AND (s.origin_branch_id = :sc OR s.dest_branch_id = :sc)'; $args[':sc'] = $scope; }
    $st = db()->prepare($sql . ' GROUP BY d.id, s.kind ORDER BY d.name, s.kind');
    $st->execute($args);
    $rows = $st->fetchAll();

    // Jumlah unit barang terkirim per cabang tujuan
    $roots = db()->prepare("SELECT p.id FROM packages p JOIN shipments s ON s.id = p.shipment_id WHERE s.dest_branch_id = ? AND s.kind = ? AND strftime('%Y-%m', s.departed_at) = ?");
    foreach ($rows as &$r) {
        $roots->execute([$r['id'], $r['kind'], $month]);
        $r['items'] = 0;
        foreach ($roots->fetchAll(PDO::FETCH_COLUMN) as $pid) $r['items'] += package_total_items((int)$pid);
        $r['weight'] = round((float)$r['weight'], 2);
    }
    return $rows;
}

function report_outstanding(?int $scope): array
{
    $sql = "SELECT s.*, o.name AS origin_name, d.name AS dest_name, (SELECT COUNT(*) FROM packages WHERE shipment_id = s.id) AS koli,
                   CAST(julianday('now','localtime') - julianday(COALESCE(s.departed_at, s.created_at)) AS INTEGER) AS days
            FROM shipments s JOIN branches o ON o.id = s.origin_branch_id JOIN branches d ON d.id = s.dest_branch_id
            WHERE s.status IN ('draft','in_transit')";
    $args = [];
    if ($scope) { $sql .= ' AND (s.origin_branch_id = ? OR s.dest_branch_id = ?)'; $args = [$scope, $scope]; }
    $st = db()->prepare($sql . ' ORDER BY s.status DESC, days DESC');
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['status_label'] = SHIPMENT_STATUS[$r['status']] ?? $r['status'];
    return $rows;
}

switch ($action) {
    case 'stats':
        $pdo = db();
        $q = function ($sql, $args = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); };
        $me = user_branch_id();
        $scope = branch_scope();
        $u = current_user();
        $actSql = 'SELECT a.*, u.name AS user_name FROM activity a LEFT JOIN users u ON u.id = a.user_id';
        $actArgs = [];
        if ($scope) { $actSql .= ' WHERE a.user_id IN (SELECT id FROM users WHERE branch_id = ?) OR a.shipment IN (SELECT code FROM shipments WHERE dest_branch_id = ? OR origin_branch_id = ?)'; $actArgs = [$scope, $scope, $scope]; }
        $act = $pdo->prepare($actSql . ' ORDER BY a.id DESC LIMIT 15');
        $act->execute($actArgs);
        out([
            'items_here' => $q("SELECT COUNT(*) FROM items WHERE location_branch_id = ? AND status = 'ada'", [$me]),
            'items_total' => $q('SELECT COUNT(*) FROM items'),
            'items_unpacked' => $q("SELECT COUNT(*) FROM items i WHERE i.location_branch_id = ? AND i.status = 'ada' AND NOT EXISTS (SELECT 1 FROM package_items pi JOIN packages p ON p.id = pi.package_id WHERE pi.item_id = i.id AND p.stage <> 'received')", [$me]),
            'items_transit' => $q("SELECT COUNT(*) FROM items WHERE status = 'dikirim'"),
            'items_lowstock' => $q("SELECT COUNT(*) FROM items WHERE track = 'qty' AND min_stock > 0 AND stock <= min_stock AND location_branch_id = ?", [$me]),
            'items_missing' => $q("SELECT COUNT(*) FROM items WHERE status = 'hilang'"),
            'pkg_packing' => $q("SELECT COUNT(*) FROM packages WHERE stage = 'packing' AND branch_id = ?", [$me]),
            'pkg_ready' => $q("SELECT COUNT(*) FROM packages WHERE stage = 'sealed' AND shipment_id IS NULL AND parent_id IS NULL AND branch_id = ?", [$me]),
            'ship_draft' => $q("SELECT COUNT(*) FROM shipments WHERE status = 'draft' AND origin_branch_id = ?", [$me]),
            'ship_outgoing' => $q("SELECT COUNT(*) FROM shipments WHERE status = 'in_transit' AND origin_branch_id = ?", [$me]),
            'ship_incoming' => $q("SELECT COUNT(*) FROM shipments WHERE status = 'in_transit' AND dest_branch_id = ?", [$me]),
            'ship_issue' => $q("SELECT COUNT(*) FROM shipments WHERE status IN ('partial','problem') AND date(received_at) >= date('now','localtime','-30 day')" . ($scope ? ' AND (origin_branch_id = ? OR dest_branch_id = ?)' : ''), $scope ? [$scope, $scope] : []),
            'activity' => $act->fetchAll(),
            'default_password' => $u['must_change'] ? true : false,
            'disc_open' => $q('SELECT COUNT(*) FROM receipt_items r JOIN shipments s ON s.id = r.shipment_id WHERE ' . NEEDS_RESOLUTION_SQL . ' AND r.resolution IS NULL' . ($scope ? ' AND (s.origin_branch_id = ? OR s.dest_branch_id = ?)' : ''), $scope ? [$scope, $scope] : []),
        ]);

    case 'report_outstanding':
        out(['rows' => report_outstanding(branch_scope())]);

    case 'report_discrepancies':
        $from = param('from') ?: date('Y-m-01');
        $to = param('to') ?: date('Y-m-d');
        out(['rows' => report_discrepancies($from, $to, branch_scope()), 'from' => $from, 'to' => $to]);

    case 'report_recap':
        $month = preg_match('/^\d{4}-\d{2}$/', param('month')) ? param('month') : date('Y-m');
        out(['rows' => report_recap($month, branch_scope()), 'month' => $month]);
}
