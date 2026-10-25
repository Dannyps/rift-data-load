// Sign-in helpers for the whole site (no libraries, no inline styles: the admin pages have a strict CSP).
//  - sign-in page: the part of the address after # (a shared card link) is lost by the redirect to the login, so it is added to
//    the hidden "next" field and the visitor comes back to the same card.
//  - charts and meetings pages: show who is signed in (name, "Sair"), and send the visitor to the sign-in page when the
//    session has ended (window.riftAuth.expired() is also called by those pages when a data request answers 401).
(function () {
  'use strict';

  var next = document.getElementById('loginnext');   // (not 'next': the meetings page has a button with that id)
  if (next) {
    if (location.hash && next.value && next.value.indexOf('#') < 0) next.value += location.hash;
    else if (location.hash && !next.value) next.value = '/' + location.hash;
    return;
  }
  if (document.body.classList.contains('admin')) return;

  function here() { return location.pathname + location.search + location.hash; }
  function expired() { location.href = '/entrar?next=' + encodeURIComponent(here()); }
  window.riftAuth = { expired: expired };

  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text) e.textContent = text;
    return e;
  }

  fetch('/admin/api/me', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
    if (r.status === 401) { expired(); return null; }
    return r.ok ? r.json() : null;
  }).then(function (me) {
    var header = document.querySelector('header');
    if (!me || !me.loggedIn || !header) return;
    // the link to the admin area in the footer is only for administrators
    var adminLink = document.querySelector('footer .footer-links');
    if (adminLink && me.role !== 'admin') adminLink.remove();
    var chip = el('div', { 'class': 'userchip' });
    if (me.display) {
      chip.appendChild(el('span', {}, 'Ecrã · só leitura'));
      chip.appendChild(el('a', { href: '/entrar' }, 'Entrar'));
    } else {
      chip.appendChild(el('a', { href: '/conta' }, me.name || me.user));
      var form = el('form', { method: 'post', action: '/sair' });
      form.appendChild(el('input', { type: 'hidden', name: 'csrf', value: me.csrf || '' }));
      form.appendChild(el('button', { type: 'submit' }, 'Sair'));
      chip.appendChild(form);
    }
    header.appendChild(chip);
  }).catch(function () { /* offline: the page still works from what it has */ });
})();
