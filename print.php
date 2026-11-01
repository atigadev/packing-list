<?php
// Halaman cetak: label QR barang, label koli/paket, packing list, surat jalan, BAST.
// Format: A4 (default) atau thermal 80 mm (&format=thermal).
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/helpers.php';

require_page_login();

$pdo = db();
$mode = $_GET['mode'] ?? 'items';
$code = $_GET['code'] ?? '';
$format = ($_GET['format'] ?? 'a4') === 'thermal' ? 'thermal' : 'a4';
$now = date('d-m-Y H:i');
$company = setting('company_name');
$companyAddr = setting('company_address');
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
// Barang manual tidak punya kode barang / barcode: tampilkan tanda, bukan kode internal
$dc = function ($code) use ($h) { return stripos((string)$code, MANUAL_PREFIX) === 0 ? '<i>manual</i>' : $h($code); };
$num = function ($n, $dec = 1) { return $n === null || $n === '' ? '-' : rtrim(rtrim(number_format((float)$n, $dec, ',', '.'), '0'), ','); };
$dt = function ($s) { return $s ? date('d-m-Y H:i', strtotime($s)) : '-'; };

function not_found(string $msg): void
{
    http_response_code(404);
    exit($msg);
}

$title = 'Cetak';
$labels = [];

if ($mode === 'items') {
    $title = 'Label Barang';
    $codes = array_filter(array_map('trim', explode(',', $_GET['codes'] ?? '')));
    if ($codes) {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare("SELECT * FROM items WHERE code IN ($in) ORDER BY code");
        $st->execute(array_values($codes));
    } else {
        $where = ($_GET['filter'] ?? '') === 'unpacked'
            ? "WHERE status = 'ada' AND NOT EXISTS (SELECT 1 FROM package_items pi JOIN packages p ON p.id = pi.package_id WHERE pi.item_id = items.id AND p.stage <> 'received')" : '';
        $st = $pdo->query("SELECT * FROM items $where ORDER BY kategori, code");
    }
    foreach ($st->fetchAll() as $i) {
        $sub = array_filter([trim("{$i['merk']} {$i['model']}"), $i['serial'] !== '' ? "SN {$i['serial']}" : '', !empty($i['asset_no']) ? "Aset {$i['asset_no']}" : '', !empty($i['bin_location']) ? "Rak {$i['bin_location']}" : '']);
        $labels[] = ['code' => $i['code'], 'title' => $i['nama'] ?: $i['jenis'], 'sub' => implode(' · ', $sub)];
    }
} elseif ($mode === 'package' || $mode === 'list') {
    $pkg = find_package($code) ?: not_found('Paket tidak ditemukan.');
    $p = package_info($pkg);
    $items = package_all_items((int)$pkg['id']);
    $st = $pdo->prepare('SELECT code FROM packages WHERE parent_id = ? ORDER BY code');
    $st->execute([$pkg['id']]);
    $children = $st->fetchAll(PDO::FETCH_COLUMN);
    $root = package_root($pkg);
    $ship = $root['shipment_id'] ? shipment_info(shipment_by_id((int)$root['shipment_id'])) : null;
    $summary = [];
    foreach ($items as $i) {
        $k = $i['jenis'] . '|' . $i['merk'] . '|' . $i['model'] . '|' . $i['unit'];
        $summary[$k] = ($summary[$k] ?? 0) + (int)$i['qty'];
    }
    $totalQty = array_sum(array_column($items, 'qty'));
    $extras = package_all_extras((int)$pkg['id']);
    $receiver = $p['receiver_name'] ?: ($ship['dest']['pic_name'] ?? '');
    $receiverPhone = $p['receiver_phone'] ?: ($ship['dest']['pic_phone'] ?? '');
    $title = ($mode === 'list' ? 'Packing List ' : 'Label ') . $p['code'];
} elseif ($mode === 'sj' || $mode === 'bast' || $mode === 'selisih') {
    $s = find_shipment($code) ?: not_found('Surat jalan tidak ditemukan.');
    $d = shipment_detail($s);
    $s = $d['shipment'];
    $expected = shipment_expected_items((int)$s['id']);
    if ($mode !== 'sj' && !$s['received_at']) not_found('Pengiriman belum diterima.');
    $title = ($mode === 'sj' ? ($s['kind'] === 'retur' ? 'Surat Jalan Retur ' : 'Surat Jalan ') : ($mode === 'bast' ? 'BAST ' : 'Berita Acara Selisih ')) . $s['code'];
    $issues = array_values(array_filter(array_reverse($d['receipts']), function ($r) { return $r['needs_resolution']; }));
    $transport = $s['mode'] === 'ekspedisi'
        ? 'Ekspedisi ' . $s['courier'] . ($s['tracking_no'] ? ' · Resi ' . $s['tracking_no'] : '')
        : trim(implode(' · ', array_filter([$s['vehicle'], $s['plate'], $s['driver'] ? 'Sopir: ' . $s['driver'] : '', $s['driver_phone']])));
} else {
    not_found('Mode cetak tidak dikenal.');
}

