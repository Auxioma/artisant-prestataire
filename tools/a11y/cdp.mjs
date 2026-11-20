// Pilote Chrome headless minimal (CDP) : node cdp.mjs ./scenario.mjs — scénario : export default async ({go, js, shot, size, wait}) => {}
import { spawn } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const PORT = 9333;
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
  '--headless=new', `--remote-debugging-port=${PORT}`, '--no-first-run', '--no-default-browser-check',
  '--disable-gpu', '--hide-scrollbars', `--user-data-dir=${mkdtempSync(join(tmpdir(), 'cdp-'))}`, 'about:blank',
], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let target;
for (let i = 0; i < 50 && !target; i++) {
  try { target = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()).find((t) => t.type === 'page'); } catch { await sleep(200); }
}
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let id = 0; const pending = new Map(); const errors = [];
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
  if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.text + ' ' + (m.params.exceptionDetails.exception?.description ?? ''));
});
const send = (method, params = {}) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
await send('Page.enable'); await send('Runtime.enable');
let width = 1440, height = 900;
const api = {
  wait: sleep,
  size: async (w, h) => { width = w; height = h; await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 600 }); },
  go: async (url) => { await send('Page.navigate', { url }); await sleep(300); },
  js: async (expr) => (await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })).result?.result?.value,
  shot: async (name, full = false) => {
    const p = { format: 'png' };
    if (full) { const h = await api.js('document.documentElement.scrollHeight'); p.clip = { x: 0, y: 0, width, height: h, scale: 1 }; p.captureBeyondViewport = true; }
    const r = await send('Page.captureScreenshot', p);
    writeFileSync(join(here, name + '.png'), Buffer.from(r.result.data, 'base64'));
  },
};
try {
  const scenario = (await import(pathToFileURL(resolve(process.argv[2])).href)).default;
  await scenario(api);
} finally {
  console.log('JS errors:', JSON.stringify(errors));
  ws.close(); chrome.kill();
}
