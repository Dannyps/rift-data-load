#!/usr/bin/env python3
"""Static copies of the admin pages for the CSS preview (fixtures/admin/).

Run against a LOCAL server on the throwaway database (see tools/shots.py and README 'CSS rework'), never the live site:

  RIFT_DB_CONFIG=... php -S 127.0.0.1:8099 -t . tools/dev_router.php &
  PLAYWRIGHT_PY=... python3 -I tools/capture_admin_samples.py --base http://127.0.0.1:8099 --state state.json

Saves the raw server HTML of each page (JavaScript runs again in the browser, as on the real site), a few states with
flash messages, and the JSON the statistics page asks for: fixtures/admin/ (the admin area) and fixtures/auth/ (sign-in,
sign-up, password reset and the account page). Needs one pending sign-up and one valid reset token in the throwaway
database: pass --psql 'docker exec -i rift-capture-db psql -U rift'. Regenerate whenever that markup changes.
"""
import argparse
import os
import re
import subprocess
import sys
from datetime import date, timedelta

sys.path.insert(0, os.environ.get('PLAYWRIGHT_PY', ''))
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = f'{ROOT}/fixtures/admin'
AUTH_OUT = f'{ROOT}/fixtures/auth'
CHROMIUM = os.environ.get('CHROMIUM', '/usr/bin/chromium')
PAGES = ['reunioes', 'dias-especiais', 'pessoas', 'correcoes', 'contadores', 'estatisticas', 'utilizadores', 'registo']


def fetch_text(pg, url, form=None):
    """The raw body of GET url (or of the page a POST of form redirects to), fetched by the browser so cookies apply."""
    return pg.evaluate("""async ([url, form]) => {
        const r = await fetch(url, form ? { method: 'POST', body: new URLSearchParams(form), credentials: 'same-origin' } : { credentials: 'same-origin' });
        return await r.text();
    }""", [url, form])


def save(name, text, out=None):
    with open(f'{out or OUT}/{name}', 'w', encoding='utf-8') as f:
        f.write(text)
    print('  ', name, len(text), 'bytes')


def main():
    from playwright.sync_api import sync_playwright
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', default='http://127.0.0.1:8099')
    ap.add_argument('--state', default='state.json')
    ap.add_argument('--week', default='', help='Sunday (YYYY-MM-DD) whose statistics to keep; default: the last Sunday before today')
    ap.add_argument('--psql', default='', help='psql command for the throwaway database (creates the reset token for the reset form)')
    a = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)
    os.makedirs(AUTH_OUT, exist_ok=True)
    with sync_playwright() as p:
        b = p.chromium.launch(executable_path=CHROMIUM, args=['--no-sandbox'])
        ctx = b.new_context(storage_state=a.state, locale='pt-PT')
        home = ctx.new_page()
        home.goto(f'{a.base}/assets/site.css')       # any page of the origin: fetch() runs from here
        for slug in PAGES:
            save(f'{slug}.html', fetch_text(home, f'{a.base}/admin/{slug}'))

        # the statistics page asks for JSON three times (part=week, month, year): keep each answer
        pg = ctx.new_page()
        answers = []
        pg.on('response', lambda r: answers.append(r) if '/admin/api/stats' in r.url else None)
        week = a.week or (date.today() - timedelta(days=(date.today().weekday() + 1) % 7 or 7)).isoformat()    # last Sunday before today
        pg.goto(f'{a.base}/admin/estatisticas?week={week}&month={week[:7]}')
        pg.wait_for_load_state('networkidle')
        pg.wait_for_timeout(500)
        for r in answers:
            m = re.search(r'part=(week|month|year)', r.url)
            save(f'api-stats-{m.group(1)}.json', r.text())
        # what the public pages ask: in the preview everybody is a read-only signed-in viewer (so the header shows its chip)
        save('api-me.json', '{"loggedIn":true,"user":"maria.silva","name":"Maria Silva","role":"viewer","canReport":false,"display":false,"csrf":"preview"}\n')

        # states with a flash message: post a form, follow the redirect, keep the page that shows the message.
        # (nothing is changed in the throwaway database: an invalid special day, and deleting a day that has none)
        def csrf_of(html):
            m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
            return m.group(1)

        url = f'{a.base}/admin/dias-especiais'
        token = csrf_of(fetch_text(home, url))
        save('dias-especiais-erro.html', fetch_text(home, url, {'csrf': token, 'day': '2030-01-01', 'name': '', 'times': '', 'duration_min': '120'}))
        token = csrf_of(fetch_text(home, url))
        save('dias-especiais-ok.html', fetch_text(home, url, {'csrf': token, 'action': 'delete', 'day': '2030-01-01'}))

        # the sign-in, sign-up, password and account pages (anonymous, except the account page)
        anon = b.new_context(locale='pt-PT').new_page()
        anon.goto(f'{a.base}/assets/site.css')
        def anon_get(path):
            return fetch_text(anon, a.base + path)
        def anon_post(path, form, page_for_csrf=None):
            html = anon_get(page_for_csrf or path)
            return fetch_text(anon, a.base + path, {'csrf': csrf_of(html), **form})
        save('entrar.html', anon_get('/entrar'), AUTH_OUT)
        save('entrar-erro.html', anon_post('/entrar', {'username': 'maria.silva', 'password': 'wrong', 'next': ''}), AUTH_OUT)
        save('registar.html', anon_get('/registar'), AUTH_OUT)
        form = {'name': 'Maria Teste', 'email': 'maria@example.org', 'username': 'maria.teste', 'password': 'uma palavra-passe', 'password2': 'uma palavra-passe', 'code': 'wrong-code'}
        save('registar-erro.html', anon_post('/registar', form), AUTH_OUT)
        save('registar-enviado.html', anon_post('/registar', {**form, 'ref_empresa': 'robot'}), AUTH_OUT)    # the hidden field: success page, nothing created
        save('esqueci-palavra-passe.html', anon_get('/esqueci-palavra-passe'), AUTH_OUT)
        save('esqueci-palavra-passe-enviado.html', anon_post('/esqueci-palavra-passe', {'email': 'ninguem@example.org'}), AUTH_OUT)
        save('repor-palavra-passe-invalido.html', anon_get('/repor-palavra-passe?token=' + 'ab' * 32), AUTH_OUT)
        if a.psql:
            token = 'cd' * 32
            subprocess.run(a.psql.split() + ['-q', '-c', "DELETE FROM password_resets; INSERT INTO password_resets (user_id, token_hash, expires_at) "
                           f"SELECT id, encode(sha256(convert_to('{token}', 'UTF8')), 'hex'), now() + interval '1 hour' FROM admin_users WHERE username = 'maria.silva'"], check=True)
            save('repor-palavra-passe.html', anon_get('/repor-palavra-passe?token=' + token), AUTH_OUT)
            subprocess.run(a.psql.split() + ['-q', '-c', 'DELETE FROM password_resets'], check=True)
        save('conta.html', fetch_text(home, f'{a.base}/conta'), AUTH_OUT)
        token = csrf_of(fetch_text(home, f'{a.base}/conta'))
        save('conta-ok.html', fetch_text(home, f'{a.base}/conta', {'csrf': token, 'action': 'profile', 'name': 'Sample Admin', 'email': 'sample.admin@example.org'}), AUTH_OUT)
        b.close()
    print('done')


if __name__ == '__main__':
    main()
