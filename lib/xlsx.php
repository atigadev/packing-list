<?php
// Pembaca .xlsx sederhana (sheet pertama) tanpa library eksternal.

function xlsx_rows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('File Excel tidak dapat dibuka.');

    $strings = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml !== false) {
        $sst = simplexml_load_string($xml);
        foreach ($sst->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string)$si->t;
            } else {
                $t = '';
                foreach ($si->r as $r) $t .= (string)$r->t;
                $strings[] = $t;
            }
        }
    }

    // Cari path sheet pertama lewat workbook rels, fallback ke sheet1.xml
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb && $rels) {
        $wbx = simplexml_load_string($wb);
        $first = $wbx->sheets->sheet[0] ?? null;
        if ($first) {
            $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheetPath = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('Sheet tidak ditemukan di file Excel.');

    $rows = [];
    foreach (simplexml_load_string($sheetXml)->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = preg_replace('/\d+/', '', (string)$c['r']);
            $type = (string)$c['t'];
            if ($type === 'inlineStr') {
                $val = (string)$c->is->t;
            } elseif (!isset($c->v)) {
                continue;
            } else {
                $val = (string)$c->v;
                if ($type === 's') $val = $strings[(int)$val] ?? '';
            }
            $cells[$col] = trim($val);
        }
        if ($cells) $rows[] = $cells;
    }
    return $rows;
}

/**
 * Import barang dari Excel. Baris header dicari otomatis (baris yang memuat kolom "Code").
 * Barang dengan Code yang sudah ada akan diperbarui.
 */
function import_items_from_xlsx(string $path): array
{
    $map = [
        'code' => ['code', 'kode', 'kode barang'],
        'jenis' => ['jenis item', 'jenis', 'item'],
        'kategori' => ['kategori', 'category'],
        'merk' => ['merk', 'merek', 'brand'],
        'model' => ['model', 'tipe', 'type'],
        'serial' => ['serial number', 'serial', 'sn', 's/n'],
        'nama' => ['nama item', 'nama barang', 'nama'],
    ];

    $rows = xlsx_rows($path);
    $cols = null;
    $start = 0;
    foreach ($rows as $i => $cells) {
        $found = [];
        foreach ($cells as $col => $val) {
            $v = strtolower(trim($val));
            foreach ($map as $field => $aliases) {
                if (!isset($found[$field]) && in_array($v, $aliases, true)) $found[$field] = $col;
            }
        }
        if (isset($found['code'])) {
            $cols = $found;
            $start = $i + 1;
            break;
        }
    }
    if (!$cols) throw new RuntimeException('Kolom "Code" tidak ditemukan di file Excel.');

    $pdo = db();
    $find = $pdo->prepare('SELECT id FROM items WHERE code = ?');
    $hq = hq_branch_id();
    $ins = $pdo->prepare("INSERT INTO items (code, jenis, kategori, merk, model, serial, nama, location_branch_id) VALUES (?,?,?,?,?,?,?,$hq)");
    $upd = $pdo->prepare("UPDATE items SET jenis=?, kategori=?, merk=?, model=?, serial=?, nama=?, updated_at=datetime('now','localtime') WHERE id=?");

    $inserted = $updated = $skipped = 0;
    $pdo->beginTransaction();
    foreach (array_slice($rows, $start) as $cells) {
        $get = function ($f) use ($cols, $cells) {
            return isset($cols[$f]) ? ($cells[$cols[$f]] ?? '') : '';
        };
        $code = $get('code');
        if ($code === '') { $skipped++; continue; }
        $vals = [$get('jenis'), $get('kategori'), $get('merk'), $get('model'), $get('serial'), $get('nama')];

        $find->execute([$code]);
        $id = $find->fetchColumn();
        if ($id) {
            $upd->execute(array_merge($vals, [$id]));
            $updated++;
        } else {
            $ins->execute(array_merge([$code], $vals));
            item_event((int)$pdo->lastInsertId(), 'created', 'Diimport dari Excel ke ' . branch_name($hq), null, null, $hq);
            $inserted++;
        }
    }
    $pdo->commit();

    log_activity('import', null, null, "Import Excel: $inserted baru, $updated diperbarui");
    return compact('inserted', 'updated', 'skipped');
}
