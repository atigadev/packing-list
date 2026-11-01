/* Paket: daftar, detail (isi & verifikasi), form, segel, cetak; halaman Cek Paket. */
'use strict';

// ---------- Daftar paket ----------
let pkgFilter = { q: '', status: 'active' };
ROUTES.packages = async function viewPackages() {
  const hq = hashQuery();
  if (hq) pkgFilter.status = hq;
  const tabs = [['active', 'Aktif'], ['open', 'Dipacking'], ['sealed', 'Siap kirim'], ['in_transit', 'Dikirim'], ['received', 'Diterima']];
  $view.innerHTML = `
    <div class="row between"><h2>Paket / Koli</h2>${can('gudang', 'cabang') ? '<button class="btn" id="newPkg">➕ Baru</button>' : ''}</div>
    <div class="card">
      <input id="q" placeholder="Cari kode, nama, tujuan, no. segel…" value="${esc(pkgFilter.q)}">
      <div class="tabs" style="margin:10px 0 0">${tabs.map(([k, l]) => `<button data-s="${k}" class="${pkgFilter.status === k ? 'active' : ''}">${l}</button>`).join('')}</div>
    </div>
    <div class="card"><div id="list">Memuat…</div></div>`;
  const nb = document.getElementById('newPkg');
  if (nb) nb.onclick = () => newPackageDialog();
  const load = async () => {
    const r = await get('packages', pkgFilter);
    const el = document.getElementById('list');
    if (!el) return;
    el.innerHTML = r.packages.length ? `<ul class="list">${r.packages.map((p) => `
      <li class="click" data-href="#/package/${enc(p.code)}"><span class="ico">${TYPE_ICON[p.type] || '📦'}</span>
        <div class="grow"><b class="mono">${esc(p.code)}</b> ${p.name ? '· ' + esc(p.name) : ''}
          <div class="muted small">${esc(p.type_label)} · ${p.total_items} barang${+p.child_count ? ' · ' + p.child_count + ' sub-paket' : ''}${p.weight_kg ? ' · ' + fmtNum(p.weight_kg) + ' kg' : ''}${p.parent_code ? ' · di ' + esc(p.parent_code) : ''}${p.shipment_code ? ' · ' + esc(p.shipment_code) + (p.koli_no ? ' koli ' + p.koli_no : '') : ''}</div></div>
        ${stageBadge(p.stage)}</li>`).join('')}</ul>` : '<div class="empty">Tidak ada paket</div>';
    bindList(el);
  };
  let t;
  document.getElementById('q').oninput = (e) => { pkgFilter.q = e.target.value; clearTimeout(t); t = setTimeout(load, 250); };
  $view.querySelectorAll('[data-s]').forEach((b) => (b.onclick = () => {
    pkgFilter.status = b.dataset.s;
    $view.querySelectorAll('[data-s]').forEach((x) => x.classList.toggle('active', x === b));
    load();
  }));
  load();
};

function packageForm(p) {
  p = p || {};
  const handling = (p.handling_list || []);
  return `
    ${!p.code && isMulti() ? `<label class="f">Dipacking di gudang<select name="branch_id">${branchOptions(workBranch())}</select></label>` : ''}
    ${p.code ? '' : `<label class="f">Jenis packing<select name="type">${Object.entries(TYPES()).map(([k, v]) => `<option value="${k}">${TYPE_ICON[k] || ''} ${esc(v)}</option>`).join('')}</select></label>`}
    <label class="f">Nama / label isi (opsional)<input name="name" value="${esc(p.name)}" placeholder="mis. Peralatan jaringan"></label>
    <div class="grid2">
      <label class="f">Berat (kg)<input name="weight_kg" inputmode="decimal" value="${esc(p.weight_kg)}"></label>
      <label class="f">Tujuan (jika tanpa surat jalan)<input name="destination" value="${esc(p.destination)}"></label>
    </div>
    <label class="f">Dimensi P × L × T (cm)</label>
    <div class="grid3" style="margin-top:-6px;margin-bottom:10px">
      <input name="length_cm" inputmode="decimal" placeholder="P" value="${esc(p.length_cm)}">
      <input name="width_cm" inputmode="decimal" placeholder="L" value="${esc(p.width_cm)}">
      <input name="height_cm" inputmode="decimal" placeholder="T" value="${esc(p.height_cm)}">
    </div>
    <div class="grid2">
      <label class="f">Nama penerima<input name="receiver_name" value="${esc(p.receiver_name)}" placeholder="kosong = PIC cabang"></label>
      <label class="f">No. HP penerima<input name="receiver_phone" inputmode="tel" value="${esc(p.receiver_phone)}"></label>
    </div>
    <div class="f">Penanganan khusus<div class="checks">${Object.entries(BOOT.handling).map(([k, l]) => `<label><input type="checkbox" name="handling" value="${k}" ${handling.includes(k) ? 'checked' : ''}> ${esc(l)}</label>`).join('')}</div></div>
    <label class="f">Catatan<textarea name="notes" rows="2">${esc(p.notes)}</textarea></label>`;
}

