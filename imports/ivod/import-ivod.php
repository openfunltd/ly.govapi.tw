<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');
include(__DIR__ . '/../../imports/Importer.php');
$crawled = 0;

if (!getenv('date')) {
    putenv('date=' . (time() - 86400 * 7));
}
foreach ([
    'Full' => 'current-full-id',
    'Clip' => 'current-id',
    ] as $type => $v_file) {
    $overtime_limit = 5; // 有幾個超時的就要替過
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
        if (getenv('date')) {
            if (strtotime($ivod->start_time) < getenv('date')) {
                $overtime_limit --;
                if ($overtime_limit <= 0) {
                    break;
                }
            } else {
                $overtime_limit = 5;
            }
        }

        if (!$ivod = IVodParser::checkMeetFromIVOD($ivod, $error_name)) {
            continue;
        }
        $ivod->features = [];
        $result = [];
        if (file_exists(__DIR__ . "/ivod-transcript/{$v}.json")) {
            $content = file_get_contents(__DIR__ . "/ivod-transcript/{$v}.json");
            if (strpos($content, 'status: error') === false) {
                $obj = json_decode($content);
                if ($obj->pyannote->result ?? false) {
                    $result['pyannote'] = [];
                    foreach ($obj->pyannote->result->result as $r) {
                        $result['pyannote'][] = [
                            'speaker' => $r[2],
                            'start' => $r[0],
                            'end' => $r[1],
                        ];
                    }
                }
                if ($obj->whisperx->result ?? false) {
                    $result['whisperx'] = [];
                    foreach ((json_decode($obj->whisperx->result->json)->segments ?? []) as $r) {
                        $result['whisperx'][] = [
                            'start' => $r->start,
                            'end' => $r->end,
                            'text' => $r->text,
                        ];
                    }
                }
                $ivod->transcript = $result;
                $ivod->features[] = 'ai-transcript';
            }
        }
        $gazette_file = __DIR__ . "/ivod-gazette/{$v}.json";
        if (file_exists($gazette_file)) {
            $ivod->gazette = json_decode(file_get_contents($gazette_file));
            $ivod->features[] = 'gazette';
        }

        $ivod_target_file = __DIR__ . "/ivod-data/{$v}.json";
        if (!file_exists($ivod_target_file)) {
            file_put_contents($ivod_target_file, json_encode($ivod, JSON_UNESCAPED_UNICODE));
            Elastic::dbBulkInsert('ivod', $ivod->id, $ivod);

            Importer::addImportLog([
                'event' => 'ivod-add',
                'group' => 'ivod',
                'message' => sprintf("增加 ivod: %s", $ivod->id),
                'data' => json_encode([
                    'ivod_id' => $ivod->id,
                    '委員名稱' => $ivod->{'委員名稱'},
                    'meet_id' => $ivod->meet->id,
                    '會議名稱' => $ivod->{'會議名稱'},
                    'type' => $type,
                    'features' => $ivod->features,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $commit = false);
            continue;
        }
        $old_ivod_data = json_decode(file_get_contents($ivod_target_file));
        if (json_encode($ivod) == json_encode($old_ivod_data)) {
            continue;
        }

        $changes = [];
        if (!property_exists($ivod, 'gazette') and !property_exists($old_ivod_data, 'gazette')) {
            // do nothing
        } else if (json_encode($ivod->gazette) != json_encode($old_ivod_data->gazette)) {
            $changes[] = 'gazette';
        }

        if (!property_exists($ivod, 'transcript') and !property_exists($old_ivod_data, 'transcript')) {
            // do nothing
        } else if (json_encode($ivod->transcript) != json_encode($old_ivod_data->transcript)) {
            $changes[] = 'transcript';
        }
        if ($ivod->duration > 0) {
            Importer::addImportLog([
                'event' => 'ivod-change',
                'group' => 'ivod',
                'message' => sprintf("變更 ivod: %s (%s)", $ivod->id, implode(',', $changes)),
                'data' => json_encode([
                    'ivod_id' => $ivod->id,
                    '委員名稱' => $ivod->{'委員名稱'},
                    'meet_id' => $ivod->meet->id,
                    '會議名稱' => $ivod->{'會議名稱'},
                    'type' => $type,
                    'features' => $ivod->features,
                    'changes' => $changes,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $commit = false);
        }

        file_put_contents($ivod_target_file, json_encode($ivod, JSON_UNESCAPED_UNICODE));
        Elastic::dbBulkInsert('ivod', $ivod->id, $ivod);
    }
}
Elastic::dbBulkCommit();
Importer::addImportLog(null, $commit = true);
