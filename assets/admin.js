// Ask before anything marked data-confirm (on a form or on its submit button).
document.addEventListener('submit', function (e) {
  var msg = (e.submitter && e.submitter.dataset.confirm) || e.target.dataset.confirm;
  if (msg && !confirm(msg)) e.preventDefault();
});
