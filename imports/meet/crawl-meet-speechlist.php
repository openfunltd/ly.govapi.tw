<?php

include(__DIR__ . '/../../init.inc.php');

// 委員會發言索引
$url = 'https://data.ly.gov.tw/odw/usageFile.action?id=223&type=CSV&fname=223_CSV.csv';
system(sprintf("curl -4 -o %s %s", escapeshellarg(__DIR__ . '/../../cache/223-meet-speech.csv'), escapeshellarg($url)));

// 院會發言名單
$url = 'https://data.ly.gov.tw/odw/usageFile.action?id=221&type=CSV&fname=221_CSV.csv';
system(sprintf("curl -4 -o %s %s", escapeshellarg(__DIR__ . '/../../cache/221-meet-speech.csv'), escapeshellarg($url)));

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
    $fp = fopen(__DIR__ . "/../../cache/{$id}-meet-speech.csv", 'r');
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

        if ($id == 221) {
            $key = '發言紀錄:' . $values['smeetingDate'] . ':' . $values['meetingName'] . ':' . $values['speechKindName'] . ':' . $values['speechType'];
        } else {
            $key = '發言紀錄:' . $values['smeetingDate'] . ':' . $values['meetingName'] . ':' . crc32($values['meetingContent']);
        }
        if (!array_key_exists($key, $meet_speeches)) {
            $meet_speeches[$key] = [
                'values' => $values,
                'list' => [],
                'meet_obj' => $meet_obj,
            ];
        }
        $names = $values['legislatorNameList'];
        $names = str_replace(';', '', $names);
        $names = GazetteParser::parsePeople($names, $meet_obj->term, '提案');
        foreach ($names as $n) {
            $meet_speeches[$key]['list'][] = $n;
        }
    }
    fclose($fp);

    foreach ($meet_speeches as $key => $values_list) {
        $values = $values_list['values'];
        $meet_obj = $values_list['meet_obj'];

        $values['legislatorNameList'] = $values_list['list'];

        $meet_data_file = __DIR__ . sprintf("/../meet/meet-sub-data/%s.json", $meet_obj->id);
        if (!file_exists($meet_data_file)) {
            $meet_data = new StdClass;
        } else {
            $meet_data = json_decode(file_get_contents($meet_data_file));
        }

        if (property_exists($meet_data, $key) and json_encode($meet_data->{$key}) == json_encode($values)) {
            continue;
        }
        error_log("{$meet_obj->id} {$key}");
        error_log(json_encode($values, JSON_UNESCAPED_UNICODE));
        error_log(json_encode($meet_data->{$key}, JSON_UNESCAPED_UNICODE));
        $meet_data->{$key} = $values;
        file_put_contents($meet_data_file, json_encode($meet_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

