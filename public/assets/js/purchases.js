(function () {
  var dataEl = document.getElementById('product-data');
  var rows = document.getElementById('item-rows');
  var tpl = document.getElementById('row-template');
  var addBtn = document.getElementById('add-row');
  if (!dataEl || !rows || !tpl || !addBtn) { return; }

  var products = JSON.parse(dataEl.textContent);
  var byId = {};
  products.forEach(function (p) { byId[p.id] = p; });

  var totalEl = document.getElementById('grand-total');
  var paidEl = document.getElementById('amount_paid');
  var balEl = document.getElementById('balance-left');

  function fmt(n) { return Math.round(n).toLocaleString('en-US') + ' RWF'; }

  function setDefaultCost(row) {
    var opt = row.querySelector('.p-unit option:checked');
    if (opt) { row.querySelector('.p-cost').value = opt.getAttribute('data-cost'); }
  }

  function fillUnits(row, selectedUnit, keepCost) {
    var p = byId[row.querySelector('.p-product').value];
    var sel = row.querySelector('.p-unit');
    sel.innerHTML = '';
    if (!p) { recalc(); return; }
    p.units.forEach(function (u) {
      var o = document.createElement('option');
      o.value = u.id;
      o.textContent = u.name + ' (' + u.factor + ' ' + p.base_unit + ')';
      o.setAttribute('data-cost', u.cost);
      if (String(u.id) === String(selectedUnit)) { o.selected = true; }
      sel.appendChild(o);
    });
    if (!keepCost) { setDefaultCost(row); }
    recalc();
  }

  function recalc() {
    var total = 0;
    rows.querySelectorAll('.item-row').forEach(function (row) {
      var qty = parseFloat(row.querySelector('.p-qty').value) || 0;
      var cost = parseFloat(row.querySelector('.p-cost').value) || 0;
      var line = qty * cost;
      row.querySelector('.p-total').textContent = fmt(line);
      total += line;
    });
    totalEl.textContent = fmt(total);
    var paid = parseFloat(paidEl.value) || 0;
    balEl.textContent = fmt(Math.max(total - paid, 0));
  }

  rows.addEventListener('change', function (ev) {
    var row = ev.target.closest('.item-row');
    if (!row) { return; }
    if (ev.target.classList.contains('p-product')) { fillUnits(row, null, false); }
    if (ev.target.classList.contains('p-unit')) { setDefaultCost(row); recalc(); }
  });
  rows.addEventListener('input', recalc);
  paidEl.addEventListener('input', recalc);

  rows.addEventListener('click', function (ev) {
    if (ev.target.classList.contains('remove-row')) {
      if (rows.querySelectorAll('.item-row').length > 1) {
        ev.target.closest('.item-row').remove();
        recalc();
      }
    }
  });

  addBtn.addEventListener('click', function () {
    rows.insertAdjacentHTML('beforeend', tpl.innerHTML);
  });

  // Initial rows (also rows re-shown after a validation error)
  rows.querySelectorAll('.item-row').forEach(function (row) {
    fillUnits(row, row.getAttribute('data-unit'), true);
  });
  recalc();
})();
