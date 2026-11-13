(function () {
  var data = JSON.parse(document.getElementById('pos-data').textContent);
  var products = data.products.slice(), customers = data.customers.slice();
  var byId = {}, custById = {};
  function buildIndexes() {
    byId = {}; custById = {};
    products.forEach(function (p) { byId[p.id] = p; });
    customers.forEach(function (c) { custById[c.id] = c; });
  }
  buildIndexes();

  var grid = document.getElementById('product-grid');
  var catsEl = document.getElementById('pos-cats');
  var search = document.getElementById('pos-search');
  var searchBtn = document.getElementById('pos-search-btn');
  var cartBody = document.getElementById('cart-rows');
  var cartEmpty = document.getElementById('cart-empty');
  var cartCount = document.getElementById('cart-count');
  var payBody = document.getElementById('pay-rows');
  var custSel = document.getElementById('customer_id');
  var discEl = document.getElementById('discount');
  var form = document.getElementById('pos-form');
  var btn = document.getElementById('btn-complete');
  var btnLabel = document.getElementById('btn-complete-label');
  var errBox = document.getElementById('pos-errors');
  var warnBox = document.getElementById('credit-warning');
  var overrideBox = document.getElementById('override-box');
  var overrideInput = overrideBox ? overrideBox.querySelector('input') : null;

  var saving = false;
  var activeCat = '';

  // tile colour is picked by product id (id % 8), so a product keeps its colour
  var COLORS = ['#a07a35', '#3a67c4', '#de9187', '#8fd5bd', '#f2706b', '#ffd699', '#a9cffb', '#c487a0'];
  var LOW_STOCK = 5;

  function fmt(n) { return Math.round(n).toLocaleString('en-US') + ' RWF'; }
  function esc(s) { var d = document.createElement('div'); d.textContent = (s === null || s === undefined) ? '' : s; return d.innerHTML; }
  function initials(name) {
    var w = String(name).trim().split(/\s+/);
    var s = (w[0] || '').charAt(0) + (w[1] ? w[1].charAt(0) : '');
    return s.toUpperCase();
  }

  // ---------- customers ----------
  function renderCustomers() {
    custSel.innerHTML = '<option value="0">Walk-in customer</option>' + customers.map(function (c) {
      return '<option value="' + c.id + '">' + esc(c.name) + (c.phone ? ' (' + esc(c.phone) + ')' : '') + '</option>';
    }).join('') + '<option value="__other__">\u2795 Other / New customer...</option>';
  }
  renderCustomers();

  // ---------- offline mode: rebuild the grid from the cached snapshot ----------
  function applyOfflineMode() {
    if (!window.OfflineQueue || window.OfflineQueue.isOnline) { return; }
    // offline sales are cash / Mobile Money only, walk-in only
    payBody.querySelectorAll('select.p-method').forEach(function (s) {
      var bank = s.querySelector('option[value="bank"]');
      if (bank) { bank.disabled = true; }
    });
    custSel.value = '0';
    custSel.disabled = true;
    recalc();
  }
  document.addEventListener('offline-snapshot', function (ev) {
    var snap = ev.detail;
    if (!snap || !snap.products || !snap.products.length) { return; }
    products = snap.products;
    customers = (snap.customers || []).slice();
    buildIndexes();
    renderCustomers();
    applyOfflineMode();
    renderCats();
    renderGrid();
  });
  window.addEventListener('offline', function () { applyOfflineMode(); });
  window.addEventListener('online', function () {
    custSel.disabled = false;
    payBody.querySelectorAll('option[value="bank"]').forEach(function (o) { o.disabled = false; });
  });

  // ---------- categories ----------
  var cats = [], catCount = {};
  products.forEach(function (p) {
    var c = p.category || 'Other';
    if (!(c in catCount)) { catCount[c] = 0; cats.push(c); }
    catCount[c]++;
  });
  function renderCats() {
    var html = '<button type="button" class="tabs__tab' + (activeCat === '' ? ' tabs__tab--active' : '') + '" data-cat="">All <span class="tabs__count">' + products.length + '</span></button>';
    cats.forEach(function (c) {
      html += '<button type="button" class="tabs__tab' + (activeCat === c ? ' tabs__tab--active' : '') + '" data-cat="' + esc(c) + '">'
        + esc(c) + ' <span class="tabs__count">' + catCount[c] + '</span></button>';
    });
    catsEl.innerHTML = html;
  }
  catsEl.addEventListener('click', function (ev) {
    var t = ev.target.closest('.tabs__tab');
    if (!t) { return; }
    activeCat = t.getAttribute('data-cat');
    renderCats();
    renderGrid();
  });

  // ---------- product grid ----------
  function basePrice(p) {
    var u = p.units.filter(function (x) { return x.factor === 1; })[0] || p.units[0];
    return u.price;
  }
  function matches(p, q) {
    var hay = (p.name + ' ' + (p.brand || '') + ' ' + (p.barcode || '') + ' ' + p.code).toLowerCase();
    return hay.indexOf(q) !== -1;
  }
  function renderGrid() {
    var q = search.value.trim().toLowerCase();
    var html = '';
    products.forEach(function (p) {
      if (q && !matches(p, q)) { return; }
      if (activeCat !== '' && (p.category || 'Other') !== activeCat) { return; }
      var out = p.stock <= 0;
      var badge = out ? '<span class="pill pill--out">Out of stock</span>'
        : (p.stock <= LOW_STOCK ? '<span class="pill pill--low">' + p.stock + ' left</span>'
                                : '<span class="pill pill--ok">' + p.stock + ' in stock</span>');
      html += '<button type="button" class="tile' + (out ? ' out' : '') + '" data-id="' + p.id + '"' + (out ? ' disabled' : '')
        + ' title="' + esc(p.name) + ' - ' + p.stock + ' ' + esc(p.base_unit) + '(s)">'
        + '<span class="tile__art" style="background:' + COLORS[p.id % COLORS.length] + '">' + esc(initials(p.name))
        + (out ? '' : '<span class="tile__add"><svg class="icon"><use href="#i-plus"/></svg></span>') + '</span>'
        + '<span class="tile__name">' + esc(p.name) + '</span>'
        + '<span class="tile__foot"><strong class="tile__price">' + fmt(basePrice(p)) + '</strong>' + badge + '</span></button>';
    });
    grid.innerHTML = html || '<p class="muted">No products found.</p>';
  }
  grid.addEventListener('click', function (ev) {
    var t = ev.target.closest('.tile');
    if (t && !t.disabled) { addLine(parseInt(t.getAttribute('data-id'), 10)); }
  });
  search.addEventListener('input', renderGrid);

  // Enter (or the search button): scan a barcode / add the only match
  function searchEnter() {
    var q = search.value.trim().toLowerCase();
    if (!q) { return; }
    var exact = products.filter(function (p) { return p.barcode && p.barcode.toLowerCase() === q; })[0];
    var list = exact ? [exact] : products.filter(function (p) { return matches(p, q) && p.stock > 0; });
    if (list.length === 1) {
      addLine(list[0].id);
      search.value = '';
      renderGrid();
    }
  }
  search.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter') { return; }
    ev.preventDefault();
    searchEnter();
  });
  searchBtn.addEventListener('click', function () { searchEnter(); search.focus(); });

  // ---------- cart ----------
  function selectedUnit(row) {
    var opt = row.querySelector('.c-unit option:checked');
    return {
      id: parseInt(opt.value, 10), factor: parseInt(opt.getAttribute('data-factor'), 10),
      price: parseFloat(opt.getAttribute('data-price')), name: opt.getAttribute('data-name')
    };
  }

  function addLine(pid) {
    var p = byId[pid];
    if (!p) { return; }
    // same product in the base unit already in the cart? add 1
    var rows = cartBody.querySelectorAll('.cart-row');
    for (var i = 0; i < rows.length; i++) {
      if (parseInt(rows[i].getAttribute('data-pid'), 10) === pid && selectedUnit(rows[i]).factor === 1) {
        var q = rows[i].querySelector('.c-qty');
        q.value = (parseInt(q.value, 10) || 0) + 1;
        recalc();
        return;
      }
    }
    var tr = document.createElement('div');
    tr.className = 'cart-row';
    tr.setAttribute('data-pid', p.id);
    var opts = p.units.map(function (u) {
      return '<option value="' + u.id + '" data-factor="' + u.factor + '" data-price="' + u.price + '" data-name="' + esc(u.name) + '"'
        + (u.factor === 1 ? ' selected' : '') + '>' + esc(u.name) + (u.factor > 1 ? ' (' + u.factor + ')' : '') + '</option>';
    }).join('');
    tr.innerHTML = '<div class="cart-row__info">'
      + '<input type="hidden" name="product_id[]" value="' + p.id + '">'
      + '<strong class="cart-row__name">' + esc(p.name) + '</strong>'
      + '<span class="cart-row__sub"><span class="c-price"></span>'
      + '<select name="unit_id[]" class="c-unit"' + (p.units.length < 2 ? ' hidden' : '') + '>' + opts + '</select></span>'
      + '<span class="line-msg"></span></div>'
      + '<div class="stepper"><button type="button" class="stepper__btn c-dec" aria-label="Decrease">&minus;</button>'
      + '<input type="number" name="quantity[]" class="c-qty" min="1" step="1" value="1" aria-label="Quantity">'
      + '<button type="button" class="stepper__btn c-inc" aria-label="Increase">+</button></div>'
      + '<strong class="c-total cart-row__total"></strong>';
    cartBody.appendChild(tr);
    recalc();
  }

  cartBody.addEventListener('input', recalc);
  cartBody.addEventListener('change', recalc);
  cartBody.addEventListener('click', function (ev) {
    var row = ev.target.closest('.cart-row');
    if (!row) { return; }
    var q = row.querySelector('.c-qty');
    if (ev.target.closest('.c-inc')) {
      q.value = (parseInt(q.value, 10) || 0) + 1;
      recalc();
    } else if (ev.target.closest('.c-dec')) {
      var n = (parseInt(q.value, 10) || 0) - 1;
      if (n < 1) { row.remove(); } else { q.value = n; }
      recalc();
    }
  });

  // ---------- payments ----------
  function addPayment(method) {
    var tr = document.createElement('div');
    tr.className = 'pay-row';
    tr.innerHTML = '<select name="pay_method[]" class="p-method">'
      + '<option value="cash"' + (method === 'cash' ? ' selected' : '') + '>Cash</option>'
      + '<option value="mobile_money"' + (method === 'mobile_money' ? ' selected' : '') + '>Mobile Money</option>'
      + '<option value="bank"' + (method === 'bank' ? ' selected' : '') + '>Bank</option></select>'
      + '<input type="number" name="pay_amount[]" class="p-amt" min="0" step="1" placeholder="Amount">'
      + '<button type="button" class="pay-row__btn p-fill">Fill</button>'
      + '<button type="button" class="pay-row__btn pay-row__btn--x p-remove" aria-label="Remove payment">&times;</button>'
      + '<input type="text" name="pay_ref[]" class="p-ref" maxlength="60" placeholder="Reference (optional)"' + (method === 'cash' ? ' hidden' : '') + '>';
    payBody.appendChild(tr);
  }
  document.getElementById('add-pay').addEventListener('click', function () { addPayment('mobile_money'); recalc(); });
  payBody.addEventListener('input', recalc);
  payBody.addEventListener('change', function (ev) {
    if (ev.target.classList.contains('p-method')) {
      ev.target.closest('.pay-row').querySelector('.p-ref').hidden = (ev.target.value === 'cash');
    }
    recalc();
  });
  payBody.addEventListener('click', function (ev) {
    var row = ev.target.closest('.pay-row');
    if (!row) { return; }
    if (ev.target.classList.contains('p-remove') && payBody.children.length > 1) {
      row.remove();
      recalc();
    }
    if (ev.target.classList.contains('p-fill')) {
      var total = currentTotals().total, others = 0;
      payBody.querySelectorAll('.pay-row').forEach(function (r) {
        if (r !== row) { others += parseFloat(r.querySelector('.p-amt').value) || 0; }
      });
      row.querySelector('.p-amt').value = Math.max(total - others, 0);
      recalc();
    }
  });
  custSel.addEventListener('change', recalc);
  if (discEl) { discEl.addEventListener('input', recalc); }
  if (overrideInput) { overrideInput.addEventListener('change', recalc); }

  // ---------- totals ----------
  function currentTotals() {
    var subtotal = 0, count = 0, used = {};
    cartBody.querySelectorAll('.cart-row').forEach(function (row) {
      var u = selectedUnit(row);
      var qty = parseInt(row.querySelector('.c-qty').value, 10) || 0;
      subtotal += qty * u.price;
      count += qty;
      var pid = row.getAttribute('data-pid');
      used[pid] = (used[pid] || 0) + qty * u.factor;
    });
    var discount = discEl ? (parseFloat(discEl.value) || 0) : 0;
    discount = Math.min(Math.max(discount, 0), subtotal);
    return { subtotal: subtotal, discount: discount, total: subtotal - discount, used: used, count: count };
  }

  function recalc() {
    var t = currentTotals();
    var overStock = false;

    cartBody.querySelectorAll('.cart-row').forEach(function (row) {
      var u = selectedUnit(row);
      var qty = parseInt(row.querySelector('.c-qty').value, 10) || 0;
      var pid = row.getAttribute('data-pid');
      var p = byId[pid];
      row.querySelector('.c-price').textContent = fmt(u.price) + ' / ' + u.name;
      row.querySelector('.c-total').textContent = fmt(qty * u.price);
      var msg = row.querySelector('.line-msg');
      if (t.used[pid] > p.stock) {
        row.classList.add('over');
        msg.textContent = 'Only ' + p.stock + ' ' + p.base_unit + '(s) in stock';
        overStock = true;
      } else {
        row.classList.remove('over');
        msg.textContent = '';
      }
    });

    var hasLines = cartBody.children.length > 0;
    cartEmpty.hidden = hasLines;
    cartCount.textContent = t.count;
    document.getElementById('t-subtotal').textContent = fmt(t.subtotal);
    document.getElementById('t-total').textContent = fmt(t.total);

    var paid = 0;
    payBody.querySelectorAll('.p-amt').forEach(function (i) { paid += parseFloat(i.value) || 0; });
    var credit = Math.max(t.total - paid, 0);
    document.getElementById('t-paid').textContent = fmt(paid);
    var creditEl = document.getElementById('t-credit');
    creditEl.textContent = fmt(credit);
    creditEl.classList.toggle('is-debt', credit > 0.5);

    var warn = '', needOverride = false, blocked = false;
    var cust = custById[custSel.value];
    var isNewCust = custSel.value === '__other__';   // the server creates the customer; its rules apply there
    if (paid > t.total + 0.5) {
      warn = 'Payments are more than the total.';
      blocked = true;
    } else if (credit > 0.5 && !cust && !isNewCust) {
      warn = 'The balance of ' + fmt(credit) + ' needs a customer: choose a credit customer or enter payments that cover the total.';
      blocked = true;
    } else if (credit > 0.5 && cust) {
      if (cust.limit <= 0) {
        warn = cust.name + ' is not authorized for credit.';
        blocked = true;
      } else if (cust.debt + credit > cust.limit + 0.5) {
        warn = 'Credit limit exceeded: limit ' + fmt(cust.limit) + ', current debt ' + fmt(cust.debt) + ', this sale adds ' + fmt(credit) + '.';
        needOverride = true;
        if (!(overrideInput && overrideInput.checked)) { blocked = true; }
      }
    }
    if (overStock) {
      warn = (warn ? warn + ' ' : '') + 'Some lines have more than the stock available.';
      blocked = true;
    }
    warnBox.hidden = !warn;
    warnBox.textContent = warn;
    if (overrideBox) { overrideBox.hidden = !needOverride; }

    // The server still validates everything; this only mirrors it so the button is disabled when a sale cannot succeed.
    btn.disabled = saving || !hasLines || blocked;
  }

  // ---------- submit ----------
  function showErr(msg) { errBox.hidden = false; errBox.textContent = msg; }
  function resetBtn() { saving = false; btn.disabled = false; btnLabel.textContent = 'Complete sale'; recalc(); }

  // Offline checkout: queue the sale with an idempotency key (offline.js) and
  // clear the cart. The server re-checks everything when it syncs.
  function queueSaleOffline() {
    saving = true;
    btn.disabled = true;
    btnLabel.textContent = 'Queueing...';
    window.OfflineQueue.enqueuePosSale(new FormData(form)).then(function () {
      document.getElementById('btn-clear').click();
      saving = false;
      btn.disabled = false;
      btnLabel.textContent = 'Complete sale';
      recalc();
    }).catch(function (e) {
      showErr(e.message || 'The sale could not be queued.');
      resetBtn();
    });
  }

  btn.addEventListener('click', function () {
    errBox.hidden = true;
    if (!cartBody.children.length) { showErr('The cart is empty.'); return; }
    if (window.OfflineQueue && !window.OfflineQueue.isOnline) { return queueSaleOffline(); }
    saving = true;
    btn.disabled = true;
    btnLabel.textContent = 'Saving...';
    fetch(data.checkoutUrl, {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.text(); }).then(function (txt) {
      var j;
      try { j = JSON.parse(txt); } catch (e) {
        throw new Error('Unexpected answer from the server. Check Sales History before trying again, then reload this page.');
      }
      if (j.ok) { window.location = j.receipt_url; return; }
      showErr(j.message || 'The sale could not be saved.');
      resetBtn();
    }).catch(function (e) {
      if (window.OfflineQueue && !navigator.onLine) { return queueSaleOffline(); }  // connection dropped mid-submit
      showErr(e.message || 'Network error. Check Sales History before trying again.');
      resetBtn();
    });
  });

  document.getElementById('btn-clear').addEventListener('click', function () {
    cartBody.innerHTML = '';
    payBody.innerHTML = '';
    addPayment('cash');
    custSel.value = '0';
    if (discEl) { discEl.value = 0; }
    if (overrideInput) { overrideInput.checked = false; }
    errBox.hidden = true;
    recalc();
  });

  renderCats();
  renderGrid();
  addPayment('cash');
  recalc();
})();
