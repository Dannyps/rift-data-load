// Ask before anything marked data-confirm (on a form or on its submit button).
document.addEventListener('submit', function (e) {
  var msg = (e.submitter && e.submitter.dataset.confirm) || e.target.dataset.confirm;
  if (msg && !confirm(msg)) e.preventDefault();
});

// Sidebar menu on phones: the button toggles it; Escape, the backdrop, a link or a wider screen close it.
(function () {
  var toggle = document.getElementById('menutoggle');
  var nav = document.getElementById('adminnav');
  var backdrop = document.getElementById('navbackdrop');
  if (!toggle || !nav) return;

  function setOpen(open, returnFocus) {
    document.body.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Fechar o menu' : 'Abrir o menu');
    backdrop.hidden = !open;
    if (open) {
      var current = nav.querySelector('[aria-current="page"]') || nav.querySelector('a');
      if (current) current.focus();
    } else if (returnFocus) {
      toggle.focus();
    }
  }

  toggle.addEventListener('click', function () { setOpen(!document.body.classList.contains('nav-open')); });
  backdrop.addEventListener('click', function () { setOpen(false, true); });
  nav.addEventListener('click', function (e) { if (e.target.closest('a')) setOpen(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.body.classList.contains('nav-open')) setOpen(false, true);
  });
  window.matchMedia('(min-width: 721px)').addEventListener('change', function (e) { if (e.matches) setOpen(false); });
})();

// Tables as cards on phones. The CSS (admin.css, max-width 720px) shows each row of a table.stack as a card; for that
// every cell needs its column name (data-label), copied here from the header. The table roles are set explicitly
// because display:block on table elements makes some screen readers forget they are tables.
(function () {
  function stack(table) {
    if (table.dataset.stacked) return;
    table.dataset.stacked = '1';
    table.classList.add('stack');
    var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    table.setAttribute('role', 'table');
    Array.prototype.forEach.call(table.querySelectorAll('thead, tbody'), function (g) { g.setAttribute('role', 'rowgroup'); });
    Array.prototype.forEach.call(table.querySelectorAll('tr'), function (tr) {
      tr.setAttribute('role', 'row');
      Array.prototype.forEach.call(tr.children, function (cell, i) {
        var inHead = cell.parentNode.parentNode.tagName === 'THEAD';
        cell.setAttribute('role', inHead ? 'columnheader' : (cell.tagName === 'TH' ? 'rowheader' : 'cell'));
        if (inHead) return;
        cell.setAttribute('data-label', heads[i] || '');
        var fields = cell.querySelectorAll('input, select, textarea');
        var onlyCheckbox = fields.length === 1 && fields[0].type === 'checkbox';
        if (fields.length && !onlyCheckbox) cell.classList.add('has-field');
        if (!cell.textContent.trim() && !fields.length) cell.classList.add(cell.children.length ? 'actions-cell' : 'empty-cell');
      });
    });
  }
  window.stackTables = function (root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('table.admin, table.stackable'), stack);
  };
  window.stackTables();
})();
