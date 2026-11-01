/* Admin: pengguna, cabang, pengaturan & backup. */
'use strict';

async function refreshBoot() {
  BOOT = await get('me');
  renderNav();
}

ROUTES.users = async function viewUsers() {
  if (!can()) return (location.hash = '#/menu');
  const r = await get('users');
  $view.innerHTML = `<div class="crumbs"><a href="#/menu">Menu</a></div>
    <div class="row between"><h2>Pengguna</h2><button class="btn" id="add">➕ Tambah</button></div>
    <div class="card"><p class="muted small" style="margin-top:0">
      <b>Admin</b>: semua akses · <b>Gudang</b>: barang, packing, surat jalan · <b>Kurir/Sopir</b>: scan muat & berangkat ·
      <b>Penerima Cabang</b>: packing & retur dari cabangnya, menerima kiriman ke cabangnya ·
      <b>Koordinator Gudang</b>: semua pekerjaan gudang (packing, surat jalan, muat, terima, tindak lanjut) di <b>semua lokasi</b>, pindah lokasi kerja kapan saja; tanpa akses pengguna/cabang/pengaturan.</p>
      <ul class="list">${r.users.map((u, n) => `<li class="click" data-n="${n}"><span class="ico">${+u.active ? '👤' : '🚫'}</span>
        <div class="grow"><b>${esc(u.name)}</b> <span class="muted small">@${esc(u.username)}</span>
        <div class="muted small">${esc(BOOT.roles[u.role])} · ${esc(u.branch_name || '-')} · login terakhir ${u.last_login ? fmtDate(u.last_login) : 'belum pernah'}</div></div>
        ${+u.active ? '' : badge('Nonaktif', 'none')}</li>`).join('')}</ul></div>`;
  document.getElementById('add').onclick = () => userDialog();
  $view.querySelectorAll('[data-n]').forEach((li) => (li.onclick = () => userDialog(r.users[li.dataset.n])));
};

