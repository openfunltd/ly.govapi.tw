<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../imports/Importer.php');

$fp = fopen(__DIR__ . '/../../cache/42-meet.jsonl', 'r');
$meet_map = new StdClass;
while ($line = fgets($fp)) {
    $meet_data = json_decode($line);
    $meet_data = LYLib::filterMeetData($meet_data);
    $meet_obj = LYLib::meetToId('meet_data', $meet_data);
    if (!$meet_obj) {
        continue;
    }
    if (!property_exists($meet_map, $meet_obj->id)) {
        $meet = new StdClass;
        $meet->meet_id = $meet_obj->id;
        $meet->meet_data = [];
        $meet->term = $meet_obj->term;
        $meet->meet_type = $meet_obj->type;
        $meet->committees = $meet_obj->committees ?? null;
        $meet->sessionPeriod = $meet_obj->sessionPeriod;
        $meet->sessionTimes = $meet_obj->sessionTimes ?? null;
        $meet->title = $meet_obj->title;

        $meet_map->{$meet_obj->id} = $meet;
    }
    $meet_map->{$meet_obj->id}->meet_data[] = $meet_data;
}

$diff_function = function($a, $b, $path = '.') use (&$diff_function) {
    $diff = [];
    if (is_scalar($a) and is_scalar($b)) {
        if ($a != $b) {
            $diff[] = json_encode(['diff', $path, $a, $b], JSON_UNESCAPED_UNICODE) . "\n";
        }
        return $diff;
    }
    if (is_scalar($a) or is_scalar($b)) {
        $diff[] = json_encode(['diff', $path, $a, $b], JSON_UNESCAPED_UNICODE) . "\n";
        return $diff;
    }
    if (is_null($a) and is_null($b)) {
        return $diff;
    }
    if (is_null($a)) {
        $diff[] = json_encode(['diff', $path, $a, $b], JSON_UNESCAPED_UNICODE) . "\n";
        return $diff;
    }
    $showed = [];
    foreach ($a as $k => $v) {
        $showed[$k] = true;
        if (!property_exists($b, $k)) {
            if (in_array($k, [
                "\t列席官員",
                '口頭質詢',
                '書面質詢',
            ])) {
                // XXX: 舊資料有這個欄位，但是新資料沒有
                continue;
            }
            $diff[] = json_encode(['miss', $path, $k, $v], JSON_UNESCAPED_UNICODE) . "\n";
            continue;
        }
        if (is_scalar($v) and is_scalar($b->{$k})) {
            if ($k == 'title') {
                $va = $v;
                $vb = $b->{$k};
                $va = str_replace('立法院', '', $va);
                $vb = str_replace('立法院', '', $vb);
                $va = str_replace('會議', '', $va);
                $vb = str_replace('會議', '', $vb);
                // XXX: title 有可能有「立法院」字串，但是不影響內容
                if ($va == $vb) {
                    continue;
                }
            }
            if ($k == '列席官員') {
                continue;
            }
            if ($v != $b->{$k}) {
                $diff[] = json_encode(['diff', $path, $k, $v, $b->{$k}], JSON_UNESCAPED_UNICODE) . "\n";
            }
            continue;
        }
        if (is_scalar($v) or is_scalar($b->{$k})) {
            $diff[] = json_encode(['diff', $path, $k, $v, $b->{$k}], JSON_UNESCAPED_UNICODE) . "\n";
            continue;
        }
        if (is_array($v) and is_array($b->{$k})) {
            if (count($v) != count($b->{$k})) {
                $diff[] = json_encode(['diff-array-count', $path, $k, $v, $b->{$k}], JSON_UNESCAPED_UNICODE) . "\n";
                continue;
            }
            for ($i = 0; $i < count($v); $i++) {
                $diff = array_merge($diff, $diff_function($v[$i], $b->{$k}[$i], $path . '.' . $k . '.' . $i));
            }
            continue;
        }
        $diff = array_merge($diff, $diff_function($v, $b->{$k}, $path . '.' . $k));
    }

    foreach ($b as $k => $v) {
        if (isset($showed[$k])) {
            continue;
        }
        if ($path == '..議事錄' and $k == '列席官員') {
            continue;
        }
        $diff[] = json_encode(['add', $path, $k, $v], JSON_UNESCAPED_UNICODE) . "\n";
    }
    return $diff;
};

