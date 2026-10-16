<?php

// 快速重算身分比對：直接用已經存進 ES ivod index 的 transcript.speakers
// （label+embedding，見 reindex-speaker-identity.php）對 speaker_identity
// 做 kNN，更新 transcript.pyannote 的 speaker_name/speaker_score/is_chair
// 並寫回 ES（+ 本地 ivod-data，如果還在本機的話）。
//
// 跟 backfill-speaker-identity.php + reindex-speaker-identity.php 的差別：
// 這支完全不讀本地 ivod-pyannote/*.json，也不管某個 speaker 是不是「已經
// 有 identity」——一律用目前的 IVodParser::identifySpeaker 門檻 / speaker_identity 內容重新比對、
// 覆蓋舊結果。調整門檻或新增聲紋後，只要跑這支就能讓既有影片反映最新規則，
// 不需要重新跑 ASR/pyannote，也不需要掃過本地檔案。
//
// 使用：
//   php recompute-speaker-identity.php
//   limit=500 php recompute-speaker-identity.php
//   only_ids=17742,17566 php recompute-speaker-identity.php
//   dry_run=1 limit=20 php recompute-speaker-identity.php   # 只印出變更，不寫檔不寫 ES

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');

// 同 reindex-speaker-identity.php：這支只打 ES，清掉 config.php 給 ivod.ly.gov.tw
// 下載用的 https_proxy，避免不相關的 proxy 拖慢／搞壞 Elastic 連線。
putenv('https_proxy');

$ivod_dir = __DIR__;
$limit    = intval(getenv('limit') ?: 0);
$dry_run  = (bool)getenv('dry_run');
$only_ids = getenv('only_ids') ? array_map('intval', explode(',', getenv('only_ids'))) : null;
$done = $updated = $skipped = $errors = 0;

function identify_speaker($embedding_b64, $term = 11) {
    // 門檻與比對規則統一在 IVodParser::identifySpeaker（含宣讀人員 role=reader 的處理）
    $r = IVodParser::identifySpeaker($embedding_b64, $term);
    return $r ? (object)$r : null;
}

$query = ['exists' => ['field' => 'transcript.speakers']];
if ($only_ids) {
    $query = ['bool' => ['must' => [
        $query,
        ['terms' => ['id' => $only_ids]],
    ]]];
}

$search_after = null;
while (true) {
    if ($limit && $done >= $limit) break;

    $body = [
        'size'    => 100,
        'query'   => $query,
        'sort'    => [['id' => 'asc']],
        '_source' => ['id', 'meet', 'date', 'transcript'],
    ];
    if ($search_after) $body['search_after'] = $search_after;

    $result = Elastic::dbQuery('/{prefix}ivod/_search', 'POST', json_encode($body, JSON_UNESCAPED_UNICODE));
    $hits = $result->hits->hits ?? [];
    if (empty($hits)) break;

    foreach ($hits as $hit) {
        if ($limit && $done >= $limit) break;
        $done++;
        $search_after = $hit->sort;

        $ivod = $hit->_source;
        $v = $ivod->id;
        $speakers = $ivod->transcript->speakers ?? [];
        $pyannote_segs = $ivod->transcript->pyannote ?? [];
        if (!$speakers || !$pyannote_segs) { $skipped++; continue; }

        $transcript_segs = [];
        foreach ($pyannote_segs as $seg) {
            $transcript_segs[] = [$seg->start, $seg->end, $seg->speaker];
        }

        // transcript.speakers[].speaker 已是 reindex-speaker-identity.php 對齊過的 transcript label
        // （跟 transcript.pyannote[].speaker 同一套編號），直接 kNN 即可，不需再做時間重疊對齊。
        $identities = [];
        foreach ($speakers as $spk) {
            $identity = identify_speaker($spk->embedding);
            if ($identity) $identities[$spk->speaker] = $identity;
        }

        $reading_windows = IVodParser::findReadingWindows($ivod->transcript->whisperx ?? []);

        // 「請宣讀→完畢」內容規則只在 kNN 完全沒比對到身分的 speaker 上當 fallback
        $reading_labels = array_diff_key(
            IVodParser::findReadingSpeakerLabels($transcript_segs, $reading_windows),
            $identities
        );

        if (!$reading_labels && !$identities) { $skipped++; continue; }

        $meet_id = $ivod->meet->id ?? '';
        $date    = $ivod->date ?? '';

        $changed = false;
        foreach ($pyannote_segs as $seg) {
            if (isset($identities[$seg->speaker])) {
                $new_name    = $identities[$seg->speaker]->label;
                $new_score   = $identities[$seg->speaker]->score;
                $new_is_chair = IVodParser::isSessionChair($meet_id, $date, $identities[$seg->speaker]->person_name) ? true : null;
                if (($seg->speaker_name ?? null) !== $new_name
                    || ($seg->speaker_score ?? null) !== $new_score
                    || ($seg->is_chair ?? null) !== $new_is_chair) {
                    $seg->speaker_name  = $new_name;
                    $seg->speaker_score = $new_score;
                    if ($new_is_chair) {
                        $seg->is_chair = true;
                    } else {
                        unset($seg->is_chair);
                    }
                    $changed = true;
                }
            } elseif (isset($reading_labels[$seg->speaker])) {
                // kNN 沒比對到任何身分時的 fallback：內容規則判斷為宣讀人員
                if (($seg->speaker_name ?? null) !== '宣讀人員' || isset($seg->speaker_score) || isset($seg->is_chair)) {
                    $seg->speaker_name = '宣讀人員';
                    unset($seg->speaker_score, $seg->is_chair);
                    $changed = true;
                }
            } elseif (isset($seg->speaker_name)) {
                // 這次重算沒有比對到身分（例如門檻調高後原本的比對被推翻），清掉舊標記
                unset($seg->speaker_name, $seg->speaker_score, $seg->is_chair);
                $changed = true;
            }
        }

        if (!$changed) { $skipped++; continue; }

        if ($dry_run) {
            $names = [];
            foreach ($pyannote_segs as $seg) {
                if (isset($seg->speaker_name)) $names[$seg->speaker] = $seg->speaker_name;
            }
            echo "[dry] {$v}: " . json_encode($names, JSON_UNESCAPED_UNICODE) . "\n";
            $updated++;
            continue;
        }

        Elastic::dbBulkInsert('ivod', $v, ['transcript' => ['pyannote' => $pyannote_segs]]);

        // 同步寫回本地 ivod-data（如果還在本機）維持一致，找不到就算了
        $data_path = $ivod_dir . "/ivod-data/{$v}.json";
        if (file_exists($data_path)) {
            $local = json_decode(file_get_contents($data_path));
            if ($local) {
                $local->transcript->pyannote = $pyannote_segs;
                file_put_contents($data_path, json_encode($local, JSON_UNESCAPED_UNICODE));
            }
        }

        $updated++;
    }

    if (!$dry_run) Elastic::dbBulkCommit();
    if ($done % 500 === 0) {
        error_log("recompute-speaker-identity: {$done} done, {$updated} updated");
    }
}

if (!$dry_run) Elastic::dbBulkCommit();
error_log("recompute-speaker-identity: done={$done}, updated={$updated}, skipped={$skipped}, errors={$errors}");
echo "done={$done}, updated={$updated}, skipped={$skipped}, errors={$errors}\n";
