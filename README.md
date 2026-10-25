# RIFT people counter

Receives people-counter data from a RIFT device, stores it in PostgreSQL and shows it on
https://rift.dannyps.net (interface in European Portuguese): charts, and the attendance of each
church meeting. Runs on `altair` (Apache + mod_php, Postgres in Docker).

## Pages and endpoints

| URL | What |
|---|---|
| `/` | charts: entries/exits per interval, and the estimated balance of people inside (needs an account) |
| `/reunioes` | attendance per meeting for a week (**Monday to Sunday**), event cards, and the week's totals at the bottom (needs an account) |
| `/api/chart`, `/api/services` | JSON for those pages (401 without an account) |
| `/entrar`, `/registar`, `/esqueci-palavra-passe`, `/repor-palavra-passe`, `/sair`, `/conta` | sign in, sign up, password reset, sign out, my account (the only pages a stranger can open) |
| `/admin` | admin area, admins only (below) |
| `/load_from_request.php` | where the device POSTs; requires its `Authorization` token (no account involved) |

Clean URLs are Apache rewrite rules in the vhost (`deploy/apache/`). `/` and `/reunioes` go through `gate.php` (serves the static
page only to an account), the account pages are `account.php`. `sql/`, `src/`, `deploy/`, `tools/`, `fixtures/` and the CLI scripts are not served.

## How it works

1. The device POSTs a JSON document once a minute (about 4 minutes after the minute it describes). The
   `Authorization` header must equal `/etc/rift-data-load/auth-token`.
2. `load_from_request.php` validates it, appends it to `/var/lib/rift-data-load/records` (archive, one JSON
   line each) and inserts it into `records` in one transaction. Each request outcome is logged to
   `ingest.log`. Re-delivered entries are ignored.
3. The counters are **cumulative**. `balance_entries()` (`sql/seed.sql`) turns them into per-reading
   changes and a running balance of people inside. The balance restarts at zero every day at 04:00
   Europe/Lisbon, or when an admin presses "Repor saldo a zero agora" (Admin → Contadores, after confirming that the
   building is empty; effective from the first reading stamped after the click; can be undone), and on the first day
   in the data it starts at `OPENING_BALANCE` (`src/balance.php`).
4. A meeting's count is the peak balance in its window (start to start + duration, default 2 h). On days
   without meetings, more than 5 people inside makes an "event": a stretch of readings above 5 (dips of
   up to 15 minutes do not split it) that does not touch any meeting window of that day; a stretch that
   does, such as early arrivals who stay for the meeting, is the meeting's own crowd. One event per day (the
   stretch with the highest peak). Results are saved in `service_snapshots` / `event_snapshots`, updated
   while running and frozen 10 minutes after the window ends (`src/services.php`, `event_stretch()`;
   tests: `php tests/services_event_test.php`). The database and code call meetings "services"; the interface says "reuniões".

## Meeting report (info button on each card)

