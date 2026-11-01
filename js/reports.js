/* Laporan: pengiriman belum diterima, selisih penerimaan, rekap bulanan, ekspor Excel. */
'use strict';

let reportTab = 'outstanding';

ROUTES.reports = async function viewReports() {
  const today = new Date();
  const iso = (d) => d.toISOString().slice(0, 10);
  const state = {
    from: iso(new Date(today.getFullYear(), today.getMonth(), 1)),
    to: iso(today),
    month: iso(today).slice(0, 7),
  };
  $view.innerHTML = `<div class="crumbs"><a href="#/menu">Menu</a></div><h2>Laporan</h2>
    <div class="tabs">
      <button data-t="outstanding">🚚 Belum diterima</button>
      <button data-t="disc">⚠️ Selisih penerimaan</button>
      <button data-t="recap">📊 Rekap bulanan</button>
      <button data-t="export">⬇️ Ekspor</button>
    </div>
    <div id="rep"></div>`;
  const el = document.getElementById('rep');

  const render = async () => {
    $view.querySelectorAll('[data-t]').forEach((b) => b.classList.toggle('active', b.dataset.t === reportTab));
    el.innerHTML = '<div class="card">Memuat…</div>';
    if (reportTab === 'outstanding') {
      const r = await get('report_outstanding');
      el.innerHTML = `<div class="card"><p class="muted small" style="margin-top:0">Surat jalan yang masih persiapan atau dalam perjalanan. Perhatikan yang sudah lama belum diterima.</p>
        ${r.rows.length ? `<table class="t"><tr><th>Surat jalan</th><th>Tujuan</th><th>Koli</th><th>Status</th><th>Hari</th></tr>
        ${r.rows.map((s) => `<tr class="click" data-href="#/shipment/${enc(s.code)}"><td class="mono">${esc(s.code)}</td><td>${esc(s.dest_name)}<div class="muted small">dari ${esc(s.origin_name)}</div></td><td>${s.koli}</td><td>${shipBadge(s.status)}</td>
          <td><b style="color:${s.status === 'in_transit' && s.days > 3 ? 'var(--err)' : 'inherit'}">${s.days}</b></td></tr>`).join('')}</table>` : '<div class="empty">Semua pengiriman sudah diterima 👍</div>'}</div>`;
    } else if (reportTab === 'disc') {
      const r = await get('report_discrepancies', { from: state.from, to: state.to });
      el.innerHTML = `<div class="card">
        <div class="row"><label class="f grow">Dari<input type="date" id="from" value="${state.from}"></label><label class="f grow">Sampai<input type="date" id="to" value="${state.to}"></label>
        <a class="btn secondary sm" href="export.php?type=discrepancies&from=${state.from}&to=${state.to}">⬇️ Excel</a></div>
        ${r.rows.length ? `<table class="t"><tr><th>Surat jalan</th><th>Barang</th><th>Selisih</th><th>Tindak lanjut</th></tr>
        ${r.rows.map((x) => `<tr class="click" data-href="#/shipment/${enc(x.shipment_code)}"><td class="mono small">${esc(x.shipment_code)}<div class="muted">${esc(x.dest_name)}</div></td>
          <td>${esc(x.nama || x.jenis || '')}<div class="mono small muted">${esc(x.code)} · ${x.qty} ${esc(x.unit || 'unit')}</div></td><td>${badge(x.cond_label, 'err')}<div class="small muted hide-sm">${esc(x.note || '')}</div></td><td class="small">${x.resolution_label ? '✅ ' + esc(x.resolution_label) + (x.resolution_ref ? ' · ' + esc(x.resolution_ref) : '') : '<b class="err-text">⏳ Belum</b>'}</td></tr>`).join('')}</table>`
        : '<div class="empty">Tidak ada selisih pada periode ini</div>'}</div>`;
      const upd = () => { state.from = document.getElementById('from').value; state.to = document.getElementById('to').value; render(); };
      document.getElementById('from').onchange = upd;
      document.getElementById('to').onchange = upd;
    } else if (reportTab === 'recap') {
      const r = await get('report_recap', { month: state.month });
      const tot = r.rows.reduce((a, x) => ({ s: a.s + +x.shipments, k: a.k + +x.koli, i: a.i + +x.items, w: a.w + +x.weight }), { s: 0, k: 0, i: 0, w: 0 });
      el.innerHTML = `<div class="card">
        <div class="row"><label class="f grow">Bulan<input type="month" id="month" value="${state.month}"></label><a class="btn secondary sm" href="export.php?type=recap&month=${state.month}">⬇️ Excel</a></div>
        <div class="verify" style="grid-template-columns:repeat(4,1fr)"><div><b>${tot.s}</b>Pengiriman</div><div><b>${tot.k}</b>Koli</div><div><b>${tot.i}</b>Barang</div><div><b>${fmtNum(tot.w)}</b>kg</div></div>
        ${r.rows.length ? `<table class="t"><tr><th>Cabang tujuan</th><th>Kirim</th><th>Koli</th><th>Barang</th><th class="hide-sm">Berat</th><th>Hasil</th></tr>
        ${r.rows.map((x) => `<tr><td>${esc(x.branch)}${x.kind === 'retur' ? ' ' + badge('Retur') : ''}</td><td>${x.shipments}</td><td>${x.koli}</td><td>${x.items}</td><td class="hide-sm">${fmtNum(x.weight)} kg</td>
          <td class="small">✅ ${x.ok}${+x.issue ? ` · <span class="err-text">⚠ ${x.issue}</span>` : ''}${+x.transit ? ' · 🚚 ' + x.transit : ''}</td></tr>`).join('')}</table>` : '<div class="empty">Belum ada pengiriman bulan ini</div>'}</div>`;
      document.getElementById('month').onchange = (e) => { state.month = e.target.value; render(); };
    } else {
      el.innerHTML = `<div class="card"><p class="muted small" style="margin-top:0">Unduh data dalam format Excel (.xlsx).</p>
        <div class="row"><label class="f grow">Dari<input type="date" id="from" value="${state.from}"></label><label class="f grow">Sampai<input type="date" id="to" value="${state.to}"></label></div>
        <div class="actions">
          <a class="btn secondary" href="export.php?type=items">🗂️ Database barang & lokasi</a>
          <a class="btn secondary" id="xs" href="#">🚚 Daftar pengiriman</a>
          <a class="btn secondary" id="xd" href="#">⚠️ Selisih penerimaan</a>
          <a class="btn secondary" href="export.php?type=recap&month=${state.month}">📊 Rekap bulan ini</a>
        </div></div>`;
      const link = (t) => `export.php?type=${t}&from=${document.getElementById('from').value}&to=${document.getElementById('to').value}`;
      document.getElementById('xs').onclick = (e) => { e.preventDefault(); location.href = link('shipments'); };
      document.getElementById('xd').onclick = (e) => { e.preventDefault(); location.href = link('discrepancies'); };
    }
    bindList(el);
  };

  $view.querySelectorAll('[data-t]').forEach((b) => (b.onclick = () => { reportTab = b.dataset.t; render(); }));
  render();
};
