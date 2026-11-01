/* Tindak lanjut selisih penerimaan: barang kurang / rusak / kelengkapan kurang / lebih. */
'use strict';

const RES_HELP = {
  found_dest: 'Barang ternyata ada di cabang tujuan (terselip / lupa discan). Lokasi barang dipindah ke tujuan dan statusnya kembali Tersedia.',
  left_origin: 'Barang tidak ikut terkirim dan masih di gudang asal. Lokasi dikembalikan ke asal (barang jumlah: stok dikembalikan) sehingga bisa dikirim susulan.',
  resent: 'Barang dikirim dengan surat jalan lain. Selisih ini juga tertutup otomatis bila barang tersebut diterima lewat surat jalan berikutnya ke tujuan yang sama.',
  claim: 'Ajukan klaim ke ekspedisi / pihak yang bertanggung jawab. Isi nomor klaim dan nilainya.',
  writeoff: 'Barang dinyatakan hilang dan dihapus dari daftar barang tersedia. Isi kronologi kehilangan untuk berita acara.',
  retur: 'Barang dikirim balik untuk servis / penggantian. Buat surat jalan retur, lalu isi nomornya di sini.',
  complete: 'Kelengkapan yang kurang sudah diterima / dilengkapi.',
  accept: 'Barang diterima dan dipakai dalam kondisi apa adanya.',
  keep_dest: 'Barang yang tidak tercatat di surat jalan diterima dan disimpan di cabang tujuan.',
  return_origin: 'Barang lebih akan dikembalikan ke cabang asal (buat surat jalan retur bila perlu).',
};

const issueText = (r) => r.cond === 'ok' ? `Jumlah kurang ${r.short_qty} ${r.unit || ''}` : BOOT.receipt_conditions[r.cond];

/** Dialog keputusan tindak lanjut untuk satu baris penerimaan. onDone(detailSuratJalan) */
function resolveDialog(shipmentCode, r, onDone) {
  const opts = Object.entries(r.resolution_options || {});
  modal(`<h3>Tindak Lanjut Selisih</h3>
    <p class="small" style="margin-top:0"><b class="mono">${+r.is_manual ? '📝 Barang manual' : esc(r.code)}</b> ${esc(r.nama || r.jenis || '')}<br>
      <span class="err-text">${esc(issueText(r))}</span> · ${esc(shipmentCode)}${r.note ? '<br><span class="muted">' + esc(r.note) + '</span>' : ''}</p>
    <form>
      <div class="seg col">${opts.map(([k, l], i) => `<label><input type="radio" name="resolution" value="${k}" ${i === 0 ? 'checked' : ''}> ${esc(l)}</label>`).join('')}</div>
      <p class="muted small" id="rhelp"></p>
      <div id="refF"><label class="f"><span id="refL">No. dokumen</span><input name="ref" autocapitalize="characters"></label></div>
      <div id="valF"><label class="f">Nilai klaim (Rp)<input name="value" inputmode="numeric"></label></div>
      <label class="f"><span id="noteL">Keterangan</span><textarea name="note" rows="2"></textarea></label>
      <button type="button" class="btn secondary block" id="ph">📷 Lampirkan foto / dokumen</button>
      <div class="row end" style="margin-top:12px"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn ok">Simpan</button></div>
    </form>`,
  (m) => {
    const f = m.querySelector('form');
    const sync = () => {
      const v = f.resolution.value;
      m.querySelector('#rhelp').textContent = RES_HELP[v] || '';
      const refLabel = { claim: 'No. klaim *', resent: 'No. surat jalan susulan', retur: 'No. surat jalan retur', return_origin: 'No. surat jalan retur', writeoff: 'No. berita acara internal' }[v];
      m.querySelector('#refF').hidden = !refLabel;
      m.querySelector('#refL').textContent = refLabel || '';
      m.querySelector('#valF').hidden = v !== 'claim';
      m.querySelector('#noteL').textContent = v === 'writeoff' ? 'Kronologi kehilangan *' : 'Keterangan';
    };
    f.querySelectorAll('[name=resolution]').forEach((x) => (x.onchange = sync));
    sync();
    m.querySelector('#ph').onclick = () => takePhoto('receipt', shipmentCode + '|' + r.code, 'Tindak lanjut ' + r.code);
    f.onsubmit = async (e) => {
      e.preventDefault();
      try {
        const d = await api('resolve', { shipment: shipmentCode, code: r.code, resolution: f.resolution.value, ref: f.ref.value, value: f.value.value, note: f.note.value });
        m.close();
        toast(d.shipment.status === 'resolved' ? `Semua selisih ${shipmentCode} selesai ✔` : 'Tindak lanjut tersimpan', 'ok');
        onDone && onDone(d);
      } catch (err) { toast(err.message, 'err'); }
    };
  });
}

