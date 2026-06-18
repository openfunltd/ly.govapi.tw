<?php

include(__DIR__ . '/../../init.inc.php');

function resolve_meet_id_from_name($name, $term) {
    $name = trim($name);
    $name = str_replace('　', '', $name); // strip full-width spaces
    if (!$name) return null;
    // If 屆 is missing, prepend it using the known term
    if (!preg_match('/第\d+屆/u', $name)) {
        $name = "第{$term}屆{$name}";
    }
    try {
        $meet = LYLib::meetNameToId($name);
        return $meet->id ?? null;
    } catch (Exception $e) {
        return null;
    }
}

$prefix = getenv('prefix') ?: '';
$cache_file = __DIR__ . '/vote-processed.json';
$cache = file_exists($cache_file) ? json_decode(file_get_contents($cache_file), true) : [];

// Build lcidc_doc_id -> meet_id reverse map from meet index
error_log("Building lcidc → meet_id map...");
$lcidc_to_meet = [];
$r = Elastic::dbQuery('/{prefix}meet/_search', 'GET', json_encode([
    'size' => 10000,
    '_source' => ['meet_id', '公報發言紀錄'],
    'query' => ['exists' => ['field' => '公報發言紀錄']],
]));
foreach ($r->hits->hits as $hit) {
    $meet_id = $hit->_source->meet_id ?? $hit->_id;
    foreach ($hit->_source->{'公報發言紀錄'} ?? [] as $record) {
        foreach ($record->agenda_lcidc_ids ?? [] as $lcidc_id) {
            $lcidc_to_meet[$lcidc_id] = $meet_id;
        }
    }
}
error_log(sprintf("Built lcidc→meet map with %d entries", count($lcidc_to_meet)));

// Build doc filename id -> agenda info map from gazette-agenda-data/ JSON files.
// One agenda can have multiple docUrls (e.g., agenda 1060405_00001 has both
// LCIDC01_1060405_00002.doc and LCIDC01_1060405_00003.doc), so the doc filename
// number does not always match the agenda_id number.
error_log("Building doc filename → agenda map...");
$agenda_data_dir = __DIR__ . '/gazette-agenda-data';
$doc_to_agenda = [];
foreach (glob($agenda_data_dir . '/*.json') as $f) {
    $a = json_decode(file_get_contents($f));
    foreach ($a->docUrls as $url) {
        if (preg_match('#LCIDC01_(\d+_\d+)\.doc#', $url, $m)) {
            $doc_to_agenda[$m[1]] = $a;
        }
    }
}
error_log(sprintf("Built doc→agenda map with %d entries", count($doc_to_agenda)));

$files = glob(__DIR__ . '/agenda-tikahtml/LCIDC01_' . $prefix . '*.doc.html');
sort($files);
error_log(sprintf("Found %d tikahtml files", count($files)));

$count_new = 0;
$count_votes = 0;
$count_skipped = 0;

foreach ($files as $filepath) {
    $basename = basename($filepath);
    if (!preg_match('#LCIDC01_(\d+_\d+)\.doc\.html$#', $basename, $m)) {
        continue;
    }
    $lcidc_doc_id = $m[1];
    if (!isset($doc_to_agenda[$lcidc_doc_id])) {
        error_log("[{$lcidc_doc_id}] no gazette-agenda-data entry, skipping");
        continue;
    }
    $agenda_data = $doc_to_agenda[$lcidc_doc_id];
    $term = $agenda_data->term;
    $session_period = $agenda_data->sessionPeriod;
    $agenda_id = $agenda_data->agenda_id;

    if (isset($cache[$lcidc_doc_id])) {
        $count_skipped++;
        continue;
    }

    $content = file_get_contents($filepath);
    if (!$content || strlen($content) < 500) {
        $cache[$lcidc_doc_id] = 0;
        $count_new++;
        continue;
    }

    // parse() calls parseVote() internally when it finds 第N屆 in the title;
    // call it explicitly as fallback for docs where the title extraction fails.
    try {
        $ret = GazetteTranscriptParser::parse($content);
        if (!isset($ret->votes)) {
            GazetteTranscriptParser::parseVote($ret, $term);
        }
    } catch (Exception $e) {
        error_log("[{$lcidc_doc_id}] parse error: " . $e->getMessage());
        $cache[$lcidc_doc_id] = -1;
        $count_new++;
        continue;
    }

    $vote_count = count($ret->votes ?? []);
    $cache[$lcidc_doc_id] = $vote_count;
    $count_new++;

    if ($vote_count === 0) {
        continue;
    }

    $file_meet_id = $lcidc_to_meet[$lcidc_doc_id] ?? null;
    foreach ($ret->votes as $vote) {
        $meet_id = $file_meet_id ?? resolve_meet_id_from_name($vote->{'會議名稱'} ?? '', $term);
        $doc = (array) $vote;
        $doc['lcidc_doc_id'] = $lcidc_doc_id;
        $doc['agenda_id'] = $agenda_id;
        $doc['meet_id'] = $meet_id;
        $doc['term'] = $term;
        $doc['session_period'] = $session_period;
        $doc['投票委員'] = array_merge(
            $doc['贊成'] ?? [],
            $doc['反對'] ?? [],
            $doc['棄權'] ?? []
        );
        Elastic::dbBulkInsert('gazette_vote', $lcidc_doc_id . '_' . $vote->line_no, $doc);
        $count_votes++;
    }
    error_log(sprintf("[%s] %d votes (file_meet_id=%s)", $lcidc_doc_id, $vote_count, $file_meet_id ?? 'null'));

    if ($count_new % 100 === 0) {
        Elastic::dbBulkCommit('gazette_vote');
        file_put_contents($cache_file, json_encode($cache, JSON_UNESCAPED_UNICODE));
    }
}

Elastic::dbBulkCommit('gazette_vote');
file_put_contents($cache_file, json_encode($cache, JSON_UNESCAPED_UNICODE));
error_log(sprintf("Done. New: %d, votes indexed: %d, skipped: %d", $count_new, $count_votes, $count_skipped));
