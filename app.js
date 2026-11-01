/* Packing List Gudang — PWA frontend (inti: API, login, scanner, foto, cetak, router, update) */
'use strict';

const $view = document.getElementById('view');
const $modal = document.getElementById('modal');
const TYPE_ICON = { DUS: '📦', BOX: '🧰', CTR: '🚛', PLT: '🪵', KRG: '🛍️', LNY: '📁' };
const ROUTES = {};          // diisi oleh file js/*.js
let BOOT = null;            // data login: user, settings, branches, label-label
let activeScanner = null;

// ---------- Utilitas ----------
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const enc = encodeURIComponent;
const fmtNum = (n, d = 1) => (n === null || n === undefined || n === '') ? '-' : (+n).toLocaleString('id-ID', { maximumFractionDigits: d });
const fmtDate = (s) => s ? new Date(s.replace(' ', 'T')).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';
const me = () => BOOT && BOOT.user;
const role = () => me() ? me().role : '';
const can = (...roles) => role() === 'admin' || roles.includes(role()) || (role() === 'koordinator' && roles.some((r) => ['gudang', 'cabang', 'kurir'].includes(r)));
const isMulti = () => !!(me() && +me().multi_branch);
const workBranch = () => me() ? (me().work_branch_id || me().branch_id) : null;
const workBranchName = () => me() ? (me().work_branch_name || me().branch_name || '') : '';
const branchName = (id) => { const b = BOOT.branches.find((x) => +x.id === +id); return b ? b.name : '-'; };
const TYPES = () => BOOT.types;

async function api(action, data, method) {
  const opts = { method: method || (data ? 'POST' : 'GET'), headers: {}, credentials: 'same-origin' };
  let url = 'api.php?action=' + action;
  if (data instanceof FormData) {
    opts.body = data;
  } else if (data && opts.method === 'POST') {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(data);
  } else if (data) {
    url += '&' + new URLSearchParams(data).toString();
  }
  let res;
  try {
    res = await fetch(url, opts);
  } catch (e) {
    if (opts.method === 'POST' && !(data instanceof FormData) && OfflineQueue.canQueue(action)) return OfflineQueue.add(action, data);
    throw Object.assign(new Error('Tidak dapat terhubung ke server. Periksa koneksi jaringan.'), { offline: true });
  }
  const json = await res.json().catch(() => ({ error: 'Respon server tidak valid' }));
  if (res.status === 401 && json.auth && action !== 'login') {
    BOOT = null;
    showLogin();
    throw Object.assign(new Error(json.error), { status: 401, data: json });
  }
  if (!res.ok) throw Object.assign(new Error(json.error || 'Terjadi kesalahan'), { status: res.status, data: json });
  return json;
}
const get = (action, params) => api(action, params || {}, 'GET');

let toastTimer;
function toast(msg, type) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast ' + (type || '');
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (t.hidden = true), 2800);
}

let audioCtx;
function beep(ok) {
  try {
    audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
    const o = audioCtx.createOscillator(), g = audioCtx.createGain();
    o.frequency.value = ok ? 1200 : 300;
    o.type = ok ? 'sine' : 'square';
    g.gain.value = 0.15;
    o.connect(g); g.connect(audioCtx.destination);
    o.start(); o.stop(audioCtx.currentTime + (ok ? 0.12 : 0.35));
  } catch (e) { /* abaikan */ }
  if (navigator.vibrate) navigator.vibrate(ok ? 60 : [100, 60, 100]);
}

function modal(html, onMount) {
  $modal.oncancel = null;
  delete $modal.dataset.keep;
  $modal.innerHTML = html;
  if (!$modal.open) $modal.showModal();
  $modal.querySelectorAll('[data-close]').forEach((b) => (b.onclick = () => $modal.close()));
  if (onMount) onMount($modal);
}

function confirmBox(message, okLabel, danger) {
  return new Promise((resolve) => {
    modal(`<h3>Konfirmasi</h3><p>${message}</p>
      <div class="row end"><button class="btn secondary" data-no>Batal</button><button class="btn ${danger ? 'danger-solid' : ''}" data-yes>${esc(okLabel || 'Ya')}</button></div>`,
    (m) => {
      m.querySelector('[data-no]').onclick = () => { m.close(); resolve(false); };
      m.querySelector('[data-yes]').onclick = () => { m.close(); resolve(true); };
      m.addEventListener('cancel', () => resolve(false), { once: true });
    });
  });
}

