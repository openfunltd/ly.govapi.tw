<?php
// 補跑指定屆期缺少轉檔的 gazette-agenda 文件
// 用法：php reprocess-missing.php
//       prefix=113 php reprocess-missing.php   # 指定屆期前綴

include(__DIR__ . '/../../init.inc.php');

$prefix = getenv('prefix') ?: '115';

$docfiles = glob(__DIR__ . "/docfile/LCIDC01_{$prefix}*.doc");
$total = count($docfiles);
$done_tika = 0;
$done_html = 0;
$skip = 0;
$fail = 0;

foreach ($docfiles as $idx => $docfilepath) {
    $docfilename = basename($docfilepath);
    $tikapath    = __DIR__ . "/agenda-tikahtml/{$docfilename}.html";
    $htmlpath    = __DIR__ . "/agenda-html/{$docfilename}.html";
    $docxpath    = __DIR__ . "/agenda-docx/{$docfilename}";

    $need_tika = !file_exists($tikapath) || filesize($tikapath) < 1000;
    $need_html = !file_exists($htmlpath) || filesize($htmlpath) == 0;

    if (!$need_tika && !$need_html) {
        $skip++;
        continue;
    }

    error_log(sprintf("[%d/%d] %s (tika=%s html=%s)", $idx + 1, $total, $docfilename, $need_tika ? '缺' : 'ok', $need_html ? '缺' : 'ok'));

    // 確保 agenda-doc/ 有這個檔案，讓 getAgendaHTML 不重新下載
    $agenda_docfile = __DIR__ . "/agenda-doc/{$docfilename}";
    if (!file_exists($agenda_docfile)) {
        copy($docfilepath, $agenda_docfile);
    }

    // agenda-html：透過 getAgendaHTML（DOC→DOCX→HTML via unoserver），同時產生 agenda-docx/
    if ($need_html) {
        try {
            LYLib::getAgendaHTML("https://ppg.ly.gov.tw/placeholder/{$docfilename}");
            clearstatcache();
            if (file_exists($htmlpath) && filesize($htmlpath) > 0) {
                error_log("  html ok");
                $done_html++;
            } else {
                error_log("  html 轉換後仍為空（可能是大檔 unoserver 轉換失敗）");
            }
        } catch (Exception $e) {
            error_log("  html 失敗: " . $e->getMessage());
            $fail++;
        }
    }

    // agenda-tikahtml：優先用 DOCX（大檔 DOC 直接送 Tika 會失敗），DOCX 不存在才退回 DOC
    if ($need_tika) {
        clearstatcache();
        $source = (file_exists($docxpath) && filesize($docxpath) >= 1000) ? $docxpath : $docfilepath;
        error_log("  tika from " . basename($source));
        $cmd = sprintf(
            "env https_proxy= curl -s -T %s https://tika.openfun.dev/tika -H 'Accept: text/html' -o %s",
            escapeshellarg($source),
            escapeshellarg(__DIR__ . '/tmp-reprocess.html')
        );
        system($cmd, $ret);
        clearstatcache();
        if ($ret || !file_exists(__DIR__ . '/tmp-reprocess.html') || filesize(__DIR__ . '/tmp-reprocess.html') < 100) {
            error_log("  tika 失敗: ret=$ret");
            $fail++;
            @unlink(__DIR__ . '/tmp-reprocess.html');
        } else {
            rename(__DIR__ . '/tmp-reprocess.html', $tikapath);
            error_log("  tika ok");
            $done_tika++;
        }
    }
}

error_log(sprintf("完成：tika 補轉 %d，html 補轉 %d，跳過 %d，失敗 %d（共 %d 個）",
    $done_tika, $done_html, $skip, $fail, $total));
