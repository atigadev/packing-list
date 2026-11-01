/* Pengiriman / surat jalan: daftar, form, koli, scan muat, berangkat, cetak. */
'use strict';

let shipFilter = { q: '', status: 'open' };

ROUTES.shipments = async function viewShipments() {
  const hq = hashQuery();
  if (hq === 'incoming') shipFilter = { q: '', status: 'in_transit', incoming: 1 };
  else if (hq) shipFilter = { q: '', status: hq };
  const tabs = [['open', 'Aktif'], ['draft', 'Persiapan'], ['in_transit', 'Dalam perjalanan'], ['done', 'Selesai'], ['all', 'Semua']];
  $view.innerHTML = `
    <div class="row between"><h2>${role() === 'cabang' ? 'Pengiriman & Penerimaan' : 'Pengiriman'}</h2>${can('gudang', 'cabang') ? '<button class="btn" id="newShip">➕ Surat Jalan</button>' : ''}</div>
    <div class="card">
      <input id="q" placeholder="Cari no. surat jalan, resi, plat, sopir, tujuan…" value="${esc(shipFilter.q)}">
      <div class="row between" style="margin-top:10px">
        <div class="tabs" style="margin:0">${tabs.map(([k, l]) => `<button data-s="${k}" class="${shipFilter.status === k ? 'active' : ''}">${l}</button>`).join('')}</div>
        <label class="small"><input type="checkbox" id="incoming" ${shipFilter.incoming ? 'checked' : ''}> Hanya menuju ${esc(workBranchName() || 'cabang saya')}</label>
      </div>
    </div>
    <div class="card"><div id="list">Memuat…</div></div>`;
  const nb = document.getElementById('newShip');
  if (nb) nb.onclick = () => newShipmentDialog();
  const load = async () => {
    const r = await get('shipments', shipFilter);
    const el = document.getElementById('list');
    if (!el) return;
    el.innerHTML = r.shipments.length ? `<ul class="list">${r.shipments.map((s) => `
      <li class="click" data-href="#/shipment/${enc(s.code)}"><span class="ico">${s.kind === 'retur' ? '↩️' : '🚚'}</span>
        <div class="grow"><b class="mono">${esc(s.code)}</b> <span class="small">${esc(s.origin_name)} → <b>${esc(s.dest_name)}</b></span>
          <div class="muted small">${s.koli} koli · ${s.mode === 'ekspedisi' ? esc(s.courier || 'Ekspedisi') + (s.tracking_no ? ' · resi ' + esc(s.tracking_no) : '') : esc([s.plate, s.driver].filter(Boolean).join(' · ') || 'Kendaraan internal')} · ${fmtDate(s.departed_at || s.created_at)}</div></div>
        ${shipBadge(s.status)}</li>`).join('')}</ul>` : '<div class="empty">Tidak ada pengiriman</div>';
    bindList(el);
  };
  let t;
  document.getElementById('q').oninput = (e) => { shipFilter.q = e.target.value; clearTimeout(t); t = setTimeout(load, 250); };
  document.getElementById('incoming').onchange = (e) => { shipFilter.incoming = e.target.checked ? 1 : ''; load(); };
  $view.querySelectorAll('[data-s]').forEach((b) => (b.onclick = () => {
    shipFilter.status = b.dataset.s;
    $view.querySelectorAll('[data-s]').forEach((x) => x.classList.toggle('active', x === b));
    load();
  }));
  load();
};

