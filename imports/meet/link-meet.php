<?php

include(__DIR__ . '/../../init.inc.php');

$fp = fopen(__DIR__ . '/meet.jsonl', 'r');
$meet_map = new StdClass;
$ids = [];
while ($line = fgets($fp)) {
    $meet = json_decode($line);
    $meet = LYLib::filterMeetData($meet);
    try {
        $meet_obj = LYLib::meetNameToId($meet->meetingName);
        if (!$meet_obj) {
            continue;;
        }
    } catch (Exception $e) {
        continue;
    }
    if (!property_exists($meet_map, $meet_obj->id)) {
        $meet_map->{$meet_obj->id} = [];
        $ids[$meet_obj->id] = $meet_obj;
    }
    $meet_map->{$meet_obj->id}[] = $meet;
}

$cmd = [
    'size' => 10000,
    'query' => [
        'terms' => [
            '_id' => array_keys($ids),
        ],
    ],
];
$obj = Elastic::dbQuery("/{prefix}meet/_search", 'GET', json_encode($cmd));
foreach ($obj->hits->hits as $hit) {
    $source = $hit->_source;
    $source->meet_data = $meet_map->{$hit->_id};
    unset($meet_map->{$hit->_id});
    Elastic::dbBulkInsert('meet', $hit->_id, $source);
}

foreach ($meet_map as $id => $meets) {
    $meet_obj = $ids[$id];
    $meet = new StdClass;
    $meet->meet_id = $id;
    $meet->term = $meet_obj->term;
    $meet->meet_type = $meet_obj->meet_type;
    $meet->committees = $meet_obj->committees;
    $meet->sessionPeriod = $meet_obj->sessionPeriod;
    $meet->sessionTimes = $meet_obj->sessionTimes;
    $meet->title = $meet_obj->title;
    $meet->meet_data = $meets;
    Elastic::dbBulkInsert('meet', $id, $meet);
}

Elastic::dbBulkCommit();
echo implode(',', array_keys($ids)) . "\n";
