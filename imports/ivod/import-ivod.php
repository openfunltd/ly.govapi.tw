<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');
$crawled = 0;

foreach ([
    'Full' => 'current-full-id',
    'Clip' => 'current-id',
    ] as $type => $v_file) {
    $v = intval(file_get_contents(__DIR__ . '/' . $v_file));
    $error_name = [];
    for (; $v > 0; $v --) {
        //error_log($v);
        $url = sprintf("https://ivod.ly.gov.tw/Play/%s/1M/%d", $type, $v);
        $html_target = __DIR__ . "/html/{$v}.html";
        if (!file_exists($html_target)) {
            continue;
            //break;
        }
        $content = file_get_contents($html_target);
        $ivod = IVodParser::parseHTML($v, $content, $type);
        if (getenv('year')) {
            if (strtotime($ivod->start_time) < mktime(0, 0, 0, 1, 1, getenv('year'))) {
                break;
            }
        }

        if (!$ivod = IVodParser::checkMeetFromIVOD($ivod, $error_name)) {
            continue;
        }
        $ivod->features = [];
        if (file_exists(__DIR__ . "/ivod-transcript/{$v}.json") and strpos(file_get_contents(__DIR__ . "/ivod-transcript/{$v}.json"), 'status: error') === false) {
            $ivod->features[] = 'ai-transcript';
        }
        Elastic::dbBulkInsert('ivod', $ivod->id, $ivod);
    }
}
Elastic::dbBulkCommit();
print_r(array_keys($error_name));
