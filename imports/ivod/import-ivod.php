<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');
include(__DIR__ . '/../../imports/Importer.php');
$crawled = 0;

if (!getenv('date')) {
    putenv('date=' . (time() - 86400 * 7));
}
// only_ids=17566,17587 php import-ivod.php：只重新匯入指定 id（不受 date 限制），
// 用於 reprocess-batch.php 重跑舊影片後，把新的 ivod-transcript/ivod-pyannote 推進 ivod-data 與 ES。
$only_ids = getenv('only_ids') ? array_map('intval', explode(',', getenv('only_ids'))) : null;
$max_full_id = intval(file_get_contents(__DIR__ . '/current-full-id'));
foreach ([
    'Full' => 'current-full-id',
    'Clip' => 'current-id',
    ] as $type => $v_file) {
    $overtime_limit = 5; // 有幾個超時的就要替過
    $v = intval(file_get_contents(__DIR__ . '/' . $v_file));
    $error_name = [];
    $type_ids = null;
    if ($only_ids !== null) {
        // Full 的 id 範圍 <= current-full-id，Clip 的 > current-full-id
        $type_ids = array_values(array_filter($only_ids, fn($id) => $type === 'Full' ? $id <= $max_full_id : $id > $max_full_id));
        if (!$type_ids) continue;
        $v = max($type_ids);
    }
    for (; $v > 0; $v --) {
        if ($type_ids !== null && !in_array($v, $type_ids)) continue;
        //error_log($v);
        $url = sprintf("https://ivod.ly.gov.tw/Play/%s/1M/%d", $type, $v);
        $html_target = __DIR__ . "/html/{$v}.html";
        if (!file_exists($html_target)) {
            continue;
            //break;
        }
        $content = file_get_contents($html_target);
        $ivod = IVodParser::parseHTML($v, $content, $type);
        if (getenv('date') && $type_ids === null) {
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
                    $pyann = null;
                    $pyann_file = __DIR__ . "/ivod-pyannote/{$v}.json";
                    if (file_exists($pyann_file)) {
                        $pyann = json_decode(file_get_contents($pyann_file));
                        $identities = IVodParser::alignPyannoteIdentities(
                            $obj->pyannote->result->result, $pyann->speakers ?? []);
                    }
                    // Full 影片把每個 speaker 的 embedding 一併存進 transcript.speakers（speaker 為對齊後的
                    // 逐字稿 label、embed_id 為 ivod-pyannote 原編號），之後調門檻、新增聲紋或公報到了要補
                    // is_chair 時，recompute-speaker-identity.php 不用重跑 ASR 就能重算。Clip 以委員質詢為主、
                    // 沒有宣讀等程序性發言，依 2026-10-16 決定不存。
                    if ($type === 'Full' && $pyann && !empty($pyann->speakers)) {
                        $label_by_eid = array_flip(IVodParser::alignPyannoteSpeakers(
                            $obj->pyannote->result->result, $pyann->speakers));
                        $result['speakers'] = [];
                        foreach ($pyann->speakers as $spk) {
                            if (empty($spk->embedding) || !isset($label_by_eid[$spk->id])) continue;
                            $result['speakers'][] = [
                                'speaker'       => $label_by_eid[$spk->id],
                                'embed_id'      => $spk->id,
                                'embedding'     => $spk->embedding,
                                'total_seconds' => $spk->total_seconds ?? null,
                            ];
                        }
                    }
                    // 身分一律以 kNN（IVodParser::identifySpeaker）為準：委員、官員、
                    // 宣讀人員（speaker_identity 裡 role=reader 的「宣讀人員N」）都走同一條路。
                    // 「請宣讀→完畢」內容規則只在 kNN 完全沒比對到時當 fallback——同一支
                    // 影片同一個 SPEAKER_XX 就是同一個人，只要有一段落在宣讀視窗內，這個
                    // 編號的所有段落都算宣讀人員（見 IVodParser::findReadingSpeakerLabels）。
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
                        if (isset($identities[$r[2]])) {
                            $entry['speaker_name']  = $identities[$r[2]]->label;
                            $entry['speaker_score'] = $identities[$r[2]]->score;
                            if (IVodParser::isSessionChair($meet_id, $date, $identities[$r[2]]->person_name)) {
                                $entry['is_chair'] = true;
                            }
                        } elseif (isset($reading_labels[$r[2]])) {
                            // kNN 沒比對到任何身分時的 fallback：內容規則判斷為宣讀人員
                            $entry['speaker_name'] = '宣讀人員';
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
