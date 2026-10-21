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

// 取得媒體清單 URL 與 TS 分段清單
//
// 正確的鏈是 playlist.m3u8（主清單，#EXT-X-STREAM-INF）→ chunklist_w<id>.m3u8（媒體清單）→ media_N.ts，
// 而且網址裡帶的 token 每次請求都不同，分段必須沿著同一次拿到的媒體清單網址解析。
// 2026-10-20 事故：舊版把 playlist.m3u8 直接改名成 chunklist.m3u8 去抓，CDN 回的是一份「變體就是它自己」的
// 主清單，非 # 的行只有 chunklist.m3u8 一行，被當成 TS 分段下載後餵給 ffmpeg，ffmpeg 把它當 HLS 清單打開、
// 又指回自己 → 無窮迴圈吃光記憶體，整台機器卡死。所以這裡：
//   1. 照規格解析主清單、解析相對網址，變體指回自己就視為失敗
//   2. 分段只收 .ts，任何 .m3u8 都不准進清單
function m3u8_lines($content) {
    return array_values(array_filter(array_map('trim', explode("\n", $content)), fn($l) => $l !== '' && $l[0] !== '#'));
}
function m3u8_resolve($base_url, $ref) {
    if (preg_match('#^https?://#', $ref)) return $ref;
    if ($ref[0] === '/') { $p = parse_url($base_url); return "{$p['scheme']}://{$p['host']}" . (isset($p['port']) ? ":{$p['port']}" : '') . $ref; }
    return preg_replace('#/[^/]*$#', '/', $base_url) . $ref;
}
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
        $playlist_url = $m[1];

        $fetch = function($u) {
            $c = curl_init($u);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_TIMEOUT => 30]);
            $r = curl_exec($c); curl_close($c);
            return (string)$r;
        };

        // 最多跟兩層（主清單 → 媒體清單），每層都檢查不是指回自己
        $media_url = $playlist_url;
        $media = $fetch($media_url);
        for ($hop = 0; $hop < 2 && strpos($media, '#EXT-X-STREAM-INF') !== false; $hop++) {
            $variants = array_values(array_filter(m3u8_lines($media), fn($l) => preg_match('#\.m3u8(\?|$)#', $l)));
            if (!$variants) { $media = ''; break; }
            $next = m3u8_resolve($media_url, $variants[0]);
            if ($next === $media_url || basename(parse_url($next, PHP_URL_PATH)) === basename(parse_url($media_url, PHP_URL_PATH))) {
                error_log("get_m3u8: {$type}/{$video_id} 變體清單指回自己（{$variants[0]}），放棄 {$quality}");
                $media = ''; break;
            }
            $media_url = $next;
            $media = $fetch($media_url);
        }
        if (!$media || strpos($media, '#EXT-X-STREAM-INF') !== false) continue;

        $ts_list = array_values(array_filter(m3u8_lines($media), fn($l) => preg_match('#\.ts(\?|$)#', $l)));
        $bad = array_filter(m3u8_lines($media), fn($l) => !preg_match('#\.ts(\?|$)#', $l));
        if ($bad) error_log("get_m3u8: {$type}/{$video_id} 媒體清單有非 .ts 項目被略過: " . implode(',', array_slice($bad, 0, 3)));
        if (empty($ts_list)) continue;
        return [$media_url, $ts_list];
    }
    throw new Exception("Failed to get m3u8: {$type}/{$video_id}");
}

// 並行下載 TS 檔（最多 5 條同時，每條走不同 proxy）
function download_ts_parallel($m3u8_url, $ts_list, $cache_dir, $proxy_urls) {
    $ips = gethostbynamel('ivod-lyvod.cdn.hinet.net') ?: [];
    $max_parallel = $proxy_urls ? count($proxy_urls) : 1;

    $make_cmd = function($ts, $idx, $retry = 0) use ($m3u8_url, $cache_dir, $proxy_urls, $ips) {
        if (!preg_match('#\.ts(\?|$)#', $ts)) throw new Exception("not a ts segment: {$ts}");
        $file_url = m3u8_resolve($m3u8_url, $ts);
        $target = $cache_dir . '/' . basename(parse_url($ts, PHP_URL_PATH));
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
                $done[$item['idx']] = basename(parse_url($item['ts'], PHP_URL_PATH));
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
        // 保險：ffmpeg 一律限制 CPU 時間與虛擬記憶體（Clip 20 分鐘、Full 90 分鐘；3 GB），
        // 就算清單再出問題也只會失敗，不會把整台機器拖垮
        $limit_sec = $type === 'Full' ? 5400 : 1200;
        $cmd = sprintf(
            'bash -c %s 2>&1',
            escapeshellarg(sprintf(
                'ulimit -v 3000000; exec timeout -k 15 %d ffmpeg -nostdin -loglevel error -y -f concat -safe 0 -i %s -vn -acodec pcm_s16le -ar 16000 -ac 1 -f wav %s',
                $limit_sec, escapeshellarg($mylist), escapeshellarg($wav_tmp)
            ))
        );
        exec($cmd, $output, $ret);

        if ($ret !== 0 || !file_exists($wav_tmp) || filesize($wav_tmp) < 10000) {
            @unlink($wav_tmp);
            throw new Exception("ffmpeg failed (exit {$ret}): " . implode(' ', array_slice($output, -3)));
        }
        rename($wav_tmp, $wav_path);
    } finally {
        // 清理 cache
        foreach (glob($cache_dir . '/*') as $f) @unlink($f);
        @rmdir($cache_dir);
    }
}

