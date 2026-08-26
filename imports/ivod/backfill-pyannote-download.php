<?php

// 歷史補足：只下載 WAV，放到 wav/ 等 backfill-pyannote-process.php 消化
// 使用方式：limit=5 php backfill-pyannote-download.php
// 設計：與 process 腳本並行執行，各自跑各自的

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');

$ivod_dir   = __DIR__;
$batch      = intval(getenv('limit') ?: 5);

$proxy_urls = [];
$base_proxy = getenv('https_proxy');
if ($base_proxy) {
    $p = parse_url($base_proxy);
    $base = sprintf('%s://%s:%s@%s', $p['scheme'], $p['user'], $p['pass'], $p['host']);
    for ($i = 1; $i <= 5; $i++) $proxy_urls[] = $base . ':2000' . $i;
}

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
            // 保留 chunklist.m3u8 版本供 str_replace 用
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

// ──────────────────────────────────────────────
// 主流程
// ──────────────────────────────────────────────

$max_full_id = intval(file_get_contents($ivod_dir . '/current-full-id'));

$all_ids = array_map(fn($f) => intval(basename($f, '.json')), glob($ivod_dir . '/ivod-transcript/*.json') ?: []);
rsort($all_ids);

$downloaded = 0;
foreach ($all_ids as $v) {
    if ($downloaded >= $batch) break;
    if (file_exists($ivod_dir . "/ivod-pyannote/{$v}.json")) continue;  // 已完成
    $wav_path = $ivod_dir . "/wav/{$v}.wav";
    if (file_exists($wav_path) || file_exists($wav_path . '.tmp')) continue;  // 已在下載中或等處理
    if (!file_exists($ivod_dir . "/html/{$v}.html")) continue;

    $type = $v <= $max_full_id ? 'Full' : 'Clip';
    $html = file_get_contents($ivod_dir . "/html/{$v}.html");
    $ivod = IVodParser::parseHTML($v, $html, $type);
    error_log("[{$v}] downloading {$type} {$ivod->start_time}...");

    try {
        download_wav($v, $type, $wav_path, $proxy_urls);
        error_log("[{$v}] WAV ready: " . round(filesize($wav_path) / 1024 / 1024, 1) . ' MB');
        $downloaded++;
    } catch (Exception $e) {
        error_log("[{$v}] download failed: " . $e->getMessage());
        // 標記失敗，不再重試
        file_put_contents($ivod_dir . "/ivod-pyannote/{$v}.json", json_encode(['id' => (string)$v, 'error' => 'download_failed', 'speakers' => []]));
    }
}

error_log("download: {$downloaded} WAVs ready");