function promptQty(title, info, def, max) {
  return new Promise((resolve) => {
    modal(`<h3>${esc(title)}</h3><p class="muted small">${info}</p>
      <form><input type="number" name="q" min="0" ${max !== undefined ? 'max="' + max + '"' : ''} value="${def ?? 1}" inputmode="numeric" required>
      <div class="row end" style="margin-top:12px"><button type="button" class="btn secondary" data-no>Batal</button><button class="btn">OK</button></div></form>`,
    (m) => {
      const f = m.querySelector('form');
      f.q.select();
      m.querySelector('[data-no]').onclick = () => { m.close(); resolve(null); };
      m.addEventListener('cancel', () => resolve(null), { once: true });
      f.onsubmit = (e) => { e.preventDefault(); m.close(); resolve(+f.q.value); };
    });
  });
}

const badge = (text, cls) => `<span class="badge ${cls || ''}">${esc(text)}</span>`;
const STAGE_CLS = { packing: 'open', sealed: 'sealed', loaded: 'info', in_transit: 'info', received: 'none' };
const stageBadge = (stage) => badge((stage === 'sealed' ? '🔒 ' : '') + (BOOT.stages[stage] || stage), STAGE_CLS[stage]);
const SHIP_CLS = { draft: 'open', in_transit: 'info', received: 'sealed', partial: 'err', problem: 'err', resolved: 'sealed' };
const shipBadge = (st) => badge(BOOT.shipment_status[st] || st, SHIP_CLS[st]);
const statusBadge = (s) => s === 'sealed' ? badge('🔒 Disegel', 'sealed') : badge('Terbuka', 'open');
const pathCrumbs = (path) => path.map((p) => `<a href="#/package/${enc(p.code)}">${esc(p.code)}</a>`).join(' › ');
const openPrint = (qs) => window.open('print.php?' + qs, '_blank');
const branchOptions = (selected, opts = {}) => BOOT.branches
  .filter((b) => (+b.active || +b.id === +selected) && +b.id !== +(opts.exclude || 0))
  .map((b) => `<option value="${b.id}" ${+b.id === +selected ? 'selected' : ''}>${esc(b.name)}</option>`).join('');

// ---------- Komponen scanner (kamera + input manual / scanner USB-Bluetooth) ----------
function createScanner(root, onCode, opts) {
  opts = opts || {};
  const id = 'cam' + Date.now();
  // Layar sentuh: keyboard pasif (inputmode=none). Input tetap fokus sehingga scanner USB/Bluetooth
  // bisa mengetik, tetapi keyboard layar tidak muncul menutupi kamera. Tombol ⌨️ untuk ketik manual.
  const touch = window.matchMedia('(pointer: coarse)').matches;
  const placeholder = opts.placeholder || 'Scan / ketik kode lalu Enter';
  root.innerHTML = `
    <div class="scanner">
      <div class="cam" id="${id}"></div>
      <form autocomplete="off">
        <input name="code" placeholder="${esc(placeholder)}" enterkeyhint="go" autocapitalize="characters" ${touch ? 'inputmode="none"' : ''}>
        ${touch ? '<button type="button" class="btn secondary kbd" data-kbd title="Ketik manual">⌨️</button>' : ''}
        <button type="button" class="btn secondary" data-cam title="Kamera">📷</button>
        <button class="btn">OK</button>
      </form>
      <div class="result"></div>
    </div>`;
  const form = root.querySelector('form');
  const input = form.code;
  const camBtn = root.querySelector('[data-cam]');
  const kbdBtn = root.querySelector('[data-kbd]');
  let cam = null, last = '', lastAt = 0, busy = false, manual = false;

  // Mode ketik manual: tampilkan keyboard layar. Mode pasif: keyboard disembunyikan.
  function setManual(on) {
    if (!touch) return;
    manual = on;
    input.blur();
    input.inputMode = on ? 'text' : 'none';
    input.placeholder = on ? 'Ketik kode lalu Enter' : placeholder;
    if (kbdBtn) kbdBtn.classList.toggle('active', on);
    if (on) setTimeout(() => input.focus(), 0);
    else if (!cam) setTimeout(() => input.focus(), 0);
  }
  if (kbdBtn) kbdBtn.onclick = () => setManual(!manual);
  // Mengetuk kolom input = ingin mengetik
  if (touch) input.addEventListener('pointerdown', () => { if (!manual) { setManual(true); } });
  const focusInput = () => {
    if (touch && cam && !manual) return input.blur(); // kamera aktif: jangan munculkan apa pun
    input.focus();
  };

  async function handle(code) {
    code = code.trim();
    if (!code || busy) return;
    const now = Date.now();
    if (code === last && now - lastAt < 2500) return; // cegah scan ganda dari kamera
    last = code; lastAt = now; busy = true;
    try { await onCode(code); } finally { busy = false; }
  }

  form.onsubmit = (e) => {
    e.preventDefault();
    const v = input.value;
    input.value = '';
    last = '';
    handle(v);
    focusInput();
  };

  async function startCam() {
    if (!window.isSecureContext) {
      toast('Kamera butuh HTTPS. Gunakan input/scanner USB, atau akses lewat https://', 'warn');
      return;
    }
    if (typeof Html5Qrcode === 'undefined') return toast('Library scanner gagal dimuat', 'err');
    cam = new Html5Qrcode(id, { verbose: false });
    try {
      await cam.start({ facingMode: 'environment' }, { fps: 10, qrbox: (w, h) => { const s = Math.floor(Math.min(w, h) * 0.7); return { width: s, height: s }; } },
        (text) => handle(text), () => {});
      camBtn.textContent = '✖';
      localStorage.setItem('camOn', '1');
      if (touch) setManual(false);
      input.blur();
    } catch (e) {
      cam = null;
      toast('Tidak dapat membuka kamera: ' + (e.message || e), 'err');
    }
  }
  async function stopCam() {
    if (cam) {
      try { await cam.stop(); cam.clear(); } catch (e) { /* abaikan */ }
      cam = null;
    }
    camBtn.textContent = '📷';
  }
  camBtn.onclick = () => {
    if (cam) { stopCam().then(focusInput); localStorage.setItem('camOn', '0'); } else startCam();
  };

  const scanner = {
    stop: stopCam,
    focus: focusInput,
    result(msg, type) { root.querySelector('.result').innerHTML = msg ? `<div class="scan-result ${type}">${msg}</div>` : ''; },
  };
  activeScanner = scanner;
  const autoCam = localStorage.getItem('camOn') === '1' && window.isSecureContext;
  if (autoCam) startCam();
  else setTimeout(focusInput, 50);
  return scanner;
}

