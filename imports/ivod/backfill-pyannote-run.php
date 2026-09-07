<?php

// 歷史補足持續執行版本：不停跑，直到所有 transcript 都補上 pyannote embedding
// 使用方式：php backfill-pyannote-run.php
// 中斷方式：Ctrl+C（每批做完才會退出）

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');

$ivod_dir  = __DIR__;
$asr_base  = 'https://asr-tool.api.openfun.dev';
$asr_input = '/srv/data/api.openfun.dev/asr-tool/input';
$api_key   = getenv('OPENFUN_API_KEY');
$batch     = intval(getenv('limit') ?: 5);

$proxy_urls = [];
$base_proxy = getenv('https_proxy');
if ($base_proxy) {
    $p = parse_url($base_proxy);
    $base = sprintf('%s://%s:%s@%s', $p['scheme'], $p['user'], $p['pass'], $p['host']);
    for ($i = 1; $i <= 5; $i++) $proxy_urls[] = $base . ':2000' . $i;
}

// ──────────────────────────────────────────────
// 工具函式
// ──────────────────────────────────────────────

function get_m3u8($video_id, $type) {
    foreach (['300K', '1M'] as $quality) {
        $url = sprintf('https://ivod.ly.gov.tw/Play/%s/%s/%d', $type, $quality, $video_id);
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; rv:8.0) Gecko/20100101 Firefox/8.0']);
        $content = curl_exec($curl); curl_close($curl);
        if (!preg_match('#readyPlayer\("([^"]*)#', $content, $m)) continue;
        $m3u8_url = str_replace('playlist.m3u8', 'chunklist.m3u8', $m[1]);
        $curl = curl_init($m3u8_url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
        $content = curl_exec($curl); curl_close($curl);
        if (preg_match('#chunklist_w(\d+)\.m3u8#', $content, $m)) {
            $w_url = str_replace('chunklist.m3u8', 'chunklist_w' . $m[1] . '.m3u8', $m3u8_url);
            $curl = curl_init($w_url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
            $tmp = curl_exec($curl); curl_close($curl);
            if ($tmp) $content = $tmp;
        }
        if (!$content) continue;
        $ts_list = array_values(array_filter(explode("\n", trim($content)), fn($l) => $l && $l[0] !== '#'));
        if (empty($ts_list)) continue;
        return [$m3u8_url, $ts_list];
    }
    throw new Exception("Failed to get m3u8: {$type}/{$video_id}");
}

function download_wav($video_id, $type, $wav_path, $proxy_urls) {
    global $ivod_dir;
    $cache_dir = $ivod_dir . '/wav-cache/' . $video_id;
    if (!file_exists($cache_dir)) mkdir($cache_dir, 0777, true);
    try {
        [$m3u8_url, $ts_list] = get_m3u8($video_id, $type);
        $ips = gethostbynamel('ivod-lyvod.cdn.hinet.net') ?: [];
        $max_p = $proxy_urls ? count($proxy_urls) : 1;
        $queue = array_map(fn($ts, $i) => ['ts' => $ts, 'idx' => $i, 'retry' => 0], $ts_list, array_keys($ts_list));
        $running = []; $done = [];
        while (!empty($queue) || !empty($running)) {
            while (count($running) < $max_p && !empty($queue)) {
                $task = array_shift($queue);
                $target = $cache_dir . '/' . $task['ts'];
                $file_url = str_replace('chunklist.m3u8', $task['ts'], $m3u8_url);
                $cmd = 'curl --max-time 15 --connect-timeout 10 --retry 0 -s -4';
                if ($ips) $cmd .= ' --resolve ' . escapeshellarg('ivod-lyvod.cdn.hinet.net:443:' . $ips[($task['idx'] + $task['retry']) % count($ips)]);
                if ($proxy_urls) $cmd .= ' -x ' . escapeshellarg($proxy_urls[$task['idx'] % count($proxy_urls)]);
                $cmd .= ' -o ' . escapeshellarg($target) . ' ' . escapeshellarg($file_url);
                $running[] = $task + ['proc' => proc_open($cmd, [], $pipes), 'target' => $target];
            }
            usleep(200000);
            foreach ($running as $k => $item) {
                $s = proc_get_status($item['proc']);
                if ($s['running']) continue;
                proc_close($item['proc']); unset($running[$k]);
                if ($s['exitcode'] === 0 && file_exists($item['target']) && filesize($item['target']) > 0) {
                    $done[$item['idx']] = $item['ts'];
                } elseif ($item['retry'] < 5) {
                    sleep($item['retry']);
                    $queue[] = ['ts' => $item['ts'], 'idx' => $item['idx'], 'retry' => $item['retry'] + 1];
                } else {
                    throw new Exception("TS download failed: {$item['ts']}");
                }
            }
            $running = array_values($running);
        }
        ksort($done);
        $mylist = $cache_dir . '/mylist.txt';
        file_put_contents($mylist, implode("\n", array_map(fn($f) => "file '$f'", array_map(fn($f) => $cache_dir . '/' . $f, array_values($done)))) . "\n");
        $wav_tmp = $wav_path . '.tmp';
        system(sprintf('ffmpeg -y -f concat -safe 0 -i %s -vn -acodec pcm_s16le -ar 16000 -ac 1 -f wav %s 2>/dev/null', escapeshellarg($mylist), escapeshellarg($wav_tmp)), $ret);
        if ($ret !== 0 || !file_exists($wav_tmp) || filesize($wav_tmp) < 10000) throw new Exception('ffmpeg failed');
        rename($wav_tmp, $wav_path);
    } finally {
        foreach (glob($cache_dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($cache_dir);
    }
}

function asr_submit_pyannote($wav_name) {
    global $asr_base, $api_key;
    $curl = curl_init("{$asr_base}/jobs");
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['tool' => 'pyannote', 'input' => "local:{$wav_name}", 'params' => ['return_embeddings' => true]]),
        CURLOPT_HTTPHEADER => ["X-Api-Key: {$api_key}", 'Content-Type: application/json'],
    ]);
    $result = curl_exec($curl); curl_close($curl);
    return json_decode($result);
}

