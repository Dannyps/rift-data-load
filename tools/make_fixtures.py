#!/usr/bin/env python3
"""Static JSON for the CSS preview site (rift-css): invented data in every state the pages can show.

  python3 tools/make_fixtures.py        # writes fixtures/chart-<range>.json and fixtures/services*.json

The preview's fixed 'now' is Sunday 2026-10-18 12:15 Lisbon: the 09:30 meeting is done, the 11:30 one is running, the
17:30 one has not started. Names are invented. Re-run only when the JSON shapes of api.php / services.php change.
"""
import json
import os
import sys
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fakecrowd import LISBON, simulate

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'fixtures')
NOW = datetime(2026, 10, 18, 12, 15, tzinfo=LISBON).astimezone(timezone.utc)
iso = lambda d: d.astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def write(name, data):
    with open(os.path.join(OUT, name), 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=1)
        f.write('\n')


# ------------------------------------------------------------------ chart (api.php)
RANGES = {'1h': 60, '6h': 300, '24h': 900, '7d': 3600, '30d': 86400}   # bucket seconds
WINDOW = {'1h': 3600, '6h': 6 * 3600, '24h': 86400, '7d': 7 * 86400, '30d': 30 * 86400}
minutes = list(simulate(NOW - timedelta(days=31), NOW))


def epoch_of(t):
    local = t.astimezone(LISBON)
    boundary = local.replace(hour=4, minute=0, second=0, microsecond=0)
    if local < boundary:
        boundary -= timedelta(days=1)
    return boundary.astimezone(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')


for rng_name, bucket in RANGES.items():
    since = NOW - timedelta(seconds=WINDOW[rng_name])
    buckets = {}
    for t, n_in, n_out, inside in minutes:
        if t < since:
            continue
        key = int(t.timestamp()) // bucket * bucket
        b = buckets.setdefault(key, {'in': 0, 'out': 0, 'balance': 0, 'epoch': ''})
        b['in'] += n_in
        b['out'] += n_out
        b['balance'] = inside
        b['epoch'] = epoch_of(t)
    points = [{'t': iso(datetime.fromtimestamp(k, timezone.utc)), **v} for k, v in sorted(buckets.items())]
    write(f'chart-{rng_name}.json', {
        'range': rng_name, 'openingBalance': 150, 'resetHour': 4, 'timezone': 'Europe/Lisbon', 'bucketSeconds': bucket,
        'points': points, 'lastReading': iso(NOW - timedelta(minutes=4)), 'lastReceived': NOW.strftime('%Y-%m-%dT%H:%M:%S+00:00'),
    })

# ------------------------------------------------------------------ meetings page (services.php)
def person(v, src='pco'):
    return {'value': v, 'source': src, 'flag': None}


def people(mc=None, oferta=None, lider=None, pregador=None):
    out = {}
    for k, v in (('mc', mc), ('oferta', oferta), ('lider', lider), ('pregador', pregador)):
        if v:
            out[k] = person(v)
    return out


def at(day, hhmm):
    h, m = map(int, hhmm.split(':'))
    return datetime(*map(int, day.split('-')), h, m, tzinfo=LISBON)


def svc(day, hhmm, status, count, name=None, partial=False, special=False, ppl=None, minutes_=120):
    s = at(day, hhmm)
    return {'name': name or f'Reunião das {hhmm}', 'time': hhmm, 'start': iso(s), 'end': iso(s + timedelta(minutes=minutes_)),
            'status': status, 'count': count, 'partial': partial, 'special': special, 'people': ppl if ppl is not None else {}}


def event(day, start, end, count, peak_at):
    return {'start': iso(at(day, start)), 'end': iso(at(day, end)), 'count': count, 'peakAt': iso(at(day, peak_at))}


def week(sunday, days, current=False):
    d0 = datetime.strptime(sunday, '%Y-%m-%d')
    ymd = lambda d: d.strftime('%Y-%m-%d')
    return {'weekStart': sunday, 'weekEnd': ymd(d0 + timedelta(days=6)), 'prev': ymd(d0 - timedelta(days=7)), 'next': ymd(d0 + timedelta(days=7)),
            'isCurrent': current, 'today': '2026-10-18', 'timezone': 'Europe/Lisbon', 'days': days}


A = [  # the current week: running meeting + not started + a weekday evening
    {'date': '2026-10-18', 'services': [
        svc('2026-10-18', '09:30', 'done', 138, ppl=people('Ana Ferreira', 'Rui Almeida', 'Sofia Lopes', 'Pr. Miguel Duarte')),
        svc('2026-10-18', '11:30', 'running', 419, ppl=people('Carlos Mendes', 'Rui Almeida', 'Sofia Lopes', 'Pr. Miguel Duarte')),
        svc('2026-10-18', '17:30', 'unknown', None, ppl=people('Inês Barros', None, 'Tomás Pinto')),
    ], 'event': None},
    {'date': '2026-10-21', 'services': [svc('2026-10-21', '20:30', 'unknown', None, ppl={})], 'event': None},
]
B = [  # a past week: partial data, no data, a special day, an event
    {'date': '2026-10-11', 'services': [
        svc('2026-10-11', '09:30', 'done', 112, partial=True, ppl=people('Ana Ferreira', 'Rui Almeida', 'Sofia Lopes', 'Pr. Miguel Duarte')),
        svc('2026-10-11', '11:30', 'done', 407, ppl=people('Carlos Mendes', 'Rui Almeida', 'Sofia Lopes', 'Pr. Miguel Duarte')),
        svc('2026-10-11', '17:30', 'done', 89, ppl=people('Inês Barros', 'Tomás Pinto', 'Beatriz Cruz', 'Pra. Helena Costa')),
    ], 'event': None},
    {'date': '2026-10-14', 'services': [svc('2026-10-14', '19:30', 'done', 64, name='Noite de Louvor', special=True, ppl={})], 'event': None},
    {'date': '2026-10-15', 'services': [svc('2026-10-15', '20:30', 'done', None, ppl={})], 'event': None},
    {'date': '2026-10-17', 'services': [], 'event': event('2026-10-17', '10:14', '14:04', 22, '11:35')},
]
C = []  # an empty week
D = [  # a future week with a special day (clocks change that Sunday)
    {'date': '2026-10-25', 'services': [svc('2026-10-25', '09:30', 'unknown', None, ppl=people('Ana Ferreira')),
                                         svc('2026-10-25', '11:30', 'unknown', None), svc('2026-10-25', '17:30', 'unknown', None)], 'event': None},
    {'date': '2026-10-28', 'services': [svc('2026-10-28', '19:30', 'unknown', None, name='Especial de Outono', special=True)], 'event': None},
]
write('services.json', week('2026-10-18', A, current=True))
write('services-2026-10-11.json', week('2026-10-11', B))
write('services-2026-10-04.json', week('2026-10-04', C))
write('services-2026-10-25.json', week('2026-10-25', D))
print('fixtures written to', os.path.abspath(OUT))
