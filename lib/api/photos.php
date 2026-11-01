<?php
// Foto bukti: isi paket sebelum disegel, kondisi saat tiba, barang rusak.
// File disimpan di data/uploads (tidak bisa diakses langsung) dan ditampilkan lewat photo.php.

const PHOTO_REF_TYPES = ['package', 'shipment', 'receipt', 'item'];

switch ($action) {
    case 'photo_upload':
        $refType = param('ref_type');
        $refCode = param('ref_code');
        if (!in_array($refType, PHOTO_REF_TYPES, true) || $refCode === '') fail('Referensi foto tidak valid.');
        if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('File foto gagal diunggah.');
        if ($_FILES['file']['size'] > 8 * 1024 * 1024) fail('Ukuran foto maksimal 8 MB.');
        $info = @getimagesize($_FILES['file']['tmp_name']);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (!$ext) fail('File harus berupa gambar JPG, PNG, atau WEBP.');

        $sub = date('Ym');
        $dir = data_dir() . '/uploads/' . $sub;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = $sub . '/' . date('dHis') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], data_dir() . '/uploads/' . $name)) fail('Gagal menyimpan foto.');
        db()->prepare('INSERT INTO photos (ref_type, ref_code, path, caption, user_id) VALUES (?,?,?,?,?)')
            ->execute([$refType, $refCode, $name, param('caption') ?: null, current_user_id()]);
        out(['ok' => true, 'id' => (int)db()->lastInsertId()]);

    case 'photos':
        $st = db()->prepare('SELECT p.id, p.ref_type, p.ref_code, p.caption, p.created_at, u.name AS user_name FROM photos p LEFT JOIN users u ON u.id = p.user_id WHERE p.ref_type = ? AND p.ref_code LIKE ? ORDER BY p.id DESC');
        $prefix = (bool)param('prefix', false);
        $st->execute([param('ref_type'), param('ref_code') . ($prefix ? '%' : '')]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) $r['url'] = 'photo.php?id=' . $r['id'];
        out(['photos' => $rows]);

    case 'photo_delete':
        $st = db()->prepare('SELECT * FROM photos WHERE id = ?');
        $st->execute([(int)param('id')]);
        $p = $st->fetch() ?: fail('Foto tidak ditemukan.', 404);
        if ((int)$p['user_id'] !== current_user_id() && !has_role()) fail('Hanya pengunggah atau admin yang dapat menghapus foto.', 403);
        @unlink(data_dir() . '/uploads/' . $p['path']);
        db()->prepare('DELETE FROM photos WHERE id = ?')->execute([$p['id']]);
        out(['ok' => true]);
}