function asr_poll($job_id) {
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
        $data['speakers'][] = ['id' => $spk->id, 'total_seconds' => $spk->total_seconds, 'embedding' => $spk->embedding ?? null, 'segments' => $segs];
    }
    file_put_contents($ivod_dir . "/ivod-pyannote/{$v}.json", json_encode($data, JSON_UNESCAPED_UNICODE));
}

function run_batch($todo, $proxy_urls) {
    global $ivod_dir, $asr_input, $batch;
    $max_full_id = intval(file_get_contents($ivod_dir . '/current-full-id'));
    $ready_wavs = [];
    $inflight   = [];

    foreach ($todo as $v) {
        $type     = $v <= $max_full_id ? 'Full' : 'Clip';
        $html     = file_get_contents($ivod_dir . "/html/{$v}.html");
        $ivod     = IVodParser::parseHTML($v, $html, $type);
        $wav_path = sys_get_temp_dir() . "/ivod-bf-{$v}.wav";

        // 提交上一批
        foreach ($ready_wavs as $rv => $rwav) {
            $wav_name = "ivod-backfill-{$rv}.wav";
            if (!copy($rwav, $asr_input . '/' . $wav_name)) {
                error_log("[{$rv}] copy failed");
                file_put_contents($ivod_dir . "/ivod-pyannote/{$rv}.json", json_encode(['id' => (string)$rv, 'error' => 'copy_failed', 'speakers' => []]));
                @unlink($rwav); continue;
            }
            $job = asr_submit_pyannote($wav_name);
            if (empty($job->job_id)) {
                error_log("[{$rv}] submit failed: " . json_encode($job));
                file_put_contents($ivod_dir . "/ivod-pyannote/{$rv}.json", json_encode(['id' => (string)$rv, 'error' => 'submit_failed', 'speakers' => []]));
                @unlink($rwav); @unlink($asr_input . '/' . $wav_name); continue;
            }
            $inflight[$rv] = ['job_id' => $job->job_id, 'wav_name' => $wav_name, 'wav_path' => $rwav];
            error_log("[{$rv}] submitted: {$job->job_id}");
        }
        $ready_wavs = [];

        // 下載目前這支
        error_log("[{$v}] downloading {$type} {$ivod->start_time}...");
        try {
            download_wav($v, $type, $wav_path, $proxy_urls);
            error_log("[{$v}] WAV: " . round(filesize($wav_path) / 1024 / 1024, 1) . ' MB');
            $ready_wavs[$v] = $wav_path;
        } catch (Exception $e) {
            error_log("[{$v}] download failed: " . $e->getMessage());
            file_put_contents($ivod_dir . "/ivod-pyannote/{$v}.json", json_encode(['id' => (string)$v, 'error' => 'download_failed', 'speakers' => []]));
        }

        // 輪詢 inflight
        $still = [];
        foreach ($inflight as $rv => $info) {
            $r = asr_poll($info['job_id']);
            error_log("[{$rv}] asr {$r->status}" . ($r->stage ? " {$r->stage}" : ''));
            if ($r->status === 'done') {
                save_pyannote($rv, $r);
                @unlink($info['wav_path']); @unlink($asr_input . '/' . $info['wav_name']);
                error_log("[{$rv}] done: " . count($r->result->speakers ?? []) . " speakers");
            } elseif ($r->status === 'error') {
                error_log("[{$rv}] asr error: " . ($r->error ?? 'unknown'));
                file_put_contents($ivod_dir . "/ivod-pyannote/{$rv}.json", json_encode(['id' => (string)$rv, 'error' => 'asr_failed', 'speakers' => []]));
                @unlink($info['wav_path']); @unlink($asr_input . '/' . $info['wav_name']);
            } else {
                $still[$rv] = $info;
            }
        }
        $inflight = $still;
    }

    // 提交最後一批
    foreach ($ready_wavs as $rv => $rwav) {
        $wav_name = "ivod-backfill-{$rv}.wav";
        if (!copy($rwav, $asr_input . '/' . $wav_name)) {
            file_put_contents($ivod_dir . "/ivod-pyannote/{$rv}.json", json_encode(['id' => (string)$rv, 'error' => 'copy_failed', 'speakers' => []]));
            @unlink($rwav); continue;
        }
        $job = asr_submit_pyannote($wav_name);
        if (empty($job->job_id)) {
            file_put_contents($ivod_dir . "/ivod-pyannote/{$rv}.json", json_encode(['id' => (string)$rv, 'error' => 'submit_failed', 'speakers' => []]));
            @unlink($rwav); @unlink($asr_input . '/' . $wav_name); continue;
        }
        $inflight[$rv] = ['job_id' => $job->job_id, 'wav_name' => $wav_name, 'wav_path' => $rwav];
        error_log("[{$rv}] submitted: {$job->job_id}");
    }

    // 等所有 inflight 完成
    $deadline = time() + 6000;
    while (!empty($inflight)) {
        if (time() > $deadline) { error_log("timeout on remaining asr jobs"); break; }
        sleep(5);
        $still = [];
        foreach ($inflight as $rv => $info) {
            $r = asr_poll($info['job_id']);
            error_log("[{$rv}] asr {$r->status}" . ($r->stage ? " {$r->stage}" : ''));
            if ($r->status === 'done') {
                save_pyannote($rv, $r);
                @unlink($info['wav_path']); @unlink($asr_input . '/' . $info['wav_name']);
                error_log("[{$rv}] done: " . count($r->result->speakers ?? []) . " speakers");
            } elseif ($r->status === 'error') {
                error_log("[{$rv}] asr error: " . ($r->error ?? 'unknown'));
                file_put_contents($ivod_dir . "/ivod-pyannote/{$rv}.json", json_encode(['id' => (string)$rv, 'error' => 'asr_failed', 'speakers' => []]));
                @unlink($info['wav_path']); @unlink($asr_input . '/' . $info['wav_name']);
            } else {
                $still[$rv] = $info;
            }
        }
        $inflight = $still;
    }
}

