# RIFT people counter

Receives people-counter data from a RIFT device, stores it in PostgreSQL and shows it on
https://rift.dannyps.net (interface in European Portuguese): charts, and the attendance of each
church meeting. Runs on `altair` (Apache + mod_php, Postgres in Docker).

## Pages and endpoints

| URL | What |
|---|---|
| `/` | charts: entries/exits per interval, and the estimated balance of people inside |
| `/reunioes` | attendance per meeting for a week (Sunday to Saturday), plus event cards |
| `/api/chart`, `/api/services` | JSON for those pages |
| `/admin` | login-protected admin area (below) |
| `/load_from_request.php` | where the device POSTs; requires its `Authorization` token |

Clean URLs are Apache rewrite rules in the vhost (`deploy/apache/`). `sql/`, `src/` and the CLI scripts are not served.

## How it works

1. The device POSTs a JSON document once a minute (about 4 minutes after the minute it describes). The
   `Authorization` header must equal `/etc/rift-data-load/auth-token`.
2. `load_from_request.php` validates it, appends it to `/var/lib/rift-data-load/records` (archive, one JSON
   line each) and inserts it into `records` in one transaction. Each request outcome is logged to
   `ingest.log`. Re-delivered entries are ignored.
3. The counters are **cumulative**. `balance_entries()` (`sql/seed.sql`) turns them into per-reading
   changes and a running balance of people inside. The balance restarts at zero every day at 04:00
   Europe/Lisbon, and on the first day in the data it starts at `OPENING_BALANCE` (`src/balance.php`).
4. A meeting's count is the peak balance in its window (start to start + duration, default 2 h). On days
   without meetings, more than 5 people inside makes an "event". Results are saved in `service_snapshots`
   / `event_snapshots`, updated while running and frozen 10 minutes after the window ends
   (`src/services.php`). The database and code call meetings "services"; the interface says "reuniões".

## Admin area (`/admin`)

A login form (`admin.php`, `src/auth.php`; pages in Portuguese). Accounts are in `admin_users`; the only
privilege is **can manage users**. Everyone can edit weekly meetings, special days and corrections, and
change their own password; users with the privilege also add and remove users, reset passwords and grant
or withdraw the privilege (there must always be at least one such user, and you can't remove yourself).
Every change is written to the audit log (`/admin/registo`).

- Passwords: argon2id, at least 10 characters. An Apache `$apr1$` hash imported from `.htpasswd` works once and
  is upgraded at the first login. Five failed attempts per user (30 per IP) lock logins for 15 minutes.
- Session cookie: Secure, HttpOnly, SameSite=Strict, limited to `/admin`, 8 h idle timeout; every POST needs a
  CSRF token; a strict CSP (no inline scripts or styles).
- First users: `php admin_user.php import-htpasswd dannyps --manage` (reuses the `/internal` password),
  `php admin_user.php create USER [--manage]`, `php admin_user.php list`.

## Configuration

- Weekly meetings (table `services`, seeded: Sundays 09:30, 11:30, 17:30), special days and count corrections
  are edited in the admin area. A special day replaces that date's meetings. A correction (with a note) wins
  over the computed peak; "Recalcular" deletes the saved row so it is recomputed.
- The same from the shell: `php special_day.php add 2026-12-19 "Especial de Natal" 11:30 17:30 [--duration=120]`,
  `list`, `remove DATE`.
- Secrets live outside the repo: `/etc/rift-data-load/db.ini` and `auth-token` (root:www-data, 640).
- The schema is created and migrated automatically (`sql/utils.php`, `SCHEMA_VERSION`; bump it together
  with the comment on `balance_entries()` when that function changes).

## Operations

Reference copies of everything installed outside this repo are in `deploy/`.

- Database: `deploy/postgres-compose.yml` (container `rift-postgres-db-1`, 127.0.0.1:5433, data and `.env`
  in `/media/ssd/www/rift-postgres/`).
- `rift-postgres-backup.timer` (03:45): `pg_dump` into `rift-postgres/backups/`, verified, newest 14 plus
  Sundays for 8 weeks. Restore: `pg_restore -U rift -d <db> --no-owner < backups/rift-DATE.dump`.
  Same disk as the database: no off-host copy yet.
- `rift-snapshot.timer` (5 min): `snapshot.php` keeps meeting snapshots fresh and frozen.
- `rift-monitor.timer` (1 min): emails root@altair.dannyps.net (Roundcube folder `altair.dannyps.net`) when
  no data has arrived for 10 minutes, every 6 h while it persists, and when it recovers.
- `php load_files.php [file...]` replays an archive (also `.gz`) into the database; safe to repeat.
- logrotate keeps 52 weekly `records` and `ingest.log` files (`deploy/logrotate`).

## Notes

- The device clock is correct, but it reports each minute about 4 minutes late, so "last reading" is
  always a few minutes behind.
- A balance is an estimate: counting errors accumulate within a day and are cleared at 04:00.
