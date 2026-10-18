#!/usr/bin/env python3
"""SQL with invented counter data, for a THROWAWAY database only (admin sample capture and CSS regression shots).

  python3 tools/fake_data.py | docker exec -i rift-capture-db psql -U rift -q

Minute readings of two cumulative counters (register 0 = in, 1 = out) for the last 10 days, with crowds around the
Sunday meetings and a small event on Saturday (see fakecrowd.py). Deterministic.
"""
import os
import sys
from datetime import datetime, timedelta, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fakecrowd import simulate

now = datetime.now(timezone.utc).replace(second=0, microsecond=0)
cin = cout = 5000
log_id = 3_000_000
print('BEGIN;')
print('COPY records (registerid, value, logentryid, "timestamp", deviceid, received_at) FROM stdin;')
for t, n_in, n_out, _ in simulate(now - timedelta(days=10), now):
    cin += n_in
    cout += n_out
    log_id += 1
    ts = t.strftime('%Y-%m-%d %H:%M:%S')
    print(f'0\t{cin}\t{log_id}\t{ts}\tD001\t{ts}+00')
    print(f'1\t{cout}\t{log_id}\t{ts}\tD001\t{ts}+00')
print('\\.')
print('COMMIT;')
