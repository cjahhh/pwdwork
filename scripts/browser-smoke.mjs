// Real browser + Laravel + SQLite smoke checks, using only throwaway data.
// Requires installed dependencies, PHP, Node 24, and Chrome/Edge (or BROWSER_PATH).
import assert from "node:assert/strict";
import { spawn, spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync, existsSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { resolve, join, dirname, basename } from "node:path";
import { fileURLToPath } from "node:url";
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
  QUEUE_CONNECTION: "database", DB_QUEUE_CONNECTION: "sqlite", PASSWORD_RESET_QUEUE_CONNECTION: "database",
  PASSWORD_RESET_QUEUE: "password-resets", MAIL_MAILER: "log", MAIL_LOG_CHANNEL: "stderr", LOG_CHANNEL: "stderr", BCRYPT_ROUNDS: "4",
  ADMIN_NOTIFICATION_MAIL: "false",
  APP_CONFIG_CACHE: join(temp, "config.php"), APP_ROUTES_CACHE: join(temp, "routes.php"),
};
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
async function waitFor(check, label, timeout = 20000) {
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
      }, 20000).unref();
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
  const previousLoad = client.loadSerial || 0;
  await client.send("Page.navigate", { url: "http://localhost:5173" + path });
  await waitFor(() => (client.loadSerial || 0) > previousLoad, "document loaded " + path);
  await check(`location.pathname === ${JSON.stringify(path.split("?")[0])} && document.querySelector('main') && document.querySelector('header')?.getAttribute('aria-busy') === 'false' && !document.body.innerText.includes('Loading account...')`, "route " + path);
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
  await client.evaluate(`(() => { const button = [...document.querySelectorAll(${JSON.stringify(scope + " button")})].find(el => el.textContent.trim() === ${JSON.stringify(text)} && !el.disabled); if (!button) throw new Error('Missing enabled button: ' + ${JSON.stringify(text)}); button.click(); })()`);
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

try {
  await requireFreePort(8000, "127.0.0.1");
  await requireFreePort(5173, "localhost");
  await requireFreePort(9222, "127.0.0.1");
  const fixture = spawnSync("php", ["tests/browser-fixture.php"], { cwd: backend, env, encoding: "utf8", windowsHide: true });
  assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
  launch("php", ["-S", "127.0.0.1:8000", join(backend, "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php")], join(backend, "public"));
  launch(process.execPath, [join(root, "node_modules/vite/bin/vite.js"), "--host", "localhost", "--port", "5173", "--strictPort"], root,
    { ...process.env, VITE_API_URL: "/api" });
  await waitFor(async () => (await fetch("http://localhost:5173/api/health")).ok, "Vite/API startup");
  const browser = process.env.BROWSER_PATH || [
    "C:/Program Files/Google/Chrome/Application/chrome.exe",
    "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
  ].find(existsSync);
  assert.ok(browser, "Set BROWSER_PATH to Chrome or Edge.");
  launch(browser, ["--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
    "--remote-debugging-port=9222", "--remote-debugging-address=127.0.0.1", `--user-data-dir=${join(temp, "profile")}`, "about:blank"]);
  let target;
  await waitFor(async () => { target = (await (await fetch("http://127.0.0.1:9222/json/list")).json()).find((t) => t.type === "page"); return target; }, "browser startup");
  const socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((done, reject) => { socket.onopen = done; socket.onerror = reject; });
  client = new CDP(socket);
  await client.send("Page.enable");
  await client.send("Runtime.enable");
  await client.send("Network.enable");

  await go("/jobs");
  await check("document.body.innerText.includes('27 matching jobs')", "job results");
  await click("Next page", 'nav[aria-label="Job search pages"]');
  await check("document.body.innerText.includes('Page 2 of 3')", "second job page");
  await client.evaluate("[...document.querySelectorAll('main label')].find(el => el.textContent.trim() === 'Contract').querySelector('input').click()");
  await check("document.body.innerText.includes('11 matching jobs') && document.body.innerText.includes('Page 1 of 1')", "contract filter resets pagination");
  passed("public search pagination and Contract filter");

  await login("candidate@browser.example");
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
  await go("/employer/listings");
  await check("document.body.innerText.includes('Page 1 of 3')", "employer listing pages");
  await click("Next page", 'nav[aria-label="Employer listing pages"]');
  await check("document.body.innerText.includes('Page 2 of 3')", "employer listing next");
  await go("/employer/applicants");
  await check("document.body.innerText.includes('41 results')", "employer applications loaded");
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
  await click("Create account");
  await check("location.pathname === '/jobs' && document.body.innerText.includes('Log out')", "browser registration");
  const storage = await client.evaluate("Object.keys(localStorage)");
  assert.ok(storage.every((key) => !/token|auth|profile|application/i.test(key)), "Sensitive data in localStorage");
  await logout();
  await go("/forgot-password");
  await fill('main input[type="email"]', "registered@browser.example");
  await click("Send reset link");
  await check("document.body.innerText.includes('Request accepted.')", "queued recovery accepted");
  const worker = spawnSync("php", ["artisan", "queue:work", "database", "--queue=password-resets", "--once", "--tries=1"],
    { cwd: backend, env, windowsHide: true, encoding: "utf8" });
  assert.equal(worker.status, 0, worker.stderr || worker.stdout);
  const mail = worker.stderr + worker.stdout;
  const token = mail.match(/token[=3D]+([a-f0-9]{64})/i)?.[1];
  assert.ok(token, "Worker did not log a reset token");
  await go(`/reset-password?token=${token}&email=registered%40browser.example`);
  await fill('main input[type="password"]', 'changed-password');
  await fill('main label:last-of-type input[type="password"]', 'changed-password');
  await click("Reset password");
  await check("document.body.innerText.includes('Password reset. Sign in')", "password reset complete");
  await login("registered@browser.example", "changed-password");
  await logout();
  passed("browser registration, queued log-mail recovery, reset and new-password login");

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
  console.log("Browser smoke checks passed. Only isolated temporary SQLite fixtures were used.");
} catch (error) {
  if (client) {
    try { console.error((await client.evaluate("document.body.innerText")).slice(0, 2500)); } catch { /* Browser may have exited. */ }
  }
  console.error(error);
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
    catch { console.error("Temporary browser files remain at " + temp); }
  }
}
