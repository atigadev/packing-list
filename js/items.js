/* Database barang (format standar pergudangan): daftar, form, input banyak unit, impor template, riwayat, kategori. */
'use strict';

let itemFilter = { q: '', status: 'all', location: '', category: '' };
const EVENT_ICON = { followup: '🧾', found: '🔎', created: '🆕', packed: '📥', unpacked: '📤', shipped: '🚚', received: '✅', missing: '❌', location: '📍', condition: '🔧', stock: '🔢' };
const fmtRp = (v) => v === null || v === undefined || v === '' ? '-' : 'Rp ' + (+v).toLocaleString('id-ID', { maximumFractionDigits: 0 });
const fmtDay = (s) => s ? new Date(s + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';
const categoryOptions = (selected) => BOOT.categories.filter((c) => +c.active || +c.id === +selected)
  .map((c) => `<option value="${c.id}" ${+c.id === +selected ? 'selected' : ''}>${esc(c.code)} — ${esc(c.name)}</option>`).join('');

function itemStatusBadge(i) {
  if (i.status === 'dikirim') return badge('Dikirim', 'info');
  if (i.status === 'hilang') return badge('Hilang', 'err');
  if (i.status === 'dihapus') return badge('Dihapusbukukan', 'none');
  if (i.package_code) return `<a class="badge sealed" href="#/package/${enc(i.package_code)}">${esc(i.package_code)}</a>`;
  return badge('Tersedia', 'none');
}

const isLow = (i) => i.track === 'qty' && +i.min_stock > 0 && +i.stock <= +i.min_stock;

ROUTES.items = async function viewItems() {
  const hq = hashQuery();
  if (hq) itemFilter.status = hq;
  const tabs = [['all', 'Semua'], ['unpacked', 'Belum dipacking'], ['packed', 'Dalam paket'], ['dikirim', 'Dikirim'], ['lowstock', 'Stok minimum'], ['rusak', 'Rusak'], ['hilang', 'Hilang']];
  $view.innerHTML = `
    <div class="row between"><h2>Database Barang</h2>
      <div class="row">${can('gudang') ? '<button class="btn secondary sm" id="import">⬆️ Import Excel</button><button class="btn sm" id="add">➕ Tambah Barang</button>' : ''}</div></div>
    <div class="card">
      <input id="q" placeholder="Cari kode, nama, merk, SN, no. aset, rak, supplier, no. PO…" value="${esc(itemFilter.q)}">
      <div class="grid2" style="margin-top:8px">
        <select id="cat"><option value="">Semua kategori</option>${categoryOptions(itemFilter.category)}</select>
        <select id="loc"><option value="">Semua gudang</option>${branchOptions(itemFilter.location)}</select>
      </div>
      <div class="row between" style="margin-top:10px">
        <div class="tabs" style="margin:0">${tabs.map(([k, l]) => `<button data-s="${k}" class="${itemFilter.status === k ? 'active' : ''}">${l}</button>`).join('')}</div>
        <div class="row"><button class="btn secondary sm" id="labels">🏷️ Label QR</button><a class="btn secondary sm" href="export.php?type=items">⬇️ Excel</a></div>
      </div>
    </div>
    <div class="card"><div id="bulkBar" class="bulk-bar" hidden></div><div id="list">Memuat…</div></div>`;

  let rows = [];
  const selected = new Set(); // id barang yang dicentang (tetap tersimpan walau filter berubah)
  const selectable = can('gudang');
  const renderBulkBar = () => {
    const bar = document.getElementById('bulkBar');
    if (!bar) return;
    bar.hidden = !selected.size;
    bar.innerHTML = `<span><b>${selected.size}</b> barang dipilih</span>
      <span class="row" style="flex-wrap:nowrap">
        <button class="btn sm" id="bulkMove">🚚 Pindah gudang</button>
        <button class="btn secondary sm" id="bulkLabel">🏷️ Label</button>
        <button class="btn secondary sm" id="bulkClear">Batal</button></span>`;
    document.getElementById('bulkMove').onclick = () => bulkMoveDialog([...selected], rows, () => { selected.clear(); renderBulkBar(); load(); });
    document.getElementById('bulkLabel').onclick = () => {
      const byId = new Map(rows.map((r) => [+r.id, r.code]));
      const codes = [...selected].map((id) => byId.get(id)).filter(Boolean);
      if (codes.length < selected.size) toast('Sebagian barang terpilih tidak tampil di filter ini; label hanya untuk yang tampil', 'warn');
      if (codes.length) openPrint('mode=items&codes=' + enc(codes.join(',')));
    };
    document.getElementById('bulkClear').onclick = () => { selected.clear(); renderBulkBar(); load(); };
  };
  const load = async () => {
    const r = await get('items', itemFilter);
    rows = r.items;
    const el = document.getElementById('list');
    if (!el) return;
    el.innerHTML = rows.length ? `<div class="muted small" style="margin-bottom:6px">${rows.length} barang</div>
      <table class="t"><thead><tr>${selectable ? `<th class="chk"><input type="checkbox" id="chkAll" title="Pilih semua yang tampil" ${rows.every((r) => selected.has(+r.id)) ? 'checked' : ''}></th>` : ''}<th>Kode / Nama</th><th class="hide-sm">Merk / Model</th><th class="hide-sm">SN / No. Aset</th><th class="hide-sm">Lokasi</th><th>Status</th><th></th></tr></thead><tbody>
      ${rows.map((i, n) => `<tr class="click ${selected.has(+i.id) ? 'sel' : ''}" data-href="#/item/${enc(i.code)}">
        ${selectable ? `<td class="chk"><input type="checkbox" data-chk="${i.id}" ${selected.has(+i.id) ? 'checked' : ''}></td>` : ''}
        <td><div>${esc(i.nama)}${i.track === 'qty' ? ` <span class="badge ${isLow(i) ? 'err' : ''}">${i.stock} ${esc(i.unit)}</span>` : ''}${i.cond === 'rusak' ? ' ' + badge('Rusak', 'err') : ''}</div>
          <div class="mono small muted">${esc(i.code)} · ${esc(i.category_name || i.jenis || '')}</div></td>
        <td class="hide-sm">${esc(i.merk)}<div class="small muted">${esc(i.model)}</div></td>
        <td class="hide-sm small">${esc(i.serial || '-')}<div class="muted">${esc(i.asset_no || '')}</div></td>
        <td class="hide-sm small">${esc(i.location_name || '-')}${i.bin_location ? `<div class="muted">Rak ${esc(i.bin_location)}</div>` : ''}</td>
        <td>${itemStatusBadge(i)}</td>
        <td>${can('gudang') ? `<button class="btn secondary sm" data-edit="${n}">✏️</button>` : ''}</td></tr>`).join('')}
      </tbody></table>` : `<div class="empty">Belum ada barang${itemFilter.q || itemFilter.category || itemFilter.location ? ' yang cocok' : ''}.${can('gudang') && !itemFilter.q ? '<br><br>Tekan <b>➕ Tambah Barang</b> atau <b>⬆️ Import Excel</b>.' : ''}</div>`;
    bindList(el);
    el.querySelectorAll('[data-edit]').forEach((b) => (b.onclick = (e) => { e.stopPropagation(); itemDialog(rows[b.dataset.edit], load); }));
    // Centang: jangan membuka detail barang saat checkbox / selnya diketuk
    el.querySelectorAll('td.chk, th.chk').forEach((td) => td.addEventListener('click', (e) => {
      e.stopPropagation();
      const cb = td.querySelector('input');
      if (e.target !== cb) cb.checked = !cb.checked;
      if (cb.id === 'chkAll') {
        rows.forEach((r) => (cb.checked ? selected.add(+r.id) : selected.delete(+r.id)));
        el.querySelectorAll('[data-chk]').forEach((c) => { c.checked = cb.checked; c.closest('tr').classList.toggle('sel', cb.checked); });
      } else {
        cb.checked ? selected.add(+cb.dataset.chk) : selected.delete(+cb.dataset.chk);
        cb.closest('tr').classList.toggle('sel', cb.checked);
        const all = el.querySelector('#chkAll');
        if (all) all.checked = rows.every((r) => selected.has(+r.id));
      }
      renderBulkBar();
    }, true));
    renderBulkBar();
  };

  let t;
  document.getElementById('q').oninput = (e) => { itemFilter.q = e.target.value; clearTimeout(t); t = setTimeout(load, 250); };
  document.getElementById('loc').onchange = (e) => { itemFilter.location = e.target.value; load(); };
  document.getElementById('cat').onchange = (e) => { itemFilter.category = e.target.value; load(); };
  $view.querySelectorAll('[data-s]').forEach((b) => (b.onclick = () => {
    itemFilter.status = b.dataset.s;
    $view.querySelectorAll('[data-s]').forEach((x) => x.classList.toggle('active', x === b));
    load();
  }));
  const on = (id, fn) => { const el = document.getElementById(id); if (el) el.onclick = fn; };
  on('add', () => itemDialog(null, load));
  on('import', () => importDialog(load));
  on('labels', () => {
    if (!rows.length) return toast('Tidak ada barang untuk dicetak', 'warn');
    if (rows.length > 300) return openPrint('mode=items' + (itemFilter.status === 'unpacked' ? '&filter=unpacked' : ''));
    openPrint('mode=items&codes=' + enc(rows.map((r) => r.code).join(',')));
  });
  load();
};

/** Pindah gudang / rak untuk banyak barang sekaligus. */
function bulkMoveDialog(ids, rows, done) {
  const shown = rows.filter((r) => ids.includes(+r.id));
  const blocked = shown.filter((r) => r.status === 'dikirim' || r.package_code);
  const fromSummary = {};
  shown.forEach((r) => { const k = r.location_name || '-'; fromSummary[k] = (fromSummary[k] || 0) + 1; });
  modal(`<h3>🚚 Pindah Gudang Massal</h3>
    <p class="small" style="margin-top:0"><b>${ids.length}</b> barang dipilih${Object.keys(fromSummary).length ? ' — saat ini di ' + Object.entries(fromSummary).map(([k, n]) => `${esc(k)} (${n})`).join(', ') : ''}.</p>
    ${blocked.length ? `<div class="scan-result warn" style="margin-bottom:10px">${blocked.length} barang masih di dalam paket / sedang dikirim dan akan <b>dilewati</b>.</div>` : ''}
    <form>
      <label class="f">Gudang tujuan *<select name="branch_id" required><option value="">— pilih gudang —</option>${branchOptions('')}</select></label>
      <label class="f">Rak / bin di gudang tujuan<input name="bin_location" autocapitalize="characters" placeholder="kosongkan bila belum ditentukan"></label>
      <label class="f">Keterangan<input name="note" placeholder="mis. pemindahan stok event GIIAS, koreksi data"></label>
      <p class="muted small">Untuk perpindahan fisik antar kota yang perlu dibawa & diterima, gunakan <b>Surat Jalan</b> agar ada bukti muat & serah terima. Fitur ini mencatat perpindahan langsung (koreksi lokasi / pindah internal).</p>
      <div class="row end"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn">Pindahkan</button></div>
    </form>`,
  (m) => {
    const f = m.querySelector('form');
    f.onsubmit = async (e) => {
      e.preventDefault();
      const btn = f.querySelector('button:not([type])');
      btn.disabled = true;
      try {
        const r = await api('item_bulk_move', { ids, branch_id: f.branch_id.value, bin_location: f.bin_location.value, note: f.note.value });
        if (r.skipped.length) {
          modal(`<h3>Pindah Gudang Selesai</h3>
            <div class="scan-result ok">✔ ${r.moved} barang dipindahkan.</div>
            <p class="small"><b>${r.skipped.length} dilewati:</b></p>
            <ul class="small" style="padding-left:18px;max-height:40vh;overflow:auto">${r.skipped.map((s) => `<li>${esc(s)}</li>`).join('')}</ul>
            <button class="btn block" data-close>Tutup</button>`);
        } else {
          m.close();
          toast(`${r.moved} barang dipindahkan`, 'ok');
        }
        done();
      } catch (err) { toast(err.message, 'err'); btn.disabled = false; }
    };
  });
}

/** Form barang standar. item = null untuk barang baru. */
function itemDialog(item, reload) {
  const isNew = !item;
  const i = item || { track: 'serial', unit: 'unit', stock: 0, min_stock: 0, cond: 'baru', location_branch_id: workBranch(), category_id: localStorage.getItem('lastCategory') || '' };
  const unitOpts = (sel) => BOOT.units.map((u) => `<option ${u === sel ? 'selected' : ''}>${u}</option>`).join('');
  modal(`<h3>${isNew ? 'Tambah Barang' : 'Edit ' + esc(i.code)}</h3>
    <form class="item-form">
      ${isNew ? `<label class="f">Kode barang (opsional)<input name="code" placeholder="kosongkan = otomatis dari kategori, mis. NET-0001"></label>
        <p class="muted small" style="margin-top:-6px">Isi bila barang sudah punya kode / label sendiri. Untuk input beberapa unit sekaligus, kode selalu otomatis.</p>` : ''}
      <fieldset><legend>Identitas barang</legend>
        <label class="f">Kategori *<select name="category_id" required><option value="">— pilih kategori —</option>${categoryOptions(i.category_id)}</select></label>
        <label class="f">Nama barang *<input name="nama" value="${esc(i.nama)}" required placeholder="mis. Switch 8 Port Gigabit"></label>
        <div class="grid2">
          <label class="f">Merk<input name="merk" value="${esc(i.merk)}"></label>
          <label class="f">Model / tipe<input name="model" value="${esc(i.model)}"></label>
        </div>
        <label class="f">Spesifikasi<input name="spec" value="${esc(i.spec)}" placeholder="mis. 8 port 10/100/1000, unmanaged"></label>
      </fieldset>

      <fieldset><legend>Pelacakan & stok</legend>
        <div class="seg">
          <label><input type="radio" name="track" value="serial" ${i.track !== 'qty' ? 'checked' : ''} ${isNew ? '' : 'disabled'}> Per unit (aset / ber-serial)</label>
          <label><input type="radio" name="track" value="qty" ${i.track === 'qty' ? 'checked' : ''} ${isNew ? '' : 'disabled'}> Berdasarkan jumlah (habis pakai)</label>
        </div>
        <div class="trk-serial">
          ${isNew ? `<label class="f">Jumlah unit<input type="number" name="unit_count" min="1" max="200" value="1"></label>
            <p class="muted small" style="margin-top:-6px">Lebih dari 1 = beberapa unit identik dengan SN / no. aset masing-masing (tiap unit mendapat kode sendiri).</p>` : ''}
          <div id="unitRows"></div>
        </div>
        <div class="trk-qty grid3">
          <label class="f">Stok<input type="number" name="stock" min="0" value="${esc(i.stock)}"></label>
          <label class="f">Satuan *<select name="unit">${unitOpts(i.unit)}</select></label>
          <label class="f">Stok minimum<input type="number" name="min_stock" min="0" value="${esc(i.min_stock)}"></label>
        </div>
      </fieldset>

      <fieldset><legend>Lokasi & kondisi</legend>
        <div class="grid2">
          <label class="f">Gudang *<select name="location_branch_id">${branchOptions(i.location_branch_id)}</select></label>
          <label class="f">Rak / bin<input name="bin_location" value="${esc(i.bin_location)}" placeholder="mis. A-01-02" autocapitalize="characters"></label>
        </div>
        <div class="grid2">
          <label class="f">Kondisi *<select name="cond">${Object.entries(BOOT.item_conditions).map(([k, l]) => `<option value="${k}" ${i.cond === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
          <label class="f">Kelengkapan<input name="accessories" value="${esc(i.accessories)}" placeholder="mis. adaptor, kabel, remote"></label>
        </div>
      </fieldset>

      <details ${i.acquired_date || i.supplier || i.acquired_value || i.po_number || i.warranty_until ? 'open' : ''}><summary class="small"><b>Perolehan & garansi</b> (opsional)</summary>
        <div class="grid2" style="margin-top:8px">
          <label class="f">Tanggal perolehan<input type="date" name="acquired_date" value="${esc(i.acquired_date)}"></label>
          <label class="f">Garansi s/d<input type="date" name="warranty_until" value="${esc(i.warranty_until)}"></label>
        </div>
        <label class="f">Supplier / vendor<input name="supplier" value="${esc(i.supplier)}"></label>
        <div class="grid2">
          <label class="f">Nilai perolehan (Rp)<input name="acquired_value" inputmode="numeric" value="${esc(i.acquired_value ?? '')}"></label>
          <label class="f">No. PO / dokumen<input name="po_number" value="${esc(i.po_number)}"></label>
        </div>
      </details>
      <label class="f" style="margin-top:10px">Keterangan<textarea name="notes" rows="2">${esc(i.notes)}</textarea></label>

      ${!isNew && can() && ['hilang', 'dihapus'].includes(i.status) ? `<label class="small" style="display:block;margin-bottom:10px"><input type="checkbox" name="restore" value="1"> Barang ditemukan kembali — pulihkan status menjadi <b>Tersedia</b> di lokasi di atas</label>` : ''}
      <div class="row between">
        ${!isNew && can() ? '<button type="button" class="btn danger" data-del>Hapus</button>' : '<span></span>'}
        <div class="row"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn">Simpan</button></div>
      </div>
    </form>`,
  (m) => {
    const f = m.querySelector('form');
    const rowsEl = m.querySelector('#unitRows');
    const renderUnits = () => {
      const n = isNew ? Math.max(1, Math.min(200, +f.unit_count.value || 1)) : 1;
      const prev = [...rowsEl.querySelectorAll('.unit-row')].map((r) => [r.querySelector('[data-sn]').value, r.querySelector('[data-asset]').value]);
      rowsEl.innerHTML = Array.from({ length: n }, (_, k) => `<div class="unit-row grid2">
        <label class="f">${n > 1 ? 'Unit ' + (k + 1) + ' — ' : ''}Serial number<input data-sn value="${esc(prev[k] ? prev[k][0] : (k === 0 ? i.serial || '' : ''))}"></label>
        <label class="f">No. aset<input data-asset autocapitalize="characters" value="${esc(prev[k] ? prev[k][1] : (k === 0 ? i.asset_no || '' : ''))}"></label></div>`).join('');
    };
    const sync = () => {
      const qty = (f.querySelector('[name=track]:checked') || {}).value === 'qty';
      m.querySelector('.trk-serial').hidden = qty;
      m.querySelector('.trk-qty').hidden = !qty;
      if (!qty && isNew && f.unit.value !== 'unit') f.unit.value = 'unit';
    };
    renderUnits();
    if (isNew) f.unit_count.oninput = renderUnits;
    f.querySelectorAll('[name=track]').forEach((r) => (r.onchange = sync));
    sync();

    f.onsubmit = async (e) => {
      e.preventDefault();
      const d = Object.fromEntries(new FormData(f));
      d.id = i.id || 0;
      d.track = (f.querySelector('[name=track]:checked') || {}).value || i.track;
      const units = [...rowsEl.querySelectorAll('.unit-row')].map((r) => ({ serial: r.querySelector('[data-sn]').value.trim(), asset_no: r.querySelector('[data-asset]').value.trim() }));
      if (d.track === 'serial') {
        d.serial = units[0] ? units[0].serial : '';
        d.asset_no = units[0] ? units[0].asset_no : '';
        if (isNew && units.length > 1) d.units = units;
      }
      const btn = f.querySelector('button:not([type])');
      btn.disabled = true;
      try {
        const r = await api('item_save', d);
        localStorage.setItem('lastCategory', d.category_id);
        m.close();
        toast(isNew ? (r.codes.length > 1 ? `${r.codes.length} barang tersimpan: ${r.codes[0]} s/d ${r.codes[r.codes.length - 1]}` : 'Barang tersimpan: ' + r.codes[0]) : 'Perubahan tersimpan', 'ok');
        reload();
        if (isNew && (await confirmBox(`Cetak label QR untuk ${r.codes.length} barang baru?`, 'Cetak Label'))) openPrint('mode=items&codes=' + enc(r.codes.join(',')));
      } catch (err) { toast(err.message, 'err'); btn.disabled = false; }
    };
    const del = m.querySelector('[data-del]');
    if (del) del.onclick = async () => {
      if (!(await confirmBox(`Hapus barang <span class="mono">${esc(i.code)}</span> beserta riwayatnya?`, 'Hapus', true))) return;
      try { await api('item_delete', { id: i.id }); toast('Barang dihapus', 'ok'); reload(); } catch (err) { toast(err.message, 'err'); }
    };
  });
}

function importDialog(reload) {
  modal(`<h3>Import Barang dari Excel</h3>
    <ol class="small" style="padding-left:18px;margin-top:0">
      <li>Unduh template standar (berisi petunjuk, daftar kode kategori & gudang).</li>
      <li>Isi satu baris per barang. Kolom <b>Kode Barang</b> diisi bila barang sudah punya kode; kosong = otomatis.</li>
      <li>Upload file yang sudah diisi.</li>
    </ol>
    <a class="btn secondary block" href="export.php?type=template">⬇️ Unduh template Excel</a>
    <label class="f" style="margin-top:12px">File hasil isian (.xlsx)<input type="file" id="up" accept=".xlsx"></label>
    <label class="small" style="display:block;margin-bottom:10px"><input type="checkbox" id="cfn"> Kode barang lama ada di kolom <b>Keterangan</b> (dipakai sebagai kode barang)</label>
    <button class="btn block" id="impUp">⬆️ Upload & Import</button>
    <div id="impResult"></div>
    <button class="btn secondary block" style="margin-top:8px" data-close>Tutup</button>`,
  (m) => {
    m.querySelector('#impUp').onclick = async () => {
      const file = m.querySelector('#up').files[0];
      if (!file) return toast('Pilih file terlebih dahulu', 'warn');
      const fd = new FormData(); fd.append('file', file);
      if (m.querySelector('#cfn').checked) fd.append('code_from_notes', '1');
      const btn = m.querySelector('#impUp');
      btn.disabled = true;
      try {
        const r = await api('import', fd);
        m.querySelector('#impResult').innerHTML = `<div class="scan-result ${r.errors.length ? 'warn' : 'ok'}" style="margin-top:10px">
          ✔ ${r.inserted} barang berhasil diimport${r.inserted ? ` (${esc(r.codes[0])} s/d ${esc(r.codes[r.codes.length - 1])})` : ''}.
          ${r.errors.length ? `<br>⚠ ${r.errors.length} baris gagal:<ul class="small" style="margin:4px 0 0;padding-left:18px">${r.errors.slice(0, 30).map((x) => `<li>${esc(x)}</li>`).join('')}</ul>${r.errors.length > 30 ? '…' : ''}` : ''}</div>
          ${r.inserted ? `<button class="btn secondary block" style="margin-top:8px" id="lblNew">🏷️ Cetak label ${r.inserted} barang baru</button>` : ''}`;
        const l = m.querySelector('#lblNew');
        if (l) l.onclick = () => openPrint('mode=items&codes=' + enc(r.codes.join(',')));
        reload();
      } catch (e) { toast(e.message, 'err'); }
      btn.disabled = false;
    };
  });
}

// ---------- Detail & riwayat barang ----------
ROUTES.item = async function viewItem(code) {
  const r = await get('item_history', { code });
  const i = r.item, loc = r.location;
  const row = (label, val) => val === null || val === undefined || val === '' ? '' : `<tr><th>${label}</th><td>${val}</td></tr>`;
  const warrantyLeft = i.warranty_until ? Math.ceil((new Date(i.warranty_until) - new Date()) / 86400000) : null;
  $view.innerHTML = `
    <div class="crumbs"><a href="#/items">Barang</a></div>
    <div class="card">
      <div class="row between"><h2 style="margin:0">${esc(i.nama)}</h2>${itemStatusBadge({ ...i, package_code: loc ? loc.code : null })}</div>
      <div class="mono small" style="margin:4px 0 10px">${esc(i.code)} · ${esc(i.category_name)}</div>
      <table class="t">
        ${row('Merk / Model', esc([i.merk, i.model].filter(Boolean).join(' · ')))}
        ${row('Spesifikasi', esc(i.spec))}
        ${i.track === 'qty' ? row('Stok', `<b class="${isLow(i) ? 'err-text' : ''}">${i.stock} ${esc(i.unit)}</b>${+i.min_stock ? ` (minimum ${i.min_stock})` : ''} · ${i.packed_qty} di paket aktif${isLow(i) ? ' · ⚠ di bawah stok minimum' : ''}`)
          : row('Serial number', esc(i.serial)) + row('No. aset', esc(i.asset_no))}
        ${row('Kondisi', esc(BOOT.item_conditions[i.cond] || i.cond))}
        ${row('Kelengkapan', esc(i.accessories))}
        ${row('Lokasi', `📍 <b>${esc(i.location_name)}</b>${i.bin_location ? ' · rak <b>' + esc(i.bin_location) + '</b>' : ''}${loc ? '<br>di paket ' + pathCrumbs(loc.path) + ' ' + stageBadge(loc.stage) + (loc.shipment ? ` · <a href="#/shipment/${enc(loc.shipment)}">${esc(loc.shipment)}</a>` : '') : ''}`)}
        ${row('Tgl perolehan', i.acquired_date ? fmtDay(i.acquired_date) : '')}
        ${row('Supplier', esc(i.supplier))}
        ${row('Nilai perolehan', i.acquired_value ? fmtRp(i.acquired_value) : '')}
        ${row('No. PO / dokumen', esc(i.po_number))}
        ${row('Garansi s/d', i.warranty_until ? fmtDay(i.warranty_until) + (warrantyLeft < 0 ? ' <span class="err-text">(habis)</span>' : ` (${warrantyLeft} hari lagi)`) : '')}
        ${row('Keterangan', esc(i.notes))}
      </table>
      <div class="row" style="margin-top:10px">
        <button class="btn secondary sm" id="label">🏷️ Cetak label</button>
        ${can('gudang') ? '<button class="btn secondary sm" id="edit">✏️ Edit</button>' : ''}
      </div>
    </div>
    <div class="card"><h3 style="margin-top:0">Foto</h3><div id="gallery"></div></div>
    <div class="card">
      <h3 style="margin-top:0">Riwayat</h3>
      ${r.events.length ? `<ul class="timeline">${r.events.map((e) => `
        <li><span class="dot">${EVENT_ICON[e.event] || '•'}</span><div><div>${esc(e.detail)}</div>
          <div class="muted small">${fmtDate(e.created_at)}${e.user_name ? ' · ' + esc(e.user_name) : ''}${e.package_code ? ` · <a href="#/package/${enc(e.package_code)}">${esc(e.package_code)}</a>` : ''}${e.shipment_code ? ` · <a href="#/shipment/${enc(e.shipment_code)}">${esc(e.shipment_code)}</a>` : ''}</div></div></li>`).join('')}</ul>`
        : '<div class="empty">Belum ada riwayat</div>'}
    </div>`;
  document.getElementById('label').onclick = () => openPrint('mode=items&codes=' + enc(i.code));
  const ed = document.getElementById('edit');
  if (ed) ed.onclick = () => itemDialog(i, () => route());
  photoGallery(document.getElementById('gallery'), 'item', i.code, { caption: i.code });
};

// ---------- Master kategori ----------
ROUTES.categories = async function viewCategories() {
  const r = await get('categories');
  $view.innerHTML = `<div class="crumbs"><a href="#/menu">Menu</a></div>
    <div class="row between"><h2>Kategori Barang</h2>${can('gudang') ? '<button class="btn" id="add">➕ Tambah</button>' : ''}</div>
    <div class="card"><p class="muted small" style="margin-top:0">Kode kategori menjadi awalan kode barang (mis. kategori <b>NET</b> → NET-0001, NET-0002). Kode tidak bisa diubah setelah dipakai barang.</p>
      <ul class="list">${r.categories.map((c, n) => `<li class="${can('gudang') ? 'click' : ''}" data-n="${n}"><span class="koli-no" style="min-width:52px">${esc(c.code)}</span>
        <div class="grow"><b>${esc(c.name)}</b><div class="muted small">${c.item_count} barang</div></div>${+c.active ? '' : badge('Nonaktif', 'none')}</li>`).join('')}</ul></div>`;
  const add = document.getElementById('add');
  if (add) add.onclick = () => categoryDialog();
  if (can('gudang')) $view.querySelectorAll('[data-n]').forEach((li) => (li.onclick = () => categoryDialog(r.categories[li.dataset.n])));
};

function categoryDialog(c) {
  const x = c || { active: 1 };
  modal(`<h3>${c ? 'Edit' : 'Tambah'} Kategori</h3><form>
    <div class="grid2"><label class="f">Kode (2-5 huruf)<input name="code" value="${esc(x.code)}" required maxlength="5" autocapitalize="characters" placeholder="mis. NET" ${c && +c.item_count ? 'readonly' : ''}></label>
    <label class="f">Nama kategori<input name="name" value="${esc(x.name)}" required placeholder="mis. Perangkat Jaringan"></label></div>
    ${c ? `<label class="small"><input type="checkbox" name="active" ${+x.active ? 'checked' : ''}> Aktif (bisa dipilih saat input barang)</label>` : ''}
    <div class="row end" style="margin-top:12px"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn">Simpan</button></div>
  </form>`, (m) => {
    const f = m.querySelector('form');
    f.onsubmit = async (e) => {
      e.preventDefault();
      try {
        await api('category_save', { id: x.id || 0, code: f.code.value, name: f.name.value, active: f.active ? f.active.checked : true });
        m.close(); toast('Kategori disimpan', 'ok');
        BOOT = await get('me');
        route();
      } catch (err) { toast(err.message, 'err'); }
    };
  });
}