$handlingText = function (array $list) {
    return implode(' · ', array_map(function ($k) { return HANDLING[$k] ?? $k; }, $list));
};
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($title) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 12px; color: #000; font-size: 10pt; }
  .toolbar { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap; }
  .toolbar button, .toolbar select { padding: 8px 14px; font-size: 14px; }
  .grid { display: flex; flex-wrap: wrap; gap: 4mm; }
  .label { border: 1px dashed #999; padding: 2mm; display: flex; gap: 2mm; align-items: center; break-inside: avoid; page-break-inside: avoid; }
  .label.s { width: 60mm; height: 30mm; }
  .label.m { width: 80mm; height: 40mm; }
  .label .t { font-size: 9pt; font-weight: bold; line-height: 1.15; overflow: hidden; }
  .label .c { font-family: Consolas, monospace; font-size: 8pt; word-break: break-all; margin-top: 1mm; }
  .label .u { font-size: 7pt; color: #333; margin-top: 1mm; }
  .mono { font-family: Consolas, 'Courier New', monospace; }

  /* Label koli / paket */
  .ship { border: 2px solid #000; width: 100mm; margin: 0 auto; break-inside: avoid; }
  .ship > div { padding: 2mm 3mm; border-bottom: 1px solid #000; }
  .ship > div:last-child { border-bottom: 0; }
  .ship .top { display: flex; justify-content: space-between; align-items: center; font-weight: bold; }
  .ship .mid { display: flex; gap: 3mm; align-items: center; }
  .ship .code { font-size: 20pt; font-weight: bold; font-family: Consolas, monospace; }
  .ship .koli { font-size: 26pt; font-weight: bold; text-align: center; line-height: 1; }
  .ship .koli small { display: block; font-size: 8pt; font-weight: normal; }
  .ship .lbl { font-size: 7.5pt; text-transform: uppercase; color: #333; }
  .ship .to { font-size: 12pt; font-weight: bold; }
  .ship .handling { font-weight: bold; font-size: 11pt; text-align: center; background: #000; color: #fff; }
  .ship .row2 { display: flex; justify-content: space-between; gap: 3mm; }

  /* Dokumen A4 */
  .doc { max-width: 190mm; margin: 0 auto; }
  .doc header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 8px; }
  .doc h1 { margin: 0 0 2px; font-size: 16pt; }
  .doc .company { font-size: 9pt; color: #333; margin-bottom: 6px; }
  .doc table.grid { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 9pt; }
  .doc table.grid th, .doc table.grid td { border: 1px solid #555; padding: 3px 5px; text-align: left; vertical-align: top; }
  .doc table.grid th { background: #eee; }
  .doc .meta { border-collapse: collapse; font-size: 9.5pt; }
  .doc .meta td { padding: 1px 8px 1px 0; vertical-align: top; }
  .doc h3 { margin: 12px 0 0; font-size: 11pt; }
  .doc .bad { color: #b00; font-weight: bold; }
  .sign { display: flex; justify-content: space-between; margin-top: 28px; text-align: center; gap: 10px; }
  .sign > div { flex: 1; }
  .sign .line { border-top: 1px solid #000; margin-top: 60px; padding-top: 2px; min-height: 16px; }
  .sign img { height: 60px; display: block; margin: 0 auto -60px; position: relative; top: 0; }

  /* Struk thermal 80 mm (area cetak ±72 mm) */
  .thermal { width: 72mm; margin: 0 auto; font-family: Consolas, 'Courier New', monospace; font-size: 9pt; line-height: 1.3; }
  .thermal .center { text-align: center; }
  .thermal h1 { font-size: 13pt; margin: 0; }
  .thermal .big { font-size: 15pt; font-weight: bold; }
  .thermal .hr { border-top: 1px dashed #000; margin: 4px 0; }
  .thermal .row { display: flex; justify-content: space-between; gap: 6px; }
  .thermal .row span:last-child { text-align: right; }
  .thermal .it { margin: 3px 0; }
  .thermal .it .code { font-weight: bold; word-break: break-all; }
  .thermal .it .sub { font-size: 8pt; }
  .thermal .qr { display: flex; justify-content: center; margin: 4px 0; }
  .thermal .sig { display: flex; justify-content: space-between; margin-top: 18px; text-align: center; font-size: 8pt; }
  .thermal .sig > div { width: 45%; }
  .thermal .sig .line { border-top: 1px solid #000; margin-top: 36px; }
  .thermal .inv { background: #000; color: #fff; text-align: center; font-weight: bold; padding: 2px; }

  @media print {
    .toolbar { display: none; }
    body { padding: 0; }
    .label { border-color: #ccc; }
    @page { margin: 8mm; }
  }
  <?php if ($format === 'thermal'): ?>
  @media print { @page { size: 80mm auto; margin: 3mm 4mm; } }
  <?php endif ?>
</style>
</head>
<body>
<div class="toolbar">
  <button onclick="window.print()">🖨 Cetak</button>
  <?php if ($mode === 'items'): ?>
    <label>Ukuran label
      <select onchange="document.querySelectorAll('.label').forEach(function(l){l.className='label '+this.value}.bind(this))">
        <option value="s">60 × 30 mm</option>
        <option value="m">80 × 40 mm</option>
      </select>
    </label>
    <span><?= count($labels) ?> label</span>
  <?php else: ?>
    <label>Format
      <select onchange="location.search = location.search.replace(/&?format=\w+/, '') + '&format=' + this.value">
        <option value="a4" <?= $format === 'a4' ? 'selected' : '' ?>>Kertas A4 / label</option>
        <option value="thermal" <?= $format === 'thermal' ? 'selected' : '' ?>>Thermal 80 mm</option>
      </select>
    </label>
  <?php endif ?>
  <button onclick="history.length > 1 ? history.back() : window.close()">← Kembali</button>
</div>

<?php /* ======================= LABEL BARANG ======================= */ if ($mode === 'items'): ?>
<div class="grid">
  <?php foreach ($labels as $l): ?>
    <div class="label s">
      <div class="qr" data-code="<?= $h($l['code']) ?>" data-size="90"></div>
      <div>
        <div class="t"><?= $h($l['title']) ?></div>
        <div class="c"><?= $h($l['code']) ?></div>
        <div class="u"><?= $h($l['sub']) ?></div>
      </div>
    </div>
  <?php endforeach ?>
  <?php if (!$labels): ?><p>Tidak ada data untuk dicetak.</p><?php endif ?>
</div>

<?php /* ======================= LABEL KOLI ======================= */ elseif ($mode === 'package'): ?>
<div class="ship" style="<?= $format === 'thermal' ? 'width:72mm' : '' ?>">
  <div class="top"><span><?= $h($company) ?></span><span><?= $h(strtoupper($p['type_label'])) ?></span></div>
  <div class="mid">
    <div class="qr" data-code="<?= $h($p['code']) ?>" data-size="<?= $format === 'thermal' ? 110 : 130 ?>"></div>
    <div style="flex:1">
      <div class="code"><?= $h($p['code']) ?></div>
      <?php if ($p['koli']): ?><div class="koli">KOLI <?= $h($p['koli']) ?><small><?= $h($p['shipment_code']) ?></small></div><?php endif ?>
      <?php if ($p['name']): ?><div><?= $h($p['name']) ?></div><?php endif ?>
    </div>
  </div>
  <?php if ($ship): ?>
    <div><div class="lbl">Dari</div><?= $h($ship['origin_name']) ?></div>
    <div>
      <div class="lbl">Kepada</div>
      <div class="to"><?= $h($ship['dest_name']) ?></div>
      <?php if (!empty($ship['dest']['address'])): ?><div><?= $h($ship['dest']['address']) ?></div><?php endif ?>
      <?php if ($receiver): ?><div>Penerima: <b><?= $h($receiver) ?></b> <?= $h($receiverPhone) ?></div><?php endif ?>
    </div>
  <?php elseif ($p['destination'] || $receiver): ?>
    <div><div class="lbl">Tujuan</div><div class="to"><?= $h($p['destination']) ?></div><?php if ($receiver): ?><div>Penerima: <b><?= $h($receiver) ?></b> <?= $h($receiverPhone) ?></div><?php endif ?></div>
  <?php endif ?>
  <div class="row2">
    <span>Isi: <b><?= $totalQty ?></b> barang<?= $extras ? ' + ' . count($extras) . ' manual' : '' ?><?= $children ? ' · ' . count($children) . ' sub-paket' : '' ?></span>
    <span>Berat: <b><?= $num($p['weight_kg']) ?> kg</b></span>
  </div>
  <?php if ($p['seal_no'] || $p['length_cm']): ?>
    <div class="row2">
      <span><?= $p['length_cm'] ? 'Dimensi ' . $num($p['length_cm'], 0) . '×' . $num($p['width_cm'], 0) . '×' . $num($p['height_cm'], 0) . ' cm' : '' ?></span>
      <span><?= $p['seal_no'] ? 'Segel: <b>' . $h($p['seal_no']) . '</b>' : '' ?></span>
    </div>
  <?php endif ?>
  <?php if ($p['handling_list']): ?><div class="handling"><?= $h(strtoupper($handlingText($p['handling_list']))) ?></div><?php endif ?>
</div>

<?php /* ======================= PACKING LIST thermal ======================= */ elseif ($mode === 'list' && $format === 'thermal'): ?>
<div class="thermal">
  <div class="center">
    <?php if ($company): ?><div><?= $h($company) ?></div><?php endif ?>
    <h1>PACKING LIST</h1>
    <div class="qr" data-code="<?= $h($p['code']) ?>" data-size="120"></div>
    <div class="big"><?= $h($p['code']) ?></div>
    <?php if ($p['koli']): ?><div class="big">KOLI <?= $h($p['koli']) ?></div><?php endif ?>
  </div>
  <div class="hr"></div>
  <div class="row"><span>Jenis</span><span><?= $h($p['type_label']) ?></span></div>
  <?php if ($p['name']): ?><div class="row"><span>Nama</span><span><?= $h($p['name']) ?></span></div><?php endif ?>
  <?php if ($ship): ?><div class="row"><span>Surat jalan</span><span><?= $h($ship['code']) ?></span></div><div class="row"><span>Tujuan</span><span><?= $h($ship['dest_name']) ?></span></div>
  <?php elseif ($p['destination']): ?><div class="row"><span>Tujuan</span><span><?= $h($p['destination']) ?></span></div><?php endif ?>
  <?php if ($receiver): ?><div class="row"><span>Penerima</span><span><?= $h($receiver . ' ' . $receiverPhone) ?></span></div><?php endif ?>
  <div class="row"><span>Berat</span><span><?= $num($p['weight_kg']) ?> kg</span></div>
  <?php if ($p['seal_no']): ?><div class="row"><span>No. segel</span><span><?= $h($p['seal_no']) ?></span></div><?php endif ?>
  <div class="row"><span>Status</span><span><?= $h($p['stage_label']) ?></span></div>
  <div class="row"><span>Dicetak</span><span><?= $now ?></span></div>
  <?php if ($children): ?><div>Sub-paket: <?= $h(implode(', ', $children)) ?></div><?php endif ?>
  <?php if ($p['notes']): ?><div>Catatan: <?= nl2br($h($p['notes'])) ?></div><?php endif ?>
  <?php if ($p['handling_list']): ?><div class="inv"><?= $h(strtoupper($handlingText($p['handling_list']))) ?></div><?php endif ?>
  <div class="hr"></div>
  <b>RINGKASAN</b>
  <?php foreach ($summary as $k => $n): [$j, $m, $mo, $un] = explode('|', $k); ?>
    <div class="row"><span><?= $h(trim("$j $mo")) ?></span><span><?= $n ?> <?= $h($un) ?></span></div>
  <?php endforeach ?>
  <div class="hr"></div>
  <b>RINCIAN BARANG</b>
  <?php foreach ($items as $n => $i): ?>
    <div class="it">
      <div class="code"><?= $n + 1 ?>. <?= $h($i['code']) ?><?= $i['track'] === 'qty' ? ' × ' . (int)$i['qty'] . ' ' . $h($i['unit']) : '' ?></div>
      <div class="sub"><?= $h($i['nama'] ?: $i['jenis']) ?><?= $i['serial'] !== '' ? ' · SN ' . $h($i['serial']) : '' ?><?= $children ? ' · ' . $h($i['package_code']) : '' ?><?= $i['accessories'] ? ' · ' . $h($i['accessories']) : '' ?></div>
    </div>
  <?php endforeach ?>
  <?php if (!$items && !$extras): ?><div class="center">Paket masih kosong</div><?php endif ?>
  <?php if ($extras): ?>
    <div class="hr"></div><b>BARANG MANUAL</b><div class="sub" style="font-size:8pt">(tidak tercatat di gudang)</div>
    <?php foreach ($extras as $n => $x): ?>
      <div class="it"><div class="code"><?= $n + 1 ?>. <?= $h($x['nama']) ?> × <?= (int)$x['qty'] ?> <?= $h($x['unit']) ?></div><?php if ($x['note'] || $children): ?><div class="sub"><?= $h($x['note']) ?><?= $children ? ' · ' . $h($x['package_code']) : '' ?></div><?php endif ?></div>
    <?php endforeach ?>
  <?php endif ?>
  <div class="hr"></div>
  <div class="row"><b>TOTAL</b><b><?= $totalQty ?> barang</b></div>
  <div class="sig"><div>Dipacking<div class="line"></div></div><div>Diterima<div class="line"></div></div></div>
  <div class="center" style="margin-top:8px">- - -</div>
</div>

<?php /* ======================= PACKING LIST A4 ======================= */ elseif ($mode === 'list'): ?>
<div class="doc">
  <header>
    <div>
      <?php if ($company): ?><div class="company"><b><?= $h($company) ?></b><?= $companyAddr ? ' · ' . $h($companyAddr) : '' ?></div><?php endif ?>
      <h1>PACKING LIST</h1>
      <table class="meta">
        <tr><td>Kode Paket</td><td>: <b><?= $h($p['code']) ?></b><?= $p['koli'] ? ' — <b>Koli ' . $h($p['koli']) . '</b>' : '' ?></td></tr>
        <tr><td>Jenis</td><td>: <?= $h($p['type_label']) ?><?= $p['name'] ? ' — ' . $h($p['name']) : '' ?></td></tr>
        <?php if ($ship): ?>
          <tr><td>Surat Jalan</td><td>: <?= $h($ship['code']) ?> (<?= $h($ship['origin_name']) ?> → <?= $h($ship['dest_name']) ?>)</td></tr>
        <?php elseif ($p['destination']): ?><tr><td>Tujuan</td><td>: <?= $h($p['destination']) ?></td></tr><?php endif ?>
        <?php if ($receiver): ?><tr><td>Penerima</td><td>: <?= $h($receiver) ?> <?= $h($receiverPhone) ?></td></tr><?php endif ?>
        <tr><td>Berat / Dimensi</td><td>: <?= $num($p['weight_kg']) ?> kg<?= $p['length_cm'] ? ' · ' . $num($p['length_cm'], 0) . '×' . $num($p['width_cm'], 0) . '×' . $num($p['height_cm'], 0) . ' cm (volumetrik ' . $num($p['vol_weight'], 2) . ' kg)' : '' ?></td></tr>
        <tr><td>No. Segel</td><td>: <?= $h($p['seal_no'] ?: '-') ?></td></tr>
        <tr><td>Status</td><td>: <?= $h($p['stage_label']) ?><?= $p['sealed_at'] ? ' · disegel ' . $dt($p['sealed_at']) : '' ?></td></tr>
        <?php if ($p['handling_list']): ?><tr><td>Penanganan</td><td>: <b><?= $h($handlingText($p['handling_list'])) ?></b></td></tr><?php endif ?>
        <tr><td>Dicetak</td><td>: <?= $now ?></td></tr>
      </table>
    </div>
    <div class="qr" data-code="<?= $h($p['code']) ?>" data-size="110"></div>
  </header>
  <?php if ($p['notes']): ?><p><b>Catatan:</b> <?= nl2br($h($p['notes'])) ?></p><?php endif ?>
  <?php if ($children): ?><p><b>Sub-paket di dalamnya:</b> <?= $h(implode(', ', $children)) ?></p><?php endif ?>

  <h3>Ringkasan</h3>
  <table class="grid">
    <tr><th>Jenis Item</th><th>Merk</th><th>Model</th><th style="width:70px">Qty</th></tr>
    <?php foreach ($summary as $k => $n): [$j, $m, $mo, $un] = explode('|', $k); ?>
      <tr><td><?= $h($j) ?></td><td><?= $h($m) ?></td><td><?= $h($mo) ?></td><td><?= $n ?> <?= $h($un) ?></td></tr>
    <?php endforeach ?>
    <tr><th colspan="3" style="text-align:right">Total</th><th><?= $totalQty ?></th></tr>
  </table>

  <h3>Rincian Barang</h3>
  <table class="grid">
    <tr><th style="width:28px">No</th><th>Kode</th><th>Nama Item</th><th>Merk / Model</th><th>SN</th><th>Kelengkapan</th><th>Qty</th><?php if ($children): ?><th>Di Paket</th><?php endif ?><th style="width:34px">Cek</th></tr>
    <?php foreach ($items as $n => $i): ?>
      <tr>
        <td><?= $n + 1 ?></td>
        <td class="mono"><?= $h($i['code']) ?></td>
        <td><?= $h($i['nama'] ?: $i['jenis']) ?></td>
        <td><?= $h(trim("{$i['merk']} {$i['model']}")) ?></td>
        <td><?= $h($i['serial']) ?></td>
        <td><?= $h($i['accessories']) ?></td>
        <td><?= (int)$i['qty'] ?> <?= $h($i['unit']) ?></td>
        <?php if ($children): ?><td><?= $h($i['package_code']) ?></td><?php endif ?>
        <td></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$items): ?><tr><td colspan="9" style="text-align:center">Tidak ada barang inventaris</td></tr><?php endif ?>
  </table>

  <?php if ($extras): ?>
    <h3>Barang Manual <span style="font-weight:normal;font-size:9pt">(non-inventaris, tidak tercatat di gudang, tanpa kode/barcode)</span></h3>
    <table class="grid">
      <tr><th style="width:28px">No</th><th>Nama Barang</th><th style="width:90px">Qty</th><th>Keterangan</th><?php if ($children): ?><th>Di Paket</th><?php endif ?><th style="width:34px">Cek</th></tr>
      <?php foreach ($extras as $n => $x): ?>
        <tr>
          <td><?= $n + 1 ?></td>
          <td><?= $h($x['nama']) ?></td>
          <td><?= (int)$x['qty'] ?> <?= $h($x['unit']) ?></td>
          <td><?= $h($x['note']) ?></td>
          <?php if ($children): ?><td><?= $h($x['package_code']) ?></td><?php endif ?>
          <td></td>
        </tr>
      <?php endforeach ?>
    </table>
  <?php endif ?>
  <div class="sign">
    <div>Dipacking oleh<div class="line"></div></div>
    <div>Diperiksa oleh<div class="line"></div></div>
    <div>Diterima oleh<div class="line"></div></div>
  </div>
</div>

<?php /* ======================= SURAT JALAN thermal ======================= */ elseif ($mode === 'sj' && $format === 'thermal'): ?>
<div class="thermal">
  <div class="center">
    <?php if ($company): ?><div><?= $h($company) ?></div><?php endif ?>
    <h1><?= $s['kind'] === 'retur' ? 'SURAT JALAN RETUR' : 'SURAT JALAN' ?></h1>
    <div class="qr" data-code="<?= $h($s['code']) ?>" data-size="120"></div>
    <div class="big"><?= $h($s['code']) ?></div>
  </div>
  <div class="hr"></div>
  <div class="row"><span>Dari</span><span><?= $h($s['origin_name']) ?></span></div>
  <div class="row"><span>Kepada</span><span><b><?= $h($s['dest_name']) ?></b></span></div>
  <?php if (!empty($s['dest']['address'])): ?><div><?= $h($s['dest']['address']) ?></div><?php endif ?>
  <div class="row"><span>Tanggal</span><span><?= $dt($s['departed_at'] ?: $s['created_at']) ?></span></div>
  <div><?= $h($transport) ?></div>
  <?php if ($s['reason']): ?><div>Alasan retur: <?= $h($s['reason']) ?></div><?php endif ?>
  <div class="hr"></div>
  <?php foreach ($d['packages'] as $p): ?>
    <div class="it">
      <div class="code">Koli <?= (int)$p['koli_no'] ?>: <?= $h($p['code']) ?></div>
      <div class="sub"><?= $h($p['type_label']) ?> · <?= $p['total_items'] ?> brg · <?= $num($p['weight_kg']) ?> kg<?= $p['seal_no'] ? ' · segel ' . $h($p['seal_no']) : '' ?></div>
    </div>
  <?php endforeach ?>
  <div class="hr"></div>
  <div class="row"><b>TOTAL</b><b><?= count($d['packages']) ?> koli · <?= $d['total_items'] ?> brg</b></div>
  <div class="row"><span>Berat total</span><span><?= $num($d['total_weight']) ?> kg</span></div>
  <?php if ($s['notes']): ?><div>Catatan: <?= $h($s['notes']) ?></div><?php endif ?>
  <div class="sig"><div>Pengirim<div class="line"></div></div><div><?= $s['mode'] === 'ekspedisi' ? 'Ekspedisi' : 'Sopir' ?><div class="line"></div></div></div>
  <div class="sig"><div>Penerima<div class="line"></div></div><div></div></div>
  <div class="center" style="margin-top:8px">- - -</div>
</div>

<?php /* ======================= SURAT JALAN A4 ======================= */ elseif ($mode === 'sj'): ?>
<div class="doc">
  <header>
    <div>
      <?php if ($company): ?><div class="company"><b><?= $h($company) ?></b><?= $companyAddr ? ' · ' . $h($companyAddr) : '' ?></div><?php endif ?>
      <h1><?= $s['kind'] === 'retur' ? 'SURAT JALAN RETUR' : 'SURAT JALAN' ?></h1>
      <table class="meta">
        <tr><td>Nomor</td><td>: <b><?= $h($s['code']) ?></b></td></tr>
        <tr><td>Tanggal</td><td>: <?= $dt($s['departed_at'] ?: $s['created_at']) ?><?= $s['eta'] ? ' · perkiraan tiba ' . $h(date('d-m-Y', strtotime($s['eta']))) : '' ?></td></tr>
        <tr><td>Dari</td><td>: <?= $h($s['origin_name']) ?></td></tr>
        <tr><td>Kepada</td><td>: <b><?= $h($s['dest_name']) ?></b><?= !empty($s['dest']['address']) ? '<br>&nbsp; ' . $h($s['dest']['address']) : '' ?><?= !empty($s['dest']['pic_name']) ? '<br>&nbsp; PIC: ' . $h($s['dest']['pic_name'] . ' ' . $s['dest']['pic_phone']) : '' ?></td></tr>
        <tr><td>Pengangkutan</td><td>: <?= $h($transport ?: '-') ?></td></tr>
        <?php if ($s['reason']): ?><tr><td>Alasan retur</td><td>: <?= $h($s['reason']) ?></td></tr><?php endif ?>
      </table>
    </div>
    <div class="qr" data-code="<?= $h($s['code']) ?>" data-size="110"></div>
  </header>

  <h3>Daftar Koli</h3>
  <table class="grid">
    <tr><th style="width:40px">Koli</th><th>Kode Paket</th><th>Jenis / Nama</th><th>Isi</th><th>Berat (kg)</th><th>Dimensi (cm)</th><th>No. Segel</th><th style="width:40px">Cek</th></tr>
    <?php foreach ($d['packages'] as $p): ?>
      <tr>
        <td><?= (int)$p['koli_no'] ?>/<?= count($d['packages']) ?></td>
        <td class="mono"><b><?= $h($p['code']) ?></b></td>
        <td><?= $h($p['type_label']) ?><?= $p['name'] ? ' — ' . $h($p['name']) : '' ?></td>
        <td><?= $p['total_items'] ?> barang</td>
        <td><?= $num($p['weight_kg']) ?></td>
        <td><?= $p['length_cm'] ? $num($p['length_cm'], 0) . '×' . $num($p['width_cm'], 0) . '×' . $num($p['height_cm'], 0) : '-' ?></td>
        <td><?= $h($p['seal_no'] ?: '-') ?></td>
        <td></td>
      </tr>
    <?php endforeach ?>
    <tr><th colspan="3" style="text-align:right">Total</th><th><?= $d['total_items'] ?> barang</th><th><?= $num($d['total_weight']) ?></th><th colspan="3"><?= count($d['packages']) ?> koli</th></tr>
  </table>

  <h3>Rincian Isi</h3>
  <table class="grid">
    <tr><th style="width:40px">Koli</th><th>Kode Barang</th><th>Nama Item</th><th>SN</th><th>Kelengkapan</th><th>Qty</th></tr>
    <?php foreach ($expected as $i): ?>
      <tr>
        <td><?= (int)$i['koli_no'] ?></td>
        <td class="mono"><?= $dc($i['code']) ?></td>
        <td><?= $h($i['nama'] ?: $i['jenis']) ?></td>
        <td><?= $h($i['serial']) ?></td>
        <td><?= $h($i['accessories']) ?></td>
        <td><?= (int)$i['qty'] ?> <?= $h($i['unit']) ?></td>
      </tr>
    <?php endforeach ?>
  </table>
  <?php if ($s['notes']): ?><p><b>Catatan:</b> <?= nl2br($h($s['notes'])) ?></p><?php endif ?>
  <p style="font-size:8.5pt">Barang di atas diterima dalam keadaan baik dan segel utuh, kecuali dicatat lain pada Berita Acara Serah Terima.</p>
  <div class="sign">
    <div>Pengirim<div class="line"><?= $h($s['created_by_name']) ?></div></div>
    <div><?= $s['mode'] === 'ekspedisi' ? 'Ekspedisi' : 'Sopir' ?><div class="line"><?= $h($s['mode'] === 'ekspedisi' ? $s['courier'] : $s['driver']) ?></div></div>
    <div>Penerima<div class="line"></div></div>
  </div>
</div>

<?php /* ======================= BAST ======================= */ elseif ($mode === 'bast'):
    $issues = array_filter($d['receipts'], function ($r) { return $r['cond'] !== 'ok' || $r['note']; });
?>
<?php if ($format === 'thermal'): ?>
<div class="thermal">
  <div class="center">
    <?php if ($company): ?><div><?= $h($company) ?></div><?php endif ?>
    <h1>BERITA ACARA<br>SERAH TERIMA</h1>
    <div class="big"><?= $h($s['code']) ?></div>
    <div class="inv"><?= $h(strtoupper($s['result_label'])) ?></div>
  </div>
  <div class="hr"></div>
  <div class="row"><span>Dari</span><span><?= $h($s['origin_name']) ?></span></div>
  <div class="row"><span>Kepada</span><span><?= $h($s['dest_name']) ?></span></div>
  <div class="row"><span>Berangkat</span><span><?= $dt($s['departed_at']) ?></span></div>
  <div class="row"><span>Diterima</span><span><?= $dt($s['received_at']) ?></span></div>
  <div class="row"><span>Penerima</span><span><?= $h($s['receiver_name']) ?></span></div>
  <div class="row"><span>Metode cek</span><span><?= $s['receive_mode'] === 'koli' ? 'Per koli' : 'Per barang' ?></span></div>
  <div class="hr"></div>
  <b>KOLI (<?= $d['arrived'] ?>/<?= count($d['packages']) ?> tiba)</b>
  <?php foreach ($d['packages'] as $p): $a = $p['arrival']; ?>
    <div class="row"><span><?= (int)$p['koli_no'] ?>. <?= $h($p['code']) ?></span><span><?= !$a ? 'TIDAK TIBA' : ($a['seal_ok'] === null ? 'tiba' : ((int)$a['seal_ok'] ? 'segel utuh' : 'SEGEL RUSAK')) ?></span></div>
  <?php endforeach ?>
  <div class="hr"></div>
  <b>HASIL PEMERIKSAAN</b>
  <?php foreach (RECEIPT_CONDITIONS as $k => $lbl): if (!$d['receipt_summary'][$k]) continue; ?>
    <div class="row"><span><?= $h($lbl) ?></span><span><?= $d['receipt_summary'][$k] ?></span></div>
  <?php endforeach ?>
  <?php if ($issues): ?>
    <div class="hr"></div><b>CATATAN BARANG</b>
    <?php foreach ($issues as $r): ?>
      <div class="it"><div class="code"><?= $dc($r['code']) ?> <?= $h($r['nama']) ?></div><div class="sub"><?= $h(RECEIPT_CONDITIONS[$r['cond']]) ?> · <?= (int)$r['qty'] ?> <?= $h($r['unit'] ?: 'unit') ?><?= $r['note'] ? ' · ' . $h($r['note']) : '' ?></div></div>
    <?php endforeach ?>
  <?php endif ?>
  <?php if ($s['receive_notes']): ?><div class="hr"></div><div>Catatan: <?= $h($s['receive_notes']) ?></div><?php endif ?>
  <div class="hr"></div>
  <div class="center">Penerima,<br><img src="photo.php?sig=<?= urlencode($s['code']) ?>" style="height:60px"><br><b><?= $h($s['receiver_name']) ?></b></div>
  <div class="center" style="margin-top:8px">- - -</div>
</div>
<?php else: ?>
<div class="doc">
  <header>
    <div>
      <?php if ($company): ?><div class="company"><b><?= $h($company) ?></b><?= $companyAddr ? ' · ' . $h($companyAddr) : '' ?></div><?php endif ?>
      <h1>BERITA ACARA SERAH TERIMA BARANG</h1>
      <table class="meta">
        <tr><td>No. Surat Jalan</td><td>: <b><?= $h($s['code']) ?></b> <?= $s['kind'] === 'retur' ? '(Retur)' : '' ?></td></tr>
        <tr><td>Dari → Kepada</td><td>: <?= $h($s['origin_name']) ?> → <b><?= $h($s['dest_name']) ?></b></td></tr>
        <tr><td>Pengangkutan</td><td>: <?= $h($transport ?: '-') ?></td></tr>
        <tr><td>Berangkat</td><td>: <?= $dt($s['departed_at']) ?></td></tr>
        <tr><td>Diterima</td><td>: <?= $dt($s['received_at']) ?> oleh <b><?= $h($s['receiver_name']) ?></b></td></tr>
        <tr><td>Metode cek</td><td>: <?= $s['receive_mode'] === 'koli' ? 'Per koli (label & segel dicek, isi tidak discan satu per satu)' : 'Per barang (setiap isi discan)' ?></td></tr>
        <tr><td>Hasil</td><td>: <b class="<?= ($s['receive_result'] ?: $s['status']) === 'received' ? '' : 'bad' ?>"><?= $h($s['result_label']) ?></b><?= $s['status'] === 'resolved' ? ' — selisih sudah ditindaklanjuti ' . $dt($s['resolved_at']) : '' ?></td></tr>
      </table>
    </div>
    <div class="qr" data-code="<?= $h($s['code']) ?>" data-size="100"></div>
  </header>

  <h3>Penerimaan Koli</h3>
  <table class="grid">
    <tr><th>Koli</th><th>Kode Paket</th><th>No. Segel</th><th>Segel dicek</th><th>Kondisi Segel</th><th>Catatan</th></tr>
    <?php foreach ($d['packages'] as $p): $a = $p['arrival']; ?>
      <tr>
        <td><?= (int)$p['koli_no'] ?>/<?= count($d['packages']) ?></td>
        <td class="mono"><?= $h($p['code']) ?></td>
        <td><?= $h($p['seal_no'] ?: '-') ?></td>
        <td><?= $h($a['seal_no_checked'] ?? '-') ?></td>
        <td><?= !$a ? '<span class="bad">TIDAK TIBA</span>' : ($a['seal_ok'] === null ? 'Tidak dicek' : ((int)$a['seal_ok'] ? 'Utuh' : '<span class="bad">RUSAK / TERBUKA</span>')) ?></td>
        <td><?= $h($a['note'] ?? '') ?></td>
      </tr>
    <?php endforeach ?>
  </table>

  <h3>Ringkasan Pemeriksaan Barang</h3>
  <table class="grid">
    <tr><?php foreach (RECEIPT_CONDITIONS as $lbl): ?><th><?= $h($lbl) ?></th><?php endforeach ?></tr>
    <tr><?php foreach (RECEIPT_CONDITIONS as $k => $lbl): ?><td style="text-align:center" class="<?= $k !== 'ok' && $d['receipt_summary'][$k] ? 'bad' : '' ?>"><?= $d['receipt_summary'][$k] ?></td><?php endforeach ?></tr>
  </table>

  <h3>Rincian Barang</h3>
  <table class="grid">
    <tr><th style="width:28px">No</th><th>Kode</th><th>Nama Item</th><th>SN</th><th>Koli/Paket</th><th>Qty</th><th>Kondisi</th><th>Catatan</th></tr>
    <?php foreach (array_reverse($d['receipts']) as $n => $r): ?>
      <tr>
        <td><?= $n + 1 ?></td>
        <td class="mono"><?= $dc($r['code']) ?></td>
        <td><?= $h($r['nama'] ?: $r['jenis']) ?></td>
        <td><?= $h($r['serial']) ?></td>
        <td><?= $h($r['package_code'] ?: '-') ?></td>
        <td><?= (int)$r['qty'] ?> <?= $h($r['unit'] ?: 'unit') ?></td>
        <td class="<?= $r['cond'] !== 'ok' ? 'bad' : '' ?>"><?= $h(RECEIPT_CONDITIONS[$r['cond']]) ?></td>
        <td><?= $h($r['note']) ?></td>
      </tr>
    <?php endforeach ?>
  </table>
  <?php if ($s['receive_notes']): ?><p><b>Catatan penerimaan:</b> <?= nl2br($h($s['receive_notes'])) ?></p><?php endif ?>
  <p style="font-size:8.5pt">Dengan ini pihak penerima menyatakan telah menerima barang sesuai rincian dan kondisi di atas.</p>
  <div class="sign">
    <div>Diserahkan oleh<br><?= $s['mode'] === 'ekspedisi' ? 'Ekspedisi' : 'Sopir' ?><div class="line"><?= $h($s['mode'] === 'ekspedisi' ? $s['courier'] : $s['driver']) ?></div></div>
    <div>Diterima oleh<br><?= $h($s['dest_name']) ?><img src="photo.php?sig=<?= urlencode($s['code']) ?>" alt=""><div class="line"><b><?= $h($s['receiver_name']) ?></b></div></div>
  </div>
</div>
<?php endif ?>
<?php /* ======================= BERITA ACARA SELISIH & TINDAK LANJUT ======================= */ elseif ($mode === 'selisih'):
    $resLabel = function ($r) { return $r['resolution_label'] ?: 'BELUM DITINDAKLANJUTI'; };
    $issueLabel = function ($r) { return $r['cond'] === 'ok' ? 'Jumlah kurang ' . (int)$r['short_qty'] . ' ' . ($r['unit'] ?: 'unit') : RECEIPT_CONDITIONS[$r['cond']]; };
    $openCount = count(array_filter($issues, function ($r) { return !$r['resolution']; }));
?>
<?php if ($format === 'thermal'): ?>
<div class="thermal">
  <div class="center">
    <?php if ($company): ?><div><?= $h($company) ?></div><?php endif ?>
    <h1>BERITA ACARA<br>SELISIH BARANG</h1>
    <div class="big"><?= $h($s['code']) ?></div>
    <div class="inv"><?= $openCount ? $openCount . ' BELUM SELESAI' : 'SELESAI DITINDAKLANJUTI' ?></div>
  </div>
  <div class="hr"></div>
  <div class="row"><span>Dari</span><span><?= $h($s['origin_name']) ?></span></div>
  <div class="row"><span>Kepada</span><span><?= $h($s['dest_name']) ?></span></div>
  <div class="row"><span>Diterima</span><span><?= $dt($s['received_at']) ?></span></div>
  <div class="row"><span>Hasil terima</span><span><?= $h($s['result_label']) ?></span></div>
  <div class="hr"></div>
  <?php foreach ($issues as $n => $r): ?>
    <div class="it">
      <div class="code"><?= $n + 1 ?>. <?= $h($r['code']) ?></div>
      <div class="sub"><?= $h($r['nama'] ?: $r['jenis']) ?> · <?= $h($issueLabel($r)) ?></div>
      <div class="sub"><b>→ <?= $h($resLabel($r)) ?></b><?= $r['resolution_ref'] ? ' · ' . $h($r['resolution_ref']) : '' ?><?= $r['resolution_note'] ? ' · ' . $h($r['resolution_note']) : '' ?></div>
    </div>
  <?php endforeach ?>
  <?php if (!$issues): ?><div class="center">Tidak ada selisih barang.</div><?php endif ?>
  <div class="hr"></div>
  <div class="sig"><div>Cabang asal<div class="line"></div></div><div>Cabang tujuan<div class="line"></div></div></div>
  <div class="center" style="margin-top:8px">- - -</div>
</div>
<?php else: ?>
<div class="doc">
  <header>
    <div>
      <?php if ($company): ?><div class="company"><b><?= $h($company) ?></b><?= $companyAddr ? ' · ' . $h($companyAddr) : '' ?></div><?php endif ?>
      <h1>BERITA ACARA SELISIH &amp; TINDAK LANJUT</h1>
      <table class="meta">
        <tr><td>No. Surat Jalan</td><td>: <b><?= $h($s['code']) ?></b></td></tr>
        <tr><td>Dari → Kepada</td><td>: <?= $h($s['origin_name']) ?> → <b><?= $h($s['dest_name']) ?></b></td></tr>
        <tr><td>Diterima</td><td>: <?= $dt($s['received_at']) ?> oleh <?= $h($s['receiver_name']) ?></td></tr>
        <tr><td>Hasil penerimaan</td><td>: <b class="bad"><?= $h($s['result_label']) ?></b></td></tr>
        <tr><td>Status tindak lanjut</td><td>: <b class="<?= $openCount ? 'bad' : '' ?>"><?= $openCount ? $openCount . ' selisih belum ditindaklanjuti' : 'Selesai' . ($s['resolved_at'] ? ' — ' . $dt($s['resolved_at']) : '') ?></b></td></tr>
        <tr><td>Dicetak</td><td>: <?= $now ?></td></tr>
      </table>
    </div>
    <div class="qr" data-code="<?= $h($s['code']) ?>" data-size="100"></div>
  </header>

  <table class="grid">
    <tr><th style="width:24px">No</th><th>Kode / Nama Barang</th><th>Koli/Paket</th><th>Selisih</th><th>Catatan Penerimaan</th><th>Tindak Lanjut</th><th>No. Dokumen / Nilai</th><th>Oleh / Tanggal</th></tr>
    <?php foreach ($issues as $n => $r): ?>
      <tr>
        <td><?= $n + 1 ?></td>
        <td><span class="mono"><?= $dc($r['code']) ?></span><br><?= $h($r['nama'] ?: $r['jenis']) ?><?= $r['serial'] ? ' · SN ' . $h($r['serial']) : '' ?></td>
        <td><?= $h($r['package_code'] ?: '-') ?></td>
        <td class="bad"><?= $h($issueLabel($r)) ?><?= $r['cond'] === 'kurang' ? '<br>' . (int)$r['qty'] . ' ' . $h($r['unit'] ?: 'unit') : '' ?></td>
        <td><?= $h($r['note']) ?></td>
        <td class="<?= $r['resolution'] ? '' : 'bad' ?>"><b><?= $h($resLabel($r)) ?></b><?= $r['resolution_note'] ? '<br>' . $h($r['resolution_note']) : '' ?></td>
        <td><?= $h($r['resolution_ref']) ?><?= $r['resolution_value'] ? '<br>Rp ' . number_format((float)$r['resolution_value'], 0, ',', '.') : '' ?></td>
        <td><?= $h($r['resolved_by_name']) ?><br><?= $r['resolved_at'] ? $dt($r['resolved_at']) : '' ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$issues): ?><tr><td colspan="8" style="text-align:center">Tidak ada selisih barang.</td></tr><?php endif ?>
  </table>
  <?php if ($s['receive_notes']): ?><p><b>Catatan:</b> <?= nl2br($h($s['receive_notes'])) ?></p><?php endif ?>
  <p style="font-size:8.5pt">Berita acara ini dibuat sebagai dasar penyelesaian selisih antara barang yang dikirim dan yang diterima.</p>
  <div class="sign">
    <div>Cabang asal<br><?= $h($s['origin_name']) ?><div class="line"></div></div>
    <div><?= $s['mode'] === 'ekspedisi' ? 'Ekspedisi' : 'Sopir' ?><br><?= $h($s['mode'] === 'ekspedisi' ? $s['courier'] : $s['driver']) ?><div class="line"></div></div>
    <div>Cabang tujuan<br><?= $h($s['dest_name']) ?><div class="line"></div></div>
  </div>
</div>
<?php endif ?>
<?php endif ?>

<script src="vendor/qrcode.js"></script>
<script>
  document.querySelectorAll('.qr').forEach(function (el) {
    var qr = qrcode(0, 'M');
    qr.addData(el.dataset.code);
    qr.make();
    var size = +el.dataset.size, n = qr.getModuleCount(), cell = Math.max(1, Math.floor(size / (n + 2)));
    el.innerHTML = qr.createImgTag(cell, cell);
  });
</script>
</body>
</html>