function formData(f) {
  const d = Object.fromEntries(new FormData(f));
  d.handling = [...f.querySelectorAll('[name=handling]:checked')].map((c) => c.value);
  return d;
}

function newPackageDialog() {
  modal(`<h3>Buat Paket Baru</h3><form>${packageForm()}
    <p class="muted small">Kode paket dibuat otomatis (mis. DUS-0001). Berat & dimensi bisa diisi nanti sebelum disegel.</p>
    <div class="row end"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn">Buat</button></div></form>`,
  (m) => {
    const f = m.querySelector('form');
    f.type.value = localStorage.getItem('lastType') || 'DUS';
    f.onsubmit = async (e) => {
      e.preventDefault();
      try {
        localStorage.setItem('lastType', f.type.value);
        const r = await api('package_create', formData(f));
        m.close();
        toast('Paket ' + r.package.code + ' dibuat', 'ok');
        location.hash = '#/package/' + enc(r.package.code);
      } catch (err) { toast(err.message, 'err'); }
    };
  });
}

function packagePrintDocs(code) {
  return [
    { label: 'Label koli', mode: 'package', code, bt: async (paper) => BtPrint.packageLabel(await get('package_print', { code }), paper) },
    { label: 'Packing list', mode: 'list', code, bt: async (paper) => BtPrint.packingList(await get('package_print', { code }), paper) },
  ];
}

