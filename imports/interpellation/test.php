<?php

include(__DIR__ . '/../../init.inc.php');
$file = $_SERVER['argv'][1];
// 如果是 pdf ，就先用 pdftotext -layout 轉成 txt
if (preg_match('#\.pdf$#', $file)) {
    $txt = __DIR__ . '/tmp.txt';
    system("pdftotext -layout '$file' '$txt'");
    $file = $txt;
    $content = file_get_contents($file);
} else {
    $content = file_get_contents($file);
}
$ret = GazetteParser::parseInterpellation($content);
echo json_encode($ret, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
