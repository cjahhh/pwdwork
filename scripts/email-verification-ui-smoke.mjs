// Real browser against built React assets and a loopback-only mocked API.
// No Laravel process, database, real account, mail provider or production URL is used.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { dirname, extname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(root, 'dist');
const cache = join(root, 'backend/storage/framework/cache');
mkdirSync(cache, { recursive: true });
const temp = mkdtempSync(join(cache, 'email-verification-ui-'));
const profile = join(temp, 'profile');
const proof = 'synthetic-ui-proof-with-no-real-signature';
const user = { id: 1, name: 'Synthetic Candidate', email: 'candidate@example.test', role: 'applicant', status: 'active',
  email_verified: false, email_verification_required: true, email_verification_available: true };
const job = { id: 1, title: 'Safe synthetic job', company: 'Synthetic Company', location: 'Manila', type: 'Contract',
  mode: 'Remote', description: 'Synthetic browser test listing.', accommodations: [], employerId: 1 };
let signedIn = true;
let privateCalls = 0;
let confirmations = 0;
let limited = false;
let browser;
let client;

const server = createServer(async (request, response) => {
  const path = new URL(request.url, 'http://localhost').pathname;
  response.setHeader('Cache-Control', 'no-store');
  if (path.startsWith('/api/')) {
    response.setHeader('Content-Type', 'application/json');
    let raw = '';
    for await (const chunk of request) raw += chunk;
    const body = raw ? JSON.parse(raw) : {};
    function reply(status, payload = {}) { response.writeHead(status); response.end(JSON.stringify(payload)); }
    if (path === '/api/csrf-cookie') { response.writeHead(204); response.end(); return; }
    if (path === '/api/user') { reply(signedIn ? 200 : 401, signedIn ? { user } : { message: 'Unauthenticated.' }); return; }
    if (path === '/api/login') { signedIn = true; reply(200, { user }); return; }
    if (path === '/api/logout') { signedIn = false; reply(200, { message: 'Logged out.' }); return; }
    if (path === '/api/jobs') { reply(200, { jobs: [job], meta: { total: 1, current_page: 1, last_page: 1 } }); return; }
    if (path === '/api/jobs/1') { reply(200, { job }); return; }
    if (path === '/api/email/verification/confirm') {
      confirmations++;
      assert.equal(body.proof, proof, 'The proof must be submitted only in the JSON body');
      assert.equal(request.url.includes(proof), false);
      user.email_verified = true;
      user.email_verification_required = false;
      reply(200, { message: 'Email address verified.' }); return;
    }
    if (path === '/api/email/verification/resend') {
      if (limited) { response.setHeader('Retry-After', '2'); reply(429, { message: 'Please wait.' }); }
      else reply(202, { message: 'Request accepted. Check your inbox and spam folder.' });
      return;
    }
    privateCalls++;
    reply(403, { message: 'Verify your email address before continuing.', code: 'email_verification_required' });
    return;
  }
  const asset = path.startsWith('/assets/') ? resolve(dist, '.' + path) : join(dist, 'index.html');
  if (!asset.startsWith(resolve(dist) + sep) || !existsSync(asset)) { response.writeHead(404); response.end(); return; }
  response.setHeader('Content-Type', ({ '.js': 'application/javascript', '.css': 'text/css', '.html': 'text/html' })[extname(asset)] || 'application/octet-stream');
  response.end(readFileSync(asset));
});

const delay = (ms) => new Promise((done) => setTimeout(done, ms));
async function waitFor(check, label) {
  const end = Date.now() + 15000;
  while (Date.now() < end) {
    try { if (await check()) return; } catch { /* Document may be navigating. */ }
    await delay(50);
  }
  throw new Error('Timed out: ' + label);
}
class CDP {
  constructor(socket) {
    this.socket = socket; this.serial = 0; this.pending = new Map(); this.exceptions = [];
    socket.onmessage = ({ data }) => {
      const message = JSON.parse(data);
      if (message.id) {
        const pending = this.pending.get(message.id);
        if (pending) {
          this.pending.delete(message.id);
          if (message.error) pending.reject(new Error(message.error.message)); else pending.done(message.result);
        }
      }
      if (message.method === 'Runtime.exceptionThrown') this.exceptions.push(message.params.exceptionDetails.text);
    };
    socket.onclose = ({ code }) => {
      for (const pending of this.pending.values()) pending.reject(new Error('Browser protocol closed: ' + code));
      this.pending.clear();
    };
  }
  send(method, params = {}) {
    return new Promise((done, reject) => {
      const id = ++this.serial;
      this.pending.set(id, { done, reject });
      this.socket.send(JSON.stringify({ id, method, params }));
      setTimeout(() => { if (this.pending.delete(id)) reject(new Error('Browser command timeout: ' + method)); }, 30000).unref();
    });
  }
  async evaluate(expression) {
    const result = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error('Browser evaluation failed');
    return result.result.value;
  }
}

