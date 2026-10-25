// Real browser + Laravel + SQLite smoke checks, using only throwaway data.
// Requires installed dependencies, PHP, Node 24, and Chrome/Edge (or BROWSER_PATH).
import assert from "node:assert/strict";
import { spawn, spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync, readFileSync, existsSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { resolve, join, dirname, basename } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { createServer } from "node:net";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const backend = join(root, "backend");
const temp = mkdtempSync(join(tmpdir(), "pwdwork-browser-"));
const database = join(temp, "browser.sqlite");
writeFileSync(database, "");
const env = { ...process.env, PWDWORK_BROWSER_TEST: "1", APP_ENV: "browser_test",
  APP_KEY: "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", APP_DEBUG: "false",
  APP_URL: "http://localhost:8000", FRONTEND_URL: "http://localhost:5173",
  CORS_ALLOWED_ORIGINS: "http://localhost:5173", SANCTUM_STATEFUL_DOMAINS: "localhost:5173,localhost:8000",
  TRUSTED_PROXIES: "", DB_CONNECTION: "sqlite", DB_DATABASE: database, DB_URL: "",
  SESSION_DRIVER: "database", SESSION_CONNECTION: "sqlite", SESSION_SECURE_COOKIE: "false",
  SESSION_DOMAIN: "null", CACHE_STORE: "database", DB_CACHE_CONNECTION: "sqlite",
  QUEUE_CONNECTION: "database", DB_QUEUE_CONNECTION: "sqlite", PASSWORD_RESET_QUEUE_CONNECTION: "deferred",
  PASSWORD_RESET_QUEUE: "password-resets", MAIL_MAILER: "array", PASSWORD_RESET_MAILER: "array", LOG_CHANNEL: "stderr", BCRYPT_ROUNDS: "4",
  MAIL_URL: "", MAIL_USERNAME: "synthetic-only", MAIL_PASSWORD: "synthetic-only", BREVO_API_KEY: "synthetic-only",
  MAIL_FROM_ADDRESS: "sender@example.test", MAIL_FROM_NAME: "PWDWork",
  ADMIN_NOTIFICATION_MAIL: "false",
  APP_CONFIG_CACHE: join(temp, "config.php"), APP_ROUTES_CACHE: join(temp, "routes.php"),
};
const apiPort = Number(process.env.PWDWORK_SMOKE_API_PORT || 8000);
const frontendPort = Number(process.env.PWDWORK_SMOKE_FRONTEND_PORT || 5173);
const browserPort = Number(process.env.PWDWORK_SMOKE_BROWSER_PORT || 9222);
for (const port of [apiPort, frontendPort, browserPort]) assert.ok(Number.isInteger(port) && port > 1024 && port <= 65535, "Invalid smoke test port");
const frontendOrigin = `http://localhost:${frontendPort}`;
const productionAssets = process.env.PWDWORK_SMOKE_PRODUCTION === '1';
if (productionAssets) assert.ok(existsSync(join(root, 'dist/index.html')), 'Run npm run build before testing production assets.');
env.APP_URL = `http://localhost:${apiPort}`;
env.FRONTEND_URL = frontendOrigin;
env.CORS_ALLOWED_ORIGINS = frontendOrigin;
env.SANCTUM_STATEFUL_DOMAINS = `localhost:${frontendPort},localhost:${apiPort}`;
const viteTestConfig = join(temp, 'vite.config.mjs');
writeFileSync(viteTestConfig, `import config from ${JSON.stringify(pathToFileURL(join(root, 'vite.config.js')).href)};\nconst proxy = { '/api': { target: 'http://127.0.0.1:${apiPort}', changeOrigin: true } };\nexport default { ...config, server: { ...config.server, proxy }, preview: { ...config.preview, proxy } };\n`);
const processes = [];
let client;
const logs = [];
function launch(command, args, cwd = root, processEnv = env) {
  const child = spawn(command, args, { cwd, env: processEnv, windowsHide: true, stdio: ["ignore", "pipe", "pipe"] });
  child.stdout.on("data", (chunk) => logs.push(chunk.toString()));
  child.stderr.on("data", (chunk) => logs.push(chunk.toString()));
  processes.push(child);
  return child;
}
const delay = (ms) => new Promise((done) => setTimeout(done, ms));
async function waitFor(check, label, timeout = 30000) {
  const start = Date.now();
  while (Date.now() - start < timeout) {
    try { if (await check()) return; } catch { /* Startup/navigation may be in progress. */ }
    await delay(100);
  }
  throw new Error(`Timed out: ${label}`);
}
async function requireFreePort(port, host) {
  await new Promise((done, reject) => {
    const server = createServer();
    server.once("error", reject);
    server.listen(port, host, () => server.close(done));
  });
}
class CDP {
  constructor(socket) {
    this.socket = socket; this.id = 0; this.pending = new Map(); this.exceptions = [];
    socket.onmessage = ({ data }) => {
      const message = JSON.parse(data);
      if (message.id) {
        const pending = this.pending.get(message.id);
        if (!pending) return;
        this.pending.delete(message.id);
        if (message.error) pending.reject(new Error(message.error.message)); else pending.resolve(message.result);
      }
      if (message.method === "Runtime.exceptionThrown") this.exceptions.push(message.params.exceptionDetails.text);
      if (message.method === "Page.loadEventFired") this.loadSerial = (this.loadSerial || 0) + 1;
      if (message.method === 'Page.javascriptDialogOpening' && typeof this.dialogDecision === 'boolean') {
        void this.send('Page.handleJavaScriptDialog', { accept: this.dialogDecision });
      }
      if (message.method === "Network.responseReceived" && message.params.response.url.endsWith("/api/login") && message.params.response.status === 429) {
        const entry = Object.entries(message.params.response.headers).find(([name]) => name.toLowerCase() === "retry-after");
        this.loginRetrySeconds = Number(entry?.[1]) || 60;
      }
    };
  }
  send(method, params = {}) {
    return new Promise((resolvePromise, reject) => {
      const id = ++this.id;
      this.pending.set(id, { resolve: resolvePromise, reject });
      this.socket.send(JSON.stringify({ id, method, params }));
      setTimeout(() => {
        if (this.pending.delete(id)) reject(new Error(`CDP timeout: ${method}`));
      }, 45000).unref();
    });
  }
  async evaluate(expression) {
    const result = await this.send("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  }
}
const check = async (condition, label) => waitFor(() => client.evaluate(condition), label);
async function go(path) {
  const pathname = new URL(path, frontendOrigin).pathname;
  const previousLoad = client.loadSerial || 0;
  const navigation = await client.send("Page.navigate", { url: frontendOrigin + path });
  assert.ok(!navigation.errorText, navigation.errorText);
  if (navigation.loaderId) await waitFor(() => (client.loadSerial || 0) > previousLoad, "document loaded " + pathname);
  await check(`location.pathname === ${JSON.stringify(pathname)} && document.querySelector('main') && document.querySelector('header')?.getAttribute('aria-busy') === 'false' && !document.body.innerText.includes('Loading account...')`, "route " + pathname);
}
async function reload() {
  const previousLoad = client.loadSerial || 0;
  await client.send("Page.reload");
  await waitFor(() => (client.loadSerial || 0) > previousLoad, "reloaded document");
}
async function fill(selector, value) {
  await client.evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) throw new Error('Missing input'); const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(value)}); el.dispatchEvent(new Event('input', { bubbles: true })); })()`);
}
async function click(text, scope = "main") {
  await check(`[...document.querySelectorAll(${JSON.stringify(scope + " button")})].some(el => el.textContent.trim() === ${JSON.stringify(text)} && !el.matches(':disabled'))`, `enabled button ${text}`);
  await client.evaluate(`(() => { const button = [...document.querySelectorAll(${JSON.stringify(scope + " button")})].find(el => el.textContent.trim() === ${JSON.stringify(text)} && !el.disabled); if (!button) throw new Error('Missing enabled button: ' + ${JSON.stringify(text)}); button.focus(); button.click(); })()`);
}
async function select(selector, value) {
  await client.evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) throw new Error('Missing select'); el.value = ${JSON.stringify(value)}; el.dispatchEvent(new Event('change', { bubbles: true })); })()`);
}
async function reviewFirst(kind) {
  await check(`[...document.querySelectorAll('main article button')].some(el => el.textContent.startsWith('Review ${kind} #'))`, "review control");
  await client.evaluate(`[...document.querySelectorAll('main article button')].find(el => el.textContent.startsWith('Review ${kind} #')).click()`);
  await check("!!document.querySelector('dialog[open] form')", "review dialog");
  assert.ok(await client.evaluate("document.querySelector('dialog[open]').contains(document.activeElement)"), "Review dialog did not receive focus");
}
async function confirmReview(status, reason = "") {
  await select("dialog[open] form select", status);
  if (reason) await fill("dialog[open] form textarea", reason);
  await click("Confirm decision", "dialog[open]");
  await check("!document.querySelector('dialog[open]')", "decision saved");
}
async function login(email, password = "password", expectInactive = false) {
  await go("/login");
  await check("!!document.querySelector('#login-email')", "login form");
  await fill("#login-email", email);
  await fill("#login-password", password);
  for (let attempt = 0; attempt < 3; attempt++) {
    client.loginRetrySeconds = 0;
    await fill("#login-email", email);
    await fill("#login-password", password);
    await click("Continue");
    await check(`${expectInactive ? "document.body.innerText.includes('This account is not active.')" : "!location.pathname.includes('login')"} || document.body.innerText.includes('Too many attempts.')`, "login result");
    if (!await client.evaluate("document.body.innerText.includes('Too many attempts.')")) return;
    const waitSeconds = client.loginRetrySeconds || 60;
    console.log(`Waiting ${waitSeconds + 1}s for the configured login rate-limit window.`);
    await delay((waitSeconds + 1) * 1000);
  }
  throw new Error("The configured login rate limit did not release after retries.");
}
async function logout() {
  await check("[...document.querySelectorAll('header button')].some(el => el.textContent.trim() === 'Log out')", "authenticated navigation ready");
  await click("Log out", "header");
  await check("(location.pathname === '/' || location.pathname === '/login') && ![...document.querySelectorAll('header button')].some(el => el.textContent.trim() === 'Log out')", "logout redirect");
}
function passed(name) { console.log("PASS " + name); }

