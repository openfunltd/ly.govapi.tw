<?php

include(__DIR__ . '/../../init.inc.php');

$fp = fopen(__DIR__ . '/meet.jsonl', 'r');
$meet_map = new StdClass;
$meets = [];
$next = null;
while ($line = fgets($fp)) {
    $meet_data = json_decode($line);
    $meet_data = LYLib::filterMeetData($meet_data);
    $o = new StdClass;
    $o->meet_data = [$meet_data];
    $meet_data = LYLib::buildMeet($o)->meet_data[0];
    try {
        $meet_obj = LYLib::meetNameToId($meet_data->meetingName);
        if (!$meet_obj) {
            continue;;
        }
    } catch (Exception $e) {
        continue;
    }
    if (getenv('term') and $meet_obj->term != getenv('term')) {
        continue;
    }
    if ($meet_obj->type == '院會') { // 因為院會是 PDF ，這邊無法處理
        continue;
    }
    $txt_target = __DIR__ . "/meet-proceeding-txt/{$meet_obj->id}.txt";
    if ($next and $next != $txt_target) {
        continue;
    }
    if (!file_exists($txt_target)) {
        continue;
    }
    try {
        error_log($txt_target);
        $info = GazetteParser::parseAgendaWholeMeetingNote($txt_target, $meet_obj->id, $meet_obj);
    } catch (Exception $e) {
        throw $e;
    }
    $meets[$meet_obj->id] = [$info, $meet_obj, false];
}

$ret = Elastic::dbQuery('/{prefix}meet/_search', 'GET', json_encode([
    'query' => ['terms' => ['_id' => array_keys($meets)]],
    'size' => 1000,
]));
foreach ($ret->hits->hits as $hit) {
    $meets[$hit->_id][2] = $hit->_source;
}

foreach ($meets as $meet) {
    list($info, $meet_obj, $meet_data) = $meet;
    if ($meet_data) {
        $meet = $meet_data;
    } else {
        $meet = new StdClass;
    }
    $meet->meet_id = $meet_obj->id;
    $meet->term = $meet_obj->term;
    $meet->meet_type = $meet_obj->type;
    $meet->committees = $meet_obj->committees;
    $meet->sessionPeriod = $meet_obj->sessionPeriod;
    $meet->sessionTimes = $meet_obj->sessionTimes;

    $meet->{'議事錄'} = $info;

    $meet = LYLib::buildMeet($meet, 'db');
    Elastic::dbBulkInsert('meet', $meet->meet_id, $meet);
}

Elastic::dbBulkCommit();