// ---------- Detail paket ----------
ROUTES.package = async function viewPackage(code) {
  let data;
  try { data = await get('package', { code }); } catch (e) {
    $view.innerHTML = `<div class="card empty">${esc(e.message)}<br><br><a class="btn" href="#/packages">Kembali</a></div>`;
    return;
  }
  let mode = 'pack';
  let verified = new Set(), extra = [], highlight = null, lastResult = null;
  const writable = () => can('gudang', 'cabang');

  const expectedCodes = () => [...data.children.map((c) => c.code), ...data.items.map((i) => i.code)];

  const contentList = (removable) => {
    const rows = [
      ...data.children.map((c) => `<li class="click" data-href="#/package/${enc(c.code)}"><span class="ico">${TYPE_ICON[c.type] || '📦'}</span>
        <div class="grow"><b class="mono">${esc(c.code)}</b><div class="muted small">${esc(TYPES()[c.type] || c.type)} · ${c.total_items} barang</div></div>
        ${stageBadge(c.stage)}${removable ? `<button class="btn danger sm" data-remove="${esc(c.code)}">Keluarkan</button>` : ''}</li>`),
      ...data.items.map((i) => `<li class="${highlight === i.code ? 'flash' : ''}"><span class="ico">${i.track === 'qty' ? '🔸' : '🔹'}</span>
        <div class="grow"><div>${esc(i.nama || i.jenis)}${i.track === 'qty' ? ` <b>× ${i.qty} ${esc(i.unit)}</b>` : ''}</div><div class="mono small muted">${esc(i.code)}${i.accessories ? ' · ' + esc(i.accessories) : ''}</div></div>
        ${removable ? `<button class="btn danger sm" data-remove="${esc(i.code)}">✕</button>` : ''}</li>`),
    ];
    return rows.length ? `<ul class="list">${rows.join('')}</ul>` : '<div class="empty">Paket masih kosong</div>';
  };

  const extrasHtml = () => {
    const canEdit = data.package.editable && writable();
    const ex = data.extras || [];
    if (!ex.length && !canEdit) return '';
    return `<div class="row between" style="margin-top:14px"><h3 style="margin:0">📝 Barang manual <span class="muted small" style="font-weight:normal">non-inventaris · tanpa barcode</span></h3>
        ${canEdit ? '<button class="btn secondary sm" data-extra-add>➕ Barang manual</button>' : ''}</div>
      ${ex.length ? `<ul class="list">${ex.map((x, n) => `<li class="${canEdit ? 'click' : ''}" data-extra="${n}"><span class="ico">📝</span>
        <div class="grow"><div>${esc(x.name)} <b>× ${x.qty} ${esc(x.unit)}</b></div>${x.note ? `<div class="small muted">${esc(x.note)}</div>` : ''}</div>
        ${canEdit ? `<button class="btn danger sm" data-extra-del="${n}">✕</button>` : ''}</li>`).join('')}</ul>`
        : '<p class="muted small">Barang yang tidak ada di database gudang (mis. dokumen, spanduk, barang titipan) bisa ditulis di sini. Ikut tercetak di packing list & surat jalan, dan dicentang saat penerimaan.</p>'}`;
  };

  const packBodyHtml = () => `<div class="row between"><h3 style="margin:0">Isi Paket</h3>${badge(data.package.total_items + ' barang' + ((data.extras || []).length ? ' + ' + data.extras.length + ' manual' : ''))}</div>
    ${contentList(data.package.editable && writable())}${extrasHtml()}`;

  const verifyStatsHtml = () => {
    const expected = expectedCodes();
    return `<div><b style="color:var(--ok)">${verified.size}</b>Cocok</div>
      <div><b style="color:var(--warn)">${expected.length - verified.size}</b>Belum discan</div>
      <div><b style="color:var(--err)">${extra.length}</b>Tidak sesuai</div>`;
  };

  const verifyBodyHtml = () => {
    const expected = expectedCodes();
    return `${extra.length ? `<h3 style="margin-top:0;color:var(--err)">Tidak seharusnya ada di paket ini</h3><ul class="list">${extra.map((x) => `<li><span class="ico">⚠️</span><div class="grow"><div class="mono">${esc(x.code)}</div><div class="muted small">${esc(x.info)}</div></div></li>`).join('')}</ul>` : ''}
      <h3 style="margin-top:0">Daftar Isi</h3>
      ${expected.length ? `<ul class="list">${expected.map((c) => {
        const it = data.items.find((i) => i.code === c);
        return `<li><span class="ico">${verified.has(c) ? '✅' : '⬜'}</span><div class="grow"><div>${esc(it ? (it.nama || it.jenis) : 'Sub-paket')}${it && it.track === 'qty' ? ' × ' + it.qty : ''}</div><div class="mono small muted">${esc(c)}</div></div></li>`;
      }).join('')}</ul>` : '<div class="empty">Paket kosong</div>'}`;
  };

  const refresh = () => {
    const body = document.getElementById('body');
    if (!body) return render();
    if (mode === 'pack') {
      body.innerHTML = packBodyHtml();
      bindBody();
    } else {
      document.getElementById('vstats').innerHTML = verifyStatsHtml();
      body.innerHTML = verifyBodyHtml();
    }
    if (activeScanner && lastResult) activeScanner.result(lastResult.msg, lastResult.type);
  };

  const extraDialog = (x) => {
    x = x || { qty: 1, unit: 'pcs' };
    modal(`<h3>${x.id ? 'Ubah' : 'Tambah'} Barang Manual</h3>
      <p class="muted small" style="margin-top:0">Barang sementara yang tidak tercatat di gudang: tanpa kode barang & barcode, tidak mengubah stok / lokasi.</p>
      <form>
        <label class="f">Nama barang *<input name="name" value="${esc(x.name)}" required placeholder="mis. Dokumen serah terima, spanduk event"></label>
        <div class="grid2"><label class="f">Jumlah *<input type="number" name="qty" min="1" value="${esc(x.qty)}" required></label>
          <label class="f">Satuan<select name="unit">${BOOT.units.map((u) => `<option ${u === x.unit ? 'selected' : ''}>${u}</option>`).join('')}</select></label></div>
        <label class="f">Keterangan<input name="note" value="${esc(x.note)}" placeholder="mis. milik tim event, kembalikan setelah acara"></label>
        <div class="row end"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn">Simpan</button></div>
      </form>`,
    (m) => {
      const f = m.querySelector('form');
      f.onsubmit = async (e) => {
        e.preventDefault();
        try {
          data = await api('package_extra_save', { package: data.package.code, id: x.id || 0, ...Object.fromEntries(new FormData(f)) });
          m.close(); toast('Barang manual tersimpan', 'ok'); refresh();
        } catch (err) { toast(err.message, 'err'); }
      };
    });
  };

  const bindBody = () => {
    const body = document.getElementById('body');
    const add = body.querySelector('[data-extra-add]');
    if (add) add.onclick = () => extraDialog();
    if (data.package.editable && writable()) body.querySelectorAll('[data-extra]').forEach((li) => (li.onclick = (e) => { if (!e.target.closest('button')) extraDialog(data.extras[+li.dataset.extra]); }));
    body.querySelectorAll('[data-extra-del]').forEach((b) => (b.onclick = async (e) => {
      e.stopPropagation();
      const x = data.extras[+b.dataset.extraDel];
      if (!(await confirmBox(`Hapus barang manual <b>${esc(x.name)}</b> dari paket?`, 'Hapus'))) return;
      try { data = await api('package_extra_delete', { package: data.package.code, id: x.id }); refresh(); } catch (err) { toast(err.message, 'err'); }
    }));
    bindList(body);
    body.querySelectorAll('[data-remove]').forEach((b) => (b.onclick = async (e) => {
      e.stopPropagation();
      if (!(await confirmBox(`Keluarkan <span class="mono">${esc(b.dataset.remove)}</span> dari paket?`, 'Keluarkan'))) return;
      try { data = await api('package_remove', { package: data.package.code, code: b.dataset.remove }); highlight = null; refresh(); } catch (err) { toast(err.message, 'err'); }
      if (activeScanner) activeScanner.focus();
    }));
  };

  const render = () => {
    stopScanner();
    const p = data.package;
    const sealed = p.status === 'sealed';
    const dims = p.length_cm ? `${fmtNum(p.length_cm, 0)}×${fmtNum(p.width_cm, 0)}×${fmtNum(p.height_cm, 0)} cm` : '';
    $view.innerHTML = `
      <div class="crumbs"><a href="#/packages">Paket</a>${p.path.length > 1 ? ' › ' + pathCrumbs(p.path.slice(0, -1)) : ''}</div>
      <div class="card">
        <div class="row between"><h2 style="margin:0">${TYPE_ICON[p.type] || '📦'} <span class="mono">${esc(p.code)}</span></h2>${stageBadge(p.stage)}</div>
        <div class="muted small" style="margin-top:4px">${esc(p.type_label)}${p.name ? ' · ' + esc(p.name) : ''} · ${esc(p.branch_name)} · dibuat ${fmtDate(p.created_at)}</div>
        <div class="kv">
          ${p.shipment_code ? `<div><span>Surat jalan</span><a href="#/shipment/${enc(p.shipment_code)}"><b>${esc(p.shipment_code)}</b></a>${p.koli ? ' · Koli ' + esc(p.koli) : ''} → ${esc(p.shipment_dest)}</div>` : ''}
          <div><span>Berat</span>${p.weight_kg ? fmtNum(p.weight_kg) + ' kg' : '<span class="muted">belum diisi</span>'}${dims ? ' · ' + dims + (p.vol_weight ? ` (volumetrik ${fmtNum(p.vol_weight, 2)} kg)` : '') : ''}</div>
          ${p.seal_no ? `<div><span>No. segel</span><b>${esc(p.seal_no)}</b></div>` : ''}
          ${p.receiver_name ? `<div><span>Penerima</span>${esc(p.receiver_name)} ${esc(p.receiver_phone)}</div>` : ''}
          ${p.destination ? `<div><span>Tujuan</span>${esc(p.destination)}</div>` : ''}
          ${p.handling_list.length ? `<div><span>Penanganan</span><b class="warn-text">${p.handling_list.map((h) => esc(BOOT.handling[h])).join(' · ')}</b></div>` : ''}
          ${p.notes ? `<div><span>Catatan</span>${esc(p.notes)}</div>` : ''}
        </div>
        <div class="row" style="margin-top:12px">
          ${writable() && p.stage === 'packing' ? '<button class="btn ok sm" id="seal">🔒 Segel Paket</button>' : ''}
          ${writable() && p.stage === 'sealed' && !p.shipment_code ? '<button class="btn secondary sm" id="unseal">🔓 Buka Segel</button>' : ''}
          <button class="btn secondary sm" id="print">🖨 Cetak</button>
          ${writable() && !['in_transit', 'received'].includes(p.stage) ? '<button class="btn secondary sm" id="edit">✏️ Edit</button>' : ''}
          ${writable() && p.editable && !p.shipment_code ? '<button class="btn danger sm" id="del">🗑 Hapus</button>' : ''}
        </div>
      </div>
      <div class="tabs">
        <button data-m="pack" class="${mode === 'pack' ? 'active' : ''}">📥 Isi Paket</button>
        <button data-m="verify" class="${mode === 'verify' ? 'active' : ''}">✅ Verifikasi Isi</button>
        <button data-m="photo" class="${mode === 'photo' ? 'active' : ''}">📷 Foto${p.photo_count ? ' (' + p.photo_count + ')' : ''}</button>
      </div>
      ${mode === 'pack' ? `
        <div class="card">
          ${!writable() ? '<div class="muted small">Anda hanya dapat melihat isi paket.</div>'
            : sealed || !p.editable ? `<div class="scan-result warn">Paket ${esc(p.stage_label.toLowerCase())}. ${p.stage === 'sealed' && !p.shipment_code ? 'Buka segel untuk menambah / mengeluarkan barang.' : 'Isi tidak dapat diubah.'}</div>`
            : '<div class="muted small" style="margin-bottom:8px">Scan kode barang (atau kode paket lain untuk dimasukkan sebagai sub-paket).</div><div id="sc"></div>'}
        </div>
        <div class="card" id="body">${packBodyHtml()}</div>`
      : mode === 'verify' ? `
        <div class="card">
          <div class="muted small" style="margin-bottom:8px">Scan semua barang di dalam paket fisik untuk mencocokkan dengan data packing list.</div>
          <div class="verify" id="vstats">${verifyStatsHtml()}</div>
          <div id="sc"></div>
          <button class="btn secondary sm" id="resetVerify" style="margin-top:8px">Ulangi verifikasi</button>
        </div>
        <div class="card" id="body">${verifyBodyHtml()}</div>`
      : `<div class="card"><p class="muted small" style="margin-top:0">Foto isi paket sebelum disegel dan kondisi kemasan — bukti bila ada klaim kerusakan / kehilangan.</p><div id="gallery"></div></div>`}`;

    bindList($view);
    if (mode === 'pack') bindBody();
    if (mode === 'photo') photoGallery(document.getElementById('gallery'), 'package', p.code, { caption: 'Isi paket ' + p.code, canAdd: writable() });
    $view.querySelectorAll('[data-m]').forEach((b) => (b.onclick = () => { mode = b.dataset.m; lastResult = null; render(); }));
    const on = (id, fn) => { const el = document.getElementById(id); if (el) el.onclick = fn; };
    on('seal', sealDialog);
    on('unseal', async () => {
      if (!(await confirmBox(`Buka segel <b>${esc(p.code)}</b>? Nomor segel lama akan dihapus — pasang segel baru saat menyegel ulang.`, 'Buka Segel'))) return;
      try { data = await api('package_seal', { code: p.code, sealed: false }); toast('Segel dibuka', 'ok'); render(); } catch (e) { toast(e.message, 'err'); }
    });
    on('print', () => printDialog(p.code, packagePrintDocs(p.code)));
    on('edit', editDialog);
    on('del', async () => {
      if (!(await confirmBox(`Hapus paket <b>${esc(p.code)}</b>? Barang di dalamnya kembali berstatus belum dipacking.`, 'Hapus', true))) return;
      try { await api('package_delete', { code: p.code }); toast('Paket dihapus', 'ok'); location.hash = '#/packages'; } catch (e) { toast(e.message, 'err'); }
    });
    on('resetVerify', () => { verified = new Set(); extra = []; lastResult = null; refresh(); activeScanner && activeScanner.result('', ''); });

    const sc = document.getElementById('sc');
    if (sc) {
      const scanner = createScanner(sc, mode === 'pack' ? (c) => packScan(c, {}) : verifyScan);
      if (lastResult) scanner.result(lastResult.msg, lastResult.type);
    }
  };

  const setResult = (msg, type) => { lastResult = { msg, type }; beep(type === 'ok'); };

  async function packScan(scanned, opts) {
    try {
      const r = await api('package_add', { package: data.package.code, code: scanned, move: !!opts.move, force_location: !!opts.force, found: opts.found ? 1 : '', qty: opts.qty ?? '' });
      if (r.queued) {
        setResult(`⏳ ${esc(scanned)} disimpan offline (${r.count} antre) — dikirim otomatis saat online`, 'warn');
        return refresh();
      }
      data = r;
      highlight = r.kind === 'item' ? r.item.code : null;
      const name = r.kind === 'item' ? (r.item.nama || r.item.jenis) + (opts.qty ? ` × ${opts.qty}` : '') : 'Sub-paket ' + r.child.code;
      setResult(`✔ ${esc(name)}${r.moved_from ? ' (dipindah dari ' + esc(r.moved_from) + ')' : ''}`, 'ok');
    } catch (e) {
      const d = e.data || {};
      if (d.need_qty) {
        const q = await promptQty('Jumlah ' + (d.item.nama || d.item.code), `Stok tersedia: <b>${d.available} ${esc(d.item.unit)}</b>`, 1, d.available);
        if (activeScanner) activeScanner.focus();
        if (q) return packScan(scanned, { ...opts, qty: q });
        return;
      }
      if (d.missing_case) {
        setResult('⚠ ' + esc(e.message), 'warn');
        refresh();
        const ok = await confirmBox(`${esc(e.message)}<br><br>Barang ini <b>ditemukan di sini</b>? Selisih ${d.shipment ? 'di <b>' + esc(d.shipment) + '</b> ' : ''}akan ditutup otomatis (ditemukan / tertinggal di gudang asal) dan barang dimasukkan ke paket.`, 'Ya, Ditemukan');
        if (activeScanner) activeScanner.focus();
        if (ok) return packScan(scanned, { ...opts, found: true });
        return;
      }
      if (d.location_conflict) {
        setResult('⚠ ' + esc(e.message), 'warn');
        refresh();
        const ok = await confirmBox(`${esc(e.message)}<br>Barang benar-benar ada di sini? Lokasinya akan dikoreksi saat diterima.`, 'Tetap Masukkan');
        if (activeScanner) activeScanner.focus();
        if (ok) return packScan(scanned, { ...opts, force: true });
        return;
      }
      if (d.conflict) {
        setResult('⚠ ' + esc(e.message), 'warn');
        refresh();
        const ok = await confirmBox(`${esc(e.message)}<br>Pindahkan ke <b>${esc(data.package.code)}</b>?`, 'Pindahkan');
        if (activeScanner) activeScanner.focus();
        if (ok) return packScan(scanned, { ...opts, move: true });
        return;
      }
      setResult((d.duplicate ? '⚠ ' : '✖ ') + esc(e.message), d.duplicate ? 'warn' : 'err');
    }
    refresh();
  }

  async function verifyScan(scanned) {
    const expected = expectedCodes();
    const match = expected.find((c) => c.toLowerCase() === scanned.toLowerCase());
    if (match) {
      if (verified.has(match)) setResult('⚠ Sudah discan: ' + esc(match), 'warn');
      else { verified.add(match); setResult('✔ Cocok: ' + esc(match), 'ok'); }
    } else if (!extra.some((x) => x.code.toLowerCase() === scanned.toLowerCase())) {
      let info = 'Tidak terdaftar di database';
      try {
        const r = await get('lookup', { code: scanned });
        info = r.kind === 'item' ? (r.item.nama || r.item.jenis) + (r.location ? ' — tercatat di ' + r.location.code : ' — belum dipacking') : 'Kode ' + (r.kind === 'package' ? 'paket ' : 'surat jalan ') + scanned;
      } catch (e) { /* tetap tidak terdaftar */ }
      extra.push({ code: scanned, info });
      setResult('✖ Tidak sesuai: ' + esc(scanned), 'err');
    } else {
      setResult('✖ Tidak sesuai: ' + esc(scanned), 'err');
    }
    refresh();
    if (expected.length && verified.size === expected.length && !extra.length) toast('Verifikasi lengkap — semua isi cocok ✔', 'ok');
  }

  function sealDialog() {
    const p = data.package;
    const missing = [];
    if (!p.total_items) missing.push('Paket masih <b>kosong</b>.');
    if (!p.weight_kg) missing.push('Berat belum diisi.');
    if (!p.photo_count) missing.push('Belum ada <b>foto isi paket</b> sebagai bukti.');
    modal(`<h3>🔒 Segel ${esc(p.code)}</h3>
      ${missing.length ? `<div class="scan-result warn" style="margin-bottom:10px">${missing.join('<br>')}</div>` : ''}
      <form>
        <label class="f">Nomor segel fisik (opsional)<input name="seal_no" placeholder="mis. nomor pada segel plastik / stiker" autocapitalize="characters"></label>
        <label class="f">Berat (kg)<input name="weight_kg" inputmode="decimal" value="${esc(p.weight_kg)}"></label>
        <button type="button" class="btn secondary block" id="photoBtn">📷 Foto isi sebelum ditutup${p.photo_count ? ` (${p.photo_count} foto)` : ''}</button>
        <div class="row end" style="margin-top:12px"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn ok">Segel</button></div>
      </form>`,
    (m) => {
      const f = m.querySelector('form');
      m.querySelector('#photoBtn').onclick = async () => {
        if (await takePhoto('package', p.code, 'Isi sebelum disegel')) {
          p.photo_count++;
          m.querySelector('#photoBtn').textContent = `📷 Foto isi sebelum ditutup (${p.photo_count} foto) ✔`;
        }
      };
      f.onsubmit = async (e) => {
        e.preventDefault();
        try {
          if (f.weight_kg.value && f.weight_kg.value !== String(p.weight_kg ?? '')) {
            await api('package_update', { code: p.code, name: p.name, destination: p.destination, notes: p.notes, weight_kg: f.weight_kg.value, length_cm: p.length_cm, width_cm: p.width_cm, height_cm: p.height_cm, receiver_name: p.receiver_name, receiver_phone: p.receiver_phone, handling: p.handling_list });
          }
          data = await api('package_seal', { code: p.code, sealed: true, seal_no: f.seal_no.value });
          m.close();
          lastResult = null;
          toast('Paket disegel', 'ok');
          render();
          if (await confirmBox('Cetak label koli / packing list sekarang?', 'Cetak')) printDialog(p.code, packagePrintDocs(p.code));
        } catch (err) { toast(err.message, 'err'); }
      };
    });
  }

  function editDialog() {
    modal(`<h3>Edit ${esc(data.package.code)}</h3><form>${packageForm(data.package)}
      <div class="row end"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn">Simpan</button></div></form>`,
    (m) => {
      const f = m.querySelector('form');
      f.onsubmit = async (e) => {
        e.preventDefault();
        try { data = await api('package_update', { code: data.package.code, ...formData(f) }); m.close(); render(); } catch (err) { toast(err.message, 'err'); }
      };
    });
  }

  render();
};

