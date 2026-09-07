<?php

// 對已有 ivod-pyannote/{id}.json 但尚無 identity 的 speaker，
// 做 ES kNN 查詢補上 identity 欄位。
//
// 使用：
//   php backfill-speaker-identity.php          # 全量（從新到舊）
//   limit=500 php backfill-speaker-identity.php

include(__DIR__ . '/../../init.inc.php');

$ivod_dir  = __DIR__;
$limit     = intval(getenv('limit') ?: 0);
$MIN_SCORE = 0.925; // ES score 對應 cosine ≥ 0.85
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

$files = glob($ivod_dir . '/ivod-pyannote/1?????.json');
rsort($files); // 最新優先

foreach ($files as $path) {
    if ($limit && $done >= $limit) break;

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
