// Budgets de performance (CDC §8, 75e percentile mobile) mesurés en laboratoire : mobile émulé, processeur ralenti
// ×4 et réseau « 4G lente » (profil Lighthouse : 150 ms d'aller-retour, 1,6 Mbit/s). Usage (Chrome sur l'hôte) :
//   BASE=http://localhost:8091 PAGES="/,/nos-professionnels" node tools/a11y/cdp.mjs tools/perf/web-vitals.mjs
// HTTPS=1 ajoute X-Forwarded-Proto (copie en mode prod derrière un proxy de confiance) ; INSECURE=1 accepte le
// certificat auto-signé d'une copie locale servie en HTTPS (HTTP/2, comme en production).
// INP n'existe qu'avec de vraies interactions : on mesure le temps de blocage (TBT, tâches longues > 50 ms) comme
// indicateur de laboratoire, à confirmer par les mesures terrain (CrUX / RUM) après la mise en ligne.
import { writeFileSync } from 'node:fs';

const BASE = process.env.BASE || 'http://localhost:8091';
const PAGES = (process.env.PAGES || '/').split(',').filter(Boolean);
const RUNS = Number(process.env.RUNS || 3);
export const BUDGETS = { lcp: 2500, cls: 0.1, tbt: 200, ttfb: 600, weight: 1.5 * 1024 * 1024 };

const OBSERVE = `(() => {
  window.__perf = { lcp: 0, lcpEl: '', cls: 0, tbt: 0 };
  new PerformanceObserver(l => l.getEntries().forEach(e => { window.__perf.lcp = e.startTime; const el = e.element; window.__perf.lcpEl = el ? el.tagName + (el.className ? '.' + String(el.className).split(' ')[0] : '') + (e.url ? ' ' + e.url.split('/').pop() : '') : ''; })).observe({ type: 'largest-contentful-paint', buffered: true });
  new PerformanceObserver(l => l.getEntries().forEach(e => { if (!e.hadRecentInput) window.__perf.cls += e.value; })).observe({ type: 'layout-shift', buffered: true });
  new PerformanceObserver(l => l.getEntries().forEach(e => { window.__perf.tbt += Math.max(0, e.duration - 50); })).observe({ type: 'longtask', buffered: true });
})()`;
const COLLECT = `JSON.stringify((() => {
  const nav = performance.getEntriesByType('navigation')[0];
  const res = performance.getEntriesByType('resource');
  const weight = (nav.transferSize || 0) + res.reduce((s, r) => s + (r.transferSize || 0), 0);
  return { ...window.__perf, ttfb: nav.responseStart, weight, requests: res.length + 1 };
})())`;

const median = (xs) => [...xs].sort((a, b) => a - b)[Math.floor(xs.length / 2)];

export default async ({ go, js, size, wait, send }) => {
  await size(412, 823);
  await send('Emulation.setDeviceMetricsOverride', { width: 412, height: 823, deviceScaleFactor: 2.625, mobile: true });
  await send('Emulation.setCPUThrottlingRate', { rate: 4 });
  await send('Network.enable');
  await send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: 1.6 * 1024 * 1024 / 8, uploadThroughput: 750 * 1024 / 8 });
  if (process.env.HTTPS) await send('Network.setExtraHTTPHeaders', { headers: { 'X-Forwarded-Proto': 'https' } });
  // INSECURE=1 : certificat auto-signé de la copie locale en HTTPS / HTTP/2 (jamais pour un site distant).
  if (process.env.INSECURE) await send('Security.setIgnoreCertificateErrors', { ignore: true });
  await send('Page.addScriptToEvaluateOnNewDocument', { source: OBSERVE });
  const report = {};
  for (const p of PAGES) {
    const runs = [];
    for (let i = 0; i < RUNS; ++i) {
      await send('Network.clearBrowserCache');
      await send('Network.clearBrowserCookies');
      await go('about:blank');
      await go(BASE + p);
      const loaded = `(() => { const n = performance.getEntriesByType('navigation')[0]; return !!n && n.loadEventEnd > 0 && location.href !== 'about:blank'; })()`;
      for (let t = 0; t < 120 && !await js(loaded); ++t) await wait(250);
      await wait(2500);
      runs.push(JSON.parse(await js(COLLECT)));
    }
    const m = Object.fromEntries(['lcp', 'cls', 'tbt', 'ttfb', 'weight', 'requests'].map(k => [k, median(runs.map(r => r[k]))]));
    m.lcpEl = runs[0].lcpEl;
    const over = Object.entries(BUDGETS).filter(([k, b]) => m[k] > b).map(([k]) => k);
    report[p] = { ...m, over };
    console.log(p.padEnd(28), `LCP ${Math.round(m.lcp)} ms`, `CLS ${m.cls.toFixed(3)}`, `TBT ${Math.round(m.tbt)} ms`, `TTFB ${Math.round(m.ttfb)} ms`,
      `${Math.round(m.weight / 1024)} Ko / ${m.requests} req`, over.length ? 'HORS BUDGET: ' + over.join(',') : 'OK', '·', m.lcpEl);
  }
  writeFileSync(new URL('./rapport.json', import.meta.url), JSON.stringify(report, null, 1));
};