// 失敗標記：下載／轉檔失敗的影片寫 wav/{id}.wav.failed（每次失敗追加一行時間與原因），
// 常態掃描依失敗次數指數退避：第 1 次 5 分鐘後可重試，之後 10、20、40、80、160 分鐘，最長 6 小時。
// 剛上架的影片 CDN 的 playlist 常還是 404，要很快重試；真的壞掉的（例如已下架）才會越退越久。
// 成功後清掉標記；only_ids 模式不受限。
function mark_failed($wav_path, $msg) {
    @file_put_contents($wav_path . '.failed', date('c') . ' ' . $msg . "\n", FILE_APPEND);
}
function recently_failed($wav_path) {
    $f = $wav_path . '.failed';
    if (!file_exists($f)) return false;
    $n = max(1, count(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
    $wait = min(6 * 3600, 300 * (2 ** ($n - 1)));
    return (time() - filemtime($f)) < $wait;
}

// ──────────────────────────────────────────────
// 指定 id 模式：only_ids=17717,17734 php ivod-wav.php
// 不管 ivod-transcript 是否已存在，直接下載 wav（給 reprocess-batch.php 重跑舊結果用），
// 下載完即結束，不進入下方的常態掃描流程。
// ──────────────────────────────────────────────

if (getenv('only_ids')) {
    foreach (explode(',', getenv('only_ids')) as $v) {
        $v = intval(trim($v));
        if (!$v) continue;
        $wav_path = $ivod_dir . "/wav/{$v}.wav";
        if (file_exists($wav_path)) { error_log("{$v}: wav 已存在，略過"); continue; }
        $html = $ivod_dir . "/html/{$v}.html";
        if (!file_exists($html)) { error_log("{$v}: html 不存在，略過"); continue; }
        $max_full_id = intval(file_get_contents($ivod_dir . '/current-full-id'));
        $type = $v <= $max_full_id ? 'Full' : 'Clip';
        error_log("{$type} {$v}: 開始下載");
        try {
            download_ivod_to_wav($v, $type, $wav_path, $proxy_urls);
            @unlink($wav_path . '.failed');
            error_log("{$type} {$v} done: " . round(filesize($wav_path) / 1024 / 1024, 1) . ' MB');
        } catch (Exception $e) {
            mark_failed($wav_path, $e->getMessage());
            error_log("{$type} {$v} error: " . $e->getMessage());
        }
    }
    exit;
}

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
    if (recently_failed($ivod_dir . "/wav/{$v}.wav")) continue;

    $ivod = IVodParser::parseHTML($v, file_get_contents($html));
    if (strtotime($ivod->start_time) < $endtime) continue;

    error_log("Clip {$v}: {$ivod->委員名稱} {$ivod->影片長度}");
    try {
        download_ivod_to_wav($v, 'Clip', $ivod_dir . "/wav/{$v}.wav", $proxy_urls);
        @unlink($ivod_dir . "/wav/{$v}.wav.failed");
        error_log("Clip {$v} done: " . round(filesize($ivod_dir . "/wav/{$v}.wav") / 1024 / 1024, 1) . ' MB');
        $c_clip++;
    } catch (Exception $e) {
        mark_failed($ivod_dir . "/wav/{$v}.wav", $e->getMessage());
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
        if (recently_failed($ivod_dir . "/wav/{$v}.wav")) continue;

        $ivod = IVodParser::parseHTML($v, $content, 'Full');
        if (strtotime($ivod->start_time) < $endtime) break;

        error_log("Full {$v}: {$ivod->會議名稱} {$ivod->影片長度}");
        try {
            download_ivod_to_wav($v, 'Full', $ivod_dir . "/wav/{$v}.wav", $proxy_urls);
            @unlink($ivod_dir . "/wav/{$v}.wav.failed");
            error_log("Full {$v} done: " . round(filesize($ivod_dir . "/wav/{$v}.wav") / 1024 / 1024, 1) . ' MB');
            $c_full++;
        } catch (Exception $e) {
            mark_failed($ivod_dir . "/wav/{$v}.wav", $e->getMessage());
            error_log("Full {$v} error: " . $e->getMessage());
        }
    }
}

error_log("done: clip={$c_clip} full={$c_full}");
