#!/usr/bin/env python3
"""Static JSON for the CSS preview site (rift-css): invented data in every state the pages can show.

  python3 tools/make_fixtures.py        # writes fixtures/chart-<range>.json and fixtures/services*.json

The preview's fixed 'now' is Sunday 2026-10-18 12:15 Lisbon: the 09:30 meeting is done, the 11:30 one is running, the
14:00 one opens its report (2 hours before), the 17:30 one has not started. Weeks run Monday to Sunday. Names are invented. Re-run only when the JSON shapes of api.php / services.php change.
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


REPORTS = {}      # (day, time) -> saved report figures, only used to add up the week totals


def svc(day, hhmm, status, count, name=None, partial=False, special=False, ppl=None, minutes_=120, total=None, report=None):
    s = at(day, hhmm)
    if report:
        REPORTS[(day, hhmm)] = report
    return {'name': name or f'Reunião das {hhmm}', 'time': hhmm, 'start': iso(s), 'end': iso(s + timedelta(minutes=minutes_)),
            'status': status, 'reportOpen': s - timedelta(minutes=120) <= NOW,      # the report can be filled in from 2 hours before the start
            'count': count, 'total': total, 'partial': partial, 'special': special, 'people': ppl if ppl is not None else {}}


def event(day, start, end, count, peak_at):
    return {'start': iso(at(day, start)), 'end': iso(at(day, end)), 'count': count, 'peakAt': iso(at(day, peak_at))}


def totals(days):
    """The same figures as week_totals() in src/services.php."""
    ms = [(d['date'], s) for d in days for s in d['services']]
    started = [(day, s) for day, s in ms if s['status'] != 'unknown']
    shown = [s['total'] if s['total'] is not None else s['count'] for _, s in started if (s['total'] if s['total'] is not None else s['count']) is not None]
    reps = [REPORTS[(day, s['time'])] for day, s in started if (day, s['time']) in REPORTS]
    cat = lambda k: sum(r[k] for r in reps) if reps else None
    return {'meetings': len(ms), 'started': len(started), 'withData': len(shown), 'reports': len(reps), 'shown': sum(shown) if shown else None,
            'voluntarios': cat('voluntarios'), 'visitantes': cat('visitantes'), 'hillsongKids': (sum(r['kids'] + r['auditorio'] for r in reps) if reps else None),
            'decisoes': cat('decisoes'), 'running': sum(1 for _, s in ms if s['status'] == 'running')}


def week(monday, days, current=False):
    """A week Monday to Sunday starting on the date `monday`."""
    d0 = datetime.strptime(monday, '%Y-%m-%d')
    ymd = lambda d: d.strftime('%Y-%m-%d')
    return {'weekStart': monday, 'weekEnd': ymd(d0 + timedelta(days=6)), 'prev': ymd(d0 - timedelta(days=7)), 'next': ymd(d0 + timedelta(days=7)),
            'isCurrent': current, 'today': '2026-10-18', 'timezone': 'Europe/Lisbon', 'days': days, 'totals': totals(days)}


R1 = {'voluntarios': 38, 'visitantes': 12, 'kids': 61, 'auditorio': 7, 'decisoes': 3}
A = [  # the current week (Monday 12 to Sunday 18 October): a weekday evening, an event, then Sunday: done, running, one opening soon, one later
    {'date': '2026-10-13', 'services': [svc('2026-10-13', '20:30', 'done', 58, ppl={})], 'event': None},
    {'date': '2026-10-17', 'services': [], 'event': event('2026-10-17', '10:14', '14:04', 22, '11:35')},
    {'date': '2026-10-18', 'services': [
        svc('2026-10-18', '09:30', 'done', 138, ppl=people('Ana Ferreira', 'Rui Almeida', 'Sofia Lopes', 'Pr. Miguel Duarte')),
        svc('2026-10-18', '11:30', 'running', 419, total=490, report=R1, ppl=people('Carlos Mendes', 'Rui Almeida', 'Sofia Lopes', 'Pr. Miguel Duarte')),
        svc('2026-10-18', '14:00', 'unknown', None, name='Reunião das 14:00 (batismos)', special=True, ppl=people('Inês Barros')),
        svc('2026-10-18', '17:30', 'unknown', None, ppl=people('Inês Barros', None, 'Tomás Pinto')),
    ], 'event': None},
]
B = [  # a past week (Monday 5 to Sunday 11 October): partial data, no data, a special day, an event, a saved report
    {'date': '2026-10-07', 'services': [svc('2026-10-07', '19:30', 'done', 64, name='Noite de Louvor', special=True, ppl={})], 'event': None},
    {'date': '2026-10-08', 'services': [svc('2026-10-08', '20:30', 'done', None, ppl={})], 'event': None},
    {'date': '2026-10-10', 'services': [], 'event': event('2026-10-10', '10:14', '14:04', 22, '11:35')},
    {'date': '2026-10-11', 'services': [
        svc('2026-10-11', '09:30', 'done', 112, partial=True, ppl=people('Ana Ferreira', 'Rui Almeida', 'Sofia Lopes', 'Pr. Miguel Duarte')),
        svc('2026-10-11', '11:30', 'done', 407, total=484, report={'voluntarios': 35, 'visitantes': 9, 'kids': 55, 'auditorio': 6, 'decisoes': 2},
            ppl=people('Carlos Mendes', 'Rui Almeida', 'Sofia Lopes', 'Pr. Miguel Duarte')),
        svc('2026-10-11', '17:30', 'done', 89, ppl=people('Inês Barros', 'Tomás Pinto', 'Beatriz Cruz', 'Pra. Helena Costa')),
    ], 'event': None},
]
C = []  # an empty week
D = [  # a future week (Monday 19 to Sunday 25 October) with a special day; clocks go back that Sunday
    {'date': '2026-10-21', 'services': [svc('2026-10-21', '19:30', 'unknown', None, name='Especial de Outono', special=True)], 'event': None},
    {'date': '2026-10-25', 'services': [svc('2026-10-25', '09:30', 'unknown', None, ppl=people('Ana Ferreira')),
                                         svc('2026-10-25', '11:30', 'unknown', None), svc('2026-10-25', '17:30', 'unknown', None)], 'event': None},
]
for f in os.listdir(OUT):
    if f.startswith('services'):
        os.remove(os.path.join(OUT, f))        # the weeks used to start on Sunday: no stale files
write('services.json', week('2026-10-12', A, current=True))
write('services-2026-10-05.json', week('2026-10-05', B))
write('services-2026-09-28.json', week('2026-09-28', C))
write('services-2026-10-19.json', week('2026-10-19', D))
print('fixtures written to', os.path.abspath(OUT))