function shipmentForm(s) {
  s = s || { kind: 'kirim', mode: 'internal', origin_branch_id: workBranch() };
  const isNew = !s.code;
  const lockDest = !isNew && s.status !== 'draft';
  return `
    ${isNew ? `<div class="seg"><label><input type="radio" name="kind" value="kirim" ${s.kind !== 'retur' ? 'checked' : ''}> 🚚 Kirim</label><label><input type="radio" name="kind" value="retur" ${s.kind === 'retur' ? 'checked' : ''}> ↩️ Retur ke pusat/cabang</label></div>` : ''}
    <div class="grid2">
      <label class="f">Dari<select name="origin_branch_id" ${isNew && role() !== 'cabang' ? '' : 'disabled'}>${branchOptions(s.origin_branch_id)}</select></label>
      <label class="f">Tujuan<select name="dest_branch_id" ${lockDest ? 'disabled' : ''} required><option value="">— pilih —</option>${branchOptions(s.dest_branch_id)}</select></label>
    </div>
    <div class="f retur-only">Alasan retur<textarea name="reason" rows="2">${esc(s.reason)}</textarea></div>
    <div class="seg"><label><input type="radio" name="mode" value="internal" ${s.mode !== 'ekspedisi' ? 'checked' : ''}> Kendaraan sendiri</label><label><input type="radio" name="mode" value="ekspedisi" ${s.mode === 'ekspedisi' ? 'checked' : ''}> Ekspedisi pihak ketiga</label></div>
    <div class="mode-internal">
      <div class="grid2"><label class="f">Kendaraan<input name="vehicle" value="${esc(s.vehicle)}" placeholder="mis. L300, Grand Max"></label><label class="f">No. Polisi<input name="plate" value="${esc(s.plate)}" autocapitalize="characters"></label></div>
      <div class="grid2"><label class="f">Nama sopir<input name="driver" value="${esc(s.driver)}"></label><label class="f">HP sopir<input name="driver_phone" inputmode="tel" value="${esc(s.driver_phone)}"></label></div>
    </div>
    <div class="mode-ekspedisi">
      <div class="grid2"><label class="f">Nama ekspedisi<input name="courier" value="${esc(s.courier)}" placeholder="mis. JNE Trucking, Indah Cargo"></label><label class="f">No. resi<input name="tracking_no" value="${esc(s.tracking_no)}"></label></div>
    </div>
    <div class="grid2"><label class="f">Rencana berangkat<input type="date" name="depart_plan" value="${esc((s.depart_plan || '').slice(0, 10))}"></label><label class="f">Perkiraan tiba<input type="date" name="eta" value="${esc((s.eta || '').slice(0, 10))}"></label></div>
    <label class="f">Catatan<textarea name="notes" rows="2">${esc(s.notes)}</textarea></label>`;
}

function bindShipmentForm(f) {
  const sync = () => {
    const kind = f.kind ? f.kind.value : null;
    f.querySelectorAll('.retur-only').forEach((el) => (el.hidden = kind !== 'retur' && !(f.reason.value)));
    const mode = f.querySelector('[name=mode]:checked').value;
    f.querySelector('.mode-internal').hidden = mode !== 'internal';
    f.querySelector('.mode-ekspedisi').hidden = mode !== 'ekspedisi';
  };
  f.querySelectorAll('[name=kind],[name=mode]').forEach((r) => (r.onchange = sync));
  sync();
}

function newShipmentDialog() {
  modal(`<h3>Surat Jalan Baru</h3><form>${shipmentForm()}
    <p class="muted small">Nomor surat jalan dibuat otomatis. Setelah dibuat, scan label koli (paket yang sudah disegel) untuk memasukkannya.</p>
    <div class="row end"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn">Buat</button></div></form>`,
  (m) => {
    const f = m.querySelector('form');
    bindShipmentForm(f);
    f.onsubmit = async (e) => {
      e.preventDefault();
      try {
        const d = Object.fromEntries(new FormData(f));
        if (f.origin_branch_id.disabled) d.origin_branch_id = f.origin_branch_id.value;
        const r = await api('shipment_create', d);
        m.close();
        toast('Surat jalan ' + r.shipment.code + ' dibuat', 'ok');
        location.hash = '#/shipment/' + enc(r.shipment.code);
      } catch (err) { toast(err.message, 'err'); }
    };
  });
}

function shipmentPrintDocs(s) {
  const docs = [{ label: 'Surat jalan', mode: 'sj', code: s.code, bt: async (paper) => BtPrint.suratJalan(await get('shipment', { code: s.code, expected: 1 }), paper) }];
  if (s.received_at) docs.push({ label: 'BAST', mode: 'bast', code: s.code, bt: async (paper) => BtPrint.bast(await get('shipment', { code: s.code }), paper) });
  if (s.received_at && (s.receive_result || s.status) !== 'received') docs.push({ label: 'Berita acara selisih', mode: 'selisih', code: s.code });
  return docs;
}

