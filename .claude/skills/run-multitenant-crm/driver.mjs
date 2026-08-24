#!/usr/bin/env node
/**
 * Driver per il pannello Filament del CRM multitenant.
 *
 * Fa il login, risolve da solo il prefisso di tenant nelle route
 * (/admin/<tenant>/...) e permette di aprire una risorsa, leggere la
 * tabella, aprire un form e leggerne i campi — sempre con screenshot.
 *
 * Playwright non sta nel repo: si installa una volta in una cartella
 * fuori dal progetto e si passa via PW_MODULES (vedi SKILL.md).
 *
 *   node .claude/skills/run-multitenant-crm/driver.mjs smoke
 *   node .claude/skills/run-multitenant-crm/driver.mjs open information-requests
 *   node .claude/skills/run-multitenant-crm/driver.mjs form information-requests/create
 *   node .claude/skills/run-multitenant-crm/driver.mjs shot /admin/alex/customers
 */
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const require = createRequire(import.meta.url);
const PW = process.env.PW_MODULES || `${process.env.HOME}/.cache/multitenant-crm-driver/node_modules`;

let chromium;
try {
  ({ chromium } = require(resolve(PW, 'playwright')));
} catch {
  console.error(
    `\n✗ Playwright non trovato in ${PW}\n\n` +
    `  mkdir -p ~/.cache/multitenant-crm-driver && \\\n` +
    `    (cd ~/.cache/multitenant-crm-driver && npm i playwright) && \\\n` +
    `    npx playwright install chromium\n`
  );
  process.exit(2);
}

const BASE  = process.env.CRM_URL      || 'http://localhost:8092';
const EMAIL = process.env.CRM_EMAIL    || 'admin@test.it';
const PASS  = process.env.CRM_PASSWORD || 'password';
const OUT   = process.env.CRM_SHOTS    || '/tmp/crm-shots';

const [cmd = 'smoke', arg] = process.argv.slice(2);
const log = (...a) => console.log('·', ...a);
mkdirSync(OUT, { recursive: true });

/** Il pannello e' Livewire: dopo un'azione serve lasciar sedimentare il DOM. */
const settle = async (page) => {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(400);
};

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
const page = await ctx.newPage();

const errs = [];
page.on('console', (m) => m.type() === 'error' && errs.push(m.text()));
page.on('pageerror', (e) => errs.push(`pageerror: ${e.message}`));

let failed = false;
const fail = (msg) => { console.error('✗', msg); failed = true; };

/** Login + scoperta del prefisso di tenant. Ritorna es. "/admin/alex". */
async function login() {
  const res = await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  if (!res?.ok()) throw new Error(`login page HTTP ${res?.status()} — l'app e' su? (docker compose up -d)`);

  await page.fill('input[type="email"]', EMAIL);
  await page.fill('input[type="password"]', PASS);
  await Promise.all([
    page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30000 }),
    page.click('button[type="submit"]'),
  ]);

  const prefix = new URL(page.url()).pathname.replace(/\/$/, '');
  log('login OK, prefisso tenant:', prefix);
  return prefix;
}

/** "information-requests" -> "/admin/alex/information-requests"; i path assoluti passano intatti. */
const url = (prefix, p) => (p.startsWith('/') ? `${BASE}${p}` : `${BASE}${prefix}/${p}`);

async function dumpTable() {
  const h1 = await page.locator('h1').first().innerText().catch(() => '(nessun h1)');
  log('titolo:', h1.trim());

  const headers = (await page.locator('table thead th').allInnerTexts())
    .map((h) => h.replace(/\s+/g, ' ').trim())
    .filter((h) => h && !/Seleziona\/Deseleziona/i.test(h));
  if (headers.length) log('colonne:', headers.join(' | '));

  const rows = await page.locator('table tbody tr').count();
  if (rows) {
    log('righe visibili:', rows);
    const first = await page.locator('table tbody tr').first().innerText().catch(() => '');
    // Filament apre ogni riga con la label screen-reader della checkbox di
    // selezione massiva: e' rumore, e contiene l'UUID del record.
    const clean = first.replace(/\s+/g, ' ').replace(/^Seleziona.*?azioni di massa\.\s*/i, '').trim();
    log('prima riga:', clean.slice(0, 130));
  }

  const total = await page.locator('text=/Mostrati da .* di .* risultati/').first().innerText().catch(() => null);
  if (total) log('paginazione:', total.replace(/\s+/g, ' ').trim());
}

async function dumpForm() {
  const labels = (await page.locator('form label').allInnerTexts())
    .map((l) => l.replace(/\s+/g, ' ').trim())
    .filter(Boolean);
  const uniq = [...new Set(labels)];
  log('campi:', uniq.join(' | ') || '(nessuno)');
  const required = uniq.filter((l) => l.endsWith('*'));
  if (required.length) log('obbligatori:', required.join(' | '));

  const helpers = (await page.locator('form .fi-fo-field-wrp-hint, form .fi-fo-field-wrp-helper-text').allInnerTexts())
    .map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean);
  if (helpers.length) log('note dei campi:', [...new Set(helpers)].join(' / '));
}

async function shot(name) {
  const path = `${OUT}/${name}.png`;
  await page.screenshot({ path, fullPage: true });
  log('screenshot:', path);
}

try {
  if (cmd === 'smoke') {
    for (const [label, path, want] of [
      ['health',      '/up',           200],
      ['login page',  '/admin/login',  200],
    ]) {
      const r = await page.goto(`${BASE}${path}`);
      const got = r?.status();
      got === want ? log(`${label}: ${got}`) : fail(`${label}: atteso ${want}, ottenuto ${got}`);
    }

    const prefix = await login();
    await settle(page);
    await shot('smoke-dashboard');

    const items = (await page.locator('nav a[href*="/admin/"]').allInnerTexts())
      .map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean);
    log('voci di menu raggiungibili:', items.length);

  } else if (cmd === 'open') {
    if (!arg) throw new Error('uso: open <risorsa>   es. open information-requests');
    const prefix = await login();
    const target = url(prefix, arg);
    const r = await page.goto(target, { waitUntil: 'networkidle' });
    log('aperto:', target, '→', r?.status());
    if (!r?.ok()) fail(`HTTP ${r?.status()} su ${target}`);
    await settle(page);
    await dumpTable();
    await shot(`open-${arg.replace(/\W+/g, '-')}`);

  } else if (cmd === 'form') {
    if (!arg) throw new Error('uso: form <risorsa>/create');
    const prefix = await login();
    const target = url(prefix, arg);
    const r = await page.goto(target, { waitUntil: 'networkidle' });
    log('aperto:', target, '→', r?.status());
    if (!r?.ok()) fail(`HTTP ${r?.status()} su ${target}`);
    await settle(page);
    await dumpForm();
    await shot(`form-${arg.replace(/\W+/g, '-')}`);

  } else if (cmd === 'shot') {
    if (!arg) throw new Error('uso: shot <path>   es. shot /admin/alex/customers');
    const prefix = await login();
    await page.goto(url(prefix, arg), { waitUntil: 'networkidle' });
    await settle(page);
    await shot(`shot-${arg.replace(/\W+/g, '-')}`);

  } else {
    throw new Error(`comando sconosciuto: ${cmd}  (smoke | open | form | shot)`);
  }

  log('errori console JS:', errs.length ? errs.slice(0, 5).join(' | ') : 'nessuno');
} catch (e) {
  fail(e.message);
} finally {
  await browser.close();
}

process.exit(failed ? 1 : 0);
