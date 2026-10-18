#!/usr/bin/env python3
"""Screenshots of every page, for before/after CSS comparisons (needs Playwright + a Chromium; see README 'CSS rework').

  python3 tools/shots.py login   --base http://127.0.0.1:8099 --state state.json     # log in once to the sample admin
  python3 tools/shots.py shoot   --base http://127.0.0.1:8099 --state state.json --out DIR [--only SUBSTR]
  python3 tools/shots.py compare DIR_A DIR_B                                         # byte-compare two shot directories

Public pages get the JSON in fixtures/ (same rewrites as the preview vhost) and a frozen browser clock, so two runs of the
same CSS give identical PNGs. Admin pages come from the --base server (a LOCAL server on the throwaway database) with the
logged-in session of --state. The sample admin is sample.admin / capture-pass in the throwaway database ONLY.
Set PLAYWRIGHT_PY to the directory holding the playwright package and CHROMIUM to the browser binary if they are not the defaults.
"""
import argparse
import hashlib
import json
import os
import sys
from datetime import datetime, timezone

sys.path.insert(0, os.environ.get('PLAYWRIGHT_PY', ''))
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CHROMIUM = os.environ.get('CHROMIUM', '/usr/bin/chromium')
NOW = datetime(2026, 10, 18, 11, 15, tzinfo=timezone.utc)      # the fixtures' fixed "now" (12:15 Lisbon)
VIEWPORTS = {'phone': {'width': 390, 'height': 844}, 'desktop': {'width': 1200, 'height': 900}}
SCHEMES = ('light', 'dark')

PUBLIC = [  # name, path, after-load action
    ('index-1h', '/', None),
    ('index-24h', '/?range=24h', None),
    ('index-balance-7d', '/?range=7d&view=balance', None),
    ('index-tooltip', '/?range=6h', 'chart-hover'),
    ('reunioes', '/reunioes', None),
    ('reunioes-past-week', '/reunioes?week=2026-10-11', None),
    ('reunioes-empty-week', '/reunioes?week=2026-10-04', None),
    ('reunioes-future-week', '/reunioes?week=2026-10-25', None),
    ('reunioes-linked', '/reunioes#r-2026-10-18-1130', None),
    ('reunioes-report', '/reunioes', 'open-report'),
]
ADMIN = ['reunioes', 'dias-especiais', 'pessoas', 'correcoes', 'contadores', 'estatisticas', 'utilizadores', 'palavra-passe', 'registo']


def fixture_for(url):
    """The fixture file the preview vhost would serve for an API URL, or None."""
    from urllib.parse import urlparse, parse_qs
    u = urlparse(url)
    q = parse_qs(u.query)
    if u.path == '/api/chart':
        return f"chart-{q.get('range', ['24h'])[0]}.json"
    if u.path == '/api/services':
        wk = q.get('week', [None])[0]
        return f'services-{wk}.json' if wk and os.path.exists(f'{ROOT}/fixtures/services-{wk}.json') else 'services.json'
    if u.path == '/admin/api/me':
        return None
    return None


def new_context(browser, vp, scheme, state=None):
    ctx = browser.new_context(viewport=VIEWPORTS[vp], color_scheme=scheme, reduced_motion='reduce', locale='pt-PT', timezone_id='Europe/Lisbon',
                              storage_state=state, device_scale_factor=1)
    return ctx


def public_page(ctx, base):
    pg = ctx.new_page()
    pg.clock.set_fixed_time(NOW)

    def route(r):
        name = fixture_for(r.request.url)
        if name:
            r.fulfill(path=f'{ROOT}/fixtures/{name}', content_type='application/json')
        elif '/admin/api/me' in r.request.url:
            r.fulfill(json={'loggedIn': False})
        else:
            r.continue_()
    pg.route('**/*', route)
    return pg


def act(pg, what):
    if what == 'chart-hover':
        box = pg.locator('#chart svg').bounding_box()
        pg.mouse.move(box['x'] + box['width'] * 0.8, box['y'] + box['height'] * 0.5)
    elif what == 'open-report':
        pg.locator('.info-btn:not([disabled])').first.click()
    pg.wait_for_timeout(250)


