<?php

// 歷史補足：消化 wav/ 目錄中的 WAV，送 pyannote（不跑 whisper），存 ivod-pyannote/，刪 WAV
// 使用方式：php backfill-pyannote-process.php
// 設計：與 download 腳本並行執行

include(__DIR__ . '/../../init.inc.php');

$ivod_dir  = __DIR__;
$asr_base  = 'https://asr-tool.api.openfun.dev';
$asr_input = '/srv/data/api.openfun.dev/asr-tool/input';
$api_key   = getenv('OPENFUN_API_KEY');

function asr_submit_pyannote($wav_name) {
    global $asr_base, $api_key;
    $curl = curl_init("{$asr_base}/jobs");
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'tool'   => 'pyannote',
            'input'  => "local:{$wav_name}",
            'params' => ['return_embeddings' => true],
        ]),
        CURLOPT_HTTPHEADER => ["X-Api-Key: {$api_key}", 'Content-Type: application/json'],
    ]);
    $result = curl_exec($curl); curl_close($curl);
    return json_decode($result);
}

function asr_get($job_id) {
    global $asr_base, $api_key;
    $curl = curl_init("{$asr_base}/jobs/{$job_id}");
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["X-Api-Key: {$api_key}"]]);
    $result = curl_exec($curl); curl_close($curl);
    return json_decode($result);
}

function save_pyannote($v, $job_result) {
    global $ivod_dir;
    $speakers = $job_result->result->speakers ?? [];
    $data = ['id' => (string)$v, 'speakers' => []];
    foreach ($speakers as $spk) {
        $segs = array_map(fn($s) => [$s->start, $s->end], $spk->segments);
        $data['speakers'][] = [
            'id'            => $spk->id,
            'total_seconds' => $spk->total_seconds,
            'embedding'     => $spk->embedding ?? null,
            'segments'      => $segs,
        ];
    }
    file_put_contents($ivod_dir . "/ivod-pyannote/{$v}.json", json_encode($data, JSON_UNESCAPED_UNICODE));
}

// ──────────────────────────────────────────────
// Step 1: 輪詢上次未完成的 job（crash 後恢復）
// ──────────────────────────────────────────────

$job_cache_dir = $ivod_dir . '/ivod-asr-jobs';
$pending = [];
foreach (glob($job_cache_dir . '/pyannote-*.json') ?: [] as $f) {
    $info = json_decode(file_get_contents($f));
    if ($info && $info->job_id && $info->v) {
        $pending[$info->v] = $info->job_id;
    }
}

foreach ($pending as $v => $job_id) {
    $r = asr_get($job_id);
    error_log("pending [{$v}] ({$job_id}): {$r->status}" . ($r->stage ? " {$r->stage}" : ''));
    if ($r->status === 'done') {
        save_pyannote($v, $r);
        @unlink($job_cache_dir . "/pyannote-{$v}.json");
        @unlink($ivod_dir . "/wav/{$v}.wav");
        error_log("[{$v}] recovered from pending: " . count($r->result->speakers ?? []) . " speakers");
        unset($pending[$v]);
    } elseif ($r->status === 'error') {
        error_log("[{$v}] pending job error: " . ($r->error ?? 'unknown'));
        file_put_contents($ivod_dir . "/ivod-pyannote/{$v}.json", json_encode(['id' => (string)$v, 'error' => 'asr_failed', 'speakers' => []]));
        @unlink($job_cache_dir . "/pyannote-{$v}.json");
        @unlink($ivod_dir . "/wav/{$v}.wav");
        unset($pending[$v]);
    }
}

// ──────────────────────────────────────────────
// Step 2: 掃描 wav/ 目錄，送出新 job（最多 3 個）
// ──────────────────────────────────────────────

$wavs = [];
foreach (glob($ivod_dir . '/wav/*.wav') ?: [] as $wav_file) {
    $v = intval(basename($wav_file, '.wav'));
    if (file_exists($ivod_dir . "/ivod-pyannote/{$v}.json")) { @unlink($wav_file); continue; }
    if (file_exists($job_cache_dir . "/pyannote-{$v}.json")) continue;
    $wavs[] = $v;
}
rsort($wavs);
$to_submit = array_slice($wavs, 0, 3);

$new_jobs = [];
foreach ($to_submit as $v) {
    $wav_name = "ivod-backfill-{$v}.wav";
    if (!copy($ivod_dir . "/wav/{$v}.wav", $asr_input . '/' . $wav_name)) {
        error_log("[{$v}] copy to asr input failed");
        continue;
    }
    $job = asr_submit_pyannote($wav_name);
    if (empty($job->job_id)) {
        error_log("[{$v}] submit failed: " . json_encode($job));
        @unlink($asr_input . '/' . $wav_name);
        continue;
    }
    file_put_contents($job_cache_dir . "/pyannote-{$v}.json", json_encode(['job_id' => $job->job_id, 'v' => $v, 'wav_name' => $wav_name, 'submitted_at' => time()]));
    $new_jobs[$v] = $job->job_id;
    error_log("[{$v}] submitted: {$job->job_id}");
}

if (empty($new_jobs) && empty($pending)) {
    error_log('process: no jobs, nothing pending');
    exit(0);
}

// ──────────────────────────────────────────────
// Step 3: 等待新送出的 job 完成
// ──────────────────────────────────────────────

$wait_jobs = $new_jobs;
$start_time = time();

while (!empty($wait_jobs)) {
    if (time() - $start_time > 6000) {
        error_log("timeout waiting for jobs: " . implode(',', array_keys($wait_jobs)));
        break;
    }
    sleep(5);
    foreach ($wait_jobs as $v => $job_id) {
        $r = asr_get($job_id);
        error_log("[{$v}] {$r->status}" . ($r->stage ? " {$r->stage}" : ''));
        if ($r->status === 'done') {
            save_pyannote($v, $r);
            $wav_name = json_decode(file_get_contents($job_cache_dir . "/pyannote-{$v}.json"))->wav_name ?? "ivod-backfill-{$v}.wav";
            @unlink($job_cache_dir . "/pyannote-{$v}.json");
            @unlink($ivod_dir . "/wav/{$v}.wav");
            @unlink($asr_input . '/' . $wav_name);
            error_log("[{$v}] done: " . count($r->result->speakers ?? []) . " speakers");
            unset($wait_jobs[$v]);
        } elseif ($r->status === 'error') {
            error_log("[{$v}] asr error: " . ($r->error ?? 'unknown'));
            file_put_contents($ivod_dir . "/ivod-pyannote/{$v}.json", json_encode(['id' => (string)$v, 'error' => 'asr_failed', 'speakers' => []]));
            @unlink($job_cache_dir . "/pyannote-{$v}.json");
            @unlink($ivod_dir . "/wav/{$v}.wav");
            unset($wait_jobs[$v]);
        }
    }
}

error_log('process: batch done');