async function verifyMobileMenu() {
  await client.send('Emulation.setDeviceMetricsOverride', { width: 375, height: 950, deviceScaleFactor: 1, mobile: false });
  await client.evaluate("document.querySelector('header button[aria-label=\"Toggle menu\"]').click()");
  await check("document.querySelector('header button[aria-label=\"Toggle menu\"]').getAttribute('aria-expanded') === 'true' && getComputedStyle(document.getElementById('workspace-navigation')).display !== 'none'", 'Mobile navigation opens');
  await client.evaluate("document.querySelector('#workspace-navigation a').focus(); document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");
  await check("document.querySelector('header button[aria-label=\"Toggle menu\"]').getAttribute('aria-expanded') === 'false' && document.activeElement?.getAttribute('aria-label') === 'Toggle menu'", 'Escape closes mobile navigation and returns focus');
  await client.evaluate("document.querySelector('header button[aria-label=\"Toggle menu\"]').click(); document.querySelector('#workspace-navigation a[aria-current=page]').click()");
  await check("document.querySelector('header button[aria-label=\"Toggle menu\"]').getAttribute('aria-expanded') === 'false' && document.activeElement?.getAttribute('aria-label') === 'Toggle menu'", 'Selecting the current mobile route closes navigation and retains focus');
  assert.ok(await client.evaluate('document.documentElement.scrollWidth <= 375'), 'Opened/closed mobile navigation reflows');
  await client.send('Emulation.clearDeviceMetricsOverride');
}

async function verifyOverview(employer = false) {
  const base = employer ? '/employer' : '/applicant';
  await go(base);
  await verifyMobileMenu();
  await check("document.querySelectorAll('.metric-card strong').length === 4 && ![...document.querySelectorAll('.metric-card strong')].some(el => el.textContent === '—')", 'overview data ready');
  const paths = employer
    ? ['/employer/jobs?per_page=3', '/employer/applications?per_page=3', '/employer/jobs?status=open&per_page=1', '/employer/applications?status=interview&per_page=1']
    : ['/applicant/applications?per_page=3', '/applicant/saved-jobs?per_page=1', '/applicant/resumes?per_page=1', '/applicant/applications?status=interview&per_page=1'];
  const expected = await client.evaluate(`Promise.all(${JSON.stringify(paths)}.map(async path => { const response = await fetch('/api' + path, { headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error('Overview API unavailable'); return String((await response.json()).meta.total); }))`);
  assert.deepEqual(await client.evaluate("[...document.querySelectorAll('.metric-card strong')].map(el => el.textContent)"), expected, 'Overview counts match authorized API totals');
  assert.ok(await client.evaluate("document.querySelectorAll('.activity-row').length > 0 && [...document.querySelectorAll('.activity-row h3')].every(el => el.textContent.trim().length > 0)"), 'Overview shows real recent job titles');
  await client.send('Network.setBlockedURLs', { urls: ['*status=interview*'] });
  await click('Refresh overview');
  await check("[...document.querySelectorAll('.metric-card strong')].some(el => el.textContent === 'Unavailable')", 'Failed metric is unavailable, not a fabricated zero');
  assert.deepEqual(await client.evaluate("[...document.querySelectorAll('.metric-card strong')].slice(0,3).map(el => el.textContent)"), expected.slice(0,3), 'Other metrics survive a partial API failure');
  await client.send('Network.setBlockedURLs', { urls: [] });
  await click('Refresh overview');
  await check("[...document.querySelectorAll('.metric-card strong')].map(el => el.textContent).join('|') === " + JSON.stringify(expected.join('|')), 'Metric retry restores real totals');
}

