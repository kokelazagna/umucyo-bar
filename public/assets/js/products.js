(function () {
  var rows = document.getElementById('unit-rows');
  var tpl = document.getElementById('unit-template');
  var addBtn = document.getElementById('add-unit');
  var baseSel = document.getElementById('base_unit');
  if (!rows || !tpl || !addBtn) { return; }

  addBtn.addEventListener('click', function () {
    rows.insertAdjacentHTML('beforeend', tpl.innerHTML);
    syncBaseLabels();
  });

  rows.addEventListener('click', function (ev) {
    if (ev.target.classList.contains('remove-new')) {
      ev.target.closest('.unit-row').remove();
    }
  });

  // Keep "bottle(s)" / "can(s)" labels in sync with the base unit select
  function syncBaseLabels() {
    var unit = baseSel.value;
    document.querySelectorAll('.base-label').forEach(function (el) {
      el.textContent = el.closest('.unit-row') ? unit + '(s)' : unit;
    });
  }
  baseSel.addEventListener('change', syncBaseLabels);
})();