def shoot(args):
    from playwright.sync_api import sync_playwright
    os.makedirs(args.out, exist_ok=True)
    n = 0
    with sync_playwright() as p:
        b = p.chromium.launch(executable_path=CHROMIUM, args=['--no-sandbox'])
        for vp in VIEWPORTS:
            for scheme in SCHEMES:
                ctx = new_context(b, vp, scheme)
                for name, path, what in PUBLIC:
                    tag = f'{name}__{vp}__{scheme}'
                    if args.only and args.only not in tag:
                        continue
                    pg = public_page(ctx, args.base)
                    pg.goto(args.base + path)
                    pg.wait_for_load_state('networkidle')
                    pg.wait_for_timeout(400)
                    if what:
                        act(pg, what)
                    pg.screenshot(path=f'{args.out}/{tag}.png', full_page=True, animations='disabled')
                    pg.close(); n += 1
                ctx.close()
                # admin: anonymous login page, then the logged-in pages
                ctx = new_context(b, vp, scheme, args.state)
                for name in ['entrar'] + ADMIN + (['reunioes-drawer'] if vp == 'phone' else []):
                    tag = f'admin-{name}__{vp}__{scheme}'
                    if args.only and args.only not in tag:
                        continue
                    pg = ctx.new_page()
                    pg.clock.set_fixed_time(NOW)
                    if name == 'entrar':
                        c2 = new_context(b, vp, scheme); pg2 = c2.new_page(); pg2.clock.set_fixed_time(NOW)
                        pg2.goto(args.base + '/admin/entrar'); pg2.wait_for_load_state('networkidle')
                        pg2.screenshot(path=f'{args.out}/{tag}.png', full_page=True, animations='disabled'); c2.close(); pg.close(); n += 1
                        continue
                    drawer = name == 'reunioes-drawer'
                    pg.goto(args.base + '/admin/' + ('reunioes' if drawer else name))
                    pg.wait_for_load_state('networkidle')
                    pg.wait_for_timeout(400)
                    if drawer:
                        pg.click('#menutoggle'); pg.wait_for_timeout(500)
                    pg.screenshot(path=f'{args.out}/{tag}.png', full_page=True, animations='disabled')
                    pg.close(); n += 1
                ctx.close()
        b.close()
    print(f'{n} screenshots in {args.out}')


def login(args):
    from playwright.sync_api import sync_playwright
    with sync_playwright() as p:
        b = p.chromium.launch(executable_path=CHROMIUM, args=['--no-sandbox'])
        ctx = b.new_context(locale='pt-PT')
        pg = ctx.new_page()
        pg.goto(args.base + '/admin/entrar')
        pg.fill('#username', 'sample.admin'); pg.fill('#password', 'capture-pass')
        pg.click('button.primary'); pg.wait_for_load_state('networkidle')
        print('logged in at', pg.url)
        ctx.storage_state(path=args.state)
        b.close()


def digest(path):
    with open(path, 'rb') as f:
        return hashlib.sha256(f.read()).hexdigest()


def compare(a, b):
    names = sorted(set(os.listdir(a)) | set(os.listdir(b)))
    bad = [n for n in names if not (os.path.exists(f'{a}/{n}') and os.path.exists(f'{b}/{n}') and digest(f'{a}/{n}') == digest(f'{b}/{n}'))]
    print(f'{len(names) - len(bad)}/{len(names)} identical')
    for n in bad:
        print('DIFFERS', n)
    return 1 if bad else 0


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('cmd', choices=['login', 'shoot', 'compare'])
    ap.add_argument('dirs', nargs='*')
    ap.add_argument('--base', default='http://127.0.0.1:8099')
    ap.add_argument('--state', default='state.json')
    ap.add_argument('--out', default='shots')
    ap.add_argument('--only', default='')
    a = ap.parse_args()
    if a.cmd == 'login':
        login(a)
    elif a.cmd == 'shoot':
        shoot(a)
    else:
        sys.exit(compare(*a.dirs))
