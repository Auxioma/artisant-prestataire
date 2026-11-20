// Aide à l'audit manuel RGAA 4.1 (T-903b) : parcours clavier réel (touche Tab) avec focus visible (10.7) et lien
// d'évitement en premier (12.7), reformatage à 320 px
// (10.11) et zoom 200 % approché par une fenêtre de 640 px (10.4). Usage :
//   PAGES="/,/connexion" node tools/a11y/cdp.mjs tools/a11y/rgaa-assist.mjs
import { writeFileSync } from 'node:fs';

const BASE = process.env.BASE || 'http://localhost:8090';
const PAGES = (process.env.PAGES || '').split(',').filter(Boolean);

// Instantané du style de l'élément actif (indicateur de focus) ; l'élément est gardé pour être comparé une fois quitté.
const SNAP = `(() => {
  // L'indicateur peut être porté par le champ ou par son conteneur (:focus-within) : on compare les trois niveaux.
  window.__look = window.__look || ((el) => [el, el.parentElement, el.parentElement?.parentElement].filter(Boolean).map(e => {
    const s = getComputedStyle(e);
    return [s.outlineStyle, s.outlineWidth, s.outlineColor, s.boxShadow, s.backgroundColor, s.borderColor, s.textDecorationLine, s.color].join('|');
  }).join('#'));
  const el = document.activeElement;
  if (!el || el === document.body) return JSON.stringify(null);
  const look = window.__look(el);
  window.__probe = window.__probe || [];
  window.__probe.push({ el, look });
  return JSON.stringify({ i: window.__probe.length - 1, html: el.outerHTML.slice(0, 140), skip: el.matches('.tm-skip-link, [href="#contenu"]') });
})()`;
// Compare chaque élément visité à son aspect une fois le focus parti : identique = focus invisible (10.7).
const COMPARE = `(() => JSON.stringify((window.__probe || []).filter(p => {
  if (document.activeElement === p.el) return false;
  return window.__look(p.el) === p.look && p.el.isConnected;
}).map(p => p.el.outerHTML.slice(0, 140))))()`;
const STEPS = Number(process.env.STEPS || 40);

export default async ({ go, js, size, wait, press }) => {
  const report = {};
  for (const p of PAGES) {
    const url = p.startsWith('http') ? p : BASE + p;
    const entry = {};
    await size(1280, 900); await go(url); await wait(1500);
    await js(`document.querySelector('.tm-cmp')?.remove(); 0`);
    entry.issues = [];
    let first = null;
    for (let n = 0; n < STEPS; ++n) {
      await press('Tab'); await wait(60);
      const snap = JSON.parse((await js(SNAP)) || 'null');
      if (0 === n) first = snap;
    }
    await press('Tab'); await wait(60);
    if (!first || !first.skip) entry.issues.push({ rule: '12.7 evitement', el: first ? first.html : 'aucun focus' });
    JSON.parse((await js(COMPARE)) || '[]').forEach(html => entry.issues.push({ rule: '10.7 focus-invisible', el: html }));
    entry.focusables = STEPS;
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
