/* ============================================================================
   Umucyo Bar (BSSBMS) - offline mode client engine (loaded on every page).

   What it does:
   1. Caches the data the screens need (products + units + prices, categories,
      customers with limits and debts, the open cash session, expense
      categories) in IndexedDB, refreshed from sync/data on every page load.
   2. Shows the status bar: Online / Offline / Syncing / "N waiting to sync",
      with a "Needs attention" list for rejected offline actions.
   3. When offline, intercepts the allowed actions (POS sales, table draft
      items, serve/settle, expenses) and queues them in IndexedDB. Each queued
      action gets a unique id (idempotency key) so a retry can never create a
      duplicate sale or payment on the server.
   4. Syncs the queue IN ORDER when the connection returns. The server is the
      authority: it re-checks everything and answers accepted / rejected with
      a reason. Rejected items stay in "Needs attention" with the reason.
   5. Blocks offline actions that are online only (money/stock-critical).
   6. Clears the cached data and the queue on logout. No passwords are ever
      stored - only product/customer/price data and the CSRF token.
============================================================================ */

(function () {
  'use strict';

  var boot = readBoot();
  var BASE = boot.base || '';
  var URLS = boot.urls || {};
  var PAGE = (boot.page && boot.page.active) || '';

  // pages that keep working offline (everything else shows a clear notice)
  var OFFLINE_CAPABLE = ['pos', 'table', 'expense', 'dashboard'];

  var DB_NAME = 'bssbms_offline';
  var DB_VERSION = 1;
  var STORE_QUEUE = 'queue';    // queued offline actions
  var STORE_KV = 'kv';          // snapshot + csrf + user + cash session

  var OFFLINE_ONLY_REASON = 'This action is online only (money or stock critical).';

  var state = {
    online: navigator.onLine,
    syncing: false,
    queue: [],            // [{clientId, type, payload, createdAt, status, message, attempts}]
    snapshot: null,
    csrf: boot.csrf || '',
    sessionExpired: false
  };

  // ------------------------------------------------------------------ utils
  function readBoot() {
    var el = document.getElementById('offline-boot');
    if (!el) { return {}; }
    try { return JSON.parse(el.textContent); } catch (e) { return {}; }
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
    return 'q-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10) + '-' + Math.random().toString(36).slice(2, 10);
  }
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = (s === null || s === undefined) ? '' : String(s);
    return d.innerHTML;
  }
  function fmt(n) { return Math.round(n).toLocaleString('en-US') + ' RWF'; }
  function nowIso() { return new Date().toISOString(); }

  // ------------------------------------------------------------------ IndexedDB
  var dbPromise = null;
  function db() {
    if (!dbPromise) {
      dbPromise = new Promise(function (resolve, reject) {
        var req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = function () {
          var idb = req.result;
          if (!idb.objectStoreNames.contains(STORE_QUEUE)) {
            var q = idb.createObjectStore(STORE_QUEUE, { keyPath: 'seq', autoIncrement: true });
            q.createIndex('clientId', 'clientId', { unique: true });
            q.createIndex('status', 'status', { unique: false });
          }
          if (!idb.objectStoreNames.contains(STORE_KV)) {
            idb.createObjectStore(STORE_KV, { keyPath: 'key' });
          }
        };
        req.onsuccess = function () { resolve(req.result); };
        req.onerror = function () { reject(req.error); };
      });
    }
    return dbPromise;
  }
  function tx(store, mode) {
    return db().then(function (idb) { return idb.transaction(store, mode).objectStore(store); });
  }
  function reqAsPromise(request) {
    return new Promise(function (resolve, reject) {
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error); };
    });
  }
  function kvSet(key, value) {
    return tx(STORE_KV, 'readwrite').then(function (s) { return reqAsPromise(s.put({ key: key, value: value })); });
  }
  function kvGet(key) {
    return tx(STORE_KV, 'readonly').then(function (s) { return reqAsPromise(s.get(key)); })
      .then(function (row) { return row ? row.value : null; });
  }
  function queueAdd(item) {
    return tx(STORE_QUEUE, 'readwrite').then(function (s) { return reqAsPromise(s.add(item)); });
  }
  function queueAll() {
    return tx(STORE_QUEUE, 'readonly').then(function (s) { return reqAsPromise(s.getAll()); });
  }
  function queuePut(item) {
    return tx(STORE_QUEUE, 'readwrite').then(function (s) { return reqAsPromise(s.put(item)); });
  }
  function queueDelete(seq) {
    return tx(STORE_QUEUE, 'readwrite').then(function (s) { return reqAsPromise(s.delete(seq)); });
  }
  function queueClear() {
    return tx(STORE_QUEUE, 'readwrite').then(function (s) { return reqAsPromise(s.clear()); });
  }
  function wipeAll() {
    // clears the cache AND the queue (used on logout)
    return db().then(function (idb) {
      return new Promise(function (resolve, reject) {
        var t = idb.transaction([STORE_QUEUE, STORE_KV], 'readwrite');
        t.objectStore(STORE_QUEUE).clear();
        t.objectStore(STORE_KV).clear();
        t.oncomplete = function () { resolve(); };
        t.onerror = function () { reject(t.error); };
      });
    }).then(function () {
      try { localStorage.removeItem('bssbms_offline_flag'); } catch (e) {}
    });
  }

  // ------------------------------------------------------------------ status bar
  var bar, barText, barDot, attBtn, attCount, syncBtn, attPanel, attList;
  function initBar() {
    bar = document.getElementById('offline-status');
    if (!bar) { return; }
    bar.hidden = false;
    barDot = document.getElementById('offline-dot');
    barText = document.getElementById('offline-text');
    attBtn = document.getElementById('offline-attention-btn');
    attCount = document.getElementById('offline-attention-count');
    syncBtn = document.getElementById('offline-sync-btn');
    attPanel = document.getElementById('offline-attention');
    attList = document.getElementById('offline-attention-list');
    attBtn.addEventListener('click', openAttention);
    var closeBtn = document.getElementById('offline-attention-close');
    if (closeBtn) { closeBtn.addEventListener('click', function () { attPanel.hidden = true; }); }
    attPanel.addEventListener('click', function (ev) { if (ev.target === attPanel) { attPanel.hidden = true; } });
    syncBtn.addEventListener('click', function () { startSync(true); });
    render();
  }
  function render() {
    if (!bar) { return; }
    var attention = state.queue.filter(function (q) { return q.status === 'rejected'; }).length;
    var pending = state.queue.filter(function (q) { return q.status === 'pending'; }).length;
    var s, t;
    if (state.syncing) {
      s = 'syncing'; t = 'Syncing...';
    } else if (attention > 0) {
      s = 'attention'; t = (state.online ? 'Online' : 'Offline') + ' - ' + attention + ' item(s) need attention';
    } else if (!state.online) {
      s = 'offline'; t = 'Offline' + (pending ? ' - ' + pending + ' waiting to sync' : '');
    } else if (pending) {
      s = 'online'; t = 'Online - ' + pending + ' waiting to sync';
    } else if (state.sessionExpired) {
      s = 'offline'; t = 'Session expired - sign in again to finish syncing';
    } else {
      s = 'online'; t = 'Online';
    }
    bar.setAttribute('data-state', s);
    barText.textContent = t;
    attBtn.hidden = attention === 0;
    attCount.textContent = String(attention);
    syncBtn.hidden = !(state.online && (pending || attention) && !state.syncing);
  }

  // ------------------------------------------------------------ needs attention
  function openAttention() {
    if (!attPanel) { return; }
    var items = state.queue.filter(function (q) { return q.status === 'rejected'; });
    attList.innerHTML = items.length ? items.map(function (q) {
      return '<div class="off-item" data-seq="' + q.seq + '">'
        + '<strong>' + esc(typeLabel(q.type)) + '</strong>'
        + '<span class="off-item__meta"> - made ' + esc(q.createdAt) + ', key ' + esc(q.clientId) + '</span>'
        + '<div class="off-item__reason">Rejected: ' + esc(q.message || 'no reason given') + '</div>'
        + '<button type="button" class="btn btn-light off-item__retry">Retry</button> '
        + '<button type="button" class="btn btn-light text-red off-item__discard">Discard</button>'
        + '</div>';
    }).join('') : '<p class="muted">Nothing needs attention.</p>';
    attPanel.hidden = false;
    attList.querySelectorAll('.off-item__retry').forEach(function (b) {
      b.addEventListener('click', function () { retryItem(Number(b.closest('.off-item').getAttribute('data-seq'))); });
    });
    attList.querySelectorAll('.off-item__discard').forEach(function (b) {
      b.addEventListener('click', function () {
        if (confirm('Discard this offline action? It will never be sent to the server.')) {
          discardItem(Number(b.closest('.off-item').getAttribute('data-seq')));
        }
      });
    });
  }
  function retryItem(seq) {
    var item = state.queue.filter(function (q) { return q.seq === seq; })[0];
    if (!item) { return; }
    item.status = 'pending';
    item.attempts = 0;
    item.message = '';
    queuePut(item).then(function () { refreshState(); });
    attPanel.hidden = true;
  }
  function discardItem(seq) {
    var item = state.queue.filter(function (q) { return q.seq === seq; })[0];
    if (!item) { return; }
    queueDelete(seq).then(function () { refreshState(); });
    attPanel.hidden = true;
  }
  function typeLabel(type) {
    return ({
      pos_sale: 'POS sale',
      table_draft_add: 'Table draft item',
      table_draft_update: 'Table draft change',
      table_serve: 'Confirm served round',
      table_settle: 'Table tab payment',
      expense_create: 'Expense'
    })[type] || type;
  }

  // ------------------------------------------------------------------ snapshot
  function saveSnapshot(json) {
    state.csrf = json.csrf || state.csrf;
    state.snapshot = json.snapshot || null;
    return kvSet('csrf', state.csrf)
      .then(function () { return kvSet('user', json.user || null); })
      .then(function () { return kvSet('snapshot', state.snapshot); })
      .then(function () {
        document.dispatchEvent(new CustomEvent('offline-snapshot', { detail: state.snapshot }));
      });
  }
  function loadCachedData() {
    return Promise.all([kvGet('snapshot'), kvGet('csrf'), queueAll()]).then(function (r) {
      state.snapshot = r[0];
      state.csrf = r[1] || state.csrf;
      state.queue = r[2] || [];
      if (state.snapshot) {
        document.dispatchEvent(new CustomEvent('offline-snapshot', { detail: state.snapshot }));
      }
      render();
    });
  }
  function fetchSnapshot() {
    if (!state.online || !URLS.syncData) { return Promise.resolve(); }
    return fetch(URLS.syncData, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) { throw new Error('bad status ' + r.status); }
        return r.json();
      })
      .then(saveSnapshot)
      .catch(function () { /* offline or not logged in - keep the cached copy */ });
  }

  // ------------------------------------------------------------------ page notices
  function pageNotice() {
    if (OFFLINE_CAPABLE.indexOf(PAGE) !== -1) { return; }
    if (!state.online) {
      var notice = document.createElement('div');
      notice.className = 'offline-notice';
      notice.innerHTML = '<strong>You are offline.</strong> This page is showing the last saved copy only. ' +
        'Actions on this page (users, purchases, stock, reports, cash close...) need the server connection. ' +
        'The Point of Sale, table tabs and expenses keep working offline.';
      var main = document.querySelector('main.orders');
      if (main) { main.insertBefore(notice, main.children[3] || main.firstChild); }
    }
  }

  // Disables the "online only" controls (marked with data-online-only in the views).
  function blockOnlineOnly() {
    if (state.online) { return; }
    document.querySelectorAll('[data-online-only]').forEach(function (el) {
      if (el.tagName === 'FORM') {
        el.querySelectorAll('button[type="submit"]').forEach(function (b) {
          b.disabled = true;
          b.setAttribute('data-online-blocked', '1');
          b.title = OFFLINE_ONLY_REASON;
        });
      } else {
        el.disabled = true;
        el.setAttribute('data-online-blocked', '1');
        el.title = OFFLINE_ONLY_REASON;
      }
    });
  }

  // ---------------------------------------------------------- queue: intercepts
  function isOnlineOnlyForm(form) {
    return form.hasAttribute('data-online-only') || form.querySelector('[data-online-only]');
  }

  function enqueue(type, payload, note) {
    var item = {
      clientId: uuid(),
      type: type,
      payload: payload,
      createdAt: nowIso(),
      status: 'pending',
      attempts: 0,
      message: ''
    };
    return queueAdd({ clientId: item.clientId, type: item.type, payload: item.payload, createdAt: item.createdAt, status: 'pending', attempts: 0, message: '' })
      .then(function (seq) {
        item.seq = seq;
        state.queue.push(item);
        render();
        if (note) { toast(note); }
        if (state.online) { startSync(); }
        return item;
      });
  }

  // Marks queued forms so the user sees the action is waiting to sync.
  function toast(message) {
    var t = document.createElement('div');
    t.className = 'offline-notice';
    t.style.position = 'fixed';
    t.style.bottom = '52px';
    t.style.right = '16px';
    t.style.zIndex = '9200';
    t.style.boxShadow = '0 4px 16px rgba(0,0,0,.25)';
    t.textContent = message;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 5000);
  }

  // Intercepts plain form POSTs marked with data-offline-action="type".
  // The payloads are mapped to the exact shapes the server's sync/push expects.
  function mapPayload(type, payload) {
    if (type === 'table_draft_add' && payload.product_unit) {
      var pair = String(payload.product_unit).split(':');
      payload.product_id = pair[0];
      payload.unit_id = pair[1];
      delete payload.product_unit;
    }
    if (type === 'table_settle') {
      var payments = [];
      if (parseFloat(payload.cash_amount) > 0) {
        payments.push({ method: 'cash', amount: parseFloat(payload.cash_amount), reference: '' });
      }
      if (parseFloat(payload.momo_amount) > 0) {
        payments.push({ method: 'mobile_money', amount: parseFloat(payload.momo_amount), reference: payload.momo_ref || '' });
      }
      if (parseFloat(payload.bank_amount) > 0) {
        return { error: 'Bank payments are online only.' };
      }
      if ((parseInt(payload.customer_id, 10) || 0) !== 0 || payload.customer_id === '__other__') {
        return { error: 'Settling a tab on customer credit is online only.' };
      }
      payload = {
        session_id: payload.session_id,
        payments: payments,
        customer_id: 0,
        discount: parseFloat(payload.discount) || 0
      };
    }
    if (type === 'expense_create' && payload.category_id === '__other__') {
      payload.new_category = payload.category_other || '';
      delete payload.category_id;
      delete payload.category_other;
    }
    return { payload: payload };
  }

  function interceptForms() {
    document.querySelectorAll('form[data-offline-action]').forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        if (state.online || state.syncing) { return; }   // let it go to the server normally
        ev.preventDefault();
        if (isOnlineOnlyForm(form)) {
          alert(OFFLINE_ONLY_REASON);
          return;
        }
        var type = form.getAttribute('data-offline-action');
        var raw = {};
        new FormData(form).forEach(function (v, k) { raw[k] = v; });
        var mapped = mapPayload(type, raw);
        if (mapped.error) { alert(mapped.error); return; }
        var payload = mapped.payload;
        var note = form.getAttribute('data-offline-note') || 'Saved offline - it will sync when the connection returns.';
        enqueue(type, payload, note).then(function () {
          if (type !== 'table_draft_update') { form.reset(); }
        });
      });
    });
  }

  // ---------------------------------------------------------- POS integration
  // Called by pos.js when the checkout cannot reach the server.
  window.OfflineQueue = {
    get snapshot() { return state.snapshot; },
    get isOnline() { return state.online; },
    get csrf() { return state.csrf; },
    get session() { return state.snapshot ? state.snapshot.cash_session : null; },
    enqueuePosSale: function (formData) {
      // The POS form uses PHP-style names (product_id[], unit_id[]...); accept both styles.
      function all(form, plain, bracket) {
        var v = form.getAll(plain);
        return v.length ? v : form.getAll(bracket);
      }
      var items = [], payments = [];
      var pids = all(formData, 'product_id', 'product_id[]'),
          uids = all(formData, 'unit_id', 'unit_id[]'),
          qtys = all(formData, 'quantity', 'quantity[]');
      for (var i = 0; i < pids.length; i++) {
        items.push({ product_id: parseInt(pids[i], 10), unit_id: parseInt(uids[i], 10), quantity: parseInt(qtys[i], 10) });
      }
      var methods = all(formData, 'pay_method', 'pay_method[]'),
          amounts = all(formData, 'pay_amount', 'pay_amount[]'),
          refs    = all(formData, 'pay_ref', 'pay_ref[]');
      for (var j = 0; j < methods.length; j++) {
        var amount = parseFloat(amounts[j]);
        if (amount > 0) {
          payments.push({ method: methods[j], amount: amount, reference: (refs[j] || '') });
        }
      }
      var discount = parseFloat(formData.get('discount')) || 0;
      // offline rules: walk-in only, cash or Mobile Money only, fully paid
      for (var k = 0; k < payments.length; k++) {
        if (payments[k].method === 'bank') {
          return Promise.reject(new Error('Bank payments are online only.'));
        }
      }
      var total = 0;
      var snap = state.snapshot;
      if (snap) {
        items.forEach(function (it) {
          var p = snap.products.filter(function (x) { return x.id === it.product_id; })[0];
          if (p) {
            var u = p.units.filter(function (x) { return x.id === it.unit_id; })[0];
            if (u) { total += it.quantity * u.price; }
          }
        });
        total = Math.max(total - discount, 0);
      }
      var paid = payments.reduce(function (s, p) { return s + p.amount; }, 0);
      if (total > 0 && paid < total - 0.5) {
        return Promise.reject(new Error('Offline sales must be fully paid (cash or Mobile Money). Credit sales are online only.'));
      }
      if (!state.snapshot || !state.snapshot.cash_session) {
        return Promise.reject(new Error('No open cash session in the cached data. Open one while online - cash session management is online only.'));
      }
      // update the cached stock so a second offline sale sees what is left
      if (snap) {
        var changed = false;
        items.forEach(function (it) {
          snap.products.forEach(function (p) {
            if (p.id === it.product_id) {
              var u = p.units.filter(function (x) { return x.id === it.unit_id; })[0];
              if (u) { p.stock = Math.max(p.stock - it.quantity * u.factor, 0); changed = true; }
            }
          });
        });
        if (changed) { kvSet('snapshot', snap); }
      }
      return enqueue('pos_sale', { items: items, payments: payments, discount: discount, customer_id: 0 },
        'Sale queued (' + fmt(total) + '). It will sync automatically when the connection returns - the receipt will appear in Sales History.');
    },
    refresh: refreshState,
    wipe: wipeAll,
    enqueue: enqueue   // generic queued action (used by tests; views use data-offline-action forms)
  };

  // ---------------------------------------------------------- sync loop
  function startSync(manual) {
    if (state.syncing || !state.online) { return; }
    var pending = state.queue.filter(function (q) { return q.status === 'pending'; });
    if (!pending.length) { return; }
    if (!URLS.syncPush) { return; }
    state.syncing = true;
    render();
    syncNext();
  }

  function syncNext() {
    var pending = state.queue.filter(function (q) { return q.status === 'pending'; })
      .sort(function (a, b) { return a.seq - b.seq; });   // strictly in queue order
    if (!pending.length) {
      state.syncing = false;
      state.sessionExpired = false;
      render();
      if (state.queue.filter(function (q) { return q.status === 'rejected'; }).length) { openAttentionOnDemand(); }
      return;
    }
    var item = pending[0];
    fetch(URLS.syncPush, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ _csrf: state.csrf, actions: [{ client_id: item.clientId, type: item.type, payload: item.payload, created_at: item.createdAt }] })
    }).then(function (r) {
      if (r.status === 401 || r.status === 403 || r.status === 419) {
        // session or CSRF expired: ask the user to log in again, keep the queue
        state.syncing = false;
        state.sessionExpired = true;
        render();
        toast('Your session expired. Sign in again on this page - the queued actions will then sync automatically.');
        return;
      }
      return r.json().then(function (j) {
        var res = (j.results && j.results[0]) || null;
        if (!res) {
          item.attempts = (item.attempts || 0) + 1;
          if (item.attempts >= 3) { item.status = 'rejected'; item.message = 'The server gave no clear answer. Open the needs-attention list to retry or discard it.'; }
        } else if (res.status === 'accepted' || res.status === 'duplicate') {
          state.queue = state.queue.filter(function (q) { return q.seq !== item.seq; });
          queueDelete(item.seq);
          if (res.status === 'accepted') { toast('Synced: ' + res.message); }
          if (res.status === 'duplicate') { toast('Already synced earlier - no duplicate was created.'); }
          // refresh cached stock/prices after a successful sync
          fetchSnapshot();
        } else { // rejected with a reason
          item.status = 'rejected';
          item.message = res.message || 'Rejected by the server.';
          queuePut(item);
        }
        render();
        setTimeout(syncNext, 0);
      });
    }).catch(function () {
      // network dropped mid-sync
      state.syncing = false;
      state.online = navigator.onLine;
      render();
    });
  }

  function openAttentionOnDemand() {
    if (attPanel && attPanel.hidden && document.visibilityState === 'visible') { openAttention(); }
  }

  // ---------------------------------------------------------- logout clearing
  function interceptLogout() {
    var links = document.querySelectorAll('a[href*="auth/logout"], form[action*="auth/logout"]');
    links.forEach(function (el) {
      if (el.tagName === 'FORM') {
        el.addEventListener('submit', function (ev) {
          var waiting = state.queue.filter(function (q) { return q.status === 'pending'; }).length;
          var attention = state.queue.filter(function (q) { return q.status === 'rejected'; }).length;
          if (waiting || attention) {
            var ok = confirm('You have ' + (waiting + attention) + ' offline item(s) not yet on the server.\n' +
              'Logging out clears them permanently. Sync first (stay online and wait for the status bar) or discard them.\n\n' +
              'Log out anyway and lose them?');
            if (!ok) { ev.preventDefault(); return; }
          }
          wipeAll();   // clear cached data + queue on logout (never stores passwords)
        });
      } else {
        el.addEventListener('click', function (ev) {
          var waiting = state.queue.filter(function (q) { return q.status === 'pending'; }).length;
          var attention = state.queue.filter(function (q) { return q.status === 'rejected'; }).length;
          if (waiting || attention) {
            var ok = confirm('You have ' + (waiting + attention) + ' offline item(s) not yet on the server.\n' +
              'Logging out clears them permanently. Sync first (stay online and wait for the status bar) or discard them.\n\n' +
              'Log out anyway and lose them?');
            if (!ok) { ev.preventDefault(); return; }
          }
          wipeAll();   // clear cached data + queue on logout (never stores passwords)
        });
      }
    });
  }

  // ---------------------------------------------------------- wiring
  function refreshState() {
    return queueAll().then(function (q) {
      state.queue = q || [];
      render();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Register the service worker (served at the project root, so its scope
    // covers the whole app whatever the folder is called).
    if ('serviceWorker' in navigator && boot.offline !== false) {
      navigator.serviceWorker.register(BASE + '/sw.js').catch(function (e) {
        console.warn('Service worker could not be registered:', e);
      });
    }
    initBar();
    pageNotice();
    blockOnlineOnly();
    interceptForms();
    interceptLogout();
    loadCachedData().then(function () {
      if (state.online) {
        fetchSnapshot().then(function () {
          var pending = state.queue.filter(function (q) { return q.status === 'pending'; }).length;
          if (pending) { startSync(); }
        });
      }
    });
  });

  window.addEventListener('online', function () {
    state.online = true;
    state.sessionExpired = false;
    render();
    fetchSnapshot().then(function () { startSync(); });
  });
  window.addEventListener('offline', function () {
    state.online = false;
    render();
  });
  // periodic retry while there is something to send
  setInterval(function () {
    if (state.online && !state.syncing && state.queue.filter(function (q) { return q.status === 'pending'; }).length) {
      startSync();
    }
  }, 30000);
})();