// ──────────────────────────────────────────────
// 持續執行主迴圈
// ──────────────────────────────────────────────

error_log("backfill-pyannote-run: start (batch={$batch})");

while (true) {
    // 每輪重新掃描（新影片可能加進來）
    $all_ids = array_map(fn($f) => intval(basename($f, '.json')), glob($ivod_dir . '/ivod-transcript/*.json') ?: []);
    rsort($all_ids);

    $todo = [];
    foreach ($all_ids as $v) {
        if (count($todo) >= $batch) break;
        // 第11屆（2024-02-01）以前不處理：Full < 15664，Clip < 149103
        if ($v <= $max_full_id && $v < 15664) continue;
        if ($v > $max_full_id  && $v < 149103) continue;
        if (file_exists($ivod_dir . "/ivod-pyannote/{$v}.json")) continue;
        if (!file_exists($ivod_dir . "/html/{$v}.html")) continue;
        $todo[] = $v;
    }

    if (empty($todo)) {
        error_log("backfill-pyannote-run: all done!");
        break;
    }

    $done_count = count(glob($ivod_dir . '/ivod-pyannote/*.json') ?: []);
    $total      = count($all_ids);
    error_log(sprintf("backfill: %d/%d done, processing next %d (ids %d..%d)",
        $done_count, $total, count($todo), end($todo), reset($todo)));

    run_batch($todo, $proxy_urls);
}
