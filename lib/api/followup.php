<?php
// Tindak lanjut selisih penerimaan: daftar selisih terbuka, keputusan per barang, penutupan surat jalan.

switch ($action) {
    case 'followups':
        $status = param('status', 'open');
        $sql = "SELECT r.*, s.code AS shipment_code, s.received_at, s.origin_branch_id, s.dest_branch_id, s.status AS shipment_status,
                       o.name AS origin_name, d.name AS dest_name, COALESCE(i.nama, pe.name) AS nama, i.jenis, i.serial, COALESCE(i.unit, pe.unit) AS unit, COALESCE(i.track, CASE WHEN pe.id IS NOT NULL THEN 'qty' END) AS track, (pe.id IS NOT NULL) AS is_manual, p.code AS package_code, u.name AS resolved_by_name
                FROM receipt_items r JOIN shipments s ON s.id = r.shipment_id
                JOIN branches o ON o.id = s.origin_branch_id JOIN branches d ON d.id = s.dest_branch_id
                LEFT JOIN items i ON i.id = r.item_id " . MANUAL_JOIN_SQL . " LEFT JOIN packages p ON p.id = r.package_id LEFT JOIN users u ON u.id = r.resolved_by
                WHERE " . NEEDS_RESOLUTION_SQL . ($status === 'done' ? ' AND r.resolution IS NOT NULL' : ' AND r.resolution IS NULL');
        $args = [];
        if ($scope = branch_scope()) { $sql .= ' AND (s.origin_branch_id = ? OR s.dest_branch_id = ?)'; $args = [$scope, $scope]; }
        $st = db()->prepare($sql . ($status === 'done' ? ' ORDER BY r.resolved_at DESC LIMIT 300' : ' ORDER BY s.received_at, s.code, r.code'));
        $st->execute($args);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['issue'] = receipt_issue($r);
            $r['cond_label'] = $r['issue'] === 'short' ? 'Jumlah kurang ' . $r['short_qty'] . ' ' . $r['unit'] : (RECEIPT_CONDITIONS[$r['cond']] ?? $r['cond']);
            $r['resolution_options'] = resolution_options($r);
            $r['resolution_label'] = $r['resolution'] ? RESOLUTIONS[$r['resolution']]['label'] : null;
            $r['can_resolve'] = can_resolve(['origin_branch_id' => $r['origin_branch_id'], 'dest_branch_id' => $r['dest_branch_id']]);
        }
        // Surat jalan bermasalah tanpa baris selisih (mis. hanya segel rusak) -> perlu ditutup manual
        $sql2 = "SELECT s.code, s.received_at, o.name AS origin_name, d.name AS dest_name, s.origin_branch_id, s.dest_branch_id FROM shipments s
                 JOIN branches o ON o.id = s.origin_branch_id JOIN branches d ON d.id = s.dest_branch_id
                 WHERE s.status IN ('partial','problem') AND NOT EXISTS (SELECT 1 FROM receipt_items r WHERE r.shipment_id = s.id AND " . NEEDS_RESOLUTION_SQL . " AND r.resolution IS NULL)";
        $args2 = [];
        if ($scope) { $sql2 .= ' AND (s.origin_branch_id = ? OR s.dest_branch_id = ?)'; $args2 = [$scope, $scope]; }
        $st = db()->prepare($sql2);
        $st->execute($args2);
        out(['rows' => $rows, 'to_close' => $status === 'done' ? [] : $st->fetchAll()]);

    case 'resolve':
        $s = find_shipment(param('shipment')) ?: fail('Surat jalan tidak ditemukan.', 404);
        if (!can_resolve($s)) fail('Hanya admin atau petugas cabang asal / tujuan yang dapat menindaklanjuti selisih.', 403);
        $st = db()->prepare('SELECT r.*, p.code AS package_code FROM receipt_items r LEFT JOIN packages p ON p.id = r.package_id WHERE r.shipment_id = ? AND r.code = ?');
        $st->execute([$s['id'], param('code')]);
        $r = $st->fetch() ?: fail('Baris penerimaan tidak ditemukan.', 404);
        if (!receipt_issue($r)) fail('Barang ini diterima baik, tidak perlu tindak lanjut.');
        if ($r['resolution']) fail('Selisih ini sudah ditindaklanjuti: ' . RESOLUTIONS[$r['resolution']]['label'] . '.', 409);
        $res = param('resolution');
        if (!isset(resolution_options($r)[$res])) fail('Pilihan tindak lanjut tidak sesuai untuk selisih ini.');
        $note = (string)param('note');
        $ref = (string)param('ref');
        if ($res === 'claim' && $ref === '') fail('Isi nomor klaim / dokumen.');
        if ($res === 'writeoff' && $note === '') fail('Isi alasan / kronologi kehilangan.');
        $value = num_param('value');
        db()->beginTransaction();
        apply_resolution($s, $r, $res, $note, $ref, $value);
        db()->commit();
        out(shipment_detail(find_shipment($s['code'])));

    case 'shipment_close':
        // Menutup surat jalan bermasalah yang tidak punya baris selisih terbuka (mis. hanya segel rusak, isi lengkap)
        $s = find_shipment(param('shipment')) ?: fail('Surat jalan tidak ditemukan.', 404);
        if (!can_resolve($s)) fail('Anda tidak berhak menutup surat jalan ini.', 403);
        if (param('note') === '') fail('Isi catatan hasil pemeriksaan.');
        db()->beginTransaction();
        if (!close_if_resolved((int)$s['id'], true)) fail('Masih ada selisih yang belum ditindaklanjuti.', 409);
        db()->prepare("UPDATE shipments SET receive_notes = TRIM(COALESCE(receive_notes, '') || char(10) || ?) WHERE id = ?")
            ->execute(['Tindak lanjut: ' . param('note'), $s['id']]);
        db()->commit();
        out(shipment_detail(find_shipment($s['code'])));
}
