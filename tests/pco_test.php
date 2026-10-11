<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Tests for the deterministic parts of the Planning Center code: php tests/pco_test.php
require(__DIR__ . "/../src/pco.php");

$failures = 0;
function check(string $name, $got, $want): void
{
    global $failures;
    if ($got === $want) {
        echo "ok   $name\n";
    } else {
        $failures++;
        echo "FAIL $name\n   got  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n   want " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

$AM = ['09:30', '11:30'];

// every shape of Person note seen in the real plans
check('single name, all meetings', pco_parse_person('Pr. Isaque Lucena', $AM), [['09:30' => 'Pr. Isaque Lucena', '11:30' => 'Pr. Isaque Lucena'], []]);
check('title spelling normalised', pco_parse_person('Pr Isaque Lucena ', $AM)[0]['09:30'], 'Pr. Isaque Lucena');
check('Pra', pco_parse_person('pra deise lucena', ['17:30'])[0]['17:30'], 'Pra. deise lucena');
check('trailing time tags', pco_parse_person("Ingvald (9:30) \nPhill Dpley (11:30)", $AM), [['09:30' => 'Ingvald', '11:30' => 'Phill Dpley'], []]);
check('leading 9h30 tags', pco_parse_person("9h30 João Eduardo e Nalinda \n11h30 Rafael e Cris Ruiz", $AM)[0], ['09:30' => 'João Eduardo e Nalinda', '11:30' => 'Rafael e Cris Ruiz']);
check('leading 9:30 tags', pco_parse_person("9:30 Tiago Pinheiro \n11:30 Daniel Arci", $AM)[0], ['09:30' => 'Tiago Pinheiro', '11:30' => 'Daniel Arci']);
check('tags in reverse order', pco_parse_person("Pra. Deise (11:30)\nPr. Isaque (9:30)", $AM)[0], ['09:30' => 'Pr. Isaque', '11:30' => 'Pra. Deise']);
check('only one meeting tagged, no fallback', pco_parse_person('11h30 Rafael', $AM)[0], ['09:30' => null, '11:30' => 'Rafael']);
check('tagged plus plain line = fallback for the other', pco_parse_person("Ana\n11:30 Rui", $AM)[0], ['09:30' => 'Ana', '11:30' => 'Rui']);
check('tag for a time with no meeting is flagged', pco_parse_person("9:30 Ana\n17:30 Zé", $AM), [['09:30' => 'Ana', '11:30' => null], ['tag 17:30 matches no meeting']]);
check('two people are kept whole', pco_parse_person('Mariana Couto e Gabrielle Silva', ['17:30']), [['17:30' => 'Mariana Couto e Gabrielle Silva'], []]);
check('plus and note in brackets', pco_parse_person('Isamara + João Lucena (15/min cada)', ['17:30']), [['17:30' => 'Isamara + João Lucena (15/min cada)'], []]);
check('accents and apostrophes', pco_parse_person("Mário Coutinho", ['17:30'])[0]['17:30'], 'Mário Coutinho');
check('free text is flagged and shown raw', pco_parse_person("15’ de pregação Daniel Silva\n15’ de pregação João Lucena - João faz o apelo", ['17:30']),
    [['17:30' => '15’ de pregação Daniel Silva / 15’ de pregação João Lucena - João faz o apelo'], ['free text']]);
check('empty / blank note', pco_parse_person("  \n ", ['17:30']), [['17:30' => null], []]);
check('null note', pco_parse_person(null, $AM)[0], ['09:30' => null, '11:30' => null]);
check('time 9h without minutes is not a tag', pco_parse_person('9h Rui', ['09:30'])[1], ['free text']);

// which plan items feed which role
check('item MC', pco_item_roles('> MC:ORAÇÃO + BOAS VINDAS + ANÚNCIOS FALADOS'), ['mc', 'mc']);
check('item welcome (youth)', pco_item_roles('BOAS-VINDAS + ANÚNCIOS + GAME TIME'), ['mc']);
check('item preaching', pco_item_roles('> PREGAÇÃO + APELO'), ['pregador']);
check('item preaching with trailing space', pco_item_roles('PREGAÇÃO + APELO '), ['pregador']);
check('item offering', pco_item_roles('> OFERTAS'), ['oferta']);
check('item offering variant', pco_item_roles('OFERTA CORAÇÃO PELA CASA'), ['oferta']);
check('presenting the preacher is an MC item, not preaching', pco_item_roles('MC: APRESENTAÇÃO DO PREGADOR'), ['mc']);
check('songs and others have no role', pco_item_roles('DEVOCIONAL'), []);
check('prayer (youth) is not the MC', pco_item_roles('ORAÇÃO  '), []);

// the Band note is a fallback for names, never for stage cues
foreach (['Lucas Orgarato' => true, 'Pra. Deise' => true, 'Pr. Isaque' => true, 'Renato e Gabi' => true,
          'BANDA SOBE A FALTAR 5MIN' => false, 'BANDA SOBE A PEDIDO DO PASTOR' => false, 'BANDA SOBE A FALTAR 5 MIN PARA TERMINAR A PREGAÇÃO' => false,
          'Banda sobe a pedido do pastor' => false, 'VOZES SOBEM QUANDO A IGREJA FICAR DE PÉ' => false, 'Luz de sala e palco' => false,
          'JP' => false, '' => false] as $v => $good) {
    check("band value '" . $v . "' ok=" . json_encode($good), pco_band_value_ok($v), $good);
}
check('band note with per-meeting tags', pco_parse_person("Pra. Deise (9:30)\nPr. Isaque (11:30)", $AM), [['09:30' => 'Pra. Deise', '11:30' => 'Pr. Isaque'], []]);

// leader position
check('leader position', (bool)preg_match(PCO_LEADER_POSITION, 'a) Líder Louvor'), true);
check('co-leader is not the leader', (bool)preg_match(PCO_LEADER_POSITION, 'b)Co-Líder Louvor'), false);
check('vocal director is not the leader', (bool)preg_match(PCO_LEADER_POSITION, 'Diretor Vocal'), false);

// the time zone quirk: typed Portuguese times are stored as America/Los_Angeles
$la = new DateTimeZone('America/Los_Angeles');
check('17:30 Sunday (PDT)', pco_wall('2026-10-12T00:25:00Z', $la), '2026-10-11 17:25');
check('20:30 Friday (PDT)', pco_wall('2026-10-17T03:30:00Z', $la), '2026-10-16 20:30');
check('20:30 Friday (PST, after the US change)', pco_wall('2026-11-07T04:30:00Z', $la), '2026-11-06 20:30');
check('morning plan 08:15 (PDT)', pco_wall('2026-10-18T15:15:00Z', $la), '2026-10-18 08:15');
check('evening that crosses UTC midnight stays on its wall-clock day', substr(pco_wall('2026-10-12T00:25:00Z', $la), 0, 10), '2026-10-11');

// excluded types and folders
foreach (['Porto - Manhã (HSK)' => true, 'Aveiro - Tarde (HSK)' => true, 'Kids Camps' => true, 'Porto - Domingo - Manhã' => false, 'Youth' => false, 'Young Adults' => false] as $name => $excluded) {
    check("type '$name' excluded=" . json_encode($excluded), (bool)preg_match(PCO_EXCLUDE_TYPE, $name), $excluded);
}
check("folder 'Hillsong KIDS' excluded", (bool)preg_match(PCO_EXCLUDE_FOLDER, 'Hillsong KIDS'), true);
check("folder 'Hillsong Men' kept", (bool)preg_match(PCO_EXCLUDE_FOLDER, 'Hillsong Men'), false);

echo $failures ? "\n$failures FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
