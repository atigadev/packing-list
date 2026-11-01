/* Antrean scan offline: scan packing / muat / penerimaan disimpan di HP saat sinyal hilang,
 * lalu dikirim otomatis begitu koneksi kembali. */
'use strict';

const OfflineQueue = (() => {
  const KEY = 'pl_queue', FAIL = 'pl_failed';
  const QUEUEABLE = ['package_add', 'shipment_load', 'receive_item', 'receive_package'];
  let syncing = false;

  const read = (k) => { try { return JSON.parse(localStorage.getItem(k)) || []; } catch (e) { return []; } };
  const write = (k, v) => { localStorage.setItem(k, JSON.stringify(v)); updateBadge(); };

  function updateBadge() {
    const el = document.getElementById('queueBadge');
    if (!el) return;
    const n = read(KEY).length, f = read(FAIL).length;
    el.hidden = !n && !f;
    el.textContent = n ? `⏳ ${n} antre` : `⚠ ${f} gagal`;
    el.className = 'net ' + (n ? 'queue' : 'fail');
  }

  function add(action, data) {
    const q = read(KEY);
    q.push({ id: Date.now() + '-' + Math.random().toString(36).slice(2, 6), action, data, at: new Date().toISOString() });
    write(KEY, q);
    return { queued: true, count: q.length };
  }

  async function sync() {
    if (syncing || !navigator.onLine || !read(KEY).length || !BOOT) return;
    syncing = true;
    let sent = 0, failed = 0;
    try {
      while (read(KEY).length) {
        const job = read(KEY)[0];
        try {
          const res = await fetch('api.php?action=' + job.action, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(job.data),
          });
          const json = await res.json().catch(() => ({}));
          if (res.status === 401) break; // sesi habis: tunggu login ulang
          if (!res.ok && !json.duplicate) {
            failed++;
            write(FAIL, [...read(FAIL), { ...job, error: json.error || 'Gagal', failed_at: new Date().toISOString() }]);
          } else {
            sent++;
          }
          write(KEY, read(KEY).filter((j) => j.id !== job.id));
        } catch (e) {
          break; // masih offline
        }
      }
    } finally {
      syncing = false;
      updateBadge();
    }
    if (sent || failed) {
      toast(`Sinkronisasi: ${sent} scan terkirim${failed ? `, ${failed} gagal (lihat Menu › Antrean Offline)` : ''}`, failed ? 'warn' : 'ok');
      if (sent && typeof route === 'function' && !document.querySelector('dialog[open]')) route();
    }
  }

  setInterval(sync, 30000);
  document.addEventListener('DOMContentLoaded', updateBadge);

  return {
    canQueue: (action) => QUEUEABLE.includes(action),
    add, sync,
    count: () => read(KEY).length,
    pending: () => read(KEY),
    failed: () => read(FAIL),
    clearFailed: () => write(FAIL, []),
    removeFailed: (id) => write(FAIL, read(FAIL).filter((j) => j.id !== id)),
    retryFailed: (id) => {
      const job = read(FAIL).find((j) => j.id === id);
      if (!job) return;
      write(FAIL, read(FAIL).filter((j) => j.id !== id));
      write(KEY, [...read(KEY), { id: job.id, action: job.action, data: job.data, at: job.at }]);
      sync();
    },
  };
})();

const ACTION_LABEL = { package_add: 'Masuk paket', shipment_load: 'Scan muat', receive_item: 'Terima barang', receive_package: 'Koli tiba' };

ROUTES.queue = async function viewQueue() {
  const render = () => {
    const pending = OfflineQueue.pending(), failed = OfflineQueue.failed();
    const ref = (j) => j.data.package || j.data.shipment || '';
    $view.innerHTML = `<h2>Antrean Offline</h2>
      <p class="muted small">Scan yang dilakukan saat tidak ada sinyal disimpan di HP ini dan dikirim otomatis saat koneksi kembali.</p>
      <div class="card"><div class="row between"><h3 style="margin:0">Menunggu dikirim (${pending.length})</h3>
        <button class="btn sm" id="syncNow" ${pending.length ? '' : 'disabled'}>Kirim sekarang</button></div>
        ${pending.length ? `<ul class="list">${pending.map((j) => `<li><span class="ico">⏳</span><div class="grow"><b class="mono">${esc(j.data.code)}</b>
          <div class="muted small">${esc(ACTION_LABEL[j.action] || j.action)} ${esc(ref(j))} · ${fmtDate(j.at)}</div></div></li>`).join('')}</ul>` : '<div class="empty">Tidak ada antrean</div>'}
      </div>
      <div class="card"><div class="row between"><h3 style="margin:0">Gagal (${failed.length})</h3>
        ${failed.length ? '<button class="btn danger sm" id="clearFail">Hapus semua</button>' : ''}</div>
        <p class="muted small">Scan yang ditolak server (mis. barang sudah ada di paket lain). Periksa lalu ulangi scan secara manual bila perlu.</p>
        ${failed.length ? `<ul class="list">${failed.map((j) => `<li><span class="ico">⚠️</span><div class="grow"><b class="mono">${esc(j.data.code)}</b>
          <div class="small">${esc(j.error)}</div><div class="muted small">${esc(ACTION_LABEL[j.action] || j.action)} ${esc(ref(j))} · ${fmtDate(j.at)}</div></div>
          <button class="btn secondary sm" data-retry="${j.id}">Ulangi</button><button class="btn danger sm" data-rm="${j.id}">✕</button></li>`).join('')}</ul>` : '<div class="empty">Tidak ada</div>'}
      </div>`;
    const s = document.getElementById('syncNow');
    if (s) s.onclick = async () => { await OfflineQueue.sync(); render(); };
    const c = document.getElementById('clearFail');
    if (c) c.onclick = () => { OfflineQueue.clearFailed(); render(); };
    $view.querySelectorAll('[data-retry]').forEach((b) => (b.onclick = () => { OfflineQueue.retryFailed(b.dataset.retry); setTimeout(render, 800); }));
    $view.querySelectorAll('[data-rm]').forEach((b) => (b.onclick = () => { OfflineQueue.removeFailed(b.dataset.rm); render(); }));
  };
  render();
};
