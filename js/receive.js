/* Penerimaan di cabang: koli tiba + cek segel, scan isi + kondisi, tanda tangan & BAST. */
'use strict';

ROUTES.receive = async function viewReceive(code) {
  let d = await get('shipment', { code, expected: 1 });
  if (!d.can_receive) {
    location.hash = '#/shipment/' + enc(code);
    return toast(d.shipment.status === 'in_transit' ? 'Anda tidak berhak menerima pengiriman ini' : 'Pengiriman tidak dalam tahap penerimaan', 'warn');
  }
  let lastResult = null;
  const S = d.shipment;

  // Metode penerimaan: 'koli' = scan label koli saja, 'item' = koli + setiap isi
  const MODE_KEY = 'receiveMode:' + S.code;
  let rmode = localStorage.getItem(MODE_KEY);

  const receiptOf = (c) => d.receipts.find((r) => r.code.toLowerCase() === c.toLowerCase());
  const koliArrived = (koliCode) => { const p = d.packages.find((x) => x.code === koliCode); return !!(p && p.arrival); };
  /** Barang dianggap tercakup: sudah discan, atau (mode koli) koli-nya sudah tiba. */
  const covered = (e) => !!receiptOf(e.code) || (rmode === 'koli' && koliArrived(e.koli_code));
  const expectedTotal = () => d.expected.length;
  const scannedExpected = () => d.expected.filter(covered).length;

  const statusIcon = (r) => !r ? '⬜' : { ok: '✅', rusak: '💥', tidak_lengkap: '🧩', lebih: '➕' }[r.cond] || '✅';

  const bodyHtml = () => {
    const extra = d.receipts.filter((r) => r.cond === 'lebih');
    return `${d.packages.map((p) => {
      const a = p.arrival;
      const items = d.expected.filter((e) => e.koli_code === p.code);
      const done = items.filter(covered).length;
      return `<details class="sub" ${done < items.length || (rmode === 'koli' && !a) ? 'open' : ''}>
        <summary><span><span class="koli-no">${p.koli_no}</span> <b class="mono">${esc(p.code)}</b> <span class="muted small">${done}/${items.length} barang${p.seal_no ? ' · segel ' + esc(p.seal_no) : ''}</span></span>
          <span class="row" style="gap:4px">${!a ? badge('Belum tiba', 'open') : a.seal_ok === null ? `<button class="btn sm secondary" data-arrive="${esc(p.code)}">Cek segel</button>` : +a.seal_ok ? badge('Segel utuh', 'sealed') : badge('Segel rusak', 'err')}</span></summary>
        <div class="sub-body"><ul class="list">${items.map((e) => {
          const r = receiptOf(e.code);
          const byKoli = !r && rmode === 'koli' && a;
          const tick = !r && !byKoli && +e.is_manual; // barang manual tanpa barcode: dicentang dengan diketuk
          return `<li class="${r || byKoli || tick ? 'click' : ''}" ${r ? `data-cond="${esc(e.code)}"` : byKoli ? `data-mark="${esc(e.code)}"` : tick ? `data-tick="${esc(e.code)}"` : ''}><span class="ico">${byKoli ? '☑️' : tick ? '📝' : statusIcon(r)}</span>
            <div class="grow"><div>${esc(e.nama || e.jenis)}${e.track === 'qty' ? ` <b>× ${r ? r.qty + '/' : ''}${e.qty} ${esc(e.unit)}</b>` : ''}</div>
            <div class="mono small muted">${+e.is_manual ? '<span class="badge">manual · tanpa barcode</span>' + (tick ? ' ketuk untuk centang' : '') : esc(e.code)}${e.package_code !== p.code ? ' · di ' + esc(e.package_code) : ''}${e.accessories ? ' · ' + esc(e.accessories) : ''}</div>
            ${r && r.cond !== 'ok' ? `<div class="small err-text">${esc(BOOT.receipt_conditions[r.cond])}${r.note ? ' — ' + esc(r.note) : ''}</div>` : r && r.note ? `<div class="small">${esc(r.note)}</div>` : ''}
            ${byKoli ? '<div class="small muted">Diterima per koli · ketuk untuk tandai rusak / kurang lengkap</div>' : ''}</div>
            ${r || byKoli ? '<span class="muted">›</span>' : ''}</li>`;
        }).join('') || '<div class="empty">Koli kosong</div>'}</ul></div></details>`;
    }).join('')}
    ${extra.length ? `<h3>➕ Barang tidak tercatat di surat jalan</h3><ul class="list">${extra.map((r) => `<li class="click" data-cond="${esc(r.code)}"><span class="ico">➕</span><div class="grow"><b class="mono">${esc(r.code)}</b> ${esc(r.nama || '')}<div class="small muted">${esc(r.note || '')}</div></div></li>`).join('')}</ul>` : ''}`;
  };

  const statsHtml = () => `<div><b style="color:${d.arrived === d.packages.length ? 'var(--ok)' : 'var(--warn)'}">${d.arrived}/${d.packages.length}</b>Koli tiba</div><div><b style="color:${scannedExpected() === expectedTotal() ? 'var(--ok)' : 'var(--warn)'}">${scannedExpected()}/${expectedTotal()}</b>${rmode === 'koli' ? 'Barang tercakup' : 'Barang discan'}</div>
    <div><b style="color:${d.receipt_summary.rusak + d.receipt_summary.tidak_lengkap ? 'var(--err)' : 'var(--muted)'}">${d.receipt_summary.rusak + d.receipt_summary.tidak_lengkap}</b>Bermasalah</div>`;

  const refresh = () => {
    document.getElementById('rstats').innerHTML = statsHtml();
    document.getElementById('rbody').innerHTML = bodyHtml();
    bindBody();
    if (activeScanner && lastResult) activeScanner.result(lastResult.msg, lastResult.type);
  };

  const merge = (r) => { const exp = d.expected; d = { ...d, ...r, expected: exp, can_receive: true }; };
  const setResult = (msg, type) => { lastResult = { msg, type }; beep(type === 'ok'); };

  function bindBody() {
    const b = document.getElementById('rbody');
    b.querySelectorAll('[data-arrive]').forEach((x) => (x.onclick = (e) => { e.preventDefault(); arrivalDialog(d.packages.find((p) => p.code === x.dataset.arrive)); }));
    b.querySelectorAll('[data-cond]').forEach((x) => (x.onclick = () => conditionDialog(x.dataset.cond)));
    b.querySelectorAll('[data-tick]').forEach((x) => (x.onclick = () => scan(x.dataset.tick)));
    // Mode koli: barang belum discan bisa ditandai bermasalah (dicatat dulu sebagai diterima, lalu ubah kondisinya)
    b.querySelectorAll('[data-mark]').forEach((x) => (x.onclick = async () => {
      const e = d.expected.find((i) => i.code === x.dataset.mark);
      try {
        merge(await api('receive_item', { shipment: S.code, code: e.code, qty: e.track === 'qty' ? e.qty : '', note: 'Diperiksa saat penerimaan per koli' }));
        refresh();
        conditionDialog(e.code);
      } catch (err) { toast(err.message, 'err'); }
    }));
  }

  const allKoliArrived = () => d.packages.length && d.arrived === d.packages.length;
  const MODE_TEXT = {
    koli: 'Scan label setiap koli & cek segelnya. Isi koli yang tiba otomatis dicatat diterima lengkap & baik. Ketuk barang untuk menandai rusak / kurang lengkap.',
    item: '1) Scan label tiap koli & cek segel · 2) buka koli, scan setiap barang · 3) tandai barang rusak · 4) tanda tangan.',
  };

  function setMode(m) {
    rmode = m;
    localStorage.setItem(MODE_KEY, m);
    const seg = document.getElementById('rmode');
    if (seg) seg.querySelectorAll('input').forEach((i) => (i.checked = i.value === m));
    const help = document.getElementById('rhelp');
    if (help) help.textContent = MODE_TEXT[m];
    const input = document.querySelector('.scanner input[name=code]');
    if (input) input.placeholder = m === 'koli' ? 'Scan label koli' : 'Scan label koli atau kode barang';
    refresh();
  }

  function chooseModeDialog() {
    modal(`<h3>Metode Penerimaan</h3>
      <p class="muted small">Pilih cara memeriksa barang untuk surat jalan <b>${esc(S.code)}</b> (${d.packages.length} koli, ${d.expected.length} jenis barang). Bisa diganti selama belum selesai.</p>
      <div class="actions" style="grid-template-columns:1fr">
        <button class="btn" data-mode="koli">📦 Scan koli saja<br><span class="small" style="font-weight:normal">Cepat — cek label & segel tiap koli, isi dianggap lengkap</span></button>
        <button class="btn secondary" data-mode="item">📦 + 🔹 Scan koli beserta isi<br><span class="small" style="font-weight:normal">Teliti — setiap barang di dalam koli discan</span></button>
      </div>`,
    (m) => {
      m.oncancel = (e) => e.preventDefault();
      m.querySelectorAll('[data-mode]').forEach((b) => (b.onclick = () => { m.close(); setMode(b.dataset.mode); if (activeScanner) activeScanner.focus(); }));
    });
  }

  async function scan(c, qty) {
    try {
      const r = await api('receive_item', { shipment: S.code, code: c, qty: qty ?? '' });
      if (r.queued) { setResult(`⏳ ${esc(c)} disimpan offline (${r.count} antre)`, 'warn'); return refresh(); }
      if (r.kind === 'package') {
        setResult(`📦 Koli ${esc(r.package.koli || '')} ${esc(r.package.code)} — periksa segel`, 'ok');
        refresh();
        return arrivalDialog(d.packages.find((p) => p.code === r.package.code) || r.package);
      }
      merge(r);
      if (r.expected) setResult(`✔ ${esc(r.item.nama || r.item.jenis || r.item.code)}${r.qty_short ? ' — <b>jumlah kurang</b>' : ''}`, r.qty_short ? 'warn' : 'ok');
      else setResult(`➕ ${esc(c)} tidak tercatat di surat jalan (dicatat sebagai lebih)`, 'warn');
      if (scannedExpected() === expectedTotal() && expectedTotal()) toast('Semua barang sudah discan ✔ — tekan Selesai & Tanda Tangan', 'ok');
    } catch (e) {
      const dd = e.data || {};
      if (dd.need_qty) {
        const q = await promptQty('Jumlah diterima', `${esc(dd.item.nama || dd.item.code)} — tercatat <b>${dd.expected_qty} ${esc(dd.item.unit)}</b>`, dd.expected_qty);
        if (activeScanner) activeScanner.focus();
        if (q !== null) return scan(c, q);
        return;
      }
      setResult((dd.duplicate ? '⚠ ' : '✖ ') + esc(e.message), dd.duplicate ? 'warn' : 'err');
    }
    refresh();
  }

  function arrivalDialog(p) {
    modal(`<h3>Koli ${esc(p.koli_no)} — ${esc(p.code)}</h3>
      <p class="small">No. segel tercatat: <b>${esc(p.seal_no || 'tidak ada')}</b></p>
      <form>
        <div class="seg"><label><input type="radio" name="seal_ok" value="1" checked> ✅ Segel utuh</label><label><input type="radio" name="seal_ok" value="0"> ⚠️ Rusak / terbuka</label></div>
        ${p.seal_no ? '<label class="f">No. segel pada koli<input name="seal_no_checked" autocapitalize="characters" placeholder="ketik / cocokkan nomor segel"></label>' : ''}
        <label class="f">Catatan kondisi kemasan<input name="note" placeholder="mis. dus penyok sisi kiri"></label>
        <button type="button" class="btn secondary block" id="ph">📷 Foto kondisi koli</button>
        <div class="row end" style="margin-top:12px"><button type="button" class="btn secondary" data-close>Nanti</button><button class="btn">Simpan</button></div>
      </form>`,
    (m) => {
      const f = m.querySelector('form');
      m.querySelector('#ph').onclick = () => takePhoto('receipt', S.code + '|' + p.code, 'Kondisi koli ' + p.code + ' saat tiba');
      f.onsubmit = async (e) => {
        e.preventDefault();
        const sealNo = f.seal_no_checked ? f.seal_no_checked.value.trim() : '';
        let sealOk = f.seal_ok.value === '1';
        if (sealOk && p.seal_no && sealNo && sealNo.toUpperCase() !== String(p.seal_no).toUpperCase()) {
          if (!(await confirmBox(`Nomor segel <b>${esc(sealNo)}</b> tidak sama dengan yang tercatat (<b>${esc(p.seal_no)}</b>). Tandai segel bermasalah?`, 'Tandai Bermasalah', true))) return arrivalDialog(p);
          sealOk = false;
        }
        try {
          const r = await api('receive_package', { shipment: S.code, code: p.code, seal_ok: sealOk ? 1 : 0, seal_no_checked: sealNo, note: f.note.value });
          if (!r.queued) merge(r);
          m.close();
          setResult(sealOk ? `✔ Koli ${p.koli_no} tiba, segel utuh` : `⚠ Koli ${p.koli_no}: segel bermasalah`, sealOk ? 'ok' : 'warn');
          refresh();
          if (rmode === 'koli' && allKoliArrived()) toast('Semua koli sudah tiba ✔ — tekan Selesai & Tanda Tangan', 'ok');
          if (activeScanner) activeScanner.focus();
        } catch (err) { toast(err.message, 'err'); }
      };
    });
  }

  function conditionDialog(c) {
    const r = receiptOf(c);
    if (!r) return;
    const exp = d.expected.find((e) => e.code.toLowerCase() === c.toLowerCase());
    const conds = Object.entries(BOOT.receipt_conditions).filter(([k]) => r.cond === 'lebih' ? k === 'lebih' : !['kurang', 'lebih'].includes(k));
    modal(`<h3>${esc(r.nama || r.jenis || c)}</h3><p class="mono small">${esc(c)}${exp && exp.accessories ? '<br>Kelengkapan: ' + esc(exp.accessories) : ''}</p>
      <form>
        <div class="seg col">${conds.map(([k, l]) => `<label><input type="radio" name="cond" value="${k}" ${r.cond === k ? 'checked' : ''}> ${statusIcon({ cond: k })} ${esc(l)}</label>`).join('')}</div>
        ${exp && exp.track === 'qty' ? `<label class="f">Jumlah diterima (tercatat ${exp.qty} ${esc(exp.unit)})<input type="number" name="qty" min="0" value="${r.qty}"></label>` : ''}
        <label class="f">Catatan<input name="note" value="${esc(r.note)}" placeholder="mis. layar retak, adaptor tidak ada"></label>
        <button type="button" class="btn secondary block" id="ph">📷 Foto barang</button>
        <div class="row between" style="margin-top:12px"><button type="button" class="btn danger sm" id="undo">Batalkan scan</button>
          <div class="row"><button type="button" class="btn secondary" data-close>Tutup</button><button class="btn">Simpan</button></div></div>
      </form>`,
    (m) => {
      const f = m.querySelector('form');
      m.querySelector('#ph').onclick = () => takePhoto('receipt', S.code + '|' + c, 'Kondisi ' + c);
      m.querySelector('#undo').onclick = async () => {
        try { merge(await api('receive_item_delete', { shipment: S.code, code: c })); m.close(); refresh(); } catch (err) { toast(err.message, 'err'); }
      };
      f.onsubmit = async (e) => {
        e.preventDefault();
        try {
          merge(await api('receive_item_update', { shipment: S.code, code: c, cond: f.cond.value, note: f.note.value, qty: f.qty ? f.qty.value : '' }));
          m.close();
          refresh();
        } catch (err) { toast(err.message, 'err'); }
      };
    });
  }

  function finishDialog() {
    const missing = d.expected.filter((e) => !covered(e));
    const notArrived = d.packages.filter((p) => !p.arrival);
    const sealUnchecked = d.packages.filter((p) => p.arrival && p.arrival.seal_ok === null);
    const byKoli = rmode === 'koli' ? d.expected.filter((e) => !receiptOf(e.code) && koliArrived(e.koli_code)).length : 0;
    modal(`<h3>Selesai & Tanda Tangan</h3>
      <p class="small" style="margin-top:0">Metode: <b>${rmode === 'koli' ? '📦 Per koli' : '📦 + 🔹 Per barang'}</b></p>
      ${missing.length || notArrived.length ? `<div class="scan-result err" style="margin-bottom:8px">
        ${notArrived.length ? `${notArrived.length} koli belum tiba: ${notArrived.map((p) => esc(p.code)).join(', ')}<br>` : ''}
        ${missing.length ? `${missing.length} barang ${rmode === 'koli' ? 'di koli yang belum tiba' : 'belum discan'} akan dicatat <b>KURANG / HILANG</b>:<br><span class="small">${missing.slice(0, 8).map((e) => esc(e.code)).join(', ')}${missing.length > 8 ? '…' : ''}</span>` : ''}</div>`
        : `<div class="scan-result ok" style="margin-bottom:8px">${rmode === 'koli' ? 'Semua koli sudah tiba.' : 'Semua koli tiba dan semua barang sudah discan.'}</div>`}
      ${byKoli ? `<div class="scan-result warn" style="margin-bottom:8px">${byKoli} barang dicatat <b>diterima baik</b> berdasarkan koli (isi tidak discan satu per satu).</div>` : ''}
      ${sealUnchecked.length ? `<div class="scan-result warn" style="margin-bottom:8px">${sealUnchecked.length} koli belum dicek segelnya.</div>` : ''}
      <form>
        <label class="f">Nama penerima<input name="receiver_name" value="${esc(me().name)}" required></label>
        <label class="f">Catatan penerimaan<textarea name="notes" rows="2"></textarea></label>
        <div class="f">Tanda tangan penerima<canvas class="sigpad"></canvas><button type="button" class="btn secondary sm" id="clr" style="margin-top:4px">Hapus tanda tangan</button></div>
        <div class="row end" style="margin-top:12px"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn ok">Simpan BAST</button></div>
      </form>`,
    (m) => {
      const f = m.querySelector('form');
      const pad = signaturePad(m.querySelector('canvas'));
      m.querySelector('#clr').onclick = () => pad.clear();
      f.onsubmit = async (e) => {
        e.preventDefault();
        if (pad.isEmpty()) return toast('Tanda tangan penerima wajib diisi', 'warn');
        if (OfflineQueue.count()) return toast('Masih ada scan offline yang belum terkirim. Tunggu sampai online.', 'warn');
        const btn = f.querySelector('.btn.ok');
        btn.disabled = true;
        try {
          const r = await api('receive_finish', { shipment: S.code, receiver_name: f.receiver_name.value, notes: f.notes.value, signature: pad.toDataURL(), mode: rmode });
          localStorage.removeItem(MODE_KEY);
          m.close();
          toast('Penerimaan selesai — ' + r.shipment.status_label, r.shipment.status === 'received' ? 'ok' : 'warn');
          location.hash = '#/shipment/' + enc(S.code);
          setTimeout(() => printDialog('BAST ' + S.code, shipmentPrintDocs(r.shipment).filter((x) => x.mode === 'bast')), 400);
        } catch (err) { toast(err.message, 'err'); btn.disabled = false; }
      };
    });
  }

  $view.innerHTML = `
    <div class="crumbs"><a href="#/shipments">Pengiriman</a> › <a href="#/shipment/${enc(S.code)}">${esc(S.code)}</a></div>
    <div class="card">
      <div class="row between"><h2 style="margin:0">📥 Terima ${esc(S.code)}</h2>${shipBadge(S.status)}</div>
      <div class="route"><span>${esc(S.origin_name)}</span><span class="arrow">→</span><b>${esc(S.dest_name)}</b></div>
      <div class="seg" id="rmode" style="margin-top:10px">
        <label><input type="radio" name="rmode" value="koli" ${rmode === 'koli' ? 'checked' : ''}> 📦 Scan koli saja</label>
        <label><input type="radio" name="rmode" value="item" ${rmode === 'item' ? 'checked' : ''}> 📦 + 🔹 Koli beserta isi</label>
      </div>
      <div class="muted small" id="rhelp">${esc(MODE_TEXT[rmode] || 'Pilih metode penerimaan.')}</div>
      <div class="verify" id="rstats" style="grid-template-columns:repeat(3,1fr);margin-top:10px">${statsHtml()}</div>
      <div id="sc"></div>
      <button class="btn ok block" id="finish" style="margin-top:10px">✍️ Selesai & Tanda Tangan</button>
    </div>
    <div class="card" id="rbody">${bodyHtml()}</div>`;
  bindBody();
  createScanner(document.getElementById('sc'), (c) => scan(c), { placeholder: rmode === 'koli' ? 'Scan label koli' : 'Scan label koli atau kode barang' });
  document.getElementById('finish').onclick = () => (rmode ? finishDialog() : chooseModeDialog());
  document.querySelectorAll('#rmode input').forEach((i) => (i.onchange = () => setMode(i.value)));
  if (!rmode) chooseModeDialog();
};
