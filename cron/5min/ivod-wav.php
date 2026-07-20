<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../imports/ivod/IVodParser.php');

$ivod_dir = __DIR__ . '/../../imports/ivod';

// 從 https_proxy env 衍生 5 組 proxy URL (port 20001-20005)
$proxy_urls = [];
$base_proxy = getenv('https_proxy');
if ($base_proxy) {
    $p = parse_url($base_proxy);
    $base = sprintf('%s://%s:%s@%s', $p['scheme'], $p['user'], $p['pass'], $p['host']);
    for ($i = 1; $i <= 5; $i++) {
        $proxy_urls[] = $base . ':2000' . $i;
    }
}

// 取得 m3u8 URL 與 TS 清單
function get_m3u8($video_id, $type) {
    foreach (['300K', '1M'] as $quality) {
        $url = sprintf("https://ivod.ly.gov.tw/Play/%s/%s/%d", $type, $quality, $video_id);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; rv:8.0) Gecko/20100101 Firefox/8.0',
        ]);
        $content = curl_exec($curl);
        curl_close($curl);
        if (!preg_match('#readyPlayer\("([^"]*)#', $content, $m)) continue;

        $m3u8_url = str_replace('playlist.m3u8', 'chunklist.m3u8', $m[1]);
        $curl = curl_init($m3u8_url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
        $content = curl_exec($curl);
        curl_close($curl);

        // 嘗試取有解析度的 chunklist_w{n}.m3u8
        if (preg_match('#chunklist_w(\d+)\.m3u8#', $content, $m)) {
            $w_url = str_replace('chunklist.m3u8', 'chunklist_w' . $m[1] . '.m3u8', $m3u8_url);
            $curl = curl_init($w_url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
            $tmp = curl_exec($curl);
            curl_close($curl);
            if ($tmp) $content = $tmp;
        }

        if (!$content) continue;
        $ts_list = array_values(array_filter(explode("\n", trim($content)), fn($l) => $l && $l[0] !== '#'));
        if (empty($ts_list)) continue;
        return [$m3u8_url, $ts_list];
    }
    throw new Exception("Failed to get m3u8: {$type}/{$video_id}");
}

// 並行下載 TS 檔（最多 5 條同時，每條走不同 proxy）
function download_ts_parallel($m3u8_url, $ts_list, $cache_dir, $proxy_urls) {
    $ips = gethostbynamel('ivod-lyvod.cdn.hinet.net') ?: [];
    $max_parallel = $proxy_urls ? count($proxy_urls) : 1;

    $make_cmd = function($ts, $idx, $retry = 0) use ($m3u8_url, $cache_dir, $proxy_urls, $ips) {
        $file_url = str_replace('chunklist.m3u8', $ts, $m3u8_url);
        $target = $cache_dir . '/' . $ts;
        $cmd = 'curl --max-time 15 --connect-timeout 10 --retry 0 -4';
        if ($ips) {
            $ip = $ips[($idx + $retry) % count($ips)];
            $cmd .= ' --resolve ' . escapeshellarg("ivod-lyvod.cdn.hinet.net:443:{$ip}");
        }
        if ($proxy_urls) {
            $proxy = $proxy_urls[$idx % count($proxy_urls)];
            $cmd .= ' -x ' . escapeshellarg($proxy);
        }
        $cmd .= ' -o ' . escapeshellarg($target) . ' ' . escapeshellarg($file_url);
        return [$cmd, $target, $file_url];
    };

    // 建立任務佇列
    $queue = [];
    foreach ($ts_list as $idx => $ts) {
        $queue[] = ['ts' => $ts, 'idx' => $idx, 'retry' => 0];
    }

    $running = [];
    $done = [];

    while (!empty($queue) || !empty($running)) {
        // 補滿並行槽
        while (count($running) < $max_parallel && !empty($queue)) {
            $task = array_shift($queue);
            [$cmd, $target, $file_url] = $make_cmd($task['ts'], $task['idx'], $task['retry']);
            error_log("[{$task['idx']}] download: {$task['ts']}" . ($task['retry'] ? " (retry {$task['retry']})" : ''));
            $proc = proc_open($cmd, [], $pipes);
            $running[] = $task + compact('proc', 'target', 'cmd', 'file_url');
        }

        usleep(200000); // 200ms

        foreach ($running as $k => $item) {
            $status = proc_get_status($item['proc']);
            if ($status['running']) continue;
            proc_close($item['proc']);
            unset($running[$k]);

            $ok = $status['exitcode'] === 0
                && file_exists($item['target'])
                && filesize($item['target']) > 0;

            if ($ok) {
                $done[$item['idx']] = $item['ts'];
            } elseif ($item['retry'] < 5) {
                sleep($item['retry']);
                $queue[] = ['ts' => $item['ts'], 'idx' => $item['idx'], 'retry' => $item['retry'] + 1];
            } else {
                throw new Exception("Failed after 5 retries: {$item['ts']}");
            }
        }
        $running = array_values($running);
    }

    ksort($done);
    return array_values($done);
}

