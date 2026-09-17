<?php
/**
 * crawl-dangtuan.php
 * 下載黨團協商會議紀錄 PDF，透過 tika 轉 HTML，寫入 dangtuan-tikahtml/。
 *
 * 來源優先順序（同一場會議）：
 *   1. 會議相關附件（SittingAttachmentCapp）— 直接對應會議，最可靠
 *   2. 公報紀錄（SittingCappInfo）— 備選，直接下載 PDF，不繞道 communique1/work
 *
 * 多個 PDF（同一場有多份紀錄）全部下載，anchor parse 時合併。
 *
 * Usage:
 *   php crawl-dangtuan.php              # 第11屆所有未處理的
 *   php crawl-dangtuan.php --dry-run    # 只列出，不下載
 *   php crawl-dangtuan.php --force      # 強制重新下載 / tika
 *   term=11 php crawl-dangtuan.php      # 指定屆期（預設 11）
 */

include(__DIR__ . '/../../init.inc.php');

$dry_run  = in_array('--dry-run', $argv);
$force    = in_array('--force',   $argv);
$term     = (int)(getenv('term') ?: 11);

$ppg_dir  = __DIR__ . '/../meet/ppg_meet_page_json';
$meet_dir = __DIR__ . '/../meet/meet-data';
$pdf_dir  = __DIR__ . '/dangtuan-pdf';
$tika_dir = __DIR__ . '/dangtuan-tikahtml';
$data_dir = __DIR__ . '/dangtuan-data';

if (!$dry_run) {
    foreach ([$pdf_dir, $tika_dir, $data_dir] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }
}

$stats = ['ok' => 0, 'skip_exists' => 0, 'skip_no_src' => 0, 'error' => 0];

foreach (glob("{$ppg_dir}/*.json") as $f) {
    $d = json_decode(file_get_contents($f), true);
    if (!$d || strpos($d['title'] ?? '', '黨團協商') === false) continue;

    $meeting_no = explode('-', basename($f, '.json'))[0];

    // 確認屆期
    $meet_path = "{$meet_dir}/黨團協商-{$meeting_no}.json";
    if (!file_exists($meet_path)) continue;
    $meet = json_decode(file_get_contents($meet_path), true);
    if (($meet['term'] ?? null) != $term) continue;

    $date           = $meet['meet_data'][0]['date'] ?? null;
    $session_period = $meet['sessionPeriod'] ?? null;

    // ── 收集 PDF 來源清單 ────────────────────────────────────────────────────
    $sources = [];

    // 來源 1：SittingAttachmentCapp（會議相關附件）
    foreach ($d['attachments'] ?? [] as $att) {
        if (($att['group'] ?? '') === '會議相關附件' && ($att['filetype'] ?? '') === 'pdf') {
            $sources[] = [
                'source'    => 'attachment',
                'pdf_url'   => $att['href'],
                'pdf_file'  => basename($att['href']),
                'src_title' => $att['title'] ?? '',
            ];
        }
    }

    // 來源 2：SittingCappInfo（備選，只有在無附件時使用）
    if (!$sources) {
        foreach ($d['links'] ?? [] as $lnk) {
            if (strpos($lnk['href'] ?? '', 'SittingCappInfo') !== false) {
                $href = str_replace('\\', '/', $lnk['href']);
                $sources[] = [
                    'source'    => 'cappinfo',
                    'pdf_url'   => $href,
                    'pdf_file'  => basename($href),
                    'src_title' => $lnk['title'] ?? '',
                ];
            }
        }
    }

    if (!$sources) {
        $stats['skip_no_src']++;
        continue;
    }

    // ── 逐份 PDF 下載並 tika ─────────────────────────────────────────────────
    $processed = [];
    foreach ($sources as $src) {
        $pdf_file  = $src['pdf_file'];
        $pdf_path  = "{$pdf_dir}/{$pdf_file}";
        $tika_path = "{$tika_dir}/{$pdf_file}.html";

        if (!$force && file_exists($tika_path) && filesize($tika_path) > 500) {
            $processed[] = $src;
            $stats['skip_exists']++;
            continue;
        }

        if ($dry_run) {
            echo "[{$src['source']}] {$meeting_no} {$date}  {$pdf_file}  {$src['src_title']}\n";
            $stats['ok']++;
            $processed[] = $src;
            continue;
        }

        // 下載 PDF
        if (!file_exists($pdf_path) || filesize($pdf_path) < 1000) {
            $tmp = tempnam(sys_get_temp_dir(), 'dangtuan_');
            system(sprintf(
                'curl -sL --user-agent %s -o %s %s',
                escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
                escapeshellarg($tmp),
                escapeshellarg($src['pdf_url'])
            ), $ret);
            if ($ret || !file_exists($tmp) || filesize($tmp) < 1000) {
                error_log("crawl-dangtuan: download failed [{$src['source']}] {$src['pdf_url']}");
                @unlink($tmp);
                $stats['error']++;
                continue;
            }
            rename($tmp, $pdf_path);
        }

        // tika → HTML
        $tmp_html = tempnam(sys_get_temp_dir(), 'dangtuan_tika_');
        $cmd = sprintf(
            'env https_proxy= curl -sL -T %s -H %s -H %s https://tika.api.openfun.dev/tika > %s',
            escapeshellarg($pdf_path),
            escapeshellarg('X-Api-Key: ' . getenv('OPENFUN_API_KEY')),
            escapeshellarg('Accept: text/html'),
            escapeshellarg($tmp_html)
        );
        system($cmd, $ret);
        if ($ret || !file_exists($tmp_html) || filesize($tmp_html) < 500) {
            error_log("crawl-dangtuan: tika failed {$pdf_file}");
            @unlink($tmp_html);
            $stats['error']++;
            continue;
        }
        rename($tmp_html, $tika_path);

        echo "OK [{$src['source']}] {$meeting_no} {$date}  {$pdf_file}\n";
        $stats['ok']++;
        $processed[] = $src;
    }

    if (!$processed || $dry_run) continue;

    // 寫 dangtuan-data/{meeting_no}.json
    $meta = [
        'meeting_no'    => $meeting_no,
        'date'          => $date,
        'term'          => $meet['term'] ?? null,
        'sessionPeriod' => $session_period,
        'pdfs'          => $processed,
    ];
    file_put_contents(
        "{$data_dir}/{$meeting_no}.json",
        json_encode($meta, JSON_UNESCAPED_UNICODE)
    );
}

$label = $dry_run ? '[dry-run] ' : '';
echo "{$label}Done. " . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
