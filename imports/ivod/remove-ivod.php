<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');
include(__DIR__ . '/../../imports/Importer.php');

$id = $_SERVER['argv'][1];
if (!$id) {
    throw new Exception("Usage php remove-ivod.php <id>");
}

$html_target = __DIR__ . "/html/{$id}.html";
if (!file_exists($html_target)) {
    throw new Exception("File not found: {$html_target}");
}

$content = file_get_contents($html_target);
if (strpos($content, '"rettim":null') === false) {
    readline("有 rettime 表示這影片存在，你確定要刪除這隻影片嗎？");
}

$ret = Elastic::dbQuery("/{prefix}ivod/_doc/{$id}", 'DELETE', '');
unlink($html_target);