try {
  assert.ok(existsSync(join(dist, 'index.html')), 'Build the frontend first');
  await new Promise((done, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', done); });
  const origin = `http://127.0.0.1:${server.address().port}`;
  const executable = process.env.BROWSER_PATH || [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ].find(existsSync);
  assert.ok(executable, 'Set BROWSER_PATH to Chrome or Edge');
  browser = spawn(executable, ['--headless=new', '--disable-gpu', '--in-process-gpu', '--disable-background-networking', '--disable-component-update',
    '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1',
    `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
  await waitFor(() => existsSync(join(profile, 'DevToolsActivePort')), 'isolated browser startup');
  const port = readFileSync(join(profile, 'DevToolsActivePort'), 'utf8').split(/\r?\n/)[0];
  const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
  const socket = new WebSocket(targets.find((target) => target.type === 'page').webSocketDebuggerUrl);
  await new Promise((done, reject) => { socket.onopen = done; socket.onerror = reject; });
  client = new CDP(socket);
  await client.send('Page.enable');
  await client.send('Runtime.enable');
  await client.send('Network.enable');
  await client.send('Network.setBlockedURLs', { urls: ['https://*'] });
  async function go(path, condition) {
    await client.send('Page.navigate', { url: origin + path });
    await waitFor(() => client.evaluate(condition), path.split('#')[0]);
  }
  async function click(text) {
    const expression = `[...document.querySelectorAll('main button')].find(button => button.textContent.trim() === ${JSON.stringify(text)} && !button.disabled)`;
    await waitFor(() => client.evaluate(`Boolean(${expression})`), 'enabled ' + text);
    await client.evaluate(`(${expression}).click()`);
  }
  await go('/jobs', "document.body.innerText.includes('Safe synthetic job')");
  await go('/jobs/1', "document.body.innerText.includes('You can keep browsing this listing.')");
  assert.equal(privateCalls, 0);
  assert.ok(await client.evaluate("[...document.querySelectorAll('main button')].filter(button => ['Save job','Apply now'].includes(button.textContent.trim())).every(button => button.disabled)"));
  await go('/', "document.body.innerText.includes('Safe synthetic job')");
  assert.equal(privateCalls, 0);
  console.log('PASS pending users browse home, search and job details without protected prefetches');

  await go('/applicant', "location.pathname === '/verify-email' && document.body.innerText.includes('Signed in as')");
  await go('/verify-email#proof=' + encodeURIComponent(proof), "[...document.querySelectorAll('main button')].some(button => button.textContent === 'Confirm email')");
  await waitFor(() => client.evaluate("location.hash === ''"), 'fragment removal');
  assert.equal(confirmations, 0);
  assert.ok(await client.evaluate(`!JSON.stringify([localStorage,sessionStorage,history.state]).includes(${JSON.stringify(proof)})`));
  await click('Log out');
  await waitFor(() => client.evaluate("Boolean(document.querySelector('main form input[type=email]'))"), 'inline login');
  for (const [type, value] of [['email', user.email], ['password', 'synthetic-password']]) {
    await client.evaluate(`(() => { const input = document.querySelector('main input[type=${type}]'); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(input,${JSON.stringify(value)}); input.dispatchEvent(new Event('input',{bubbles:true})); })()`);
  }
  await click('Sign in');
  await waitFor(() => client.evaluate("document.body.innerText.includes('Signed in as')"), 'matching-account login');
  await click('Confirm email');
  await waitFor(() => client.evaluate("document.body.innerText.includes('Your email address is verified.')"), 'confirmation status refresh');
  assert.equal(confirmations, 1);
  assert.ok(await client.evaluate("[...document.querySelectorAll('main a')].some(link => link.textContent === 'Continue to your account' && link.pathname === '/applicant')"));
  console.log('PASS protected redirect, explicit confirmation, fragment privacy and logout/login proof retention');

  user.email_verified = false; user.email_verification_required = true;
  await go('/verify-email', "document.body.innerText.includes('Your email address is verified.') === false && document.body.innerText.includes('Resend verification email')");
  await click('Resend verification email');
  await waitFor(() => client.evaluate("[...document.querySelectorAll('main button')].some(button => button.textContent.startsWith('Resend available in') && button.disabled)"), 'send cooldown');
  limited = true;
  await go('/verify-email', "document.body.innerText.includes('Resend verification email')");
  await click('Resend verification email');
  await waitFor(() => client.evaluate("document.body.innerText.includes('Too many attempts.') && [...document.querySelectorAll('main button')].some(button => button.textContent.startsWith('Resend available in') && button.disabled)"), 'retry header cooldown');
  console.log('PASS accepted resend and server Retry-After cooldown feedback');

  user.email_verification_required = false;
  await go('/verify-email', "document.body.innerText.includes('Your existing account access is unchanged.')");
  assert.ok(await client.evaluate("!document.body.innerText.includes('Your email address is verified.')"));
  assert.deepEqual(client.exceptions, []);
  console.log('PASS legacy access is preserved without a false ownership label; no JavaScript exceptions');
  console.log('PASS browser verification smoke with mocked API only; no database or provider access');
} finally {
  if (client?.socket.readyState === WebSocket.OPEN) {
    await client.send('Browser.close').catch(() => {});
    client.socket.close();
  }
  if (browser && browser.exitCode === null) {
    await Promise.race([new Promise((done) => browser.once('exit', done)), delay(3000)]);
    if (browser.exitCode === null) browser.kill();
  }
  server.closeAllConnections();
  await new Promise((done) => server.close(done));
  assert.ok(resolve(temp).startsWith(resolve(cache) + sep), 'Cleanup must stay in the test cache');
  rmSync(temp, { recursive: true, force: true, maxRetries: 20, retryDelay: 100 });
}
