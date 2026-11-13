// "Other / New..." option in dropdowns.
// A select that carries data-other-input="<id>" shows the paired text input
// when the user picks the option with value "__other__", and hides it again
// when a normal option is chosen. No AJAX: the typed name travels with the
// same form POST.
(function () {
  var OTHER = '__other__';

  function apply(sel) {
    var input = document.getElementById(sel.getAttribute('data-other-input'));
    if (!input) { return; }
    var isOther = sel.value === OTHER;
    input.hidden = !isOther;
    if (isOther) {
      input.required = true;
      input.focus();
    } else {
      input.required = false;
      input.value = '';
    }
  }

  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest('select[data-other-input]');
    if (sel) { apply(sel); }
  });

  // forms with an "Other" open: the state must survive a re-rendered page
  // (validation errors), so apply once on load for every such select.
  document.addEventListener('DOMContentLoaded', function () {
    var sels = document.querySelectorAll('select[data-other-input]');
    for (var i = 0; i < sels.length; i++) { apply(sels[i]); }
  });
})();
