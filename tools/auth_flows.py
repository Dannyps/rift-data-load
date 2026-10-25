#!/usr/bin/env python3
"""End-to-end checks of sign-in, sign-up with approval, password reset and the counter-network rule, in a real browser.

Runs against a LOCAL server on a THROWAWAY database (see README 'CSS rework' for the recipe), never the live site:

  RIFT_DB_CONFIG=... RIFT_SENDMAIL=<script that stores stdin in $MAILDIR> RIFT_TEST_ADDR_HEADER=1 \\
      php -S 127.0.0.1:8099 -t . tools/dev_router.php
  PLAYWRIGHT_PY=... python3 -I tools/auth_flows.py --base http://127.0.0.1:8099 --maildir DIR --psql 'docker exec -i rift-capture-db psql -U rift'

Expects the manager sample.admin / capture-pass, the viewer maria.silva / capture-pass and the access code 'testcode1'.
"""
import argparse
import base64
import email
import glob
import os
import re
import subprocess
import sys
import time

sys.path.insert(0, os.environ.get('PLAYWRIGHT_PY', ''))
CHROMIUM = os.environ.get('CHROMIUM', '/usr/bin/chromium')
ok_all = True


def check(name, cond, extra=''):
    global ok_all
    print(('PASS ' if cond else 'FAIL ') + name + (f'   {extra}' if extra and not cond else ''))
    ok_all = ok_all and bool(cond)


