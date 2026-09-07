<?php

// 對已有 speaker identity 的 clip，更新 ivod-data 中的 transcript.pyannote，
// 加上 speaker_name / speaker_score，並重新 index 進 ES。
//
// 使用：
//   php reindex-speaker-identity.php
//   limit=500 php reindex-speaker-identity.php

include(__DIR__ . '/../../init.inc.php');

$ivod_dir = __DIR__;
$limit    = intval(getenv('limit') ?: 0);
$done = $updated = $skipped = $errors = 0;

$files = glob($ivod_dir . '/ivod-pyannote/1?????.json');
rsort($files);

foreach ($files as $pyann_path) {
    if ($limit && $done >= $limit) break;

    $v = intval(basename($pyann_path, '.json'));
    $data_path = $ivod_dir . "/ivod-data/{$v}.json";

    if (!file_exists($data_path)) { $skipped++; continue; }

    $pyann = json_decode(file_get_contents($pyann_path), true);
    if (!$pyann) { $errors++; continue; }

    // 建 speaker_id → identity 對照表
    $identities = [];
    foreach ($pyann['speakers'] ?? [] as $spk) {
        if (!empty($spk['identity'])) {
            $identities[$spk['id']] = $spk['identity'];
        }
    }
    if (empty($identities)) { $skipped++; continue; }

    $ivod = json_decode(file_get_contents($data_path));
    if (!$ivod) { $errors++; continue; }

    $pyannote_segs = $ivod->transcript->pyannote ?? null;
    if (!$pyannote_segs) { $skipped++; continue; }

    // 檢查是否已有 speaker_name（避免重複 index）
    $already_done = false;
    foreach ($pyannote_segs as $seg) {
        if (isset($seg->speaker_name)) { $already_done = true; break; }
    }
    if ($already_done) { $skipped++; continue; }

    // 加上 speaker_name / speaker_score
    $changed = false;
    foreach ($pyannote_segs as $seg) {
        $spk_id = $seg->speaker ?? '';
        if (isset($identities[$spk_id])) {
            $seg->speaker_name  = $identities[$spk_id]['label'];
            $seg->speaker_score = $identities[$spk_id]['score'];
            $changed = true;
        }
    }

    if (!$changed) { $skipped++; continue; }

    // 存回 ivod-data
    file_put_contents($data_path, json_encode($ivod, JSON_UNESCAPED_UNICODE));

    // 重新 index 進 ES
    Elastic::dbBulkInsert('ivod', $ivod->id, $ivod);
    $updated++;
    $done++;

    if ($done % 500 === 0) {
        Elastic::dbBulkCommit();
        error_log("reindex-speaker-identity: {$done} done, {$updated} updated");
    }
}

Elastic::dbBulkCommit();
error_log("reindex-speaker-identity: done={$done}, updated={$updated}, skipped={$skipped}, errors={$errors}");
echo "done={$done}, updated={$updated}, skipped={$skipped}, errors={$errors}\n";
