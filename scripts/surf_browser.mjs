#!/usr/bin/env node
import dns from 'node:dns/promises';
import process from 'node:process';
import { chromium } from 'playwright';

const action = process.argv[2] ?? 'status';
const sessionDir = process.argv[3];
const rawPayload = process.argv[4] ?? '{}';

if (!sessionDir) {
  console.error(JSON.stringify({ ok: false, error: 'Missing session directory.' }));
  process.exit(1);
}

const payload = parsePayload(rawPayload);
const viewport = normalizeViewport(payload.viewport ?? {});

try {
  const result = await withBrowserSession(sessionDir, viewport, async ({ page }) => {
    switch (action) {
      case 'navigate':
        return await navigate(page, String(payload.url ?? ''));
      case 'status':
        return await snapshot(page);
      case 'click':
        return await click(page, payload);
      case 'type':
        return await typeText(page, String(payload.text ?? ''));
      case 'key':
        return await pressKey(page, String(payload.key ?? ''));
      case 'scroll':
        return await scrollPage(page, Number(payload.deltaY ?? 0));
      default:
        throw new Error(`Unsupported action: ${action}`);
    }
  });

  process.stdout.write(`${JSON.stringify({ ok: true, ...result })}\n`);
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.stdout.write(`${JSON.stringify({ ok: false, error: error instanceof Error ? error.message : String(error) })}\n`);
  process.exit(1);
}

function parsePayload(rawPayload) {
  try {
    return JSON.parse(rawPayload);
  } catch {
    return {};
  }
}

function normalizeViewport(viewport) {
  const width = clampNumber(viewport.width, 390, 320, 1600);
  const height = clampNumber(viewport.height, 844, 320, 2400);
  return { width, height };
}

function clampNumber(value, fallback, min, max) {
  const number = Number(value);
  if (!Number.isFinite(number)) {
    return fallback;
  }
  return Math.min(max, Math.max(min, Math.round(number)));
}

async function withBrowserSession(userDataDir, viewport, callback) {
  const context = await chromium.launchPersistentContext(userDataDir, {
    headless: true,
    viewport,
    deviceScaleFactor: 1,
    ignoreHTTPSErrors: false,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 LocalChatSurf/2.0',
  });

  await context.route('**/*', async (route) => {
    const requestUrl = route.request().url();
    const allowed = await isAllowedUrl(requestUrl);
    if (!allowed.ok) {
      await route.abort();
      return;
    }
    await route.continue();
  });

  try {
    const page = context.pages()[0] ?? await context.newPage();
    await page.setViewportSize(viewport);
    return await callback({ context, page });
  } finally {
    await context.close();
  }
}

async function navigate(page, url) {
  if (!url.trim()) {
    return snapshot(page);
  }

  const allowed = await isAllowedUrl(url);
  if (!allowed.ok) {
    throw new Error(allowed.error);
  }

  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.waitForTimeout(800);
  return snapshot(page);
}

async function click(page, payload) {
  await page.mouse.click(Number(payload.x ?? 0), Number(payload.y ?? 0));
  await page.waitForTimeout(600);
  return snapshot(page);
}

async function typeText(page, text) {
  if (!text) {
    return snapshot(page);
  }
  await page.keyboard.type(text, { delay: 20 });
  await page.waitForTimeout(350);
  return snapshot(page);
}

async function pressKey(page, key) {
  if (!key) {
    return snapshot(page);
  }
  await page.keyboard.press(key);
  await page.waitForTimeout(600);
  return snapshot(page);
}

async function scrollPage(page, deltaY) {
  await page.mouse.wheel(0, deltaY);
  await page.waitForTimeout(300);
  return snapshot(page);
}

async function snapshot(page) {
  const title = await page.title().catch(() => 'Surf Mode');
  const url = page.url() || '';
  const screenshot = await page.screenshot({ type: 'png' });
  const viewportSize = page.viewportSize() ?? { width: 390, height: 844 };
  return {
    title: title || 'Surf Mode',
    url,
    viewport: viewportSize,
    screenshot: screenshot.toString('base64'),
  };
}

async function isAllowedUrl(rawUrl) {
  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch {
    return { ok: false, error: 'The URL is invalid.' };
  }

  if (!['http:', 'https:'].includes(parsed.protocol)) {
    return { ok: false, error: 'Only http and https URLs are allowed.' };
  }

  if (parsed.port && !['80', '443'].includes(parsed.port)) {
    return { ok: false, error: 'Only ports 80 and 443 are allowed.' };
  }

  const host = parsed.hostname.toLowerCase();
  if (host === 'localhost' || host.endsWith('.localhost')) {
    return { ok: false, error: 'Local addresses are blocked.' };
  }

  const addresses = await resolveHost(host);
  if (addresses.length === 0) {
    return { ok: false, error: 'Could not resolve that host.' };
  }

  for (const ip of addresses) {
    if (isPrivateOrReservedIp(ip)) {
      return { ok: false, error: 'Private or reserved network targets are blocked.' };
    }
  }

  return { ok: true };
}

async function resolveHost(host) {
  if (isIpAddress(host)) {
    return [host];
  }

  try {
    const results = await dns.lookup(host, { all: true, verbatim: true });
    return results.map((entry) => entry.address);
  } catch {
    return [];
  }
}

function isIpAddress(host) {
  return /^\d+\.\d+\.\d+\.\d+$/.test(host) || host.includes(':');
}

function isPrivateOrReservedIp(ip) {
  if (ip.includes(':')) {
    const normalized = ip.toLowerCase();
    return normalized === '::1'
      || normalized.startsWith('fc')
      || normalized.startsWith('fd')
      || normalized.startsWith('fe8')
      || normalized.startsWith('fe9')
      || normalized.startsWith('fea')
      || normalized.startsWith('feb');
  }

  const parts = ip.split('.').map((part) => Number(part));
  if (parts.length !== 4 || parts.some((part) => !Number.isInteger(part) || part < 0 || part > 255)) {
    return true;
  }

  const [a, b] = parts;
  if (a === 10 || a === 127 || a === 0) {
    return true;
  }
  if (a === 169 && b === 254) {
    return true;
  }
  if (a === 172 && b >= 16 && b <= 31) {
    return true;
  }
  if (a === 192 && b === 168) {
    return true;
  }
  if (a >= 224) {
    return true;
  }
  return false;
}