function stopScanner() {
  if (activeScanner) { activeScanner.stop(); activeScanner = null; }
}

// ---------- Foto bukti ----------
function pickImage() {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.capture = 'environment';
    input.onchange = () => resolve(input.files[0] || null);
    input.click();
  });
}

async function resizeImage(file, max = 1600, quality = 0.82) {
  try {
    const img = await createImageBitmap(file);
    const scale = Math.min(1, max / Math.max(img.width, img.height));
    const c = document.createElement('canvas');
    c.width = Math.round(img.width * scale);
    c.height = Math.round(img.height * scale);
    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
    return await new Promise((r) => c.toBlob(r, 'image/jpeg', quality));
  } catch (e) {
    return file; // browser lama: unggah apa adanya
  }
}

async function takePhoto(refType, refCode, caption) {
  const file = await pickImage();
  if (!file) return false;
  const blob = await resizeImage(file);
  const fd = new FormData();
  fd.append('ref_type', refType);
  fd.append('ref_code', refCode);
  fd.append('caption', caption || '');
  fd.append('file', blob, 'foto.jpg');
  try {
    await api('photo_upload', fd);
    toast('Foto tersimpan', 'ok');
    return true;
  } catch (e) {
    toast(e.message, 'err');
    return false;
  }
}

async function photoGallery(el, refType, refCode, opts = {}) {
  if (!el) return;
  const load = async () => {
    let photos = [];
    try { photos = (await get('photos', { ref_type: refType, ref_code: refCode, prefix: opts.prefix ? 1 : '' })).photos; } catch (e) { /* abaikan */ }
    el.innerHTML = `<div class="gallery">
      ${photos.map((p) => `<figure data-id="${p.id}" data-url="${esc(p.url)}" data-cap="${esc(p.caption || '')}" data-meta="${esc((p.user_name || '') + ' · ' + fmtDate(p.created_at))}"><img src="${esc(p.url)}" loading="lazy" alt="">${p.caption ? `<figcaption>${esc(p.caption)}</figcaption>` : ''}</figure>`).join('')}
      ${opts.canAdd !== false ? '<button class="add-photo" type="button">📷<span>Tambah foto</span></button>' : ''}
      ${!photos.length && opts.canAdd === false ? '<span class="muted small">Belum ada foto</span>' : ''}
    </div>`;
    const add = el.querySelector('.add-photo');
    if (add) add.onclick = async () => { if (await takePhoto(refType, refCode, opts.caption)) load(); };
    el.querySelectorAll('figure').forEach((f) => (f.onclick = () => {
      modal(`<img src="${esc(f.dataset.url)}" style="width:100%;border-radius:8px">
        <p class="small">${esc(f.dataset.cap)}<br><span class="muted">${esc(f.dataset.meta)}</span></p>
        <div class="row between"><button class="btn danger sm" data-del>Hapus foto</button><button class="btn secondary" data-close>Tutup</button></div>`,
      (m) => (m.querySelector('[data-del]').onclick = async () => {
        if (!(await confirmBox('Hapus foto ini?', 'Hapus', true))) return;
        try { await api('photo_delete', { id: f.dataset.id }); load(); } catch (e) { toast(e.message, 'err'); }
      }));
    }));
  };
  load();
}