ROUTES.shipment = async function viewShipment(code) {
  let d;
  try { d = await get('shipment', { code }); } catch (e) {
    $view.innerHTML = `<div class="card empty">${esc(e.message)}<br><br><a class="btn" href="#/shipments">Kembali</a></div>`;
    return;
  }
  let mode = 'koli', lastResult = null;

  const koliList = () => {
    const s = d.shipment;
    if (!d.packages.length) return '<div class="empty">Belum ada koli. Scan label paket yang sudah disegel.</div>';
    return `<ul class="list">${d.packages.map((p) => {
      const a = p.arrival;
      const right = s.status === 'draft'
        ? (p.loaded_at ? badge('✔ Dimuat', 'sealed') : badge('Belum dimuat', 'open')) + (can('gudang', 'cabang') ? ` <button class="btn danger sm" data-remove="${esc(p.code)}">✕</button>` : '')
        : s.status === 'in_transit' ? (a ? badge('Tiba', 'sealed') : stageBadge(p.stage))
        : (!a ? badge('Tidak tiba', 'err') : a.seal_ok === null ? badge('Tiba', 'sealed') : +a.seal_ok ? badge('Segel utuh', 'sealed') : badge('Segel rusak', 'err'));
      return `<li class="click" data-href="#/package/${enc(p.code)}"><span class="koli-no">${p.koli_no}</span>
        <div class="grow"><b class="mono">${esc(p.code)}</b> <span class="small">${esc(p.type_label)}${p.name ? ' · ' + esc(p.name) : ''}</span>
          <div class="muted small">${p.total_items} barang · ${p.weight_kg ? fmtNum(p.weight_kg) + ' kg' : 'berat ?'}${p.seal_no ? ' · segel ' + esc(p.seal_no) : ''}</div></div>${right}</li>`;
    }).join('')}</ul>`;
  };

  const totals = () => `<div class="verify" style="grid-template-columns:repeat(4,1fr)">
      <div><b>${d.packages.length}</b>Koli</div><div><b>${d.total_items}</b>Barang</div><div><b>${fmtNum(d.total_weight)}</b>kg</div>
      <div><b style="color:${d.loaded === d.packages.length && d.packages.length ? 'var(--ok)' : 'var(--warn)'}">${d.shipment.status === 'draft' ? d.loaded : d.arrived}/${d.packages.length}</b>${d.shipment.status === 'draft' ? 'Dimuat' : 'Tiba'}</div></div>`;

  const refresh = () => {
    const k = document.getElementById('koli');
    if (!k) return render();
    k.innerHTML = koliList();
    document.getElementById('totals').innerHTML = totals();
    bindKoli();
    if (activeScanner && lastResult) activeScanner.result(lastResult.msg, lastResult.type);
  };

  const bindKoli = () => {
    const k = document.getElementById('koli');
    bindList(k);
    k.querySelectorAll('[data-remove]').forEach((b) => (b.onclick = async (e) => {
      e.stopPropagation();
      if (!(await confirmBox(`Keluarkan <b>${esc(b.dataset.remove)}</b> dari surat jalan?`, 'Keluarkan'))) return;
      try { d = { ...d, ...(await api('shipment_remove_package', { shipment: d.shipment.code, code: b.dataset.remove })) }; refresh(); } catch (err) { toast(err.message, 'err'); }
    }));
  };

  const setResult = (msg, type) => { lastResult = { msg, type }; beep(type === 'ok'); };

  async function addScan(c, allowEmpty) {
    try {
      d = { ...d, ...(await api('shipment_add_package', { shipment: d.shipment.code, code: c, allow_empty: allowEmpty ? 1 : '' })) };
      setResult(`✔ Koli ${d.packages.length}: ${esc(c)}`, 'ok');
    } catch (e) {
      if (e.data && e.data.empty) {
        if (await confirmBox(esc(e.message) + ' Tetap masukkan sebagai koli?', 'Masukkan')) return addScan(c, true);
        return;
      }
      setResult((e.data && e.data.duplicate ? '⚠ ' : '✖ ') + esc(e.message), e.data && e.data.duplicate ? 'warn' : 'err');
    }
    refresh();
  }

  async function loadScan(c) {
    try {
      const r = await api('shipment_load', { shipment: d.shipment.code, code: c });
      if (r.queued) { setResult(`⏳ ${esc(c)} disimpan offline (${r.count} antre)`, 'warn'); return refresh(); }
      d = { ...d, ...r };
      setResult(`✔ Koli ${r.koli_no} (${esc(c)}) dimuat — ${d.loaded}/${d.packages.length}`, 'ok');
      if (d.loaded === d.packages.length) toast('Semua koli sudah dimuat ✔', 'ok');
    } catch (e) {
      setResult((e.data && e.data.duplicate ? '⚠ ' : '✖ ') + esc(e.message), e.data && e.data.duplicate ? 'warn' : 'err');
    }
    refresh();
  }

  const render = () => {
    stopScanner();
    const s = d.shipment;
    const transport = s.mode === 'ekspedisi'
      ? `Ekspedisi <b>${esc(s.courier || '-')}</b>${s.tracking_no ? ' · resi <b>' + esc(s.tracking_no) + '</b>' : ''}`
      : `${esc([s.vehicle, s.plate].filter(Boolean).join(' · ') || 'Kendaraan internal')}${s.driver ? ' · sopir <b>' + esc(s.driver) + '</b> ' + esc(s.driver_phone || '') : ''}`;
    const draft = s.status === 'draft';
    const done = ['received', 'partial', 'problem', 'resolved'].includes(s.status);
    $view.innerHTML = `
      <div class="crumbs"><a href="#/shipments">Pengiriman</a></div>
      <div class="card">
        <div class="row between"><h2 style="margin:0">${s.kind === 'retur' ? '↩️' : '🚚'} <span class="mono">${esc(s.code)}</span></h2>${shipBadge(s.status)}</div>
        <div class="route"><span>${esc(s.origin_name)}</span><span class="arrow">→</span><b>${esc(s.dest_name)}</b></div>
        <div class="kv">
          <div><span>Angkutan</span>${transport}</div>
          ${s.reason ? `<div><span>Alasan retur</span>${esc(s.reason)}</div>` : ''}
          <div><span>Dibuat</span>${fmtDate(s.created_at)} · ${esc(s.created_by_name || '-')}</div>
          ${s.depart_plan && draft ? `<div><span>Rencana</span>berangkat ${esc(s.depart_plan)}${s.eta ? ' · tiba ' + esc(s.eta) : ''}</div>` : ''}
          ${s.departed_at ? `<div><span>Berangkat</span>${fmtDate(s.departed_at)} · ${esc(s.departed_by_name || '-')}${s.eta ? ' · perkiraan tiba ' + esc(s.eta) : ''}</div>` : ''}
          ${s.received_at ? `<div><span>Diterima</span>${fmtDate(s.received_at)} · ${esc(s.receiver_name)}${s.receive_mode ? ' · ' + (s.receive_mode === 'koli' ? '📦 cek per koli' : '🔹 cek per barang') : ''}</div>` : ''}
          ${s.notes ? `<div><span>Catatan</span>${esc(s.notes)}</div>` : ''}
        </div>
        <div class="row" style="margin-top:12px">
          ${d.can_receive ? '<a class="btn ok sm" href="#/receive/' + enc(s.code) + '">📥 Terima Barang</a>' : ''}
          ${draft && can('gudang', 'cabang', 'kurir') ? '<button class="btn ok sm" id="depart">🚚 Berangkatkan</button>' : ''}
          <button class="btn secondary sm" id="print">🖨 Cetak</button>
          <a class="btn secondary sm" href="export.php?type=shipment&code=${enc(s.code)}">⬇️ Excel</a>
          ${!done && can('gudang', 'cabang', 'kurir') ? '<button class="btn secondary sm" id="edit">✏️ Edit</button>' : ''}
          ${draft && can('gudang', 'cabang') ? '<button class="btn danger sm" id="del">🗑 Hapus</button>' : ''}
        </div>
      </div>
      ${done ? receiptSummary(d) : ''}
      ${draft ? `<div class="tabs">
          ${can('gudang', 'cabang') ? `<button data-m="koli" class="${mode === 'koli' ? 'active' : ''}">📦 Tambah Koli</button>` : ''}
          <button data-m="load" class="${mode === 'load' || !can('gudang', 'cabang') ? 'active' : ''}">🚛 Scan Muat</button>
        </div>
        <div class="card">
          <div class="muted small" style="margin-bottom:8px">${mode === 'koli' && can('gudang', 'cabang') ? 'Scan label paket yang sudah disegel untuk dijadikan koli surat jalan ini.' : 'Scan label setiap koli saat dinaikkan ke kendaraan. Sistem menolak koli yang bukan bagian surat jalan ini.'}</div>
          <div id="sc"></div>
        </div>` : ''}
      <div class="card"><div id="totals">${totals()}</div><h3 style="margin:12px 0 0">Daftar Koli</h3><div id="koli">${koliList()}</div></div>
      <div class="card"><h3 style="margin-top:0">Foto</h3><div id="gallery"></div></div>
      <div class="card"><h3 style="margin-top:0">Riwayat</h3><div id="timeline" class="muted small">Memuat…</div></div>`;

    bindKoli();
    $view.querySelectorAll('[data-m]').forEach((b) => (b.onclick = () => { mode = b.dataset.m; lastResult = null; render(); }));
    if (!can('gudang', 'cabang')) mode = 'load';
    const sc = document.getElementById('sc');
    if (sc) createScanner(sc, mode === 'koli' ? addScan : loadScan, { placeholder: mode === 'koli' ? 'Scan label paket' : 'Scan label koli yang dimuat' });
    if (sc && lastResult && activeScanner) activeScanner.result(lastResult.msg, lastResult.type);

    const on = (id, fn) => { const el = document.getElementById(id); if (el) el.onclick = fn; };
    on('print', () => printDialog(s.code, shipmentPrintDocs(s)));
    on('edit', editDialog);
    on('del', async () => {
      if (!(await confirmBox(`Hapus surat jalan <b>${esc(s.code)}</b>? Koli di dalamnya dilepas kembali menjadi paket siap kirim.`, 'Hapus', true))) return;
      try { await api('shipment_delete', { code: s.code }); toast('Surat jalan dihapus', 'ok'); location.hash = '#/shipments'; } catch (e) { toast(e.message, 'err'); }
    });
    on('depart', depart);
    photoGallery(document.getElementById('gallery'), 'shipment', s.code, { prefix: true, caption: s.code });
    $view.querySelectorAll('[data-res]').forEach((b) => (b.onclick = () => resolveDialog(s.code, d.receipts[+b.dataset.res], (nd) => { d = { ...d, ...nd }; render(); })));
    on('closeSj', () => closeShipmentDialog(s.code, (nd) => { d = { ...d, ...nd }; render(); }));
    loadTimeline(s.code);
  };

  async function loadTimeline(c) {
    try {
      const r = await get('activity', { code: c });
      const el = document.getElementById('timeline');
      if (!el) return;
      el.className = '';
      el.innerHTML = r.activity.length ? `<ul class="timeline">${r.activity.map((a) => `<li><span class="dot">•</span><div><div>${esc(a.message)}</div><div class="muted small">${fmtDate(a.created_at)}${a.user_name ? ' · ' + esc(a.user_name) : ''}</div></div></li>`).join('')}</ul>` : '<div class="muted small">Belum ada riwayat</div>';
    } catch (e) { /* abaikan */ }
  }

  async function depart(force) {
    const s = d.shipment;
    if (force !== true && !(await confirmBox(`Berangkatkan <b>${esc(s.code)}</b> ke <b>${esc(s.dest_name)}</b>?<br>${d.packages.length} koli · ${d.total_items} barang · ${fmtNum(d.total_weight)} kg.<br>Setelah berangkat, koli tidak bisa diubah.`, 'Berangkatkan'))) return;
    try {
      d = { ...d, ...(await api('shipment_depart', { shipment: s.code, force: force === true ? 1 : '' })) };
      d.can_receive = false;
      toast('Surat jalan berangkat', 'ok');
      lastResult = null;
      render();
      if (await confirmBox('Cetak surat jalan untuk dibawa sopir?', 'Cetak')) printDialog(s.code, shipmentPrintDocs(d.shipment));
    } catch (e) {
      if (e.data && e.data.not_loaded) {
        if (await confirmBox(`${esc(e.message)}<br><br>Berangkatkan tanpa scan muat? Disarankan scan semua koli dulu.`, 'Tetap Berangkat', true)) return depart(true);
        return;
      }
      toast(e.message, 'err');
    }
  }

  function editDialog() {
    modal(`<h3>Edit ${esc(d.shipment.code)}</h3><form>${shipmentForm(d.shipment)}
      <div class="row end"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn">Simpan</button></div></form>`,
    (m) => {
      const f = m.querySelector('form');
      bindShipmentForm(f);
      f.onsubmit = async (e) => {
        e.preventDefault();
        try {
          const data = Object.fromEntries(new FormData(f));
          d = { ...d, ...(await api('shipment_update', { code: d.shipment.code, ...data })) };
          m.close();
          render();
        } catch (err) { toast(err.message, 'err'); }
      };
    });
  }

  render();
};

