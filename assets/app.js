/* Garage – kleine Helfer, ohne Bibliotheken */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  // Sheets (Bottom-Sheet / Dialog)
  function openSheet(id) {
    const s = document.getElementById('sheet-' + id);
    if (!s) return;
    s.hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function closeSheets() {
    $$('.sheet').forEach(s => (s.hidden = true));
    document.body.style.overflow = '';
  }
  document.addEventListener('click', e => {
    const opener = e.target.closest('[data-sheet]');
    if (opener) { e.preventDefault(); openSheet(opener.dataset.sheet); return; }
    if (e.target.closest('[data-close]')) { e.preventDefault(); closeSheets(); return; }
    const row = e.target.closest('tr[data-href]');
    if (row && !e.target.closest('a,button,input')) location.href = row.dataset.href;
    const copy = e.target.closest('[data-copy]');
    if (copy) {
      navigator.clipboard?.writeText(copy.dataset.copy).then(() => { copy.classList.add('on'); setTimeout(() => copy.classList.remove('on'), 1200); });
    }
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeSheets(); closeMenus(); } });

  // Mini-Menüs in Listen (details.li-menu): nur eins offen, Klick daneben schließt
  function closeMenus(except) { $$('details.li-menu[open]').forEach(d => d !== except && (d.open = false)); }
  document.addEventListener('click', e => closeMenus(e.target.closest('details.li-menu')));

  // Schnell-Eintrag: Fahrzeugauswahl setzt Ziel-Links
  const qa = $('#qa-vehicles');
  function updateQa() {
    const on = $('.seg-btn.on', qa);
    if (!on) return;
    const vid = on.dataset.vid, motor = on.dataset.motor === '1';
    $$('.qa-item').forEach(a => {
      a.href = a.dataset.href.replace('__V__', vid);
      a.classList.toggle('disabled', a.hasAttribute('data-motor-only') && !motor);
    });
  }
  if (qa) {
    qa.addEventListener('click', e => {
      const b = e.target.closest('.seg-btn'); if (!b) return;
      $$('.seg-btn', qa).forEach(x => x.classList.toggle('on', x === b));
      updateQa();
    });
    updateQa();
  }

  // Löschen bestätigen
  document.addEventListener('submit', e => {
    const f = e.target.closest('form[data-confirm]');
    if (f && !confirm(f.dataset.confirm)) e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]:not([data-keep])');
    if (btn && !e.defaultPrevented) setTimeout(() => (btn.disabled = true), 0);
  });

  // Toasts ausblenden
  $$('.toast').forEach((t, i) => setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 400); }, 3800 + i * 400));

  // Topbar-Trennlinie beim Scrollen
  const onScroll = () => document.body.classList.toggle('scrolled', window.scrollY > 4);
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();

  // Theme sofort umschalten (Einstellungen)
  $$('input[name="theme"]').forEach(r => r.addEventListener('change', () => { document.documentElement.dataset.theme = r.value; }));

  // Tankformular: Gesamtpreis / Literpreis gegenseitig berechnen
  const parse = v => { v = (v || '').trim().replace(/\s|€/g, ''); if (v.includes(',')) v = v.replace(/\./g, '').replace(',', '.'); const n = parseFloat(v); return isNaN(n) ? null : n; };
  const fmt = (n, d) => n.toFixed(d).replace('.', ',');
  const fuelForm = $('form[data-fuel]');
  if (fuelForm) {
    const q = $('[name="quantity"]', fuelForm), t = $('[name="total"]', fuelForm), p = $('[name="ppu"]', fuelForm);
    let last = 'total';
    t.addEventListener('input', () => (last = 'total'));
    p.addEventListener('input', () => (last = 'ppu'));
    const calc = () => {
      const qq = parse(q.value), tt = parse(t.value), pp = parse(p.value);
      if (!qq) return;
      if (last === 'ppu' && pp) t.value = fmt(qq * pp, 2);
      else if (tt) p.value = fmt(tt / qq, 3);
    };
    [q, t, p].forEach(el => el.addEventListener('change', calc));
  }

  // Frist-Vorlagen: Titel & Intervall vorbelegen
  const kind = $('#reminder-kind');
  if (kind) {
    kind.addEventListener('change', () => {
      const o = kind.selectedOptions[0];
      const title = $('[name="title"]'), im = $('[name="interval_months"]'), ik = $('[name="interval_km"]');
      if (title && (!title.value || title.dataset.auto === '1')) { title.value = o.textContent; title.dataset.auto = '1'; }
      if (im && o.dataset.months !== undefined && !im.value) im.value = o.dataset.months;
      if (ik && o.dataset.km !== undefined && !ik.value) ik.value = o.dataset.km;
    });
    $('[name="title"]')?.addEventListener('input', e => (e.target.dataset.auto = '0'));
  }

  // Teile-Kategorie: Laufleistung vorschlagen
  const pc = $('#part-cat');
  if (pc) pc.addEventListener('change', () => {
    const l = $('[name="limit_km"]'); const km = pc.selectedOptions[0].dataset.km;
    if (l && !l.value && km) l.value = km;
  });

  // Fahrzeugtyp: Motor-Felder ein-/ausblenden
  const vt = $('#vehicle-type');
  if (vt) {
    const upd = () => {
      const motor = ['auto', 'motorrad'].includes(vt.value);
      $$('[data-motor]').forEach(el => (el.style.display = motor ? '' : 'none'));
      $$('[data-bike]').forEach(el => (el.style.display = motor ? 'none' : ''));
    };
    vt.addEventListener('change', upd); upd();
  }

  // Service Worker für App-Installation & schnellen Start
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    navigator.serviceWorker.register((document.querySelector('link[rel=manifest]')?.href || '').replace('manifest.webmanifest', 'sw.js')).catch(() => {});
  }
})();
