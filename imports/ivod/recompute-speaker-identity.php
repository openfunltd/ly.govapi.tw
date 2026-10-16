<?php

// 快速重算身分比對：直接用已經存進 ES ivod index 的 transcript.speakers
// （label+embedding，見 reindex-speaker-identity.php）對 speaker_identity
// 做 kNN，更新 transcript.pyannote 的 speaker_name/speaker_score/is_chair
// 並寫回 ES（+ 本地 ivod-data，如果還在本機的話）。
//
// 跟 backfill-speaker-identity.php + reindex-speaker-identity.php 的差別：
// 這支完全不讀本地 ivod-pyannote/*.json，也不管某個 speaker 是不是「已經
// 有 identity」——一律用目前的 $MIN_SCORE / speaker_identity 內容重新比對、
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
    $MIN_SCORE = 0.85; // 對應 cosine >= 0.70，需跟 whisper-transcript.php 保持同步
    try {
        $vec = array_values(unpack('f*', base64_decode($embedding_b64)));
        if (max(array_map('abs', $vec)) < 1e-6) return null;
        $body = json_encode([
            'knn' => [
                'field' => 'embedding',
                'query_vector' => $vec,
                'k' => 1,
                'num_candidates' => 30,
                'filter' => ['term' => ['term' => $term]],
            ],
            'size' => 1,
            '_source' => ['label', 'person_name'],
        ], JSON_UNESCAPED_UNICODE);
        $result = Elastic::dbQuery('/{prefix}speaker_identity/_search', 'POST', $body);
        $hits = $result->hits->hits ?? [];
        if (empty($hits)) return null;
        $hit = $hits[0];
        if ($hit->_score < $MIN_SCORE) return null;
        return (object)[
            'label'       => $hit->_source->label,
            'person_name' => $hit->_source->person_name,
            'score'       => round($hit->_score * 2 - 1, 3),
        ];
    } catch (Exception $e) {
        error_log("identify_speaker error: " . $e->getMessage());
        return null;
    }
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

        // 用既有 transcript.pyannote 逐段重建每個 speaker label 的時間區段，
        // 跟 alignPyannoteIdentities 需要的格式一致，不需要額外存 segments。
        $segs_by_label = [];
        $transcript_segs = [];
        foreach ($pyannote_segs as $seg) {
            $segs_by_label[$seg->speaker][] = [$seg->start, $seg->end];
            $transcript_segs[] = [$seg->start, $seg->end, $seg->speaker];
        }

        $pyann_speakers = [];
        $has_identity = false;
        foreach ($speakers as $spk) {
            $identity = identify_speaker($spk->embedding);
            if ($identity) $has_identity = true;
            $pyann_speakers[] = (object)[
                'id'       => $spk->speaker,
                'segments' => $segs_by_label[$spk->speaker] ?? [],
                'identity' => $identity,
            ];
        }

        $reading_windows = IVodParser::findReadingWindows($ivod->transcript->whisperx ?? []);

        $identities = [];
        if ($has_identity) {
            $identities = IVodParser::alignPyannoteIdentities($transcript_segs, $pyann_speakers);
        }

        $reading_labels = array_diff_key(
            IVodParser::findReadingSpeakerLabels($transcript_segs, $reading_windows),
            $identities
        );

        if (!$reading_labels && !$identities) { $skipped++; continue; }

        $meet_id = $ivod->meet->id ?? '';
        $date    = $ivod->date ?? '';

        $changed = false;
        foreach ($pyannote_segs as $seg) {
            if (isset($reading_labels[$seg->speaker])) {
                if (($seg->speaker_name ?? null) !== '宣讀人員' || isset($seg->speaker_score) || isset($seg->is_chair)) {
                    $seg->speaker_name = '宣讀人員';
                    unset($seg->speaker_score, $seg->is_chair);
                    $changed = true;
                }
            } elseif ($reading_windows && IVodParser::isReadingTime($reading_windows, $seg->start, $seg->end)) {
                if (($seg->speaker_name ?? null) !== '宣讀人員' || isset($seg->speaker_score) || isset($seg->is_chair)) {
                    $seg->speaker_name = '宣讀人員';
                    unset($seg->speaker_score, $seg->is_chair);
                    $changed = true;
                }
            } elseif (isset($identities[$seg->speaker])) {
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
