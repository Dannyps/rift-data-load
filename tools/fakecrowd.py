"""Invented crowd model shared by fake_data.py (throwaway database) and make_fixtures.py (static preview JSON)."""
import random
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

LISBON = ZoneInfo('Europe/Lisbon')
MEETINGS = {6: [(9, 30, 140), (11, 30, 420), (17, 30, 95)]}   # python weekday 6 = Sunday


def target_inside(t_utc):
    """How many people we want inside at this minute."""
    t = t_utc.astimezone(LISBON)
    best = 0
    for hh, mm, peak in MEETINGS.get(t.weekday(), []):
        s = t.replace(hour=hh, minute=mm, second=0, microsecond=0)
        m = (t - s).total_seconds() / 60
        if -45 <= m < 0:
            best = max(best, peak * (m + 45) / 45)
        elif 0 <= m <= 95:
            best = max(best, peak)
        elif 95 < m <= 125:
            best = max(best, peak * (125 - m) / 30)
    if t.weekday() == 5 and 10 <= t.hour < 12:      # a small Saturday event
        best = max(best, 28)
    return best


def simulate(start, end, seed=7):
    """Yield (time, people in this minute, people out this minute, inside) every minute from start to end (UTC datetimes)."""
    rng = random.Random(seed)
    inside = 0
    t = start
    while t <= end:
        target = target_inside(t)
        want = max(round(target + rng.uniform(-2, 2)), 0) if target else 0
        diff = want - inside
        n_in = n_out = 0
        if diff > 0:
            n_in = min(diff, rng.randint(1, 25))
        elif diff < 0:
            n_out = min(-diff, rng.randint(1, 30))
        if want == 0 and inside == 0 and rng.random() < 0.02:
            n_in, n_out = 1, 1
        inside += n_in - n_out
        yield t, n_in, n_out, inside
        t += timedelta(minutes=1)
