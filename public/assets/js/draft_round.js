// Draft round product grid: search box + category tabs (same behaviour as the POS grid).
// Everything else (adding a drink) is a plain form POST - no AJAX here.
(function () {
  var search = document.getElementById('draft-search');
  var catsEl = document.getElementById('draft-cats');
  var grid = document.getElementById('draft-grid');
  var empty = document.getElementById('draft-empty');
  if (!grid) { return; }
  var tiles = Array.prototype.slice.call(grid.querySelectorAll('.tile'));
  if (!tiles.length) { return; }

  // build the category tabs from what is on the page
  var cats = [], counts = {};
  tiles.forEach(function (t) {
    var c = t.getAttribute('data-cat') || 'Other';
    if (!(c in counts)) { counts[c] = 0; cats.push(c); }
    counts[c]++;
  });
  var active = '';
  function renderTabs() {
    var html = '<button type="button" class="tabs__tab' + (active === '' ? ' tabs__tab--active' : '') + '" data-cat="">All <span class="tabs__count">' + tiles.length + '</span></button>';
    cats.forEach(function (c) {
      html += '<button type="button" class="tabs__tab' + (active === c ? ' tabs__tab--active' : '') + '" data-cat="' + c.replace(/"/g, '&quot;') + '">'
        + c + ' <span class="tabs__count">' + counts[c] + '</span></button>';
    });
    catsEl.innerHTML = html;
  }
  catsEl.addEventListener('click', function (ev) {
    var t = ev.target.closest('.tabs__tab');
    if (!t) { return; }
    active = t.getAttribute('data-cat');
    renderTabs();
    apply();
  });

  function apply() {
    var q = (search.value || '').trim().toLowerCase();
    var shown = 0;
    tiles.forEach(function (t) {
      var okCat = (active === '' || t.getAttribute('data-cat') === active);
      var okText = (!q || t.getAttribute('data-name').indexOf(q) !== -1);
      var show = okCat && okText;
      t.style.display = show ? '' : 'none';
      if (show) { shown++; }
    });
    if (empty) { empty.hidden = shown !== 0; }
  }

  search.addEventListener('input', apply);
  renderTabs();
})();
