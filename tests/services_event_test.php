<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Tests for event_stretch() in src/services.php: php tests/services_event_test.php
require(__DIR__ . "/../src/services.php");   // loading it does not touch the database

$failures = 0;
function check(string $name, $got, $want): void
{
    global $failures;
    if ($got === $want) {
        echo "ok   $name\n";
    } else {
        $failures++;
        echo "FAIL $name\n   got  " . json_encode($got) . "\n   want " . json_encode($want) . "\n";
    }
}

/** one reading per minute for $day (UTC == the local day here); balance = f(HH:MM) */
function readings(string $day, callable $balance, string $from = '00:00', string $to = '23:59'): array
{
    $out = [];
    $z = new DateTimeZone('UTC');
    for ($t = new DateTimeImmutable("$day $from", $z); $t <= new DateTimeImmutable("$day $to", $z); $t = $t->modify('+1 minute')) {
        $out[] = ['timestamp' => $t->format('Y-m-d H:i:s'), 'day' => $day, 'balance' => $balance($t->format('H:i'))];
    }
    return $out;
}
/** balance from a list of [HH:MM, balance] steps: the last step at or before the time applies */
function steps(array $steps): callable
{
    return function (string $hm) use ($steps): int {
        $v = 0;
        foreach ($steps as [$at, $b]) {
            if ($hm >= $at) {
                $v = $b;
            }
        }
        return $v;
    };
}
$D = '2026-10-13';
$meeting = [["$D 19:30:00", "$D 21:00:00"]];

// 13 Oct 2026: people trickle in before the course, nobody leaves: the stretch runs into the meeting
$e = readings($D, steps([['19:05', 3], ['19:08', 5], ['19:14', 7], ['19:15', 8], ['19:21', 11], ['19:30', 23], ['19:56', 36], ['21:30', 0]]));
check('early arrivals that stay are the meeting, not an event', event_stretch($e, $D, $meeting), null);
$s = event_stretch($e, $D, []);
check('same day without a meeting is one event until the real end', $s, ["$D 19:14:00", "$D 21:29:00", 36, "$D 19:56:00"]);

// a separate gathering at 16:00, the building empties, then the evening meeting
$e = readings($D, steps([['16:00', 12], ['17:00', 0], ['19:30', 30], ['21:30', 0]]));
check('earlier separate gathering is an event, the meeting is not', event_stretch($e, $D, $meeting), ["$D 16:00:00", "$D 16:59:00", 12, "$D 16:00:00"]);

// early arrivals who go down to 5 for a long time before the window opens are a stretch of their own
$e = readings($D, steps([['19:00', 9], ['19:10', 5], ['19:30', 25], ['21:30', 0]]));
check('stretch that ends over 15 min before the window is an event', event_stretch($e, $D, $meeting), ["$D 19:00:00", "$D 19:09:00", 9, "$D 19:00:00"]);
$e = readings($D, steps([['19:10', 9], ['19:20', 5], ['19:30', 25], ['21:30', 0]]));
check('stretch that ends 11 min before the window is the meeting crowd', event_stretch($e, $D, $meeting), null);

// stretch touching the window edges counts as the meeting
$e = readings($D, steps([['19:00', 8], ['19:31', 0]]));
check('stretch reaching the window start belongs to the meeting', event_stretch($e, $D, $meeting), null);
$e = readings($D, steps([['21:00', 8], ['21:10', 0]]));
check('stretch starting exactly at the window end belongs to the meeting', event_stretch($e, $D, $meeting), null);
$e = readings($D, steps([['21:01', 8], ['21:10', 0]]));
check('stretch starting after the window is an event', event_stretch($e, $D, $meeting), ["$D 21:01:00", "$D 21:09:00", 8, "$D 21:01:00"]);

// 14 Oct 2026: 6 inside at 19:09, 5 at 19:10, then 9 and rising: one crowd, not a separate event
$e = readings($D, steps([['19:08', 5], ['19:09', 6], ['19:10', 5], ['19:11', 9], ['19:30', 26], ['21:30', 0]]));
check('a one-minute dip to the threshold does not split the crowd', event_stretch($e, $D, $meeting), null);

// merge gap: 15 minutes apart is one stretch, 16 is two
$e = readings($D, steps([['10:00', 8], ['10:30', 0], ['10:45', 12], ['11:15', 0]]));   // last 10:29, next 10:45: 16 min
check('stretches 16 min apart stay separate (largest peak wins)', event_stretch($e, $D, []), ["$D 10:45:00", "$D 11:14:00", 12, "$D 10:45:00"]);
$e = readings($D, steps([['10:00', 8], ['10:30', 0], ['10:44', 12], ['11:15', 0]]));   // last 10:29, next 10:44: 15 min
check('stretches 15 min apart merge', event_stretch($e, $D, []), ["$D 10:00:00", "$D 11:14:00", 12, "$D 10:44:00"]);
// the separate 16:00 gathering stays separate from a meeting hours later
$e = readings($D, steps([['16:00', 12], ['17:00', 0], ['19:30', 30], ['21:30', 0]]));
check('gap of hours is not merged into the meeting', event_stretch($e, $D, $meeting)[2], 12);

// threshold: 5 people is not an event, 6 is
check('5 inside is not an event', event_stretch(readings($D, steps([['10:00', 5], ['11:00', 0]])), $D, []), null);
check('6 inside is an event', event_stretch(readings($D, steps([['10:00', 6], ['11:00', 0]])), $D, [])[2], 6);

// several stretches: the highest peak wins, then the longest
$e = readings($D, steps([['09:00', 8], ['09:30', 0], ['14:00', 15], ['14:10', 0], ['17:00', 8], ['18:00', 0]]));
check('highest peak wins', event_stretch($e, $D, [])[2], 15);
$e = readings($D, steps([['09:00', 8], ['09:30', 0], ['14:00', 8], ['14:10', 0]]));
check('equal peaks: the longest wins', event_stretch($e, $D, [])[0], "$D 09:00:00");

// other days' readings are ignored, and an empty day is null
$e = array_merge(readings('2026-10-12', steps([['10:00', 40]])), readings($D, steps([])));
check('other days are ignored', event_stretch($e, $D, []), null);
check('no readings', event_stretch([], $D, $meeting), null);

// a stretch still running at the last reading
$e = readings($D, steps([['22:00', 9]]), '21:30', '22:30');
$s = event_stretch($e, $D, $meeting);
check('open-ended stretch ends at the last reading', [$s[0], $s[1]], ["$D 22:00:00", "$D 22:30:00"]);

echo $failures ? "\n$failures FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
