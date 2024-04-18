<?php

include(__DIR__ . '/../../init.inc.php');

// 委員會發言索引
$url = 'https://data.ly.gov.tw/odw/usageFile.action?id=223&type=CSV&fname=223_CSV.csv';
system(sprintf("wget -4 -O %s %s", escapeshellarg(__DIR__ . '/223.csv'), escapeshellarg($url)));

// 院會發言名單
$url = 'https://data.ly.gov.tw/odw/usageFile.action?id=221&type=CSV&fname=221_CSV.csv';
system(sprintf("wget -4 -O %s %s", escapeshellarg(__DIR__ . '/221.csv'), escapeshellarg($url)));

// [smeetingDate] => 104/05/05
// [meetingRoom] => 議場
// [meetingTypeName] => 院會
// [meetingName] => 第8屆第7會期第10次會議
// [meetingContent] => 一、1日上午9時至10時進行國是論壇。二、討論事項：詳見議事日程。三、5日下午5時至6時處理臨時提案。
// [speechKindName] => 臨時提案
// [legislatorNameList] => 李貴敏
// [speechType] => 口頭質詢
$meet_speeches = [];
foreach ([221, 223] as $id) {
    error_log("loading {$id}");
    $fp = fopen(__DIR__ . "/{$id}.csv", 'r');
    $cols = fgetcsv($fp);
    $cols[0] = 'smeetingDate';
    while ($rows = fgetcsv($fp)) {
        if (!$rows[0]) {
            continue;
        }
        $values = array_combine($cols, $rows);
        unset($values['']);
        list($y,$m,$d) = array_map('intval', explode('/', $values['smeetingDate']));
        $values['smeetingDate'] = sprintf('%d-%02d-%02d', $y + 1911, $m, $d);

        if ($id == 221) {
            $key = $values['smeetingDate'] . ':' . $values['meetingName'] . ':' . $values['speechKindName'] . ':' . $values['speechType'];
        } else {
            $key = $values['smeetingDate'] . ':' . $values['meetingName'] . ':' . crc32($values['meetingContent']);
        }

        $legislatorNameList = $values['legislatorNameList'];
        unset($values['legislatorNameList']);

        if (!isset($meet_speeches[$key])) {
            $meet_speeches[$key] = [
                $values,
                $legislatorNameList,
                $id,
            ];
        } else {
            if (json_encode($meet_speeches[$key][0]) != json_encode($values)) {
                print_r($meet_speeches[$key][0]);
                print_r($values);
                readline('continue');
            }
            $meet_speeches[$key][1] .= $legislatorNameList;
        }
    }

    $meets = [];
    foreach ($meet_speeches as $data) {
        list($values, $names, $id) = $data;
        try {
            $meet_obj = LYLib::meetNameToId($values['meetingName']);
        } catch (Exception $e) {
            error_log("{$values['meetingName']} not found: " . $e->getMessage());
            continue;
        }
        if (is_null($meet_obj)) {
            continue;
        }
        if ($id == 232) {
            if (!$meet_obj->committees) {
                continue;
            }
        }
        if (!$meet_obj->id) {
            var_dump($meet_obj);
            var_dump($values);
            exit;
        }
        if (!isset($meets[$meet_obj->id])) {
            $meets[$meet_obj->id] = [
                $meet_obj,
                [],
            ];
        }
        $names = str_replace(';', '', $names);
        $names = GazetteParser::parsePeople($names, $meet_obj->term, '提案');
        $values['legislatorNameList'] = $names;
        $meets[$meet_obj->id][1][] = $values;
    }

    $ret = Elastic::dbQuery('/{prefix}meet/_search', 'GET', json_encode([
        'query' => ['terms' => ['_id' => array_keys($meets)]],
        'size' => 10000,
    ]));
    foreach ($ret->hits->hits as $hit) {
        $meets[$hit->_id][2] = $hit->_source;
    }

    foreach ($meets as $meet) {
        list($meet_obj, $info, $meet_data) = $meet;
        if ($meet_data) {
            $meet = $meet_data;
        } else {
            $meet = new StdClass;
        }
        $meet->meet_id = $meet_obj->id;
        $meet->term = $meet_obj->term;
        $meet->meet_type = $meet_obj->type;
        if (property_exists($meet_obj, 'committees')) {
            $meet->committees = $meet_obj->committees;
        }
        $meet->sessionPeriod = $meet_obj->sessionPeriod;
        $meet->sessionTimes = $meet_obj->sessionTimes;

        $meet->{'發言紀錄'} = $info;
        $meet = LYLib::buildMeet($meet, 'db');

        Elastic::dbBulkInsert('meet', $meet->meet_id, $meet);
    }

    Elastic::dbBulkCommit();
}

unlink(__DIR__ . '/221.csv');
unlink(__DIR__ . '/223.csv');
