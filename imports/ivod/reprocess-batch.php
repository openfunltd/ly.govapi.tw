<?php

// 重跑指定 ivod id 清單（舊 pyannote 結果疑似長音檔分群合併錯誤），
// 用新版 asr-tool 重新產生 ivod-pyannote / ivod-transcript，蓋掉舊結果。
// 需求：imports/ivod/wav/{id}.wav 必須存在。
//
// 使用：
//   only_ids=17742,17566 php reprocess-batch.php
//   preloaded=17742:/path/to/result.json only_ids=... php reprocess-batch.php   # 17742 已經有現成結果，直接套用不重送

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');

// 這支只打 asr-tool/ES，不碰 ivod.ly.gov.tw，清掉 config.php 給 CDN 下載用的
// https_proxy（process-wide putenv 會被 curl 自動套用到所有 https 請求，
// 不相關的流量走這個 proxy 會不穩定甚至卡住）。
putenv('https_proxy');

$ivod_dir = __DIR__;
$asr_base = 'https://asr-tool.api.openfun.dev';
$asr_input_dir = '/srv/data/api.openfun.dev/asr-tool/input';
$api_key = getenv('OPENFUN_API_KEY');

$only_ids = getenv('only_ids') ? explode(',', getenv('only_ids')) : [];
if (!$only_ids) { fwrite(STDERR, "need only_ids\n"); exit(1); }

$preloaded = [];
if (getenv('preloaded')) {
    foreach (explode(',', getenv('preloaded')) as $pair) {
        [$id, $path] = explode(':', $pair, 2);
        $preloaded[$id] = $path;
    }
}

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
            'params' => ['language' => 'zh', 'model_id' => 'medium', 'return_embeddings' => true],
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

function identify_speaker($embedding_b64, $term = 11) {
    // 門檻與比對規則統一在 IVodParser::identifySpeaker（含宣讀人員 role=reader 的處理）
    return IVodParser::identifySpeaker($embedding_b64, $term);
}

function pipeline_to_transcript($v, $job_result) {
    $data = new StdClass;
    $data->id = (string)$v;
    $data->pipeline = $job_result;

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

function save_pyannote($v, $job_result) {
    global $ivod_dir;
    $speakers = $job_result->result->speakers ?? [];
    $has_embedding = false;
    foreach ($speakers as $spk) {
        if (isset($spk->embedding)) { $has_embedding = true; break; }
    }
    if (!$has_embedding) return;
    $data = ['id' => (string)$v, 'speakers' => []];
    foreach ($speakers as $spk) {
        if (!isset($spk->embedding)) continue; // 太短的雜訊 speaker 可能沒有 embedding
        $entry = [
            'id'            => $spk->id,
            'total_seconds' => $spk->total_seconds,
            'embedding'     => $spk->embedding,
            'segments'      => array_map(fn($s) => [$s->start, $s->end], $spk->segments),
        ];
        $identity = identify_speaker($spk->embedding);
        if ($identity) {
            $entry['identity'] = $identity;
        }
        $data['speakers'][] = $entry;
    }
    file_put_contents($ivod_dir . "/ivod-pyannote/{$v}.json", json_encode($data, JSON_UNESCAPED_UNICODE));
    error_log("pyannote saved: {$v} (" . count($speakers) . " speakers)");
}

function save_transcript($v, $job_result) {
    global $ivod_dir, $asr_input_dir;
    $transcript = pipeline_to_transcript($v, $job_result);
    file_put_contents($ivod_dir . "/ivod-transcript/{$v}.json", json_encode($transcript, JSON_UNESCAPED_UNICODE));
    save_pyannote($v, $job_result);
    @unlink($ivod_dir . "/wav/{$v}.wav");
    @unlink($ivod_dir . "/ivod-asr-jobs/{$v}.json");
    @unlink($asr_input_dir . "/{$v}.wav");
    @unlink($asr_input_dir . "/ivod-{$v}.wav");
    error_log("transcript done: {$v}");
}

// ──────────────────────────────────────────────

foreach ($only_ids as $v) {
    $v = trim($v);
    error_log("=== 開始處理 {$v} ===");

    if (isset($preloaded[$v])) {
        $result = json_decode(file_get_contents($preloaded[$v]));
        if (!$result || $result->status !== 'done') {
            error_log("{$v}: preloaded 結果無效，略過");
            continue;
        }
        save_transcript($v, $result);
        continue;
    }

    $wav_path = "$ivod_dir/wav/{$v}.wav";
    if (!file_exists($wav_path)) {
        error_log("{$v}: wav 不存在，略過");
        continue;
    }

    $asr_path = "{$asr_input_dir}/{$v}.wav";
    if (!copy($wav_path, $asr_path)) {
        error_log("{$v}: copy 到 asr input 失敗");
        continue;
    }

    $job = asr_submit("{$v}.wav");
    if (empty($job->job_id)) {
        error_log("{$v}: submit 失敗 " . json_encode($job));
        @unlink($asr_path);
        continue;
    }
    error_log("{$v}: submitted {$job->job_id}");

    $start = time();
    $timeout = 3600;
    while (true) {
        if (time() - $start > $timeout) {
            error_log("{$v}: timeout");
            break;
        }
        sleep(30);
        $result = asr_get($job->job_id);
        error_log("{$v} ({$job->job_id}): {$result->status}" . ($result->stage ? " stage:{$result->stage}" : ''));
        if ($result->status === 'done') {
            save_transcript($v, $result);
            break;
        }
        if ($result->status === 'error') {
            error_log("{$v}: asr error " . ($result->error ?? 'unknown'));
            @unlink($asr_path);
            break;
        }
    }
}

error_log("=== 全部完成 ===");
