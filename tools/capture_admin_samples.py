#!/usr/bin/env python3
"""Static copies of the admin pages for the CSS preview (fixtures/admin/).

Run against a LOCAL server on the throwaway database (see tools/shots.py and README 'CSS rework'), never the live site:

  RIFT_DB_CONFIG=... php -S 127.0.0.1:8099 -t . tools/dev_router.php &
  PLAYWRIGHT_PY=... python3 -I tools/capture_admin_samples.py --base http://127.0.0.1:8099 --state state.json

Saves the raw server HTML of each page (JavaScript runs again in the browser, as on the real site), a few states with
flash messages, and the JSON the statistics page asks for. Regenerate whenever the admin markup changes.
"""
import argparse
import os
import re
import sys

sys.path.insert(0, os.environ.get('PLAYWRIGHT_PY', ''))
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = f'{ROOT}/fixtures/admin'
CHROMIUM = os.environ.get('CHROMIUM', '/usr/bin/chromium')
PAGES = ['reunioes', 'dias-especiais', 'pessoas', 'correcoes', 'contadores', 'estatisticas', 'utilizadores', 'palavra-passe', 'registo']


def fetch_text(pg, url, form=None):
    """The raw body of GET url (or of the page a POST of form redirects to), fetched by the browser so cookies apply."""
    return pg.evaluate("""async ([url, form]) => {
        const r = await fetch(url, form ? { method: 'POST', body: new URLSearchParams(form), credentials: 'same-origin' } : { credentials: 'same-origin' });
        return await r.text();
    }""", [url, form])


def save(name, text):
    with open(f'{OUT}/{name}', 'w', encoding='utf-8') as f:
        f.write(text)
    print('  ', name, len(text), 'bytes')


def main():
    from playwright.sync_api import sync_playwright
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', default='http://127.0.0.1:8099')
    ap.add_argument('--state', default='state.json')
    a = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)
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
        pg.goto(f'{a.base}/admin/estatisticas')
        pg.wait_for_load_state('networkidle')
        pg.wait_for_timeout(500)
        for r in answers:
            m = re.search(r'part=(week|month|year)', r.url)
            save(f'api-stats-{m.group(1)}.json', r.text())
        save('api-me.json', '{"loggedIn":false}\n')     # what the public pages ask; the preview is never logged in

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

        # the login page, and its error (anonymous)
        anon = b.new_context(locale='pt-PT').new_page()
        anon.goto(f'{a.base}/assets/site.css')
        login = f'{a.base}/admin/entrar'
        html = fetch_text(anon, login)
        save('entrar.html', html)
        save('entrar-erro.html', fetch_text(anon, login, {'csrf': csrf_of(html), 'username': 'sample.admin', 'password': 'wrong'}))
        b.close()
    print('done')


if __name__ == '__main__':
    main()
