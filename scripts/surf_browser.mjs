#!/usr/bin/env node
import crypto from 'node:crypto';
import net from 'node:net';
import os from 'node:os';
import { createRequire } from 'node:module';
import { spawn } from 'node:child_process';

const SERVICE_HOST = process.env.CHAT_SURF_BROWSER_HOST || '127.0.0.1';
const SERVICE_PORT = Number(process.env.CHAT_SURF_BROWSER_PORT || 38555);
const BROWSER_EXECUTABLE_PATH = process.env.CHAT_SURF_BROWSER_EXECUTABLE_PATH || undefined;

const sessions = new Map();

let playwright = null;
let browser = null;
let startupError = null;

function shouldAttemptBrowserInstall(error) {
  const message = error instanceof Error ? error.message : String(error || '');
  return message.includes("Executable doesn't exist");
}

function runProcess(command, args) {
  return new Promise((resolve) => {
    const child = spawn(command, args, { stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';

    child.stdout.on('data', (chunk) => {
      stdout += chunk.toString('utf8');
    });
    child.stderr.on('data', (chunk) => {
      stderr += chunk.toString('utf8');
    });
    child.on('error', (error) => {
      resolve({ ok: false, error: error instanceof Error ? error.message : String(error), stdout, stderr, exitCode: -1 });
    });
    child.on('close', (exitCode) => {
      resolve({ ok: exitCode === 0, stdout, stderr, exitCode: exitCode ?? -1 });
    });
  });
}

async function installPlaywrightBrowser() {
  const installEnabled = String(process.env.CHAT_SURF_PLAYWRIGHT_INSTALL_ON_START || '1').toLowerCase();
  if (['0', 'false', 'off', 'no'].includes(installEnabled)) {
    return { ok: false, skipped: true, reason: 'CHAT_SURF_PLAYWRIGHT_INSTALL_ON_START is disabled' };
  }

  const require = createRequire(import.meta.url);
  try {
    const cliPath = require.resolve('playwright/cli');
    const result = await runProcess(process.execPath, [cliPath, 'install', 'chromium']);
    if (result.ok) {
      return { ok: true, method: 'playwright-cli', stdout: result.stdout, stderr: result.stderr };
    }
  } catch {
    // Fall back to npx when CLI resolution is unavailable.
  }

  const npxResult = await runProcess('npx', ['playwright', 'install', 'chromium']);
  return {
    ok: npxResult.ok,
    method: 'npx',
    stdout: npxResult.stdout,
    stderr: npxResult.stderr,
    exitCode: npxResult.exitCode,
  };
}

async function init() {
  try {
    playwright = await import('playwright');
    browser = await playwright.chromium.launch({
      headless: true,
      executablePath: BROWSER_EXECUTABLE_PATH,
      args: ['--disable-dev-shm-usage'],
    });
    await browser.version();
  } catch (error) {
    if (shouldAttemptBrowserInstall(error)) {
      const installResult = await installPlaywrightBrowser();
      if (installResult.ok) {
        try {
          browser = await playwright.chromium.launch({
            headless: true,
            executablePath: BROWSER_EXECUTABLE_PATH,
            args: ['--disable-dev-shm-usage'],
          });
          await browser.version();
          startupError = null;
          return;
        } catch (retryError) {
          startupError = `Browser startup failed after install attempt: ${retryError instanceof Error ? retryError.message : String(retryError)}`;
          console.error(startupError);
          return;
        }
      }

      startupError = `Browser startup failed: ${error instanceof Error ? error.message : String(error)}. Auto-install failed (${installResult.method || 'unknown'}). ${String(installResult.stderr || installResult.stdout || installResult.reason || '')}`.trim();
      console.error(startupError);
      return;
    }

    startupError = `Browser startup failed: ${error instanceof Error ? error.message : String(error)}`;
    console.error(startupError);
  }
}

function createServiceErrorResponse(message, extra = {}) {
  return {
    ok: false,
    error: message,
    ...extra,
  };
}

function assertHealthy() {
  if (startupError) {
    throw new Error(`${startupError}. Host may disallow browser processes.`);
  }

  if (!browser) {
    throw new Error('Browser is not initialized.');
  }
}

function isBlockedHost(hostname) {
  const host = (hostname || '').toLowerCase().trim();
  if (!host) {
    return true;
  }

  if (host === 'localhost' || host.endsWith('.localhost')) {
    return true;
  }

  if (/^\d+\.\d+\.\d+\.\d+$/.test(host)) {
    const parts = host.split('.').map((part) => Number(part));
    if (parts[0] === 10) return true;
    if (parts[0] === 127) return true;
    if (parts[0] === 169 && parts[1] === 254) return true;
    if (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31) return true;
    if (parts[0] === 192 && parts[1] === 168) return true;
    if (parts[0] === 0) return true;
  }

  if (host.includes(':')) {
    if (host === '::1' || host === '::') return true;
    if (host.startsWith('fe80:') || host.startsWith('fc') || host.startsWith('fd')) return true;
  }

  return false;
}

function validateUrl(rawUrl) {
  let parsed;
  try {
    parsed = new URL(String(rawUrl || ''));
  } catch {
    throw new Error('Invalid URL.');
  }

  if (!['http:', 'https:'].includes(parsed.protocol)) {
    throw new Error('Only http/https URLs are allowed.');
  }

  if (isBlockedHost(parsed.hostname)) {
    throw new Error('Blocked by SSRF policy.');
  }

  return parsed.toString();
}

async function createSession() {
  assertHealthy();
  const context = await browser.newContext({ ignoreHTTPSErrors: false });

  await context.route('**/*', async (route) => {
    const reqUrl = route.request().url();
    try {
      validateUrl(reqUrl);
      await route.continue();
    } catch {
      await route.abort('blockedbyclient');
    }
  });

  const page = await context.newPage();
  const sessionId = crypto.randomUUID();
  sessions.set(sessionId, { context, page, createdAt: Date.now() });
  return { ok: true, session_id: sessionId };
}

function getSession(sessionId) {
  const record = sessions.get(String(sessionId || ''));
  if (!record) {
    throw new Error('Session not found.');
  }
  return record;
}

async function runCommand(command, payload = {}) {
  if (command === 'health') {
    return {
      ok: !startupError,
      status: startupError ? 'degraded' : 'ready',
      startup_error: startupError,
      sessions: sessions.size,
      host: os.hostname(),
    };
  }

  if (command === 'create_session') {
    return createSession();
  }

  if (command === 'status') {
    const { page } = getSession(payload.session_id);
    return { ok: true, session_id: payload.session_id, url: page.url(), title: await page.title() };
  }

  const { page } = getSession(payload.session_id);

  switch (command) {
    case 'navigate': {
      const url = validateUrl(payload.url);
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
      return { ok: true, session_id: payload.session_id, url: page.url(), title: await page.title() };
    }
    case 'click': {
      await page.locator(String(payload.selector || '')).first().click({ timeout: 10000 });
      return { ok: true, session_id: payload.session_id };
    }
    case 'type': {
      await page.locator(String(payload.selector || '')).first().fill(String(payload.text || ''));
      return { ok: true, session_id: payload.session_id };
    }
    case 'key': {
      await page.keyboard.press(String(payload.key || 'Enter'));
      return { ok: true, session_id: payload.session_id };
    }
    case 'scroll': {
      const amount = Number(payload.amount || 640);
      const direction = String(payload.direction || 'down').toLowerCase() === 'up' ? -1 : 1;
      await page.mouse.wheel(0, amount * direction);
      return { ok: true, session_id: payload.session_id };
    }
    case 'snapshot': {
      const imageBase64 = await page.screenshot({ type: 'png', fullPage: false, timeout: 15000 }).then((buf) => buf.toString('base64'));
      return { ok: true, session_id: payload.session_id, image_base64: imageBase64 };
    }
    default:
      throw new Error(`Unsupported command: ${command}`);
  }
}

function handleClient(socket) {
  let buffer = '';

  socket.on('data', async (chunk) => {
    buffer += chunk.toString('utf8');
    const lines = buffer.split('\n');
    buffer = lines.pop() || '';

    for (const line of lines) {
      const message = line.trim();
      if (!message) {
        continue;
      }

      let request;
      try {
        request = JSON.parse(message);
      } catch {
        socket.write(`${JSON.stringify(createServiceErrorResponse('Malformed JSON request.'))}\n`);
        continue;
      }

      try {
        const response = await runCommand(String(request.command || ''), request.payload || {});
        socket.write(`${JSON.stringify(response)}\n`);
      } catch (error) {
        socket.write(`${JSON.stringify(createServiceErrorResponse(error instanceof Error ? error.message : String(error)))}\n`);
      }
    }
  });
}

await init();

const server = net.createServer(handleClient);
server.on('error', (error) => {
  console.error('Surf browser service failed:', error);
  process.exit(1);
});

server.listen(SERVICE_PORT, SERVICE_HOST, () => {
  console.log(`surf_browser service listening on ${SERVICE_HOST}:${SERVICE_PORT}`);
  if (startupError) {
    console.log('Service is running in degraded mode. Use command=health for details.');
  }
});

const shutdown = async () => {
  server.close();
  for (const { context } of sessions.values()) {
    await context.close().catch(() => {});
  }
  sessions.clear();
  if (browser) {
    await browser.close().catch(() => {});
  }
  process.exit(0);
};

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