function userDialog(u) {
  const x = u || { role: 'gudang', branch_id: BOOT.branches.find((b) => +b.is_hq)?.id, active: 1 };
  modal(`<h3>${u ? 'Edit' : 'Tambah'} Pengguna</h3><form>
    <label class="f">Nama lengkap<input name="name" value="${esc(x.name)}" required></label>
    <label class="f">Username<input name="username" value="${esc(x.username)}" required autocapitalize="none" pattern="[a-zA-Z0-9._\\-]{3,30}"></label>
    <div class="grid2">
      <label class="f">Peran<select name="role">${Object.entries(BOOT.roles).map(([k, l]) => `<option value="${k}" ${x.role === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
      <label class="f">Cabang<select name="branch_id">${branchOptions(x.branch_id)}</select></label>
    </div>
    <label class="f">${u ? 'Reset password (kosongkan jika tidak diubah)' : 'Password awal (min. 6 karakter)'}<input name="password" type="text" autocomplete="off" ${u ? '' : 'required minlength="6"'}></label>
    <p class="muted small">Pengguna diminta mengganti password saat login pertama / setelah direset.</p>
    <label class="small"><input type="checkbox" name="active" ${+x.active ? 'checked' : ''}> Akun aktif</label>
    <div class="row end" style="margin-top:12px"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn">Simpan</button></div>
  </form>`, (m) => {
    const f = m.querySelector('form');
    f.onsubmit = async (e) => {
      e.preventDefault();
      try {
        await api('user_save', { id: x.id || 0, ...Object.fromEntries(new FormData(f)), active: f.active.checked });
        m.close(); toast('Pengguna disimpan', 'ok'); route();
      } catch (err) { toast(err.message, 'err'); }
    };
  });
}

ROUTES.branches = async function viewBranches() {
  if (!can()) return (location.hash = '#/menu');
  const r = await get('branches');
  $view.innerHTML = `<div class="crumbs"><a href="#/menu">Menu</a></div>
    <div class="row between"><h2>Cabang</h2><button class="btn" id="add">➕ Tambah</button></div>
    <div class="card"><ul class="list">${r.branches.map((b, n) => `<li class="click" data-n="${n}"><span class="ico">${+b.is_hq ? '🏭' : '🏢'}</span>
      <div class="grow"><b>${esc(b.name)}</b> <span class="mono small muted">${esc(b.code)}</span>
      <div class="muted small">${esc(b.address || 'alamat belum diisi')}${b.pic_name ? ' · PIC ' + esc(b.pic_name) + ' ' + esc(b.pic_phone || '') : ''}</div></div>
      ${+b.is_hq ? badge('Pusat') : +b.active ? '' : badge('Nonaktif', 'none')}</li>`).join('')}</ul></div>`;
  document.getElementById('add').onclick = () => branchDialog();
  $view.querySelectorAll('[data-n]').forEach((li) => (li.onclick = () => branchDialog(r.branches[li.dataset.n])));
};

function branchDialog(b) {
  const x = b || { active: 1 };
  modal(`<h3>${b ? 'Edit' : 'Tambah'} Cabang</h3><form>
    <div class="grid2"><label class="f">Kode<input name="code" value="${esc(x.code)}" required autocapitalize="characters" placeholder="mis. SBY"></label>
    <label class="f">Nama<input name="name" value="${esc(x.name)}" required placeholder="mis. Cabang Surabaya"></label></div>
    <label class="f">Alamat (dicetak di label & surat jalan)<textarea name="address" rows="2">${esc(x.address)}</textarea></label>
    <div class="grid2"><label class="f">PIC / penerima<input name="pic_name" value="${esc(x.pic_name)}"></label>
    <label class="f">No. HP PIC<input name="pic_phone" inputmode="tel" value="${esc(x.pic_phone)}"></label></div>
    ${+x.is_hq ? '' : `<label class="small"><input type="checkbox" name="active" ${+x.active ? 'checked' : ''}> Cabang aktif</label>`}
    <div class="row end" style="margin-top:12px"><button type="button" class="btn secondary" data-close>Batal</button><button class="btn">Simpan</button></div>
  </form>`, (m) => {
    const f = m.querySelector('form');
    f.onsubmit = async (e) => {
      e.preventDefault();
      try {
        await api('branch_save', { id: x.id || 0, ...Object.fromEntries(new FormData(f)), active: f.active ? f.active.checked : true });
        m.close(); toast('Cabang disimpan', 'ok'); await refreshBoot(); route();
      } catch (err) { toast(err.message, 'err'); }
    };
  });
}

ROUTES.settings = async function viewSettings() {
  if (!can()) return (location.hash = '#/menu');
  const s = BOOT.settings;
  const b = await get('backups');
  $view.innerHTML = `<div class="crumbs"><a href="#/menu">Menu</a></div><h2>Pengaturan & Backup</h2>
    <div class="card"><form id="sf">
      <label class="f">Nama perusahaan (kop dokumen & label)<input name="company_name" value="${esc(s.company_name)}"></label>
      <label class="f">Alamat perusahaan<input name="company_address" value="${esc(s.company_address)}"></label>
      <div class="grid2">
        <label class="f">Pembagi berat volumetrik<select name="vol_divisor">
          ${[[6000, '6000 — udara / umum'], [5000, '5000 — kurir ekspres'], [4000, '4000 — darat / trucking']].map(([v, l]) => `<option value="${v}" ${+s.vol_divisor === v ? 'selected' : ''}>${l}</option>`).join('')}
        </select></label>
        <label class="f">Simpan backup otomatis (hari)<input name="backup_keep" type="number" min="1" max="365" value="${esc(s.backup_keep)}"></label>
      </div>
      <button class="btn">Simpan</button>
    </form></div>
    <div class="card">
      <div class="row between"><h3 style="margin:0">Backup Database</h3><a class="btn sm" href="backup.php">⬇️ Backup sekarang</a></div>
      <p class="muted small">Backup otomatis dibuat setiap hari saat aplikasi pertama kali dipakai. Simpan salinan secara berkala di tempat lain (flashdisk / Google Drive).</p>
      ${b.backups.length ? `<table class="t">${b.backups.map((x) => `<tr><td class="mono small">${esc(x.name)}</td><td class="small">${esc(x.time)}</td><td class="small">${fmtNum(x.size / 1024, 0)} KB</td><td><a class="btn secondary sm" href="backup.php?file=${enc(x.name)}">⬇️</a></td></tr>`).join('')}</table>` : '<div class="empty">Belum ada backup</div>'}
    </div>`;
  document.getElementById('sf').onsubmit = async (e) => {
    e.preventDefault();
    try { await api('settings_save', Object.fromEntries(new FormData(e.target))); await refreshBoot(); toast('Pengaturan disimpan', 'ok'); } catch (err) { toast(err.message, 'err'); }
  };
};