// ---------- Tanda tangan ----------
function signaturePad(canvas) {
  const ctx = canvas.getContext('2d');
  let drawing = false, empty = true, lastPt = null;
  const resize = () => {
    const r = canvas.getBoundingClientRect();
    canvas.width = r.width * devicePixelRatio;
    canvas.height = r.height * devicePixelRatio;
    ctx.scale(devicePixelRatio, devicePixelRatio);
    ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, r.width, r.height);
    empty = true;
  };
  const pt = (e) => { const r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
  canvas.addEventListener('pointerdown', (e) => { drawing = true; lastPt = pt(e); canvas.setPointerCapture(e.pointerId); });
  canvas.addEventListener('pointermove', (e) => {
    if (!drawing) return;
    const p = pt(e);
    ctx.beginPath(); ctx.moveTo(lastPt.x, lastPt.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    lastPt = p; empty = false;
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach((ev) => canvas.addEventListener(ev, () => (drawing = false)));
  setTimeout(resize, 30);
  return { clear: resize, isEmpty: () => empty, toDataURL: () => canvas.toDataURL('image/png') };
}

// ---------- Dialog cetak (browser A4/thermal + printer Bluetooth) ----------
/* docs: [{ label, mode, code, bt: async (paper) => Uint8Array | null }] */
function printDialog(title, docs) {
  const s = BtPrint.settings();
  modal(`<h3>Cetak — ${esc(title)}</h3>
    <p class="muted small" style="margin:0 0 6px">Printer thermal Bluetooth (dari HP):</p>
    <div class="actions" style="margin-bottom:6px">${docs.filter((d) => d.bt).map((d, i) => `<button class="btn" data-bt="${i}">🧾 ${esc(d.label)}</button>`).join('')}</div>
    <details style="margin-bottom:12px"><summary class="small muted">Pengaturan printer Bluetooth</summary>
      <label class="f" style="margin-top:8px">Metode koneksi<select id="btMethod">
        <option value="rawbt" ${s.method === 'rawbt' ? 'selected' : ''}>Aplikasi RawBT (Bluetooth classic/BLE)</option>
        <option value="ble" ${s.method === 'ble' ? 'selected' : ''} ${BtPrint.bleAvailable() ? '' : 'disabled'}>Web Bluetooth langsung (BLE, butuh HTTPS)</option>
      </select></label>
      <label class="f">Lebar kertas<select id="btPaper"><option value="80" ${s.paper === 80 ? 'selected' : ''}>80 mm</option><option value="58" ${s.paper === 58 ? 'selected' : ''}>58 mm</option></select></label>
      <div id="bleRow" class="row" ${s.method === 'ble' ? '' : 'hidden'}>
        <span class="small grow">Printer: <b>${esc(localStorage.getItem('btDeviceName') || 'belum dipilih')}</b></span>
        <button class="btn secondary sm" id="btPick">Pilih printer</button>
      </div>
      <p class="muted small" id="rawbtHint" ${s.method === 'rawbt' ? '' : 'hidden'}>Pasang aplikasi <b>RawBT</b> dari Play Store, lalu di RawBT pilih printer Bluetooth yang sudah di-pair.</p>
    </details>
    <p class="muted small" style="margin:0 0 6px">Printer biasa (dialog cetak browser):</p>
    <div class="print-grid">${docs.map((d) => `<span class="small">${esc(d.label)}</span>
      <button class="btn secondary sm" data-mode="${d.mode}" data-code="${esc(d.code)}" data-f="a4">📄 A4</button>
      <button class="btn secondary sm" data-mode="${d.mode}" data-code="${esc(d.code)}" data-f="thermal">🧾 80 mm</button>`).join('')}</div>
    <button class="btn secondary block" style="margin-top:12px" data-close>Tutup</button>`,
  (m) => {
    m.querySelectorAll('[data-f]').forEach((b) => (b.onclick = () => openPrint(`mode=${b.dataset.mode}&code=${enc(b.dataset.code)}&format=${b.dataset.f}`)));
    m.querySelector('#btMethod').onchange = (e) => {
      localStorage.setItem('btMethod', e.target.value);
      m.querySelector('#bleRow').hidden = e.target.value !== 'ble';
      m.querySelector('#rawbtHint').hidden = e.target.value !== 'rawbt';
    };
    m.querySelector('#btPaper').onchange = (e) => localStorage.setItem('btPaper', e.target.value);
    m.querySelector('#btPick').onclick = async () => {
      try {
        await BtPrint.connectBle(true);
        m.querySelector('#bleRow b').textContent = localStorage.getItem('btDeviceName');
        toast('Printer terhubung', 'ok');
      } catch (e) { if (e.name !== 'NotFoundError') toast(e.message, 'err'); }
    };
    const btDocs = docs.filter((d) => d.bt);
    m.querySelectorAll('[data-bt]').forEach((b) => (b.onclick = async () => {
      b.disabled = true;
      try {
        const cfg = BtPrint.settings();
        if (cfg.method === 'ble') await BtPrint.connectBle(false); // sambungkan selagi masih dalam aksi klik
        const bytes = await btDocs[+b.dataset.bt].bt(cfg.paper);
        if (bytes) {
          await BtPrint.print(bytes);
          if (cfg.method === 'ble') toast('Terkirim ke printer', 'ok');
        }
      } catch (e) {
        if (e.name !== 'NotFoundError') toast('Gagal cetak: ' + e.message, 'err');
      } finally { b.disabled = false; }
    }));
  });
}

// ---------- Login ----------
function showLogin(message) {
  stopScanner();
  document.body.classList.add('logged-out');
  if ($modal.open) { delete $modal.dataset.keep; $modal.close(); }
  $view.innerHTML = `
    <div class="login card">
      <div style="text-align:center;font-size:42px">📦</div>
      <h2 style="text-align:center">Packing List Gudang</h2>
      ${message ? `<div class="scan-result warn">${esc(message)}</div>` : ''}
      <form autocomplete="on">
        <label class="f">Username<input name="username" autocomplete="username" autocapitalize="none" required></label>
        <label class="f">Password<input name="password" type="password" autocomplete="current-password" required></label>
        <button class="btn block">Masuk</button>
      </form>
    </div>`;
  const f = $view.querySelector('form');
  f.username.focus();
  f.onsubmit = async (e) => {
    e.preventDefault();
    const btn = f.querySelector('button');
    btn.disabled = true;
    try {
      BOOT = await api('login', Object.fromEntries(new FormData(f)));
      afterLogin();
    } catch (err) {
      toast(err.message, 'err');
      btn.disabled = false;
    }
  };
}

function afterLogin() {
  document.body.classList.remove('logged-out');
  renderNav();
  if (+me().must_change) {
    changePasswordDialog(true);
  }
  route();
  OfflineQueue.sync();
}

function changePasswordDialog(forced) {
  modal(`<h3>${forced ? 'Ganti Password Awal' : 'Ganti Password'}</h3>
    ${forced ? '<p class="muted small">Demi keamanan, ganti password bawaan sebelum memakai aplikasi.</p>' : ''}
    <form>
      <label class="f">Password lama<input type="password" name="old_password" required autocomplete="current-password"></label>
      <label class="f">Password baru (min. 6 karakter)<input type="password" name="new_password" required minlength="6" autocomplete="new-password"></label>
      <label class="f">Ulangi password baru<input type="password" name="confirm" required minlength="6" autocomplete="new-password"></label>
      <div class="row end">${forced ? '' : '<button type="button" class="btn secondary" data-close>Batal</button>'}<button class="btn">Simpan</button></div>
    </form>`,
  (m) => {
    if (forced) {
      // Tidak bisa ditutup (Esc / pindah halaman) sebelum password diganti
      m.dataset.keep = '1';
      m.oncancel = (e) => e.preventDefault();
    }
    const f = m.querySelector('form');
    f.onsubmit = async (e) => {
      e.preventDefault();
      if (f.new_password.value !== f.confirm.value) return toast('Ulangi password tidak sama', 'err');
      try {
        await api('change_password', { old_password: f.old_password.value, new_password: f.new_password.value });
        me().must_change = 0;
        delete m.dataset.keep;
        m.oncancel = null;
        m.close();
        toast('Password diganti', 'ok');
      } catch (err) { toast(err.message, 'err'); }
    };
  });
}

/** Koordinator / admin: pilih gudang tempat bekerja saat ini. */
function workBranchDialog() {
  modal(`<h3>📍 Lokasi Kerja</h3>
    <p class="muted small">Pilih gudang / cabang tempat Anda bekerja sekarang. Paket baru, surat jalan, dan angka di Beranda mengikuti lokasi ini.</p>
    <ul class="list">${BOOT.branches.filter((b) => +b.active).map((b) => `<li class="click" data-b="${b.id}"><span class="ico">${+b.id === +workBranch() ? '✅' : (+b.is_hq ? '🏭' : '🏢')}</span>
      <div class="grow"><b>${esc(b.name)}</b><div class="muted small">${esc(b.code)}${b.address ? ' · ' + esc(b.address) : ''}</div></div></li>`).join('')}</ul>
    <button class="btn secondary block" data-close>Tutup</button>`,
  (m) => m.querySelectorAll('[data-b]').forEach((li) => (li.onclick = async () => {
    try {
      BOOT = await api('set_work_branch', { branch_id: li.dataset.b });
      m.close();
      renderNav();
      toast('Lokasi kerja: ' + workBranchName(), 'ok');
      route();
    } catch (e) { toast(e.message, 'err'); }
  })));
}

async function logout() {
  if (OfflineQueue.count() && !(await confirmBox(`Masih ada ${OfflineQueue.count()} scan offline yang belum terkirim. Tetap keluar?`, 'Keluar', true))) return;
  await api('logout', {}).catch(() => {});
  BOOT = null;
  showLogin();
}

// ---------- Navigasi ----------
function renderNav() {
  const tabs = [
    ['', '🏠', 'Beranda', 'home'],
    ['cek', '🔍', 'Cek', 'cek'],
    ['packages', '📦', 'Paket', 'packages'],
    ['shipments', '🚚', role() === 'cabang' ? 'Terima' : 'Kirim', 'shipments'],
    ['menu', '☰', 'Menu', 'menu'],
  ];
  document.querySelector('.tabbar').innerHTML = tabs.map(([h, i, l, t]) => `<a href="#/${h}" data-tab="${t}"><span>${i}</span>${l}</a>`).join('');
  const who = document.getElementById('who');
  who.textContent = me() ? me().name + ' · ' + (isMulti() ? '📍 ' : '') + workBranchName() : '';
  who.classList.toggle('switchable', isMulti());
  who.onclick = isMulti() ? () => workBranchDialog() : null;
}

const TAB_OF = { '': 'home', cek: 'cek', scan: 'cek', packages: 'packages', package: 'packages', shipments: 'shipments', shipment: 'shipments', receive: 'shipments' };

async function route() {
  stopScanner();
  if ($modal.open && !$modal.dataset.keep) $modal.close();
  if (!BOOT) return;
  const hash = location.hash.replace(/^#\/?/, '').split('?')[0];
  const [page, ...rest] = hash.split('/');
  const arg = decodeURIComponent(rest.join('/'));
  const tab = TAB_OF[page] || (page ? 'menu' : 'home');
  document.querySelectorAll('.tabbar a').forEach((a) => a.classList.toggle('active', a.dataset.tab === tab));
  window.scrollTo(0, 0);
  const view = ROUTES[page === 'scan' ? 'cek' : page] || ROUTES[''];
  try {
    await view(arg);
  } catch (e) {
    if (e.status === 401) return;
    $view.innerHTML = `<div class="card empty">${esc(e.message)}<br><br><button class="btn" onclick="route()">Coba lagi</button></div>`;
  }
}

// ---------- Beranda ----------
ROUTES[''] = async function viewHome() {
  const s = await get('stats');
  const u = me();
  const tile = (n, label, href, cls) => `<a class="stat ${cls || ''}" href="${href}"><b>${n}</b><span>${label}</span></a>`;
  let incoming = [];
  if (s.ship_incoming) incoming = (await get('shipments', { status: 'in_transit', incoming: 1 })).shipments;
  $view.innerHTML = `
    <h2>Halo, ${esc(u.name)}</h2>
    <p class="muted small" style="margin-top:-8px">${esc(BOOT.roles[u.role])} · ${isMulti() ? `📍 Lokasi kerja: <b>${esc(workBranchName())}</b> <a href="#" id="chWork">Ganti</a>` : esc(u.branch_name || '')}</p>
    ${s.default_password ? '<div class="card warn-card">⚠️ Anda masih memakai password bawaan. <a href="#" id="chpw">Ganti sekarang</a></div>' : ''}
    <div id="notifCard"></div>
    ${incoming.length ? `<div class="card incoming"><h3 style="margin-top:0">🚚 Pengiriman menuju ${esc(workBranchName())}</h3><ul class="list">${incoming.map((x) => `
      <li class="click" data-href="#/shipment/${enc(x.code)}"><span class="ico">🚚</span><div class="grow"><b class="mono">${esc(x.code)}</b>
      <div class="muted small">dari ${esc(x.origin_name)} · ${x.koli} koli · berangkat ${fmtDate(x.departed_at)}</div></div>${can('cabang', 'gudang') ? '<span class="btn sm">Terima</span>' : ''}</li>`).join('')}</ul></div>` : ''}
    <div class="stats">
      ${tile(s.items_here, 'Barang di ' + esc(workBranchName() || 'gudang'), '#/items')}
      ${tile(s.items_unpacked, 'Belum dipacking', '#/items?unpacked')}
      ${tile(s.pkg_packing, 'Paket sedang dipacking', '#/packages?open')}
      ${tile(s.pkg_ready, 'Paket siap kirim (disegel)', '#/packages?sealed')}
      ${tile(s.ship_draft, 'Surat jalan persiapan', '#/shipments?draft')}
      ${tile(s.ship_outgoing, 'Dalam perjalanan (keluar)', '#/shipments?in_transit')}
      ${tile(s.ship_incoming, 'Menuju cabang ini', '#/shipments?incoming', s.ship_incoming ? 'hl' : '')}
      ${tile(s.disc_open, 'Selisih belum ditindaklanjuti', '#/followup', s.disc_open ? 'bad' : '')}
      ${s.items_lowstock ? tile(s.items_lowstock, 'Stok di bawah minimum', '#/items?lowstock', 'bad') : ''}
      ${s.items_missing ? tile(s.items_missing, 'Barang hilang', '#/items?hilang', 'bad') : ''}
    </div>
    <div class="actions">
      ${can('gudang', 'cabang') ? '<button class="btn" id="newPkg">➕ Paket Baru</button><button class="btn" id="newShip">🚚 Surat Jalan Baru</button>' : ''}
      <a class="btn secondary" href="#/cek">🔍 Cek Kode / Paket</a>
    </div>
    <div class="card">
      <h3 style="margin-top:0">Aktivitas Terakhir</h3>
      ${s.activity.length ? `<ul class="list">${s.activity.map((a) => `
        <li><div class="grow"><div>${esc(a.message)}</div><div class="muted small">${fmtDate(a.created_at)}${a.user_name ? ' · ' + esc(a.user_name) : ''}</div></div></li>`).join('')}</ul>`
        : '<div class="empty">Belum ada aktivitas</div>'}
    </div>
    <p class="muted small center" id="ver"></p>`;
  bindList($view);
  const on = (id, fn) => { const el = document.getElementById(id); if (el) el.onclick = fn; };
  on('newPkg', () => newPackageDialog());
  on('newShip', () => newShipmentDialog());
  on('chpw', (e) => { e.preventDefault(); changePasswordDialog(); });
  on('chWork', (e) => { e.preventDefault(); workBranchDialog(); });

  if (window.Notification && Notification.permission === 'default' && window.isSecureContext) {
    const nc = document.getElementById('notifCard');
    nc.innerHTML = `<div class="card row between"><span class="small grow">🔔 Aktifkan notifikasi agar mendapat pemberitahuan saat ada update sistem.</span><button class="btn sm" id="notifOn">Aktifkan</button></div>`;
    document.getElementById('notifOn').onclick = async () => {
      const p = await Notification.requestPermission();
      nc.innerHTML = '';
      toast(p === 'granted' ? 'Notifikasi diaktifkan' : 'Notifikasi tidak diizinkan', p === 'granted' ? 'ok' : 'warn');
    };
  }
  const v = await appVersion();
  const ver = document.getElementById('ver');
  if (v && ver) ver.textContent = 'Versi aplikasi ' + v;
};

// ---------- Menu ----------
ROUTES.menu = async function viewMenu() {
  const items = [
    ['#/items', '🗂️', 'Database Barang', 'Data barang standar: kategori, SN/no. aset, rak, perolehan, stok', true],
    ['#/categories', '🏷️', 'Kategori Barang', 'Master kategori & awalan kode barang', true],
    ['#/followup', '🧾', 'Tindak Lanjut Selisih', 'Barang kurang / rusak / lebih yang harus diselesaikan', true],
    ['#/reports', '📊', 'Laporan', 'Belum diterima, selisih, rekap bulanan, ekspor Excel', true],
    ['#/branches', '🏢', 'Cabang', 'Daftar cabang tujuan pengiriman', can()],
    ['#/users', '👥', 'Pengguna', 'Akun & hak akses', can()],
    ['#/settings', '⚙️', 'Pengaturan & Backup', 'Nama perusahaan, berat volumetrik, backup database', can()],
    ['#/queue', '📶', 'Antrean Offline', OfflineQueue.count() + ' scan menunggu dikirim · ' + OfflineQueue.failed().length + ' gagal', true],
  ];
  $view.innerHTML = `<h2>Menu</h2>
    <div class="card"><ul class="list">${items.filter((x) => x[4]).map(([h, i, t, d]) => `
      <li class="click" data-href="${h}"><span class="ico">${i}</span><div class="grow"><b>${t}</b><div class="muted small">${esc(d)}</div></div><span class="muted">›</span></li>`).join('')}</ul></div>
    <div class="card"><div class="row between"><div><b>${esc(me().name)}</b><div class="muted small">${esc(me().username)} · ${esc(BOOT.roles[me().role])} · ${esc(workBranchName())}</div></div></div>
      <div class="row" style="margin-top:10px">${isMulti() ? '<button class="btn secondary sm" id="chWork">📍 Ganti Lokasi Kerja</button>' : ''}<button class="btn secondary sm" id="chpw">🔑 Ganti Password</button><button class="btn danger sm" id="logout">Keluar</button></div></div>
    <p class="muted small center" id="ver"></p>`;
  bindList($view);
  document.getElementById('chpw').onclick = () => changePasswordDialog();
  document.getElementById('logout').onclick = logout;
  const cw = document.getElementById('chWork');
  if (cw) cw.onclick = () => workBranchDialog();
  const v = await appVersion();
  if (v) document.getElementById('ver').textContent = 'Versi aplikasi ' + v;
};

function bindList(root) {
  root.querySelectorAll('[data-href]').forEach((li) => (li.onclick = (e) => { if (!e.target.closest('button')) location.hash = li.dataset.href; }));
}

/** Parameter setelah "?" di hash, mis. #/items?unpacked */
function hashQuery() {
  const i = location.hash.indexOf('?');
  return i >= 0 ? location.hash.slice(i + 1) : '';
}

// ---------- Notifikasi update aplikasi ----------
let swReg = null, reloading = false;

function showUpdateBanner() {
  if (document.getElementById('updateBar')) return;
  document.body.insertAdjacentHTML('beforeend', `
    <div id="updateBar" class="update-bar">
      <span>🔔 <b>Versi baru tersedia.</b> Perbarui untuk memakai sistem terbaru.</span>
      <span class="row" style="flex-wrap:nowrap"><button class="btn sm" id="updNow">Perbarui</button><button class="btn sm secondary" id="updLater">Nanti</button></span>
    </div>`);
  document.getElementById('updNow').onclick = () => {
    document.getElementById('updNow').disabled = true;
    if (swReg && swReg.waiting) swReg.waiting.postMessage('SKIP_WAITING');
    else location.reload();
  };
  document.getElementById('updLater').onclick = () => document.getElementById('updateBar').remove();
  beep(true);
  if (swReg && window.Notification && Notification.permission === 'granted') {
    swReg.showNotification('Packing List — update tersedia', {
      body: 'Versi baru sistem sudah siap. Ketuk untuk membuka dan memperbarui.',
      icon: 'icons/icon-192.png', badge: 'icons/icon-192.png', tag: 'app-update', renotify: true,
    }).catch(() => {});
  }
}

function watchWorker(w) {
  if (!w) return;
  const check = () => { if (w.state === 'installed' && navigator.serviceWorker.controller) showUpdateBanner(); };
  check();
  w.addEventListener('statechange', check);
}

async function initServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  try {
    swReg = await navigator.serviceWorker.register('sw.php');
  } catch (e) { return; }
  if (swReg.waiting && navigator.serviceWorker.controller) showUpdateBanner();
  watchWorker(swReg.installing);
  swReg.addEventListener('updatefound', () => watchWorker(swReg.installing));
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (reloading) return;
    reloading = true;
    location.reload();
  });
  const check = () => { if (navigator.onLine) swReg.update().catch(() => {}); };
  check();
  setInterval(check, 60 * 1000);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') check(); });
  window.addEventListener('online', check);
}

function appVersion() {
  return new Promise((resolve) => {
    const ctl = navigator.serviceWorker && navigator.serviceWorker.controller;
    if (!ctl) return resolve(null);
    const ch = new MessageChannel();
    ch.port1.onmessage = (e) => resolve(e.data);
    ctl.postMessage('GET_VERSION', [ch.port2]);
    setTimeout(() => resolve(null), 1000);
  });
}

// ---------- Mulai ----------
window.addEventListener('hashchange', route);
const updateNet = () => (document.getElementById('net').hidden = navigator.onLine);
window.addEventListener('online', () => { updateNet(); OfflineQueue.sync(); });
window.addEventListener('offline', updateNet);

document.addEventListener('DOMContentLoaded', async () => {
  updateNet();
  initServiceWorker();
  try {
    BOOT = await get('me');
    afterLogin();
  } catch (e) {
    if (e.status !== 401) {
      $view.innerHTML = `<div class="card empty">${esc(e.message)}<br><br><button class="btn" onclick="location.reload()">Coba lagi</button></div>`;
    }
  }
});
