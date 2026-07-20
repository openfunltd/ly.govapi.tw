<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');

$ivod_dir = __DIR__;
$asr_base = 'https://asr-tool.api.openfun.dev';
$asr_input_dir = '/srv/data/api.openfun.dev/asr-tool/input';
$api_key = getenv('OPENFUN_API_KEY');

// ──────────────────────────────────────────────
// 工具函式
// ──────────────────────────────────────────────

function asr_get($job_id) {
    global $asr_base, $api_key;
    $curl = curl_init("{$asr_base}/jobs/{$job_id}");
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["X-Api-Key: {$api_key}"],
    ]);
    $result = curl_exec($curl);
    curl_close($curl);
    return json_decode($result);
}

function asr_submit($wav_name) {
    global $asr_base, $api_key;
    $curl = curl_init("{$asr_base}/jobs");
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'tool' => 'pipeline',
            'input' => "local:{$wav_name}",
            'params' => ['language' => 'zh', 'model_id' => 'large'],
        ]),
        CURLOPT_HTTPHEADER => [
            "X-Api-Key: {$api_key}",
            'Content-Type: application/json',
        ],
    ]);
    $result = curl_exec($curl);
    curl_close($curl);
    return json_decode($result);
}

// pipeline 結果轉成向下相容的舊格式（同時保留 pipeline 原始結果）
function pipeline_to_transcript($v, $job_result) {
    $data = new StdClass;
    $data->id = (string)$v;

    // 保留 pipeline 原始結果
    $data->pipeline = $job_result;

    // 舊 pyannote 格式：[[start, end, speaker], ...]（從 speakers 展開）
    $pyannote_segs = [];
    foreach ($job_result->result->speakers ?? [] as $speaker) {
        foreach ($speaker->segments as $seg) {
            $pyannote_segs[] = [$seg->start, $seg->end, $speaker->id];
        }
    }
    usort($pyannote_segs, fn($a, $b) => $a[0] <=> $b[0]);
    $data->pyannote = (object)[
        'status' => 'done',
        'result' => (object)['result' => $pyannote_segs],
    ];

    // 舊 whisperx 格式：result->json 是 JSON 字串，內有 segments
    $whisperx_segs = [];
    foreach ($job_result->result->segments ?? [] as $seg) {
        $whisperx_segs[] = ['start' => $seg->start, 'end' => $seg->end, 'text' => $seg->text];
    }
    $data->whisperx = (object)[
        'status' => 'done',
        'result' => (object)[
            'json' => json_encode(['segments' => $whisperx_segs], JSON_UNESCAPED_UNICODE),
        ],
    ];

    return $data;
}

function save_transcript($v, $job_result) {
    global $ivod_dir, $asr_input_dir;
    $transcript = pipeline_to_transcript($v, $job_result);
    file_put_contents($ivod_dir . "/ivod-transcript/{$v}.json", json_encode($transcript, JSON_UNESCAPED_UNICODE));
    @unlink($ivod_dir . "/wav/{$v}.wav");
    @unlink($ivod_dir . "/ivod-asr-jobs/{$v}.json");
    @unlink($asr_input_dir . "/ivod-{$v}.wav");
    error_log("transcript done: {$v}");
}

// ──────────────────────────────────────────────
// Step 1：輪詢上次未完成的 job（crash 後恢復）
// ──────────────────────────────────────────────

$pending = [];
foreach (glob($ivod_dir . '/ivod-asr-jobs/*.json') ?: [] as $f) {
    $v = intval(basename($f, '.json'));
    $info = json_decode(file_get_contents($f));
    $pending[$v] = $info->job_id;
}

foreach ($pending as $v => $job_id) {
    $result = asr_get($job_id);
    error_log("pending {$v} ({$job_id}): {$result->status}" . ($result->stage ? " stage:{$result->stage}" : ''));
    if ($result->status === 'done') {
        save_transcript($v, $result);
        unset($pending[$v]);
    } elseif ($result->status === 'error') {
        error_log("asr error {$v}: " . ($result->error ?? 'unknown'));
        @unlink($ivod_dir . "/ivod-asr-jobs/{$v}.json");
        unset($pending[$v]);
    }
}

// ──────────────────────────────────────────────
// Step 2：掃描 wav/ 目錄，送出新 job
// ──────────────────────────────────────────────

$new_jobs = [];
foreach (glob($ivod_dir . '/wav/*.wav') ?: [] as $wav_file) {
    $v = intval(basename($wav_file, '.wav'));
    if (file_exists($ivod_dir . "/ivod-transcript/{$v}.json")) continue;
    if (file_exists($ivod_dir . "/ivod-asr-jobs/{$v}.json")) continue; // 已送出

    $wav_name = "ivod-{$v}.wav";
    $asr_path = $asr_input_dir . '/' . $wav_name;

    if (!copy($wav_file, $asr_path)) {
        error_log("copy failed: {$wav_file}");
        continue;
    }

    $job = asr_submit($wav_name);
    if (empty($job->job_id)) {
        error_log("submit failed for {$v}: " . json_encode($job));
        @unlink($asr_path);
        continue;
    }

    file_put_contents($ivod_dir . "/ivod-asr-jobs/{$v}.json", json_encode([
        'job_id' => $job->job_id,
        'v' => $v,
        'submitted_at' => time(),
    ]));
    $new_jobs[$v] = $job->job_id;
    error_log("submitted {$v}: {$job->job_id}");
}

// ──────────────────────────────────────────────
// Step 3：等待新送出的 job 完成
// ──────────────────────────────────────────────

if (empty($new_jobs) && empty($pending)) {
    error_log("no jobs, sleep 60 seconds");
    sleep(60);
}

$wait_jobs = $new_jobs;
$start_time = time();
$timeout = 6000;

while (!empty($wait_jobs)) {
    if (time() - $start_time > $timeout) {
        error_log("timeout waiting for jobs: " . implode(',', array_keys($wait_jobs)));
        break;
    }
    sleep(5);

    foreach ($wait_jobs as $v => $job_id) {
        $result = asr_get($job_id);
        error_log("{$v} ({$job_id}): {$result->status}" . ($result->stage ? " stage:{$result->stage}" : ''));

        if ($result->status === 'done') {
            save_transcript($v, $result);
            unset($wait_jobs[$v]);
        } elseif ($result->status === 'error') {
            error_log("asr error {$v}: " . ($result->error ?? 'unknown'));
            @unlink($ivod_dir . "/ivod-asr-jobs/{$v}.json");
            unset($wait_jobs[$v]);
        }
    }
}