// ---------- Cek Paket: scan kode paket / barang / surat jalan ----------
ROUTES.cek = async function viewCheck(initial) {
  $view.innerHTML = `<h2>Cek Kode</h2>
    <p class="muted small">Scan QR paket untuk melihat seluruh isinya (termasuk sub-paket). Scan kode barang untuk melihat lokasi & riwayatnya. Scan surat jalan untuk membuka pengirimannya.</p>
    <div class="card" id="sc"></div><div id="out"></div>`;
  const out = document.getElementById('out');

  const show = async (code, scanner) => {
    try {
      const r = await get('package_tree', { code });
      if (r.kind === 'item') { location.hash = '#/item/' + enc(r.code); return; }
      if (r.kind === 'shipment') { location.hash = '#/shipment/' + enc(r.code); return; }
      if (scanner) { beep(true); scanner.result('✔ ' + esc(r.tree.code) + (r.scanned_item ? ' (berisi ' + esc(r.scanned_item) + ')' : ''), 'ok'); }
      history.replaceState(null, '', '#/cek/' + enc(r.tree.code));
      out.innerHTML = checkResult(r);
      bindList(out);
      const hl = out.querySelector('.flash');
      if (hl) hl.scrollIntoView({ block: 'center', behavior: 'smooth' });
    } catch (e) {
      if (scanner) { beep(false); scanner.result('✖ ' + esc(e.message), 'err'); }
      out.innerHTML = '';
    }
  };

  const scanner = createScanner(document.getElementById('sc'), (code) => show(code, scanner), { placeholder: 'Scan / ketik kode lalu Enter' });
  if (initial) show(initial, null);
};

