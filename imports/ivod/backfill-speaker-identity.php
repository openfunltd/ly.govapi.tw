<?php

// 對已有 ivod-pyannote/{id}.json 但尚無 identity 的 speaker，
// 做 ES kNN 查詢補上 identity 欄位。
//
// 使用：
//   php backfill-speaker-identity.php          # 全量（從新到舊）
//   limit=500 php backfill-speaker-identity.php
//   only_ids=17688,17695 php backfill-speaker-identity.php   # 只處理指定 ID（例如新增聲紋後想重查的幾支）

include(__DIR__ . '/../../init.inc.php');

// 同 whisper-transcript.php：這支只打 ES，清掉 config.php 給 ivod.ly.gov.tw
// 下載用的 https_proxy，避免不相關的 proxy 拖慢／搞壞 Elastic 連線。
putenv('https_proxy');

$ivod_dir  = __DIR__;
$limit     = intval(getenv('limit') ?: 0);
$only_ids  = getenv('only_ids') ? array_flip(explode(',', getenv('only_ids'))) : null;
$MIN_SCORE = 0.85;  // ES score 對應 cosine ≥ 0.70
$done = $skipped = $updated = 0;

function identify_speaker_es($embedding_b64, $term = 11) {
    global $MIN_SCORE;
    $vec = array_values(unpack('f*', base64_decode($embedding_b64)));
    // 跳過全零向量
    if (max(array_map('abs', $vec)) < 1e-6) return null;
    try {
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
    return [
        'label'       => $hit->_source->label,
        'person_name' => $hit->_source->person_name,
        'score'       => round($hit->_score * 2 - 1, 3),
    ];
    } catch (Exception $e) {
        error_log("identify_speaker_es error: " . $e->getMessage());
        return null;
    }
}

$files = glob($ivod_dir . '/ivod-pyannote/*.json');
rsort($files); // 最新優先

foreach ($files as $path) {
    if ($limit && $done >= $limit) break;

    $id = basename($path, '.json');
    if (!ctype_digit($id)) continue;  // 排除 research-*.json 等非 ID 檔名（Clip/Full ID 位數不同，不限長度）
    if ($only_ids !== null && !isset($only_ids[$id])) continue;

    $data = json_decode(file_get_contents($path), true);
    if (!$data || empty($data['speakers'])) { $skipped++; continue; }

    $changed = false;
    foreach ($data['speakers'] as &$spk) {
        if (isset($spk['identity'])) continue;       // 已有，跳過
        if (empty($spk['embedding'])) continue;

        $identity = identify_speaker_es($spk['embedding']);
        if ($identity) {
            $spk['identity'] = $identity;
            $changed = true;
        }
    }
    unset($spk);

    if ($changed) {
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
        $updated++;
    }

    $done++;
    if ($done % 200 === 0) {
        error_log("backfill-speaker-identity: {$done} done, {$updated} updated");
    }
}

error_log("backfill-speaker-identity: done={$done}, updated={$updated}, skipped={$skipped}");
