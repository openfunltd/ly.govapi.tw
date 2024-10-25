<?php

include(__DIR__ . '/../../init.inc.php');

$fp = fopen(__DIR__ . '/../../cache/42-meet.jsonl', 'r');
$meet_map = new StdClass;
$meets = [];
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
    if (!file_exists($txt_target)) {
        continue;
    }

    $meet_data_file = __DIR__ . sprintf("/../meet/meet-sub-data/%s.json", $meet_obj->id);
    if (!file_exists($meet_data_file)) {
        $meet_data = new StdClass;
    } else {
        $meet_data = json_decode(file_get_contents($meet_data_file));
    }
    if (property_exists($meet_data, '議事錄') and property_exists($meet_data->{'議事錄'}, 'comYear')) {
        // 已經在 parse-meet-from-gazette.php 抓取的，以那邊優先，因為那邊才有公報位置
        continue;
    }
    try {
        $info = GazetteParser::parseAgendaWholeMeetingNote($txt_target, $meet_obj->id, $meet_obj);
    } catch (Exception $e) {
        throw $e;
    }
    if (!property_exists($meet_data, '議事錄') or json_encode($info) != json_encode($meet_data->{'議事錄'})) {
        error_log("{$txt_target} {$meet_obj->id}");
        $meet_data->{'議事錄'} = $info;
        file_put_contents($meet_data_file, json_encode($meet_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