Every card on `/reunioes` has a clipboard-icon button (disabled until 2 hours before the meeting starts: `REPORT_OPEN_BEFORE_MIN` in `src/services.php`, checked again on the server when saving; the API gives each meeting a `reportOpen` flag so the browser's clock does not matter) that opens a dialog (all in `reunioes.html`, nothing is saved or sent to
the server for anonymous visitors). For a meeting: seven counts (Assistência, Voluntários, Visitantes, Adultos fora das portas, Crianças no Kids,
Crianças/bebés no auditório, Decisões) and the four names (MC, Oferta, Líder Louvor, Pregação, pre-filled from the
card), all required; "Gerar texto" builds a WhatsApp-style (`*bold*`) report starting with a title line and an
`*Assistência:* N` line (the counter's count for the meeting, pre-filled and correctable), and "Copiar"
puts it on the clipboard. For an event card the text is just the peak count.
**When a logged-in admin user generates the text, the report is also saved** (`meeting_reports`) and loaded next time; the
"Voluntários" field is suggested from the devotional head-count. The page asks `/admin/api/me` whether you are logged in
(the session cookie only travels to `/admin/...`). `buildReport()` is the text builder.

## Statistics (`/admin/estatisticas`, login required)

Week (Monday to Sunday; per meeting: attendance vs the same meeting last week, late arrivals, devotional head-count, saved report numbers;
the Sunday total; exit speed; a progression chart per meeting with last week's dashed), month (averages per meeting slot,
previous month beside it) and year (bars Sunday after Sunday: total attendance, volunteers, visitors, kids, decisions;
every chart has a table view). Charts are plain SVG in `assets/stats.js`; data comes from `/admin/api/stats` (`src/stats_pages.php`).

| Metric | Definition (`src/stats.php`, tested in `tests/stats_test.php`) |
|---|---|
| Attendance | the saved report's Assistência, else a manual correction, else the counter's peak |
| Late arrivals | share of the counter's arrivals in (start−60 min, end] that come more than the *grace* after the start; hidden when the counter began after the meeting did (partial) |
| Devotional | highest balance within 5 min after the plan's `Devocional` time (Planning Center plan time; fallback setting 08:43 / 16:58); suggested as Voluntários |
| Exit speed | minutes from 13:00 and 19:00 until the balance is ≤ 5 (0 if already; «não atingiu» after 3 h; «sem dados» if no readings) |
| Sunday total | the Sunday's meetings added together (someone at two meetings counts twice) |

The grace (default 10 min), the devotional fallbacks, the exit hours and the threshold are in the *Definições* box on that page
(`settings` table). **Changing a setting never rewrites the past:** each meeting's numbers are saved with it (and frozen with it),
including the grace they were computed with. Early departures are not implemented (they need the real end time of each meeting).
The year chart starts on 11 Oct 2026, the first day of counter data.

## Accounts, sign-up and the admin area

**Nothing on the site is visible without an account** (except the sign-in, sign-up and password pages and the static assets). A
visitor without a session is sent to `/entrar?next=<where they were going>` (a shared card link, `/reunioes#r-...`, survives the
login: `assets/auth.js` adds the `#` part to the form); the JSON APIs answer 401 and the pages then go to the sign-in page. Code:
`src/auth.php` (accounts, sessions, throttles, reset tokens), `account.php` (public pages), `gate.php` (the two pages),
`admin.php` (admin area), `src/mail.php`, `assets/auth.js`. Pages are in Portuguese. Accounts are in `admin_users`:

- **role** `admin` (Administrador: every admin page; may also hold the *can manage users* privilege: users, requests, access code,
  there must always be one such user and nobody can remove or re-role themselves), `reporter` (Relatórios: reads the site and saves
  meeting reports from the dialog on `/reunioes`), `viewer` (Só consulta: only reads the charts and meetings pages). Only admins
  reach `/admin/*` (others get a redirect or 403) and `/admin/api/stats`; saving a report needs admin or reporter.
- **status** `active`, `pending` (a sign-up waiting for approval: cannot sign in, sees nothing) or `disabled` (a manager locked it;
  the session ends at once, the account is re-read on every request).
- **kind** `person`, or the built-in `display` account `contador` (see *Counter network* below).

**Sign-up** (`/registar`): name, email, username, password (10+ characters) and the shared **access code** (managers see and change it on
`/admin/utilizadores`; stored in `settings.signup_code`). Rate limits: 5 requests per IP per hour, 30 per day, 10 wrong codes per IP per
15 minutes; a hidden field catches robots. The request is stored as `pending`/`viewer`, managers get an email (to
`root@altair.dannyps.net`) and a count badge next to *Utilizadores* in the admin menu. A manager approves with a role (the person gets
an email) or rejects (deletes). Accounts made by a manager (or `php admin_user.php`) are active at once.

**Password reset** (`/esqueci-palavra-passe`): the person gives their email; if an active account has it, a one-use link (valid 1 hour,
only the SHA-256 is stored, `password_resets`) is mailed from `rift@altair.dannyps.net`; the answer is the same either way (max 3 requests
per hour per address and per IP). Setting the password ends every other session of that account (`password_changed_at`). Links use the fixed
`SITE_URL` (`src/auth.php`), never the Host header. Mail is queued in the background through `/usr/sbin/sendmail` (`src/mail.php`;
`RIFT_SENDMAIL` replaces it in tests). Accounts without an email cannot reset by mail (a manager sets a password, or they add an email in `/conta`).

**Counter network.** `load_from_request.php` records the IP the counter posts from (`device_seen`) only after its token is accepted.
Requests from that same IP (seen in the last 7 days) are treated as the read-only built-in account `contador` (the TV or kiosk beside
the counter): they read the two pages and their data, never `/admin`, and cannot save anything. `settings.display_autologin` (on/off,
switch on `/admin/utilizadores`) turns it off. Everybody behind the same public IP (guests on the wifi) gets the same read-only access;
visits over IPv6 do not match an IPv4 device address; only `REMOTE_ADDR` is used.

**Sessions:** cookie `rift_session` (Secure, HttpOnly, **SameSite=Lax** so links from other apps arrive signed in, path `/`), kept
30 days of inactivity in `/var/lib/rift-data-load/sessions` (own directory, cleaned by `tmpfiles.d` after 31 days; PHP's default
directory is emptied after 24 minutes by Debian), every POST needs a CSRF token, strict CSP on the account and admin pages.

Every change is written to the audit log (`/admin/registo`). Pages (admins): Reuniões (weekly meetings), Dias especiais (+ Planning
Center sync status), Pessoas, Correções, Contadores (manual balance reset), Estatísticas, Utilizadores (sign-up requests, access
code, counter-network switch, one card per user with the edit section collapsed), A minha conta (`/conta`, every account), Registo.

- Passwords: argon2id, at least 10 characters. An Apache `$apr1$` hash imported from `.htpasswd` works once and
  is upgraded at the first login. Five failed attempts per user (30 per IP) lock logins for 15 minutes.
- On phones the admin tables (and the month table of the statistics page) become cards: one card per row with the column
  name beside each value (`admin.js` copies the header text into `data-label`, `site.css` does the layout; add
  `class="admin"` or `"stackable"` to a table to get it, and re-run `stackTables(el)` after drawing one dynamically).
- On phones (up to 720 px) the admin navigation is a slide-in sidebar opened by a menu button (closes with Escape, the
  backdrop, a link or a wider screen); on wider screens it is the top bar.
- First users: `php admin_user.php import-htpasswd dannyps --manage` (reuses the `/internal` password),
  `php admin_user.php create USER [--manage | --reporter | --viewer]`, `php admin_user.php list`.
- Tests: `php tests/auth_test.php` (pure rules); `tools/auth_flows.py` drives the whole thing in a browser against a throwaway
  database and a fake sendmail (sign-up, approval, locked accounts, viewer limits, reset, counter network, throttles; see its header).

## Planning Center (people and special days)

`pco_sync.php` (every 30 minutes, `rift-pco-sync.timer`; also the "Sincronizar agora" button in admin) reads
Planning Center Services with the token in `/etc/rift-data-load/pco.ini` (`client_id`, `secret`; root:www-data 640).
It follows the folders Porto and Young Adults & Youth (`src/pco.php`: `PCO_ROOT_FOLDERS`), never Kids.

- **Special days:** a plan on any day except Sunday becomes a special day (2 h, named after the plan title, or the
  service type's name when it has none). A manual special day for the same date always wins; past days are never
  touched; Sundays keep the weekly meetings.
- **People on the meeting cards** (order MC, Oferta, Líder louvor, Pregador), found by fixed rules, no guessing:
  the leader is the team member in position `a) Líder Louvor`; MC, preacher and offering speaker are the "Person"
  note on the plan item whose title matches `^MC` (or "BOAS-VINDAS" on Youth/Young Adults), `PREGAÇÃO`, `^OFERTA`.
  A line like `9h30 X`, `9:30 X` or `X (9:30)` applies to that meeting only; other lines to every meeting of the
  plan. Names are shown as typed. A plan applies to the meetings of its day that start within its service times
  (the Sunday morning plan covers 09:30 and 11:30). Empty roles show "por anunciar" until the meeting is over.
  If an item has no Person note at all, its "Band" note is used instead, but only when it reads like names (mixed
  case, no stage-cue words such as BANDA/VOZES/SOBE/LUZ), because that field usually holds cues like "BANDA SOBE A
  FALTAR 5MIN". Admin → Pessoas overrides a name for one meeting.
- **Gotcha:** the Planning Center account is set to America/Los_Angeles but the times in it are Portuguese
  wall-clock times, so the sync reads them in that zone and treats them as Lisbon time.
- `php pco_sync.php --dry-run` shows what would change; `--backfill-days=70` also reads the people of older plans;
  `php tests/pco_test.php` runs the parser tests. If the sync has not worked for 6 hours the monitor emails you.

## Styling and the CSS preview (`assets/site.css`)

All styling is ONE file, `assets/site.css`: design tokens (colours, light/dark), shared rules, then the meetings page
(every rule starts with `body.reunioes`) and the admin area (`body.admin`). The JavaScript builds markup with the existing
class names, so a restyle changes the CSS only. The vhost sends `Cache-Control: no-cache` for `*.css` (revalidated, never stale).

**Preview for a CSS rework** (`https://rift-css.dannyps.net`, one label under the main domain; the Cloudflare record is
DNS only, nothing is cached): a STATIC copy of the site in its own git clone (`/media/ssd/www/rift-css`, branch `css-rework`,
owned by www-data) fed by invented JSON in `fixtures/` (`tools/make_fixtures.py`) and static admin pages captured from a
throwaway database (`fixtures/admin/`, and the sign-in, sign-up, password and account screens in `fixtures/auth/`; `tools/capture_admin_samples.py`, which also needs `--psql` for the reset-password form). The preview pretends everybody is a signed-in read-only viewer (`fixtures/admin/api-me.json`), so the header chip shows. No PHP, no database, no secrets; the device endpoint,
`/admin` writes and all source directories answer 404/403. The person doing the rework (or their Claude) reads
`https://rift-css.dannyps.net/_edit/` (Markdown briefing, also `/llms.txt`) and replaces the stylesheet with
`PUT /_edit/css` + `Authorization: Bearer <token>` (token: `/etc/rift-data-load/css-token`). Every save is a git commit on
`css-rework`; `GET /_edit/log`, `POST /_edit/revert?to=<commit>`. The API (`deploy/css-preview/api/index.php`, installed
read-only under `/usr/local/lib/rift-css-preview`) validates the CSS (balanced, UTF-8, <= 256 KB, no `@import`/external
`url()`), refuses uploads that drop more than half the selectors unless `&force=1`, warns about lost selectors/tokens, and
serialises saves with a lock. Install: `deploy/css-preview/install.sh` (dry run by default; `--base-branch`, `--docroot`,
`--certbot`). Keep it current with `deploy/css-preview/sync-from-main.sh [--docroot DIR] [BRANCH]` (merges main into
`css-rework` via a bundle, because the clone and this repository have different owners). Bring the result home with
`deploy/css-preview/promote.sh [--docroot DIR]` (fetches `css-rework` as a local branch and checks that only
`assets/site.css` changed), review, run the screenshot comparison, then merge.

**Regression screenshots** (`tools/shots.py`, needs Playwright and a Chromium): `shoot` takes 82 screenshots of every page
(phone/desktop, light/dark, meeting states, report dialog, admin drawer) with fixture data and a frozen clock, so the same CSS
gives byte-identical PNGs; `compare DIR_A DIR_B` lists the pages that differ. Admin pages come from a local server on a
THROWAWAY database: `docker run postgres:16-alpine`, `tools/fake_data.py | psql`, a `db.ini` pointed to by `RIFT_DB_CONFIG`,
`RIFT_DB_CONFIG=... php -S 127.0.0.1:8099 -t . tools/dev_router.php` (same clean URLs as the vhost), a sample admin inserted with a
password hash, then `shots.py login`. Never aim these tools at the real database.

## Configuration

- Weekly meetings (table `services`, seeded: Sundays 09:30, 11:30, 17:30), special days and count corrections
  are edited in the admin area. A special day replaces that date's meetings. A correction (with a note) wins
  over the computed peak; "Recalcular" deletes the saved row so it is recomputed.
- The same from the shell: `php special_day.php add 2026-12-19 "Especial de Natal" 11:30 17:30 [--duration=120]`,
  `list`, `remove DATE`.
- Secrets live outside the repo: `/etc/rift-data-load/db.ini` and `auth-token` (root:www-data, 640).
- The schema is created and migrated automatically (`sql/utils.php`, `SCHEMA_VERSION`; bump it together
  with the comment on `balance_entries()` when that function changes).

## Install

`deploy/install.sh` sets up everything this project needs outside the repository on a Debian/Ubuntu host (Docker
with the compose plugin must already be installed): packages and Apache modules, the Postgres container, the secrets
files in `/etc/rift-data-load`, the database schema, the systemd timers, the monitor, logrotate and the Apache vhosts
with HTTPS. It is idempotent, never overwrites secrets or data, keeps a `.bak.<time>` copy of any file it replaces,
and **only prints what it would do unless you add `--apply`**.

```bash
git clone git@github.com:Dannyps/rift-data-load.git /srv/rift/rift-data-load && cd /srv/rift/rift-data-load
sudo deploy/install.sh                                   # dry run
sudo deploy/install.sh --apply --domain rift.example.org --certbot you@example.org \
     [--auth-token-file TOKEN] [--pco-ini pco.ini] [--db-dir DIR] [--app-dir DIR]
```

Run it on the server where the site already works and it reports `0 change(s)`: the templates in `deploy/` render
to exactly what is installed. Then, by hand: point the DNS name at the host, create the first admin
(`sudo -u www-data php admin_user.php create NAME --manage`) and set the URL and the token
(`sudo cat /etc/rift-data-load/auth-token`) in the people counter. Without `--pco-ini` the Planning Center timer is not
enabled. Files in `deploy/` are templates (`@APP_DIR@`, `@DB_DIR@`, `@DOMAIN@`); edit them, not the installed copies,
and re-run the script. Never `chown` the database's `pgdata/` directory: the container owns it (uid 70).

## Scheduled jobs

systemd timers (no cron). Each has a matching service, so a failure shows in `systemctl --failed` and the journal;
they all use `Persistent=true` (a run missed while the server was down happens after boot).

| Timer | When | What it does |
|---|---|---|
| `rift-monitor` | every 60 s, root | Emails `root@altair.dannyps.net` (Roundcube folder `altair.dannyps.net`) when no reading was received for 10 min (with the last rejected requests from `ingest.log`, or the database error), or when the Planning Center sync has not succeeded for 6 h (with its last error); repeats every 6 h and sends a "recovered" mail. State in `/var/lib/rift-monitor/`. Read-only. |
| `rift-snapshot` | every 5 min, www-data | `snapshot.php`: for the current and previous week, saves each started meeting's peak balance, arrivals/late share and devotional head-count, marks it partial if the counter began late, and freezes it 10 min after the window ends; saves the exit speeds after 13:00/19:00; saves the day's event (a stretch with balance over 5 outside every meeting window) and freezes it after the day. Never touches frozen rows or manual corrections. Loading `/reunioes` does the same on demand. |
| `rift-pco-sync` | every 30 min (+≤60 s), www-data | `pco_sync.php` under a lock: reads the Porto and Young Adults & Youth folders (no Kids), the plans and their service times, the people of plans from yesterday to +21 days, saves them, deletes vanished future plans, and creates/updates/removes the Planning Center special days (manual ones win; past rows and a meeting already started are never removed). About 100 API calls, ~1 min; on a Planning Center error it records the error and changes nothing. |
| `rift-postgres-backup` | 03:45 (+≤10 min), root | `pg_dump -Fc` of the `rift` database to `rift-postgres/backups/rift-YYYYMMDD-HHMM.dump` (mode 600), kept only if `pg_restore --list` can read it; keeps the newest 14 plus every Sunday one for 8 weeks. Same disk as the database: no off-host copy. A failure fails the unit but sends no email. |

Not timers: the 04:00 balance reset is not a job: `balance_epoch()` (SQL) works out the reset from each reading's time
(Europe/Lisbon, daylight saving included). The system `certbot.timer` renews the certificate and `logrotate.timer` keeps
52 weekly `records` and `ingest.log` files.

Other operations:
- Database: container `rift-postgres-db-1`, 127.0.0.1:5433, data in `rift-postgres/pgdata`. Restore a dump:
  `pg_restore -U rift -d <db> --no-owner < backups/rift-DATE.dump`.
- `php load_files.php [file...]` replays an archive (also `.gz`) into the database; safe to repeat.
- `deploy/` holds the install script and the templates of everything installed outside the repo.

## Notes

- The device clock is correct, but it reports each minute about 4 minutes late, so "last reading" is
  always a few minutes behind.
- A balance is an estimate: counting errors accumulate within a day and are cleared at 04:00.