/** Tutup surat jalan bermasalah tanpa baris selisih (mis. hanya segel rusak, isi lengkap). */
function closeShipmentDialog(code, onDone) {
  modal(`<h3>Tutup Selisih ${esc(code)}</h3>
    <p class="muted small">Tidak ada barang yang selisih, tetapi penerimaan tercatat bermasalah (mis. segel rusak). Catat hasil pemeriksaan lalu tutup.</p>
    <form><label class="f">Hasil pemeriksaan *<textarea name="note" rows="3" required placeholder="mis. segel rusak saat bongkar, isi dicek lengkap & baik"></textarea></label>
    <div class="row end"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn ok">Tutup</button></div></form>`,
  (m) => {
    const f = m.querySelector('form');
    f.onsubmit = async (e) => {
      e.preventDefault();
      try { const d = await api('shipment_close', { shipment: code, note: f.note.value }); m.close(); toast('Surat jalan ditutup', 'ok'); onDone && onDone(d); } catch (err) { toast(err.message, 'err'); }
    };
  });
}

let followTab = 'open';
ROUTES.followup = async function viewFollowup() {
  const r = await get('followups', { status: followTab });
  const groups = {};
  r.rows.forEach((x) => (groups[x.shipment_code] = groups[x.shipment_code] || []).push(x));
  $view.innerHTML = `<div class="crumbs"><a href="#/menu">Menu</a></div>
    <h2>Tindak Lanjut Selisih</h2>
    <div class="tabs"><button data-t="open" class="${followTab === 'open' ? 'active' : ''}">⏳ Belum ditindaklanjuti</button><button data-t="done" class="${followTab === 'done' ? 'active' : ''}">✅ Sudah</button></div>
    ${followTab === 'open' ? `<p class="muted small">Setiap barang kurang, rusak, kelengkapan kurang, atau lebih harus diputuskan tindak lanjutnya. Surat jalan selesai otomatis bila semua selisihnya sudah ditindaklanjuti.</p>` : ''}
    ${Object.keys(groups).length ? Object.entries(groups).map(([code, rows]) => `
      <div class="card">
        <div class="row between"><a href="#/shipment/${enc(code)}"><b class="mono">${esc(code)}</b></a><span class="small muted">${esc(rows[0].origin_name)} → ${esc(rows[0].dest_name)} · ${fmtDate(rows[0].received_at)}</span></div>
        <ul class="list">${rows.map((x, i) => `<li><span class="ico">${x.resolution ? '✅' : x.cond === 'lebih' ? '➕' : x.cond === 'kurang' || x.cond === 'ok' ? '❌' : '💥'}</span>
          <div class="grow"><b class="mono">${+x.is_manual ? '📝' : esc(x.code)}</b> ${esc(x.nama || x.jenis || '')}${+x.is_manual ? ' <span class="badge">manual</span>' : ''}
            <div class="small err-text">${esc(x.cond_label)}${x.cond === 'kurang' ? ' · ' + x.qty + ' ' + esc(x.unit || 'unit') : ''}${x.note ? ' — ' + esc(x.note) : ''}</div>
            ${x.resolution ? `<div class="small">→ <b>${esc(x.resolution_label)}</b>${x.resolution_ref ? ' · ' + esc(x.resolution_ref) : ''}${x.resolution_note ? ' · ' + esc(x.resolution_note) : ''}<br><span class="muted">${esc(x.resolved_by_name || 'sistem')} · ${fmtDate(x.resolved_at)}</span></div>` : ''}</div>
          ${!x.resolution && x.can_resolve ? `<button class="btn sm" data-res="${esc(code)}|${i}">Tindak lanjut</button>` : ''}</li>`).join('')}</ul>
      </div>`).join('') : `<div class="card empty">${followTab === 'open' ? 'Tidak ada selisih yang perlu ditindaklanjuti 👍' : 'Belum ada tindak lanjut'}</div>`}
    ${r.to_close.length ? `<div class="card warn-card"><h3 style="margin-top:0">Surat jalan bermasalah tanpa selisih barang</h3><ul class="list">${r.to_close.map((s) => `
      <li><span class="ico">⚠️</span><div class="grow"><a href="#/shipment/${enc(s.code)}"><b class="mono">${esc(s.code)}</b></a><div class="small muted">${esc(s.origin_name)} → ${esc(s.dest_name)} · mis. segel rusak</div></div>
      <button class="btn sm" data-close-sj="${esc(s.code)}">Tutup</button></li>`).join('')}</ul></div>` : ''}`;
  $view.querySelectorAll('[data-t]').forEach((b) => (b.onclick = () => { followTab = b.dataset.t; route(); }));
  $view.querySelectorAll('[data-res]').forEach((b) => (b.onclick = () => {
    const [code, i] = b.dataset.res.split('|');
    resolveDialog(code, groups[code][+i], () => route());
  }));
  $view.querySelectorAll('[data-close-sj]').forEach((b) => (b.onclick = () => closeShipmentDialog(b.dataset.closeSj, () => route())));
};