function checkResult(r) {
  const t = r.tree;
  const summary = {};
  const collect = (node) => { node.items.forEach((i) => { const k = (i.jenis || i.nama) + (i.model ? ' ' + i.model : ''); summary[k] = (summary[k] || 0) + (+i.qty || 1); }); node.children.forEach(collect); };
  collect(t);

  const itemRow = (i) => `<li class="click ${i.code === r.scanned_item ? 'flash' : ''}" data-href="#/item/${enc(i.code)}"><span class="ico">${i.code === r.scanned_item ? '👉' : '🔹'}</span>
    <div class="grow"><div>${esc(i.nama || i.jenis)}${i.track === 'qty' ? ` <b>× ${i.qty} ${esc(i.unit)}</b>` : ''}</div><div class="mono small muted">${esc(i.code)}${i.serial ? ' · SN ' + esc(i.serial) : ''}</div></div></li>`;
  const node = (n) => `
    ${n.items.length ? `<ul class="list">${n.items.map(itemRow).join('')}</ul>` : ''}
    ${(n.extras || []).length ? `<ul class="list">${n.extras.map((x) => `<li><span class="ico">📝</span><div class="grow"><div>${esc(x.name)} <b>× ${x.qty} ${esc(x.unit)}</b></div><div class="small muted">Barang manual (non-inventaris)${x.note ? ' · ' + esc(x.note) : ''}</div></div></li>`).join('')}</ul>` : ''}
    ${n.children.map((c) => `
      <details class="sub" open>
        <summary><span>${TYPE_ICON[c.type] || '📦'} <b class="mono">${esc(c.code)}</b> <span class="muted small">${esc(c.type_label)}${c.name ? ' · ' + esc(c.name) : ''}</span></span>
          <span class="row" style="gap:4px">${badge(c.total_items + ' barang')}${stageBadge(c.stage)}</span></summary>
        <div class="sub-body">${node(c)}</div>
      </details>`).join('')}
    ${!n.items.length && !n.children.length && !(n.extras || []).length ? '<div class="empty">Kosong</div>' : ''}`;

  return `
    <div class="card">
      ${r.path.length > 1 ? `<div class="crumbs">Berada di dalam: ${pathCrumbs(r.path.slice(0, -1))}</div>` : ''}
      <div class="row between" style="margin-top:4px">
        <h2 style="margin:0">${TYPE_ICON[t.type] || '📦'} <span class="mono">${esc(t.code)}</span></h2>${stageBadge(t.stage)}
      </div>
      <div class="muted small" style="margin-top:4px">${esc(t.type_label)}${t.name ? ' · ' + esc(t.name) : ''}${t.destination ? ' · Tujuan: <b>' + esc(t.destination) + '</b>' : ''}</div>
      ${t.shipment_code ? `<div class="small" style="margin-top:6px">🚚 <a href="#/shipment/${enc(t.shipment_code)}"><b>${esc(t.shipment_code)}</b></a>${t.koli ? ' · Koli ' + esc(t.koli) : ''} → ${esc(t.shipment_dest)}</div>` : ''}
      ${t.seal_no ? `<div class="small">🔒 Segel: <b>${esc(t.seal_no)}</b></div>` : ''}
      ${t.handling_list.length ? `<div class="small warn-text"><b>${t.handling_list.map((h) => esc(BOOT.handling[h])).join(' · ')}</b></div>` : ''}
      ${t.notes ? `<div class="small" style="margin-top:6px">📝 ${esc(t.notes)}</div>` : ''}
      <div class="verify" style="grid-template-columns:repeat(3,1fr);margin:12px 0 0">
        <div><b style="color:var(--primary)">${t.total_items}</b>Barang</div>
        <div><b style="color:var(--primary)">${t.children.length}</b>Sub-paket</div>
        <div><b style="color:var(--primary)">${t.weight_kg ? fmtNum(t.weight_kg) : '-'}</b>kg</div>
      </div>
    </div>
    ${t.total_items ? `<div class="card"><h3 style="margin-top:0">Ringkasan</h3>
      <table class="t">${Object.entries(summary).map(([k, n]) => `<tr><td>${esc(k)}</td><td style="text-align:right;width:50px"><b>${n}</b></td></tr>`).join('')}</table></div>` : ''}
    <div class="card"><h3 style="margin-top:0">Isi Paket</h3>${node(t)}</div>
    <a class="btn secondary block" href="#/package/${enc(t.code)}">Buka detail / cetak paket ${esc(t.code)}</a>`;
}
