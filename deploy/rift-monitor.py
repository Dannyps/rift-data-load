#!/usr/bin/env python3
"""Email alerts for the RIFT people counter (@DOMAIN@), same channel as atento-monitor.

A systemd timer runs this every 60 s. The counter posts once a minute, so "no row received for
GRACE seconds" (default 10 min) means the device, its network, the token or the database is broken:
  * problem   : nothing received for GRACE seconds (also if the database cannot be queried)
  * reminder  : still broken, every REMIND seconds (default 6 h) after the last alert
  * recovered : data is flowing again, only if a problem alert had been sent
Mail goes to root@altair.dannyps.net (-> Roundcube folder altair.dannyps.net) through the local
sendmail shim. State: /var/lib/rift-monitor/state.json; a failed send is retried next run.
A second check does the same for the Planning Center sync (rift-pco-sync.timer, every 30 minutes):
  * problem   : no successful sync for PCO_GRACE seconds (default 6 h), with the last error
  * reminder / recovered as above (state: /var/lib/rift-monitor/pco.json)
Env overrides (testing only): RIFT_STATE_DIR, RIFT_GRACE, RIFT_PCO_GRACE, RIFT_REMIND (seconds), RIFT_SUBJECT_PREFIX.
"""
import email.utils
import json
import os
import subprocess
import sys
import time

STATE_DIR = os.environ.get("RIFT_STATE_DIR", "/var/lib/rift-monitor")
GRACE = int(os.environ.get("RIFT_GRACE", 600))
PCO_GRACE = int(os.environ.get("RIFT_PCO_GRACE", 6 * 3600))
REMIND = int(os.environ.get("RIFT_REMIND", 6 * 3600))
PREFIX = os.environ.get("RIFT_SUBJECT_PREFIX", "")
SENDER = "RIFT monitor <root@altair.dannyps.net>"
COMPOSE_DIR = "@DB_DIR@"
INGEST_LOG = "/var/lib/rift-data-load/ingest.log"


def psql(sql):
    """(first value of the query, None) or (None, reason)."""
    try:
        out = subprocess.run(
            ["docker", "compose", "--project-directory", COMPOSE_DIR, "exec", "-T", "db", "psql", "-U", "rift", "-d", "rift", "-Atc", sql],
            capture_output=True, text=True, timeout=30)
    except Exception as e:
        return None, f"cannot query the database ({type(e).__name__})"
    if out.returncode != 0:
        return None, "cannot query the database: " + (out.stderr.strip() or "no data")[:300]
    return out.stdout.strip(), None


def age_of_last_data():
    """(seconds since the last row was received, None) or (None, reason)."""
    value, error = psql("select extract(epoch from now() - max(received_at)) from records")
    if error or not value:
        return None, error or "cannot query the database: no data"
    return float(value), None


def pco_status():
    """(seconds since the last good Planning Center sync, last error) or (None, reason)."""
    value, error = psql("select coalesce(extract(epoch from now() - last_ok)::text, '') || '|' || coalesce(error, '') from pco_sync_status where id = 1")
    if error is not None:
        return None, error
    age, _, err = (value or "|").partition("|")
    return (float(age) if age else None), err


def recent_rejections():
    """The last few non-200 requests from the ingest log: usually says why data stopped."""
    try:
        lines = open(INGEST_LOG).read().splitlines()[-200:]
    except Exception:
        return ""
    bad = [l for l in lines if '"status":200' not in l][-3:]
    return ("\n\nRecent rejected requests:\n" + "\n".join(bad)) if bad else ""


def send(subject, text):
    msg = (f"From: {SENDER}\nTo: root\nSubject: {PREFIX}{subject}\n"
           f"Date: {email.utils.formatdate(localtime=True)}\nX-Rift-Monitor: yes\n"
           f"Content-Type: text/plain; charset=utf-8\n\n{text}\n")
    return subprocess.run(["/usr/sbin/sendmail", "-t"], input=msg.encode(), timeout=60).returncode == 0


def run_check(name, healthy, bad_since_hint, detail, subject_bad, subject_still, subject_ok, recovered_text):
    """Alert state machine for one check (state in STATE_DIR/<name>.json)."""
    path = os.path.join(STATE_DIR, name + ".json")
    try:
        st = json.load(open(path))
    except Exception:
        st = {}
    now = time.time()
    if healthy:
        if st.get("alerted"):
            mins = int((now - st["bad_since"]) / 60)
            if send(subject_ok, recovered_text.format(mins=mins)):
                st = {}
        else:
            st = {}
    else:
        st.setdefault("bad_since", bad_since_hint if bad_since_hint else now)
        if not st.get("alerted"):
            if send(subject_bad, detail):
                st["alerted"], st["last_alert"] = True, now
        elif now - st.get("last_alert", 0) >= REMIND:
            if send(subject_still, detail):
                st["last_alert"] = now
    json.dump(st, open(path, "w"))


def main():
    os.makedirs(STATE_DIR, exist_ok=True)
    now = time.time()

    # 1. data from the counter
    age, error = age_of_last_data()
    healthy = error is None and age is not None and age < GRACE
    if error:
        detail, hint = error, None
    else:
        detail = f"No data received for {int(age // 60)} minutes (the counter normally posts every minute)."
        hint = now - age
    detail += recent_rejections() + "\n\nCheck the counter and its network, then https://@DOMAIN@/ and /var/lib/rift-data-load/ingest.log on altair."
    run_check("state", healthy, hint, detail, "RIFT counter: no data", "RIFT counter: still no data", "RIFT counter: recovered",
              "Data is arriving again (it had stopped for about {mins} minutes).")

    # 2. Planning Center sync
    pco_age, pco_err = pco_status()
    if pco_age is None and (pco_err or "").startswith("cannot query"):
        pco_healthy = True      # a database problem is reported by the data check above, not twice
    elif pco_age is None:
        pco_healthy = not pco_err   # never synced: fine unless an error was recorded (not configured yet)
    else:
        pco_healthy = pco_age < PCO_GRACE
    detail = ("The Planning Center sync has not succeeded for " + (f"{int(pco_age // 3600)} hours" if pco_age is not None else "a long time")
              + ".\nLast error: " + (pco_err or "none recorded")
              + "\n\nSpecial days and the people on the meeting cards are not being refreshed (what is saved stays in use)."
              + "\nTry: php @APP_DIR@/pco_sync.php   (the token is in /etc/rift-data-load/pco.ini)")
    run_check("pco", pco_healthy, (now - pco_age) if pco_age is not None else None, detail,
              "RIFT Planning Center: sync failing", "RIFT Planning Center: sync still failing", "RIFT Planning Center: recovered",
              "The Planning Center sync works again (it had been failing for about {mins} minutes).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
