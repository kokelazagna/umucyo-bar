/* ============================================================================
   Automated offline tests for Umucyo Bar (BSSBMS).
   Run:  node offline.spec.js
   Covers the required scenarios:
     1. cached pages load while offline (service worker)
     2. 3 sales created offline are queued ("N waiting to sync")
     3. reload while offline keeps the queue
     4. going online syncs each sale EXACTLY ONCE (no duplicates)
     5. a duplicate retry of an already-synced sale creates nothing
     6. a rejected offline action appears in "Needs attention" with its reason
     7. logout clears the cached data and the queue
============================================================================ */
const { chromium } = require('playwright');
const mysql = require('mysql2/promise');

const BASE = 'http://localhost/bssbms3modn';
// Use your own test user: USER/PASS come from the environment (see README.md).
const USER = process.env.BSSBMS_TEST_USER || 'pw_tester';
const PASS = process.env.BSSBMS_TEST_PASS || '';

const results = [];
function check(name, ok, extra) {
  results.push({ name, ok, extra: extra || '' });
  console.log((ok ? 'PASS' : 'FAIL') + '  ' + name + (extra ? '  -> ' + extra : ''));
}

async function salesCount(db, where) {
  const [rows] = await db.query('SELECT COUNT(*) c FROM sales' + (where ? ' WHERE ' + where : ''));
  return rows[0].c;
}