function receiptSummary(d) {
  const sum = d.receipt_summary;
  const s = d.shipment;
  const lines = d.receipts.map((r, i) => ({ r, i })).filter(({ r }) => r.needs_resolution || r.note);
  const issueOf = (r) => r.cond === 'ok' ? (r.short_qty > 0 ? `Jumlah kurang ${r.short_qty} ${r.unit || ''}` : 'Baik') : BOOT.receipt_conditions[r.cond];
  const closable = ['partial', 'problem'].includes(s.status) && !d.open_issues && d.can_resolve;
  return `<div class="card ${s.status === 'received' || s.status === 'resolved' ? '' : 'warn-card'}">
    <div class="row between"><h3 style="margin:0">Hasil Penerimaan</h3>${s.receive_mode ? `<span class="small muted">${s.receive_mode === 'koli' ? '📦 cek per koli' : '🔹 cek per barang'}</span>` : ''}</div>
    ${s.result_label && s.status === 'resolved' ? `<p class="small" style="margin:6px 0 0">Hasil saat diterima: <b>${esc(s.result_label)}</b> · selisih selesai ditindaklanjuti ${fmtDate(s.resolved_at)}</p>` : ''}
    <div class="verify" style="grid-template-columns:repeat(5,1fr);margin-top:10px">${Object.entries(BOOT.receipt_conditions).map(([k, l]) => `<div><b style="color:${k === 'ok' ? 'var(--ok)' : sum[k] ? 'var(--err)' : 'var(--muted)'}">${sum[k]}</b>${esc(l)}</div>`).join('')}</div>
    ${d.open_issues ? `<div class="scan-result err">⏳ ${d.open_issues} selisih belum ditindaklanjuti</div>` : ''}
    ${lines.length ? `<ul class="list">${lines.map(({ r, i }) => `<li><span class="ico">${!r.needs_resolution ? '📝' : r.resolution ? '✅' : '⚠️'}</span><div class="grow"><b class="mono">${+r.is_manual ? '📝' : esc(r.code)}</b> ${esc(r.nama || r.jenis || '')}${+r.is_manual ? ' <span class="badge">manual</span>' : ''}
      <div class="small ${r.needs_resolution ? 'err-text' : ''}">${esc(issueOf(r))} · ${r.qty} ${esc(r.unit || 'unit')}${r.note ? ' · ' + esc(r.note) : ''}</div>
      ${r.resolution ? `<div class="small">→ <b>${esc(r.resolution_label)}</b>${r.resolution_ref ? ' · ' + esc(r.resolution_ref) : ''}${r.resolution_note ? ' · ' + esc(r.resolution_note) : ''} <span class="muted">(${esc(r.resolved_by_name || 'sistem')}, ${fmtDate(r.resolved_at)})</span></div>` : ''}</div>
      ${r.needs_resolution && !r.resolution && d.can_resolve ? `<button class="btn sm" data-res="${i}">Tindak lanjut</button>` : ''}</li>`).join('')}</ul>` : '<p class="small" style="margin-bottom:0">✅ Semua barang diterima dalam kondisi baik.</p>'}
    ${closable ? '<button class="btn secondary sm" id="closeSj" style="margin-top:8px">Tutup selisih (hasil pemeriksaan)</button>' : ''}
    ${s.receive_notes ? `<p class="small" style="white-space:pre-line"><b>Catatan:</b> ${esc(s.receive_notes)}</p>` : ''}
  </div>`;
}
