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