(async () => {
  const db = await mysql.createConnection({ host: 'localhost', user: 'root', database: 'bar_management_db' });
  const startSales = await salesCount(db);

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  page.on('console', m => { if (m.type() === 'error') { console.log('  [console.error]', m.text().slice(0, 160)); } });
  page.on('pageerror', e => console.log('  [pageerror]', e.message.slice(0, 160)));

  // ---------- login ----------
  await page.goto(BASE + '/auth/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="username"]', USER);
  await page.fill('input[name="password"]', PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard**');
  check('login works', true);

  // ---------- register + control the service worker ----------
  console.log('step: goto /pos');
  await page.goto(BASE + '/pos', { waitUntil: 'domcontentloaded', timeout: 25000 }).catch(e => console.log('  goto /pos failed:', e.message.slice(0, 150)));
  await page.evaluate(() => navigator.serviceWorker.ready.then(() => {}));
  await page.waitForFunction(() => navigator.serviceWorker.controller !== null, null, { timeout: 15000 })
    .then(() => check('service worker controls the page', true))
    .catch(() => check('service worker controls the page', false));
  console.log('step: reload (controlled by SW)');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#product-grid .tile', { timeout: 15000 })
    .then(() => check('POS page loads', true))
    .catch(() => check('POS page loads', false, 'no product tiles'));

  // status bar visible + online
  const statusText = await page.textContent('#offline-status #offline-text');
  check('status bar shows Online', /Online/i.test(statusText || ''), statusText);

  // snapshot cached?
  await page.waitForFunction(() =>
    window.OfflineQueue && window.OfflineQueue.snapshot && window.OfflineQueue.snapshot.products.length > 0,
    null, { timeout: 15000 })
    .then(() => check('snapshot cached in IndexedDB (products, cash session)', true))
    .catch(() => check('snapshot cached in IndexedDB', false));

  // ---------- helper: build one offline sale through the UI ----------
  async function offlineSale(tileIndex, qty, method) {
    const tiles = await page.$$('#product-grid .tile:not(.out):not([disabled])');
    await tiles[tileIndex].click();
    if (qty && qty > 1) {
      const row = await page.$('.cart-row:last-child .c-qty');
      await row.fill(String(qty));
      await row.dispatchEvent('input');
    }
    // payment: first pay row defaults to cash; set amount to the shown total
    const total = (await page.textContent('#t-total')).replace(/[^0-9]/g, '');
    const payRows = await page.$$('.pay-row');
    await payRows[0].$eval('.p-amt', (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); }, total);
    if (method === 'mobile_money') {
      await payRows[0].$eval('.p-method', el => { el.value = 'mobile_money'; el.dispatchEvent(new Event('change', { bubbles: true })); });
    }
    await page.click('#btn-complete');
    await page.waitForSelector('#cart-rows .cart-row', { state: 'detached', timeout: 10000 })
      .catch(() => {});
  }

  // ---------- go OFFLINE ----------
  await context.setOffline(true);
  await page.waitForTimeout(500);
  const offText = await page.textContent('#offline-status #offline-text');
  check('status bar shows Offline', /Offline/i.test(offText || ''), offText);

  // 1) reload while offline: the SW must serve the cached POS page
  await page.reload({ waitUntil: 'domcontentloaded' });
  const gridOk = await page.waitForSelector('#product-grid .tile', { timeout: 15000 })
    .then(() => true).catch(() => false);
  check('cached POS page loads while offline', gridOk);

  // 2) three offline sales
  await offlineSale(0, 1, 'cash');           // cash
  await offlineSale(1, 2, 'mobile_money');   // Mobile Money
  await offlineSale(2, 1, 'cash');           // cash
  let barText = await page.textContent('#offline-status #offline-text');
  check('3 sales queued offline ("3 waiting to sync")', /3 waiting to sync/.test(barText || ''), barText);

  // 3) reload while offline: queue must survive
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#offline-status');
  barText = await page.textContent('#offline-status #offline-text');
  check('queue survives an offline reload', /3 waiting to sync/.test(barText || ''), barText);

  // a rejected-by-server action: enqueue directly with a bad category (server will reject)
  await page.evaluate(async () => {
    const form = new FormData();
    return window.OfflineQueue.enqueue('expense_create', {
      category_id: 999999, amount: 5000, description: 'impossible category (test)',
      payment_method: 'cash', expense_date: '2026-11-13'
    }, 'queued for test');
  });
  barText = await page.textContent('#offline-status #offline-text');
  check('bad action queued too (4 waiting)', /4 waiting to sync/.test(barText || ''), barText);

  // ---------- go ONLINE: everything syncs in order ----------
  await context.setOffline(false);
  let syncedStatus = '';
  await page.waitForFunction(() => {
    const t = document.querySelector('#offline-status #offline-text');
    return t && /attention/i.test(t.textContent);
  }, null, { timeout: 40000 }).then(() => { syncedStatus = 'attention'; })
    .catch(async () => { syncedStatus = await page.textContent('#offline-status #offline-text'); });
  check('sync ran after reconnect (status: needs attention)', syncedStatus === 'attention', syncedStatus);

  // give the sync loop a moment to finish all items; the attention panel may
  // auto-open (it blocks clicks) - close it before continuing
  await page.waitForTimeout(1500);
  await page.click('#offline-attention-close').catch(() => {});

  // 4) exactly 3 new sales in the DB (one per queued sale, no duplicates)
  const salesAfterSync = await salesCount(db);
  check('3 offline sales synced exactly once', salesAfterSync === startSales + 3,
    'sales before run: ' + startSales + ', after sync: ' + salesAfterSync);

  // 5) duplicate retry of the first synced sale must create nothing
  const keyBefore = await page.evaluate(async () => {
    // read the first synced sale's key from the sync result toast history is not possible;
    // instead ask the server: push a NEW action with the same payload but a used key.
    return window.__lastSyncedKey || null;
  });
  // Use a known key from sync_log for the duplicate test:
  const [rows] = await db.query("SELECT client_id FROM sync_log WHERE action_type = 'pos_sale' AND status = 'accepted' ORDER BY id DESC LIMIT 1");
  const dupKey = rows.length ? rows[0].client_id : null;
  if (dupKey) {
    const dupResult = await page.evaluate(async (key) => {
      const boot = JSON.parse(document.getElementById('offline-boot').textContent);
      const res = await fetch(boot.urls.syncPush, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _csrf: window.OfflineQueue.csrf, actions: [{ client_id: key, type: 'pos_sale', payload: { items: [{ product_id: 3, unit_id: 5, quantity: 2 }], payments: [{ method: 'cash', amount: 3000 }], discount: 0, customer_id: 0 } }] })
      });
      const j = await res.json();
      return j.results && j.results[0] && j.results[0].status;
    }, dupKey);
    const countAfterDup = await salesCount(db);
    check('duplicate retry returns "duplicate"', dupResult === 'duplicate', 'server said: ' + dupResult);
    check('duplicate retry created NO new sale', countAfterDup === startSales + 3, 'sales before: ' + startSales + ', after: ' + countAfterDup);
  } else {
    check('duplicate retry test', false, 'no accepted sale found in sync_log');
  }

  // 6) the rejected item shows in "Needs attention" with its reason
  await page.click('#offline-attention-btn');
  const attentionHtml = await page.textContent('#offline-attention-list');
  check('rejected item listed in Needs attention', /no longer exists/i.test(attentionHtml || ''), attentionHtml.slice(0, 120));
  check('rejection reason is visible', /expense category/i.test(attentionHtml || ''), '');

  // 7) logout clears the cache + queue (accept the confirm dialog)
  await page.click('#offline-attention-close').catch(() => {});   // the panel may have auto-opened again
  let confirmText = '';
  page.once('dialog', d => { confirmText = d.message(); d.accept(); });
  await page.click('form[action*="auth/logout"] button[type="submit"]');
  await page.waitForURL('**/auth/login**', { timeout: 15000 })
    .then(() => check('logout works', true))
    .catch(() => check('logout works', false));
  check('logout warns about unsynced items', /Needs attention|attention|item/i.test(confirmText || ''), confirmText.slice(0, 80));

  // verify the offline DB is empty after logout
  const stored = await page.evaluate(async () => {
    // open a fresh page context store check
    const dbs = await indexedDB.databases();
    const has = dbs.some(d => d.name === 'bssbms_offline');
    if (!has) { return { exists: false, queue: 0, kv: 0 }; }
    return new Promise(resolve => {
      const req = indexedDB.open('bssbms_offline', 1);
      req.onsuccess = () => {
        const idb = req.result;
        const t = idb.transaction(['queue', 'kv'], 'readonly');
        const q = t.objectStore('queue').count();
        const k = t.objectStore('kv').count();
        q.onsuccess = () => { k.onsuccess = () => resolve({ exists: true, queue: q.result, kv: k.result }); };
      };
    });
  });
  check('offline cache + queue cleared on logout',
    (!stored.exists) || (stored.queue === 0 && stored.kv === 0), JSON.stringify(stored));

  await browser.close();
  await db.end();

  const failed = results.filter(r => !r.ok);
  console.log('\n========== ' + (results.length - failed.length) + '/' + results.length + ' checks passed ==========');
  if (failed.length) {
    failed.forEach(f => console.log('FAILED: ' + f.name + (f.extra ? ' -> ' + f.extra : '')));
    process.exit(1);
  }
})().catch(e => { console.error('TEST RUNNER ERROR:', e); process.exit(1); });