// 下載 IVOD 並轉成 WAV
function download_ivod_to_wav($video_id, $type, $wav_path, $proxy_urls) {
    global $ivod_dir;
    $cache_dir = $ivod_dir . '/wav-cache/' . $video_id;
    if (!file_exists($cache_dir)) mkdir($cache_dir, 0777, true);

    try {
        [$m3u8_url, $ts_list] = get_m3u8($video_id, $type);
        $files = download_ts_parallel($m3u8_url, $ts_list, $cache_dir, $proxy_urls);

        if (empty($files)) throw new Exception("No TS files downloaded");

        $mylist = $cache_dir . '/mylist.txt';
        file_put_contents($mylist, implode("\n", array_map(fn($f) => "file '$f'", $files)) . "\n");

        $wav_tmp = $wav_path . '.tmp';
        $cmd = sprintf(
            'ffmpeg -y -f concat -safe 0 -i %s -vn -acodec pcm_s16le -ar 16000 -ac 1 -f wav %s 2>&1',
            escapeshellarg($mylist), escapeshellarg($wav_tmp)
        );
        system($cmd, $ret);

        if ($ret !== 0 || !file_exists($wav_tmp) || filesize($wav_tmp) < 10000) {
            throw new Exception("ffmpeg failed");
        }
        rename($wav_tmp, $wav_path);
    } finally {
        // 清理 cache
        foreach (glob($cache_dir . '/*') as $f) @unlink($f);
        @rmdir($cache_dir);
    }
}

// ──────────────────────────────────────────────
// 主流程
// ──────────────────────────────────────────────

$endtime = time() - 6 * 30 * 86400; // 只處理 6 個月內的影片
$max_clip = 3;
$max_full = 1;
$c_clip = 0;
$c_full = 0;

// Clip：從 169400 往後掃
$max_v = intval(file_get_contents($ivod_dir . '/current-id'));
for ($v = 169400; $v <= $max_v; $v++) {
    if ($c_clip >= $max_clip) break;

    $html = $ivod_dir . "/html/{$v}.html";
    if (!file_exists($html)) continue;
    if (file_exists($ivod_dir . "/ivod-transcript/{$v}.json")) continue;
    if (file_exists($ivod_dir . "/wav/{$v}.wav")) continue;
    if (file_exists($ivod_dir . "/wav/{$v}.wav.tmp")) continue; // 下載中

    $ivod = IVodParser::parseHTML($v, file_get_contents($html));
    if (strtotime($ivod->start_time) < $endtime) continue;

    error_log("Clip {$v}: {$ivod->委員名稱} {$ivod->影片長度}");
    try {
        download_ivod_to_wav($v, 'Clip', $ivod_dir . "/wav/{$v}.wav", $proxy_urls);
        error_log("Clip {$v} done: " . round(filesize($ivod_dir . "/wav/{$v}.wav") / 1024 / 1024, 1) . ' MB');
        $c_clip++;
    } catch (Exception $e) {
        error_log("Clip {$v} error: " . $e->getMessage());
    }
}

// Full：從 current-full-id 往前掃
if ($c_full < $max_full) {
    $max_full_id = intval(file_get_contents($ivod_dir . '/current-full-id'));
    for ($v = $max_full_id; $v > 0; $v--) {
        if ($c_full >= $max_full) break;

        $html = $ivod_dir . "/html/{$v}.html";
        if (!file_exists($html)) continue;

        $content = file_get_contents($html);
        if (strpos($content, '"rettim":null') !== false) continue; // 直播中

        if (file_exists($ivod_dir . "/ivod-transcript/{$v}.json")) continue;
        if (file_exists($ivod_dir . "/wav/{$v}.wav")) continue;
        if (file_exists($ivod_dir . "/wav/{$v}.wav.tmp")) continue;

        $ivod = IVodParser::parseHTML($v, $content, 'Full');
        if (strtotime($ivod->start_time) < $endtime) break;

        error_log("Full {$v}: {$ivod->會議名稱} {$ivod->影片長度}");
        try {
            download_ivod_to_wav($v, 'Full', $ivod_dir . "/wav/{$v}.wav", $proxy_urls);
            error_log("Full {$v} done: " . round(filesize($ivod_dir . "/wav/{$v}.wav") / 1024 / 1024, 1) . ' MB');
            $c_full++;
        } catch (Exception $e) {
            error_log("Full {$v} error: " . $e->getMessage());
        }
    }
}

error_log("done: clip={$c_clip} full={$c_full}");