foreach ($meet_map as $meet_id => $meet_data) {
    $meet_data_file = __DIR__ . "/meet-data/{$meet_id}.json";

    $meet_data = MeetParser::addPageData($meet_data, __DIR__);

    $meet_sub_data_file = __DIR__ . "/meet-sub-data/{$meet_id}.json";
    if (file_exists($meet_sub_data_file)) {
        $meet_sub_data_file = json_decode(file_get_contents($meet_sub_data_file));
        foreach ($meet_sub_data_file as $k => $v) {
            if (strpos($k, ':') === false) {
                $meet_data->{$k} = $v;
                continue;
            }
            list($k1, $k2) = explode(':', $k);
            if (!property_exists($meet_data, $k1)) {
                $meet_data->{$k1} = [];
            }
            $meet_data->{$k1}[] = $v;
        }
    }
    $meet_data = LYLib::buildMeet($meet_data, 'db');
    if (!file_exists($meet_data_file)) {
        error_log("meet new: {$meet_id}");
        Importer::addImportLog([
            'event' => 'meet-new',
            'group' => 'meet',
            'message' => sprintf("新增會議 %s", $meet_data->title),
            'data' => json_encode([
                'meet_id' => $meet_id,
                'title' => $meet_data->title,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    } else {
        $source = json_decode(file_get_contents($meet_data_file));
        if (json_encode($source) == json_encode($meet_data)) {
            continue;
        }
        $changes = [];
        foreach ($source as $k => $v) {
            if ($k == '發言紀錄') {
                foreach ($v as &$record) {
                    sort($record->legislatorNameList);
                }
                if (is_array($meet_data->{$k})) {
                    foreach ($meet_data->{$k} as &$record) {
                        sort($record->legislatorNameList);
                    }
                }
            }
            if (json_encode($v) != json_encode($meet_data->{$k} ?? null)) {
                //error_log("====={$meet_id} {$k}=====");
                //error_log(json_encode($v, JSON_UNESCAPED_UNICODE));
                //error_log(json_encode($meet_data->{$k}, JSON_UNESCAPED_UNICODE));
                $changes[] = $k;
            }
        }
        if (!$changes) {
            continue;
        }
        error_log("meet change: {$meet_id} " . json_encode($changes, JSON_UNESCAPED_UNICODE));
        Importer::addImportLog([
            'event' => 'meet-change',
            'group' => 'meet',
            'message' => sprintf("會議變更 %s", $meet_data->title),
            'data' => json_encode([
                'meet_id' => $meet_id,
                'title' => $meet_data->title,
                'changes' => $changes,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
    file_put_contents($meet_data_file, json_encode($meet_data, JSON_UNESCAPED_UNICODE));
    Elastic::dbBulkInsert('bill', $meet_id, $meet_data);
    /*
    $diff = $diff_function($source, $meet_data);

    if ($diff) {
        echo 'db:  ' . json_encode($source, JSON_UNESCAPED_UNICODE) . "\n";
        echo 'file:' . json_encode($meet_data, JSON_UNESCAPED_UNICODE) . "\n";
        echo "======\n";
        $diff = arraY_map(function($line) {
            return mb_strimwidth($line, 0, 150, '...', 'UTF-8');
        }, $diff);
        echo implode("\n", $diff) . "\n";
        echo $meet_id . "\n";
        readline('continue?');
    }
     */
}
Elastic::dbBulkCommit();
