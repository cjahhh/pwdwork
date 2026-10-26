// Optional Windows host check of the container's Nginx routing and FastCGI contract.
// Uses portable Nginx + local PHP-CGI, synthetic settings, and no database or mail.
// This does not replace building/testing the Linux Docker image.
// Default: Render client-IP expression. --direct: socket client-IP expression.
// Both modes retain synthetic HTTPS so the existing cookie checks still apply.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { request as httpRequest } from 'node:http';
import { mkdtempSync, readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { resolve, dirname, join, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const backend = join(root, 'backend');
const cache = join(backend, 'storage/framework/cache');
const temp = mkdtempSync(join(cache, 'render-nginx-smoke-'));
const nginx = process.env.NGINX_PATH;
assert.ok(nginx, 'Set NGINX_PATH to an official portable nginx.exe.');
assert.equal(process.platform, 'win32', 'This host adapter is for Windows; test Linux with Docker.');
const phpCgi = process.env.PHP_CGI_PATH || 'C:/xampp/php/php-cgi.exe';
const port = 18481;
const fpmPort = 19001;
const renderMode = !process.argv.includes('--direct');
const socketIp = '127.0.0.1';
const effectiveIp = (headerIp) => renderMode ? headerIp : socketIp;
const slash = (path) => path.replaceAll('\\', '/');
const prefix = `${slash(temp)}/`;
const config = join(temp, 'nginx.conf');
const sleep = (ms) => new Promise((done) => setTimeout(done, ms));
const processes = [];
let nginxStarted = false;
let output = '';
function launch(command, args, env = process.env) {
  const child = spawn(command, args, { cwd: root, env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
  child.stdout.on('data', (chunk) => { output += chunk; });
  child.stderr.on('data', (chunk) => { output += chunk; });
  processes.push(child);
  return child;
}

try {
  for (const directory of ['client', 'fastcgi', 'proxy', 'uwsgi', 'scgi', 'logs']) mkdirSync(join(temp, directory));
  for (const directory of ['framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
    mkdirSync(join(temp, 'storage', directory), { recursive: true });
  }
  writeFileSync(join(temp, 'mime.types'), readFileSync(join(dirname(nginx), 'conf/mime.types')));
  // Test-only front controller: inspect Symfony's final request metadata after
  // Laravel middleware. This file is deleted and never copied to the image.
  const frontController = join(temp, 'index.php');
  writeFileSync(frontController, `<?php
require '${slash(backend)}/vendor/autoload.php';
$app = require '${slash(backend)}/bootstrap/app.php';
$app->useStoragePath('${slash(join(temp, 'storage'))}');
$kernel = $app->make(\\Illuminate\\Contracts\\Http\\Kernel::class);
$kernel->bootstrap();
// Local-only probes: real Laravel throttles, no credentials or database access.
\\Illuminate\\Support\\Facades\\RateLimiter::for('proxy-probe', fn ($request) =>
    \\Illuminate\\Cache\\RateLimiting\\Limit::perMinute(2)->by($request->query('scenario').':'.$request->ip()));
\\Illuminate\\Support\\Facades\\Route::get('/api/proxy-rate-probe', fn () => response()->json(['ok' => true]))
    ->middleware(['api', 'throttle:proxy-probe']);
\\Illuminate\\Support\\Facades\\Route::get('/api/account-rate-probe', fn () => response()->json(['ok' => true]))
    ->middleware(['api', 'throttle:login-account']);
$request = \\Illuminate\\Http\\Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Test-Client-IP', $request->ip());
$response->headers->set('X-Test-Request-URL', $request->url());
$response->send();
$kernel->terminate($request, $response);
`);
  const server = readFileSync(join(root, 'deploy/docker/nginx.conf.template'), 'utf8')
    .replace(/^user www-data;\r?\n/m, '') // Windows has no Unix users.
    .replaceAll('/etc/nginx', slash(temp))
    .replaceAll('/tmp/nginx', slash(temp))
    .replace('/dev/stderr', slash(join(temp, 'nginx-error.log')))
    .replace('/var/www/frontend', slash(join(root, 'dist')))
    .replace('${PORT}', String(port))
    .replace(`0.0.0.0:${port}`, `127.0.0.1:${port}`);
  writeFileSync(config, server);
  const fastcgi = readFileSync(join(root, 'deploy/docker/fastcgi-app.conf'), 'utf8')
    .replaceAll('/var/www/backend', slash(backend))
    .replace(`${slash(backend)}/public/index.php`, slash(frontController))
    .replace('__PWDWORK_HTTPS__', 'on')
    .replace('__PWDWORK_SERVER_PORT__', '443')
    .replace('__PWDWORK_CLIENT_IP__', renderMode ? '$render_client_ip' : '$remote_addr')
    .replace('127.0.0.1:9000', `127.0.0.1:${fpmPort}`);
  writeFileSync(join(temp, 'fastcgi-app.conf'), fastcgi);
  const check = spawnSync(nginx, ['-t', '-p', prefix, '-c', slash(config)], { windowsHide: true, encoding: 'utf8' });
  assert.equal(check.status, 0, check.stderr);
  console.log('PASS Nginx template syntax (Windows host path/user adaptation)');

  // Do not inherit project .env settings. No request below needs any database.
  const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false',
    APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    APP_URL: 'https://render-test.example', FRONTEND_URL: 'https://render-test.example',
    CORS_ALLOWED_ORIGINS: 'https://render-test.example', SANCTUM_STATEFUL_DOMAINS: 'render-test.example',
    TRUSTED_PROXIES: '', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '',
    CACHE_STORE: 'file', CACHE_PREFIX: 'isolated-proxy-smoke', SESSION_DRIVER: 'array', SESSION_CONNECTION: 'sqlite',
    SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'true', SESSION_HTTP_ONLY: 'true',
    QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', PASSWORD_RESET_MAILER: 'array',
    MAIL_PASSWORD: 'synthetic-only', MAIL_USERNAME: 'synthetic-only', MAIL_URL: '',
    BREVO_API_KEY: 'synthetic-only', LOG_CHANNEL: 'stderr', ADMIN_NOTIFICATION_MAIL: 'false',
    APP_CONFIG_CACHE: join(temp, 'config.php'), APP_ROUTES_CACHE: join(temp, 'routes.php'),
    PHP_FCGI_MAX_REQUESTS: '200',
  };
  launch(phpCgi, ['-b', `127.0.0.1:${fpmPort}`, '-d', 'cgi.force_redirect=0', '-d', 'expose_php=0'], env);
  launch(nginx, ['-p', prefix, '-c', slash(config)]);
  nginxStarted = true;
  const base = `http://127.0.0.1:${port}`;
  for (let attempt = 0; ; attempt++) {
    try {
      const response = await fetch(`${base}/up`);
      if (response.ok) break;
    } catch { /* Processes are starting. */ }
    assert.ok(attempt < 100, 'Nginx / PHP-CGI did not start.');
    await sleep(100);
  }
  console.log('PASS boot health through Nginx and real Laravel FastCGI, without a database');

  for (const path of ['/jobs', '/jobs/123', '/forgot-password', '/reset-password', '/applicant/saved', '/employer/listings', '/admin/users']) {
    const response = await fetch(base + path);
    assert.equal(response.status, 200, path);
    assert.match(await response.text(), /<div id="root"><\/div>/, path);
    assert.equal(response.headers.get('referrer-policy'), 'strict-origin');
    assert.match(response.headers.get('content-security-policy'), /connect-src 'self'/);
  }
  for (const path of ['/assets/nonexistent.js', '/.env', '/backend/.env', '/vendor/autoload.php', '/anything.php', '/database.sqlite', '/api/private.php']) {
    const response = await fetch(base + path);
    assert.equal(response.status, 404, path);
    assert.doesNotMatch(await response.text(), /<div id="root">/, path);
  }
  const html = readFileSync(join(root, 'dist/index.html'), 'utf8');
  const asset = html.match(/src="(\/assets\/[^"]+\.js)"/)[1];
  const assetResponse = await fetch(base + asset);
  assert.equal(assetResponse.status, 200);
  assert.match(assetResponse.headers.get('cache-control'), /max-age=31536000/);
  console.log('PASS seven SPA deep links, eight private/missing-file denials, hashed assets and headers');

  const headers = { origin: 'https://render-test.example', accept: 'application/json',
    'x-forwarded-for': '198.51.100.200', 'x-forwarded-host': 'untrusted.example',
    'x-forwarded-proto': 'http', forwarded: 'for=198.51.100.200;host=untrusted.example;proto=http',
    'cf-connecting-ip': '203.0.113.9',
    'x-real-ip': '198.51.100.201',
  };
  const health = await fetch(`${base}/api/health`, { headers });
  assert.equal(health.status, 200);
  assert.equal(health.headers.get('x-test-client-ip'), effectiveIp('203.0.113.9'));
  assert.equal(health.headers.get('x-test-request-url'), `${base.replace('http:', 'https:')}/api/health`);
  assert.deepEqual(await health.json(), { status: 'ok', app: 'PWDWork' });
  const forged = await fetch(`${base}/api/health`, { headers: { ...headers,
    'x-forwarded-for': '192.0.2.77', 'x-forwarded-host': 'forged.example', 'x-forwarded-proto': 'http', 'x-real-ip': '192.0.2.78',
  } });
  assert.equal(forged.headers.get('x-test-client-ip'), effectiveIp('203.0.113.9'));
  assert.equal(forged.headers.get('x-test-request-url'), `${base.replace('http:', 'https:')}/api/health`);
  for (const [input, expected] of [
    ['2001:db8::5', effectiveIp('2001:db8::5')],
    ['invalid-address', socketIp], ['999.999.999.999', socketIp], ['', socketIp],
    ['203.0.113.4, 203.0.113.5', socketIp], ['203.0.113.4:1234', socketIp], ['[2001:db8::5]', socketIp],
  ]) {
    const response = await fetch(`${base}/api/health`, { headers: { ...headers, 'cf-connecting-ip': input } });
    assert.equal(response.headers.get('x-test-client-ip'), expected);
  }
  const missingCf = { ...headers };
  delete missingCf['cf-connecting-ip'];
  const missing = await fetch(`${base}/api/health`, { headers: missingCf });
  assert.equal(missing.headers.get('x-test-client-ip'), socketIp);
  const duplicate = await new Promise((done, reject) => {
    // Send two actual header lines, rather than a pre-combined comma list.
    const request = httpRequest(`${base}/api/health`, { headers: { ...headers,
      'cf-connecting-ip': ['203.0.113.4', '203.0.113.5'],
    } }, (response) => {
      response.resume();
      response.once('end', () => done(response));
      response.once('error', reject);
    });
    request.once('error', reject);
    request.end();
  });
  assert.equal(duplicate.statusCode, 200);
  assert.equal(duplicate.headers['x-test-client-ip'], socketIp, 'Duplicate CF headers must not select an arbitrary IP');

  for (let i = 1; i <= 3; i++) {
    const response = await fetch(`${base}/api/proxy-rate-probe?scenario=forwarded`, { headers: { ...headers,
      'x-forwarded-for': `192.0.2.${i}`, 'x-real-ip': `192.0.2.${i + 10}`,
      forwarded: `for=192.0.2.${i};proto=http`,
    } });
    assert.equal(response.status, i <= 2 ? 200 : 429, 'Forged forwarding headers must share the IP bucket');
    if (i === 3) assert.ok(Number(response.headers.get('retry-after')) > 0);
  }
  console.log('PASS real IP throttle rejects rotating Forwarded/X-Forwarded-For/X-Real-IP (no login attempts)');

  for (let i = 1; i <= 3; i++) {
    const response = await fetch(`${base}/api/proxy-rate-probe?scenario=cf-boundary`, { headers: { ...headers,
      'cf-connecting-ip': `198.51.100.${i}`,
    } });
    assert.equal(response.headers.get('x-test-client-ip'), effectiveIp(`198.51.100.${i}`));
    assert.equal(response.status, renderMode || i <= 2 ? 200 : 429);
  }
  console.log(renderMode
    ? 'OBSERVED Render mode accepts caller-selected valid CF-Connecting-IP at the container boundary; edge sanitization is required'
    : 'PASS direct mode ignores rotating CF-Connecting-IP and preserves the socket IP throttle');

  for (let i = 1; i <= 11; i++) {
    const response = await fetch(`${base}/api/account-rate-probe?email=synthetic@example.test`, { headers: { ...headers,
      'cf-connecting-ip': `198.51.100.${i + 30}`,
    } });
    assert.equal(response.status, i <= 10 ? 200 : 429, 'Account limiter must survive any client IP selection');
  }
  console.log('PASS real account throttle survives rotating CF-Connecting-IP (synthetic GET probe only)');
  const unknown = await fetch(`${base}/api/nonexistent`, { headers });
  assert.equal(unknown.status, 404);
  assert.match(unknown.headers.get('content-type'), /application\/json/);
  const csrf = await fetch(`${base}/sanctum/csrf-cookie`, { headers });
  assert.equal(csrf.status, 204);
  const cookies = csrf.headers.getSetCookie();
  assert.ok(cookies.some((cookie) => cookie.startsWith('XSRF-TOKEN=') && /; secure/i.test(cookie)));
  assert.ok(cookies.some((cookie) => /; httponly/i.test(cookie) && /; secure/i.test(cookie)));
  assert.ok(cookies.every((cookie) => !/; domain=/i.test(cookie)));
  assert.equal(csrf.headers.get('access-control-allow-origin'), 'https://render-test.example');
  console.log('PASS API JSON, Laravel 404, same-origin CORS and secure host-only Sanctum cookies');
  console.log(`PASS ${renderMode ? 'Render' : 'direct'} client-IP mode; malformed/missing/list/duplicate CF headers fall back to socket IP`);
  console.log('PASS Nginx host smoke; no MySQL connection, migrations, real email or real credentials used');
} catch (error) {
  console.error(error.message);
  console.error(output);
  process.exitCode = 1;
} finally {
  if (nginxStarted) spawnSync(nginx, ['-s', 'quit', '-p', prefix, '-c', slash(config)], { windowsHide: true, stdio: 'ignore' });
  await sleep(500);
  for (const child of processes) if (child.exitCode === null) child.kill();
  assert.ok(resolve(temp).startsWith(resolve(cache) + sep), 'Cleanup must remain inside the framework test cache.');
  rmSync(temp, { recursive: true, force: true });
}