def main():
    from playwright.sync_api import sync_playwright
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', default='http://127.0.0.1:8099')
    ap.add_argument('--maildir', required=True)
    ap.add_argument('--psql', required=True, help='command that runs psql against the throwaway database, reading SQL on stdin')
    a = ap.parse_args()
    B = a.base

    def sql(q):
        return subprocess.run(a.psql.split() + ['-tAq', '-c', q], capture_output=True, text=True).stdout.strip()

    def mails():
        out = []
        for f in sorted(glob.glob(a.maildir + '/*.eml')):
            m = email.message_from_bytes(open(f, 'rb').read())
            body = m.get_payload(decode=True).decode('utf-8', 'replace') if not m.is_multipart() else ''
            subj = email.header.decode_header(m['Subject'])[0]
            subj = subj[0].decode(subj[1] or 'utf-8') if isinstance(subj[0], bytes) else subj[0]
            out.append({'to': m['To'], 'from': m['From'], 'subject': subj, 'body': body})
        return out

    for f in glob.glob(a.maildir + '/*.eml'):
        os.remove(f)
    sql("DELETE FROM admin_users WHERE username LIKE 'zz.%'")
    sql("DELETE FROM auth_attempts")
    sql("DELETE FROM admin_login_failures")
    sql("UPDATE settings SET value = 'on' WHERE key = 'display_autologin'")
    sql("DELETE FROM device_seen")
    waiting0 = int(sql("SELECT count(*) FROM admin_users WHERE status = 'pending'"))      # requests that are there before the test

    with sync_playwright() as p:
        b = p.chromium.launch(executable_path=CHROMIUM, args=['--no-sandbox'])

        def ctx():
            return b.new_context(viewport={'width': 1200, 'height': 900}, locale='pt-PT')

        # ------------------------------------------------------------ anonymous: everything leads to the sign-in page
        c = ctx(); pg = c.new_page()
        pg.goto(B + '/reunioes?week=2026-10-11#r-2026-10-11-1130'); pg.wait_for_load_state('networkidle')
        check('anonymous /reunioes lands on the sign-in page', pg.url.startswith(B + '/entrar'), pg.url)
        check('the card link survives the redirect (fragment)', pg.evaluate('document.getElementById("loginnext").value') == '/reunioes?week=2026-10-11#r-2026-10-11-1130',
              pg.evaluate('document.getElementById("loginnext").value'))
        check('no data on the sign-in page', 'Reunião das' not in pg.inner_text('body'))

        # wrong password: the generic message
        pg.fill('#username', 'maria.silva'); pg.fill('#password', 'wrong-password-1'); pg.click('button.primary'); pg.wait_for_load_state('networkidle')
        check('wrong password: generic message', 'incorretos' in pg.inner_text('body'))

        # ------------------------------------------------------------ sign-up
        pg.goto(B + '/registar'); pg.wait_for_load_state('networkidle')
        def signup(page, username, code='testcode1', email_addr='zz@example.org', pw='uma palavra-passe', pw2=None, hp=''):
            page.goto(B + '/registar'); page.wait_for_load_state('networkidle')
            page.fill('#name', 'Maria Teste'); page.fill('#email', email_addr); page.fill('#username', username)
            page.fill('#password', pw); page.fill('#password2', pw2 or pw); page.fill('#code', code)
            if hp:
                page.evaluate("v => { document.getElementById('ref_empresa').value = v }", hp)
            page.click('button.primary'); page.wait_for_load_state('networkidle')
            return page.inner_text('body')
        t = signup(pg, 'zz.novo', code='errado')
        check('sign-up: wrong access code is refused', 'código de acesso não está correto' in t)
        check('...and nothing was created', sql("SELECT count(*) FROM admin_users WHERE username='zz.novo'") == '0')
        t = signup(pg, 'zz.novo', pw2='outra palavra-passe')
        check('sign-up: passwords must match', 'não coincidem' in t)
        t = signup(pg, 'zz.novo', pw='curta', pw2='curta')
        check('sign-up: short password refused', 'pelo menos' in t)
        t = signup(pg, 'zz.novo', email_addr='nao-e-um-email')
        check('sign-up: bad email refused', 'email válido' in t or 'email' in t.lower())
        t = signup(pg, 'zz.robot', hp='http://spam.example')
        check('sign-up: the hidden field makes a robot look successful but creates nothing', 'pedido foi enviado' in t and sql("SELECT count(*) FROM admin_users WHERE username='zz.robot'") == '0')
        t = signup(pg, 'zz.novo')
        check('sign-up: accepted, shows the waiting message', 'pedido foi enviado' in t)
        check('...stored as pending viewer', sql("SELECT status || '/' || role || '/' || coalesce(email,'') FROM admin_users WHERE username='zz.novo'") == 'pending/viewer/zz@example.org')
        time.sleep(1.2)
        notices = [m for m in mails() if 'novo pedido de conta' in m['subject']]
        check('managers got an email', len(notices) == 1 and notices[0]['to'] == 'root@altair.dannyps.net' and 'zz.novo' in notices[0]['body'], notices)
        check('...from rift@altair.dannyps.net', notices and 'rift@altair.dannyps.net' in notices[0]['from'])
        t = signup(pg, 'zz.novo')
        check('sign-up: a taken username is refused', 'já estão em uso' in t)
        t = signup(pg, 'zz.outro', email_addr='ZZ@example.org')
        check('sign-up: a taken email is refused (any case)', 'já estão em uso' in t)

        # ------------------------------------------------------------ pending: locked
        def login(page, user, pw='uma palavra-passe'):
            page.goto(B + '/entrar'); page.wait_for_load_state('networkidle')
            page.fill('#username', user); page.fill('#password', pw); page.click('button.primary'); page.wait_for_load_state('networkidle')
            return page.inner_text('body')
        t = login(pg, 'zz.novo')
        check('pending account cannot sign in (right password): says it waits', 'aguarda aprovação' in t)
        check('...and has no session', pg.request.get(B + '/api/services').status == 401)
        t = login(pg, 'zz.novo', 'palavra-errada-12')
        check('pending account with a wrong password gets only the generic message', 'incorretos' in t and 'aprovação' not in t)

        # ------------------------------------------------------------ the manager approves (badge, list, approve as viewer)
        m = ctx(); mp = m.new_page()
        login(mp, 'sample.admin', 'capture-pass')
        check('manager lands in the admin area', '/admin/reunioes' in mp.url, mp.url)
        badge = mp.locator('.nav .count-badge').first
        check('badge shows the pending count in the menu', badge.count() == 1 and badge.inner_text().strip().endswith(str(waiting0 + 1)), badge.inner_text() if badge.count() else 'none')
        mp.goto(B + '/admin/utilizadores'); mp.wait_for_load_state('networkidle')
        check('pending request is listed with name and email', 'Maria Teste' in mp.inner_text('#pendentes ~ .pending-list') and 'zz@example.org' in mp.inner_text('#pendentes ~ .pending-list'))
        card = mp.locator('.pending-list article', has_text='zz.novo')
        card.locator('select').select_option('viewer'); card.locator('button.primary').click(); mp.wait_for_load_state('networkidle')
        check('approved: active viewer', sql("SELECT status || '/' || role FROM admin_users WHERE username='zz.novo'") == 'active/viewer')
        time.sleep(1.2)
        check('the person got an approval email', any('aprovada' in x['subject'] and x['to'] == 'zz@example.org' for x in mails()))

        # ------------------------------------------------------------ the viewer
        v = ctx(); vp = v.new_page()
        vp.goto(B + '/reunioes?week=2026-10-11#r-2026-10-11-1130'); vp.wait_for_load_state('networkidle')
        vp.fill('#username', 'zz.novo'); vp.fill('#password', 'uma palavra-passe'); vp.click('button.primary'); vp.wait_for_load_state('networkidle'); vp.wait_for_timeout(600)
        check('viewer lands on the card he was sent (fragment kept through login)', vp.url == B + '/reunioes?week=2026-10-11#r-2026-10-11-1130', vp.url)
        check('viewer sees the data', vp.locator('.card').count() > 0)
        check('the header shows who is signed in', 'Maria Teste' in vp.locator('.userchip').inner_text())
        check('viewer reaches the charts page', vp.request.get(B + '/api/chart?range=1h').status == 200)
        r = vp.request.get(B + '/admin/reunioes', max_redirects=0)
        check('viewer cannot open the admin area', r.status in (302, 303, 403), r.status)
        vp.goto(B + '/admin/utilizadores'); vp.wait_for_load_state('networkidle')
        check('...he is told it is for administrators', 'só para administradores' in vp.inner_text('body') or '/reunioes' in vp.url)
        check('viewer cannot read admin statistics', vp.request.get(B + '/admin/api/stats').status == 403)
        r = vp.request.post(B + '/admin/api/report', form={'csrf': 'x', 'day': '2026-10-11', 'time': '11:30'})
        check('viewer cannot save a report', r.status == 403, r.status)
        me = vp.request.get(B + '/admin/api/me').json()
        check('/api/me says canReport false for a viewer', me.get('canReport') is False and me.get('role') == 'viewer', me)
        vp.goto(B + '/reunioes?week=2026-10-11'); vp.wait_for_load_state('networkidle')
        vp.locator('.info-btn:not([disabled])').first.click(); vp.wait_for_timeout(500)
        check('report dialog tells a viewer nothing is saved', 'só permite consulta' in vp.inner_text('#reportIntro'), vp.inner_text('#reportIntro'))
        vp.keyboard.press('Escape')

        # cookie attributes
        cookies = [c_ for c_ in v.cookies() if c_['name'] == 'rift_session']
        check('session cookie: path /, HttpOnly, SameSite=Lax, ~30 days', bool(cookies) and cookies[0]['path'] == '/' and cookies[0]['httpOnly'] and cookies[0]['sameSite'] == 'Lax'
              and abs((cookies[0]['expires'] - time.time()) - 30 * 86400) < 600, cookies)

        # sign out
        vp.goto(B + '/reunioes'); vp.wait_for_load_state('networkidle')
        vp.click('.userchip button'); vp.wait_for_load_state('networkidle')
        check('sign out leads to the sign-in page', vp.url.startswith(B + '/entrar'), vp.url)
        check('...and the session is gone', vp.request.get(B + '/api/services').status == 401)

        # ------------------------------------------------------------ disabling takes effect at once
        v2 = ctx(); v2p = v2.new_page(); login(v2p, 'zz.novo')
        check('signed in again', v2p.request.get(B + '/api/services').status == 200)
        mp.goto(B + '/admin/utilizadores'); mp.wait_for_load_state('networkidle')
        row = mp.locator('.users article', has_text='zz.novo').last
        row.locator('summary').click()
        row.locator('button.danger', has_text='Bloquear').click(); mp.wait_for_load_state('networkidle')
        check('blocked account loses access at once (open session ends)', v2p.request.get(B + '/api/services').status == 401)
        t = login(ctx().new_page(), 'zz.novo')
        check('...and sees the "desativada" message with the right password', 'desativada' in t)
        mp.goto(B + '/admin/utilizadores'); mp.wait_for_load_state('networkidle')
        row = mp.locator('.users article', has_text='zz.novo').last
        row.locator('summary').click(); row.locator('button', has_text='Reativar').click(); mp.wait_for_load_state('networkidle')
        check('reactivated', sql("SELECT status FROM admin_users WHERE username='zz.novo'") == 'active')

        # ------------------------------------------------------------ password reset by email
        for f in glob.glob(a.maildir + '/*.eml'):
            os.remove(f)
        r1 = ctx(); r1p = r1.new_page(); login(r1p, 'zz.novo')               # a second session that must end
        rp = ctx().new_page(); rp.goto(B + '/esqueci-palavra-passe'); rp.wait_for_load_state('networkidle')
        rp.fill('#email', 'zz@example.org'); rp.click('button.primary'); rp.wait_for_load_state('networkidle')
        check('reset request: neutral answer', 'Se existir uma conta ativa' in rp.inner_text('body'))
        rp.goto(B + '/esqueci-palavra-passe'); rp.fill('#email', 'ninguem@example.org'); rp.click('button.primary'); rp.wait_for_load_state('networkidle')
        check('reset request for an unknown address: the same answer', 'Se existir uma conta ativa' in rp.inner_text('body'))
        time.sleep(1.5)
        resets = [x for x in mails() if 'reposição' in x['subject']]
        check('exactly one reset mail (none for the unknown address)', len(resets) == 1 and resets[0]['to'] == 'zz@example.org', resets)
        link = re.search(r'https://rift\.dannyps\.net(/repor-palavra-passe\?token=[0-9a-f]{64})', resets[0]['body'])
        check('the mail has a one-time link to the fixed site address', bool(link))
        rp.goto(B + link.group(1)); rp.wait_for_load_state('networkidle')
        rp.fill('#p1', 'nova palavra-passe 1'); rp.fill('#p2', 'nova palavra-passe 2'); rp.click('button.primary'); rp.wait_for_load_state('networkidle')
        check('reset: mismatching passwords refused', 'não coincidem' in rp.inner_text('body'))
        rp.fill('#p1', 'nova palavra-passe 1'); rp.fill('#p2', 'nova palavra-passe 1'); rp.click('button.primary'); rp.wait_for_load_state('networkidle')
        check('reset: done, back at the sign-in page with a message', rp.url.startswith(B + '/entrar') and 'Palavra-passe alterada' in rp.inner_text('body'), rp.url)
        check('reset: the old password no longer works', 'incorretos' in login(ctx().new_page(), 'zz.novo'))
        check('reset: the new one does', login(ctx().new_page(), 'zz.novo', 'nova palavra-passe 1') is not None)
        check('reset: other open sessions ended', r1p.request.get(B + '/api/services').status == 401)
        rp.goto(B + link.group(1)); rp.wait_for_load_state('networkidle')
        check('reset link works only once', 'já foi usada' in rp.inner_text('body'))
        rp.goto(B + '/repor-palavra-passe?token=' + 'ab' * 32); rp.wait_for_load_state('networkidle')
        check('a made-up token is refused', 'não é válida' in rp.inner_text('body'))
        sql("UPDATE password_resets SET expires_at = now() - interval '1 minute'")
        rp.goto(B + '/esqueci-palavra-passe'); rp.fill('#email', 'zz@example.org'); rp.click('button.primary'); rp.wait_for_load_state('networkidle')
        check('reset requests are limited per address (3 per hour): the 4th still looks the same', 'Se existir uma conta ativa' in rp.inner_text('body'))

        # ------------------------------------------------------------ my account
        ac = ctx(); acp = ac.new_page(); login(acp, 'zz.novo', 'nova palavra-passe 1')
        acp.goto(B + '/conta'); acp.wait_for_load_state('networkidle')
        check('account page loads for a viewer', 'A minha conta' in acp.inner_text('body') and 'Só consulta' in acp.inner_text('body'))
        acp.fill('#name', 'Maria Nova'); acp.fill('#email', 'zz.novo@example.org'); acp.click('form:has(input[value=profile]) button.primary'); acp.wait_for_load_state('networkidle')
        check('profile saved', sql("SELECT name || '/' || email FROM admin_users WHERE username='zz.novo'") == 'Maria Nova/zz.novo@example.org')
        acp.fill('#c', 'nova palavra-passe 1'); acp.fill('#n1', 'terceira palavra-passe'); acp.fill('#n2', 'terceira palavra-passe')
        acp.click('form:has(input[value=password]) button.primary'); acp.wait_for_load_state('networkidle')
        check('own password change keeps this session', acp.request.get(B + '/api/services').status == 200)

        # ------------------------------------------------------------ the counter's network
        ar = b.new_context().request
        h = {'X-Test-Remote-Addr': '2.80.147.204'}
        check('before the counter has posted, its address is a stranger', ar.get(B + '/api/services', headers=h).status == 401)
        sql("INSERT INTO device_seen (id, ip, seen_at) VALUES (1, '2.80.147.204', now()) ON CONFLICT (id) DO UPDATE SET ip = excluded.ip, seen_at = excluded.seen_at")
        check('the counter network is signed in (read only): api', ar.get(B + '/api/services', headers=h).status == 200)
        check('...the pages too', ar.get(B + '/reunioes', headers=h, max_redirects=0).status == 200)
        me = ar.get(B + '/admin/api/me', headers=h).json()
        check('...as the built-in display account, cannot report', me.get('display') is True and me.get('canReport') is False, me)
        check('...another address is still a stranger', ar.get(B + '/api/services', headers={'X-Test-Remote-Addr': '9.9.9.9'}).status == 401)
        check('...no admin area for the screen', ar.get(B + '/admin/reunioes', headers=h, max_redirects=0).status in (302, 303))
        check('...no admin statistics', ar.get(B + '/admin/api/stats', headers=h).status == 403)
        check('...and nobody can sign in as the built-in account', 'incorretos' in login(ctx().new_page(), 'contador', '!'))
        sql("UPDATE settings SET value = 'off' WHERE key = 'display_autologin'")
        check('switched off: the counter network is a stranger again', ar.get(B + '/api/services', headers=h).status == 401)
        sql("UPDATE settings SET value = 'on' WHERE key = 'display_autologin'")
        sql("UPDATE device_seen SET seen_at = now() - interval '8 days'")
        check('last post 8 days ago: stale, a stranger', ar.get(B + '/api/services', headers=h).status == 401)
        sql("DELETE FROM device_seen")

        # ------------------------------------------------------------ throttles
        for f in glob.glob(a.maildir + '/*.eml'):
            os.remove(f)
        sql("DELETE FROM auth_attempts")
        tp = ctx().new_page()
        for i in range(10):
            signup(tp, f'zz.code{i}', code='nope')
        t = signup(tp, 'zz.codeok', code='testcode1')
        check('too many wrong access codes lock sign-up for that address', 'Demasiados pedidos' in t, t[:120])
        sql("DELETE FROM auth_attempts")
        tp2 = ctx().new_page()
        for i in range(5):
            signup(tp2, f'zz.rate{i}', email_addr=f'rate{i}@example.org')
        t = signup(tp2, 'zz.rate9', email_addr='rate9@example.org')
        check('more than 5 sign-ups an hour from one address are refused', 'Demasiados pedidos' in t, t[:120])

        # ------------------------------------------------------------ rejecting a request
        mp.goto(B + '/admin/utilizadores'); mp.wait_for_load_state('networkidle')
        n = int(sql("SELECT count(*) FROM admin_users WHERE status='pending'"))
        check('five new sign-ups are waiting', n == waiting0 + 5, n)
        card = mp.locator('.pending-list article', has_text='zz.rate').first
        mp.once('dialog', lambda d: d.accept())
        card.locator('button.danger').click(); mp.wait_for_load_state('networkidle')
        check('rejecting removes the request', int(sql("SELECT count(*) FROM admin_users WHERE status='pending'")) == n - 1)

        # ------------------------------------------------------------ access code
        mp.goto(B + '/admin/utilizadores'); mp.wait_for_load_state('networkidle')
        mp.locator('summary', has_text='Alterar').first.click()
        mp.fill('#code', 'novo-codigo-123'); mp.locator('form:has(input[value=set_code]) button').click(); mp.wait_for_load_state('networkidle')
        check('the manager changed the access code', sql("SELECT value FROM settings WHERE key='signup_code'") == 'novo-codigo-123')

        b.close()
    sql("DELETE FROM admin_users WHERE username LIKE 'zz.%'")
    sql("UPDATE settings SET value = 'testcode1' WHERE key = 'signup_code'")
    print('ALL OK' if ok_all else 'FAILURES')
    sys.exit(0 if ok_all else 1)


if __name__ == '__main__':
    main()
