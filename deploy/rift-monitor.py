#!/usr/bin/env python3
"""Email alerts for the RIFT people counter (rift.dannyps.net), same channel as atento-monitor.

A systemd timer runs this every 60 s. The counter posts once a minute, so "no row received for
GRACE seconds" (default 10 min) means the device, its network, the token or the database is broken:
  * problem   : nothing received for GRACE seconds (also if the database cannot be queried)
  * reminder  : still broken, every REMIND seconds (default 6 h) after the last alert
  * recovered : data is flowing again, only if a problem alert had been sent
Mail goes to root@altair.dannyps.net (-> Roundcube folder altair.dannyps.net) through the local
sendmail shim. State: /var/lib/rift-monitor/state.json; a failed send is retried next run.
Env overrides (testing only): RIFT_STATE_DIR, RIFT_GRACE, RIFT_REMIND (seconds), RIFT_SUBJECT_PREFIX.
"""
import email.utils
import json
import os
import subprocess
import sys
import time

STATE_DIR = os.environ.get("RIFT_STATE_DIR", "/var/lib/rift-monitor")
GRACE = int(os.environ.get("RIFT_GRACE", 600))
REMIND = int(os.environ.get("RIFT_REMIND", 6 * 3600))
PREFIX = os.environ.get("RIFT_SUBJECT_PREFIX", "")
SENDER = "RIFT monitor <root@altair.dannyps.net>"
COMPOSE_DIR = "/media/ssd/www/rift-postgres"
INGEST_LOG = "/var/lib/rift-data-load/ingest.log"


def age_of_last_data():
    """(seconds since the last row was received, None) or (None, reason)."""
    try:
        out = subprocess.run(
            ["docker", "compose", "--project-directory", COMPOSE_DIR, "exec", "-T", "db", "psql", "-U", "rift", "-d", "rift",
             "-Atc", "select extract(epoch from now() - max(received_at)) from records"],
            capture_output=True, text=True, timeout=30)
    except Exception as e:
        return None, f"cannot query the database ({type(e).__name__})"
    if out.returncode != 0 or not out.stdout.strip():
        return None, "cannot query the database: " + (out.stderr.strip() or "no data")[:300]
    return float(out.stdout.strip()), None


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


def main():
    os.makedirs(STATE_DIR, exist_ok=True)
    path = os.path.join(STATE_DIR, "state.json")
    try:
        st = json.load(open(path))
    except Exception:
        st = {}
    now = time.time()
    age, error = age_of_last_data()
    healthy = error is None and age is not None and age < GRACE

    if healthy:
        if st.get("alerted"):
            mins = int((now - st["bad_since"]) / 60)
            if send("RIFT counter: recovered", f"Data is arriving again (it had stopped for about {mins} minutes)."):
                st = {}
        else:
            st = {}
    else:
        if error:
            detail = error
            st.setdefault("bad_since", now)
        else:
            detail = f"No data received for {int(age // 60)} minutes (the counter normally posts every minute)."
            st.setdefault("bad_since", now - age)
        detail += recent_rejections() + "\n\nCheck the counter and its network, then https://rift.dannyps.net/ and /var/lib/rift-data-load/ingest.log on altair."
        if not st.get("alerted"):
            if send("RIFT counter: no data", detail):
                st["alerted"], st["last_alert"] = True, now
        elif now - st.get("last_alert", 0) >= REMIND:
            if send("RIFT counter: still no data", detail):
                st["last_alert"] = now
    json.dump(st, open(path, "w"))
    return 0


if __name__ == "__main__":
    sys.exit(main())
