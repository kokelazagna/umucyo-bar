(function () {
  var data = JSON.parse(document.getElementById('adjust-data').textContent);
  var byId = {};
  data.products.forEach(function (p) { byId[p.id] = p; });

  var prod = document.getElementById('product_id');
  var unit = document.getElementById('unit_id');
  var qty = document.getElementById('quantity');
  var type = document.getElementById('type');
  var dir = document.getElementById('direction');
  var dirBox = document.getElementById('dir-box');
  var preview = document.getElementById('preview');

  data.products.forEach(function (p) {
    var o = document.createElement('option');
    o.value = p.id;
    o.textContent = p.name + ' (' + p.stock + ' ' + p.base_unit + ')';
    if (p.id === data.selectedProduct) { o.selected = true; }
    prod.appendChild(o);
  });

  function fillUnits() {
    var p = byId[prod.value];
    unit.innerHTML = '';
    if (!p) { return; }
    p.units.forEach(function (u) {
      var o = document.createElement('option');
      o.value = u.id;
      o.setAttribute('data-factor', u.factor);
      o.textContent = u.name + (u.factor > 1 ? ' (' + u.factor + ' ' + p.base_unit + ')' : '');
      if (u.id === data.selectedUnit) { o.selected = true; }
      unit.appendChild(o);
    });
  }

  function update() {
    var t = type.value;
    dirBox.hidden = (t !== 'correction');
    var p = byId[prod.value];
    if (!p) { preview.textContent = '-'; return; }
    var opt = unit.options[unit.selectedIndex];
    var factor = opt ? parseInt(opt.getAttribute('data-factor'), 10) : 1;
    var q = parseInt(qty.value, 10) || 0;
    var base = q * factor;
    var decrease = (t === 'correction') ? (dir.value === 'decrease') : (t !== 'returned');
    var after = decrease ? p.stock - base : p.stock + base;
    preview.textContent = p.stock + ' \u2192 ' + after + ' ' + p.base_unit + '(s)';
    preview.className = 'big-total' + (after < 0 ? ' text-red' : '');
  }

  prod.addEventListener('change', function () { data.selectedUnit = 0; fillUnits(); update(); });
  [unit, qty, type, dir].forEach(function (el) {
    el.addEventListener('input', update);
    el.addEventListener('change', update);
  });

  fillUnits();
  update();
})();
