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
                // 院會議事人員（宣讀人員）唸黨團提案/議案內容的區段：不辨識是哪一位
                // （輪值、公報也不會寫名字），用內容模式偵測，不管 pyannote 認得的
                // 是誰都直接標成「宣讀人員」這個角色（見 IVodParser::findReadingWindows）。
                $reading_windows = [];
                if ($obj->whisperx->result ?? false) {
                    $whisperx_segs = json_decode($obj->whisperx->result->json)->segments ?? [];
                    $reading_windows = IVodParser::findReadingWindows($whisperx_segs);
                }

                if ($obj->pyannote->result ?? false) {
                    // 讀 speaker identity（若有）。ivod-pyannote 的 embedding 可能來自
                    // 獨立重跑的 pyannote（見 backfill-pyannote-run.php），SPEAKER_XX
                    // 編號跟這裡的原始 diarization 不保證相同，需用時間重疊比對
                    // （IVodParser::alignPyannoteIdentities）才能正確對應到人。
                    $identities = [];
                    $pyann_file = __DIR__ . "/ivod-pyannote/{$v}.json";
                    if (file_exists($pyann_file)) {
                        $pyann = json_decode(file_get_contents($pyann_file));
                        $identities = IVodParser::alignPyannoteIdentities(
                            $obj->pyannote->result->result, $pyann->speakers ?? []);
                    }
                    // 同一支影片裡同一個 SPEAKER_XX 編號就是同一個人，只要曾經有一段
                    // 落在宣讀視窗內，這整支影片裡這個編號的段落都算宣讀人員（見
                    // IVodParser::findReadingSpeakerLabels 說明，處理公報列舉多案、
                    // 後面案件沒有「請宣讀」觸發詞的情況）。但如果這個編號已經比對到
                    // 身分（委員／主席），代表這其實是本人聲紋，只是偶爾因為交疊講話
                    // 被誤判落在宣讀視窗內，不能整支都蓋成宣讀人員，仍交給下面逐段判斷。
                    $reading_labels = array_diff_key(
                        IVodParser::findReadingSpeakerLabels($obj->pyannote->result->result, $reading_windows),
                        $identities
                    );
                    // 主席／召委身分跟一般委員身分用同一套 speaker_identity（存的是本人聲紋，
                    // 不分角色）；這裡額外查這場會議公報記載當天出現過的主席名單（可能不只
                    // 一位，見 getSessionChair 說明），若比對到的人在名單內，才在這個
                    // segment 上加 is_chair 標記（角色是這裡判斷出來的，不是存在 embedding 裡）。
                    $meet_id = $ivod->meet->id ?? '';
                    $date    = $ivod->date ?? '';

                    $result['pyannote'] = [];
                    foreach ($obj->pyannote->result->result as $r) {
                        $entry = [
                            'speaker' => $r[2],
                            'start' => $r[0],
                            'end' => $r[1],
                        ];
                        if (isset($reading_labels[$r[2]])) {
                            $entry['speaker_name'] = '宣讀人員';
                        } elseif ($reading_windows && IVodParser::isReadingTime($reading_windows, $r[0], $r[1])) {
                            $entry['speaker_name'] = '宣讀人員';
                        } elseif (isset($identities[$r[2]])) {
                            $entry['speaker_name']  = $identities[$r[2]]->label;
                            $entry['speaker_score'] = $identities[$r[2]]->score;
                            if (IVodParser::isSessionChair($meet_id, $date, $identities[$r[2]]->person_name)) {
                                $entry['is_chair'] = true;
                            }
                        }
                        $result['pyannote'][] = $entry;
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
                $gazette_align_file = __DIR__ . "/ivod-gazette-align/{$v}.json";
                if (file_exists($gazette_align_file)) {
                    $result['gazette'] = json_decode(file_get_contents($gazette_align_file));
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
