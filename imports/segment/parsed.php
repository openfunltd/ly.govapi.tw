<?php
// 本機版的 v2 /gazette_agenda_doc/{lcidc}/parsed：用匯入端自己的 tikahtml 檔跑 GazetteTranscriptParser，印 JSON。
// 給 segment_meet.py 用，切分器就不必為了 parsed 打正式 API（parsed 要從 S3 抓 tikahtml 再切，是最重的一種請求）。
// 用法：php parsed.php 1154904_00004            （agenda-tikahtml/LCIDC01_{id}.doc.html）
//       php parsed.php --file path/to/x.html     （任何 tikahtml，例如 dangtuan-tikahtml/）
include(__DIR__ . '/../../init.inc.php');

$argv = $_SERVER['argv'];
if (($argv[1] ?? '') === '--file') {
    $path = $argv[2] ?? '';
} else {
    $lcidc = $argv[1] ?? '';
    if (!preg_match('#^\d+_\d+$#', $lcidc)) {
        fwrite(STDERR, "usage: php parsed.php <lcidc_id> | --file <tikahtml>\n");
        exit(2);
    }
    $path = __DIR__ . "/../gazette/agenda-tikahtml/LCIDC01_{$lcidc}.doc.html";
}
if (!is_file($path) or filesize($path) < 100) {
    fwrite(STDERR, "no tikahtml: {$path}\n");
    exit(1);
}
$ret = GazetteTranscriptParser::parse(file_get_contents($path));
echo json_encode($ret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
