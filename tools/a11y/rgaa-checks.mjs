// Vérifications RGAA 4.1 automatisables, écrites à la main (aucune bibliothèque tierce).
import { writeFileSync } from 'node:fs';

const BASE = 'http://localhost:8090';
const PAGES = (process.env.PAGES || '').split(',').filter(Boolean);

const CHECKS = `(() => {
  const out = [];
  const add = (rule, el, info) => out.push({ rule, el: el ? (el.outerHTML || '').slice(0, 140) : '', info: info || '' });
  const visible = (el) => { const s = getComputedStyle(el); const r = el.getBoundingClientRect(); return s.display !== 'none' && s.visibility !== 'hidden' && (r.width > 0 || r.height > 0); };
  const name = (el) => {
    const lb = el.getAttribute('aria-labelledby');
    if (lb) return lb.split(/\\s+/).map(id => document.getElementById(id)?.textContent || '').join(' ').trim();
    return (el.getAttribute('aria-label') || el.textContent.trim() || el.getAttribute('title') || el.querySelector('img[alt]')?.getAttribute('alt') || el.querySelector('svg title')?.textContent || el.querySelector('[aria-label]')?.getAttribute('aria-label') || '').trim();
  };
  // 8.3 / 8.5 / 8.6 : langue et titre
  if (!document.documentElement.lang) add('8.3 lang', null, 'html sans lang');
  if (!document.title.trim()) add('8.5 title', null, 'title vide');
  // 1.1 / 1.2 : images
  document.querySelectorAll('img').forEach(img => { if (!img.hasAttribute('alt')) add('1.1 img-alt', img); });
  document.querySelectorAll('svg').forEach(svg => { if (svg.getAttribute('aria-hidden') !== 'true' && !svg.getAttribute('role') && !svg.querySelector('title') && !svg.closest('[aria-hidden="true"]')) add('1.1 svg-decoratif', svg, 'svg sans aria-hidden ni nom'); });
  // 11.1 : étiquettes des champs
  document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea').forEach(f => {
    if (!visible(f) && f.type !== 'checkbox' && f.type !== 'radio') return;
    const ok = (f.id && document.querySelector('label[for="' + CSS.escape(f.id) + '"]')) || f.closest('label') || f.getAttribute('aria-label') || f.getAttribute('aria-labelledby') || f.getAttribute('title');
    if (!ok) add('11.1 champ-sans-etiquette', f);
  });
  // 6.2 / 11.9 : liens et boutons nommés
  document.querySelectorAll('a[href]').forEach(a => { if (visible(a) && !name(a)) add('6.2 lien-sans-nom', a); });
  document.querySelectorAll('button, [role=button]').forEach(b => { if (visible(b) && !name(b)) add('11.9 bouton-sans-nom', b); });
  // 9.1 : titres (un h1, pas de saut de niveau)
  const hs = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].filter(visible);
  const h1 = hs.filter(h => h.tagName === 'H1').length;
  if (h1 !== 1) add('9.1 h1', null, h1 + ' h1 visibles');
  let prev = 0; hs.forEach(h => { const l = +h.tagName[1]; if (prev && l > prev + 1) add('9.1 saut-de-titre', h, 'h' + prev + ' -> h' + l); prev = l; });
  // 12.6 : zones de repère
  if (!document.querySelector('main, [role=main]')) add('12.6 main', null, 'pas de main');
  // 8.2 : identifiants dupliqués
  const ids = {}; document.querySelectorAll('[id]').forEach(e => { ids[e.id] = (ids[e.id] || 0) + 1; });
  Object.entries(ids).filter(([, n]) => n > 1).forEach(([id, n]) => add('8.2 id-duplique', document.getElementById(id), id + ' x' + n));
  // 3.2 : contraste du texte (fond opaque le plus proche)
  const rgb = (c) => { const m = c.match(/rgba?\\(([^)]+)\\)/); if (!m) return null; const p = m[1].split(',').map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = ({ r, g, b }) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const bgOf = (el) => { for (let e = el; e; e = e.parentElement) { const s = getComputedStyle(e); if (s.backgroundImage !== 'none') return null; const c = rgb(s.backgroundColor); if (c && c.a > 0.95) return c; } return { r: 255, g: 255, b: 255, a: 1 }; };
  const seen = new Set();
  document.querySelectorAll('body *').forEach(el => {
    if (!visible(el) || el.closest('[aria-hidden="true"]')) return;
    const text = [...el.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join('');
    if (!text) return;
    const s = getComputedStyle(el); const fg = rgb(s.color); const bg = bgOf(el);
    if (!fg || !bg || fg.a < 0.95) return;
    const l1 = lum(fg), l2 = lum(bg); const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    const size = parseFloat(s.fontSize); const bold = +s.fontWeight >= 700;
    const need = (size >= 24 || (size >= 18.66 && bold)) ? 3 : 4.5;
    if (ratio < need) { const k = s.color + '/' + s.backgroundColor + '/' + need; if (!seen.has(k)) { seen.add(k); add('3.2 contraste', el, ratio.toFixed(2) + ' < ' + need + ' (' + s.color + ' sur ' + JSON.stringify(bg) + ')'); } }
  });
  return JSON.stringify(out);
})()`;

export default async ({ go, js, size, wait }) => {
  await size(1280, 900);
  const report = {};
  for (const p of PAGES) {
    await go(p.startsWith('http') ? p : BASE + p);
    await wait(2000);
    const found = JSON.parse((await js(CHECKS)) || '[]');
    report[p] = found;
    const byRule = {};
    found.forEach(f => { byRule[f.rule] = (byRule[f.rule] || 0) + 1; });
    console.log(p, Object.keys(byRule).length ? Object.entries(byRule).map(([r, n]) => r + ':' + n).join(' ') : 'OK');
  }
  writeFileSync(new URL('./rapport.json', import.meta.url), JSON.stringify(report, null, 1));
};
