<?php
/**
 * 將黨團協商公報紀錄補寫進 gazette_agenda index。
 *
 * 問題背景：data.ly.gov.tw dataset 41（gazette_agenda 的主要來源 CSV）不包含
 * 黨團協商的公報紀錄項目，但 ppg meet 頁面的「公報紀錄」連結（SittingCappInfo）
 * 指向的 LCIDC01 doc 檔確實存在於公報系統內，只是沒被收錄進來。
 *
 * 本腳本從 imports/meet/ppg_meet_page_json/ 掃描所有含 SittingCappInfo 公報連結
 * 的 meet JSON，解析出 gazette_id / agenda_id / doc URL，補寫進 gazette_agenda。
 * 寫入後 crawl-doc.php 自然會下載 doc 並做後續處理（tikahtml / txt / anchor）。
 *
 * Usage:
 *   php import-dangtuan-agenda.php            # 只處理新的（跳過已存在）
 *   php import-dangtuan-agenda.php --dry-run  # 只列出，不寫入
 */

include(__DIR__ . '/../../init.inc.php');

$dry_run = in_array('--dry-run', $argv);
$ppg_dir  = __DIR__ . '/../meet/ppg_meet_page_json';
$meet_dir = __DIR__ . '/../meet/meet-data';
$out_dir  = __DIR__ . '/gazette-agenda-data';

$stats = ['skip_exists' => 0, 'skip_no_url' => 0, 'written' => 0, 'error' => 0];

foreach (scandir($ppg_dir) as $fname) {
    if (!str_ends_with($fname, '.json')) continue;

    $raw = file_get_contents("{$ppg_dir}/{$fname}");
    if (!$raw) continue;
    $data = json_decode($raw, true);
    if (!$data) continue;

    // 找 SittingCappInfo 公報紀錄連結
    $capp_urls = [];
    foreach ($data['links'] ?? [] as $lnk) {
        $href = $lnk['href'] ?? '';
        if (str_starts_with($lnk['title'] ?? '', '公報紀錄') && str_contains($href, 'SittingCappInfo') && str_contains($href, 'LCIDC01')) {
            $capp_urls[] = $href;
        }
    }
    if (!$capp_urls) {
        $stats['skip_no_url']++;
        continue;
    }

    // 從檔名取 meetingNo（格式：{meetingNo}-{date}.json）
    $meeting_no = explode('-', basename($fname, '.json'))[0];

    // 從 meet-data 取 term / sessionPeriod
    $meet_json_path = "{$meet_dir}/黨團協商-{$meeting_no}.json";
    $term = null; $session_period = null; $session_times = null; $meeting_times = null; $select_term = null;
    $meeting_date = [];
    if (file_exists($meet_json_path)) {
        $m = json_decode(file_get_contents($meet_json_path), true);
        $term           = $m['term'] ?? null;
        $session_period = $m['sessionPeriod'] ?? null;
        $session_times  = $m['sessionTimes'] ?? null;
        $meeting_times  = $m['meetingTimes'] ?? null;
        $select_term    = $m['meet_data'][0]['selectTerm'] ?? null;
        $meeting_date   = array_map(fn($md) => $md['date'], $m['meet_data'] ?? []);
    }
    // fallback：從 ppg_meet_page_json 的 dates 欄位解析日期
    if (!$meeting_date) {
        foreach ($data['dates'] ?? [] as $ds) {
            if (preg_match('/(\d{3})年(\d{1,2})月(\d{1,2})日/', $ds, $mat)) {
                $meeting_date[] = sprintf('%04d-%02d-%02d', 1911 + $mat[1], $mat[2], $mat[3]);
            }
        }
    }

    foreach ($capp_urls as $pdf_url) {
        // 解析 URL：...communique1[\/]final[\/]pdf[\/]{year}[\/]{vol}[\/]{filename}.pdf
        // 反斜線 or 正斜線都有可能
        $url_norm = str_replace('\\', '/', $pdf_url);
        if (!preg_match('#communique1/final/pdf/(\d+)/(\d+)/(LCIDC01_(\d+)_(\d+))\.pdf#i', $url_norm, $m)) {
            error_log("import-dangtuan-agenda: cannot parse URL: {$pdf_url}");
            $stats['error']++;
            continue;
        }
        [, $com_year, $com_vol, $file_base, $gazette_id, $seq_str] = $m;
        $com_year   = (int)$com_year;
        $com_vol    = (int)$com_vol;
        $agenda_no  = (int)$seq_str;
        // gazette_id = comYear(3) + comVolume(2) + comBookId(2)  e.g. "1157202"
        // comBookId is the last 2 digits of gazette_id minus comYear(3)+comVolume(2)
        $prefix     = sprintf('%03d%02d', $com_year, $com_vol);
        $com_book   = (int)substr($gazette_id, strlen($prefix));
        $agenda_id  = sprintf('%s_%05d', $gazette_id, $agenda_no);

        // 已存在就跳過
        $out_file = "{$out_dir}/{$agenda_id}.json";
        if (file_exists($out_file)) {
            $stats['skip_exists']++;
            continue;
        }

        // 建構 .doc 下載 URL
        $doc_url = sprintf('https://ppg.ly.gov.tw/ppg/download/communique1/work/%d/%02d/%s.doc',
            $com_year, $com_vol, $file_base);

        $subject = '朝野黨團協商' . (isset($data['content']) ? '：' . $data['content'] : '');

        $agenda = [
            'comYear'       => $com_year,
            'comVolume'     => $com_vol,
            'comBookId'     => $com_book,
            'term'          => $term,
            'sessionPeriod' => $session_period,
            'sessionTimes'  => $session_times,
            'meetingTimes'  => $meeting_times,
            'agendaNo'      => $agenda_no,
            'agendaType'    => 1,
            'meetingDate'   => $meeting_date,
            'subject'       => $subject,
            'pageStart'     => null,
            'pageEnd'       => null,
            'selectTerm'    => $select_term,
            'agenda_id'     => $agenda_id,
            'gazette_id'    => $gazette_id,
            'docUrls'       => [$doc_url],
            'import_source' => '黨團協商',
        ];

        if ($dry_run) {
            echo json_encode([
                'agenda_id' => $agenda_id,
                'gazette_id' => $gazette_id,
                'doc_url'   => $doc_url,
                'subject'   => substr($subject, 0, 60),
                'date'      => $meeting_date[0] ?? '',
            ], JSON_UNESCAPED_UNICODE) . "\n";
            $stats['written']++;
            continue;
        }

        file_put_contents($out_file, json_encode($agenda, JSON_UNESCAPED_UNICODE));
        Elastic::dbBulkInsert('gazette_agenda', $agenda_id, $agenda);
        $stats['written']++;
        error_log("import-dangtuan-agenda: written {$agenda_id}");
    }
}

if (!$dry_run) {
    Elastic::dbBulkCommit();
}

$label = $dry_run ? '[dry-run] ' : '';
echo "{$label}Done. " . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