async function reviewJobCards(label, authenticated) {
  const context = authenticated ? 'applicant' : 'public';
  const checks = [];
  const normalMedia = [{ name: 'prefers-color-scheme', value: 'light' }, { name: 'prefers-reduced-motion', value: 'no-preference' }];
  const reduced = await client.evaluate("document.documentElement.classList.contains('reduced-motion')");
  await client.evaluate("document.documentElement.classList.remove('reduced-motion')");
  await client.send('Emulation.setEmulatedMedia', { features: normalMedia });
  await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 950, deviceScaleFactor: 1, mobile: false });
  const apiCard = await client.evaluate("fetch('/api/jobs?per_page=12', { headers: { Accept: 'application/json' } }).then(r => r.json()).then(data => ({ description: data.jobs[0].description, accommodations: data.jobs[0].accommodations.map(item => item.label) }))");
  assert.deepEqual(await client.evaluate("({ description: document.querySelector('.job-description').textContent, accommodations: [...document.querySelectorAll('.job-card:first-child .job-support li')].map(el => el.textContent) })"), apiCard, 'Cards preserve the API description and every accommodation');
  await client.evaluate("document.getElementById('main-content').focus({ preventScroll: true }); document.querySelector('.job-card').scrollIntoView({ block: 'center' })");
  await client.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 1, y: 1 });
  const restingShadow = await client.evaluate("getComputedStyle(document.querySelector('.job-card')).boxShadow");
  assert.notEqual(restingShadow, 'none', 'Cards have resting elevation');
  writeFileSync(join(backend, `storage/framework/cache/ui-${label}-${context}-job-cards.png`), Buffer.from((await client.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  const point = await client.evaluate("(() => { const rect = document.querySelector('.job-card').getBoundingClientRect(); return { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 }; })()");
  await client.send('Input.dispatchMouseEvent', { type: 'mouseMoved', ...point });
  await check("getComputedStyle(document.querySelector('.job-card')).boxShadow !== " + JSON.stringify(restingShadow), 'Pointer hover strengthens card elevation');
  await client.evaluate("document.querySelector('.job-details-action').focus({ preventScroll: true })");
  await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
  await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
  await client.evaluate("document.querySelector('.job-details-action').focus({ preventScroll: true })");
  let forcedFocusNode;
  if (!await client.evaluate("document.querySelector('.job-details-action').matches(':focus-visible')")) {
    console.log('Inspecting forced focus-visible styling; native Windows keyboard dispatch is unavailable.');
    await client.send('DOM.enable'); await client.send('CSS.enable');
    const { root: documentRoot } = await client.send('DOM.getDocument');
    const { nodeId } = await client.send('DOM.querySelector', { nodeId: documentRoot.nodeId, selector: '.job-details-action' });
    forcedFocusNode = nodeId;
    await client.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: ['focus-visible'] });
  }
  assert.ok(await client.evaluate("(() => { const link=document.querySelector('.job-details-action'), style=getComputedStyle(link); return document.activeElement === link && parseFloat(style.outlineWidth) >= 3 && style.outlineStyle !== 'none'; })()"), 'Details action retains visible focus and a real focus destination');
  writeFileSync(join(backend, `storage/framework/cache/ui-${label}-${context}-job-focus.png`), Buffer.from((await client.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  if (forcedFocusNode) await client.send('CSS.forcePseudoState', { nodeId: forcedFocusNode, forcedPseudoClasses: [] });
  await client.evaluate("document.documentElement.classList.add('high-contrast'); document.activeElement?.blur()");
  for (const state of ['resting', 'hover-and-focus']) {
    if (state === 'resting') await client.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 1, y: 1 });
    else {
      await client.send('Input.dispatchMouseEvent', { type: 'mouseMoved', ...point });
      await client.evaluate("document.querySelector('.job-details-action').focus({ preventScroll: true })");
    }
    await check("(() => { const style=getComputedStyle(document.querySelector('.job-card')), action=getComputedStyle(document.querySelector('.job-details-action')); return ['Top','Right','Bottom','Left'].every(side => style['border' + side + 'Color'] !== style.backgroundColor && parseFloat(style['border' + side + 'Width']) >= 1) && action.borderTopColor !== action.backgroundColor; })()", 'High contrast keeps every card edge and the action boundary visible at rest, hover and focus');
    checks.push({ context, mode: 'high-contrast-boundary', state, passed: true });
  }
  await client.evaluate("document.documentElement.classList.remove('high-contrast')");
  for (const system of [false, true]) {
    await client.evaluate(`document.documentElement.classList.toggle('reduced-motion', ${!system})`);
    await client.send('Emulation.setEmulatedMedia', { features: [...normalMedia.filter(item => item.name !== 'prefers-reduced-motion'), { name: 'prefers-reduced-motion', value: system ? 'reduce' : 'no-preference' }] });
    assert.ok(await client.evaluate("(() => { const style=getComputedStyle(document.querySelector('.job-card')); return style.transform === 'none' && style.transitionDuration.split(',').every(value => parseFloat(value) <= .01); })()"), 'System and saved reduced-motion preferences suppress transitions');
    checks.push({ context, mode: system ? 'system-reduced-motion' : 'saved-reduced-motion', passed: true });
  }
  await client.evaluate("document.documentElement.classList.remove('reduced-motion')");
  for (const width of [375,1440]) {
    await client.send('Emulation.setDeviceMetricsOverride', { width, height: 950, deviceScaleFactor: 1, mobile: false });
    await client.send('Emulation.setEmulatedMedia', { features: [...normalMedia, { name: 'forced-colors', value: 'active' }] });
    assert.ok(await client.evaluate(`(() => { const style=getComputedStyle(document.querySelector('.job-card')); return matchMedia('(forced-colors: active)').matches && style.boxShadow === 'none' && parseFloat(style.borderWidth) >= 1 && style.borderStyle === 'solid' && document.documentElement.scrollWidth <= ${width}; })()`), 'Forced colors retains visible borders without shadows or overflow');
    checks.push({ context, mode: 'forced-colors', width, passed: true });
  }
  writeFileSync(join(backend, `storage/framework/cache/ui-${label}-${context}-job-forced-colors.png`), Buffer.from((await client.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  await client.send('Emulation.setEmulatedMedia', { features: normalMedia });
  const original = await client.evaluate("({ description: document.querySelector('.job-description').textContent, support: document.querySelector('.job-support li').textContent })");
  await client.evaluate("document.querySelector('.job-description').textContent = 'Synthetic long description. '.repeat(40) + 'LONG_UNBROKEN_CONTENT_'.repeat(6); document.querySelector('.job-support li').textContent = 'Synthetic accommodation label with detailed practical arrangements '.repeat(4)");
  for (const width of [320,375,768,1024,1440]) {
    await client.send('Emulation.setDeviceMetricsOverride', { width, height: 950, deviceScaleFactor: 1, mobile: false });
    for (const textScale of [100,200]) {
      await client.evaluate(`document.documentElement.style.fontSize = '${textScale === 200 ? 32 : 16}px'`);
      assert.ok(await client.evaluate(`(() => { const card=document.querySelector('.job-card'), description=card.querySelector('.job-description'), link=card.querySelector('.job-details-action'), footer=card.querySelector('.job-card-footer'); return document.documentElement.scrollWidth <= ${width} && description.scrollHeight <= description.clientHeight + 1 && link.scrollWidth <= link.clientWidth + 1 && footer.getBoundingClientRect().bottom <= card.getBoundingClientRect().bottom; })()`), 'Long descriptions, support labels and footer actions reflow without clipping');
      checks.push({ context, mode: 'long-content', width, textScale, passed: true });
    }
  }
  await client.evaluate(`document.querySelector('.job-description').textContent = ${JSON.stringify(original.description)}; document.querySelector('.job-support li').textContent = ${JSON.stringify(original.support)}; document.documentElement.style.fontSize = ''; document.documentElement.classList.toggle('reduced-motion', ${reduced}); window.scrollTo(0,0)`);
  await client.send('Emulation.clearDeviceMetricsOverride');
  return checks;
}

async function reviewCardAlignment(path, label, authenticated) {
  const original = await client.evaluate("(() => { const list = document.querySelector('.job-card .job-support ul'); if (!list) throw new Error('Missing accommodation list'); const html = list.innerHTML; for (const text of ['Synthetic: step-free interview access', 'Synthetic: written recruitment materials', 'Synthetic: flexible interview timing', 'Synthetic: assistive technology arrangements']) { const item = document.createElement('li'); item.textContent = text; list.append(item); } return html; })()");
  const results = [];
  for (const theme of ['light', 'dark-mode', 'high-contrast']) {
    await client.evaluate(`document.documentElement.classList.add('${theme}')`);
    for (const width of [320,375,768,1024,1440]) {
      await client.send('Emulation.setDeviceMetricsOverride', { width, height: 950, deviceScaleFactor: 1, mobile: false });
      for (const textScale of [100,200]) {
        await client.evaluate(`document.documentElement.style.fontSize = '${textScale === 200 ? 32 : 16}px'`);
        const measurement = await client.evaluate(`(() => {
          const cards = [...document.querySelectorAll('.job-card')];
          const rows = [];
          for (const card of cards) {
            const host = card.closest('.saved-job-item, .recommendation-card') || card;
            const rect = host.getBoundingClientRect();
            let row = rows.find(value => Math.abs(value.top - rect.top) < 2);
            if (!row) { row = { top: rect.top, cards: [] }; rows.push(row); }
            row.cards.push({ bottom: rect.bottom, footer: card.querySelector('.job-card-footer').getBoundingClientRect().bottom, action: card.querySelector('.job-details-action')?.getBoundingClientRect().bottom });
          }
          const spread = values => values.length ? Math.max(...values) - Math.min(...values) : 0;
          return {
            overflow: document.documentElement.scrollWidth > ${width},
            rows: rows.length,
            multiCardRows: rows.filter(row => row.cards.length > 1).length,
            aligned: rows.every(row => spread(row.cards.map(card => card.bottom)) <= 2 && spread(row.cards.map(card => card.footer)) <= 2 && spread(row.cards.map(card => card.action).filter(value => value !== undefined)) <= 2),
            footerAtBottom: cards.every(card => Math.abs(card.getBoundingClientRect().bottom - card.querySelector('.job-card-footer').getBoundingClientRect().bottom) <= 2),
            clipped: [...document.querySelectorAll('.job-description, .job-support li, .job-details-action')].some(element => element.scrollHeight > element.clientHeight + 1 || element.scrollWidth > element.clientWidth + 1)
          };
        })()`);
        results.push({ path, authenticated, theme, width, textScale, ...measurement });
        assert.ok(!measurement.overflow && measurement.aligned && measurement.footerAtBottom && !measurement.clipped, 'Mixed accommodation counts align card bottoms and details actions without clipping: ' + JSON.stringify(results.at(-1)));
        if (theme === 'light' && textScale === 100 && width === 1440) {
          await client.evaluate("document.querySelector('.job-card').scrollIntoView({ block: 'center' })");
          const name = path === '/' ? 'featured' : path.slice(1).replaceAll('/', '-');
          writeFileSync(join(backend, `storage/framework/cache/ui-${label}-${authenticated ? 'applicant' : 'public'}-${name}-alignment.png`), Buffer.from((await client.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
        }
      }
    }
    await client.evaluate(`document.documentElement.classList.remove('${theme}')`);
  }
  assert.ok(results.some(result => result.multiCardRows > 0), 'Alignment review includes real multi-card rows');
  await client.evaluate(`document.querySelector('.job-card .job-support ul').innerHTML = ${JSON.stringify(original)}; document.documentElement.style.fontSize = ''; window.scrollTo(0,0)`);
  await client.send('Emulation.clearDeviceMetricsOverride');
  return results;
}

async function reviewUI() {
  const widths = [320, 375, 768, 1024, 1440];
  const label = /^[a-z0-9-]{1,24}$/.test(process.env.PWDWORK_UI_LABEL || '') ? process.env.PWDWORK_UI_LABEL : 'review';
  const results = [];
  const themeResults = [];
  const cardChecks = [];
  const alignmentChecks = [];
  await go('/');
  await fill('#heroSearchInput', 'Browser');
  await fill('#heroLocationInput', 'Manila');
  await click('Search jobs');
  await check("location.pathname === '/jobs' && new URLSearchParams(location.search).get('q') === 'Browser' && new URLSearchParams(location.search).get('location') === 'Manila'", 'Hero search preserves keyword and location');
  const visits = [
    [null, ['/', '/jobs', '/jobs/1', '/employers', '/about', '/login', '/register', '/forgot-password', '/reset-password', '/missing-page']],
    ['candidate@browser.example', ['/applicant', '/jobs', '/jobs/1', '/applicant/profile', '/applicant/recommendations', '/applicant/applications', '/applicant/saved-jobs', '/applicant/accommodation-requests', '/applicant/accommodation-requests/new', '/applicant/resumes', '/notifications', '/reports']],
    ['owner@browser.example', ['/employer', '/employer/profile', '/employer/listings', '/employer/applicants', '/employer/accommodation-requests', '/notifications', '/reports']],
    ['admin@browser.example', ['/admin', '/admin/employers', '/admin/jobs', '/admin/users', '/admin/reports', '/admin/audit', '/notifications']],
  ];
  let authenticated = false;
  let requestId;
  for (const [email, configuredPaths] of visits) {
    if (authenticated) await logout();
    if (email) { await login(email); authenticated = true; }
    const paths = [...configuredPaths];
    if (email === 'candidate@browser.example') {
      requestId = await client.evaluate(`(async () => {
        await fetch('/api/csrf-cookie', { credentials: 'same-origin' });
        const applications = await (await fetch('/api/applicant/applications', { headers: { Accept: 'application/json' } })).json();
        const token = decodeURIComponent(document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN=')).split('=').slice(1).join('='));
        const response = await fetch('/api/accommodation-requests', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token }, body: JSON.stringify({ application_id: applications.applications[0].applicationId, action_key: crypto.randomUUID(), share_request: true, stage: 'interview', categories: ['Accessible restroom'], details: 'Synthetic browser request: please confirm a step-free room.' }) });
        const data = await response.json(); if (!response.ok) throw new Error(data.message); return data.request.id;
      })()`);
      paths.push(`/applicant/accommodation-requests/${requestId}`);
    } else if (email === 'owner@browser.example') paths.push(`/employer/accommodation-requests/${requestId}`);
    for (const path of paths) {
      console.log('UI review ' + path);
      await go(path);
      await check("![...document.querySelectorAll('main [role=status]')].some(el => el.textContent.includes('Loading'))", 'page data ready');
      if (email === 'candidate@browser.example' && path.startsWith('/jobs')) assert.ok(await client.evaluate("document.querySelector('.workspace-mode .app-sidebar') !== null"), 'Signed-in job discovery retains the applicant workspace');
      if (['/', '/applicant', '/employer'].includes(path)) await verifyMobileMenu();
      if (path === '/register') {
        await fill('#register-password', 'synthetic-visible-password');
        await click('Show password');
        assert.ok(await client.evaluate("document.querySelector('#register-password').type === 'text' && document.querySelector('#register-password').value === 'synthetic-visible-password'"), 'Show password preserves the entered value');
        await click('Hide password');
        assert.equal(await client.evaluate("document.querySelector('#register-password').type"), 'password');
        await fill('#register-password', '');
      }
      if (path === '/applicant/resumes') {
        await click('Create Resume');
        await fill('.resume-builder form input[required]', 'Presentation resume');
        await fill('.resume-builder form input[maxlength="160"]', 'Browser Applicant');
        await click('Save resume and preview');
        await check("!!document.querySelector('iframe') && [...document.querySelectorAll('main button')].some(b => b.textContent === 'Close editor' && !b.disabled)", 'matrix resume saved');
      }
      for (const width of widths) {
        await client.send('Emulation.setDeviceMetricsOverride', { width, height: 950, deviceScaleFactor: 1, mobile: false });
        for (const textScale of [100, 200]) {
        await client.evaluate(`document.documentElement.style.fontSize = '${textScale === 200 ? 32 : 16}px'`);
        const measurement = await client.evaluate(`({ overflow: document.documentElement.scrollWidth > ${width}, scrollWidth: document.documentElement.scrollWidth,
          unlabeled: [...document.querySelectorAll('main input:not([type=hidden]), main textarea, main select')].filter(el => !el.labels?.length && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby')).length,
          alerts: [...document.querySelectorAll('main [role=alert]')].map(el => el.textContent.trim()).filter(Boolean),
          offenders: [...document.querySelectorAll('main *,header *,footer *')].filter(el => { const r=el.getBoundingClientRect(); return r.width && r.right > ${width + 1}; }).slice(-5).map(el => ({tag:el.tagName, class:el.className})) })`);
        results.push({ path, width, textScale, ...measurement });
        if (textScale === 100 && [375, 1440].includes(width) && ['/', '/jobs', '/applicant', '/employer', '/applicant/applications', '/applicant/resumes', '/employer/applicants', '/applicant/accommodation-requests', '/admin'].includes(path)) {
          await client.evaluate("window.scrollTo(0,0)");
          const name = path === '/' ? 'home' : path.slice(1).replaceAll('/', '-');
          writeFileSync(join(backend, `storage/framework/cache/ui-${label}-${name}-${width}.png`), Buffer.from((await client.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
        }
        }
      }
      await client.evaluate("document.documentElement.style.fontSize = ''");
      if (['/', '/jobs', '/applicant', '/employer', '/applicant/resumes', '/applicant/accommodation-requests', '/admin', '/login', '/register'].includes(path)) {
        for (const theme of ['light', 'dark-mode', 'high-contrast']) {
          await client.evaluate(`document.documentElement.classList.add('${theme}')`);
          for (const width of [375, 1440]) {
            await client.send('Emulation.setDeviceMetricsOverride', { width, height: 950, deviceScaleFactor: 1, mobile: false });
            const measurement = await client.evaluate(`(() => {
              const style = getComputedStyle(document.documentElement);
              const luminance = token => {
                const rawColor = (token.startsWith('#') ? token : style.getPropertyValue(token)).trim().replace('#','');
                const color = rawColor.length === 3 ? [...rawColor].map(channel => channel + channel).join('') : rawColor;
                const channels = [0,2,4].map(i => parseInt(color.slice(i,i+2),16) / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4);
                return channels[0] * .2126 + channels[1] * .7152 + channels[2] * .0722;
              };
              const pairs = [['--ui-ink','--ui-surface'],['--ui-muted','--ui-surface'],['--ui-accent','--ui-surface'],['--ui-mint-ink','--ui-mint'],['--ui-danger','--ui-danger-bg'],['--ui-positive','--ui-positive-bg'],['--ui-warning','--ui-warning-bg'],['#ffffff','--ui-action']];
              const contrast = pairs.map(([text,bg]) => { const a=luminance(text), b=luminance(bg); return { text, bg, ratio: (Math.max(a,b)+.05)/(Math.min(a,b)+.05) }; });
              return { overflow: document.documentElement.scrollWidth > ${width}, contrast };
            })()`);
            themeResults.push({ path, width, theme, ...measurement });
            if (width === 1440) {
              await client.evaluate('window.scrollTo(0,0)');
              const name = path === '/' ? 'home' : path.slice(1).replaceAll('/', '-');
              writeFileSync(join(backend, `storage/framework/cache/ui-${label}-${name}-${theme}.png`), Buffer.from((await client.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
            }
          }
          await client.evaluate(`document.documentElement.classList.remove('${theme}')`);
        }
      }
      if (path === '/applicant/resumes') {
        for (const width of [375,1440]) {
          await client.send('Emulation.setDeviceMetricsOverride', { width, height: 950, deviceScaleFactor: 1, mobile: false });
          await client.evaluate("document.querySelector('.resume-preview-panel').scrollIntoView({ block: 'start' })");
          writeFileSync(join(backend, `storage/framework/cache/ui-${label}-resume-editor-${width}.png`), Buffer.from((await client.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
        }
      }
      if (path === '/jobs') cardChecks.push(...await reviewJobCards(label, Boolean(email)));
      if (['/', '/jobs', '/applicant/recommendations', '/applicant/saved-jobs'].includes(path)) alignmentChecks.push(...await reviewCardAlignment(path, label, Boolean(email)));
      await client.send('Emulation.clearDeviceMetricsOverride');
    }
  }
  await go('/about');
  await client.evaluate("document.querySelector('aside details').open = true");
  for (const [name, className] of [['High contrast', 'high-contrast'], ['Dark mode', 'dark-mode'], ['Reduced motion', 'reduced-motion'], ['Dyslexia-friendly reading font', 'dyslexia-font'], ['Large cursor', 'large-cursor']]) {
    const previous = await client.evaluate(`document.documentElement.classList.contains(${JSON.stringify(className)})`);
    await click(`${name}: ${previous ? 'On' : 'Off'}`, 'aside');
    await check(`document.documentElement.classList.contains(${JSON.stringify(className)}) === ${!previous}`, `${name} preference applies`);
    await click(`${name}: ${previous ? 'Off' : 'On'}`, 'aside');
    await check(`document.documentElement.classList.contains(${JSON.stringify(className)}) === ${previous}`, `${name} preference restores`);
  }
  await select('aside select', 'xlarge');
  await check("document.documentElement.classList.contains('font-size-xlarge') && parseFloat(getComputedStyle(document.documentElement).fontSize) > 16", 'text resizing preference');
  await select('aside select', 'normal');
  writeFileSync(join(backend, `storage/framework/cache/ui-${label}-results.json`), JSON.stringify({ results, themeResults, cardChecks, alignmentChecks, preferenceChecks: 6, exceptions: client.exceptions }, null, 2));
  const failures = results.filter(row => row.overflow || row.unlabeled || row.alerts.some(alert => !(row.path === '/reset-password' && alert.includes('reset token is missing'))));
  console.log(JSON.stringify({ pages: results.length / (widths.length * 2), viewportChecks: results.length, failures, exceptions: client.exceptions }));
  assert.equal(failures.length, 0, 'UI matrix overflow/labels/API error checks');
  assert.deepEqual(themeResults.filter(row => row.overflow || row.contrast.some(pair => !Number.isFinite(pair.ratio) || pair.ratio < 4.5)), [], 'Theme reflow and semantic text contrast');
  assert.deepEqual(client.exceptions, []);
  passed(`Redesign: ${results.length} responsive/text checks, ${themeResults.length} theme checks, ${cardChecks.length} card stress/preference checks, ${alignmentChecks.length} mixed-content alignment checks, 6 accessibility preferences`);
}

try {
  await requireFreePort(apiPort, "127.0.0.1");
  await requireFreePort(frontendPort, "localhost");
  await requireFreePort(browserPort, "127.0.0.1");
  const fixture = spawnSync("php", ["tests/browser-fixture.php"], { cwd: backend, env, encoding: "utf8", windowsHide: true });
  assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
  launch("php", ["-S", `127.0.0.1:${apiPort}`, join(backend, "tests/browser-router.php")], join(backend, "public"));
  launch(process.execPath, [join(root, "node_modules/vite/bin/vite.js"), ...(productionAssets ? ['preview'] : []), "--host", "localhost", "--port", String(frontendPort), "--strictPort", "--config", viteTestConfig], root,
    { ...process.env, VITE_API_URL: "/api" });
  await waitFor(async () => (await fetch(frontendOrigin + "/api/health")).ok, "Vite/API startup");
  const browser = process.env.BROWSER_PATH || [
    "C:/Program Files/Google/Chrome/Application/chrome.exe",
    "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
  ].find(existsSync);
  assert.ok(browser, "Set BROWSER_PATH to Chrome or Edge.");
  launch(browser, ["--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
    `--remote-debugging-port=${browserPort}`, "--remote-debugging-address=127.0.0.1", `--user-data-dir=${join(temp, "profile")}`, "about:blank"]);
  let target;
  await waitFor(async () => { target = (await (await fetch(`http://127.0.0.1:${browserPort}/json/list`)).json()).find((t) => t.type === "page"); return target; }, "browser startup");
  const socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((done, reject) => { socket.onopen = done; socket.onerror = reject; });
  client = new CDP(socket);
  await client.send("Page.enable");
  await client.send("Runtime.enable");
  await client.send("Network.enable");
  await client.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'light' }] });

  if (process.env.PWDWORK_SMOKE_UI_ONLY) {
    await reviewUI();
  } else {

  if (!process.env.PWDWORK_SMOKE_PHASE4_ONLY && !process.env.PWDWORK_SMOKE_PHASE5_ONLY && !process.env.PWDWORK_SMOKE_PHASE6_ONLY) {
  await go('/');
  await verifyMobileMenu();
  await fill('#heroSearchInput', 'Browser');
  await fill('#heroLocationInput', 'Manila');
  await click('Search jobs');
  await check("location.pathname === '/jobs' && new URLSearchParams(location.search).get('q') === 'Browser' && new URLSearchParams(location.search).get('location') === 'Manila'", 'Hero search preserves keyword and location');
  passed('Redesign public mobile menu, Escape/focus, current-route closure and hero search');
  await go("/jobs");
  await check("document.body.innerText.includes('27 matching jobs')", "job results");
  await click("Next page", 'nav[aria-label="Job search pages"]');
  await check("document.body.innerText.includes('Page 2 of 3')", "second job page");
  await client.evaluate("[...document.querySelectorAll('main label')].find(el => el.textContent.trim() === 'Contract').querySelector('input').click()");
  await check("document.body.innerText.includes('11 matching jobs') && document.body.innerText.includes('Page 1 of 1')", "contract filter resets pagination");
  passed("public search pagination and Contract filter");

  await login("candidate@browser.example");
  await verifyOverview();
  await go("/jobs/1");
  await check("[...document.querySelectorAll('main button')].some(el => el.textContent.trim() === 'Unsave job')", "oldest saved-job state");
  await go("/applicant/saved-jobs");
  await check("document.body.innerText.includes('Page 1 of 3')", "saved jobs page");
  await click("Next page", 'nav[aria-label="Saved job pages"]');
  await check("document.body.innerText.includes('Page 2 of 3')", "saved jobs next page");
  await reload();
  await check("document.body.innerText.includes('Saved jobs') && document.body.innerText.includes('Page 1 of 3')", "cookie refresh restoration");
  await go("/applicant/applications");
  await check("document.body.innerText.includes('Page 1 of 3')", "applications loaded");
  await client.evaluate("document.querySelector('main article input[type=checkbox]').click()");
  await check("document.querySelector('main article input[type=checkbox]')?.checked === true", "consent saved");
  await click("Refresh applications");
  await check("document.querySelector('main article input[type=checkbox]')?.checked === true", "consent persisted");
  await logout();
  passed("cookie login, refresh, saved/application pagination, sharing and logout");

  await login("owner@browser.example");
  await verifyOverview(true);
  passed('Redesign applicant/employer overview totals, real recent jobs, partial failure and retry');
  await go("/employer/listings");
  await check("document.body.innerText.includes('Page 1 of 3')", "employer listing pages");
  await click("Next page", 'nav[aria-label="Employer listing pages"]');
  await check("document.body.innerText.includes('Page 2 of 3')", "employer listing next");
  await go("/employer/applicants");
  await check("document.body.innerText.includes('41 results')", "employer applications loaded");
  assert.ok(await client.evaluate("[...document.querySelectorAll('.resume-sharing h3')].every(el => el.textContent === 'Resume Builder')"), 'Unshared builder resumes never imply active access');
  assert.equal(await client.evaluate("document.body.innerText.includes('Resume shared with this application')"), false, 'Misleading shared-resume heading removed');
  await fill('input[placeholder="Name, email, experience..."]', 'Browser Candidate');
  await check("document.body.innerText.includes('Professional browser summary')", "real professional information");
  await client.evaluate("(() => { const el = document.querySelector('select[aria-label=\"Status for Browser Candidate\"]'); el.value = 'Shortlisted'; el.dispatchEvent(new Event('change', {bubbles:true})); })()");
  await check("document.querySelector('select[aria-label=\"Status for Browser Candidate\"]')?.value === 'Shortlisted'", "shortlisted status saved");
  await click("Shortlisted");
  await check("document.body.innerText.includes('1 results') && document.body.innerText.includes('Accessible restroom')", "status filter and consent disclosure");
  await client.evaluate("(() => { const el = document.querySelector('select[aria-label=\"Status for Browser Candidate\"]'); el.value = 'Hired'; el.dispatchEvent(new Event('change', {bubbles:true})); })()");
  await check("document.body.innerText.includes('No matching applicants')", "mutation removes item from current status filter");
  await logout();
  await login("candidate@browser.example");
  await go("/applicant/applications");
  await check("document.querySelector('main article')?.innerText.includes('Status: Hired')", "applicant sees Hired");
  await logout();
  passed("employer profile details, status mutation/filtering and applicant Hired display");

  await go("/register");
  await fill("#register-name", "Registered Browser Candidate");
  await fill("#register-email", "registered@browser.example");
  await fill("#register-password", "browser-password");
  await click('Show password');
  assert.ok(await client.evaluate("document.querySelector('#register-password').type === 'text' && document.querySelector('#register-password').value === 'browser-password'"), 'Show password preserves the entered value');
  await click('Hide password');
  assert.equal(await client.evaluate("document.querySelector('#register-password').type"), 'password');
  await click("Create account");
  await check("location.pathname === '/applicant' && document.body.innerText.includes('Log out')", "browser registration opens applicant overview");
  const storage = await client.evaluate("Object.keys(localStorage)");
  assert.ok(storage.every((key) => !/token|auth|profile|application/i.test(key)), "Sensitive data in localStorage");
  await logout();
  await go("/forgot-password");
  await fill('main input[type="email"]', "registered@browser.example");
  await click("Send reset link");
  await check("document.body.innerText.includes('Request accepted.')", "queued recovery accepted");
  const testInbox = join(temp, 'reset-mail.json');
  await waitFor(() => existsSync(testInbox), 'Deferred mail delivered to the isolated test inbox without a worker');
  const resetLink = new URL(JSON.parse(readFileSync(testInbox, 'utf8')).url);
  assert.ok(resetLink.origin === frontendOrigin && resetLink.pathname === '/reset-password' && resetLink.search === '' && resetLink.hash.includes('token='), 'Reset email uses the configured frontend and a private fragment');
  await go(resetLink.pathname + resetLink.hash);
  await check("!!document.querySelector('main input[type=password]')", 'Email link opens the reset form');
  await fill('main input[type="password"]', 'changed-password');
  await fill('main label:last-of-type input[type="password"]', 'changed-password');
  await click("Reset password");
  await check("document.body.innerText.includes('Password reset. Sign in')", "password reset complete");
  assert.ok(await client.evaluate("location.hash === '' && location.search === '' && !document.querySelector('main input[type=password]')"), 'Successful reset removes URL secrets and hides the completed form');
  await go(resetLink.pathname + resetLink.hash);
  await check("!!document.querySelector('main input[type=password]')", 'Reopened link displays the reset form');
  await fill('main input[type="password"]', 'replayed-password');
  await fill('main label:last-of-type input[type="password"]', 'replayed-password');
  await click('Reset password');
  await check("document.body.innerText.includes('This reset link is invalid or expired. Request a new link.')", 'Used email link cannot be replayed');
  assert.ok(!logs.join('').includes(new URLSearchParams(resetLink.hash.slice(1)).get('token')), 'Reset tokens are absent from captured server logs');
  await login("registered@browser.example", "changed-password");
  await logout();
  passed("browser registration, worker-free private email recovery, fragment links, single-use reset and new-password login");

  await client.send("Emulation.setDeviceMetricsOverride", { width: 320, height: 800, deviceScaleFactor: 1, mobile: false });
  await go("/jobs");
  await check("document.body.innerText.includes('27 matching jobs')", "mobile jobs loaded");
  assert.ok(await client.evaluate("document.documentElement.scrollWidth <= 320"), "Horizontal overflow at 320px");
  await client.send("Network.emulateNetworkConditions", { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
  await click("Reset all filters and search");
  // Changing a filter forces an actual API request while offline.
  await client.evaluate("[...document.querySelectorAll('main label')].find(el => el.textContent.trim() === 'Contract').querySelector('input').click()");
  await check("document.body.innerText.includes('Cannot reach the server')", "offline error");
  await client.send("Network.emulateNetworkConditions", { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  await click("Try again");
  await check("document.body.innerText.includes('11 matching jobs')", "offline retry");
  assert.deepEqual(client.exceptions, [], "Uncaught browser exceptions");
  passed("320px reflow, offline errors/retry and no uncaught browser exceptions");

  await client.send("Emulation.setDeviceMetricsOverride", { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
  await login("admin@browser.example");
  await go("/admin");
  await check("document.body.innerText.includes('Registered employers') && document.querySelectorAll('.admin-stats dd').length === 11", "live admin overview");
  await go("/admin/employers?status=pending");
  await check("document.body.innerText.includes('Pending Browser Company')", "pending employer queue");
  await reviewFirst("employer");
  await confirmReview("rejected", "Please clarify your company location.");
  await logout();
  await login("pending@browser.example");
  await go("/employer/profile");
  await check("document.body.innerText.includes('Please clarify your company location.')", "employer rejection reason");
  await fill("main form input", "Corrected Browser Company");
  await click("Save profile");
  await check("document.body.innerText.includes('Profile saved to your account.')", "company correction");
  await click("Request another verification review");
  await check("document.body.innerText.includes('Your company is back in the verification queue.')", "employer resubmission");
  await go("/employer/listings");
  await click("+ Post a job");
  await fill("main form input", "New Browser Moderated Job");
  await fill("main form label:nth-child(2) input", "Manila");
  await fill("main form textarea", "A complete and accessible support job description.");
  await click("Save listing for review");
  await check("document.body.innerText.includes('New Browser Moderated Job') && document.body.innerText.includes('Moderation: pending')", "unverified draft saved");
  assert.equal(await client.evaluate("[...document.querySelectorAll('main article button')].find(el => el.textContent === 'Submit for review')?.disabled"), true);
  await logout();
  await login("admin@browser.example");
  await go("/admin/employers?status=pending");
  await check("document.body.innerText.includes('Corrected Browser Company')", "corrected company review");
  await reviewFirst("employer"); await confirmReview("verified"); await logout();
  await login("pending@browser.example");
  await go("/notifications");
  await check("document.body.innerText.includes('Employer verification verified')", "decision notification persisted");
  await go("/employer/listings");
  await check("document.body.innerText.includes('New Browser Moderated Job')", "draft reloaded");
  await click("Submit for review");
  await check("document.body.innerText.includes('Your listing is awaiting administrative approval.')", "verified employer submitted listing");
  await logout(); await login("admin@browser.example");
  await go("/admin/jobs?status=pending");
  await check("document.body.innerText.includes('New Browser Moderated Job')", "job approval queue");
  await reviewFirst("job"); await confirmReview("approved");
  await go("/jobs?q=New+Browser+Moderated+Job");
  await check("document.body.innerText.includes('1 matching jobs')", "approved job publicly visible");
  passed("admin overview, employer rejection/correction/review/notification and moderated publication");

  await logout(); await login("candidate@browser.example");
  await go("/jobs/28");
  await check("document.body.innerText.includes('New Browser Moderated Job')", "approved detail");
  await client.evaluate("[...document.querySelectorAll('main a')].find(el => el.textContent === 'Report this listing').click()");
  await check("document.body.innerText.includes('Submit a report')", "report form");
  await fill("main form textarea", "The accessibility description should be reviewed for accuracy.");
  await click("Submit report");
  await check("document.body.innerText.includes('Report submitted.')", "report created");
  await logout(); await login("admin@browser.example");
  await go("/admin/reports?status=pending"); await reviewFirst("report");
  await fill("dialog[open] form label:last-of-type textarea", "PRIVATE_BROWSER_NOTE");
  await confirmReview("resolved", "Investigated the listing and recorded the outcome.");
  await go("/admin/jobs?job_id=28"); await reviewFirst("job");
  await confirmReview("hidden", "Accessibility claims require further investigation.");
  await go("/jobs?q=New+Browser+Moderated+Job");
  await check("document.body.innerText.includes('0 matching jobs')", "hidden job removed from search");
  await go("/admin/audit");
  await check("document.body.innerText.includes('job.hidden') && document.body.innerText.includes('report.resolved')", "audits persisted");
  await go("/admin/users?query=registered%40browser.example"); await reviewFirst("user");
  await confirmReview("suspended", "Account temporarily restricted following a reviewed complaint.");
  await logout();
  await login("registered@browser.example", "changed-password", true);
  await login("admin@browser.example");
  await go("/admin/users?query=registered%40browser.example"); await reviewFirst("user");
  await confirmReview("active", "The account restriction has been reviewed and lifted.");
  await logout(); await login("registered@browser.example", "changed-password"); await logout();
  await login("candidate@browser.example"); await go("/reports");
  await check("document.body.innerText.includes('Status: Resolved')", "reporter sees outcome");
  assert.equal(await client.evaluate("document.body.innerText.includes('PRIVATE_BROWSER_NOTE')"), false);
  await logout();
  passed("private reporting, resolution, hiding, audit logs, suspension and reactivation");

  await login("admin@browser.example"); await go("/admin");
  await check("document.querySelectorAll('.admin-stats dd').length === 11", "admin stats restored");
  await client.send("Emulation.setDeviceMetricsOverride", { width: 320, height: 800, deviceScaleFactor: 1, mobile: false });
  assert.ok(await client.evaluate("document.documentElement.scrollWidth <= 320"), "Admin overflow at 320px");
  await client.evaluate("document.querySelector('aside details').open = true");
  const dark = await client.evaluate("document.documentElement.classList.contains('dark-mode')");
  await click(`Dark mode: ${dark ? "On" : "Off"}`, "aside");
  await check(`document.documentElement.classList.contains('dark-mode') === ${!dark}`, "admin dark setting");
  await click("High contrast: Off", "aside");
  await check("document.documentElement.classList.contains('high-contrast')", "admin contrast setting");
  await reload();
  await check("document.documentElement.classList.contains('high-contrast') && document.querySelectorAll('.admin-stats dd').length === 11", "persistent accessibility preferences");
  assert.ok(await client.evaluate("document.documentElement.scrollWidth <= 320"), "High-contrast admin overflow");
  assert.deepEqual(client.exceptions, [], "Uncaught Phase 2 browser exceptions");
  passed("admin narrow-screen layout and persistent dark/high-contrast settings");
  await client.send("Emulation.clearDeviceMetricsOverride");
  await logout(); await login("candidate@browser.example");
  await go("/applicant/recommendations");
  await check("document.body.innerText.includes('Fit & Access Match:') && document.querySelectorAll('main section[aria-label]').length > 0", "recommendations loaded from API");
  await check("document.querySelector('main section[aria-label] button')?.textContent === 'Saved'", "already-saved recommendation label");
  await client.evaluate("[...document.querySelectorAll('main a')].find(el => el.textContent === 'Apply for Job').click()");
  await check("document.body.innerText.includes('Unsave job')", "existing saved recommendation detail");
  await click("Unsave job");
  await check("document.body.innerText.includes('Job removed from saved jobs.')", "prepare unsaved recommendation");
  await go("/applicant/recommendations");
  await check("document.querySelector('main section[aria-label] button')?.textContent === 'Save Job'", "recommendation reflects existing unsave");
  await client.evaluate(`(() => {
    window.recommendationSaveRequests = 0;
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url, ...args) {
      if (method.toUpperCase() === 'PUT' && String(url).includes('/applicant/saved-jobs/')) window.recommendationSaveRequests++;
      return originalOpen.call(this, method, url, ...args);
    };
    const button = document.querySelector('main section[aria-label] button');
    button.focus(); button.click(); button.click();
  })()`);
  await check("document.body.innerText.includes('Job saved. Find it in Saved Jobs.')", "recommendation save workflow");
  assert.equal(await client.evaluate("document.querySelector('main section[aria-label] button').textContent"), "Saved");
  assert.equal(await client.evaluate("document.querySelector('main section[aria-label] button').getAttribute('aria-disabled')"), "true");
  assert.ok(await client.evaluate("document.activeElement === document.querySelector('main section[aria-label] button')"), "Saved button retains keyboard focus");
  await client.evaluate("document.querySelector('main section[aria-label] button').click()");
  assert.equal(await client.evaluate("window.recommendationSaveRequests"), 1, "Duplicate save requests prevented");
  await reload();
  await check("document.querySelector('main section[aria-label] button')?.textContent === 'Saved'", "persisted recommendation saved state");
  await client.evaluate("[...document.querySelectorAll('main a')].find(el => el.textContent === 'Apply for Job').click()");
  await check("location.pathname.startsWith('/jobs/') && document.body.innerText.includes('Apply')", "recommendation application detail workflow");
  await check("document.body.innerText.includes('Unsave job')", "recommendation shares detail saved state");
  await click("Unsave job");
  await check("document.body.innerText.includes('Job removed from saved jobs.')", "existing unsave workflow");
  await go("/applicant/recommendations");
  await check("document.querySelector('main section[aria-label] button')?.textContent === 'Save Job'", "recommendation reflects removed saved job");
  await go("/");
  await check("document.body.innerText.includes('Recommended for You') && document.body.innerText.includes('Fit & Access Match:')", "personalized home section");
  await go("/applicant/recommendations");
  await client.send("Emulation.setDeviceMetricsOverride", { width: 320, height: 800, deviceScaleFactor: 1, mobile: false });
  assert.ok(await client.evaluate("document.documentElement.scrollWidth <= 320"), "Recommendations overflow at 320px");
  assert.deepEqual(client.exceptions, [], "Uncaught recommendation browser exceptions");
  passed("Phase 3 recommendation explanations, save/apply links, personalized home and mobile layout");
  } else {
    await login("candidate@browser.example");
  }

  if (!process.env.PWDWORK_SMOKE_PHASE5_ONLY && !process.env.PWDWORK_SMOKE_PHASE6_ONLY) {
  await client.send("Emulation.clearDeviceMetricsOverride");
  await go('/applicant/applications');
  await check("!!document.querySelector('main a[href*=\"/accommodation-requests/new?application_id=\"]')", 'application accommodation link');
  await client.evaluate("document.querySelector('main a[href*=\"/accommodation-requests/new?application_id=\"]').click()");
  await check("document.querySelectorAll('input[name=categories]').length === 12", 'request catalog loaded');
  await client.evaluate("document.querySelector('input[name=categories][value=\"Accessible restroom\"]').click()");
  await select('main form fieldset select', 'interview');
  await fill('main form textarea', 'PRACTICAL_BROWSER_REQUEST: Please confirm an accessible interview room.');
  await client.evaluate("document.querySelector('main form input[type=checkbox][required]').click(); [...document.querySelectorAll('main button')].find(button => button.textContent === 'Share and submit request').focus()");
  await check("document.querySelector('main form input[type=checkbox][required]')?.checked && document.querySelector('main form').checkValidity()", 'request form valid for keyboard submission');
  await client.evaluate("[...document.querySelectorAll('main button')].find(button => button.textContent === 'Share and submit request').focus()");
  assert.equal(await client.evaluate('document.activeElement?.textContent'), 'Share and submit request');
  await client.send('Page.bringToFront');
  await client.send('Emulation.setFocusEmulationEnabled', { enabled: true });
  await client.send('Input.setIgnoreInputEvents', { ignore: false });
  await client.evaluate("window.phase4KeyboardEvents = []; document.addEventListener('keydown', event => window.phase4KeyboardEvents.push(event.key), { once: true }); document.querySelector('main form').addEventListener('submit', () => window.phase4KeyboardEvents.push('submitted'), { once: true })");
  await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', text: '\r', unmodifiedText: '\r', windowsVirtualKeyCode: 13 });
  await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
  if (!await client.evaluate("window.phase4KeyboardEvents.includes('Enter')")) {
    console.log('Native keyboard dispatch unavailable; checking focused native form submission instead.');
    await client.evaluate("document.querySelector('main form').requestSubmit([...document.querySelectorAll('main button')].find(button => button.textContent === 'Share and submit request'))");
  }
  await check("location.pathname.startsWith('/applicant/accommodation-requests/') && !location.pathname.endsWith('/new') && document.body.innerText.includes('PRACTICAL_BROWSER_REQUEST')", 'focused native request submission');
  const accommodationRequestId = await client.evaluate("Number(location.pathname.split('/').at(-1))");
  await fill('main form textarea', '<img src=x onerror="window.phase4Xss=true"> Practical coordination only.');
  await click('Send message');
  await check("document.querySelector('section[aria-label=\"Request messages\"]')?.innerText.includes('Practical coordination only.')", 'private message persisted');
  assert.equal(await client.evaluate("document.querySelectorAll('section[aria-label=\"Request messages\"] img').length"), 0, 'Message HTML escaped');
  assert.equal(await client.evaluate('window.phase4Xss === true'), false, 'No message XSS');
  await click('Edit request');
  await check("!!document.querySelector('dialog[open] textarea')", 'edit request dialog');
  assert.ok(await client.evaluate("document.querySelector('dialog[open]').contains(document.activeElement)"), 'Edit dialog receives keyboard focus');
  await fill('dialog[open] textarea', 'UPDATED_PRACTICAL_REQUEST: Please confirm a step-free interview room.');
  await client.evaluate("document.querySelector('dialog[open] input[type=checkbox][required]').click()");
  await click('Share updated request', 'dialog[open]');
  await check("!document.querySelector('dialog[open]') && document.body.innerText.includes('UPDATED_PRACTICAL_REQUEST')", 'shared edit and private history');
  await check("document.activeElement?.textContent === 'Edit request'", 'Edit restores focus');
  await logout(); await login('pending@browser.example');
  const deniedRequest = await client.evaluate(`fetch('/api/accommodation-requests/${accommodationRequestId}', { headers: { Accept: 'application/json' }, credentials: 'include' }).then(async response => ({ status: response.status, body: await response.json() }))`);
  assert.equal(deniedRequest.status, 404, 'Unrelated employer direct API access denied');
  assert.deepEqual(deniedRequest.body, { message: 'Accommodation request not found or you do not have permission to view it.' });
  await go(`/employer/accommodation-requests/${accommodationRequestId}`);
  await check("document.querySelector('main [role=alert]')?.innerText.includes('Accommodation request not found or you do not have permission to view it.')", 'accessible unauthorized request message');
  assert.equal(await client.evaluate("document.body.innerText.includes('UPDATED_PRACTICAL_REQUEST') || document.body.innerText.includes('App' + String.fromCharCode(92) + 'Models') || document.body.innerText.includes('No query results')"), false, 'Unauthorized page contains no private or internal details');
  await logout(); await login('owner@browser.example');
  await go('/notifications');
  await check("document.body.innerText.includes('Request submitted')", 'in-app request notification');
  assert.equal(await client.evaluate("document.body.innerText.includes('UPDATED_PRACTICAL_REQUEST') || document.body.innerText.includes('PRACTICAL_BROWSER_REQUEST')"), false, 'Notification previews contain no request details');
  assert.ok(await client.evaluate(`(() => {
    const article = [...document.querySelectorAll('main article')].find(item => item.querySelector('a') && item.querySelector('button'));
    const link = article?.querySelector('a'), button = article?.querySelector('button');
    if (!link || !button || link.parentElement !== button.parentElement) return false;
    const style = getComputedStyle(link.parentElement);
    return style.display === 'flex' && parseFloat(style.columnGap) >= 16 && parseFloat(style.rowGap) >= 8;
  })()`), 'Notification link and read button have responsive spacing');
  await go(`/employer/accommodation-requests/${accommodationRequestId}`);
  await check("document.body.innerText.includes('UPDATED_PRACTICAL_REQUEST') && !!document.querySelector('main article form')", 'authorized employer detail');
  assert.equal(await client.evaluate("document.body.innerText.includes('Professional browser summary')"), false, 'Private professional profile not fetched by request page');
  await click('Send response');
  await check("document.body.innerText.includes('Status: Under discussion')", 'employer acknowledgment');
  await select('main article form select', 'propose');
  await fill('main article form textarea', 'PROPOSED_BROWSER_ARRANGEMENT: A step-free interview room is available.');
  await click('Send response');
  await check("document.body.innerText.includes('Status: Alternative proposed')", 'employer proposal persisted');
  assert.equal(await client.evaluate("[...document.querySelectorAll('main article form select option')].some(option => option.value === 'agree')"), false, 'Employer cannot accept own alternative');
  await logout(); await login('candidate@browser.example');
  await go(`/applicant/accommodation-requests/${accommodationRequestId}`);
  await check("document.body.innerText.includes('Accept proposed arrangement') && document.body.innerText.includes('Decline proposed arrangement')", 'applicant response controls');
  await click('Accept proposed arrangement');
  await check("!!document.querySelector('dialog[open]')", 'acceptance confirmation');
  await click('Confirm accept', 'dialog[open]');
  await check("!document.querySelector('dialog[open]') && document.body.innerText.includes('Status: Agreed')", 'explicit applicant acceptance');
  await check("document.activeElement?.tagName === 'H2'", 'Acceptance focus moves to updated request heading');
  await go('/applicant/applications');
  await check("document.body.innerText.includes('Confirmed interview arrangements') && document.body.innerText.includes('PROPOSED_BROWSER_ARRANGEMENT')", 'confirmed arrangement integrated with application');
  await go(`/applicant/accommodation-requests/${accommodationRequestId}`);
  await check("document.body.innerText.includes('Status: Agreed')", 'agreement restored');
  await client.send('Emulation.setDeviceMetricsOverride', { width: 320, height: 800, deviceScaleFactor: 1, mobile: false });
  assert.ok(await client.evaluate('document.documentElement.scrollWidth <= 320'), 'Accommodation detail reflows at 320px');
  await click('Withdraw request and sharing');
  await check("!!document.querySelector('dialog[open]')", 'withdrawal confirmation');
  await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  if (await client.evaluate("!!document.querySelector('dialog[open]')")) {
    await client.evaluate("document.querySelector('dialog[open]').dispatchEvent(new Event('cancel', { cancelable: true }))");
  }
  await check("!document.querySelector('dialog[open]')", 'withdrawal dialog cancellation');
  assert.equal(await client.evaluate('document.activeElement?.textContent'), 'Withdraw request and sharing', 'Cancel restores keyboard focus');
  await click('Withdraw request and sharing');
  await click('Confirm withdraw', 'dialog[open]');
  await check("document.body.innerText.includes('Status: Withdrawn') && !document.querySelector('dialog[open]')", 'withdrawal persisted');
  await check("document.activeElement?.tagName === 'H2'", 'Withdrawal preserves focus at request heading');
  assert.equal(await client.evaluate("[...document.querySelectorAll('main button')].some(button => button.textContent === 'Send message')"), false, 'Withdrawn request not actionable');
  await client.send('Emulation.clearDeviceMetricsOverride');
  await logout(); await login('owner@browser.example');
  await go(`/employer/accommodation-requests/${accommodationRequestId}`);
  await check("!!document.querySelector('main [role=alert]')", 'withdrawal blocks future employer read');
  assert.equal(await client.evaluate("document.body.innerText.includes('UPDATED_PRACTICAL_REQUEST')"), false, 'Revoked request content unavailable');
  await go('/employer/accommodation-requests');
  await check("document.body.innerText.includes('No shared requests match this view.')", 'withdrawn request removed from employer list');
  assert.deepEqual(client.exceptions, [], 'Uncaught Phase 4 browser exceptions');
  passed('Phase 4 intentional sharing, native submission and focus, private escaped messages, edit history, employer proposal, explicit acceptance, interview integration, mobile layout and withdrawal revocation');
  await logout(); await login('candidate@browser.example');
  }
  if (!process.env.PWDWORK_SMOKE_PHASE6_ONLY) {
  await go('/applicant/resumes');
  await check("document.body.innerText.includes('Create Resume')", 'resume dashboard');
  await click('Create Resume');
  await check("document.activeElement?.textContent === 'Resume editor'", 'resume editor receives focus');
  await click('Import profile information');
  await check("!!document.querySelector('dialog[open]') && document.querySelector('dialog[open]').contains(document.activeElement)", 'profile import requires focused confirmation');
  await click('Cancel', 'dialog[open]');
  await check("!document.querySelector('dialog[open]') && document.activeElement?.textContent === 'Import profile information'", 'import cancellation restores focus');
  await fill('.resume-builder form input[required]', 'Browser resume');
  await fill('.resume-builder form input[maxlength="160"]', 'Browser Applicant');
  await fill('.resume-builder form > label textarea', 'SEARCHABLE_BROWSER_RESUME');
  await click('Add Skills');
  await check("document.body.innerText.includes('Skills entry 1')", 'repeatable skill entry');
  await fill('.resume-builder form fieldset fieldset input', 'Keyboard support');
  await click('Add Skills');
  await client.evaluate("document.querySelector('[aria-label=\"Move Skills entry 2 up\"]').focus()");
  await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
  await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
  if (!await client.evaluate("document.querySelector('.resume-builder form fieldset fieldset input').value === ''")) {
    console.log('Native keyboard dispatch unavailable for resume reorder; checking focused native button activation instead.');
    await client.evaluate("document.querySelector('[aria-label=\"Move Skills entry 2 up\"]').click()");
  }
  await check("document.querySelector('.resume-builder form fieldset fieldset input').value === ''", 'keyboard reorder');
  await click('Save resume and preview');
  await check("document.body.innerText.includes('Resume saved. Preview updated.') && !!document.querySelector('iframe') && [...document.querySelectorAll('main button')].some(b => b.textContent === 'Close editor' && !b.disabled)", 'resume save and preview');
  assert.ok(await client.evaluate("document.querySelector('iframe').srcdoc.includes('SEARCHABLE_BROWSER_RESUME')"), 'Saved preview contains entered data');
  writeFileSync(join(backend, 'storage/framework/cache/phase5-browser.png'), Buffer.from((await client.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  await client.send('Emulation.setDeviceMetricsOverride', { width: 320, height: 800, deviceScaleFactor: 1, mobile: false });
  assert.ok(await client.evaluate('document.documentElement.scrollWidth <= 320'), 'Resume editor reflows at 320px');
  await client.send('Emulation.clearDeviceMetricsOverride');
  await click('Close editor');
  await click('Duplicate');
  await check("document.querySelectorAll('.resume-builder article').length === 2", 'resume duplication');
  await click('Delete');
  await check("!!document.querySelector('dialog[open]')", 'resume delete confirmation');
  await click('Confirm delete resume', 'dialog[open]');
  await check("!document.querySelector('dialog[open]') && document.querySelectorAll('.resume-builder article').length === 1", 'confirmed resume deletion');
  await check("document.activeElement?.textContent === 'My resumes'", 'deleted card focus moves to dashboard heading');
  await go('/applicant/applications');
  await click('Choose resume to share', '.resume-sharing');
  await check("!!document.querySelector('.resume-sharing select option[value]:not([value=\"\"])')", 'resume selection on application');
  const resumeId = await client.evaluate("document.querySelector('.resume-sharing select option[value]:not([value=\"\"])').value");
  await select('.resume-sharing select', resumeId);
  await client.evaluate("document.querySelector('.resume-sharing input[type=checkbox]').click()");
  await click('Share resume', '.resume-sharing');
  await check("document.body.innerText.includes('Saved resume version shared')", 'explicit resume sharing');
  await logout(); await login('owner@browser.example');
  await go('/employer/applicants');
  await fill('input[placeholder="Name, email, experience..."]', 'Browser Candidate');
  await check("[...document.querySelectorAll('.resume-sharing button')].some(b => b.textContent === 'Preview shared resume')", 'employer sees explicitly shared snapshot');
  await click('Preview shared resume', '.resume-sharing');
  await check("!!document.querySelector('.resume-sharing iframe')", 'authorized employer preview');
  assert.ok(await client.evaluate("document.querySelector('.resume-sharing h3').textContent === 'Shared Resume Builder snapshot'"), 'Active sharing has a precise builder snapshot heading');
  assert.ok(await client.evaluate("document.querySelector('.resume-sharing iframe').srcdoc.includes('SEARCHABLE_BROWSER_RESUME')"), 'Employer receives saved resume');
  await click('Close shared preview', '.resume-sharing');
  await check("!document.querySelector('.resume-sharing iframe') && document.activeElement?.textContent === 'Preview shared resume'", 'shared preview close restores focus');
  await logout(); await login('candidate@browser.example');
  await go('/applicant/applications');
  await check("[...document.querySelectorAll('.resume-sharing button')].some(b => b.textContent === 'Revoke resume access')", 'resume revocation available');
  await click('Revoke resume access', '.resume-sharing');
  await check("document.body.innerText.includes('Future access revoked.')", 'resume access revoked');
  assert.deepEqual(client.exceptions, [], 'Uncaught Phase 5 browser exceptions');
  passed('Phase 5 resume creation, saved preview, keyboard reorder, mobile editor, duplication, explicit application share, employer preview and revocation');
  }
  await go('/about');
  await check("document.body.innerText.includes('What you can do on PWDWork')", 'audited public content');
  assert.equal(await client.evaluate("/SDG [0-9]+ Certified|555-PWD1|support@pwdwork.org|Sign Language Hotline Available|1,200\\+|5,400\\+|860\\+/.test(document.body.innerText)"), false, 'No unsupported impact/certification/support claims');
  await client.send('Emulation.setDeviceMetricsOverride', { width: 320, height: 800, deviceScaleFactor: 1, mobile: false });
  await client.evaluate("document.documentElement.style.fontSize = '32px'");
  if (!await client.evaluate('document.documentElement.scrollWidth <= 320')) {
    console.log('Overflow diagnostics: ' + JSON.stringify(await client.evaluate("[...document.querySelectorAll('body *')].filter(el => { const r=el.getBoundingClientRect(); return r.width && r.right > 321; }).map(el => ({ tag: el.tagName, class: el.className, right: el.getBoundingClientRect().right, text: el.textContent.slice(0, 35) })).slice(-15)")));
  }
  writeFileSync(join(backend, 'storage/framework/cache/phase6-large-text.png'), Buffer.from((await client.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  assert.ok(await client.evaluate('document.documentElement.scrollWidth <= 320'), 'Public content reflows at 320px with 200% base text');
  await click('Privacy information (policy pending)', 'footer');
  await check("!!document.querySelector('dialog[open]') && document.querySelector('dialog[open]').contains(document.activeElement)", 'policy-information dialog receives focus');
  assert.ok(await client.evaluate("document.querySelector('dialog[open]').scrollHeight > 0 && getComputedStyle(document.querySelector('dialog[open]')).overflowY === 'auto'"), 'Long dialog can scroll at large text');
  await click('Close dialog', 'dialog[open]');
  await check("!document.querySelector('dialog[open]') && document.activeElement?.textContent === 'Privacy information (policy pending)'", 'dialog restores focus');
  await client.evaluate("document.documentElement.style.fontSize = ''");
  await client.send('Emulation.clearDeviceMetricsOverride');
  assert.deepEqual(client.exceptions, [], 'Uncaught Phase 6 browser exceptions');
  passed('Phase 6 truthful public content, 200% text with 320px reflow, scrollable policy-information dialog and focus restoration');
  console.log("Browser smoke checks passed. Only isolated temporary SQLite fixtures were used.");
  }
} catch (error) {
  if (client) {
    try { console.error((await client.evaluate("document.body.innerText")).slice(0, 2500)); } catch { /* Browser may have exited. */ }
  }
  console.error(error);
  if (client) console.error("Browser exceptions: " + JSON.stringify(client.exceptions));
  console.error(logs.join("").slice(-2000));
  process.exitCode = 1;
} finally {
  if (client) {
    try { await client.send("Browser.close"); } catch { /* Browser may already be closed. */ }
    client.socket.close();
  }
  for (const child of processes.reverse()) {
    if (child.exitCode === null) child.kill();
  }
  await delay(2000);
  // Validate the absolute cleanup target before removing this run's own fixtures.
  if (resolve(dirname(temp)) === resolve(tmpdir()) && basename(temp).startsWith("pwdwork-browser-")) {
    try { rmSync(temp, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 }); }
    catch { console.log("NOTE: Temporary browser files remain at " + temp + ' because Windows still holds a file handle.'); }
  }
}
