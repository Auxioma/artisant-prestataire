// Aide à l'audit manuel RGAA 4.1 (T-903b) : focus visible (10.7), lien d'évitement (12.7), reformatage à 320 px
// (10.11) et zoom 200 % approché par une fenêtre de 640 px (10.4). Usage :
//   PAGES="/,/connexion" node tools/a11y/cdp.mjs tools/a11y/rgaa-assist.mjs
import { writeFileSync } from 'node:fs';

const BASE = process.env.BASE || 'http://localhost:8090';
const PAGES = (process.env.PAGES || '').split(',').filter(Boolean);

const FOCUS = `(() => {
  const out = [];
  const focusables = [...document.querySelectorAll('a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])')]
    .filter(el => { const s = getComputedStyle(el); const r = el.getBoundingClientRect(); return s.visibility !== 'hidden' && s.display !== 'none' && (r.width > 0 || r.height > 0) && !el.closest('[hidden], [inert], dialog:not([open])'); });
  const look = (el) => { const s = getComputedStyle(el); return [s.outlineStyle, s.outlineWidth, s.outlineColor, s.boxShadow, s.backgroundColor, s.borderColor, s.textDecorationLine, s.color].join('|'); };
  const first = document.querySelector('a[href], button, input, select, textarea');
  if (!first || !first.matches('.tm-skip-link, [href="#contenu"], [href="#main"]')) out.push({ rule: '12.7 evitement', el: first ? first.outerHTML.slice(0, 120) : '' });
  focusables.forEach(el => {
    document.activeElement?.blur?.();
    const before = look(el);
    el.focus({ preventScroll: true });
    if (document.activeElement !== el) return;
    const after = look(el);
    const s = getComputedStyle(el);
    const ring = (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) || s.boxShadow !== 'none';
    if (before === after && !ring) out.push({ rule: '10.7 focus-invisible', el: el.outerHTML.slice(0, 140) });
  });
  return JSON.stringify({ focusables: focusables.length, issues: out });
})()`;

export default async ({ go, js, size, wait }) => {
  const report = {};
  for (const p of PAGES) {
    const url = p.startsWith('http') ? p : BASE + p;
    const entry = {};
    await size(1280, 900); await go(url); await wait(1500);
    await js(`document.querySelector('.tm-cmp')?.remove(); 0`);
    const focus = JSON.parse((await js(FOCUS)) || '{"issues":[]}');
    entry.focusables = focus.focusables; entry.issues = focus.issues;
    for (const [w, rule] of [[320, '10.11 reformatage-320'], [640, '10.4 zoom-200']]) {
      await size(w, 900); await go(url); await wait(1200);
      const sw = await js('document.documentElement.scrollWidth');
      if (sw > w + 1) entry.issues.push({ rule, el: 'largeur ' + sw + ' > ' + w });
    }
    report[p] = entry;
    const byRule = {}; entry.issues.forEach(i => { byRule[i.rule] = (byRule[i.rule] || 0) + 1; });
    console.log(p, entry.focusables + ' focusables', Object.keys(byRule).length ? Object.entries(byRule).map(([r, n]) => r + ':' + n).join(' ') : 'OK');
  }
  writeFileSync(new URL('./rapport-assist.json', import.meta.url), JSON.stringify(report, null, 1));
};
