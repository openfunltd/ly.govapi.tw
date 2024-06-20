<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');
$crawled = 0;

// find latest gazette date
$gazette_list = glob(__DIR__ . '/../gazette/????.csv');
rsort($gazette_list);
$fp = fopen($gazette_list[0], 'r');
$cols = fgetcsv($fp);
$max_meeting_date = null;
while ($rows = fgetcsv($fp)) {
    $values = array_combine($cols, $rows);
    $max_meeting_date = max($max_meeting_date, intval($values['meetingDate']));
}
$max_meeting_date += 19110000;
$max_meeting_date = strtotime($max_meeting_date);

if ($_SERVER['argv'][1] ?? false) {
    $v = intval($_SERVER['argv'][1]);
} else {
    $v = max(intval(file_get_contents(__DIR__ . '/current-id')), 146312);
}
$error_name = [];
$c = 0;
for (; $v > 0; $v --) {
    //error_log($v);
    $url = sprintf("https://ivod.ly.gov.tw/Play/Clip/1M/%d", $v);
    $html_target = __DIR__ . "/html/{$v}.html";
    if (!file_exists($html_target)) {
        continue;
    }
    $gazette_target = __DIR__ . '/ivod-gazette/' . $v . '.json';
    if (file_exists($gazette_target)) {
        continue;
    }
    $ivod = IVodParser::parseHTML($v, file_get_contents($html_target));
    if (strtotime($ivod->start_time) > $max_meeting_date + 86400) {
        continue;
    }

    if (!$ivod = IVodParser::checkMeetFromIVOD($ivod, $error_name)) {
        error_log("{$v} can not find meet info");
        continue;
    }
    error_log("checking {$v}");
    $gazette = LYLib::getIVODGazette($ivod);
    if ($gazette->error ?? false) {
        error_log("failed {$v}: " . $gazette->message);
        if ($gazette->message == '無公報發言紀錄') {
            continue;
        }
        if (strtotime($ivod->end_time) - strtotime($ivod->start_time) < 10) {
            // Ex: https://ivod.ly.gov.tw/Play/Clip/1M/153252
            continue;
        }
        error_log(json_encode($ivod, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        exit;
    }
    file_put_contents($gazette_target, json_encode($gazette, JSON_UNESCAPED_UNICODE));
}

