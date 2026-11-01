/* Cetak langsung ke printer thermal Bluetooth (ESC/POS).
 * Metode:
 *  - rawbt : lewat aplikasi Android "RawBT" (mendukung Bluetooth classic & BLE, jalan di HTTP)
 *  - ble   : Web Bluetooth langsung dari Chrome (hanya printer BLE, wajib HTTPS/localhost)
 */
'use strict';

const BtPrint = (() => {
  const ESC = 0x1b, GS = 0x1d;

  // UUID service yang umum dipakai printer thermal BLE
  const SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb',
    '0000ae30-0000-1000-8000-00805f9b34fb',
    '0000af30-0000-1000-8000-00805f9b34fb',
  ];

  const settings = () => ({
    method: localStorage.getItem('btMethod') || (bleAvailable() ? 'ble' : 'rawbt'),
    paper: +(localStorage.getItem('btPaper') || 80),
  });
  const bleAvailable = () => !!(navigator.bluetooth && window.isSecureContext);

  // ---------- Pembentuk perintah ESC/POS ----------
  class Doc {
    constructor(paper) {
      this.paper = paper;
      this.cols = paper === 58 ? 32 : 48;          // jumlah karakter per baris (font A)
      this.dots = paper === 58 ? 384 : 576;        // lebar cetak dalam dot
      this.buf = [ESC, 0x40];                      // inisialisasi printer
    }
    raw(...b) { this.buf.push(...b); return this; }
    align(a) { return this.raw(ESC, 0x61, { left: 0, center: 1, right: 2 }[a]); }
    bold(on) { return this.raw(ESC, 0x45, on ? 1 : 0); }
    size(w, h) { return this.raw(GS, 0x21, ((w - 1) << 4) | (h - 1)); }
    text(s) {
      const clean = String(s ?? '')
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[·•]/g, '-').replace(/[—–]/g, '-').replace(/›/g, '>')
        .replace(/[^\x20-\x7e\n]/g, '?');
      for (let i = 0; i < clean.length; i++) this.buf.push(clean.charCodeAt(i));
      return this;
    }
    line(s) { return this.text(s).raw(0x0a); }
    wrap(s, cols) {
      cols = cols || this.cols;
      const words = String(s ?? '').split(/\s+/);
      let cur = '';
      for (const w of words) {
        if ((cur + ' ' + w).trim().length > cols && cur) { this.line(cur); cur = w; } else cur = (cur + ' ' + w).trim();
        while (cur.length > cols) { this.line(cur.slice(0, cols)); cur = cur.slice(cols); }
      }
      if (cur) this.line(cur);
      return this;
    }
    row(left, right) {
      left = String(left ?? ''); right = String(right ?? '');
      const space = this.cols - right.length - 1;
      if (left.length > space) { this.wrap(left); return this.line(' '.repeat(Math.max(0, this.cols - right.length)) + right); }
      return this.line(left + ' '.repeat(this.cols - left.length - right.length) + right);
    }
    hr(ch) { return this.line((ch || '-').repeat(this.cols)); }
    feed(n) { return this.raw(ESC, 0x64, n || 1); }
    cut() { return this.feed(4).raw(GS, 0x56, 0x42, 0x00); }

    // QR dicetak sebagai gambar raster (GS v 0) agar didukung hampir semua printer ESC/POS
    qr(data, sizeDots) {
      const qr = qrcode(0, 'M');
      qr.addData(data);
      qr.make();
      const n = qr.getModuleCount(), quiet = 2;
      const scale = Math.max(2, Math.floor(sizeDots / (n + quiet * 2)));
      const w = (n + quiet * 2) * scale;
      const bw = Math.ceil(w / 8);
      const bytes = new Uint8Array(bw * w);
      for (let y = 0; y < w; y++) {
        const r = Math.floor(y / scale) - quiet;
        for (let x = 0; x < w; x++) {
          const c = Math.floor(x / scale) - quiet;
          if (r >= 0 && c >= 0 && r < n && c < n && qr.isDark(r, c)) bytes[y * bw + (x >> 3)] |= 0x80 >> (x & 7);
        }
      }
      this.align('center').raw(GS, 0x76, 0x30, 0x00, bw & 0xff, bw >> 8, w & 0xff, w >> 8);
      for (let i = 0; i < bytes.length; i++) this.buf.push(bytes[i]);
      return this.raw(0x0a);
    }
    bytes() { return new Uint8Array(this.buf); }
  }

  // ---------- Template ----------
  const num = (n) => (n === null || n === undefined || n === '') ? '-' : String(+(+n).toFixed(2)).replace('.', ',');
  const sumQty = (items) => items.reduce((a, i) => a + (+i.qty || 1), 0);
  // Barang manual tidak punya kode / barcode
  const dispCode = (c) => String(c).indexOf('MANUAL-') === 0 ? '(manual)' : c;
  const handlingText = (d, list) => (list || []).map((k) => (d.handling_labels && d.handling_labels[k]) || k).join(' - ');

  function signatures(doc, left, right) {
    const half = Math.floor(doc.cols / 2);
    const pad = (s) => s.padStart(Math.floor((half + s.length) / 2)).padEnd(half);
    doc.feed(1).align('center').line(pad(left) + pad(right)).feed(3).line(pad('(__________)') + pad('(__________)'));
  }

  // Label koli: ditempel di dus / box / container
  function packageLabel(d, paper) {
    const p = d.package, s = d.shipment;
    const doc = new Doc(paper);
    if (d.company) doc.align('center').line(d.company);
    doc.align('center').bold(true).size(2, 2).line(p.type_label.toUpperCase()).size(1, 1).bold(false);
    doc.qr(p.code, paper === 58 ? 280 : 360);
    doc.align('center').bold(true).size(2, 2).line(p.code).size(1, 1).bold(false);
    if (p.koli) doc.bold(true).size(3, 3).line('KOLI ' + p.koli).size(1, 1).bold(false).line(p.shipment_code || '');
    if (p.name) doc.wrap(p.name);
    doc.align('left').hr('=');
    if (s) {
      doc.line('DARI  : ' + s.origin_name);
      doc.bold(true).line('KEPADA:').size(1, 2).wrap(s.dest_name).size(1, 1).bold(false);
      if (s.dest_address) doc.wrap(s.dest_address);
    } else if (p.destination) {
      doc.bold(true).size(1, 2).wrap('TUJUAN: ' + p.destination).size(1, 1).bold(false);
    }
    const rcv = p.receiver_name || (s && s.dest_pic);
    const phone = p.receiver_phone || (s && s.dest_phone);
    if (rcv) doc.wrap('Penerima: ' + rcv + (phone ? ' ' + phone : ''));
    doc.hr();
    doc.row('Isi', sumQty(d.items) + ' barang' + ((d.extras || []).length ? ' + ' + d.extras.length + ' manual' : '') + (d.children.length ? ', ' + d.children.length + ' sub-paket' : ''));
    doc.row('Berat', num(p.weight_kg) + ' kg');
    if (p.seal_no) doc.row('No. segel', p.seal_no);
    if (p.handling_list && p.handling_list.length) {
      doc.hr().align('center').bold(true).raw(0x1d, 0x42, 1).wrap(' ' + handlingText(d, p.handling_list).toUpperCase() + ' ').raw(0x1d, 0x42, 0).bold(false);
    }
    return doc.cut().bytes();
  }

  function packingList(d, paper) {
    const p = d.package, s = d.shipment;
    const doc = new Doc(paper);
    if (d.company) doc.align('center').line(d.company);
    doc.align('center').bold(true).size(1, 2).line('PACKING LIST').size(1, 1).bold(false);
    doc.qr(p.code, paper === 58 ? 200 : 260);
    doc.align('center').bold(true).size(2, 1).line(p.code).size(1, 1).bold(false);
    if (p.koli) doc.bold(true).line('KOLI ' + p.koli).bold(false);
    doc.align('left').hr();
    doc.row('Jenis', p.type_label);
    if (p.name) doc.row('Nama', p.name);
    if (s) { doc.row('Surat jalan', s.code); doc.row('Tujuan', s.dest_name); } else if (p.destination) doc.row('Tujuan', p.destination);
    doc.row('Berat', num(p.weight_kg) + ' kg');
    if (p.seal_no) doc.row('No. segel', p.seal_no);
    doc.row('Status', p.stage_label || '');
    doc.row('Dicetak', d.now);
    if (d.children.length) doc.wrap('Sub-paket: ' + d.children.join(', '));
    if (p.notes) doc.wrap('Catatan: ' + p.notes);
    if (p.handling_list && p.handling_list.length) doc.bold(true).wrap(handlingText(d, p.handling_list).toUpperCase()).bold(false);

    const summary = {};
    d.items.forEach((i) => { const k = (i.jenis + ' ' + (i.model || '')).trim(); summary[k] = (summary[k] || 0) + (+i.qty || 1); });
    doc.hr().bold(true).line('RINGKASAN').bold(false);
    Object.entries(summary).forEach(([k, n]) => doc.row(k, String(n)));

    doc.hr().bold(true).line('RINCIAN BARANG').bold(false);
    d.items.forEach((i, n) => {
      doc.bold(true).wrap((n + 1) + '. ' + i.code + (i.track === 'qty' ? ' x' + i.qty + ' ' + i.unit : '')).bold(false);
      doc.wrap('   ' + (i.nama || i.jenis) + (i.serial ? ' - SN ' + i.serial : '') + (d.children.length ? ' - ' + i.package_code : '') + (i.accessories ? ' - ' + i.accessories : ''));
    });
    if (!d.items.length && !(d.extras || []).length) doc.align('center').line('Paket masih kosong').align('left');
    if ((d.extras || []).length) {
      doc.hr().bold(true).line('BARANG MANUAL').bold(false).line('(tidak tercatat di gudang)');
      d.extras.forEach((x, n) => doc.wrap((n + 1) + '. ' + x.nama + ' x' + x.qty + ' ' + x.unit + (x.note ? ' - ' + x.note : '')));
    }
    doc.hr().bold(true).row('TOTAL', sumQty(d.items) + ' barang' + ((d.extras || []).length ? ' + ' + d.extras.length + ' manual' : '')).bold(false);
    signatures(doc, 'Dipacking', 'Diterima');
    return doc.cut().bytes();
  }

  // Surat jalan: data dari API "shipment" dengan expected=1
  function suratJalan(d, paper) {
    const s = d.shipment;
    const doc = new Doc(paper);
    doc.align('center').bold(true).size(1, 2).line(s.kind === 'retur' ? 'SURAT JALAN RETUR' : 'SURAT JALAN').size(1, 1).bold(false);
    doc.qr(s.code, paper === 58 ? 200 : 240);
    doc.align('center').bold(true).size(2, 1).line(s.code).size(1, 1).bold(false);
    doc.align('left').hr();
    doc.row('Tanggal', (s.departed_at || s.created_at || '').slice(0, 16));
    doc.row('Dari', s.origin_name);
    doc.bold(true).row('Kepada', s.dest_name).bold(false);
    if (s.dest && s.dest.address) doc.wrap(s.dest.address);
    if (s.dest && s.dest.pic_name) doc.wrap('PIC: ' + s.dest.pic_name + ' ' + (s.dest.pic_phone || ''));
    if (s.mode === 'ekspedisi') { doc.row('Ekspedisi', s.courier || '-'); if (s.tracking_no) doc.row('Resi', s.tracking_no); }
    else { doc.row('Kendaraan', [s.vehicle, s.plate].filter(Boolean).join(' ') || '-'); doc.row('Sopir', [s.driver, s.driver_phone].filter(Boolean).join(' ') || '-'); }
    if (s.reason) doc.wrap('Alasan retur: ' + s.reason);
    doc.hr().bold(true).line('DAFTAR KOLI').bold(false);
    d.packages.forEach((p) => {
      doc.bold(true).row('Koli ' + p.koli_no + ': ' + p.code, num(p.weight_kg) + ' kg').bold(false);
      doc.wrap('   ' + p.type_label + ' - ' + p.total_items + ' barang' + (p.seal_no ? ' - segel ' + p.seal_no : ''));
    });
    doc.hr().bold(true).row('TOTAL', d.packages.length + ' koli / ' + d.total_items + ' brg').bold(false);
    doc.row('Berat total', num(d.total_weight) + ' kg');
    if (d.expected && d.expected.length) {
      doc.hr().bold(true).line('RINCIAN ISI').bold(false);
      d.expected.forEach((i) => doc.wrap('[' + i.koli_no + '] ' + dispCode(i.code) + (i.track === 'qty' ? ' x' + i.qty : '') + ' ' + (i.nama || i.jenis || '')));
    }
    if (s.notes) doc.hr().wrap('Catatan: ' + s.notes);
    signatures(doc, 'Pengirim', s.mode === 'ekspedisi' ? 'Ekspedisi' : 'Sopir');
    signatures(doc, 'Penerima', '');
    return doc.cut().bytes();
  }

  // Berita Acara Serah Terima: data dari API "shipment" setelah diterima
  function bast(d, paper) {
    const s = d.shipment;
    const doc = new Doc(paper);
    doc.align('center').bold(true).line('BERITA ACARA SERAH TERIMA').size(2, 1).line(s.code).size(1, 1).bold(false);
    doc.raw(0x1d, 0x42, 1).bold(true).line(' ' + (s.result_label || s.status_label || '').toUpperCase() + ' ').bold(false).raw(0x1d, 0x42, 0);
    doc.align('left').hr();
    doc.row('Dari', s.origin_name);
    doc.row('Kepada', s.dest_name);
    doc.row('Berangkat', (s.departed_at || '').slice(0, 16));
    doc.row('Diterima', (s.received_at || '').slice(0, 16));
    doc.row('Penerima', s.receiver_name || '');
    doc.row('Metode cek', s.receive_mode === 'koli' ? 'Per koli' : 'Per barang');
    doc.hr().bold(true).line('KOLI (' + d.arrived + '/' + d.packages.length + ' tiba)').bold(false);
    d.packages.forEach((p) => {
      const a = p.arrival;
      doc.row(p.koli_no + '. ' + p.code, !a ? 'TIDAK TIBA' : a.seal_ok === null ? 'tiba' : (+a.seal_ok ? 'segel utuh' : 'SEGEL RUSAK'));
    });
    doc.hr().bold(true).line('HASIL PEMERIKSAAN').bold(false);
    const labels = { ok: 'Baik', rusak: 'Rusak', tidak_lengkap: 'Kelengkapan kurang', kurang: 'Tidak ada / kurang', lebih: 'Lebih' };
    Object.keys(labels).forEach((k) => { if (d.receipt_summary[k]) doc.row(labels[k], String(d.receipt_summary[k])); });
    const issues = d.receipts.filter((r) => r.cond !== 'ok' || r.note);
    if (issues.length) {
      doc.hr().bold(true).line('CATATAN BARANG').bold(false);
      issues.forEach((r) => { doc.bold(true).wrap(dispCode(r.code) + ' ' + (r.nama || '')).bold(false).wrap('   ' + labels[r.cond] + ' - ' + r.qty + ' ' + (r.unit || 'unit') + (r.note ? ' - ' + r.note : '')); });
    }
    if (s.receive_notes) doc.hr().wrap('Catatan: ' + s.receive_notes);
    doc.hr().align('center').line('Ditandatangani digital oleh').bold(true).line(s.receiver_name || '').bold(false);
    signatures(doc, s.mode === 'ekspedisi' ? 'Ekspedisi' : 'Sopir', 'Penerima');
    return doc.cut().bytes();
  }

  // ---------- Pengiriman ----------
  function toBase64(bytes) {
    let s = '';
    for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return btoa(s);
  }

  function sendRawBT(bytes) {
    // Membuka aplikasi RawBT; jika belum terpasang Android akan mengarahkan ke Play Store
    location.href = 'intent:base64,' + toBase64(bytes) + '#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;';
  }

  let bleChar = null;
  async function connectBle(forceChoose) {
    if (!bleAvailable()) throw new Error('Web Bluetooth tidak tersedia. Butuh Chrome Android + HTTPS. Gunakan metode RawBT.');
    if (bleChar && !forceChoose && bleChar.service.device.gatt.connected) return bleChar;

    let device = null;
    if (!forceChoose && navigator.bluetooth.getDevices) {
      const known = await navigator.bluetooth.getDevices();
      const saved = localStorage.getItem('btDeviceId');
      device = known.find((d) => d.id === saved) || null;
    }
    if (!device) {
      device = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: SERVICES });
      localStorage.setItem('btDeviceId', device.id);
      localStorage.setItem('btDeviceName', device.name || 'Printer');
    }
    const server = await device.gatt.connect();
    const services = await server.getPrimaryServices();
    for (const svc of services) {
      let chars = [];
      try { chars = await svc.getCharacteristics(); } catch (e) { continue; }
      const ch = chars.find((c) => c.properties.writeWithoutResponse || c.properties.write);
      if (ch) { bleChar = ch; return ch; }
    }
    server.disconnect();
    throw new Error('Printer tidak menyediakan layanan cetak BLE yang dikenali. Gunakan metode RawBT.');
  }

  async function sendBle(bytes) {
    const ch = await connectBle(false);
    const size = 100;
    for (let i = 0; i < bytes.length; i += size) {
      const chunk = bytes.subarray(i, i + size);
      if (ch.properties.writeWithoutResponse && ch.writeValueWithoutResponse) await ch.writeValueWithoutResponse(chunk);
      else await ch.writeValue(chunk);
      await new Promise((r) => setTimeout(r, 15)); // beri waktu buffer printer
    }
  }

  async function print(bytes) {
    const s = settings();
    if (s.method === 'ble') await sendBle(bytes);
    else sendRawBT(bytes);
  }

  return { settings, bleAvailable, connectBle, print, packageLabel, packingList, suratJalan, bast };
})();
