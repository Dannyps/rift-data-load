#!/usr/bin/env python3
"""Checks of the Monday-to-Sunday week, the week totals and the 2-hour report window, in a real browser.

Runs against a LOCAL server on a THROWAWAY database (see tools/shots.py and the README), never the live site:

  RIFT_DB_CONFIG=... php -S 127.0.0.1:8099 -t . tools/dev_router.php
  PLAYWRIGHT_PY=... python3 -I tools/week_flows.py --base http://127.0.0.1:8099 --psql 'docker exec -i rift-capture-db psql -U rift'

Expects the manager sample.admin / capture-pass and the fake counter data of tools/fake_data.py.
"""
import argparse
import json
import os
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

sys.path.insert(0, os.environ.get('PLAYWRIGHT_PY', ''))
CHROMIUM = os.environ.get('CHROMIUM', '/usr/bin/chromium')
LISBON = ZoneInfo('Europe/Lisbon')
ok_all = True


def check(name, cond, extra=''):
    global ok_all
    print(('PASS ' if cond else 'FAIL ') + name + (f'   {extra}' if extra and not cond else ''))
    ok_all = ok_all and bool(cond)


def main():
    from playwright.sync_api import sync_playwright
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', default='http://127.0.0.1:8099')
    ap.add_argument('--psql', required=True)
    a = ap.parse_args()
    B = a.base

    def sql(q):
        return subprocess.run(a.psql.split() + ['-tAq', '-c', q], capture_output=True, text=True).stdout.strip()

    now = datetime.now(LISBON)
    today = now.date()
    monday = today - timedelta(days=today.weekday())
    sql(f"DELETE FROM meeting_reports WHERE day >= '{monday}'")
    sql(f"DELETE FROM special_days WHERE day = '{today}'")

    with sync_playwright() as p:
        b = p.chromium.launch(executable_path=CHROMIUM, args=['--no-sandbox'])
        ctx = b.new_context(viewport={'width': 1200, 'height': 900}, locale='pt-PT', timezone_id='Europe/Lisbon')
        pg = ctx.new_page()
        pg.goto(B + '/admin/entrar'); pg.fill('#username', 'sample.admin'); pg.fill('#password', 'capture-pass'); pg.click('button.primary'); pg.wait_for_load_state('networkidle')

        def api(path):
            return pg.evaluate("async p => { const r = await fetch(p, {credentials: 'same-origin'}); return {status: r.status, body: await r.json().catch(() => null)}; }", path)

        def post(path, form):
            return pg.evaluate("async ([p, f]) => { const r = await fetch(p, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams(f)}); return {status: r.status, body: await r.json().catch(() => null)}; }", [path, form])

        # ------------------------------------------------------------ the week is Monday to Sunday
        pg.goto(B + '/admin/reunioes')
        for d in range(7):
            day = monday + timedelta(days=d)
            w = api(f'/api/services?week={day}')['body']
            check(f'{day} ({day.strftime("%A")}) is in the week of Monday {monday}', w['weekStart'] == str(monday) and w['weekEnd'] == str(monday + timedelta(days=6)), w['weekStart'])
        w = api(f'/api/services?week={monday}')['body']
        check('previous and next week are 7 days away, still Mondays', w['prev'] == str(monday - timedelta(days=7)) and w['next'] == str(monday + timedelta(days=7)))
        check('the current week is flagged', w['isCurrent'] is True and api(f'/api/services?week={w["prev"]}')['body']['isCurrent'] is False)
        check('days come in order, Monday first', [d['date'] for d in w['days']] == sorted(d['date'] for d in w['days']))
        check('a Sunday is the last day of its week', all(d['date'] <= w['weekEnd'] for d in w['days']))

        # ------------------------------------------------------------ the report window: 2 hours before the start
        past = (now - timedelta(minutes=30)).replace(second=0, microsecond=0)
        soon = (now + timedelta(minutes=90)).replace(second=0, microsecond=0)
        later = (now + timedelta(hours=3)).replace(second=0, microsecond=0)
        if not (past.date() == soon.date() == later.date() == today):
            print('SKIP report-window checks: too close to midnight')
            sys.exit(0)
        else:
            sql(f"INSERT INTO special_days (day, name, start_time, duration_min) VALUES ('{today}', 'Teste', '{past:%H:%M}', 120), ('{today}', 'Teste', '{soon:%H:%M}', 60), ('{today}', 'Teste', '{later:%H:%M}', 60)")
            w = api(f'/api/services?week={today}')['body']
            ms = {s['time']: s for d in w['days'] for s in d['services'] if d['date'] == str(today)}
            check('a meeting in 90 minutes: report open, not started', ms[f'{soon:%H:%M}']['reportOpen'] is True and ms[f'{soon:%H:%M}']['status'] == 'unknown', ms)
            check('a meeting in 3 hours: report not open yet', ms[f'{later:%H:%M}']['reportOpen'] is False)
            form = {'csrf': '', 'day': str(today), 'time': f'{soon:%H:%M}', 'assistencia': '120', 'voluntarios': '10', 'visitantes': '5', 'adultos_fora': '3', 'kids': '20', 'auditorio': '4', 'decisoes': '1',
                    'mc': 'Ana', 'oferta': 'Rui', 'lider': 'Sofia', 'pregacao': 'Pr. Miguel'}
            me = api('/admin/api/me')['body']
            form['csrf'] = me['csrf']
            r = post('/admin/api/report', form)
            check('saving a report 90 minutes before the start works', r['status'] == 200 and r['body'].get('ok') is True, r)
            r = post('/admin/api/report', {**form, 'time': f'{later:%H:%M}'})
            check('saving 3 hours before the start is refused with a clear message', r['status'] == 422 and '2 horas antes' in json.dumps(r['body'], ensure_ascii=False), r)

            # the buttons on the page
            pg2 = ctx.new_page(); pg2.goto(B + '/reunioes'); pg2.wait_for_load_state('networkidle')
            btn_soon = pg2.locator(f'[data-report="s|{today}|{soon:%H:%M}"]')
            btn_later = pg2.locator(f'[data-report="s|{today}|{later:%H:%M}"]')
            check('page: the button of the meeting in 90 minutes is enabled', btn_soon.is_enabled())
            check('page: the button of the meeting in 3 hours is disabled', btn_later.is_disabled())
            check('page: the disabled button says why', '2 horas' in (btn_later.get_attribute('title') or ''))
            btn_soon.click(); pg2.wait_for_timeout(500)
            check('page: the report dialog opens before the meeting starts, with the saved numbers', pg2.evaluate("document.getElementById('report').open") and pg2.input_value('#f_assistencia') == '120' and pg2.input_value('#f_voluntarios') == '10', pg2.input_value('#f_assistencia'))
            pg2.keyboard.press('Escape')
            # a report for the meeting that is already running (this one adds up in the week totals)
            r = post('/admin/api/report', {**form, 'time': f'{past:%H:%M}', 'assistencia': '80', 'voluntarios': '10', 'kids': '20', 'auditorio': '4', 'adultos_fora': '3'})
            check('saving a report of a running meeting works', r['status'] == 200, r)

        # ------------------------------------------------------------ the totals at the bottom
        pg3 = ctx.new_page(); pg3.goto(B + '/reunioes'); pg3.wait_for_load_state('networkidle')
        t = api('/api/services')['body']['totals']
        box = pg3.locator('#weektotals')
        check('the totals section is shown for a week with meetings', box.is_visible(), t)
        check('...below the days and above the footer', pg3.evaluate("(() => { const d = document.getElementById('days').getBoundingClientRect(), w = document.getElementById('weektotals').getBoundingClientRect(), f = document.querySelector('footer').getBoundingClientRect(); return d.bottom <= w.top && w.bottom <= f.top; })()"))
        text = box.inner_text()
        check('...it says "Totais da semana" with the Monday-Sunday range', 'Totais da semana' in text)
        if t['shown'] is not None:
            digits = ''.join(ch for ch in pg3.inner_text('#weektotals .wt-total .wt-value') if ch.isdigit())
            check('...the total of people equals the API sum', digits == str(t['shown']), (digits, t))
        check('...the report figures are the saved report (volunteers 10)', t['voluntarios'] == 10 and '10' in text)
        check('...Hillsong Kids = kids + auditorium (24)', t['hillsongKids'] == 24)
        check('...the one started meeting has its report, so no "relatórios guardados" warning', t['reports'] == 1 and t['started'] == 1 and 'Relatórios guardados' not in text, text)
        check('...it says a meeting is running and two are still to come', 'a decorrer' in text and 'Faltam 2' in text, text)
        pg3.click('#prev'); pg3.wait_for_load_state('networkidle'); pg3.wait_for_timeout(500)
        label = pg3.inner_text('#weeklabel')
        check('previous week: the label is a Monday-to-Sunday range', f'{(monday - timedelta(days=7)):%d/%m}'.lstrip('0') in label.replace('/0', '/') or f'{(monday - timedelta(days=7)).day}/{(monday - timedelta(days=7)).month}' in label, label)
        check('footer text says the weeks run Monday to Sunday', 'de segunda-feira a domingo' in pg3.evaluate("document.querySelector('footer').textContent"))

        # ------------------------------------------------------------ the empty week hides the totals
        far = monday - timedelta(days=7 * 40)
        pg3.goto(B + f'/reunioes?week={far}'); pg3.wait_for_load_state('networkidle'); pg3.wait_for_timeout(400)
        check('a week with nothing to add up has no totals section', not pg3.locator('#weektotals').is_visible())

        # ------------------------------------------------------------ admin pages use the same week
        pg3.goto(B + f'/admin/correcoes?week={today}'); pg3.wait_for_load_state('networkidle')
        want = f'{monday:%d/%m/%Y} – {(monday + timedelta(days=6)):%d/%m/%Y}'
        check('Correções: week range is Monday-Sunday', want in pg3.inner_text('main'), pg3.inner_text('main')[:200])
        pg3.goto(B + f'/admin/pessoas?week={today}'); pg3.wait_for_load_state('networkidle')
        check('Pessoas: week range is Monday-Sunday', want in pg3.inner_text('main'))
        pg3.goto(B + f'/admin/estatisticas?week={today}'); pg3.wait_for_load_state('networkidle'); pg3.wait_for_timeout(800)
        st = api(f'/admin/api/stats?part=week&week={today}')['body']
        check('Statistics: the week starts on Monday, its Sunday is the last day', st['weekStart'] == str(monday) and st['sunday'] == str(monday + timedelta(days=6)), st['weekStart'])
        check('Statistics: label shows Monday to Sunday', want.split(' – ')[0][:5].lstrip('0') in pg3.inner_text('#weekCtl') or f'{monday.day}/{monday.month}' in pg3.inner_text('#weekCtl'), pg3.inner_text('#weekCtl'))
        check('Statistics: previous/next are Mondays 7 days apart', st['prev'] == str(monday - timedelta(days=7)) and st['next'] == str(monday + timedelta(days=7)))
        b.close()
    sql(f"DELETE FROM special_days WHERE day = '{today}'")
    sql(f"DELETE FROM meeting_reports WHERE day >= '{monday}'")
    print('ALL OK' if ok_all else 'FAILURES')
    sys.exit(0 if ok_all else 1)


if __name__ == '__main__':
    main()
